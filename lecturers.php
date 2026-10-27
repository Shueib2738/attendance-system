<?php

require_once "config/includes/helpers.php";
require_once "config/database.php";
require_admin();

$message = "";
$error = "";
$edit_id = filter_input(INPUT_GET, "edit", FILTER_VALIDATE_INT) ?: 0;
$editing = null;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $action = input_string($_POST, "action");
    $lecturer_id = filter_input(INPUT_POST, "lecturer_id", FILTER_VALIDATE_INT) ?: 0;

    if ($action === "delete" && $lecturer_id > 0) {
        $check = $conn->prepare(
            "SELECT l.user_id,
                    (SELECT COUNT(*) FROM courses WHERE lecturer_id = l.id) AS course_total
             FROM lecturers l WHERE l.id = ?"
        );
        $check->bind_param("i", $lecturer_id);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();
        if (!$existing) {
            $error = "Lecturer was not found.";
        } elseif ((int) $existing["course_total"] > 0) {
            $error = "Reassign this lecturer's courses before deleting the lecturer.";
        } else {
            $conn->begin_transaction();
            try {
                if ($existing["user_id"] !== null) {
                    $disable = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
                    $disable->bind_param("i", $existing["user_id"]);
                    $disable->execute();
                    $disable->close();
                }
                $delete = $conn->prepare("DELETE FROM lecturers WHERE id = ?");
                $delete->bind_param("i", $lecturer_id);
                $delete->execute();
                $delete->close();
                audit_log($conn, "lecturer", $lecturer_id, "delete");
                $conn->commit();
                $message = "Lecturer deleted and any linked login disabled.";
            } catch (Throwable $exception) {
                $conn->rollback();
                error_log("Lecturer delete failed: " . $exception->getMessage());
                $error = "Lecturer could not be deleted.";
            }
        }
    } elseif ($action === "save") {
        $staff_number = strtoupper(trim(input_string($_POST, "staff_number")));
        $full_name = trim(input_string($_POST, "full_name"));
        $email = trim(input_string($_POST, "email"));
        $phone = trim(input_string($_POST, "phone"));
        $status = input_string($_POST, "status", "Active");
        $username = trim(input_string($_POST, "username"));
        $new_password = input_string($_POST, "password");
        $has_linked_account = false;
        if ($lecturer_id > 0) {
            $account_check = $conn->prepare(
                "SELECT user_id FROM lecturers WHERE id = ?"
            );
            $account_check->bind_param("i", $lecturer_id);
            $account_check->execute();
            $linked_row = $account_check->get_result()->fetch_assoc();
            $account_check->close();
            $has_linked_account = $linked_row && $linked_row["user_id"] !== null;
        }

        if (
            $staff_number === "" || strlen($staff_number) > 50 ||
            $full_name === "" || strlen($full_name) > 100 ||
            ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) ||
            strlen($phone) > 30 || !in_array($status, ["Active", "Inactive"], true) ||
            ($username === "" && $new_password !== "") ||
            ($username !== "" && $new_password === "" && !$has_linked_account) ||
            ($username !== "" && strlen($username) > 50) ||
            ($new_password !== "" && strlen($new_password) < 10)
        ) {
            $error = "Check lecturer details. A new login requires a username and password of at least 10 characters.";
        } else {
            $conn->begin_transaction();
            try {
                if ($lecturer_id > 0) {
                    $get = $conn->prepare("SELECT user_id FROM lecturers WHERE id = ? FOR UPDATE");
                    $get->bind_param("i", $lecturer_id);
                    $get->execute();
                    $existing = $get->get_result()->fetch_assoc();
                    $get->close();
                    if (!$existing) {
                        throw new RuntimeException("Lecturer record not found.");
                    }

                    $linked_user_id = (int) ($existing["user_id"] ?? 0);
                    if ($linked_user_id > 0) {
                        $user_update = $conn->prepare("UPDATE users SET full_name = ?, is_active = ? WHERE id = ?");
                        $active = $status === "Active" ? 1 : 0;
                        $user_update->bind_param("sii", $full_name, $active, $linked_user_id);
                        $user_update->execute();
                        $user_update->close();

                        if ($username !== "" && $new_password !== "") {
                            $hash = password_hash($new_password, PASSWORD_DEFAULT);
                            $account_update = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                            $account_update->bind_param("ssi", $username, $hash, $linked_user_id);
                            $account_update->execute();
                            $account_update->close();
                        } elseif ($username !== "") {
                            $account_update = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                            $account_update->bind_param("si", $username, $linked_user_id);
                            $account_update->execute();
                            $account_update->close();
                        }
                    } elseif ($username !== "") {
                        $hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $account_insert = $conn->prepare(
                            "INSERT INTO users (full_name, username, password, role, is_active)
                             VALUES (?, ?, ?, 'lecturer', ?)"
                        );
                        $active = $status === "Active" ? 1 : 0;
                        $account_insert->bind_param("sssi", $full_name, $username, $hash, $active);
                        $account_insert->execute();
                        $linked_user_id = (int) $conn->insert_id;
                        $account_insert->close();
                    }

                    $stmt = $conn->prepare(
                        "UPDATE lecturers SET staff_number = ?, full_name = ?, email = ?,
                            phone = ?, status = ?, user_id = NULLIF(?, 0) WHERE id = ?"
                    );
                    $stmt->bind_param("sssssii", $staff_number, $full_name, $email, $phone, $status, $linked_user_id, $lecturer_id);
                    $stmt->execute();
                    $stmt->close();
                    audit_log($conn, "lecturer", $lecturer_id, "update", null, $staff_number . " — " . $full_name);
                } else {
                    $linked_user_id = 0;
                    if ($username !== "") {
                        $hash = password_hash($new_password, PASSWORD_DEFAULT);
                        $account_insert = $conn->prepare(
                            "INSERT INTO users (full_name, username, password, role)
                             VALUES (?, ?, ?, 'lecturer')"
                        );
                        $account_insert->bind_param("sss", $full_name, $username, $hash);
                        $account_insert->execute();
                        $linked_user_id = (int) $conn->insert_id;
                        $account_insert->close();
                    }
                    $stmt = $conn->prepare(
                        "INSERT INTO lecturers (staff_number, full_name, email, phone, status, user_id)
                         VALUES (?, ?, ?, ?, ?, NULLIF(?, 0))"
                    );
                    $stmt->bind_param("sssssi", $staff_number, $full_name, $email, $phone, $status, $linked_user_id);
                    $stmt->execute();
                    $lecturer_id = (int) $conn->insert_id;
                    $stmt->close();
                    audit_log($conn, "lecturer", $lecturer_id, "create", null, $staff_number . " — " . $full_name);
                }
                $conn->commit();
                $message = "Lecturer details saved.";
                $edit_id = 0;
            } catch (mysqli_sql_exception $exception) {
                $conn->rollback();
                error_log("Lecturer save failed: " . $exception->getMessage());
                $error = $exception->getCode() === 1062
                    ? "The staff number or username is already in use."
                    : "Lecturer could not be saved. Check the details.";
            } catch (Throwable $exception) {
                $conn->rollback();
                $error = $exception->getMessage();
            }
        }
    }
}

if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM lecturers WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($editing && $editing["user_id"]) {
        $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->bind_param("i", $editing["user_id"]);
        $stmt->execute();
        $editing["username"] = $stmt->get_result()->fetch_assoc()["username"] ?? "";
        $stmt->close();
    }
}
$search = trim(input_string($_GET, "search"));
$term = "%" . $search . "%";
$stmt = $conn->prepare(
    "SELECT l.id, l.staff_number, l.full_name, l.email, l.phone, l.status,
            u.username,
            (SELECT COUNT(*) FROM courses c WHERE c.lecturer_id = l.id) AS course_total
     FROM lecturers l LEFT JOIN users u ON u.id = l.user_id
     WHERE ? = '' OR l.staff_number LIKE ? OR l.full_name LIKE ? OR l.email LIKE ?
     ORDER BY l.full_name"
);
$stmt->bind_param("ssss", $search, $term, $term, $term);
$stmt->execute();
$lecturers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>Lecturers</h1><p>Manage staff records and optional lecturer login accounts.</p></div></div>
    <?php if ($message !== ""): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="welcome-card management-form"><h2><?= $editing ? "Edit lecturer" : "Add lecturer" ?></h2>
        <form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="lecturer_id" value="<?= (int) ($editing["id"] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group"><label for="staff_number">Staff number</label><input id="staff_number" name="staff_number" maxlength="50" required value="<?= e($editing["staff_number"] ?? "") ?>"></div>
                <div class="form-group"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="100" required value="<?= e($editing["full_name"] ?? "") ?>"></div>
                <div class="form-group"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="100" value="<?= e($editing["email"] ?? "") ?>"></div>
                <div class="form-group"><label for="phone">Phone</label><input id="phone" name="phone" maxlength="30" value="<?= e($editing["phone"] ?? "") ?>"></div>
                <div class="form-group"><label for="status">Status</label><select id="status" name="status"><option<?= ($editing["status"] ?? "Active") === "Active" ? " selected" : "" ?>>Active</option><option<?= ($editing["status"] ?? "") === "Inactive" ? " selected" : "" ?>>Inactive</option></select></div>
                <div class="form-group"><label for="username">Login username (optional)</label><input id="username" name="username" maxlength="50" value="<?= e($editing["username"] ?? "") ?>"></div>
                <div class="form-group form-span"><label for="password"><?= $editing && !empty($editing["user_id"]) ? "New password (minimum 10 characters; leave blank to keep current)" : "Login password (minimum 10 characters; leave username and password blank for no login)" ?></label><input id="password" name="password" type="password" minlength="10" autocomplete="new-password"></div>
            </div>
            <div class="quick-actions"><button class="btn btn-primary" type="submit"><?= $editing ? "Save changes" : "Add lecturer" ?></button><?php if ($editing): ?><a class="btn btn-secondary" href="lecturers.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>
    <section class="welcome-card records-card"><h2>Lecturer directory</h2>
        <form method="get" class="filter-form"><label class="visually-hidden" for="search">Search lecturers</label><input id="search" name="search" type="search" placeholder="Search name, staff number or email" value="<?= e($search) ?>"><button class="btn btn-primary" type="submit">Search</button></form>
        <div class="table-container"><table><thead><tr><th>Staff number</th><th>Name</th><th>Email</th><th>Login</th><th>Courses</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if (!$lecturers): ?><tr><td colspan="7" class="empty-state">No lecturers match your search.</td></tr><?php endif; ?>
        <?php foreach ($lecturers as $lecturer): ?><tr><td><?= e($lecturer["staff_number"]) ?></td><td><?= e($lecturer["full_name"]) ?></td><td><?= e($lecturer["email"] ?: "—") ?></td><td><?= e($lecturer["username"] ?: "No login") ?></td><td><?= (int) $lecturer["course_total"] ?></td><td><span class="status-badge status-<?= strtolower(e($lecturer["status"])) ?>"><?= e($lecturer["status"]) ?></span></td><td class="action-cell"><a class="btn btn-secondary btn-small" href="lecturers.php?edit=<?= (int) $lecturer["id"] ?>">Edit</a><form method="post" data-confirm="Delete this lecturer? Reassign their courses first."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="lecturer_id" value="<?= (int) $lecturer["id"] ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
<?php require "config/includes/footer.php"; ?>
