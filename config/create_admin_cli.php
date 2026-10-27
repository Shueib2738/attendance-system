<?php

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit("Not found.");
}

require_once __DIR__ . "/database.php";

$existing = $conn->query(
    "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'"
)->fetch_assoc();

if ((int) $existing["total"] > 0) {
    fwrite(STDERR, "An administrator account already exists. Use the account-management page instead.\n");
    exit(1);
}

$prompt = static function (string $label): string {
    fwrite(STDOUT, $label);
    return trim((string) fgets(STDIN));
};

$full_name = $prompt("Administrator full name: ");
$username = $prompt("Administrator username: ");
$password = $prompt("Administrator password (minimum 12 characters): ");

if (
    $full_name === "" ||
    strlen($full_name) > 100 ||
    $username === "" ||
    strlen($username) > 50 ||
    strlen($password) < 12
) {
    fwrite(STDERR, "Invalid input. Use a name, username, and password with at least 12 characters.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare(
    "INSERT INTO users (full_name, username, password, role)
     VALUES (?, ?, ?, 'admin')"
);
$stmt->bind_param("sss", $full_name, $username, $hash);

try {
    $stmt->execute();
    fwrite(STDOUT, "Administrator account created. Sign in at http://localhost/attendance_system/login.php\n");
} catch (mysqli_sql_exception $exception) {
    error_log("CLI administrator setup failed: " . $exception->getMessage());
    fwrite(STDERR, "Could not create the account. The username may already be in use.\n");
    exit(1);
} finally {
    $stmt->close();
    $conn->close();
}

