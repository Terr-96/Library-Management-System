<?php
// Enable MySQLi error reporting for development (disable in production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = "localhost";
$user = "root";
$password = "";
$dbname = "lms";

$connection = mysqli_connect($host, $user, $password, $dbname);

if (!$connection) {
    // Log error instead of displaying in production
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Database connection error. Please try again later.");
}

// Set charset to utf8mb4 for full Unicode support
mysqli_set_charset($connection, 'utf8mb4');
?>






