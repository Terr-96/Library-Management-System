<?php

if (!headers_sent()) {
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
}

// Show errors as exceptions (set to off or log-only in production)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host     = "localhost";
$user     = "root";
$password = "";
$dbname   = "lms";

try {
    $connection = mysqli_connect($host, $user, $password, $dbname);
    mysqli_set_charset($connection, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("Database connection error. Please try again later.");
}