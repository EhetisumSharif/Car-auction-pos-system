<?php
// lc.php

// Include the database connection file
require_once 'db_connect.php';

session_start();

$message = "";

try {
    // Set PDO error mode to exception for better debugging.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Handle form submissions for adding, updating, and deleting LCs
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle deletion of an LC
        if (isset($_POST['deleteId'])) {
            $lc_id = $_POST['deleteId'];
            $sql = "DELETE FROM lc WHERE id = ?";
            $stmt = $db->prepare($sql);
            if ($stmt->execute([$lc_id])) {
                $message = "<div class='message-box success'>LC record deleted successfully.</div>";
            } else {
                $errorInfo = $stmt->errorInfo();
                $message = "<div class='message-box error'>Error deleting LC: " . htmlspecialchars($errorInfo[2]) . "</div>";
            }
        }

        // Handle adding or updating an LC
        if (isset($_POST['lcNumber']) && isset($_POST['lcAmountUsd'])) {
            $lc_id = $_POST['id'] ?? null;
            $lcNumber = trim($_POST['lcNumber']);
            $carId = trim($_POST['carId']);
            $bank = trim($_POST['bank']);
            $issueDate = trim($_POST['issueDate']);
            $paymentDate = trim($_POST['paymentDate']);
            $lcAmountUsd = floatval($_POST['lcAmountUsd']);
            $status = trim($_POST['status']);
            $cfrPriceUsd = 0.0;
            $underInvoiceUsd = 0.0;
            $overInvoiceUsd = 0.0;

            // Unified logic to calculate CFR price
            if (!empty($carId)) {
                // Corrected SQL query to use auction_datetime and its corresponding exchange rate date
                $sql_cfr = "SELECT (ap.end_price_jpy + ap.service_charge_jpy) AS total_jpy, ce.jpy_to_usd_rate
                            FROM auction_and_pricing ap
                            JOIN currency_exchange_rate ce ON ce.rate_date = DATE(ap.auction_datetime)
                            WHERE ap.chassis_number = ?";
                $stmt_cfr = $db->prepare($sql_cfr);
                $stmt_cfr->execute([$carId]);
                $result = $stmt_cfr->fetch(PDO::FETCH_ASSOC);
                if ($result) {
                    $cfrPriceUsd = $result['total_jpy'] * $result['jpy_to_usd_rate'];
                }
            }
            
            // Calculate under-invoice and over-invoice values
            $underInvoiceUsd = max(0, $cfrPriceUsd - $lcAmountUsd);
            $overInvoiceUsd = max(0, $lcAmountUsd - $cfrPriceUsd);


            if (empty($lcNumber)) {
                $message = "<div class='message-box error'>LC Number is required.</div>";
            } else {
                if ($lc_id) {
                    // Update an existing LC
                    $sql = "UPDATE lc SET lc_number = ?, bank = ?, lc_value_usd = ?, car_chassis_no = ?, cfr_price_usd = ?, under_invoice_usd = ?, over_invoice_usd = ?, status = ?, issue_date = ?, payment_date = ? WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute([$lcNumber, $bank, $lcAmountUsd, $carId, $cfrPriceUsd, $underInvoiceUsd, $overInvoiceUsd, $status, $issueDate, $paymentDate, $lc_id])) {
                        $message = "<div class='message-box success'>LC record updated successfully.</div>";
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        $message = "<div class='message-box error'>Error updating LC: " . htmlspecialchars($errorInfo[2]) . "</div>";
                    }
                } else {
                    // Add a new LC
                    $sql = "INSERT INTO lc (lc_number, bank, lc_value_usd, car_chassis_no, cfr_price_usd, under_invoice_usd, over_invoice_usd, status, issue_date, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt = $db->prepare($sql);
                    if ($stmt->execute([$lcNumber, $bank, $lcAmountUsd, $carId, $cfrPriceUsd, $underInvoiceUsd, $overInvoiceUsd, $status, $issueDate, $paymentDate])) {
                        $message = "<div class='message-box success'>New LC added successfully.</div>";
                    } else {
                        $errorInfo = $stmt->errorInfo();
                        $message = "<div class='message-box error'>Error adding LC: " . htmlspecialchars($errorInfo[2]) . "</div>";
                    }
                }
            }
            // Redirect to prevent form resubmission
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // --- Fetch all LCs for display ---
    $lcs = [];
    $sql = "SELECT id, lc_number, bank, lc_value_usd, car_chassis_no, cfr_price_usd, under_invoice_usd, over_invoice_usd, status, issue_date, payment_date FROM lc ORDER BY lc_number ASC";
    $stmt = $db->query($sql);
    if ($stmt) {
        $lcs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $message .= "<div class='message-box error'>Error fetching LCs. (Statement failed to execute)</div>";
    }

    // --- Fetch all car chassis numbers for the dropdown menu ---
    $car_chassis_numbers = [];
    $sql_cars = "SELECT chassis_number FROM car_details ORDER BY chassis_number ASC";
    $stmt_cars = $db->query($sql_cars);
    if ($stmt_cars) {
        $car_chassis_numbers = $stmt_cars->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // You might want to log this error, but don't show it to the user.
        error_log("Error fetching car chassis numbers: " . json_encode($db->errorInfo()));
    }

} catch (PDOException $e) {
    $message = "<div class='message-box error'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $lcs = [];
    $car_chassis_numbers = [];
}

// Data for the dashboard cards and alerts
$lcCounts = [
    'total' => count($lcs),
    'pending' => 0,
    'paid' => 0,
];
$alerts = [];
$today = new DateTime();
$oneWeekFromNow = (new DateTime())->modify('+7 days');

foreach ($lcs as &$lc) {
    $status = strtolower($lc['status']);
    if (strpos($status, 'paid') !== false) {
        $lcCounts['paid']++;
    } else {
        $lcCounts['pending']++;
    }

    // UPDATED LOGIC: Add a check for 'paid' status before generating an alert
    if ($lc['payment_date'] && strtolower($lc['status']) !== 'paid') {
        try {
            $paymentDate = new DateTime($lc['payment_date']);
            if ($paymentDate >= $today && $paymentDate <= $oneWeekFromNow) {
                $alerts[] = [
                    'lcNumber' => $lc['lc_number'],
                    'paymentDate' => $lc['payment_date'],
                    'lcAmountUsd' => $lc['lc_value_usd']
                ];
            }
        } catch (Exception $e) {
            // Silently fail on invalid dates
        }
    }
}
unset($lc);

// Function to format dates for display
function formatDate($dateString) {
    if (!$dateString || $dateString === '0000-00-00') {
        return 'N/A';
    }
    try {
        $date = new DateTime($dateString);
        return $date->format('F j, Y');
    } catch (Exception $e) {
        return 'Invalid Date';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LC Management</title>
    <link rel="icon" type="image/jpeg" href="pic/logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .modal-overlay { background-color: rgba(0, 0, 0, 0.5); transition: opacity 0.3s ease-in-out; opacity: 0; pointer-events: none; }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
    </style>
</head>
<body>
    <input type="checkbox" id="mobile-sidebar-toggle">
    <div class="dashboard-container">
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Main Menu</h2>
                <label for="mobile-sidebar-toggle" class="close-btn"><i class="fas fa-times"></i></label>
            </div>
            <nav>
                <ul>
                    <li><a href="home.php"><i class="fas fa-home icon"></i> Dashboard</a></li>
                    <li><a href="car_info.php"><i class="fas fa-car icon"></i> Car Information</a></li>
                    <li><a href="shipment_track.php"><i class="fas fa-truck icon"></i> Shipment Tracking</a></li>
                    <li><a href="lc.php" class="active"><i class="fas fa-folder-open icon"></i> LC Management</a></li>
                    <li><a href="client.php"><i class="fas fa-user icon"></i> Client Management</a></li>
                    <li><a href="sales.php"><i class="fas fa-chart-bar icon"></i> Sales & Reports</a></li>
                </ul>
            </nav>
        </div>
        <div class="main-content">
            <div class="sidebar-overlay"></div>
            <header class="header">
                <div style="display:flex; align-items:center; gap:1rem;">
                    <label for="mobile-sidebar-toggle" class="menu-btn"><i class="fas fa-bars"></i></label>
                    <div class="logo" style="display: flex; align-items: center; gap: 0.5rem;">
                        <img src="pic/logo.jpg" alt="IMB Logo" style="height: 40px; width: auto;" />
                        <span><span class="logo-highlight">IMB</span> Dashboard</span>
                    </div>
                </div>
                <form action="logout.php" method="post">
                    <button type="submit" class="sign-out-btn flex items-center gap-2">
                        <i class="fas fa-sign-out-alt"></i> Log Out
                    </button>
                </form>
            </header>
            <div class="p-8 max-w-7xl mx-auto">
                <header class="flex flex-col md:flex-row items-center justify-between gap-4 mb-8">
                    <div class="text-center md:text-left">
                        <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 lg:text-5xl">LC Management</h1>
                        <p class="mt-2 text-lg text-gray-500">
                            Manage all Letters of Credit and track their payment status.
                        </p>
                    </div>
                    <button
                        onclick="openAddModal()"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-md shadow-lg transition-all duration-300 transform hover:scale-105 flex items-center gap-2"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add New LC
                    </button>
                </header>
                <?php if (!empty($message)): ?>
                    <div class="mb-4 text-center"><?= $message ?></div>
                <?php endif; ?>
                <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Total LCs</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-indigo-500">
                                <path d="M4 14.5V4a2 2 0 0 1 2-2h8.5L20 7.5V20a2 2 0 0 1-2 2h-6.5"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <circle cx="8" cy="18" r="4"></circle>
                                <path d="M8 14v2"></path>
                                <path d="M10 16h-4"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $lcCounts['total'] ?></div>
                            <p class="text-xs text-gray-400 mt-1">Total LCs recorded</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Pending</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-yellow-500">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $lcCounts['pending'] ?></div>
                            <p class="text-xs text-gray-400 mt-1">Awaiting full payment</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Paid</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-green-500">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-8.87"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $lcCounts['paid'] ?></div>
                            <p class="text-xs text-gray-400 mt-1">Successfully settled</p>
                        </div>
                    </div>
                </section>
                <?php if (!empty($alerts)): ?>
                    <section class="mb-8">
                        <h2 class="text-2xl font-bold mb-4 text-gray-800">Upcoming Payment Alerts</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($alerts as $alert): ?>
                                <div class="bg-yellow-100 p-4 rounded-lg shadow-sm border border-yellow-300 animate-pulse">
                                    <h3 class="font-semibold text-lg text-yellow-800 flex items-center gap-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                            <line x1="16" y1="2" x2="16" y2="6"></line>
                                            <line x1="8" y1="2" x2="8" y2="6"></line>
                                            <line x1="3" y1="10" x2="21" y2="10"></line>
                                        </svg>
                                        Payment Due Soon for <?= htmlspecialchars($alert['lcNumber']) ?>
                                    </h3>
                                    <p class="text-sm text-yellow-700 mt-1">Payment Date: <?= formatDate($alert['paymentDate']) ?></p>
                                    <p class="text-sm text-yellow-700">Amount: $<?= number_format($alert['lcAmountUsd'], 2) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>
                <section class="bg-white p-6 rounded-xl shadow-lg border border-gray-200">
                    <div class="flex items-center gap-2 mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-gray-400">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input
                            type="text"
                            id="searchQuery"
                            placeholder="Search by LC number, bank, or status..."
                            oninput="filterTable()"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 w-full rounded-md shadow-sm border-gray-300 focus:ring-blue-500 focus:border-blue-500"
                        />
                    </div>
                    <div class="w-full overflow-auto">
                        <table id="lcTable" class="w-full caption-bottom text-sm">
                            <thead class="[&_tr]:border-b">
                                <tr class="bg-gray-50">
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">LC Number</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Chassis No</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Bank</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">LC Value (USD)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">CFR Price (USD)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Under-Invoice (USD)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Over-Invoice (USD)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Status</th>
                                    <th class="h-12 px-4 text-right align-middle font-bold text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="[&_tr:last-child]:border-0">
                                <?php if (empty($lcs)): ?>
                                    <tr>
                                        <td colspan="9" class="p-4 text-center text-gray-500">No LCs found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($lcs as $lc): ?>
                                        <tr class="border-b transition-colors hover:bg-gray-100 duration-200">
                                            <td class="p-4 align-middle font-medium"><?= htmlspecialchars($lc['lc_number']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($lc['car_chassis_no']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($lc['bank']) ?></td>
                                            <td class="p-4 align-middle">$<?= number_format($lc['lc_value_usd'], 2) ?></td>
                                            <td class="p-4 align-middle">$<?= number_format($lc['cfr_price_usd'], 2) ?></td>
                                            <td class="p-4 align-middle">
                                                <?php if ($lc['under_invoice_usd'] > 0) { echo '$' . number_format($lc['under_invoice_usd'], 2); } else { echo 'N/A'; } ?>
                                            </td>
                                            <td class="p-4 align-middle">
                                                <?php if ($lc['over_invoice_usd'] > 0) { echo '$' . number_format($lc['over_invoice_usd'], 2); } else { echo 'N/A'; } ?>
                                            </td>
                                            <td class="p-4 align-middle">
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold
                                                    <?php if (strtolower($lc['status']) === 'paid') echo 'bg-green-100 text-green-800'; else echo 'bg-yellow-100 text-yellow-800'; ?>">
                                                    <?= htmlspecialchars($lc['status']) ?>
                                                </span>
                                            </td>
                                            <td class="p-4 align-middle text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button onclick='openEditModal(<?= json_encode([
                                                        'id' => $lc['id'],
                                                        'lcNumber' => $lc['lc_number'],
                                                        'carId' => $lc['car_chassis_no'],
                                                        'bank' => $lc['bank'],
                                                        'issueDate' => $lc['issue_date'],
                                                        'paymentDate' => $lc['payment_date'],
                                                        'lcAmountUsd' => $lc['lc_value_usd'],
                                                        'cfrPriceUsd' => $lc['cfr_price_usd'],
                                                        'status' => $lc['status']
                                                    ]) ?>)' class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-blue-100 hover:text-blue-600 h-9 w-9 p-0 text-blue-500" title="Edit">
													<i class="fas fa-edit"></i>
                                                    </button>
                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this LC?');" style="display:inline;">
                                                        <input type="hidden" name="deleteId" value="<?= htmlspecialchars($lc['id']) ?>">
                                                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-red-100 hover:text-red-600 h-9 w-9 p-0 text-red-500" title="Delete">
														<i class="fas fa-trash-alt"></i>
													</button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
		   <div class="right-sidebar">
        
    </div>
    </div>
	<footer class="bg-gray-800 text-gray-400 text-center p-4">
                <p>The SAD Six | Developers</p>
            </footer>
    <div id="addEditModal" class="modal-overlay fixed inset-0 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full p-6 mx-4">
            <h2 id="modalTitle" class="text-2xl font-bold mb-4 text-gray-900">Add New LC</h2>
            <form id="lcForm" method="POST" action="">
                <input type="hidden" id="lcId" name="id">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
					<div>
						<label for="lcNumber" class="block text-sm font-medium text-gray-700">LC Number</label>
						<input type="text" id="lcNumber" name="lcNumber" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., LC-12345" required>
					</div>
                    <div>
                        <label for="carId" class="block text-sm font-medium text-gray-700">Car Chassis No</label>
                        <select id="carId" name="carId" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">Select a Car Chassis No</option>
                            <?php foreach ($car_chassis_numbers as $chassis): ?>
                                <option value="<?= htmlspecialchars($chassis) ?>"><?= htmlspecialchars($chassis) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
					<div>
						<label for="bank" class="block text-sm font-medium text-gray-700">Bank</label>
						<input type="text" id="bank" name="bank" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., BRAC Bank" />
					</div>
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                        <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="Pending">Pending</option>
                            <option value="Paid">Paid</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="issueDate" class="block text-sm font-medium text-gray-700">Issue Date</label>
                        <input type="date" id="issueDate" name="issueDate" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label for="paymentDate" class="block text-sm font-medium text-gray-700">Payment Date</label>
                        <input type="date" id="paymentDate" name="paymentDate" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
				<div>
					<label for="lcAmountUsd" class="block text-sm font-medium text-gray-700">LC Value (USD)</label>
					<input type="number" step="0.01" id="lcAmountUsd" name="lcAmountUsd" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" placeholder="e.g., 50000.00">
				</div>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors">Save LC</button>
                </div>
            </form>
        </div>
    </div>
    <script>
        function openAddModal() {
            document.getElementById('lcId').value = '';
            document.getElementById('modalTitle').innerText = 'Add New LC';
            document.getElementById('lcForm').reset();
            // Set the first option as selected for the dropdown on a new entry
            document.getElementById('carId').selectedIndex = 0;
            document.getElementById('addEditModal').classList.add('active');
        }
        function openEditModal(lc) {
            document.getElementById('lcId').value = lc.id;
            document.getElementById('modalTitle').innerText = 'Edit LC';
            document.getElementById('lcNumber').value = lc.lcNumber;
            // Set the value of the dropdown based on the LC record
            document.getElementById('carId').value = lc.carId;
            document.getElementById('bank').value = lc.bank;
            document.getElementById('issueDate').value = lc.issueDate;
            document.getElementById('paymentDate').value = lc.paymentDate;
            document.getElementById('lcAmountUsd').value = lc.lcAmountUsd;
            document.getElementById('status').value = lc.status;
            document.getElementById('addEditModal').classList.add('active');
        }
        function closeModal() {
            document.getElementById('addEditModal').classList.remove('active');
        }
        function filterTable() {
            var input, filter, table, tr, td, i, j, txtValue;
            input = document.getElementById("searchQuery");
            filter = input.value.toUpperCase();
            table = document.getElementById("lcTable");
            tr = table.getElementsByTagName("tr");
            for (i = 1; i < tr.length; i++) {
                tr[i].style.display = "none";
                td = tr[i].getElementsByTagName("td");
                for (j = 0; j < td.length; j++) {
                    if (j < 3 || j == 7) { // Check LC Number, Car ID, Bank, and Status columns
                        txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            tr[i].style.display = "";
                            break;
                        }
                    }
                }
            }
        }
    </script>
</body>
</html>