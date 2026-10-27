<?php

require_once "config/includes/helpers.php";
require_once "config/database.php";
require_login();

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $current_password = input_string($_POST, "current_password");
    $new_password = input_string($_POST, "new_password");
    $confirm_password = input_string($_POST, "confirm_password");

    if (strlen($new_password) < 12) {
        $error = "Choose a new password with at least 12 characters.";
    } elseif ($new_password !== $confirm_password) {
        $error = "The new password and confirmation do not match.";
    } else {
        $user_id = (int) $_SESSION["user_id"];
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? AND is_active = 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user || !password_verify($current_password, $user["password"])) {
            $error = "The current password is incorrect.";
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->bind_param("si", $hash, $user_id);
            $update->execute();
            $update->close();
            audit_log($conn, "user", $user_id, "password_change");
            session_regenerate_id(true);
            $message = "Password changed successfully.";
        }
    }
}

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>Account security</h1><p>Change your password. Your existing password is required.</p></div></div>
    <?php if ($message !== ""): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="welcome-card management-form"><form method="post" class="data-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="form-grid"><div class="form-group"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="form-group"><label for="new_password">New password (minimum 12 characters)</label><input id="new_password" name="new_password" type="password" minlength="12" autocomplete="new-password" required></div>
        <div class="form-group"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" minlength="12" autocomplete="new-password" required></div></div>
        <button class="btn btn-primary" type="submit">Update password</button>
    </form></section>
</main>
<?php require "config/includes/footer.php"; ?>
