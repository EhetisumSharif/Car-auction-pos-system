<?php
// home.php
include('db_connect.php');
session_start();

// --- Currency Rate Management Logic ---
$currency_message = '';
$all_currency_rates = [];

// Handle delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_rate'])) {
    $rate_id_to_delete = filter_input(INPUT_POST, 'rate_id_to_delete', FILTER_VALIDATE_INT);
    if ($rate_id_to_delete) {
        try {
            $delete_stmt = $db->prepare("DELETE FROM currency_exchange_rate WHERE rate_id = ?");
            $delete_stmt->execute([$rate_id_to_delete]);
            $currency_message .= "Exchange rate deleted successfully.";
        } catch (PDOException $e) {
            $currency_message .= "Database error deleting rate: " . htmlspecialchars($e->getMessage()) . '. ';
        }
    } else {
        $currency_message .= "Invalid rate ID provided for deletion.";
    }
}

// Handle edit request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_rate_submit'])) {
    $rate_id_to_edit = filter_input(INPUT_POST, 'edit_rate_id', FILTER_VALIDATE_INT);
    $new_usd_to_jpy = filter_input(INPUT_POST, 'edit_usd_to_jpy', FILTER_VALIDATE_FLOAT);
    $new_usd_to_bdt = filter_input(INPUT_POST, 'edit_usd_to_bdt', FILTER_VALIDATE_FLOAT);
    $new_under_invoice_rate = filter_input(INPUT_POST, 'edit_under_invoice_rate', FILTER_VALIDATE_FLOAT); // New field
    $new_rate_date = $_POST['edit_rate_date'] ?? null;

    if ($rate_id_to_edit && $new_usd_to_jpy !== false && $new_usd_to_jpy > 0 && $new_usd_to_bdt !== false && $new_usd_to_bdt > 0 && $new_under_invoice_rate !== false && $new_under_invoice_rate > 0 && $new_rate_date) {
        try {
            $new_jpy_to_usd_rate = 1 / $new_usd_to_jpy;
            $update_stmt = $db->prepare("UPDATE currency_exchange_rate SET jpy_to_usd_rate = ?, usd_to_jpy_rate = ?, usd_to_bdt_rate = ?, under_invoice_rate = ?, rate_date = ? WHERE rate_id = ?");
            $update_stmt->execute([$new_jpy_to_usd_rate, $new_usd_to_jpy, $new_usd_to_bdt, $new_under_invoice_rate, $new_rate_date, $rate_id_to_edit]);
            $currency_message .= "Exchange rate updated successfully.";
        } catch (PDOException $e) {
            $currency_message .= "Database error updating rate: " . htmlspecialchars($e->getMessage()) . '. ';
        }
    } else {
        $currency_message .= "Invalid data provided for updating rate.";
    }
}

// Handle new rate submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_rates'])) {
    $new_usd_jpy = filter_input(INPUT_POST, 'usd_to_jpy_rate', FILTER_VALIDATE_FLOAT);
    $new_usd_bdt = filter_input(INPUT_POST, 'usd_to_bdt_rate', FILTER_VALIDATE_FLOAT);
    $new_under_invoice_rate = filter_input(INPUT_POST, 'under_invoice_rate', FILTER_VALIDATE_FLOAT);
    $new_rate_date = $_POST['rate_date'] ?? null;

    if ($new_usd_jpy !== false && $new_usd_jpy > 0 && $new_usd_bdt !== false && $new_usd_bdt > 0 && $new_under_invoice_rate !== false && $new_under_invoice_rate > 0 && $new_rate_date) {
        try {
            // Calculate JPY to USD from USD to JPY
            $new_jpy_usd_rate = 1 / $new_usd_jpy;
            $insert_stmt = $db->prepare("INSERT INTO currency_exchange_rate (`jpy_to_usd_rate`, `usd_to_jpy_rate`, `usd_to_bdt_rate`, `under_invoice_rate`, `rate_date`) VALUES (?, ?, ?, ?, ?)");
            $insert_stmt->execute([$new_jpy_usd_rate, $new_usd_jpy, $new_usd_bdt, $new_under_invoice_rate, $new_rate_date]);
            $currency_message .= 'New exchange rates added successfully.';
        } catch (PDOException $e) {
            $currency_message .= "Database error adding new rates: " . htmlspecialchars($e->getMessage()) . '. ';
        }
    } else {
        $currency_message .= 'Invalid rates provided. All rates must be positive numbers and a date must be selected.';
    }
}

// Fetch all currency rates from the database, ordered by date
try {
    // Search logic for historical rates
    $search_date = $_GET['search_rate_date'] ?? '';
    $sql_currency = "SELECT rate_id, jpy_to_usd_rate, usd_to_jpy_rate, usd_to_bdt_rate, under_invoice_rate, rate_date FROM currency_exchange_rate";
    $params_currency = [];

    if (!empty($search_date)) {
        $sql_currency .= " WHERE rate_date = ?";
        $params_currency[] = $search_date;
    }

    $sql_currency .= " ORDER BY rate_date DESC";

    $stmt_currency = $db->prepare($sql_currency);
    $stmt_currency->execute($params_currency);
    $all_currency_rates = $stmt_currency->fetchAll(PDO::FETCH_ASSOC);

    // Use the latest rates for the main currency rate form
    $latest_rates = $all_currency_rates[0] ?? null;
    $currentUSDtoJPYRate = isset($latest_rates['usd_to_jpy_rate']) ? (float)$latest_rates['usd_to_jpy_rate'] : 140.0;
    $currentUSDtoBDTRate = isset($latest_rates['usd_to_bdt_rate']) ? (float)$latest_rates['usd_to_bdt_rate'] : 110.0;
    $currentUnderInvoiceRate = isset($latest_rates['under_invoice_rate']) ? (float)$latest_rates['under_invoice_rate'] : 115.0; // Default value for new field

} catch (PDOException $e) {
    $currency_message .= "Database error fetching rates: " . htmlspecialchars($e->getMessage());
    $currentUSDtoJPYRate = 140.0;
    $currentUSDtoBDTRate = 110.0;
    $currentUnderInvoiceRate = 115.0;
    $all_currency_rates = [];
}

$totalCars = 0;
$availableCars = 0;
$carsInTransit = 0;
$pendingShipments = 0;
$filteredResults = [];
$message = "";

try {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $searchCarModel = $_GET['car_model'] ?? '';
    $searchChassisNumber = $_GET['chassis_number'] ?? '';
    $searchEngineNumber = $_GET['engine_number'] ?? '';
    $searchShipmentStatus = $_GET['shipment_status'] ?? '';
    $searchClientName = $_GET['client_name'] ?? '';
    $searchLcNumber = $_GET['lc_number'] ?? '';

    $totalCars = $db->query("SELECT COUNT(*) FROM car_details")->fetchColumn() ?? 0;
    $availableCars = $db->query("SELECT COUNT(*) FROM car_details cd LEFT JOIN shipment_details st ON cd.chassis_number = st.chassis_number WHERE st.chassis_number IS NULL")->fetchColumn() ?? 0;
    $carsInTransit = $db->query("SELECT COUNT(*) FROM shipment_details WHERE `status` = 'In Transit'")->fetchColumn() ?? 0;
    $pendingShipments = $db->query("SELECT COUNT(*) FROM shipment_details WHERE `status` = 'Pending'")->fetchColumn() ?? 0;

    // --- Build dynamic SQL query for advanced search ---
$sql = "
    SELECT
        cd.model AS carModel,
        cd.chassis_number AS chassisNumber,
        cd.engine_number AS engineNumber,
        ap.auction_datetime AS purchaseDate,
        lc.lc_number AS lcNumber,
        lc.payment_date AS lcPaymentDate,
        st.status AS shipmentStatus,
        st.estimated_time_of_departure AS etd,
        st.estimated_time_of_arrival AS eta,
        s.selling_price_bdt AS sellingPriceBDT,
        c.client_name AS clientName
    FROM
        car_details cd
    LEFT JOIN
        auction_and_pricing ap ON cd.chassis_number = ap.chassis_number
    LEFT JOIN
        shipment_details st ON cd.chassis_number = st.chassis_number
    LEFT JOIN
        lc ON cd.chassis_number = lc.car_chassis_no
    LEFT JOIN
        sales s ON cd.chassis_number = s.car_chassis_no
    LEFT JOIN
        client c ON s.client_id = c.client_id
    WHERE
        1 = 1
";

    $params = [];

    if (!empty($searchCarModel)) {
        $sql .= " AND cd.model LIKE ?";
        $params[] = '%' . $searchCarModel . '%';
    }
    if (!empty($searchChassisNumber)) {
        $sql .= " AND cd.chassis_number LIKE ?";
        $params[] = '%' . $searchChassisNumber . '%';
    }
    if (!empty($searchEngineNumber)) {
        $sql .= " AND cd.engine_number LIKE ?";
        $params[] = '%' . $searchEngineNumber . '%';
    }
    if (!empty($searchLcNumber)) {
        $sql .= " AND lc.lc_number LIKE ?";
        $params[] = '%' . $searchLcNumber . '%';
    }
    if (!empty($searchShipmentStatus)) {
        $sql .= " AND st.status = ?";
        $params[] = $searchShipmentStatus;
    }
    if (!empty($searchClientName)) {
        $sql .= " AND c.client_name LIKE ?";
        $params[] = '%' . $searchClientName . '%';
    }

    $sql .= " ORDER BY cd.yard_arrival_date DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $filteredResults = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $message = "Database Error: " . htmlspecialchars($e->getMessage());
    $totalCars = 0;
    $availableCars = 0;
    $carsInTransit = 0;
    $pendingShipments = 0;
    $filteredResults = [];
}

date_default_timezone_set('Asia/Dhaka');
$currentDate = date('l, F j, Y');
$currentTime = date('g:i A');
$username = 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="icon" type="image/jpeg" href="pic/logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        .modal-overlay, .message-box-overlay {
            background-color: rgba(0, 0, 0, 0.5);
            transition: opacity 0.3s ease-in-out;
            opacity: 0;
            pointer-events: none;
        }
        .modal-overlay.active, .message-box-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }
        .message-box.error {
            background-color: #fee2e2;
            color: #ef4444;
            border: 1px solid #fca5a5;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.5rem;
        }
        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border-width: 0;
        }
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
                    <li><a href="home.php" class="active"><i class="fas fa-home icon"></i> Dashboard</a></li>
                    <li><a href="car_info.php"><i class="fas fa-car icon"></i> Car Information</a></li>
                    <li><a href="shipment_track.php"><i class="fas fa-truck icon"></i> Shipment Tracking</a></li>
                    <li><a href="lc.php"><i class="fas fa-folder-open icon"></i> LC Management</a></li>
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
            <div class="p-4 md:p-8 max-w-full mx-auto">
                 <?php if (!empty($message)): ?>
                    <div class="message-box error">
                        <p class="font-bold">Error:</p>
                        <p><?= $message ?></p>
                    </div>
                <?php endif; ?>
                <section class="mb-4 md:mb-8 p-4 md:p-6 bg-white rounded-xl shadow-lg border border-gray-200">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                        <div>
                            <h1 class="text-2xl md:text-4xl font-extrabold tracking-tight text-gray-900 lg:text-5xl">
                                Welcome back, <?= htmlspecialchars($username) ?>!
                            </h1>
                            <p class="mt-1 md:mt-2 text-sm md:text-lg text-gray-500">
                                Today is <?= htmlspecialchars($currentDate) ?>.
                            </p>
                        </div>
                        <div class="text-xl md:text-3xl font-bold text-gray-700">
                            <?= htmlspecialchars($currentTime) ?>
                        </div>
                    </div>
                </section>
                <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-4 md:mb-8">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-4 md:p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Total Cars</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-indigo-500">
                                <path d="M19 17h-1.6c-.3-.9-.7-1.8-1.2-2.7a2 2 0 0 1-2.4-.7c-.5-.8-1.1-1.6-1.8-2.3-1.6-1.6-3.4-2.5-5.3-2.5H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h1.6c.3.9.7 1.8 1.2 2.7a2 2 0 0 1 2.4.7c.5.8 1.1 1.6 1.8 2.3 1.6 1.6 3.4 2.5 5.3 2.5h3.1c1 0 2-1 2-2v-6a2 2 0 0 0-2-2z"></path>
                                <circle cx="8.5" cy="19.5" r="1.5"></circle>
                                <circle cx="15.5" cy="19.5" r="1.5"></circle>
                            </svg>
                        </div>
                        <div>
                            <div class="text-3xl md:text-4xl font-bold"><?= htmlspecialchars($totalCars) ?></div>
                            <p class="text-xs text-gray-400 mt-1">Total cars in the system</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-4 md:p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Cars in Transit</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-yellow-500">
                                <path d="M22 12V6l-2-2H4L2 6v6l2 2h4v6h10V14h4v-2z"></path>
                                <circle cx="6" cy="18" r="2"></circle>
                                <circle cx="18" cy="18" r="2"></circle>
                                <path d="M12 12v6"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-3xl md:text-4xl font-bold"><?= htmlspecialchars($carsInTransit) ?></div>
                            <p class="text-xs text-gray-400 mt-1">Currently being shipped</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-4 md:p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Pending Shipments</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-red-500">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                        </div>
                        <div>
                            <div class="text-3xl md:text-4xl font-bold"><?= htmlspecialchars($pendingShipments) ?></div>
                            <p class="text-xs text-gray-400 mt-1">Shipments awaiting departure</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-4 md:p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Available Cars</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-green-500">
                                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                                <polyline points="22 4 12 14.01 9 11.01"></polyline>
                            </svg>
                        </div>
                        <div>
                            <div class="text-3xl md:text-4xl font-bold"><?= htmlspecialchars($availableCars) ?></div>
                            <p class="text-xs text-gray-400 mt-1">Cars ready for sale</p>
                        </div>
                    </div>
                </section>
                <section class="bg-white p-4 md:p-6 rounded-xl shadow-lg border border-gray-200 mb-4 md:mb-8">
                    <h2 class="text-xl md:text-2xl font-bold mb-4 text-gray-800">Search Cars & Results</h2>
                    <form action="" method="get" class="space-y-4 mb-8">
						<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
							<div>
								<label for="car_model" class="block text-sm font-medium text-gray-700">Car Model</label>
								<input type="text" name="car_model" id="car_model" value="<?= htmlspecialchars($searchCarModel) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="e.g., Toyota Vitz">
							</div>
							<div>
								<label for="chassis_number" class="block text-sm font-medium text-gray-700">Chassis Number</label>
								<input type="text" name="chassis_number" id="chassis_number" value="<?= htmlspecialchars($searchChassisNumber) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="e.g., KSP130-1234567">
							</div>
							<div>
								<label for="engine_number" class="block text-sm font-medium text-gray-700">Engine Number</label>
								<input type="text" name="engine_number" id="engine_number" value="<?= htmlspecialchars($searchEngineNumber) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="e.g., 1KR-FE-1234567">
							</div>
							<div>
								<label for="lc_number" class="block text-sm font-medium text-gray-700">LC Number</label>
								<input type="text" name="lc_number" id="lc_number" value="<?= htmlspecialchars($searchLcNumber) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="e.g., LC-9876543">
							</div>
							<div>
								<label for="shipment_status" class="block text-sm font-medium text-gray-700">Shipment Status</label>
								<select name="shipment_status" id="shipment_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
									<option value="">All</option>
									<option value="In Transit" <?= $searchShipmentStatus === 'In Transit' ? 'selected' : '' ?>>In Transit</option>
									<option value="Completed" <?= $searchShipmentStatus === 'Completed' ? 'selected' : '' ?>>Completed</option>
									<option value="Pending" <?= $searchShipmentStatus === 'Pending' ? 'selected' : '' ?>>Pending</option>
									<option value="Arrived" <?= $searchShipmentStatus === 'Arrived' ? 'selected' : '' ?>>Arrived</option>
								</select>
							</div>
							<div>
								<label for="client_name" class="block text-sm font-medium text-gray-700">Client Name</label>
								<input type="text" name="client_name" id="client_name" value="<?= htmlspecialchars($searchClientName) ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm" placeholder="e.g., John Smith">
							</div>
						</div>
                        <div class="flex justify-end space-x-2">
                            <a href="home.php" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md shadow-sm hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors">
                                Reset
                            </a>
                            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                Search
                            </button>
                        </div>
                    </form>
                    <h3 class="text-lg md:text-xl font-bold mb-4 text-gray-800">Results</h3>
                    <div class="hidden sm:block w-full overflow-auto">
                        <table id="searchResultsTable" class="w-full caption-bottom text-sm">
                            <thead class="[&_tr]:border-b">
                                <tr class="bg-gray-50">
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">Car Model</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">Chassis No.</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">Engine No.</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">Purchase Date</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">LC Number</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">LC Payment Date</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">Shipment Status</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">ETD</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">ETA</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">Selling Price (BDT)</th>
                                    <th class="h-8 px-2 py-1 text-left align-middle text-xs font-bold text-gray-700">Client Name</th>
                                </tr>
                            </thead>
                            <tbody class="[&_tr:last-child]:border-0">
                                <?php if (empty($filteredResults)): ?>
                                    <tr>
                                        <td colspan="11" class="p-4 text-center text-gray-500">No results found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($filteredResults as $result): ?>
                                        <tr class="border-b transition-colors hover:bg-gray-100 duration-200">
                                            <td class="px-2 py-1 align-middle text-xs font-medium whitespace-nowrap"><?= htmlspecialchars($result['carModel']) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars($result['chassisNumber']) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars($result['engineNumber']) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime($result['purchaseDate']))) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars($result['lcNumber']) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime($result['lcPaymentDate']))) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap">
                                                <?php
                                                    $shipmentStatusClass = 'bg-gray-100 text-gray-800';
                                                    $shipmentStatus = strtolower($result['shipmentStatus']);
                                                    if ($shipmentStatus === 'in transit') {
                                                        $shipmentStatusClass = 'bg-yellow-100 text-yellow-800';
                                                    } else if ($shipmentStatus === 'completed') {
                                                        $shipmentStatusClass = 'bg-green-100 text-green-800';
                                                    } else if ($shipmentStatus === 'pending') {
                                                         $shipmentStatusClass = 'bg-red-100 text-red-800';
                                                    }
                                                ?>
                                                <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $shipmentStatusClass ?>">
                                                    <?= htmlspecialchars($result['shipmentStatus']) ?>
                                                </span>
                                            </td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime($result['etd']))) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars(date('d/m/Y', strtotime($result['eta']))) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars($result['sellingPriceBDT']) ?></td>
                                            <td class="px-2 py-1 align-middle text-xs whitespace-nowrap"><?= htmlspecialchars($result['clientName']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="sm:hidden grid gap-4">
                        <?php if (empty($filteredResults)): ?>
                            <div class="p-4 text-center text-gray-500">No results found.</div>
                        <?php else: ?>
                            <?php foreach ($filteredResults as $result): ?>
                                <div class="bg-gray-50 p-4 rounded-lg shadow-md border border-gray-200 text-xs">
                                    <div class="grid grid-cols-2 gap-2">
                                        <div class="font-bold text-gray-700">Car Model:</div>
                                        <div class="text-right"><?= htmlspecialchars($result['carModel']) ?></div>
                                        <div class="font-bold text-gray-700">Chassis No.:</div>
                                        <div class="text-right truncate"><?= htmlspecialchars($result['chassisNumber']) ?></div>
                                        <div class="font-bold text-gray-700">Engine No.:</div>
                                        <div class="text-right truncate"><?= htmlspecialchars($result['engineNumber']) ?></div>
                                        <div class="font-bold text-gray-700">Purchase Date:</div>
                                        <div class="text-right"><?= htmlspecialchars($result['purchaseDate']) ?></div>
                                        <div class="font-bold text-gray-700">LC Number:</div>
                                        <div class="text-right"><?= htmlspecialchars($result['lcNumber']) ?></div>
                                        <div class="font-bold text-gray-700">LC Payment Date:</div>
                                        <div class="text-right"><?= htmlspecialchars($result['lcPaymentDate']) ?></div>
                                        <div class="font-bold text-gray-700">Shipment Status:</div>
                                        <div class="text-right">
                                            <?php
                                                $shipmentStatusClass = 'bg-gray-100 text-gray-800';
                                                $shipmentStatus = strtolower($result['shipmentStatus']);
                                                if ($shipmentStatus === 'in transit') {
                                                    $shipmentStatusClass = 'bg-yellow-100 text-yellow-800';
                                                } else if ($shipmentStatus === 'completed') {
                                                    $shipmentStatusClass = 'bg-green-100 text-green-800';
                                                } else if ($shipmentStatus === 'pending') {
                                                        $shipmentStatusClass = 'bg-red-100 text-red-800';
                                                }
                                            ?>
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $shipmentStatusClass ?>">
                                                <?= htmlspecialchars($result['shipmentStatus']) ?>
                                            </span>
                                        </div>
                                        <div class="font-bold text-gray-700">ETD:</div>
                                        <div class="text-right"><?= htmlspecialchars($result['etd']) ?></div>
                                        <div class="font-bold text-gray-700">ETA:</div>
                                        <div class="text-right"><?= htmlspecialchars($result['eta']) ?></div>
                                        <div class="font-bold text-gray-700">Selling Price (USD):</div>
                                        <div class="text-right"><?= htmlspecialchars($result['sellingPriceBDT']) ?></div>
                                        <div class="font-bold text-gray-700">Client Name:</div>
                                        <div class="text-right"><?= htmlspecialchars($result['clientName']) ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="bg-white p-4 md:p-6 rounded-xl shadow-lg border border-gray-200 mb-4 md:mb-8">
                    <h2 class="text-xl md:text-2xl font-bold text-gray-800 mb-4">Currency Exchange Rate</h2>
                    <?php if (!empty($currency_message)): ?>
                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-md mb-4" role="alert">
                            <p class="font-bold">Update Status</p>
                            <p class="text-sm"><?= htmlspecialchars($currency_message) ?></p>
                        </div>
                    <?php endif; ?>
                    <p class="text-gray-600 mb-4">
                        Please enter the latest exchange rates below.
                    </p>
                    <form action="" method="POST" class="space-y-4">
                        <input type="hidden" name="update_rates" value="1" />
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <div>
                                <label for="rate_date" class="block text-sm font-medium text-gray-700">Date</label>
                                <input
                                    type="date"
                                    id="rate_date"
                                    name="rate_date"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    required
                                >
                            </div>
                            <div>
                                <label for="usd_to_jpy_rate" class="block text-sm font-medium text-gray-700">USD to JPY Rate</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    id="usd_to_jpy_rate"
                                    name="usd_to_jpy_rate"
                                    value="<?= htmlspecialchars($currentUSDtoJPYRate) ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    required
                                >
                            </div>
                            <div>
                                <label for="usd_to_bdt_rate" class="block text-sm font-medium text-gray-700">USD to BDT Rate</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    id="usd_to_bdt_rate"
                                    name="usd_to_bdt_rate"
                                    value="<?= htmlspecialchars($currentUSDtoBDTRate) ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    required
                                >
                            </div>
                            <div>
                                <label for="under_invoice_rate" class="block text-sm font-medium text-gray-700">Under Invoice Rate (USD to BDT)</label>
                                <input
                                    type="number"
                                    step="0.01"
                                    id="under_invoice_rate"
                                    name="under_invoice_rate"
                                    value="<?= htmlspecialchars($currentUnderInvoiceRate) ?>"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                                    required
                                >
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button
                                type="submit"
                                class="px-4 py-2 bg-blue-600 text-white rounded-md shadow-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
                            >
                                Add New Rates
                            </button>
                        </div>
                    </form>
                    <div class="mt-6">
                        <h3 class="text-lg font-semibold text-gray-800 mb-2">Historical Exchange Rates</h3>
                        <form action="" method="get" class="flex flex-col md:flex-row gap-2 mb-4">
                            <label for="search_rate_date" class="sr-only">Search by Date</label>
                            <input
                                type="date"
                                name="search_rate_date"
                                id="search_rate_date"
                                value="<?= htmlspecialchars($search_date) ?>"
                                class="block w-full md:w-auto rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                            >
                            <button type="submit" class="w-full md:w-auto px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-md shadow-sm hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors">
                                Search
                            </button>
                            <a href="home.php" class="w-full md:w-auto px-4 py-2 text-sm font-medium text-gray-700 bg-gray-200 rounded-md shadow-sm hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors text-center">
                                Reset
                            </a>
                        </form>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">USD to JPY</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">JPY to USD</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">USD to BDT</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Under Invoice Rate (USD to BDT)</th>
                                        <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (empty($all_currency_rates)): ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">No historical data available.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_currency_rates as $rate): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?= htmlspecialchars(date('d/m/Y', strtotime($rate['rate_date']))) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars(number_format($rate['usd_to_jpy_rate'], 6)) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars(number_format($rate['jpy_to_usd_rate'], 6)) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars(number_format($rate['usd_to_bdt_rate'], 6)) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars(number_format($rate['under_invoice_rate'], 6)) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                                    <button onclick="openEditModal(<?= htmlspecialchars($rate['rate_id']) ?>, '<?= htmlspecialchars($rate['usd_to_jpy_rate']) ?>', '<?= htmlspecialchars($rate['usd_to_bdt_rate']) ?>', '<?= htmlspecialchars($rate['under_invoice_rate']) ?>', '<?= htmlspecialchars($rate['rate_date']) ?>')" class="text-indigo-600 hover:text-indigo-900">
                                                        <i class="fa-solid fa-pen-to-square"></i><span class="sr-only">Edit</span>
                                                    </button>
                                                    <button onclick="confirmDelete(<?= htmlspecialchars($rate['rate_id']) ?>)" class="text-red-600 hover:text-red-900">
                                                        <i class="fas fa-trash-alt"></i><span class="sr-only">Delete</span>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>
			 <footer class="bg-gray-800 text-gray-400 text-center p-4">
                <p>The SAD Six | Developers</p>
            </footer>
        </div>
	   <div class="right-sidebar">

    </div>
    </div>
    <div id="editRateModal" class="fixed inset-0 z-50 overflow-y-auto hidden items-center justify-center p-4 modal-overlay">
        <div class="bg-white rounded-xl shadow-2xl p-6 w-full max-w-lg mx-auto">
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-semibold tracking-tight text-xl">Edit Exchange Rate</h3>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
            </div>
            <form id="editRateForm" action="" method="POST" class="space-y-4">
                <input type="hidden" name="edit_rate_submit" value="1" />
                <input type="hidden" name="edit_rate_id" id="edit_rate_id" />
                <div>
                    <label for="edit_rate_date" class="block text-sm font-medium text-gray-700">Date</label>
                    <input
                        type="date"
                        id="edit_rate_date"
                        name="edit_rate_date"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        required
                    >
                </div>
                <div>
                    <label for="edit_usd_to_jpy" class="block text-sm font-medium text-gray-700">USD to JPY Rate</label>
                    <input
                        type="number"
                        step="0.01"
                        id="edit_usd_to_jpy"
                        name="edit_usd_to_jpy"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        required
                    >
                </div>
                <div>
                    <label for="edit_usd_to_bdt" class="block text-sm font-medium text-gray-700">USD to BDT Rate</label>
                    <input
                        type="number"
                        step="0.01"
                        id="edit_usd_to_bdt"
                        name="edit_usd_to_bdt"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        required
                    >
                </div>
                <div>
                    <label for="edit_under_invoice_rate" class="block text-sm font-medium text-gray-700">Under Invoice Rate (USD to BDT)</label>
                    <input
                        type="number"
                        step="0.01"
                        id="edit_under_invoice_rate"
                        name="edit_under_invoice_rate"
                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                        required
                    >
                </div>
                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md shadow-md hover:bg-gray-300 transition-colors">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md shadow-md hover:bg-blue-700 transition-colors">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="message-box" class="fixed inset-0 z-50 overflow-y-auto hidden items-center justify-center p-4 message-box-overlay">
        <div class="w-full max-w-sm mx-auto bg-white rounded-xl shadow-2xl p-6 relative">
            <div class="text-center">
                <h3 id="message-box-title" class="font-semibold tracking-tight text-xl mb-4"></h3>
                <p id="message-box-content" class="text-gray-700 mb-6"></p>
                <div class="flex space-x-4">
                    <button
                        onclick="closeMessageBox()"
                        class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded-md transition-all duration-300"
                    >
                        Cancel
                    </button>
                    <form id="deleteForm" method="POST" action="" class="flex-1">
                        <input type="hidden" name="delete_rate" value="1" />
                        <input type="hidden" name="rate_id_to_delete" id="delete_rate_id" value="" />
                        <button
                            type="submit"
                            class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-md transition-all duration-300 transform hover:scale-105"
                        >
                            Delete
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script>
        const editRateModal = document.getElementById('editRateModal');
        const editRateId = document.getElementById('edit_rate_id');
        const editUSDtoJPY = document.getElementById('edit_usd_to_jpy');
        const editUSDtoBDT = document.getElementById('edit_usd_to_bdt');
        const editUnderInvoiceRate = document.getElementById('edit_under_invoice_rate'); // New element
        const editRateDate = document.getElementById('edit_rate_date');

        function openEditModal(rateId, usdToJpyRate, usdToBdtRate, underInvoiceRate, rateDate) {
            editRateId.value = rateId;
            editUSDtoJPY.value = usdToJpyRate;
            editUSDtoBDT.value = usdToBdtRate;
            editUnderInvoiceRate.value = underInvoiceRate; // Set new value
            editRateDate.value = rateDate;
            editRateModal.style.display = 'flex';
            setTimeout(() => editRateModal.classList.add('active'), 10);
        }

        function closeEditModal() {
            editRateModal.classList.remove('active');
            setTimeout(() => editRateModal.style.display = 'none', 300);
        }

        const messageBox = document.getElementById('message-box');
        const messageBoxTitle = document.getElementById('message-box-title');
        const messageBoxContent = document.getElementById('message-box-content');
        const deleteRateId = document.getElementById('delete_rate_id');

        function confirmDelete(rateId) {
            messageBoxTitle.textContent = "Confirm Deletion";
            messageBoxContent.textContent = "Are you sure you want to delete this exchange rate record? This action cannot be undone.";
            deleteRateId.value = rateId;
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