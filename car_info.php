<?php
// Start a session to manage user authentication and messages
session_start();

// Include the database connection file.
// This file is assumed to contain a working PDO connection in a variable named $db.
// It's highly recommended to set PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION in db_connect.php
require_once 'db_connect.php';

// Initialize variables before the try/catch block to prevent undefined variable warnings
$search_query = $_GET['search'] ?? ''; // Initialize with empty string if not set
$filtered_cars = []; // Initialize to an empty array
$message = ""; // Initialize message variable

try {
    // Set PDO error mode to exception for better debugging.
    // This is a good practice to ensure all database errors throw an exception.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ----------------------------------------------------
    // Handle form submission for adding a new car
    // ----------------------------------------------------
    if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_car'])) {
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
        // Use the date from the form. The database will store it as a date and time with 00:00:00 as the time.
        $auction_date = trim($_POST['auction_date']);
        $service_charge_jpy = trim($_POST['service_charge']);
        $end_price_jpy = trim($_POST['end_price']);
        $port_of_shipment = trim($_POST['port_of_shipment']);
        $estimated_time_of_departure = trim($_POST['etd']);
        $vessel_name = trim($_POST['vessel_name']);
        $estimated_time_of_arrival = trim($_POST['eta']);

        // Handle file upload securely
        $car_photo = ''; // Default empty
        if (isset($_FILES['car_photo']) && $_FILES['car_photo']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = 'uploads/'; // Directory to store uploaded images
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true); // Create directory if it doesn't exist
            }
            $file_tmp = $_FILES['car_photo']['tmp_name'];
            $file_name = uniqid() . '_' . basename($_FILES['car_photo']['name']); // Generate unique file name
            $upload_path = $upload_dir . $file_name;

            if (move_uploaded_file($file_tmp, $upload_path)) {
                $car_photo = $upload_path; // Store the path in the database
            } else {
                $_SESSION['error_message'] = "Error uploading car photo.";
                header("Location: car_info.php");
                exit();
            }
        }

        // Start a database transaction for data integrity
        $db->beginTransaction();

        // Prepare and execute the insert into car_details table
        $sql_car_details = "INSERT INTO car_details (
            chassis_number, make, model, grade, mileage_km, engine_cc, color,
            current_location, car_photo, engine_number,
            year_of_manufacture, yard_arrival_date
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_car_details = $db->prepare($sql_car_details);
        $stmt_car_details->execute([
            $chassis_number, $make, $model, $grade, $mileage_km, $engine_cc, $color,
            $current_location, $car_photo, $engine_number,
            $year_of_manufacture, $yard_arrival_date
        ]);

        // Prepare and execute the insert into auction_and_pricing table
        $sql_auction = "INSERT INTO auction_and_pricing (
            lot_number, auction_house_name, auction_datetime,
            service_charge_jpy,
            end_price_jpy, chassis_number
        ) VALUES (?, ?, ?, ?, ?, ?)"; // Corrected: One ? for each column
        $stmt_auction = $db->prepare($sql_auction);
        $stmt_auction->execute([
            $lot_number, $auction_house_name, $auction_date,
            $service_charge_jpy,
            $end_price_jpy, $chassis_number
        ]);

        // Prepare and execute the insert into shipment_details table
        $sql_shipment = "INSERT INTO shipment_details (
            port_of_shipment, estimated_time_of_departure,
            vessel_name, estimated_time_of_arrival, chassis_number
        ) VALUES (?, ?, ?, ?, ?)";
        $stmt_shipment = $db->prepare($sql_shipment);
        $stmt_shipment->execute([
            $port_of_shipment, $estimated_time_of_departure,
            $vessel_name, $estimated_time_of_arrival, $chassis_number
        ]);

        // Commit the transaction if all inserts were successful
        $db->commit();
        $_SESSION['success_message'] = "Car added successfully!";
        header("Location: car_info.php");
        exit();

    }

    // ----------------------------------------------------
    // Handle Search and Fetch Car Data
    // ----------------------------------------------------

    $search_term = '%' . $search_query . '%';

    // SQL query to fetch all car data with left joins for optional information
    $sql_fetch = "
        SELECT
            cd.chassis_number, cd.make, cd.grade, cd.mileage_km, cd.engine_cc,
            cd.color, cd.current_location, cd.car_photo, cd.model,
            cd.engine_number, cd.year_of_manufacture, cd.yard_arrival_date,
            ap.lot_number, ap.auction_house_name, ap.auction_datetime,
            ap.service_charge_jpy, ap.end_price_jpy,
            sd.shipment_id, sd.port_of_shipment, sd.estimated_time_of_departure,
            sd.vessel_name, sd.estimated_time_of_arrival,
            CONCAT(cd.make, ' ', cd.model) AS make_model,
            cd.current_location AS location
        FROM car_details cd
        LEFT JOIN auction_and_pricing ap ON cd.chassis_number = ap.chassis_number
        LEFT JOIN shipment_details sd ON cd.chassis_number = sd.chassis_number
        WHERE cd.chassis_number LIKE :search
           OR cd.make LIKE :search
           OR cd.model LIKE :search
           OR cd.grade LIKE :search
           OR cd.current_location LIKE :search
    ";

    $stmt_fetch = $db->prepare($sql_fetch);
    // Bind the same search term to multiple placeholders
    $stmt_fetch->bindParam(':search', $search_term, PDO::PARAM_STR);
    $stmt_fetch->execute();
    $filtered_cars = $stmt_fetch->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Check for a specific duplicate primary key error
    if ($e->getCode() == 23000 && strpos($e->getMessage(), '1062') !== false) {
        $_SESSION['error_message'] = "This chassis number already exists in the system. Please use a unique chassis number.";
    } else {
        // Catch any other PDO exceptions and set a general session error message
        $_SESSION['error_message'] = "Database Error: " . htmlspecialchars($e->getMessage());
    }
    
    $filtered_cars = []; // Ensure the array is empty on error
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Car Information</title>
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

        /* Modal specific styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1000; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 2rem;
            border-radius: 0.75rem;
            width: 90%;
            max-width: 700px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            position: relative;
        }

        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            position: absolute;
            top: 1rem;
            right: 1.5rem;
            cursor: pointer;
        }

        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
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

        <div class="main-content">
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
                        <i class="fas fa-sign-out-alt"></i> Log Out
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

                <div class="flex border-b border-gray-200 mb-6">
                    <button id="viewCarsTab" class="py-2 px-4 font-semibold text-indigo-600 border-b-2 border-indigo-600 focus:outline-none transition-colors duration-200" aria-controls="viewCarsSection" role="tab" aria-selected="true">View Car Information</button>
                    <button id="addCarTab" class="py-2 px-4 font-semibold text-gray-500 hover:text-gray-700 border-b-2 border-transparent focus:outline-none transition-colors duration-200" aria-controls="addCarSection" role="tab" aria-selected="false">Add Car</button>
                </div>

                <div id="viewCarsSection" role="tabpanel" aria-labelledby="viewCarsTab">
                    <h1 class="text-3xl font-bold text-gray-800 mb-6 text-center">Car Inventory</h1>
                    <p class="text-center text-gray-500 mb-8">A list of all cars currently in the system.</p>

                    <div class="mb-6">
                        <form method="GET" action="car_info.php" class="flex items-center space-x-2">
                            <input type="text" name="search" placeholder="Search by chassis, make/model, or location..."
                                   class="flex-grow rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500"
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                            <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                <i class="fas fa-search mr-2"></i> Search
                            </button>
                            <?php if (!empty($search_query)): ?>
                                <a href="car_info.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                    Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="overflow-x-auto rounded-lg shadow border border-gray-200">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Picture</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Chassis Number</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Make & Model</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sold Price (JPY)</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($filtered_cars)): ?>
                                    <?php foreach ($filtered_cars as $car): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <img src="<?php echo htmlspecialchars($car['car_photo'] ?: 'pic/default_car.png'); ?>" alt="Car" class="w-24 h-16 object-cover rounded-md shadow-sm">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($car['chassis_number']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($car['make_model']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars(number_format($car['end_price_jpy'])); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($car['location']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                                <button type="button" class="text-indigo-600 hover:text-indigo-900 mx-1" onclick="showCarDetails(<?php echo htmlspecialchars(json_encode($car)); ?>)" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <a href="edit_car.php?id=<?php echo $car['chassis_number']; ?>" class="text-blue-600 hover:text-blue-900 mx-1" title="Edit Car">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="delete_car.php?id=<?php echo $car['chassis_number']; ?>" class="text-red-600 hover:text-red-900 mx-1" onclick="return confirm('Are you sure you want to delete this car?');" title="Delete Car">
                                                    <i class="fas fa-trash-alt"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">No cars found matching your search.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div id="addCarSection" class="hidden" role="tabpanel" aria-labelledby="addCarTab">
                    <h1 class="text-3xl font-bold text-gray-800 mb-6 text-center">Add New Car Information</h1>
                    <p class="text-center text-gray-500 mb-8">Enter the complete details for a new car purchased at auction.</p>

                    <form id="carInfoForm" class="space-y-8" action="car_info.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="add_car" value="1">

                        <div class="border border-gray-300 rounded-xl p-6 bg-gray-50">
                            <h2 class="text-xl font-semibold text-gray-700 mb-4">Car Details</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="make" class="block text-sm font-medium text-gray-700">Make (Brand)</label>
                                    <input type="text" id="make" name="make" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., Toyota" required>
                                </div>
                                <div>
                                    <label for="model" class="block text-sm font-medium text-gray-700">Model</label>
                                    <input type="text" id="model" name="model" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., Corolla Fielder" required>
                                </div>
                                <div>
                                    <label for="chassis_number" class="block text-sm font-medium text-gray-700">Chassis Number</label>
                                    <input type="text" id="chassis_number" name="chassis_number" required class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="Unique Identifier">
                                </div>
                                <div>
                                    <label for="engine_number" class="block text-sm font-medium text-gray-700">Engine Number</label>
                                    <input type="text" id="engine_number" name="engine_number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="Engine number">
                                </div>
                                <div>
                                    <label for="grade" class="block text-sm font-medium text-gray-700">Grade</label>
                                    <input type="text" id="grade" name="grade" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 4.5">
                                </div>
                                <div>
                                    <label for="manufacture_year" class="block text-sm font-medium text-gray-700">Year of Manufacture</label>
                                    <input type="number" id="manufacture_year" name="manufacture_year" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 2018">
                                </div>
                                <div>
                                    <label for="mileage_km" class="block text-sm font-medium text-gray-700">Mileage (Km)</label>
                                    <input type="number" id="mileage_km" name="mileage_km" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 50000">
                                </div>
                                <div>
                                    <label for="engine_cc" class="block text-sm font-medium text-gray-700">Engine CC</label>
                                    <input type="number" id="engine_cc" name="engine_cc" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 1500">
                                </div>
                                <div>
                                    <label for="color" class="block text-sm font-medium text-gray-700">Color</label>
                                    <input type="text" id="color" name="color" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., White">
                                </div>
                                <div>
                                    <label for="location" class="block text-sm font-medium text-gray-700">Current Location</label>
                                    <select id="location" name="location" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500">
                                        <option value="">Select...</option>
                                        <option value="Auction Yard">Auction Yard</option>
                                        <option value="At Port">At Port</option>
                                        <option value="On Vessel">On Vessel</option>
                                        <option value="Arrived">Arrived</option>
                                        <option value="Delivered">Delivered</option>
                                        <option value="Sold">Sold</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="yard_arrival_date" class="block text-sm font-medium text-gray-700">Yard Arrival Date</label>
                                    <input type="date" id="yard_arrival_date" name="yard_arrival_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label for="car_photo" class="block text-sm font-medium text-gray-700">Car Photo</label>
                                    <input type="file" id="car_photo" name="car_photo" accept="image/*" class="mt-1 block w-full rounded-md text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100">
                                </div>
                            </div>
                        </div>

                        <div class="border border-gray-300 rounded-xl p-6 bg-gray-50">
                            <h2 class="text-xl font-semibold text-gray-700 mb-4">Auction & Pricing</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="auction_house_name" class="block text-sm font-medium text-gray-700">Auction House Name</label>
                                    <input type="text" id="auction_house_name" name="auction_house_name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., USS Nagoya">
                                </div>
                                <div>
                                    <label for="lot_number" class="block text-sm font-medium text-gray-700">Lot Number</label>
                                    <input type="text" id="lot_number" name="lot_number" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 12345">
                                </div>
                                <div>
                                    <label for="auction_date" class="block text-sm font-medium text-gray-700">Auction Date</label>
                                    <input type="date" id="auction_date" name="auction_date" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label for="end_price" class="block text-sm font-medium text-gray-700">Slod Price (JPY)</label>
                                    <input type="number" id="end_price" name="end_price" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 850000">
                                </div>
                                <div>
                                    <label for="service_charge" class="block text-sm font-medium text-gray-700">Service Charge (JPY)</label>
                                    <input type="number" id="service_charge" name="service_charge" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., 10000">
                                </div>
                            </div>
                        </div>

                        <div class="border border-gray-300 rounded-xl p-6 bg-gray-50">
                            <h2 class="text-xl font-semibold text-gray-700 mb-4">Shipment Details</h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="port_of_shipment" class="block text-sm font-medium text-gray-700">Port of Shipment</label>
                                    <input type="text" id="port_of_shipment" name="port_of_shipment" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., Nagoya Port">
                                </div>
                                <div>
                                    <label for="vessel_name" class="block text-sm font-medium text-gray-700">Vessel Name</label>
                                    <input type="text" id="vessel_name" name="vessel_name" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g., MV Bright Star">
                                </div>
                                <div>
                                    <label for="etd" class="block text-sm font-medium text-gray-700">Estimated Time of Departure (ETD)</label>
                                    <input type="date" id="etd" name="etd" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label for="eta" class="block text-sm font-medium text-gray-700">Estimated Time of Arrival (ETA)</label>
                                    <input type="date" id="eta" name="eta" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm p-2 focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-4">
                            <button type="submit" class="inline-flex justify-center rounded-md border border-transparent shadow-sm px-6 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:w-auto sm:text-sm transition ease-in-out duration-150">
                                Add Car
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
		   <div class="right-sidebar">
        
    </div>
    </div>
      <footer class="bg-gray-800 text-gray-400 text-center p-4">
                <p>The SAD Six | Developers</p>
            </footer>
    <div id="carDetailsModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2 class="text-2xl font-bold text-gray-800 mb-6 text-center">Car Details</h2>
            <div id="modalContentBody" class="grid grid-cols-1 md:grid-cols-2 gap-4 text-gray-700">
            </div>
        </div>
    </div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const viewCarsTab = document.getElementById('viewCarsTab');
        const addCarTab = document.getElementById('addCarTab');
        const viewCarsSection = document.getElementById('viewCarsSection');
        const addCarSection = document.getElementById('addCarSection');
        const messageBox = document.getElementById('message-box');

        const carDetailsModal = document.getElementById('carDetailsModal');
        const closeButton = document.querySelector('.close-button');
        const modalContentBody = document.getElementById('modalContentBody');

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

        /**
         * Switches the active tab and displays the corresponding content section.
         * @param {HTMLElement} activeTab - The tab element to activate.
         * @param {HTMLElement} hiddenTab - The tab element to deactivate.
         * @param {HTMLElement} activeSection - The content section to display.
         * @param {HTMLElement} hiddenSection - The content section to hide.
         */
        function switchTab(activeTab, hiddenTab, activeSection, hiddenSection) {
            activeTab.classList.add('text-indigo-600', 'border-indigo-600');
            activeTab.classList.remove('text-gray-500', 'border-transparent');
            activeTab.setAttribute('aria-selected', 'true');

            hiddenTab.classList.remove('text-indigo-600', 'border-indigo-600');
            hiddenTab.classList.add('text-gray-500', 'border-transparent');
            hiddenTab.setAttribute('aria-selected', 'false');

            activeSection.classList.remove('hidden');
            hiddenSection.classList.add('hidden');
        }

        const urlParams = new URLSearchParams(window.location.search);
        const showAddCarTab = urlParams.get('show_add_car') === 'true';

        if (showAddCarTab) {
            switchTab(addCarTab, viewCarsTab, addCarSection, viewCarsSection);
        } else {
            switchTab(viewCarsTab, addCarTab, viewCarsSection, addCarSection);
        }

        viewCarsTab.addEventListener('click', () => {
            switchTab(viewCarsTab, addCarTab, viewCarsSection, addCarSection);
        });

        addCarTab.addEventListener('click', () => {
            switchTab(addCarTab, viewCarsTab, addCarSection, viewCarsSection);
        });
        
        // --- Helper Function for Date Formatting ---
        function formatDate(dateString) {
            if (!dateString) return 'N/A';
            const date = new Date(dateString);
            const day = String(date.getDate()).padStart(2, '0');
            const month = String(date.getMonth() + 1).padStart(2, '0'); // Months are 0-indexed
            const year = date.getFullYear();
            return `${day}/${month}/${year}`;
        }

        // --- Modal Logic for "View Details" ---
        window.showCarDetails = function(car) {
            modalContentBody.innerHTML = ''; // Clear previous content

            // Create a flexible structure to display all details
            const detailsHtml = `
                <div class="col-span-full mb-4 flex justify-center">
                    <img src="${car.car_photo || 'pic/default_car.png'}" alt="Car Image" class="w-full max-w-xs rounded-md shadow-md object-cover">
                </div>
                <div><strong>Chassis Number:</strong> ${car.chassis_number || 'N/A'}</div>
                <div><strong>Make:</strong> ${car.make || 'N/A'}</div>
                <div><strong>Model:</strong> ${car.model || 'N/A'}</div>
                <div><strong>Engine Number:</strong> ${car.engine_number || 'N/A'}</div>
                <div><strong>Grade:</strong> ${car.grade || 'N/A'}</div>
                <div><strong>Manufacture Year:</strong> ${car.year_of_manufacture || 'N/A'}</div>
                <div><strong>Mileage (Km):</strong> ${car.mileage_km || 'N/A'}</div>
                <div><strong>Engine CC:</strong> ${car.engine_cc || 'N/A'}</div>
                <div><strong>Color:</strong> ${car.color || 'N/A'}</div>
                <div><strong>Current Location:</strong> ${car.location || 'N/A'}</div>
                <div><strong>Yard Arrival Date:</strong> ${formatDate(car.yard_arrival_date)}</div>

                <div class="col-span-full border-t border-gray-200 mt-4 pt-4">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Auction & Pricing</h3>
                </div>
                <div><strong>Auction House:</strong> ${car.auction_house_name || 'N/A'}</div>
                <div><strong>Lot Number:</strong> ${car.lot_number || 'N/A'}</div>
                <div><strong>Auction Date:</strong> ${formatDate(car.auction_datetime)}</div>
                <div><strong>Sold Price (JPY):</strong> ${car.end_price_jpy ? parseInt(car.end_price_jpy).toLocaleString('en-US') : 'N/A'}</div>
                <div><strong>Service Charge (JPY):</strong> ${car.service_charge_jpy ? parseInt(car.service_charge_jpy).toLocaleString('en-US') : 'N/A'}</div>

                <div class="col-span-full border-t border-gray-200 mt-4 pt-4">
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Shipment Details</h3>
                </div>
                <div><strong>Port of Shipment:</strong> ${car.port_of_shipment || 'N/A'}</div>
                <div><strong>Vessel Name:</strong> ${car.vessel_name || 'N/A'}</div>
                <div><strong>ETD:</strong> ${formatDate(car.estimated_time_of_departure)}</div>
                <div><strong>ETA:</strong> ${formatDate(car.estimated_time_of_arrival)}</div>
            `;
            modalContentBody.innerHTML = detailsHtml;
            carDetailsModal.style.display = 'flex'; // Show the modal
        };

        // When the user clicks on <span> (x), close the modal
        closeButton.onclick = function() {
            carDetailsModal.style.display = 'none';
        }

        // When the user clicks anywhere outside of the modal, close it
        window.onclick = function(event) {
            if (event.target == carDetailsModal) {
                carDetailsModal.style.display = 'none';
            }
        }
    });
</script>
</body>
</html>