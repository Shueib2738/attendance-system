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
    $student_id = filter_input(INPUT_POST, "student_id", FILTER_VALIDATE_INT) ?: 0;

    if ($action === "delete" && $student_id > 0) {
        $check = $conn->prepare("SELECT COUNT(*) AS total FROM attendance WHERE student_id = ?");
        $check->bind_param("i", $student_id);
        $check->execute();
        $has_attendance = (int) $check->get_result()->fetch_assoc()["total"] > 0;
        $check->close();

        if ($has_attendance) {
            $error = "This student has attendance history and cannot be deleted. Set the student to inactive instead.";
        } else {
            $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
            $stmt->bind_param("i", $student_id);
            if ($stmt->execute() && $stmt->affected_rows === 1) {
                audit_log($conn, "student", $student_id, "delete");
                $message = "Student deleted.";
            } else {
                $error = "Student was not found or could not be deleted.";
            }
            $stmt->close();
        }
    } elseif ($action === "save") {
        $registration_number = strtoupper(trim(input_string($_POST, "registration_number")));
        $full_name = trim(input_string($_POST, "full_name"));
        $email = trim(input_string($_POST, "email"));
        $phone = trim(input_string($_POST, "phone"));
        $status = input_string($_POST, "status", "Active");
        $submitted_courses = $_POST["course_ids"] ?? [];
        if (!is_array($submitted_courses)) {
            $submitted_courses = [];
        }
        $course_ids = array_values(array_unique(array_filter(
            array_map("intval", $submitted_courses),
            static fn($id) => $id > 0
        )));

        if (
            $registration_number === "" ||
            strlen($registration_number) > 50 ||
            $full_name === "" ||
            strlen($full_name) > 100 ||
            !in_array($status, ["Active", "Inactive"], true) ||
            ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) ||
            strlen($phone) > 30
        ) {
            $error = "Check the student details and enter valid values.";
        } else {
            $conn->begin_transaction();
            try {
                if ($student_id > 0) {
                    $before_stmt = $conn->prepare(
                        "SELECT registration_number, full_name, email, phone, status
                         FROM students WHERE id = ? FOR UPDATE"
                    );
                    $before_stmt->bind_param("i", $student_id);
                    $before_stmt->execute();
                    $before = $before_stmt->get_result()->fetch_assoc();
                    $before_stmt->close();

                    if (!$before) {
                        throw new RuntimeException("Student record not found.");
                    }

                    $stmt = $conn->prepare(
                        "UPDATE students
                         SET registration_number = ?, full_name = ?, email = ?,
                             phone = ?, status = ?
                         WHERE id = ?"
                    );
                    $stmt->bind_param(
                        "sssssi",
                        $registration_number,
                        $full_name,
                        $email,
                        $phone,
                        $status,
                        $student_id
                    );
                    $stmt->execute();
                    $stmt->close();
                    $old_value = json_encode($before);
                    $new_value = json_encode([
                        "registration_number" => $registration_number,
                        "full_name" => $full_name,
                        "email" => $email,
                        "phone" => $phone,
                        "status" => $status,
                    ]);
                    audit_log($conn, "student", $student_id, "update", $old_value, $new_value);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO students
                            (registration_number, full_name, email, phone, course, status)
                         VALUES (?, ?, ?, ?, '', ?)"
                    );
                    $stmt->bind_param(
                        "sssss",
                        $registration_number,
                        $full_name,
                        $email,
                        $phone,
                        $status
                    );
                    $stmt->execute();
                    $student_id = (int) $conn->insert_id;
                    $stmt->close();
                    audit_log($conn, "student", $student_id, "create", null, $registration_number);
                }

                $check_courses = $conn->prepare(
                    "SELECT id FROM courses WHERE id = ? AND status = 'Active'"
                );
                if ($student_id > 0 && $before) {
                    $current_courses_stmt = $conn->prepare(
                        "SELECT course_id FROM student_courses WHERE student_id = ?"
                    );
                    $current_courses_stmt->bind_param("i", $student_id);
                    $current_courses_stmt->execute();
                    $current_course_ids = array_map(
                        "intval",
                        array_column($current_courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC), "course_id")
                    );
                    $current_courses_stmt->close();

                    $delete_enrollment = $conn->prepare(
                        "DELETE FROM student_courses WHERE student_id = ? AND course_id = ?"
                    );
                    $attendance_check = $conn->prepare(
                        "SELECT COUNT(*) AS total FROM attendance
                         WHERE student_id = ? AND course_id = ?"
                    );
                    foreach ($current_course_ids as $current_course_id) {
                        if (!in_array($current_course_id, $course_ids, true)) {
                            $attendance_check->bind_param("ii", $student_id, $current_course_id);
                            $attendance_check->execute();
                            if ((int) $attendance_check->get_result()->fetch_assoc()["total"] > 0) {
                                throw new RuntimeException(
                                    "An enrollment with attendance history cannot be removed."
                                );
                            }
                            $delete_enrollment->bind_param("ii", $student_id, $current_course_id);
                            $delete_enrollment->execute();
                            audit_log($conn, "enrollment", $student_id, "delete", (string) $current_course_id, null);
                        }
                    }
                    $attendance_check->close();
                    $delete_enrollment->close();
                }
                $insert_enrollment = $conn->prepare(
                    "INSERT IGNORE INTO student_courses (student_id, course_id) VALUES (?, ?)"
                );
                foreach ($course_ids as $course_id) {
                    $check_courses->bind_param("i", $course_id);
                    $check_courses->execute();
                    if ($check_courses->get_result()->num_rows !== 1) {
                        throw new RuntimeException("Select only active courses.");
                    }
                    $insert_enrollment->bind_param("ii", $student_id, $course_id);
                    $insert_enrollment->execute();
                }
                $check_courses->close();
                $insert_enrollment->close();
                $conn->commit();
                $message = "Student details saved.";
                $edit_id = 0;
            } catch (Throwable $exception) {
                $conn->rollback();
                if ($exception instanceof mysqli_sql_exception) {
                    error_log("Student save failed: " . $exception->getMessage());
                    $error = $exception->getCode() === 1062
                        ? "That registration number is already in use."
                        : "Student could not be saved. Check the details and selected courses.";
                } else {
                    $error = $exception->getMessage();
                }
            }
        }
    }
}

if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($editing) {
        $stmt = $conn->prepare("SELECT course_id FROM student_courses WHERE student_id = ?");
        $stmt->bind_param("i", $edit_id);
        $stmt->execute();
        $editing["course_ids"] = array_map(
            "intval",
            array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), "course_id")
        );
        $stmt->close();
    }
}

$search = trim(input_string($_GET, "search"));
$course_filter = filter_input(INPUT_GET, "course", FILTER_VALIDATE_INT) ?: 0;
$page = max(1, filter_input(INPUT_GET, "page", FILTER_VALIDATE_INT) ?: 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;
$courses = $conn->query("SELECT id, course_code, course_name FROM courses ORDER BY course_code")->fetch_all(MYSQLI_ASSOC);

$conditions = [];
$params = [];
$types = "";
if ($search !== "") {
    $conditions[] = "(s.full_name LIKE ? OR s.registration_number LIKE ? OR s.email LIKE ?)";
    $term = "%" . $search . "%";
    array_push($params, $term, $term, $term);
    $types .= "sss";
}
if ($course_filter > 0) {
    $conditions[] = "EXISTS (SELECT 1 FROM student_courses f WHERE f.student_id = s.id AND f.course_id = ?)";
    $params[] = $course_filter;
    $types .= "i";
}
$where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM students s $where");
if ($types !== "") {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total = (int) $count_stmt->get_result()->fetch_assoc()["total"];
$count_stmt->close();

if (($_GET["export"] ?? "") === "csv") {
    $export_stmt = $conn->prepare(
        "SELECT s.registration_number, s.full_name, s.email, s.phone, s.status, s.created_at,
                GROUP_CONCAT(c.course_code ORDER BY c.course_code SEPARATOR ', ') AS courses
         FROM students s
         LEFT JOIN student_courses sc ON sc.student_id = s.id
         LEFT JOIN courses c ON c.id = sc.course_id
         $where
         GROUP BY s.id
         ORDER BY s.full_name"
    );
    if ($types !== "") {
        $export_stmt->bind_param($types, ...$params);
    }
    $export_stmt->execute();
    $export_rows = $export_stmt->get_result();
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"students.csv\"");
    $output = fopen("php://output", "w");
    if ($output === false) {
        http_response_code(500);
        exit("Unable to create the student export.");
    }
    fputcsv($output, ["Registration number", "Full name", "Email", "Phone", "Courses", "Status", "Registration date"]);
    while ($student = $export_rows->fetch_assoc()) {
        $values = [
            $student["registration_number"],
            $student["full_name"],
            $student["email"],
            $student["phone"],
            $student["courses"],
            $student["status"],
            date("Y-m-d", strtotime($student["created_at"])),
        ];
        foreach ($values as &$value) {
            $value = (string) $value;
            if (preg_match('/^[\s]*[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values);
    }
    fclose($output);
    $export_stmt->close();
    exit;
}

$list_stmt = $conn->prepare(
    "SELECT s.id, s.registration_number, s.full_name, s.email, s.phone, s.status, s.created_at,
            GROUP_CONCAT(c.course_code ORDER BY c.course_code SEPARATOR ', ') AS courses
     FROM students s
     LEFT JOIN student_courses sc ON sc.student_id = s.id
     LEFT JOIN courses c ON c.id = sc.course_id
     $where
     GROUP BY s.id
     ORDER BY s.id DESC
     LIMIT ? OFFSET ?"
);
$list_params = $params;
$list_types = $types . "ii";
$list_params[] = $per_page;
$list_params[] = $offset;
$list_stmt->bind_param($list_types, ...$list_params);
$list_stmt->execute();
$students = $list_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$list_stmt->close();

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading">
        <div><h1>Students</h1><p>Register students and manage course enrollments.</p></div>
        <span class="date-display"><?= number_format($total) ?> student<?= $total === 1 ? "" : "s" ?></span>
    </div>

    <?php if ($message !== ""): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <section class="welcome-card management-form">
        <h2><?= $editing ? "Edit student" : "Register student" ?></h2>
        <form method="post" class="data-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="student_id" value="<?= (int) ($editing["id"] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group"><label for="registration_number">Registration number</label><input id="registration_number" name="registration_number" maxlength="50" required value="<?= e($editing["registration_number"] ?? "") ?>"></div>
                <div class="form-group"><label for="full_name">Full name</label><input id="full_name" name="full_name" maxlength="100" required value="<?= e($editing["full_name"] ?? "") ?>"></div>
                <div class="form-group"><label for="email">Email (optional)</label><input id="email" name="email" type="email" maxlength="100" value="<?= e($editing["email"] ?? "") ?>"></div>
                <div class="form-group"><label for="phone">Phone (optional)</label><input id="phone" name="phone" maxlength="30" value="<?= e($editing["phone"] ?? "") ?>"></div>
                <div class="form-group"><label for="status">Status</label><select id="status" name="status"><option<?= ($editing["status"] ?? "Active") === "Active" ? " selected" : "" ?>>Active</option><option<?= ($editing["status"] ?? "") === "Inactive" ? " selected" : "" ?>>Inactive</option></select></div>
                <fieldset class="form-group course-checkboxes"><legend>Enroll in active courses</legend>
                    <?php if ($courses): foreach ($courses as $course): ?>
                        <label class="checkbox-label"><input type="checkbox" name="course_ids[]" value="<?= (int) $course["id"] ?>"<?= in_array((int) $course["id"], $editing["course_ids"] ?? [], true) ? " checked" : "" ?>> <?= e($course["course_code"] . " — " . $course["course_name"]) ?></label>
                    <?php endforeach; else: ?><p class="empty-note">Add courses before enrolling students.</p><?php endif; ?>
                </fieldset>
            </div>
            <div class="quick-actions"><button class="btn btn-primary" type="submit"><?= $editing ? "Save changes" : "Register student" ?></button><?php if ($editing): ?><a class="btn btn-secondary" href="students.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>

    <section class="welcome-card records-card">
        <div class="section-title"><h2>Student records</h2><a class="btn btn-secondary" href="enrollments.php">Manage enrollments</a></div>
        <form method="get" class="filter-form">
            <label class="visually-hidden" for="search">Search students</label><input id="search" name="search" type="search" placeholder="Search name, registration or email" value="<?= e($search) ?>">
            <label class="visually-hidden" for="course">Filter by course</label><select id="course" name="course"><option value="">All courses</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course["id"] ?>"<?= $course_filter === (int) $course["id"] ? " selected" : "" ?>><?= e($course["course_code"]) ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary" type="submit">Search</button>
            <button class="btn btn-secondary" type="submit" name="export" value="csv">Export CSV</button>
        </form>
        <div class="table-container"><table><thead><tr><th>Registration</th><th>Name</th><th>Email</th><th>Phone</th><th>Courses</th><th>Registered</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if (!$students): ?><tr><td colspan="8" class="empty-state">No student records match your search.</td></tr><?php endif; ?>
        <?php foreach ($students as $student): ?><tr><td><?= e($student["registration_number"]) ?></td><td><?= e($student["full_name"]) ?></td><td><?= e($student["email"] ?: "—") ?></td><td><?= e($student["phone"] ?: "—") ?></td><td><?= e($student["courses"] ?: "Not enrolled") ?></td><td><?= e(date("d M Y", strtotime($student["created_at"]))) ?></td><td><span class="status-badge status-<?= strtolower(e($student["status"])) ?>"><?= e($student["status"]) ?></span></td><td class="action-cell"><a class="btn btn-secondary btn-small" href="students.php?edit=<?= (int) $student["id"] ?>">Edit</a><form method="post" data-confirm="Delete this student? Students with attendance history cannot be deleted."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="student_id" value="<?= (int) $student["id"] ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($total > $per_page): ?><nav class="pagination" aria-label="Student pages"><?php for ($number = 1; $number <= (int) ceil($total / $per_page); $number++): ?><a class="<?= $number === $page ? "current" : "" ?>" href="?<?= e(http_build_query(["page" => $number, "search" => $search, "course" => $course_filter])) ?>"><?= $number ?></a><?php endfor; ?></nav><?php endif; ?>
    </section>
</main>
<?php require "config/includes/footer.php"; ?>
