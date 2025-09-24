<?php
include('db_connect.php'); // Assumed to contain $db PDO connection
session_start();

// Initialize variables
$message = ""; // For success/error messages
$search_query = $_GET['search'] ?? '';

try {
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ----------------------------------------------------
    // Handle form submissions for updating and deleting shipments.
    // ----------------------------------------------------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle status-only update from the new "Update Status" modal
        if (isset($_POST['update_status_only'])) {
            $chassisNumberToUpdate = htmlspecialchars($_POST['chassisNumber_status']);
            $newStatus = htmlspecialchars($_POST['new_status']);

            if (!empty($chassisNumberToUpdate) && !empty($newStatus)) {
                // First, check if the chassis number exists in shipment_details
                $checkSql = "SELECT shipment_id FROM shipment_details WHERE chassis_number = ?";
                $checkStmt = $db->prepare($checkSql);
                $checkStmt->execute([$chassisNumberToUpdate]);
                $existingShipment = $checkStmt->fetch(PDO::FETCH_ASSOC);

                if ($existingShipment) {
                    $sql = "UPDATE shipment_details SET status = ? WHERE chassis_number = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute([$newStatus, $chassisNumberToUpdate]);
                    $_SESSION['success_message'] = "Status for Chassis Number " . $chassisNumberToUpdate . " updated successfully!";
                } else {
                    $_SESSION['error_message'] = "Error: Shipment with Chassis Number " . $chassisNumberToUpdate . " not found.";
                }
            } else {
                $_SESSION['error_message'] = "Error: Chassis Number and Status cannot be empty.";
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        // Handle full shipment update (from the existing edit modal for specific rows)
        if (isset($_POST['chassisNumber'])) { // We use chassisNumber to identify an update from the main table edit
            $shipmentId = $_POST['id'] ?? null;
            $vesselName = htmlspecialchars($_POST['vesselName']);
            $ChassisNumber = htmlspecialchars($_POST['chassisNumber']); // This is the identifier for the specific row being edited
            $PortofShipment = htmlspecialchars($_POST['PortofShipment']);
            $etd = htmlspecialchars($_POST['etd']);
            $eta = htmlspecialchars($_POST['eta']);
            $status = htmlspecialchars($_POST['status']); // This column is assumed to be added to shipment_details

            // Convert date strings to DateTime objects for comparison (and later formatting for DB)
            $etd_date_db = $etd ? (new DateTime($etd))->format('Y-m-d H:i:s') : null; // Changed to datetime format
            $eta_date_db = $eta ? (new DateTime($eta))->format('Y-m-d H:i:s') : null; // Changed to datetime format

            if ($shipmentId) { // Only proceed with update if shipmentId is present
                // Update existing shipment
                $sql = "UPDATE shipment_details SET
                            port_of_shipment = ?,
                            estimated_time_of_departure = ?,
                            vessel_name = ?,
                            estimated_time_of_arrival = ?,
                            status = ?
                        WHERE shipment_id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    $PortofShipment,
                    $etd_date_db,
                    $vesselName,
                    $eta_date_db,
                    $status,
                    $shipmentId
                ]);
                $_SESSION['success_message'] = "Shipment details updated successfully!";
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        // Delete a shipment
        if (isset($_POST['deleteId'])) {
            $deleteId = $_POST['deleteId'];
            $sql = "DELETE FROM shipment_details WHERE shipment_id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$deleteId]);
            $_SESSION['success_message'] = "Shipment deleted successfully!";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // ----------------------------------------------------
    // Data Retrieval for Display
    // ----------------------------------------------------
    $shipments = [];
    $search_term = '%' . $search_query . '%';

    // SQL query to fetch shipment data, joining with car_details for Model and Engine Number
    $sql_fetch_shipments = "
        SELECT
            sd.shipment_id,
            sd.chassis_number,
            sd.port_of_shipment,
            sd.estimated_time_of_departure,
            sd.vessel_name,
            sd.estimated_time_of_arrival,
            sd.status,
            cd.model,
            cd.engine_number
        FROM shipment_details sd
        LEFT JOIN car_details cd ON sd.chassis_number = cd.chassis_number
        WHERE sd.chassis_number LIKE :search
           OR sd.vessel_name LIKE :search
           OR sd.port_of_shipment LIKE :search
           OR sd.status LIKE :search
           OR cd.model LIKE :search
           OR cd.engine_number LIKE :search;
    ";
    $stmt_fetch_shipments = $db->prepare($sql_fetch_shipments);
    $stmt_fetch_shipments->bindParam(':search', $search_term, PDO::PARAM_STR);
    $stmt_fetch_shipments->execute();
    $shipments = $stmt_fetch_shipments->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all chassis numbers for the "Update Status" dropdown
    $chassisNumbersList = [];
    $sql_fetch_chassis_numbers = "SELECT chassis_number FROM shipment_details ORDER BY chassis_number ASC";
    $stmt_fetch_chassis_numbers = $db->prepare($sql_fetch_chassis_numbers);
    $stmt_fetch_chassis_numbers->execute();
    $chassisNumbersList = $stmt_fetch_chassis_numbers->fetchAll(PDO::FETCH_COLUMN);


    // Data for the dashboard cards and alerts
    $shipmentCounts = [
        'inTransit' => 0,
        'pending' => 0,
        'delivered' => 0,
    ];
    $alerts = [];
    $today = new DateTime();
    $oneWeekFromNow = (new DateTime())->modify('+7 days');

    foreach ($shipments as $shipment) {
        $status = strtolower($shipment['status'] ?? ''); // Handle case where status might be null if not added
        if (strpos($status, 'in transit') !== false) {
            $shipmentCounts['inTransit']++;
        } elseif (strpos($status, 'pending') !== false) {
            $shipmentCounts['pending']++;
        } elseif (strpos($status, 'delivered') !== false) {
            $shipmentCounts['delivered']++;
        }

        // Check for alerts (ETD/ETA within next 7 days)
        if ($shipment['estimated_time_of_departure'] || $shipment['estimated_time_of_arrival']) {
            $etd_date = $shipment['estimated_time_of_departure'] ? new DateTime($shipment['estimated_time_of_departure']) : null;
            $eta_date = $shipment['estimated_time_of_arrival'] ? new DateTime($shipment['estimated_time_of_arrival']) : null;

            if (($etd_date && $etd_date >= $today && $etd_date <= $oneWeekFromNow) ||
                ($eta_date && $eta_date >= $today && $eta_date <= $oneWeekFromNow)) {
                $alerts[] = $shipment;
            }
        }
    }

} catch (PDOException $e) {
    $_SESSION['error_message'] = "Database Error: " . htmlspecialchars($e->getMessage());
    $shipments = []; // Ensure the array is empty on error
}

// Function to format a date string
function formatDate($dateString) {
    if (!$dateString || $dateString == '0000-00-00 00:00:00' || $dateString == '0000-00-00') return 'N/A';
    return (new DateTime($dateString))->format('M d, Y');
}

// PHP-related code for user session
// if (!isset($_SESSION['user_id'])) {
//     header('Location: login.php');
//     exit();
// }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Shipment Tracking</title>
    <link rel="icon" type="image/jpeg" href="pic/logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body {
            font-family: 'Inter', sans-serif;
        }
        .modal-overlay {
            background-color: rgba(0, 0, 0, 0.5);
            transition: opacity 0.3s ease-in-out;
            opacity: 0;
            pointer-events: none;
        }
        .modal-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }
        /* Specific modal display styles */
        .modal {
            background-color: rgba(0, 0, 0, 0.5); /* Overlay */
        }
        .modal-content {
            position: relative;
            background: white;
            padding: 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            width: 90%;
            max-width: 350px; 
        }
        .close-button {
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            font-size: 2rem;
            cursor: pointer;
            color: #aaa;
        }
        .close-button:hover {
            color: #333;
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
                    <li><a href="home.php"><i class="fas fa-home icon"></i> Dashboard</a></li>
                    <li><a href="car_info.php"><i class="fas fa-car icon"></i> Car Information</a></li>
                    <li><a href="shipment_track.php" class="active"><i class="fas fa-truck icon"></i> Shipment Tracking</a></li>
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

            <div class="p-8 max-w-7xl mx-auto">
                <header class="flex flex-col md:flex-row items-center justify-between gap-4 mb-8">
                    <div class="text-center md:text-left">
                        <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 lg:text-5xl">Shipment Tracker</h1>
                        <p class="mt-2 text-lg text-gray-500">
                            Your comprehensive dashboard for managing car shipments.
                        </p>
                    </div>
                     <button id="openUpdateStatusModal" class="px-6 py-3 bg-indigo-600 text-white rounded-md shadow-md hover:bg-indigo-700 transition-colors duration-300 flex items-center gap-2">
                        <i class="fas fa-sync-alt"></i> Update Status
                    </button>
                </header>

                 <?php if (isset($_SESSION['success_message'])): ?>
                    <div id="message-box" class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?= $_SESSION['success_message']; ?></span>
                        <?php unset($_SESSION['success_message']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div id="message-box" class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <span class="block sm:inline"><?= $_SESSION['error_message']; ?></span>
                        <?php unset($_SESSION['error_message']); ?>
                    </div>
                <?php endif; ?>


                <section class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">In Transit</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-blue-500">
                                <path d="M5 18H3c-1.1 0-2-.9-2-2V8c0-1.1.9-2 2-2h3l3.5 3.5M10 6.5l4-4M10 6.5l-4 4"></path>
                                <circle cx="18" cy="18" r="3"></circle>
                                <path d="M15 18h2v-3a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v7a4 4 0 0 0 4 4h5"></path>
                                <circle cx="7.5" cy="18" r="3"></circle>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $shipmentCounts['inTransit'] ?></div>
                            <p class="text-xs text-gray-400 mt-1">Shipments currently moving</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Pending</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-yellow-500">
                                <path d="M12.89 1.45l8 4a2 2 0 0 1 1.11 1.79v10a2 2 0 0 1-1.11 1.79l-8 4a2 2 0 0 1-1.78 0l-8-4a2 2 0 0 1-1.11-1.79v-10a2 2 0 0 1 1.11-1.79l8-4a2 2 0 0 1 1.78 0z"></path>
                                <path d="M12 2v20"></path>
                                <path d="M12 12L2.5 7"></path>
                                <path d="M12 12l9.5-5"></path>
                                <path d="M12 12v10"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $shipmentCounts['pending'] ?></div>
                            <p class="text-xs text-gray-400 mt-1">Awaiting departure</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-xl shadow-lg border border-gray-200 p-6 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex flex-row items-center justify-between space-y-0 pb-2">
                            <h3 class="text-sm font-medium text-gray-500">Delivered</h3>
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="h-6 w-6 text-green-500">
                                <path d="M19 17.5a2.5 2.5 0 0 1 0 5H5a2.5 2.5 0 0 1 0-5z"></path>
                                <path d="M22 17.5V11a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v6.5"></path>
                                <path d="M10 9l3-4h3l3 4"></path>
                            </svg>
                        </div>
                        <div>
                            <div class="text-4xl font-bold"><?= $shipmentCounts['delivered'] ?></div>
                            <p class="text-xs text-gray-400 mt-1">Successfully arrived</p>
                        </div>
                    </div>
                </section>

                <?php if (!empty($alerts)): ?>
                    <section class="mb-8">
                        <h2 class="text-2xl font-bold mb-4 text-gray-800">Upcoming ETD/ETA Alerts</h2>
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
                                       Alert for <?= htmlspecialchars($alert['model'] ?? 'N/A') ?> (Chassis: <?= htmlspecialchars($alert['chassis_number']) ?>)
                                    </h3>
                                    <p class="text-sm text-yellow-700 mt-1">Vessel: <?= htmlspecialchars($alert['vessel_name']) ?></p>
                                    <?php if ($alert['estimated_time_of_departure']): ?>
                                        <p class="text-sm text-yellow-700 mt-1">ETD: <?= formatDate($alert['estimated_time_of_departure']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($alert['estimated_time_of_arrival']): ?>
                                        <p class="text-sm text-yellow-700">ETA: <?= formatDate($alert['estimated_time_of_arrival']) ?></p>
                                    <?php endif; ?>
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
                        <input type="text" id="searchQuery" placeholder="Search by model, chassis number, engine number, vessel, or status..." oninput="filterTable()" class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 w-full rounded-md shadow-sm border-gray-300 focus:ring-blue-500 focus:border-blue-500" />
                    </div>
                    <div class="w-full overflow-auto">
                        <table id="shipmentTable" class="w-full caption-bottom text-sm">
                            <thead class="[&_tr]:border-b">
                                <tr class="bg-gray-50">
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">Model</th>
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">Vessel Name</th>
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">Engine Number</th>
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">Chassis Number</th>
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">Port of Shipment</th>
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">ETD</th>
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">ETA</th>
                                    <th scope="col" class="h-12 px-4 text-left align-middle font-bold text-gray-700">Status</th>
                                    <th scope="col" class="h-12 px-4 text-right align-middle font-bold text-gray-700">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="[&_tr:last-child]:border-0">
                                <?php if (empty($shipments)): ?>
                                    <tr>
                                        <td colspan="9" class="p-4 text-center text-gray-500">No shipments found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($shipments as $shipment): ?>
                                        <tr class="border-b transition-colors hover:bg-gray-100 duration-200">
                                            <td class="p-4 align-middle"><?= htmlspecialchars($shipment['model'] ?? 'N/A') ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($shipment['vessel_name']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($shipment['engine_number'] ?? 'N/A') ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($shipment['chassis_number']) ?></td>
                                            <td class="p-4 align-middle"><?= htmlspecialchars($shipment['port_of_shipment']) ?></td>
                                            <td class="p-4 align-middle"><?= formatDate($shipment['estimated_time_of_departure']) ?></td>
                                            <td class="p-4 align-middle"><?= formatDate($shipment['estimated_time_of_arrival']) ?></td>
                                            <td class="p-4 align-middle">
                                                <span class="px-2 py-1 rounded-full text-xs font-semibold
                                                    <?php
                                                    $status = strtolower($shipment['status'] ?? '');
                                                    if (strpos($status, 'in transit') !== false) echo 'bg-blue-100 text-blue-800';
                                                    elseif (strpos($status, 'pending') !== false) echo 'bg-yellow-100 text-yellow-800';
                                                    elseif (strpos($status, 'delivered') !== false) echo 'bg-green-100 text-green-800';
                                                    else echo 'bg-gray-100 text-gray-800';
                                                    ?>">
                                                    <?= htmlspecialchars($shipment['status'] ?? 'N/A') ?>
                                                </span>
                                            </td>
                                            <td class="p-4 align-middle text-right">
                                                <button type="button" onclick="openEditModal(<?= htmlspecialchars(json_encode($shipment)) ?>)" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-blue-100 hover:text-blue-600 h-9 w-9 p-0 text-blue-500" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" action="shipment_track.php" onsubmit="return confirmDelete(event, '<?= $shipment['shipment_id'] ?>')" style="display:inline-block;">
                                                    <input type="hidden" name="deleteId" value="<?= $shipment['shipment_id'] ?>">
                                                    <button type="submit" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 hover:bg-red-100 hover:text-red-600 h-9 w-9 p-0 text-red-500" title="Delete">
                                                        <i class="fas fa-trash-alt"></i>
                                                    </button>
                                                </form>
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

    <div id="shipmentModal" class="modal fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="modal-content bg-white p-8 rounded-lg shadow-xl w-full max-w-2xl relative">
            <span class="close-button absolute top-4 right-4 text-gray-500 hover:text-gray-800 text-3xl font-bold cursor-pointer">&times;</span>
            <h2 id="modalTitle" class="text-2xl font-bold mb-6 text-gray-800">Edit Shipment</h2>
            <form id="shipmentForm" method="POST" action="shipment_track.php" class="space-y-4">
                <input type="hidden" name="id" id="shipmentId">

                <div>
                    <label for="chassisNumber" class="block text-sm font-medium text-gray-700">Chassis Number</label>
                    <input type="text" id="chassisNumber" name="chassisNumber" required readonly class="mt-1 block w-full rounded-md border-gray-100 shadow-sm p-2 bg-gray-100">
                </div>
                 <div>
                    <label for="Model" class="block text-sm font-medium text-gray-700">Model </label>
                    <input type="text" id="Model" name="Model" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 bg-gray-100" readonly>
                </div>
                <div>
                    <label for="EngineNumber" class="block text-sm font-medium text-gray-700">Engine Number</label>
                    <input type="text" id="EngineNumber" name="EngineNumber" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 bg-gray-100" readonly>
                </div>
                <div>
                    <label for="vesselName" class="block text-sm font-medium text-gray-700">Vessel Name</label>
                    <input type="text" id="vesselName" name="vesselName" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="PortofShipment" class="block text-sm font-medium text-gray-700">Port of Shipment</label>
                    <input type="text" id="PortofShipment" name="PortofShipment" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="etd" class="block text-sm font-medium text-gray-700">Estimated Time of Departure (ETD)</label>
                    <input type="date" id="etd" name="etd" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="eta" class="block text-sm font-medium text-gray-700">Estimated Time of Arrival (ETA)</label>
                    <input type="date" id="eta" name="eta" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-blue-500 focus:ring-blue-500">
                </div>
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                    <select id="status" name="status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-blue-500 focus:ring-blue-500" required>
                        <option value="">Select Status</option>
                        <option value="Pending">Pending</option>
                        <option value="In Transit">In Transit</option>
                        <option value="Delivered">Delivered</option>
                        <option value="Delayed">Delayed</option>
                        <option value="Customs">Customs Clearance</option>
                    </select>
                </div>

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" id="cancelShipment" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <div id="updateStatusModal" class="modal fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="modal-content bg-white p-8 rounded-lg shadow-xl w-full max-w-md relative">
            <span class="close-button absolute top-4 right-4 text-gray-500 hover:text-gray-800 text-3xl font-bold cursor-pointer">&times;</span>
            <h2 class="text-2xl font-bold mb-6 text-gray-800">Update Shipment Status</h2>
            <form id="updateStatusForm" method="POST" action="shipment_track.php" class="space-y-4">
                <input type="hidden" name="update_status_only" value="1">
                <div>
                    <label for="chassisNumber_status" class="block text-sm font-medium text-gray-700">Chassis Number</label>
                    <select id="chassisNumber_status" name="chassisNumber_status" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select Chassis Number</option>
                        <?php foreach ($chassisNumbersList as $chassisNum): ?>
                            <option value="<?= htmlspecialchars($chassisNum) ?>"><?= htmlspecialchars($chassisNum) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="new_status" class="block text-sm font-medium text-gray-700">New Status</label>
                    <select id="new_status" name="new_status" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" required>
                        <option value="">Select Status</option>
                        <option value="Pending">Pending</option>
                        <option value="In Transit">In Transit</option>
                        <option value="Delivered">Delivered</option>
                        <option value="Delayed">Delayed</option>
                        <option value="Customs">Customs Clearance</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" id="cancelUpdateStatus" class="px-4 py-2 bg-gray-300 text-gray-800 rounded-md hover:bg-gray-400">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700">Update Status</button>
                </div>
            </form>
        </div>
    </div>


    <script>
        // Message box auto-hide
        window.onload = function() {
            const messageBox = document.getElementById('message-box');
            if (messageBox) {
                setTimeout(() => {
                    messageBox.style.opacity = '0';
                    messageBox.style.transition = 'opacity 0.5s ease-out';
                    setTimeout(() => messageBox.remove(), 500);
                }, 3000);
            }
        };

        // Existing Edit Shipment Modal Logic
        const shipmentModal = document.getElementById('shipmentModal');
        const closeButtonShipment = document.querySelector('#shipmentModal .close-button');
        const cancelButtonShipment = document.getElementById('cancelShipment');
        const shipmentForm = document.getElementById('shipmentForm');
        const modalTitle = document.getElementById('modalTitle');
        const shipmentIdInput = document.getElementById('shipmentId');
        const chassisNumberInput = document.getElementById('chassisNumber');
        const modelInput = document.getElementById('Model');
        const engineNumberInput = document.getElementById('EngineNumber');
        const vesselNameInput = document.getElementById('vesselName');
        const portofShipmentInput = document.getElementById('PortofShipment');
        const etdInput = document.getElementById('etd');
        const etaInput = document.getElementById('eta');
        const statusInput = document.getElementById('status');

        closeButtonShipment.onclick = function() {
            shipmentModal.style.display = 'none';
        };

        cancelButtonShipment.onclick = function() {
            shipmentModal.style.display = 'none';
        };

        // This prevents the modal from closing if clicking *inside* the content
        shipmentModal.addEventListener('click', function(event) {
            if (event.target === shipmentModal) { // Only close if clicking on the overlay itself
                shipmentModal.style.display = 'none';
            }
        });


        function openEditModal(shipment) {
            modalTitle.textContent = 'Edit Shipment';
            shipmentIdInput.value = shipment.shipment_id;
            chassisNumberInput.value = shipment.chassis_number;
            // Chassis number is read-only when editing
            chassisNumberInput.setAttribute('readonly', true);
            chassisNumberInput.classList.add('bg-gray-100');

            // Model and EngineNumber are for display, not directly editable for shipment
            modelInput.value = shipment.model ?? 'N/A';
            engineNumberInput.value = shipment.engine_number ?? 'N/A';
            modelInput.setAttribute('readonly', true);
            modelInput.classList.add('bg-gray-100');
            engineNumberInput.setAttribute('readonly', true);
            engineNumberInput.classList.add('bg-gray-100');

            vesselNameInput.value = shipment.vessel_name;
            portofShipmentInput.value = shipment.port_of_shipment;
            etdInput.value = shipment.estimated_time_of_departure ? shipment.estimated_time_of_departure.split(' ')[0] : '';
            etaInput.value = shipment.estimated_time_of_arrival ? shipment.estimated_time_of_arrival.split(' ')[0] : '';
            statusInput.value = shipment.status;
            shipmentModal.style.display = 'flex';
        }

        // New Update Status Modal Logic
        const updateStatusModal = document.getElementById('updateStatusModal');
        const openUpdateStatusModalBtn = document.getElementById('openUpdateStatusModal');
        const closeButtonUpdateStatus = document.querySelector('#updateStatusModal .close-button');
        const cancelUpdateStatusBtn = document.getElementById('cancelUpdateStatus');
        const updateStatusForm = document.getElementById('updateStatusForm');

        openUpdateStatusModalBtn.onclick = function() {
            updateStatusForm.reset(); // Clear any previous values
            updateStatusModal.style.display = 'flex';
        };

        closeButtonUpdateStatus.onclick = function() {
            updateStatusModal.style.display = 'none';
        };

        cancelUpdateStatusBtn.onclick = function() {
            updateStatusModal.style.display = 'none';
        };

        updateStatusModal.addEventListener('click', function(event) {
            if (event.target === updateStatusModal) {
                updateStatusModal.style.display = 'none';
            }
        });


        // Custom delete confirmation
        function confirmDelete(event, shipmentId) {
            if (!window.confirm("Are you sure you want to delete this shipment?")) {
                event.preventDefault(); // Stop form submission
                return false;
            }
            return true;
        }

        // Search/Filter functionality (client-side, ideally should be server-side with large datasets)
        function filterTable() {
            const searchQuery = document.getElementById('searchQuery').value.toLowerCase();
            const table = document.getElementById('shipmentTable');
            const rows = table.getElementsByTagName('tr');

            for (let i = 1; i < rows.length; i++) { // Start at 1 to skip the header row
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                // Check Model (index 0), Vessel Name (index 1), Engine Number (index 2), Chassis Number (index 3), Status (index 7)
                const searchableCells = [cells[0], cells[1], cells[2], cells[3], cells[7]];
                for (let j = 0; j < searchableCells.length; j++) {
                    const text = searchableCells[j].textContent.toLowerCase();
                    if (text.includes(searchQuery)) {
                        found = true;
                        break;
                    }
                }
                row.style.display = found ? '' : 'none';
            }
        }
    </script>
</body>
</html>