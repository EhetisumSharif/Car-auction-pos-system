<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// sales.php

// Include the database connection file
require_once 'db_connect.php';

session_start();

$message = "";

try {
    // Set PDO error mode to exception for better debugging.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // --- Handle form submissions for adding, updating, and deleting sales ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle deletion of a sale
        if (isset($_POST['deleteId'])) {
            $sale_id = $_POST['deleteId'];
            $sql = "DELETE FROM sales WHERE id = ?";
            $stmt = $db->prepare($sql);
            if ($stmt->execute([$sale_id])) {
                $message = "<div class='message-box success'>Sale record deleted successfully.</div>";
            } else {
                $errorInfo = $stmt->errorInfo();
                $message = "<div class='message-box error'>Error deleting sale: " . htmlspecialchars($errorInfo[2]) . "</div>";
            }
        }

        // Handle adding or updating a sale
        if (isset($_POST['car_chassis_no'])) {
            $sale_id = $_POST['id'] ?? null;
            $car_chassis_no = trim($_POST['car_chassis_no']);
            $client_id = trim($_POST['client_id']);
            $sale_date = trim($_POST['sale_date']);
            $payment_status = trim($_POST['payment_status']);

            // New BDT fields from the form
            $duty_bdt = floatval($_POST['duty_bdt']);
            $bank_charge_bdt = floatval($_POST['bank_charge_bdt']);
            $cn_f_charge_bdt = floatval($_POST['c_f_charge_bdt']);
            $transportation_bdt = floatval($_POST['transportation_bdt']);
            $other_cost_bdt = floatval($_POST['other_cost_bdt']);
            $profit_bdt = floatval($_POST['profit_bdt']);

            if (empty($car_chassis_no) || empty($client_id) || empty($sale_date)) {
                $message = "<div class='message-box error'>All required fields must be filled.</div>";
            } else {
                // Fetch all necessary price information from the lc table, including payment date
                $sql_cfr = "SELECT cfr_price_usd, lc_value_usd, under_invoice_usd, over_invoice_usd, payment_date FROM lc WHERE car_chassis_no = ?";
                $stmt_cfr = $db->prepare($sql_cfr);
                $stmt_cfr->execute([$car_chassis_no]);
                $cfr_result = $stmt_cfr->fetch(PDO::FETCH_ASSOC);

                if ($cfr_result) {
                    $cfr_price_usd = floatval($cfr_result['cfr_price_usd']);
                    $lc_value_usd = floatval($cfr_result['lc_value_usd']);
                    $under_invoice_usd = floatval($cfr_result['under_invoice_usd']);
                    $over_invoice_usd = floatval($cfr_result['over_invoice_usd']);
                    $lc_payment_date = $cfr_result['payment_date'];

                    // --- Fetch the correct currency exchange rates based on the LC payment date ---
                    $sql_rates = "SELECT usd_to_bdt_rate, under_invoice_rate FROM currency_exchange_rate WHERE rate_date <= ? ORDER BY rate_date DESC LIMIT 1";
                    $stmt_rates = $db->prepare($sql_rates);
                    $stmt_rates->execute([$lc_payment_date]);
                    $rates = $stmt_rates->fetch(PDO::FETCH_ASSOC);
                    $usd_to_bdt_rate = $rates['usd_to_bdt_rate'] ?? 121.95; // Fallback value
                    $under_invoice_rate = $rates['under_invoice_rate'] ?? 126.00; // Fallback value

                    // Calculate selling_price_bdt based on invoicing type
                    if ($under_invoice_usd > 0) {
                        // Under-invoicing formula
                        $selling_price_bdt = ($lc_value_usd * $usd_to_bdt_rate) + ($under_invoice_usd * $under_invoice_rate) + $duty_bdt + $bank_charge_bdt + $cn_f_charge_bdt + $transportation_bdt + $other_cost_bdt + $profit_bdt;
                    } else {
                        // Over-invoicing or normal invoicing formula
                        $selling_price_bdt = ($cfr_price_usd * $usd_to_bdt_rate) + $duty_bdt + $bank_charge_bdt + $cn_f_charge_bdt + $transportation_bdt + $other_cost_bdt + $profit_bdt;
                    }

                    // Calculate profit_usd
                    $profit_usd = $profit_bdt / $usd_to_bdt_rate;

                    if ($sale_id) {
                        // Update an existing sale
                        $sql = "UPDATE sales SET car_chassis_no = ?, client_id = ?, sale_date = ?, duty_bdt = ?, bank_charge_bdt = ?, c_f_charge_bdt = ?, transportation_bdt = ?, other_cost_bdt = ?, selling_price_bdt = ?, profit_bdt = ?, profit_usd = ?, payment_status = ? WHERE id = ?";
                        $stmt = $db->prepare($sql);
                        if ($stmt->execute([$car_chassis_no, $client_id, $sale_date, $duty_bdt, $bank_charge_bdt, $cn_f_charge_bdt, $transportation_bdt, $other_cost_bdt, $selling_price_bdt, $profit_bdt, $profit_usd, $payment_status, $sale_id])) {
                            $message = "<div class='message-box success'>Sale record updated successfully.</div>";
                        } else {
                            $errorInfo = $stmt->errorInfo();
                            $message = "<div class='message-box error'>Error updating sale: " . htmlspecialchars($errorInfo[2]) . "</div>";
                        }
                    } else {
                        // Add a new sale
                        $sql = "INSERT INTO sales (car_chassis_no, client_id, sale_date, duty_bdt, bank_charge_bdt, c_f_charge_bdt, transportation_bdt, other_cost_bdt, selling_price_bdt, profit_bdt, profit_usd, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $stmt = $db->prepare($sql);
                        if ($stmt->execute([$car_chassis_no, $client_id, $sale_date, $duty_bdt, $bank_charge_bdt, $cn_f_charge_bdt, $transportation_bdt, $other_cost_bdt, $selling_price_bdt, $profit_bdt, $profit_usd, $payment_status])) {
                            $message = "<div class='message-box success'>New sale added successfully.</div>";
                        } else {
                            $errorInfo = $stmt->errorInfo();
                            $message = "<div class='message-box error'>Error adding sale: " . htmlspecialchars($errorInfo[2]) . "</div>";
                        }
                    }
                } else {
                    $message = "<div class='message-box error'>Error: Could not find CFR price for the selected car chassis number.</div>";
                }
            }
        }
    }

    // --- Fetch all sales for display, including car and client names and lc data ---
    $sales = [];
    $sql = "
        SELECT
            s.id, s.sale_date, s.duty_bdt, s.bank_charge_bdt, s.c_f_charge_bdt, s.transportation_bdt, s.other_cost_bdt, s.selling_price_bdt, s.profit_bdt, s.payment_status,
            cd.chassis_number,
            c.client_name,
            c.client_id,
            lc.cfr_price_usd,
            lc.lc_value_usd,
            lc.under_invoice_usd,
            lc.over_invoice_usd,
            lc.payment_date
        FROM sales s
        LEFT JOIN car_details cd ON s.car_chassis_no = cd.chassis_number
        LEFT JOIN client c ON s.client_id = c.client_id
        LEFT JOIN lc ON s.car_chassis_no = lc.car_chassis_no
        ORDER BY s.sale_date DESC
    ";
    $stmt = $db->query($sql);
    if ($stmt) {
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $message .= "<div class='message-box error'>Error fetching sales. (Statement failed to execute)</div>";
    }

    // --- Fetch all car chassis numbers for the dropdown menu ---
    $car_chassis_numbers = [];
    $sql_cars = "SELECT chassis_number FROM car_details ORDER BY chassis_number ASC";
    $stmt_cars = $db->query($sql_cars);
    if ($stmt_cars) {
        $car_chassis_numbers = $stmt_cars->fetchAll(PDO::FETCH_COLUMN);
    } else {
        error_log("Error fetching car chassis numbers: " . json_encode($db->errorInfo()));
    }

    // --- Fetch all clients for the dropdown menu ---
    $clients_list = [];
    $sql_clients = "SELECT client_id, client_name FROM client ORDER BY client_name ASC";
    $stmt_clients = $db->query($sql_clients);
    if ($stmt_clients) {
        $clients_list = $stmt_clients->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("Error fetching clients: " . json_encode($db->errorInfo()));
    }


} catch (PDOException $e) {
    $message = "<div class='message-box error'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $sales = [];
    $car_chassis_numbers = [];
    $clients_list = [];
}

// Data for the dashboard cards
$pendingPaymentsCount = 0;
foreach ($sales as $sale) {
    if (strtolower($sale['payment_status']) === 'pending') {
        $pendingPaymentsCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sales & Reports</title>
    <link rel="icon" type="image/jpeg" href="pic/logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .modal-overlay { background-color: rgba(0, 0, 0, 0.5); transition: opacity 0.3s ease-in-out; opacity: 0; pointer-events: none; }
        .modal-overlay.active { opacity: 1; pointer-events: auto; }
        .message-box-overlay { background-color: rgba(0, 0, 0, 0.5); transition: opacity 0.3s ease-in-out; opacity: 0; pointer-events: none; }
        .message-box-overlay.active { opacity: 1; pointer-events: auto; }
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
                    <li><a href="lc.php"><i class="fas fa-folder-open icon"></i> LC Management</a></li>
                    <li><a href="client.php"><i class="fas fa-user icon"></i> Client Management</a></li>
                    <li><a href="sales.php" class="active"><i class="fas fa-chart-bar icon"></i> Sales & Reports</a></li>
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
                        <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 lg:text-5xl">Sales</h1>
                        <p class="mt-2 text-lg text-gray-500">
                            Track sales, profits, and client information.
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
                        Add New Sale
                    </button>
                </header>
                <?php if (!empty($message)): ?>
                    <div class="mb-4 text-center"><?= $message ?></div>
                <?php endif; ?>
                <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300 md:col-span-1">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Pending Payments</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-yellow-500">
                                <path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $pendingPaymentsCount ?></div>
                            <p class="text-xs text-gray-400 mt-1">Sales awaiting full payment</p>
                        </div>
                    </div>
                </section>
                <section class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 mb-8">
                    <div class="flex items-center gap-2 mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-5 w-5 text-gray-400">
                            <circle cx="11" cy="11" r="8"></circle>
                            <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                        </svg>
                        <input
                            type="text"
                            id="searchQuery"
                            placeholder="Search by chassis number, client name, or status..."
                            oninput="filterTable()"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 w-full rounded-md shadow-sm border-gray-300 focus:ring-blue-500 focus:border-blue-500"
                        />
                    </div>
                    <div class="w-full overflow-auto">
                        <table id="salesTable" class="w-full caption-bottom text-sm">
                            <thead class="[&_tr]:border-b">
                                <tr class="bg-gray-50">
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Chassis No</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Client Name</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Sale Date</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">LC Value (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Under Invoice (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Duty (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Bank Charge (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">C&F Charge (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Transportation (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Other Cost (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Profit (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Selling Price (BDT)</th>
                                    <th class="h-12 px-4 text-left align-middle font-bold text-gray-700">Payment Status</th>
                                    <th class="h-12 px-4 text-right align-middle font-bold text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="[&_tr:last-child]:border-0">
                                <?php if (empty($sales)): ?>
                                    <tr>
                                        <td colspan="16" class="p-4 text-center text-gray-500">No sales records found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($sales as $sale):
                                        // Fetch the correct exchange rates for the LC's payment date
                                        $sql_rates = "SELECT usd_to_bdt_rate, under_invoice_rate FROM currency_exchange_rate WHERE rate_date <= ? ORDER BY rate_date DESC LIMIT 1";
                                        $stmt_rates = $db->prepare($sql_rates);
                                        $stmt_rates->execute([$sale['payment_date']]);
                                        $rates = $stmt_rates->fetch(PDO::FETCH_ASSOC);
                                        $usd_to_bdt_rate = $rates['usd_to_bdt_rate'] ?? 121.95; // Fallback
                                        $under_invoice_rate = $rates['under_invoice_rate'] ?? 126.00; // Fallback

                                        // Calculate BDT values based on the fetched rates
                                        $cfr_price_bdt = $sale['cfr_price_usd'] * $usd_to_bdt_rate;
                                        $lc_value_bdt = $sale['lc_value_usd'] * $usd_to_bdt_rate;
                                        $under_invoice_bdt = $sale['under_invoice_usd'] * $under_invoice_rate;
                                        $over_invoice_bdt = $sale['over_invoice_usd'] * $usd_to_bdt_rate;
                                    ?>
                                        <tr class="border-b transition-colors hover:bg-gray-100 duration-200">
                                            <td class="p-4 align-middle"><?= htmlspecialchars($sale['chassis_number']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($sale['client_name']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($sale['sale_date']) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($lc_value_bdt, 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($under_invoice_bdt, 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($sale['duty_bdt'], 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($sale['bank_charge_bdt'], 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($sale['c_f_charge_bdt'], 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($sale['transportation_bdt'], 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($sale['other_cost_bdt'], 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($sale['profit_bdt'], 2) ?></td>
                                            <td class="p-4 align-middle"><?= number_format($sale['selling_price_bdt'], 2) ?></td>
                                            <td class="p-4 align-middle">
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold
                                                    <?php if (strtolower($sale['payment_status']) === 'paid') echo 'bg-green-100 text-green-800'; else echo 'bg-yellow-100 text-yellow-800'; ?>">
                                                    <?= htmlspecialchars($sale['payment_status']) ?>
                                                </span>
                                            </td>
                                            <td class="p-4 align-middle text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button onclick='openEditModal(<?= json_encode([
                                                        'id' => $sale['id'],
                                                        'carId' => $sale['chassis_number'],
                                                        'clientId' => $sale['client_id'],
                                                        'saleDate' => $sale['sale_date'],
                                                        'dutyBdt' => $sale['duty_bdt'],
                                                        'bankChargeBdt' => $sale['bank_charge_bdt'],
                                                        'cnFChargeBdt' => $sale['c_f_charge_bdt'],
                                                        'transportationBdt' => $sale['transportation_bdt'],
                                                        'otherCostBdt' => $sale['other_cost_bdt'],
                                                        'sellingPriceBdt' => $sale['selling_price_bdt'],
                                                        'profitBdt' => $sale['profit_bdt'],
                                                        'paymentStatus' => $sale['payment_status'],
                                                    ]) ?>)'class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-blue-100 hover:text-blue-600 h-9 w-9 p-0 text-blue-500" title="Edit">
													<i class="fas fa-edit"></i>
												    </button>
                                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this sale?');" style="display:inline;">
                                                        <input type="hidden" name="deleteId" value="<?= htmlspecialchars($sale['id']) ?>">
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
                <section class="bg-white p-6 rounded-xl shadow-lg border border-gray-200 mt-8">
                    <h2 class="text-2xl font-bold mb-4 text-gray-800">View Report</h2>
                    <p class="text-gray-600 mb-4">Click the button below to view a comprehensive report of all sales, cars, and clients.</p>
                    <div class="grid grid-cols-1">
                        <a href="generate_report.php" class="bg-gray-100 text-gray-800 hover:bg-gray-200 py-3 px-4 rounded-lg shadow-sm transition-colors duration-200 flex items-center justify-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                                <polyline points="10 9 9 9 8 9"></polyline>
                            </svg>
                            See Full Report
                        </a>
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
            <h2 id="modalTitle" class="text-2xl font-bold mb-4 text-gray-900">Add New Sale</h2>
            <form id="saleForm" method="POST" action="">
                <input type="hidden" id="saleId" name="id">
                <input type="hidden" name="action" value="add_sale">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="car_chassis_no" class="block text-sm font-medium text-gray-700">Car Chassis No</label>
                        <select id="car_chassis_no" name="car_chassis_no" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                            <option value="">Select a Car Chassis No</option>
                            <?php foreach ($car_chassis_numbers as $chassis): ?>
                                <option value="<?= htmlspecialchars($chassis) ?>"><?= htmlspecialchars($chassis) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="client_id" class="block text-sm font-medium text-gray-700">Client Name</label>
                        <select id="client_id" name="client_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                            <option value="">Select a Client</option>
                            <?php foreach ($clients_list as $client): ?>
                                <option value="<?= htmlspecialchars($client['client_id']) ?>"><?= htmlspecialchars($client['client_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="sale_date" class="block text-sm font-medium text-gray-700">Sale Date</label>
                        <input type="date" id="sale_date" name="sale_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" required>
                    </div>
                    <div>
                        <label for="duty_bdt" class="block text-sm font-medium text-gray-700">Duty (BDT)</label>
                        <input type="number" step="0.01" id="duty_bdt" name="duty_bdt" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" value="0.00">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="bank_charge_bdt" class="block text-sm font-medium text-gray-700">Bank Charge (BDT)</label>
                        <input type="number" step="0.01" id="bank_charge_bdt" name="bank_charge_bdt" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" value="0.00">
                    </div>
                    <div>
                        <label for="c_f_charge_bdt" class="block text-sm font-medium text-gray-700">C&F Charge (BDT)</label>
                        <input type="number" step="0.01" id="c_f_charge_bdt" name="c_f_charge_bdt" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" value="0.00">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="transportation_bdt" class="block text-sm font-medium text-gray-700">Transportation (BDT)</label>
                        <input type="number" step="0.01" id="transportation_bdt" name="transportation_bdt" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" value="0.00">
                    </div>
                    <div>
                        <label for="other_cost_bdt" class="block text-sm font-medium text-gray-700">Other Cost (BDT)</label>
                        <input type="number" step="0.01" id="other_cost_bdt" name="other_cost_bdt" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" value="0.00">
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="profit_bdt" class="block text-sm font-medium text-gray-700">Profit (BDT)</label>
                        <input type="number" step="0.01" id="profit_bdt" name="profit_bdt" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" value="0.00">
                    </div>
                    <div>
                        <label for="payment_status" class="block text-sm font-medium text-gray-700">Payment Status</label>
                        <select id="payment_status" name="payment_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="Pending">Pending</option>
                            <option value="Paid">Paid</option>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end gap-2 mt-6">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded-md hover:bg-gray-300 transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors">Save Sale</button>
                </div>
            </form>
        </div>
    </div>
   
    <div id="message-box" class="message-box-overlay fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="w-full max-w-sm mx-auto bg-white rounded-xl shadow-2xl p-6 relative">
            <div class="text-center">
                <h3 id="message-box-title" class="font-semibold tracking-tight text-xl mb-4"></h3>
                <p id="message-box-content" class="text-gray-700 mb-6"></p>
                <button
                    onclick="closeMessageBox()"
                    class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-md transition-all duration-300 transform hover:scale-105"
                >
                    OK
                </button>
            </div>
        </div>
    </div>

    <iframe id="downloadFrame" style="display: none;"></iframe>

    <script>
        const addEditModal = document.getElementById('addEditModal');
        const modalTitle = document.getElementById('modalTitle');
        const saleForm = document.getElementById('saleForm');
        const saleId = document.getElementById('saleId');
        const carChassisNo = document.getElementById('car_chassis_no');
        const clientId = document.getElementById('client_id');
        const saleDate = document.getElementById('sale_date');
        const paymentStatus = document.getElementById('payment_status'); 
        const dutyBdt = document.getElementById('duty_bdt');
        const bankChargeBdt = document.getElementById('bank_charge_bdt');
        const cnFChargeBdt = document.getElementById('c_f_charge_bdt');
        const transportationBdt = document.getElementById('transportation_bdt');
        const otherCostBdt = document.getElementById('other_cost_bdt');
        const profitBdt = document.getElementById('profit_bdt');

        function openAddModal() {
            saleId.value = '';
            modalTitle.innerText = 'Add New Sale';
            saleForm.reset();
            addEditModal.classList.add('active');
        }

        function openEditModal(sale) {
            saleId.value = sale.id;
            modalTitle.innerText = 'Edit Sale';
            carChassisNo.value = sale.carId;
            clientId.value = sale.clientId;
            saleDate.value = sale.saleDate;
            paymentStatus.value = sale.paymentStatus;
            dutyBdt.value = sale.dutyBdt;
            bankChargeBdt.value = sale.bankChargeBdt;
            cnFChargeBdt.value = sale.cnFChargeBdt;
            transportationBdt.value = sale.transportationBdt;
            otherCostBdt.value = sale.otherCostBdt;
            profitBdt.value = sale.profitBdt;
            
            addEditModal.classList.add('active');
        }

        function closeModal() {
            addEditModal.classList.remove('active');
        }

        function filterTable() {
            var input, filter, table, tr, td, i, j, txtValue;
            input = document.getElementById("searchQuery");
            filter = input.value.toUpperCase();
            table = document.getElementById("salesTable");
            tr = table.getElementsByTagName("tr");
            for (i = 1; i < tr.length; i++) {
                tr[i].style.display = "none";
                td = tr[i].getElementsByTagName("td");
                for (j = 0; j < td.length; j++) {
                    // Check Chassis No (1), Client Name (2), and Status (11) columns
                    if (j == 1 || j == 2 || j == 11) {
                        txtValue = td[j].textContent || td[j].innerText;
                        if (txtValue.toUpperCase().indexOf(filter) > -1) {
                            tr[i].style.display = "";
                            break;
                        }
                    }
                }
            }
        }

        // Message Box functionality (replaces alert())
        const messageBox = document.getElementById('message-box');
        const messageBoxTitle = document.getElementById('message-box-title');
        const messageBoxContent = document.getElementById('message-box-content');
        const downloadFrame = document.getElementById('downloadFrame');

        function showMessageBox(title, content) {
            messageBoxTitle.textContent = title;
            messageBoxContent.textContent = content;
            messageBox.style.display = 'flex';
            setTimeout(() => messageBox.classList.add('active'), 10);
        }

        function closeMessageBox() {
            messageBox.classList.remove('active');
            setTimeout(() => messageBox.style.display = 'none', 300);
        }

    </script>
</body>
</html>