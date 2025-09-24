<?php
session_start();
require_once 'db_connect.php';

// Check if a chassis number is provided in the URL
if (!isset($_GET['id'])) {
    die("Error: Car ID not specified for deletion.");
}

$chassis_number = $_GET['id'];

try {
    // Prepare a DELETE statement to prevent SQL injection
    $sql = "DELETE FROM car_details WHERE chassis_number = ?";
    $stmt = $db->prepare($sql);
    
    // Execute the statement
    if ($stmt->execute([$chassis_number])) {
        // Redirect back to the car_info.php page with a success message
        header("Location: car_info.php?message=deleted");
        exit();
    } else {
        // Redirect with an error message if the deletion fails
        header("Location: car_info.php?error=deletion_failed");
        exit();
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>