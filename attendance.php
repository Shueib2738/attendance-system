<?php

require_once "config/includes/helpers.php";
require_once "config/database.php";
require_login();

$message = "";
$error = "";
$course_id = filter_input(INPUT_GET, "course", FILTER_VALIDATE_INT) ?: 0;
$attendance_date = trim(input_string($_GET, "date", date("Y-m-d")));

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();
    $course_id = filter_input(INPUT_POST, "course_id", FILTER_VALIDATE_INT) ?: 0;
    $attendance_date = trim(input_string($_POST, "attendance_date"));

    if ($course_id < 1 || !valid_date($attendance_date) || $attendance_date > date("Y-m-d")) {
        $error = "Choose a course and a valid date that is not in the future.";
    } elseif (!current_user_can_access_course($conn, $course_id)) {
        http_response_code(403);
        exit("You are not authorized to record attendance for this course.");
    } else {
        $course_check = $conn->prepare(
            "SELECT id FROM courses WHERE id = ? AND status = 'Active'"
        );
        $course_check->bind_param("i", $course_id);
        $course_check->execute();
        $course_exists = $course_check->get_result()->num_rows === 1;
        $course_check->close();

        if (!$course_exists) {
            $error = "That course is not active.";
        } else {
            $enrolled_stmt = $conn->prepare(
                "SELECT s.id FROM student_courses sc
                 INNER JOIN students s ON s.id = sc.student_id
                 WHERE sc.course_id = ? AND s.status = 'Active'
                 ORDER BY s.id"
            );
            $enrolled_stmt->bind_param("i", $course_id);
            $enrolled_stmt->execute();
            $enrolled_ids = array_map(
                "intval",
                array_column($enrolled_stmt->get_result()->fetch_all(MYSQLI_ASSOC), "id")
            );
            $enrolled_stmt->close();

            $submitted = $_POST["status"] ?? [];
            if (!is_array($submitted)) {
                $submitted = [];
            }
            $submitted_ids = array_map("intval", array_keys($submitted));
            sort($submitted_ids);
            $expected_ids = $enrolled_ids;
            sort($expected_ids);

            if (!$enrolled_ids) {
                $error = "There are no active students enrolled in this course.";
            } elseif ($submitted_ids !== $expected_ids) {
                $error = "The student list changed or is incomplete. Reload the page and try again.";
            } else {
                $correction_reason = trim(input_string($_POST, "correction_reason"));
                if (strlen($correction_reason) > 500) {
                    $error = "The correction reason must be 500 characters or fewer.";
                }
            }

            if ($error === "") {
                $conn->begin_transaction();
                try {
                    $existing_stmt = $conn->prepare(
                        "SELECT id, status FROM attendance
                         WHERE student_id = ? AND course_id = ? AND attendance_date = ?"
                    );
                    $upsert = $conn->prepare(
                        "INSERT INTO attendance
                            (student_id, course_id, attendance_date, status, recorded_by)
                         VALUES (?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            status = VALUES(status), recorded_by = VALUES(recorded_by)"
                    );
                    $actor_id = (int) $_SESSION["user_id"];

                    foreach ($enrolled_ids as $student_id) {
                        $status = $submitted[(string) $student_id] ?? "";
                        if (!in_array($status, ["Present", "Absent"], true)) {
                            throw new RuntimeException("Mark every student as present or absent.");
                        }

                        $existing_stmt->bind_param("iis", $student_id, $course_id, $attendance_date);
                        $existing_stmt->execute();
                        $existing = $existing_stmt->get_result()->fetch_assoc();

                        $upsert->bind_param(
                            "iissi",
                            $student_id,
                            $course_id,
                            $attendance_date,
                            $status,
                            $actor_id
                        );
                        $upsert->execute();

                        if (!$existing) {
                            audit_log($conn, "attendance", $student_id, "create", null, $status . " on " . $attendance_date);
                        } elseif ($existing["status"] !== $status) {
                            if ($correction_reason === "") {
                                throw new RuntimeException(
                                    "Enter a correction reason when changing an existing attendance mark."
                                );
                            }
                            audit_log(
                                $conn,
                                "attendance",
                                (int) $existing["id"],
                                "correct",
                                $existing["status"] . " on " . $attendance_date,
                                $status . " on " . $attendance_date . ": " . $correction_reason
                            );
                        }
                    }

                    $existing_stmt->close();
                    $upsert->close();
                    $conn->commit();
                    $message = "Attendance saved for " . date("d M Y", strtotime($attendance_date)) . ".";
                } catch (Throwable $exception) {
                    $conn->rollback();
                    if ($exception instanceof mysqli_sql_exception) {
                        error_log("Attendance save failed: " . $exception->getMessage());
                        $error = "Attendance could not be saved. Check the course and student records.";
                    } else {
                        $error = $exception->getMessage();
                    }
                }
            }
        }
    }
}

$courses = $conn->prepare(
    "SELECT c.id, c.course_code, c.course_name
     FROM courses c
     LEFT JOIN lecturers l ON l.id = c.lecturer_id
     WHERE c.status = 'Active'
       AND (? = 'admin' OR l.user_id = ?)
     ORDER BY c.course_code"
);
$role = $_SESSION["role"] ?? "";
$user_id = (int) $_SESSION["user_id"];
$courses->bind_param("si", $role, $user_id);
$courses->execute();
$available_courses = $courses->get_result()->fetch_all(MYSQLI_ASSOC);
$courses->close();

$students = [];
if ($course_id > 0 && valid_date($attendance_date) && current_user_can_access_course($conn, $course_id)) {
    $stmt = $conn->prepare(
        "SELECT s.id, s.registration_number, s.full_name, a.status
         FROM student_courses sc
         INNER JOIN students s ON s.id = sc.student_id AND s.status = 'Active'
         LEFT JOIN attendance a ON a.student_id = s.id
             AND a.course_id = sc.course_id AND a.attendance_date = ?
         WHERE sc.course_id = ?
         ORDER BY s.full_name"
    );
    $stmt->bind_param("si", $attendance_date, $course_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>Attendance</h1><p>Record or correct attendance for students enrolled in your course.</p></div></div>
    <?php if ($message !== ""): ?><div class="alert alert-success" role="status"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error !== ""): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>

    <section class="welcome-card management-form"><h2>Select a class and date</h2>
        <form method="get" class="filter-form">
            <label class="visually-hidden" for="course">Course</label>
            <select id="course" name="course" required><option value="">Choose course</option><?php foreach ($available_courses as $course): ?><option value="<?= (int) $course["id"] ?>"<?= $course_id === (int) $course["id"] ? " selected" : "" ?>><?= e($course["course_code"] . " — " . $course["course_name"]) ?></option><?php endforeach; ?></select>
            <label class="visually-hidden" for="date">Attendance date</label>
            <input id="date" name="date" type="date" max="<?= e(date("Y-m-d")) ?>" value="<?= e(valid_date($attendance_date) ? $attendance_date : date("Y-m-d")) ?>" required>
            <button class="btn btn-primary" type="submit">Load students</button>
        </form>
        <?php if (!$available_courses): ?><p class="empty-note">No active course has been assigned to your lecturer account. Ask an administrator to assign a course.</p><?php endif; ?>
    </section>

    <?php if ($course_id > 0 && $available_courses): ?>
    <section class="welcome-card records-card">
        <div class="section-title"><h2>Class register</h2><button class="btn btn-secondary btn-small" type="button" id="mark-all-present">Mark all present</button></div>
        <?php if (!$students): ?><p class="empty-state">No active students are enrolled in this course yet. Use Course enrollments to add students.</p>
        <?php else: ?>
        <form method="post" class="attendance-form" data-loading-label="Saving attendance...">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="course_id" value="<?= $course_id ?>">
            <input type="hidden" name="attendance_date" value="<?= e($attendance_date) ?>">
            <div class="table-container"><table><thead><tr><th>Registration</th><th>Student</th><th>Present</th><th>Absent</th></tr></thead><tbody>
            <?php foreach ($students as $student): ?><tr><td><?= e($student["registration_number"]) ?></td><td><?= e($student["full_name"]) ?></td><td><label class="radio-label"><input type="radio" name="status[<?= (int) $student["id"] ?>]" value="Present" required<?= $student["status"] === "Present" || $student["status"] === null ? " checked" : "" ?>> Present</label></td><td><label class="radio-label"><input type="radio" name="status[<?= (int) $student["id"] ?>]" value="Absent"<?= $student["status"] === "Absent" ? " checked" : "" ?>> Absent</label></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <div class="form-group correction-reason"><label for="correction_reason">Correction reason (required only when changing a saved mark)</label><textarea id="correction_reason" name="correction_reason" rows="2" maxlength="500" placeholder="For example: corrected from the signed class register"></textarea></div>
            <div class="quick-actions"><button class="btn btn-primary" type="submit">Save attendance</button><a class="btn btn-secondary" href="reports.php?course=<?= $course_id ?>&period=daily&date_from=<?= e($attendance_date) ?>&date_to=<?= e($attendance_date) ?>">View this day's report</a></div>
        </form>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</main>
<?php require "config/includes/footer.php"; ?>
