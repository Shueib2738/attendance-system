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
    $course_id = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT) ?: 0;

    if ($action === "delete" && $course_id > 0) {
        $check = $conn->prepare("SELECT COUNT(*) AS total FROM attendance WHERE course_id = ?");
        $check->bind_param("i", $course_id);
        $check->execute();
        $has_history = (int) $check->get_result()->fetch_assoc()["total"] > 0;
        $check->close();
        if ($has_history) {
            $error = "This course has attendance history and cannot be deleted. Set it to inactive instead.";
        } else {
            $stmt = $conn->prepare("DELETE FROM courses WHERE id = ?");
            $stmt->bind_param("i", $course_id);
            if ($stmt->execute() && $stmt->affected_rows === 1) {
                audit_log($conn, "course", $course_id, "delete");
                $message = "Course deleted.";
            } else {
                $error = "Course could not be deleted.";
            }
            $stmt->close();
        }
    } elseif ($action === "save") {
        $code = strtoupper(trim(input_string($_POST, "course_code")));
        $name = trim(input_string($_POST, "course_name"));
        $description = trim(input_string($_POST, "description"));
        $semester = trim(input_string($_POST, "semester"));
        $lecturer_id = filter_input(INPUT_POST, "lecturer_id", FILTER_VALIDATE_INT) ?: 0;
        $status = input_string($_POST, "status", "Active");

        if ($code === "" || strlen($code) > 30 || $name === "" || strlen($name) > 100 || strlen($semester) > 50 || !in_array($status, ["Active", "Inactive"], true)) {
            $error = "Enter a course code, name, and valid course details.";
        } else {
            try {
                if ($course_id > 0) {
                    $stmt = $conn->prepare(
                        "UPDATE courses SET course_code = ?, course_name = ?, description = ?,
                            semester = ?, lecturer_id = NULLIF(?, 0), status = ? WHERE id = ?"
                    );
                    $stmt->bind_param("ssssisi", $code, $name, $description, $semester, $lecturer_id, $status, $course_id);
                    $stmt->execute();
                    $stmt->close();
                    audit_log($conn, "course", $course_id, "update", null, $code . " — " . $name);
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO courses (course_code, course_name, description, semester, lecturer_id, status)
                         VALUES (?, ?, ?, ?, NULLIF(?, 0), ?)"
                    );
                    $stmt->bind_param("ssssis", $code, $name, $description, $semester, $lecturer_id, $status);
                    $stmt->execute();
                    $course_id = (int) $conn->insert_id;
                    $stmt->close();
                    audit_log($conn, "course", $course_id, "create", null, $code . " — " . $name);
                }
                $message = "Course saved.";
                $edit_id = 0;
            } catch (mysqli_sql_exception $exception) {
                error_log("Course save failed: " . $exception->getMessage());
                $error = $exception->getCode() === 1062
                    ? "That course code is already in use."
                    : "Course could not be saved. Check the course and lecturer details.";
            }
        }
    }
}

if ($edit_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM courses WHERE id = ?");
    $stmt->bind_param("i", $edit_id);
    $stmt->execute();
    $editing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
$lecturers = $conn->query("SELECT id, staff_number, full_name FROM lecturers WHERE status = 'Active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$search = trim(input_string($_GET, "search"));
$stmt = $conn->prepare(
    "SELECT c.id, c.course_code, c.course_name, c.description, c.semester, c.status,
            l.full_name AS lecturer_name,
            (SELECT COUNT(*) FROM student_courses sc WHERE sc.course_id = c.id) AS student_total
     FROM courses c LEFT JOIN lecturers l ON l.id = c.lecturer_id
     WHERE ? = '' OR c.course_code LIKE ? OR c.course_name LIKE ?
     ORDER BY c.course_code"
);
$term = "%" . $search . "%";
$stmt->bind_param("sss", $search, $term, $term);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>Courses</h1><p>Manage course details, lecturer assignments, and enrollment totals.</p></div></div>
    <?php if ($message !== ""): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="welcome-card management-form"><h2><?= $editing ? "Edit course" : "Add course" ?></h2>
        <form method="post" class="data-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="course_id" value="<?= (int) ($editing["id"] ?? 0) ?>">
            <div class="form-grid">
                <div class="form-group"><label for="course_code">Course code</label><input id="course_code" name="course_code" maxlength="30" required value="<?= e($editing["course_code"] ?? "") ?>"></div>
                <div class="form-group"><label for="course_name">Course name</label><input id="course_name" name="course_name" maxlength="100" required value="<?= e($editing["course_name"] ?? "") ?>"></div>
                <div class="form-group"><label for="lecturer_id">Assigned lecturer</label><select id="lecturer_id" name="lecturer_id"><option value="0">Not assigned</option><?php foreach ($lecturers as $lecturer): ?><option value="<?= (int) $lecturer["id"] ?>"<?= (int) ($editing["lecturer_id"] ?? 0) === (int) $lecturer["id"] ? " selected" : "" ?>><?= e($lecturer["staff_number"] . " — " . $lecturer["full_name"]) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label for="semester">Semester / academic period</label><input id="semester" name="semester" maxlength="50" value="<?= e($editing["semester"] ?? "") ?>"></div>
                <div class="form-group"><label for="status">Status</label><select id="status" name="status"><option<?= ($editing["status"] ?? "Active") === "Active" ? " selected" : "" ?>>Active</option><option<?= ($editing["status"] ?? "") === "Inactive" ? " selected" : "" ?>>Inactive</option></select></div>
                <div class="form-group form-span"><label for="description">Description</label><textarea id="description" name="description" rows="3"><?= e($editing["description"] ?? "") ?></textarea></div>
            </div>
            <div class="quick-actions"><button class="btn btn-primary" type="submit"><?= $editing ? "Save changes" : "Add course" ?></button><?php if ($editing): ?><a class="btn btn-secondary" href="courses.php">Cancel</a><?php endif; ?></div>
        </form>
    </section>
    <section class="welcome-card records-card"><div class="section-title"><h2>Course catalogue</h2><a class="btn btn-secondary" href="enrollments.php">Manage enrollments</a></div>
        <form method="get" class="filter-form"><label class="visually-hidden" for="search">Search courses</label><input id="search" name="search" type="search" placeholder="Search code or name" value="<?= e($search) ?>"><button class="btn btn-primary" type="submit">Search</button></form>
        <div class="table-container"><table><thead><tr><th>Code</th><th>Course name</th><th>Lecturer</th><th>Period</th><th>Students</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if (!$courses): ?><tr><td colspan="7" class="empty-state">No courses match your search.</td></tr><?php endif; ?>
        <?php foreach ($courses as $course): ?><tr><td><?= e($course["course_code"]) ?></td><td><?= e($course["course_name"]) ?></td><td><?= e($course["lecturer_name"] ?: "Unassigned") ?></td><td><?= e($course["semester"] ?: "—") ?></td><td><?= (int) $course["student_total"] ?></td><td><span class="status-badge status-<?= strtolower(e($course["status"])) ?>"><?= e($course["status"]) ?></span></td><td class="action-cell"><a class="btn btn-secondary btn-small" href="courses.php?edit=<?= (int) $course["id"] ?>">Edit</a><form method="post" data-confirm="Delete this course? Courses with attendance history cannot be deleted."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="course_id" value="<?= (int) $course["id"] ?>"><button class="btn btn-danger btn-small" type="submit">Delete</button></form></td></tr><?php endforeach; ?>
        </tbody></table></div>
    </section>
</main>
<?php require "config/includes/footer.php"; ?>
