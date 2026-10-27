<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.cookie_httponly", "1");
    ini_set("session.cookie_samesite", "Lax");

    $cookie_params = session_get_cookie_params();
    session_set_cookie_params([
        "lifetime" => 0,
        "path" => $cookie_params["path"],
        "domain" => $cookie_params["domain"],
        "secure" => isset($_SERVER["HTTPS"]) &&
            $_SERVER["HTTPS"] !== "off",
        "httponly" => true,
        "samesite" => "Lax",
    ]);

    session_start();
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function input_string(array $source, string $key, string $default = ""): string
{
    $value = $source[$key] ?? $default;

    return is_string($value) ? $value : $default;
}

function require_login(): void
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: login.php");
        exit;
    }

    $conn = $GLOBALS["conn"] ?? null;
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare(
            "SELECT full_name, username, role
             FROM users WHERE id = ? AND is_active = 1"
        );
        $user_id = (int) $_SESSION["user_id"];
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) {
            $_SESSION = [];
            session_destroy();
            header("Location: login.php");
            exit;
        }

        $_SESSION["full_name"] = $user["full_name"];
        $_SESSION["username"] = $user["username"];
        $_SESSION["role"] = $user["role"];
    }
}

function require_admin(): void
{
    require_login();

    if (($_SESSION["role"] ?? "") !== "admin") {
        http_response_code(403);
        exit("You do not have permission to access this page.");
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION["csrf_token"])) {
        $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
    }

    return $_SESSION["csrf_token"];
}

function verify_csrf_token(): void
{
    $submitted_token = $_POST["csrf_token"] ?? "";
    $session_token = $_SESSION["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !is_string($session_token) ||
        $session_token === "" ||
        !hash_equals($session_token, $submitted_token)
    ) {
        http_response_code(400);
        exit("Your session token has expired. Reload the page and try again.");
    }
}

function set_flash(string $type, string $message): void
{
    $_SESSION["flash"] = [
        "type" => $type,
        "message" => $message,
    ];
}

function take_flash(): ?array
{
    if (!isset($_SESSION["flash"]) || !is_array($_SESSION["flash"])) {
        return null;
    }

    $flash = $_SESSION["flash"];
    unset($_SESSION["flash"]);

    return $flash;
}

function audit_log(
    mysqli $conn,
    string $entity,
    int $record_id,
    string $action,
    ?string $old_value = null,
    ?string $new_value = null
): void {
    $user_id = (int) ($_SESSION["user_id"] ?? 0);
    $stmt = $conn->prepare(
        "INSERT INTO audit_logs
            (user_id, entity_type, entity_id, action, old_value, new_value)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param(
        "isisss",
        $user_id,
        $entity,
        $record_id,
        $action,
        $old_value,
        $new_value
    );
    $stmt->execute();
    $stmt->close();
}

function current_user_can_access_course(mysqli $conn, int $course_id): bool
{
    if (($_SESSION["role"] ?? "") === "admin") {
        return true;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM courses c
         INNER JOIN lecturers l ON l.id = c.lecturer_id
         WHERE c.id = ? AND l.user_id = ? AND l.status = 'Active'"
    );
    $user_id = (int) $_SESSION["user_id"];
    $stmt->bind_param("ii", $course_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($result["total"] ?? 0) > 0;
}

function show_flash_message(): void
{
    $flash = take_flash();

    if ($flash === null) {
        return;
    }

    $type = in_array($flash["type"] ?? "", ["success", "error", "warning"], true)
        ? $flash["type"]
        : "success";

    echo '<div class="alert alert-' . e($type) . '" role="status">'
        . e($flash["message"] ?? "")
        . "</div>";
}

function valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat("!Y-m-d", $date);

    return $parsed !== false && $parsed->format("Y-m-d") === $date;
}
