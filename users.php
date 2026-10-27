<?php

require_once "config/includes/helpers.php";
require_once "config/database.php";
require_admin();

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = input_string($_POST, "action");

    if ($action === "create") {
        $full_name = trim(input_string($_POST, "full_name"));
        $username = trim(input_string($_POST, "username"));
        $password = input_string($_POST, "password");
        if ($full_name === "" || strlen($full_name) > 100 || $username === "" || strlen($username) > 50 || strlen($password) < 12) {
            $error = "Enter a name, username, and administrator password of at least 12 characters.";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO users (full_name, username, password, role)
                     VALUES (?, ?, ?, 'admin')"
                );
                $stmt->bind_param("sss", $full_name, $username, $hash);
                $stmt->execute();
                $user_id = (int) $conn->insert_id;
                $stmt->close();
                audit_log($conn, "user", $user_id, "create", null, $username . " (admin)");
                $message = "Administrator account created.";
            } catch (mysqli_sql_exception $exception) {
                error_log("Administrator account creation failed: " . $exception->getMessage());
                $error = $exception->getCode() === 1062
                    ? "That username is already in use."
                    : "The administrator account could not be created.";
            }
        }
    } elseif ($action === "toggle") {
        $target_id = filter_input(INPUT_POST, "user_id", FILTER_VALIDATE_INT) ?: 0;
        $stmt = $conn->prepare("SELECT id, role, is_active FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_id);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            $error = "User account was not found.";
        } elseif ($target_id === (int) $_SESSION["user_id"] && (int) $target["is_active"] === 1) {
            $error = "You cannot deactivate your own account.";
        } else {
            $next_active = (int) $target["is_active"] === 1 ? 0 : 1;
            if ($next_active === 0 && $target["role"] === "admin") {
                $admin_count = (int) $conn->query(
                    "SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND is_active = 1"
                )->fetch_assoc()["total"];
                if ($admin_count <= 1) {
                    $error = "The last active administrator cannot be deactivated.";
                }
            }

            if ($error === "") {
                $update = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
                $update->bind_param("ii", $next_active, $target_id);
                $update->execute();
                $update->close();
                audit_log($conn, "user", $target_id, $next_active ? "activate" : "deactivate");
                $message = $next_active ? "User account activated." : "User account deactivated.";
            }
        }
    }
}

$users = $conn->query(
    "SELECT id, full_name, username, role, is_active, created_at
     FROM users ORDER BY role, full_name"
)->fetch_all(MYSQLI_ASSOC);

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>User accounts</h1><p>Create administrator accounts and manage access. Lecturer accounts are managed with lecturer records.</p></div></div>
    <?php if ($message !== ""): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="welcome-card management-form"><h2>Create administrator</h2>
        <form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><div class="form-grid">
            <div class="form-group"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="100" required></div>
            <div class="form-group"><label for="username">Username</label><input id="username" name="username" maxlength="50" autocomplete="username" required></div>
            <div class="form-group"><label for="password">Password (minimum 12 characters)</label><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required></div>
        </div><button class="btn btn-primary" type="submit">Create administrator</button></form>
    </section>
    <section class="welcome-card records-card"><h2>Accounts</h2><div class="table-container"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Status</th><th>Created</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($users as $user): ?><tr><td><?= e($user["full_name"]) ?></td><td><?= e($user["username"]) ?></td><td><?= e(ucfirst($user["role"])) ?></td><td><span class="status-badge status-<?= (int) $user["is_active"] ? "active" : "inactive" ?>"><?= (int) $user["is_active"] ? "Active" : "Inactive" ?></span></td><td><?= e(date("d M Y", strtotime($user["created_at"]))) ?></td><td><?php if ((int) $user["id"] !== (int) $_SESSION["user_id"]): ?><form method="post" data-confirm="<?= (int) $user["is_active"] ? "Deactivate this account?" : "Reactivate this account?" ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="user_id" value="<?= (int) $user["id"] ?>"><button class="btn <?= (int) $user["is_active"] ? "btn-danger" : "btn-secondary" ?> btn-small" type="submit"><?= (int) $user["is_active"] ? "Deactivate" : "Activate" ?></button></form><?php else: ?>Current account<?php endif; ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
</main>
<?php require "config/includes/footer.php"; ?>
