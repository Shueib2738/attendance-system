<?php

$host = "localhost";
$username = "root";
$password = "";
$database = "attendance_system";

ini_set("display_errors", "0");
ini_set("log_errors", "1");
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(
        $host,
        $username,
        $password,
        $database
    );
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $exception) {
    error_log("Database connection failed: " . $exception->getMessage());
    exit("Database connection failed. Check your database settings.");
}

?>