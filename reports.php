<?php

require_once "config/includes/helpers.php";
require_once "config/database.php";
require_login();

$period = $_GET["period"] ?? "daily";
if (!in_array($period, ["daily", "weekly", "monthly", "custom"], true)) {
    $period = "daily";
}
$today = date("Y-m-d");
$date_from = trim(input_string($_GET, "date_from"));
$date_to = trim(input_string($_GET, "date_to"));
if ($date_from === "" || $date_to === "") {
    $date_to = $today;
    if ($period === "weekly") {
        $date_from = date("Y-m-d", strtotime("-6 days"));
    } elseif ($period === "monthly") {
        $date_from = date("Y-m-01");
    } else {
        $date_from = $today;
    }
}
if (!valid_date($date_from) || !valid_date($date_to) || $date_from > $date_to) {
    $date_from = $today;
    $date_to = $today;
}

$course_id = filter_input(INPUT_GET, "course", FILTER_VALIDATE_INT) ?: 0;
$search = trim(input_string($_GET, "search"));
$role = $_SESSION["role"] ?? "";
$user_id = (int) $_SESSION["user_id"];
$courses_stmt = $conn->prepare(
    "SELECT c.id, c.course_code, c.course_name
     FROM courses c
     LEFT JOIN lecturers l ON l.id = c.lecturer_id
     WHERE (? = 'admin' OR l.user_id = ?)
     ORDER BY c.course_code"
);
$courses_stmt->bind_param("si", $role, $user_id);
$courses_stmt->execute();
$available_courses = $courses_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$courses_stmt->close();

if ($course_id > 0 && !current_user_can_access_course($conn, $course_id)) {
    http_response_code(403);
    exit("You are not authorized to view reports for this course.");
}

$term = "%" . $search . "%";
$stmt = $conn->prepare(
    "SELECT s.id AS student_id, s.registration_number, s.full_name,
            c.id AS course_id, c.course_code, c.course_name,
            COUNT(DISTINCT CASE WHEN a.status = 'Present' THEN a.attendance_date END) AS present_total,
            COUNT(DISTINCT a.attendance_date) AS recorded_total,
            COALESCE(sessions.session_total, 0) AS session_total
     FROM student_courses sc
     INNER JOIN students s ON s.id = sc.student_id
     INNER JOIN courses c ON c.id = sc.course_id
     LEFT JOIN attendance a ON a.student_id = s.id
        AND a.course_id = c.id
        AND a.attendance_date BETWEEN ? AND ?
     LEFT JOIN (
        SELECT sc2.student_id, sc2.course_id,
               COUNT(DISTINCT a2.attendance_date) AS session_total
        FROM student_courses sc2
        LEFT JOIN attendance a2 ON a2.course_id = sc2.course_id
           AND a2.attendance_date BETWEEN ? AND ?
           AND a2.attendance_date >= DATE(sc2.enrolled_at)
        GROUP BY sc2.student_id, sc2.course_id
     ) sessions ON sessions.course_id = c.id AND sessions.student_id = s.id
     WHERE (? = 0 OR c.id = ?)
       AND (? = 'admin' OR c.lecturer_id IN (
            SELECT l2.id FROM lecturers l2 WHERE l2.user_id = ?
       ))
       AND (? = '' OR s.full_name LIKE ? OR s.registration_number LIKE ?)
     GROUP BY s.id, c.id, sessions.session_total
     ORDER BY c.course_code, s.full_name"
);
$stmt->bind_param(
    "ssssiisisss",
    $date_from,
    $date_to,
    $date_from,
    $date_to,
    $course_id,
    $course_id,
    $role,
    $user_id,
    $search,
    $term,
    $term
);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$low_attendance_only = ($_GET["low"] ?? "") === "1";
if ($low_attendance_only) {
    $rows = array_values(array_filter($rows, static function ($row): bool {
        $sessions = (int) $row["session_total"];
        return $sessions > 0 &&
            (int) $row["present_total"] / $sessions * 100 < 75;
    }));
}

if (($_GET["export"] ?? "") === "csv") {
    $safe_csv_value = static function ($value): string {
        $value = (string) $value;
        if (preg_match('/^[\s]*[=+\-@]/', $value)) {
            return "'" . $value;
        }
        return $value;
    };

    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"attendance-report-" . $date_from . "-to-" . $date_to . ".csv\"");
    $output = fopen("php://output", "w");
    if ($output === false) {
        http_response_code(500);
        exit("Unable to create the report download.");
    }
    fputcsv($output, ["Registration number", "Student", "Course code", "Course", "Present", "Absent", "Recorded for student", "Applicable course sessions", "Attendance percentage"]);
    foreach ($rows as $row) {
        $sessions = (int) $row["session_total"];
        $present = (int) $row["present_total"];
        $percentage = $sessions > 0 ? round($present / $sessions * 100, 1) : 0;
        fputcsv($output, array_map($safe_csv_value, [
            $row["registration_number"],
            $row["full_name"],
            $row["course_code"],
            $row["course_name"],
            $present,
            max(0, $sessions - $present),
            (int) $row["recorded_total"],
            $sessions,
            $percentage . "%",
        ]));
    }
    fclose($output);
    exit;
}

$summary = ["present" => 0, "absent" => 0, "records" => 0, "sessions" => 0];
foreach ($rows as $row) {
    $present = (int) $row["present_total"];
    $sessions = (int) $row["session_total"];
    $summary["present"] += $present;
    $summary["absent"] += max(0, $sessions - $present);
    $summary["records"] += (int) $row["recorded_total"];
    $summary["sessions"] += $sessions;
}
$overall_percentage = $summary["sessions"] > 0
    ? round($summary["present"] / $summary["sessions"] * 100, 1)
    : 0;

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>Attendance reports</h1><p>Rates use recorded course sessions on or after each student's enrollment date. A missing mark is treated as not present.</p></div></div>
    <section class="welcome-card management-form"><h2>Filter report</h2>
        <form method="get" class="filter-form report-filters">
            <label for="period">Period</label><select id="period" name="period"><option value="daily"<?= $period === "daily" ? " selected" : "" ?>>Daily</option><option value="weekly"<?= $period === "weekly" ? " selected" : "" ?>>Weekly</option><option value="monthly"<?= $period === "monthly" ? " selected" : "" ?>>Monthly</option><option value="custom"<?= $period === "custom" ? " selected" : "" ?>>Custom range</option></select>
            <label for="date_from">From</label><input id="date_from" name="date_from" type="date" value="<?= e($date_from) ?>" required>
            <label for="date_to">To</label><input id="date_to" name="date_to" type="date" value="<?= e($date_to) ?>" required>
            <label for="course">Course</label><select id="course" name="course"><option value="">All assigned courses</option><?php foreach ($available_courses as $course): ?><option value="<?= (int) $course["id"] ?>"<?= $course_id === (int) $course["id"] ? " selected" : "" ?>><?= e($course["course_code"] . " — " . $course["course_name"]) ?></option><?php endforeach; ?></select>
            <label for="search">Student</label><input id="search" name="search" type="search" placeholder="Name or registration" value="<?= e($search) ?>">
            <label class="checkbox-label"><input type="checkbox" name="low" value="1"<?= $low_attendance_only ? " checked" : "" ?>> Below 75%</label>
            <button class="btn btn-primary" type="submit">Run report</button>
        </form>
        <?php $export_query = $_GET; $export_query["export"] = "csv"; ?>
        <a class="btn btn-secondary" href="reports.php?<?= e(http_build_query($export_query)) ?>">Export CSV</a>
    </section>

    <section class="stats-grid report-stats">
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">✓</div><div><p>Present</p><h2><?= number_format($summary["present"]) ?></h2></div></article>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">×</div><div><p>Absent / unmarked sessions</p><h2><?= number_format($summary["absent"]) ?></h2></div></article>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">#</div><div><p>Student session records</p><h2><?= number_format($summary["records"]) ?></h2></div></article>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">%</div><div><p>Average recorded-session attendance</p><h2><?= number_format($overall_percentage, 1) ?>%</h2></div></article>
    </section>

    <section class="welcome-card records-card"><h2>Student attendance by course</h2><div class="table-container"><table><thead><tr><th>Registration</th><th>Student</th><th>Course</th><th>Present</th><th>Absent</th><th>Recorded</th><th>Course sessions</th><th>Attendance</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="empty-state">No enrolled students or recorded sessions match this report.</td></tr><?php endif; ?>
    <?php foreach ($rows as $row): $sessions = (int) $row["session_total"]; $present = (int) $row["present_total"]; $absent = max(0, $sessions - $present); $percentage = $sessions > 0 ? round($present / $sessions * 100, 1) : 0; ?><tr class="<?= $sessions > 0 && $percentage < 75 ? "low-attendance" : "" ?>"><td><?= e($row["registration_number"]) ?></td><td><?= e($row["full_name"]) ?></td><td><?= e($row["course_code"] . " — " . $row["course_name"]) ?></td><td><?= $present ?></td><td><?= $absent ?></td><td><?= (int) $row["recorded_total"] ?></td><td><?= $sessions ?></td><td><div class="progress-cell"><span><?= number_format($percentage, 1) ?>%</span><progress max="100" value="<?= min(100, $percentage) ?>"><?= $percentage ?>%</progress></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
    </section>
</main>
<?php require "config/includes/footer.php"; ?>
