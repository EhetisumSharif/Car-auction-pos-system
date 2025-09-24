<?php
session_start();
require_once 'db_connect.php';

$car = null;
$message = "";

// Check if a chassis number is provided in the URL
if (isset($_GET['id'])) {
    $chassis_number = $_GET['id'];
    try {
        // Fetch the existing car data from the database
        // Modified to fetch all details from all related tables
        $sql = "
            SELECT
                cd.chassis_number, cd.make, cd.grade, cd.mileage_km, cd.engine_cc,
                cd.color, cd.current_location, cd.car_photo, cd.model,
                cd.engine_number, cd.year_of_manufacture, cd.yard_arrival_date,
                ap.lot_number, ap.auction_house_name, ap.auction_datetime, ap.service_charge_jpy, ap.end_price_jpy,
                sd.port_of_shipment, sd.estimated_time_of_departure,
                sd.vessel_name, sd.estimated_time_of_arrival
            FROM car_details cd
            LEFT JOIN auction_and_pricing ap ON cd.chassis_number = ap.chassis_number
            LEFT JOIN shipment_details sd ON cd.chassis_number = sd.chassis_number
            WHERE cd.chassis_number = ?
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([$chassis_number]);
        $car = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$car) {
            die("Error: Car not found or no associated data.");
        }
    } catch (PDOException $e) {
        die("Database Error: " . $e->getMessage());
    }
} else {
    die("Error: Car ID not specified for editing.");
}

// Handle the form submission for updating the car
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_car'])) {
    // Collect and trim all post data
    $chassis_number = trim($_POST['chassis_number']);
    $make = trim($_POST['make']);
    $model = trim($_POST['model']);
    $grade = trim($_POST['grade']);
    $mileage_km = trim($_POST['mileage_km']);
    $engine_cc = trim($_POST['engine_cc']);
    $color = trim($_POST['color']);
    $current_location = trim($_POST['location']);
    $engine_number = trim($_POST['engine_number']);
    $year_of_manufacture = trim($_POST['manufacture_year']);
    $yard_arrival_date = trim($_POST['yard_arrival_date']);

    $lot_number = trim($_POST['lot_number']);
    $auction_house_name = trim($_POST['auction_house_name']);
    // Retrieve and use the date only
    $auction_date = trim($_POST['auction_date']);
    $service_charge_jpy = trim($_POST['service_charge']);
    $end_price_jpy = trim($_POST['end_price']);

    $port_of_shipment = trim($_POST['port_of_shipment']);
    $estimated_time_of_departure = trim($_POST['etd']);
    $vessel_name = trim($_POST['vessel_name']);
    $estimated_time_of_arrival = trim($_POST['eta']);

    // Handle file upload securely
    $car_photo = $_POST['existing_car_photo']; // Keep existing photo by default
    if (isset($_FILES['car_photo']) && $_FILES['car_photo']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_tmp = $_FILES['car_photo']['tmp_name'];
        $file_name = uniqid() . '_' . basename($_FILES['car_photo']['name']);
        $upload_path = $upload_dir . $file_name;

        if (move_uploaded_file($file_tmp, $upload_path)) {
            $car_photo = $upload_path; // Update with new photo path
        } else {
            $_SESSION['error_message'] = "Error uploading new car photo.";
            header("Location: edit_car.php?id=" . urlencode($chassis_number));
            exit();
        }
    }

    try {
        $db->beginTransaction();

        // Update car_details table
        $sql_car_details_update = "UPDATE car_details SET
            make = ?, grade = ?, mileage_km = ?, engine_cc = ?, color = ?,
            current_location = ?, car_photo = ?, model = ?, engine_number = ?,
            year_of_manufacture = ?, yard_arrival_date = ?
            WHERE chassis_number = ?";
        $stmt_car_details_update = $db->prepare($sql_car_details_update);
        $stmt_car_details_update->execute([
            $make, $grade, $mileage_km, $engine_cc, $color,
            $current_location, $car_photo, $model, $engine_number,
            $year_of_manufacture, $yard_arrival_date, $chassis_number
        ]);

        // Update auction_and_pricing table (UPSERT: Update if exists, Insert if not)
        // Check if a record exists for this chassis_number in auction_and_pricing
        $sql_check_auction = "SELECT COUNT(*) FROM auction_and_pricing WHERE chassis_number = ?";
        $stmt_check_auction = $db->prepare($sql_check_auction);
        $stmt_check_auction->execute([$chassis_number]);
        $auction_exists = $stmt_check_auction->fetchColumn();

        if ($auction_exists) {
            $sql_auction_update = "UPDATE auction_and_pricing SET
                lot_number = ?, auction_house_name = ?, auction_datetime = ?,
                service_charge_jpy = ?,
                end_price_jpy = ?
                WHERE chassis_number = ?";
            $stmt_auction_update = $db->prepare($sql_auction_update);
            $stmt_auction_update->execute([
                $lot_number, $auction_house_name, $auction_date,
                $service_charge_jpy,
                $end_price_jpy, $chassis_number
            ]);
        } else {
            // If no record exists, insert a new one
            // NOTE: The previous code had 8 placeholders but only 7 values. This has been corrected.
            $sql_auction_insert = "INSERT INTO auction_and_pricing (
                lot_number, auction_house_name, auction_datetime,
                service_charge_jpy,
                end_price_jpy, chassis_number
            ) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_auction_insert = $db->prepare($sql_auction_insert);
            $stmt_auction_insert->execute([
                $lot_number, $auction_house_name, $auction_date,
                $service_charge_jpy,
                $end_price_jpy, $chassis_number
            ]);
        }


        // Update shipment_details table (UPSERT: Update if exists, Insert if not)
        // Check if a record exists for this chassis_number in shipment_details
        $sql_check_shipment = "SELECT COUNT(*) FROM shipment_details WHERE chassis_number = ?";
        $stmt_check_shipment = $db->prepare($sql_check_shipment);
        $stmt_check_shipment->execute([$chassis_number]);
        $shipment_exists = $stmt_check_shipment->fetchColumn();

        if ($shipment_exists) {
            $sql_shipment_update = "UPDATE shipment_details SET
                port_of_shipment = ?, estimated_time_of_departure = ?,
                vessel_name = ?, estimated_time_of_arrival = ?
                WHERE chassis_number = ?";
            $stmt_shipment_update = $db->prepare($sql_shipment_update);
            $stmt_shipment_update->execute([
                $port_of_shipment, $estimated_time_of_departure,
                $vessel_name, $estimated_time_of_arrival, $chassis_number
            ]);
        } else {
            // If no record exists, insert a new one
            $sql_shipment_insert = "INSERT INTO shipment_details (
                port_of_shipment, estimated_time_of_departure,
                vessel_name, estimated_time_of_arrival, chassis_number
            ) VALUES (?, ?, ?, ?, ?)";
            $stmt_shipment_insert = $db->prepare($sql_shipment_insert);
            $stmt_shipment_insert->execute([
                $port_of_shipment, $estimated_time_of_departure,
                $vessel_name, $estimated_time_of_arrival, $chassis_number
            ]);
        }

        $db->commit();
        $_SESSION['success_message'] = "Car record updated successfully!";
        // Redirect back to car_info.php and potentially highlight the car or show a success message there
        header("Location: car_info.php");
        exit();

    } catch (PDOException $e) {
        $db->rollBack(); // Rollback transaction on error
        $_SESSION['error_message'] = "Database Error: " . htmlspecialchars($e->getMessage());
        header("Location: edit_car.php?id=" . urlencode($chassis_number));
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Car Information</title>
    <link rel="icon" type="image" href="pic/logo.jpg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="dashboard.css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        /* Custom styling for file input to make it look nicer */
        input[type="file"]::file-selector-button {
            background-color: #4f46e5;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s ease-in-out;
        }
        input[type="file"]::file-selector-button:hover {
            background-color: #4338ca;
        }

        .message-box {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.375rem;
            font-weight: 500;
            text-align: center;
        }
        .message-box.success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #34d399;
        }
        .message-box.error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #f87171;
        }
    </style>
</head>
<body>
    <input type="checkbox" id="mobile-sidebar-toggle" class="hidden">

    <div class="dashboard-container">
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <h2>Main Menu</h2>
                <label for="mobile-sidebar-toggle" class="close-btn" aria-label="Close menu"><i class="fas fa-times"></i></label>
            </div>
            <nav>
                <ul>
                    <li><a href="home.php"><i class="fas fa-home icon"></i> Dashboard</a></li>
                    <li><a href="car_info.php" class="active"><i class="fas fa-car icon"></i> Car Information</a></li>
                    <li><a href="shipment_track.php"><i class="fas fa-truck icon"></i> Shipment Tracking</a></li>
                    <li><a href="lc.php"><i class="fas fa-folder-open icon"></i> LC Management</a></li>
                    <li><a href="client.php"><i class="fas fa-user icon"></i> Client Management</a></li>
                    <li><a href="sales.php"><i class="fas fa-chart-bar icon"></i> Sales & Reports</a></li>
                </ul>
            </nav>
        </aside>

        <main class="main-content">
            <label for="mobile-sidebar-toggle" class="sidebar-overlay" aria-label="Close menu"></label>

            <header class="header">
                <div class="flex items-center gap-4">
                    <label for="mobile-sidebar-toggle" class="menu-btn" aria-label="Open menu"><i class="fas fa-bars"></i></label>
                    <div class="logo flex items-center gap-2">
                        <img src="pic/logo.jpg" alt="IMB Logo" class="h-10 w-auto" />
                        <span><span class="logo-highlight">IMB</span> Dashboard</span>
                    </div>
                </div>
                <form action="logout.php" method="post">
                    <button type="submit" class="sign-out-btn flex items-center gap-2">
                        <i class="fas fa-sign-out-alt"></i> Sign Out
                    </button>
                </form>
            </header>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div id="message-box" class="fixed top-4 right-4 bg-green-500 text-white px-4 py-3 rounded-lg shadow-lg z-50 transition-opacity duration-300 opacity-100" role="alert">
                    <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?>
                </div>
            <?php elseif (isset($_SESSION['error_message'])): ?>
                <div id="message-box" class="fixed top-4 right-4 bg-red-500 text-white px-4 py-3 rounded-lg shadow-lg z-50 transition-opacity duration-300 opacity-100" role="alert">
                    <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?>
                </div>
            <?php endif; ?>

            <section class="max-w-4xl mx-auto bg-white p-6 md:p-10 rounded-xl shadow-lg border border-gray-200">
                <h1 class="text-3xl font-bold text-gray-800 mb-6 text-center">Edit Car Information</h1>
                <p class="text-center text-gray-500 mb-8">Update the details for the car with chassis number: **<?= htmlspecialchars($car['chassis_number']) ?>**</p>

                <form id="editCarForm" class="space-y-8" action="edit_car.php?id=<?= htmlspecialchars($car['chassis_number']) ?>" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_car" value="1">
                    <input type="hidden" name="chassis_number" value="<?= htmlspecialchars($car['chassis_number']) ?>">
                    <input type="hidden" name="existing_car_photo" value="<?= htmlspecialchars($car['car_photo']) ?>">

                    <div class="border border-gray-300 rounded-xl p-6 bg-gray-50">
                        <h2 class="text-xl font-semibold text-gray-700 mb-4">Car Details</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="make" class="block text-sm font-medium text-gray-700">Make (Brand)</label>
                                <input type="text" id="make" name="make" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., Toyota" value="<?= htmlspecialchars($car['make'] ?? '') ?>" required>
                            </div>
                            <div>
                                <label for="model" class="block text-sm font-medium text-gray-700">Model</label>
                                <input type="text" id="model" name="model" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., Corolla Fielder" value="<?= htmlspecialchars($car['model'] ?? '') ?>" required>
                            </div>
                            <div>
                                <label for="chassis_number_display" class="block text-sm font-medium text-gray-700">Chassis Number</label>
                                <input type="text" id="chassis_number_display" value="<?= htmlspecialchars($car['chassis_number'] ?? '') ?>" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 bg-gray-100" readonly>
                            </div>
                            <div>
                                <label for="engine_number" class="block text-sm font-medium text-gray-700">Engine Number</label>
                                <input type="text" id="engine_number" name="engine_number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="Engine number" value="<?= htmlspecialchars($car['engine_number'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="grade" class="block text-sm font-medium text-gray-700">Grade</label>
                                <input type="text" id="grade" name="grade" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 4.5" value="<?= htmlspecialchars($car['grade'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="manufacture_year" class="block text-sm font-medium text-gray-700">Year of Manufacture</label>
                                <input type="number" id="manufacture_year" name="manufacture_year" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 2018" value="<?= htmlspecialchars($car['year_of_manufacture'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="mileage_km" class="block text-sm font-medium text-gray-700">Mileage (Km)</label>
                                <input type="number" id="mileage_km" name="mileage_km" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 50000" value="<?= htmlspecialchars($car['mileage_km'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="engine_cc" class="block text-sm font-medium text-gray-700">Engine CC</label>
                                <input type="number" id="engine_cc" name="engine_cc" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 1500" value="<?= htmlspecialchars($car['engine_cc'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="color" class="block text-sm font-medium text-gray-700">Color</label>
                                <input type="text" id="color" name="color" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., White" value="<?= htmlspecialchars($car['color'] ?? '') ?>">
                            </div> 
                            <div>
                                <label for="location" class="block text-sm font-medium text-gray-700">Current Location</label>
                                <select id="location" name="location" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Select...</option>
                                    <option value="Auction Yard" <?= (isset($car['current_location']) && $car['current_location'] == 'Auction Yard') ? 'selected' : '' ?>>Auction Yard</option>
                                    <option value="At Port" <?= (isset($car['current_location']) && $car['current_location'] == 'At Port') ? 'selected' : '' ?>>At Port</option>
                                    <option value="On Vessel" <?= (isset($car['current_location']) && $car['current_location'] == 'On Vessel') ? 'selected' : '' ?>>On Vessel</option>
                                    <option value="Arrived" <?= (isset($car['current_location']) && $car['current_location'] == 'Arrived') ? 'selected' : '' ?>>Arrived</option>
                                    <option value="Delivered" <?= (isset($car['current_location']) && $car['current_location'] == 'Delivered') ? 'selected' : '' ?>>Delivered</option>
                                    <option value="Sold" <?= (isset($car['current_location']) && $car['current_location'] == 'Sold') ? 'selected' : '' ?>>Sold</option>
                                </select>
                            </div>
                            <div>
                                <label for="yard_arrival_date" class="block text-sm font-medium text-gray-700">Yard Arrival Date</label>
                                <input type="date" id="yard_arrival_date" name="yard_arrival_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" value="<?= htmlspecialchars($car['yard_arrival_date'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="car_photo" class="block text-sm font-medium text-gray-700">Car Photo</label>
                                <?php if (!empty($car['car_photo'])): ?>
                                    <img src="<?= htmlspecialchars($car['car_photo']) ?>" alt="Current Car Photo" class="mt-2 w-32 h-24 object-cover rounded-md shadow-sm">
                                    <p class="text-xs text-gray-500 mt-1">Leave empty to keep current photo.</p>
                                <?php endif; ?>
                                <input type="file" id="car_photo" name="car_photo" accept="image/*" class="mt-1 block w-full rounded-md text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                            </div>
                        </div>
                    </div>

                    <div class="border border-gray-300 rounded-xl p-6 bg-gray-50">
                        <h2 class="text-xl font-semibold text-gray-700 mb-4">Auction & Pricing</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="auction_house_name" class="block text-sm font-medium text-gray-700">Auction House Name</label>
                                <input type="text" id="auction_house_name" name="auction_house_name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., USS Nagoya" value="<?= htmlspecialchars($car['auction_house_name'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="lot_number" class="block text-sm font-medium text-gray-700">Lot Number</label>
                                <input type="text" id="lot_number" name="lot_number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 12345" value="<?= htmlspecialchars($car['lot_number'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="auction_date" class="block text-sm font-medium text-gray-700">Auction Date</label>
                                <input type="date" id="auction_date" name="auction_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" value="<?= htmlspecialchars($car['auction_datetime'] ? date('Y-m-d', strtotime($car['auction_datetime'])) : '') ?>">
                            </div>
                            <div>
                                <label for="end_price" class="block text-sm font-medium text-gray-700">Slod Price (JPY)</label>
                                <input type="number" id="end_price" name="end_price" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 850000" value="<?= htmlspecialchars($car['end_price_jpy'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="service_charge" class="block text-sm font-medium text-gray-700">Service Charge (JPY)</label>
                                <input type="number" id="service_charge" name="service_charge" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 10000" value="<?= htmlspecialchars($car['service_charge_jpy'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="border border-gray-300 rounded-xl p-6 bg-gray-50">
                        <h2 class="text-xl font-semibold text-gray-700 mb-4">Shipment Details</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="port_of_shipment" class="block text-sm font-medium text-gray-700">Port of Shipment</label>
                                <input type="text" id="port_of_shipment" name="port_of_shipment" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., Nagoya Port" value="<?= htmlspecialchars($car['port_of_shipment'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="vessel_name" class="block text-sm font-medium text-gray-700">Vessel Name</label>
                                <input type="text" id="vessel_name" name="vessel_name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., MV Bright Star" value="<?= htmlspecialchars($car['vessel_name'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="etd" class="block text-sm font-medium text-gray-700">Estimated Time of Departure (ETD)</label>
                                <input type="date" id="etd" name="etd" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" value="<?= htmlspecialchars($car['estimated_time_of_departure'] ?? '') ?>">
                            </div>
                            <div>
                                <label for="eta" class="block text-sm font-medium text-gray-700">Estimated Time of Arrival (ETA)</label>
                                <input type="date" id="eta" name="eta" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" value="<?= htmlspecialchars($car['estimated_time_of_arrival'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end gap-4">
                        <a href="car_info.php" class="inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-6 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm transition ease-in-out duration-150">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex justify-center rounded-md border border-transparent shadow-sm px-6 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:w-auto sm:text-sm transition ease-in-out duration-150">
                            Update Car
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const messageBox = document.getElementById('message-box');

            // Function to handle showing the message box for a short duration
            function showTemporaryMessageBox() {
                if (messageBox) {
                    messageBox.classList.remove('hidden', 'opacity-0');
                    messageBox.classList.add('opacity-100');

                    setTimeout(() => {
                        messageBox.classList.remove('opacity-100');
                        messageBox.classList.add('opacity-0');
                        setTimeout(() => {
                            messageBox.classList.add('hidden');
                        }, 300); // Wait for the fade out transition
                    }, 3000); // Display for 3 seconds
                }
            }

            // Call this function if there's a message on page load
            if (messageBox && (messageBox.classList.contains('bg-green-500') || messageBox.classList.contains('bg-red-500'))) {
                showTemporaryMessageBox();
            }
        });
    </script>
</body>
</html>