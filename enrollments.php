<?php

require_once "config/includes/helpers.php";
require_once "config/database.php";
require_admin();

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $student_id = filter_input(INPUT_POST, "student_id", FILTER_VALIDATE_INT) ?: 0;
    $course_id = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT) ?: 0;
    $action = input_string($_POST, "action");

    if ($student_id < 1 || $course_id < 1) {
        $error = "Choose a student and a course.";
    } elseif ($action === "enroll") {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO student_courses (student_id, course_id)
             SELECT s.id, c.id FROM students s
             INNER JOIN courses c ON c.id = ? AND c.status = 'Active'
             WHERE s.id = ? AND s.status = 'Active'"
        );
        $stmt->bind_param("ii", $course_id, $student_id);
        $stmt->execute();
        if ($stmt->affected_rows === 1) {
            audit_log($conn, "enrollment", $student_id, "create", null, (string) $course_id);
            $message = "Student enrolled in the course.";
        } else {
            $error = "Student or active course was not found, or the student is already enrolled.";
        }
        $stmt->close();
    } elseif ($action === "remove") {
        $check = $conn->prepare(
            "SELECT COUNT(*) AS total FROM attendance
             WHERE student_id = ? AND course_id = ?"
        );
        $check->bind_param("ii", $student_id, $course_id);
        $check->execute();
        $has_history = (int) $check->get_result()->fetch_assoc()["total"] > 0;
        $check->close();

        if ($has_history) {
            $error = "This enrollment has attendance history and must be retained for accurate reports.";
        } else {
            $stmt = $conn->prepare(
                "DELETE FROM student_courses WHERE student_id = ? AND course_id = ?"
            );
            $stmt->bind_param("ii", $student_id, $course_id);
            $stmt->execute();
            if ($stmt->affected_rows === 1) {
                audit_log($conn, "enrollment", $student_id, "delete", (string) $course_id, null);
                $message = "Enrollment removed.";
            } else {
                $error = "Enrollment was not found.";
            }
            $stmt->close();
        }
    }
}

$students = $conn->query(
    "SELECT id, registration_number, full_name FROM students
     WHERE status = 'Active' ORDER BY full_name"
)->fetch_all(MYSQLI_ASSOC);
$courses = $conn->query(
    "SELECT id, course_code, course_name FROM courses
     WHERE status = 'Active' ORDER BY course_code"
)->fetch_all(MYSQLI_ASSOC);
$enrollments = $conn->query(
    "SELECT sc.student_id, sc.course_id, sc.enrolled_at,
            s.registration_number, s.full_name, c.course_code, c.course_name
     FROM student_courses sc
     INNER JOIN students s ON s.id = sc.student_id
     INNER JOIN courses c ON c.id = sc.course_id
     ORDER BY c.course_code, s.full_name"
)->fetch_all(MYSQLI_ASSOC);

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>Course enrollments</h1><p>Connect students to courses before recording attendance.</p></div></div>
    <?php if ($message !== ""): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
    <section class="welcome-card management-form">
        <h2>Enroll a student</h2>
        <?php if ($students && $courses): ?>
        <form method="post" class="filter-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="enroll">
            <label class="visually-hidden" for="student_id">Student</label><select id="student_id" name="student_id" required><option value="">Choose student</option><?php foreach ($students as $student): ?><option value="<?= (int) $student["id"] ?>"><?= e($student["registration_number"] . " — " . $student["full_name"]) ?></option><?php endforeach; ?></select>
            <label class="visually-hidden" for="course_id">Course</label><select id="course_id" name="course_id" required><option value="">Choose course</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course["id"] ?>"><?= e($course["course_code"] . " — " . $course["course_name"]) ?></option><?php endforeach; ?></select>
            <button class="btn btn-primary" type="submit">Enroll student</button>
        </form>
        <?php else: ?><p class="empty-note">Create an active student and an active course first.</p><?php endif; ?>
    </section>
    <section class="welcome-card records-card"><h2>Current enrollments</h2><div class="table-container"><table><thead><tr><th>Student</th><th>Course</th><th>Enrolled</th><th>Action</th></tr></thead><tbody>
    <?php if (!$enrollments): ?><tr><td colspan="4" class="empty-state">No enrollments yet.</td></tr><?php endif; ?>
    <?php foreach ($enrollments as $enrollment): ?><tr><td><?= e($enrollment["registration_number"] . " — " . $enrollment["full_name"]) ?></td><td><?= e($enrollment["course_code"] . " — " . $enrollment["course_name"]) ?></td><td><?= e(date("d M Y", strtotime($enrollment["enrolled_at"]))) ?></td><td><form method="post" data-confirm="Remove this enrollment? Enrollments with attendance history are retained."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="student_id" value="<?= (int) $enrollment["student_id"] ?>"><input type="hidden" name="course_id" value="<?= (int) $enrollment["course_id"] ?>"><button class="btn btn-danger btn-small" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div></section>
</main>
<?php require "config/includes/footer.php"; ?>
