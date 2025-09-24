<?php
// generate_report.php

// This file simulates a comprehensive report generation script
// It assumes a db_connect.php file exists and correctly establishes a PDO connection.

// Placeholder for db_connect.php
// You must ensure this file exists and contains a valid PDO database connection object named $db.
require_once 'db_connect.php';

// Setting up headers for CSV download later on
if (isset($_GET['download']) && $_GET['download'] === 'csv') {
    $filename = 'comprehensive_report_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
}

// --- Currency exchange rate ---
// Initialize with a default rate in case fetching from DB fails
$usd_to_bdt_rate = 121.95;
$under_invoice_rate = 126.00; // Added under-invoice rate
$message = ''; // Initialize message variable

try {
    // Fetch the latest USD to BDT exchange rates from the database
    $stmt_rate = $db->query("SELECT usd_to_bdt_rate, under_invoice_rate FROM currency_exchange_rate ORDER BY rate_date DESC LIMIT 1");
    if ($stmt_rate) {
        $rate_row = $stmt_rate->fetch(PDO::FETCH_ASSOC);
        if ($rate_row && isset($rate_row['usd_to_bdt_rate'])) {
            $usd_to_bdt_rate = floatval($rate_row['usd_to_bdt_rate']);
            $under_invoice_rate = floatval($rate_row['under_invoice_rate']); // Fetch the under-invoice rate
        } else {
            // Log or display a warning if no rate is found in the DB
            $message .= "<div class='mb-4 p-4 rounded-md text-sm bg-yellow-100 text-yellow-700'>Warning: No USD to BDT exchange rate found in the database. Using default rates.</div>";
        }
    } else {
        $message .= "<div class='mb-4 p-4 rounded-md text-sm bg-red-100 text-red-700'>Error fetching exchange rates from database. Using default rates.</div>";
    }
} catch (PDOException $e) {
    $message .= "<div class='mb-4 p-4 rounded-md text-sm bg-red-100 text-red-700'>Database Error fetching exchange rates: " . htmlspecialchars($e->getMessage()) . ". Using default rates.</div>";
}


// --- Initialize variables for totals in BDT ---
$totalSellingPriceBDT = 0;
$totalCostBDT = 0;
$totalProfitBDT = 0;
$reportData = [];

// --- Year-to-Year Report Feature ---
// Get start and end year from GET parameters, or default to the current year
$currentYear = date('Y');
$startYear = isset($_GET['start_year']) ? (int)$_GET['start_year'] : $currentYear;
$endYear = isset($_GET['end_year']) ? (int)$_GET['end_year'] : $currentYear;

// Ensure start year is not greater than end year
if ($startYear > $endYear) {
    $temp = $startYear;
    $startYear = $endYear;
    $endYear = $temp;
}

// Construct the report title
$reportTitle = 'Comprehensive Report';
$whereClause = "WHERE s.payment_status = 'Paid' ";
$params = [];

if ($startYear == $endYear) {
    $reportTitle .= ' for the year ' . $startYear;
    $whereClause .= "AND YEAR(s.sale_date) = :year";
    $params[':year'] = $startYear;
} else {
    $reportTitle .= ' from ' . $startYear . ' to ' . $endYear;
    $whereClause .= "AND YEAR(s.sale_date) BETWEEN :start_year AND :end_year";
    $params[':start_year'] = $startYear;
    $params[':end_year'] = $endYear;
}


try {
    // Set PDO error mode to exception for better debugging.
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL query to join all relevant tables and fetch necessary data
    // The WHERE clause is now dynamic based on the year range selection
    $sql = "
    SELECT
        c.chassis_number,
        c.make,
        c.model,
        c.year_of_manufacture,
        c.color,
        cl.client_name,
        cl.phone_number,
        s.sale_date,
        s.selling_price_bdt,
        s.profit_bdt,
        s.duty_bdt,
        s.bank_charge_bdt,
        s.c_f_charge_bdt,
        s.transportation_bdt,
        s.other_cost_bdt,
        lc.lc_number,
        lc.issue_date,
        lc.lc_value_usd,
        lc.cfr_price_usd,
        lc.over_invoice_usd,
        lc.under_invoice_usd,
        lc.status AS lc_status,
        ap.end_price_jpy,
        ap.service_charge_jpy
    FROM
        car_details c
    JOIN
        auction_and_pricing ap ON c.chassis_number = ap.chassis_number
    JOIN
        sales s ON c.chassis_number = s.car_chassis_no
    JOIN
        client cl ON s.client_id = cl.client_id
    LEFT JOIN
        lc ON c.chassis_number = lc.car_chassis_no
    $whereClause
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Process data for display and calculations
    foreach ($sales as $sale) {
        // Fetch values from the joined tables
        $selling_price_bdt_display = floatval($sale['selling_price_bdt']);
        $profit_bdt_display = floatval($sale['profit_bdt']);

        // =====================================================================
        // ## CORRECTED LOGIC ##
        // Instead of re-calculating the cost from many different fields,
        // we derive it directly from the final values stored in the sales table.
        // This is the most reliable method as it guarantees the report matches
        // the data at the time of sale.
        // Total Cost = Selling Price - Profit
        // =====================================================================
        $total_cost_bdt_calculated = $selling_price_bdt_display - $profit_bdt_display;


        // Add to totals
        $totalSellingPriceBDT += $selling_price_bdt_display;
        $totalCostBDT += $total_cost_bdt_calculated;
        $totalProfitBDT += $profit_bdt_display;

        $reportData[] = [
            'lc_number' => $sale['lc_number'],
            'issue_date' => $sale['issue_date'],
            'lc_status' => $sale['lc_status'],
            'chassis_number' => $sale['chassis_number'],
            'make' => $sale['make'],
            'model' => $sale['model'],
            'year_of_manufacture' => $sale['year_of_manufacture'],
            'color' => $sale['color'],
            'client_name' => $sale['client_name'],
            'phone_number' => $sale['phone_number'],
            'sale_date' => $sale['sale_date'],
            'selling_price_bdt_display' => $selling_price_bdt_display,
            'total_cost_bdt_calculated' => $total_cost_bdt_calculated,
            'profit_bdt_display' => $profit_bdt_display,
        ];
    }

    // If a CSV download is requested, output the CSV data and exit
    if (isset($_GET['download']) && $_GET['download'] === 'csv') {
        fputcsv($output, ['IMSUPEX BANGLADESH LTD.']);
        fputcsv($output, [$reportTitle . ' - Generated on ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []); // Blank line

        // Updated CSV header
        fputcsv($output, [
            'LC Number', 'Issue Date', 'LC Status', 'Chassis Number', 'Car Make', 'Car Model', 'Car Year',
            'Client Name','Phone Number', 'Sale Date', 'Total Cost (BDT)',
            'Selling Price (BDT)', 'Profit (BDT)'
        ]);

        foreach ($reportData as $row) {
            // Updated fputcsv to include total cost and profit from sales table
            fputcsv($output, [
                $row['lc_number'],
                $row['issue_date'],
                $row['lc_status'],
                $row['chassis_number'],
                $row['make'],
                $row['model'],
                $row['year_of_manufacture'],
                $row['client_name'],
                $row['phone_number'],
                $row['sale_date'],
                number_format($row['total_cost_bdt_calculated'], 2),
                number_format($row['selling_price_bdt_display'], 2),
                number_format($row['profit_bdt_display'], 2),
            ]);
        }
        // Add totals to the CSV
        fputcsv($output, []);
        fputcsv($output, ['Totals', '', '', '', '', '', '', '', '', '', number_format($totalCostBDT, 2), number_format($totalSellingPriceBDT, 2), number_format($totalProfitBDT, 2)]);
        fclose($output);
        exit;
    }

} catch (PDOException $e) {
    $message .= "<div class='mb-4 p-4 rounded-md text-sm bg-red-100 text-red-700'>Database Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    $reportData = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/jpeg" href="pic/logo.jpg">
    <title>Comprehensive Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 p-4 min-h-screen flex flex-col">
    <div class="max-w-7xl mx-auto bg-white rounded-xl shadow-lg p-6 sm:p-8 flex-grow">
        <div class="flex flex-col sm:flex-row justify-between items-center mb-6 border-b pb-4">
            <div>
                <h1 class="text-2xl sm:text-3xl font-bold text-gray-900">IMSUPEX BANGLADESH LTD.</h1>
                <h2 class="text-xl sm:text-2xl font-semibold text-gray-800 mt-1"><?php echo htmlspecialchars($reportTitle); ?></h2>
            </div>
            <div class="flex flex-col sm:flex-row items-center mt-4 sm:mt-0 space-y-2 sm:space-y-0 sm:space-x-2">
                <form method="GET" action="generate_report.php" class="flex items-center space-x-2">
                    <label for="start_year" class="text-sm font-medium text-gray-700">From:</label>
                    <select name="start_year" id="start_year" class="form-select rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <?php
                            $currentYear = date('Y');
                            for ($y = $currentYear; $y >= $currentYear - 20; $y--) {
                                $selected = ($y == $startYear) ? 'selected' : '';
                                echo "<option value='{$y}' {$selected}>{$y}</option>";
                            }
                        ?>
                    </select>
                    <label for="end_year" class="text-sm font-medium text-gray-700">To:</label>
                    <select name="end_year" id="end_year" class="form-select rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                        <?php
                            for ($y = $currentYear; $y >= $currentYear - 20; $y--) {
                                $selected = ($y == $endYear) ? 'selected' : '';
                                echo "<option value='{$y}' {$selected}>{$y}</option>";
                            }
                        ?>
                    </select>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white font-semibold rounded-lg shadow-md hover:bg-indigo-700 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        Generate
                    </button>
                </form>
                <a href="?download=csv&start_year=<?php echo htmlspecialchars($startYear); ?>&end_year=<?php echo htmlspecialchars($endYear); ?>" class="mt-2 sm:mt-0 sm:ml-4 px-4 py-2 bg-green-600 text-white font-semibold rounded-lg shadow-md hover:bg-green-700 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Download CSV
                </a>
                <a href="home.php" class="px-4 py-2 bg-gray-600 text-white font-semibold rounded-lg shadow-md hover:bg-gray-700 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Dashboard
                </a>
            </div>
        </div>

        <?php if (!empty($message)): ?>
            <div class="mb-4 p-4 rounded-md text-sm <?php echo strpos($message, 'error') !== false ? 'bg-red-100 text-red-700' : (strpos($message, 'warning') !== false ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700'); ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
            <div class="bg-green-50 rounded-lg p-4 shadow-sm border border-green-200">
                <p class="text-sm font-medium text-gray-500">Total Selling Price</p>
                <p class="mt-1 text-2xl font-semibold text-green-700">
                    <?php echo number_format($totalSellingPriceBDT, 2); ?>
                </p>
            </div>
            <div class="bg-red-50 rounded-lg p-4 shadow-sm border border-red-200">
                <p class="text-sm font-medium text-gray-500">Total Cost</p>
                <p class="mt-1 text-2xl font-semibold text-red-700">
                    <?php echo number_format($totalCostBDT, 2); ?>
                </p>
            </div>
            <div class="bg-blue-50 rounded-lg p-4 shadow-sm border border-blue-200">
                <p class="text-sm font-medium text-gray-500">Total Profit</p>
                <p class="mt-1 text-2xl font-semibold text-blue-700">
                    <?php echo number_format($totalProfitBDT, 2); ?>
                </p>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Car & LC Details</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client & Sale Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Cost (BDT)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Selling Price (BDT)</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Profit (BDT)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($reportData)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500">No data found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reportData as $row): ?>
                            <tr class="hover:bg-gray-50 transition-colors duration-150">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['make']) . ' ' . htmlspecialchars($row['model']) . ' (' . htmlspecialchars($row['year_of_manufacture']) . ')'; ?></div>
                                    <div class="text-xs text-gray-500">LC: <?php echo htmlspecialchars($row['lc_number']); ?> | Chassis: <?php echo htmlspecialchars($row['chassis_number']); ?></div>
                                    <div class="text-xs text-gray-500">Status: <?php echo htmlspecialchars($row['lc_status']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($row['client_name']); ?></div>
                                    <div class="text-xs text-gray-500">Phone Number: <?php echo htmlspecialchars($row['phone_number']); ?></div>
                                    <div class="text-xs text-gray-500">Sale Date: <?php echo htmlspecialchars(date('d/m/Y', strtotime($row['sale_date']))); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($row['total_cost_bdt_calculated'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo number_format($row['selling_price_bdt_display'], 2); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600 font-semibold">
                                    <?php echo number_format($row['profit_bdt_display'], 2); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <footer class="mt-8 p-4 text-center text-gray-600 text-sm">
        The SAD Six | Developers
    </footer>
</body>
</html>