<?php

require_once "config/includes/helpers.php";
require_once "config/database.php";
require_login();

$role = $_SESSION["role"] ?? "";
$user_id = (int) $_SESSION["user_id"];

if ($role === "admin") {
    $totals = $conn->query(
        "SELECT
            (SELECT COUNT(*) FROM students) AS students,
            (SELECT COUNT(*) FROM lecturers) AS lecturers,
            (SELECT COUNT(*) FROM courses) AS courses,
            (SELECT COUNT(*) FROM attendance) AS attendance"
    )->fetch_assoc();

    $today_stmt = $conn->prepare(
        "SELECT
            SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END) AS present_total,
            SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END) AS absent_total
         FROM attendance a WHERE a.attendance_date = CURDATE()"
    );
    $today_stmt->execute();
    $today = $today_stmt->get_result()->fetch_assoc();
    $today_stmt->close();

    $recent = $conn->query(
        "SELECT a.attendance_date, a.status, s.registration_number,
                s.full_name AS student_name, c.course_code, u.full_name AS recorder
         FROM attendance a
         INNER JOIN students s ON s.id = a.student_id
         INNER JOIN courses c ON c.id = a.course_id
         LEFT JOIN users u ON u.id = a.recorded_by
         ORDER BY a.created_at DESC LIMIT 8"
    )->fetch_all(MYSQLI_ASSOC);
} else {
    $scope_stmt = $conn->prepare(
        "SELECT
            COUNT(DISTINCT sc.student_id) AS students,
            COUNT(DISTINCT c.id) AS courses,
            COUNT(DISTINCT a.id) AS attendance,
            COUNT(DISTINCT CASE WHEN a.attendance_date = CURDATE() AND a.status = 'Present' THEN a.id END) AS present_total,
            COUNT(DISTINCT CASE WHEN a.attendance_date = CURDATE() AND a.status = 'Absent' THEN a.id END) AS absent_total
         FROM lecturers l
         LEFT JOIN courses c ON c.lecturer_id = l.id
         LEFT JOIN student_courses sc ON sc.course_id = c.id
         LEFT JOIN attendance a ON a.course_id = c.id
         WHERE l.user_id = ?"
    );
    $scope_stmt->bind_param("i", $user_id);
    $scope_stmt->execute();
    $scope = $scope_stmt->get_result()->fetch_assoc();
    $scope_stmt->close();
    $totals = [
        "students" => $scope["students"] ?? 0,
        "lecturers" => 1,
        "courses" => $scope["courses"] ?? 0,
        "attendance" => $scope["attendance"] ?? 0,
    ];
    $today = [
        "present_total" => $scope["present_total"] ?? 0,
        "absent_total" => $scope["absent_total"] ?? 0,
    ];
    $recent_stmt = $conn->prepare(
        "SELECT a.attendance_date, a.status, s.registration_number,
                s.full_name AS student_name, c.course_code, u.full_name AS recorder
         FROM attendance a
         INNER JOIN students s ON s.id = a.student_id
         INNER JOIN courses c ON c.id = a.course_id
         INNER JOIN lecturers l ON l.id = c.lecturer_id
         LEFT JOIN users u ON u.id = a.recorded_by
         WHERE l.user_id = ?
         ORDER BY a.created_at DESC LIMIT 8"
    );
    $recent_stmt->bind_param("i", $user_id);
    $recent_stmt->execute();
    $recent = $recent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $recent_stmt->close();
}

$present_today = (int) ($today["present_total"] ?? 0);
$absent_today = (int) ($today["absent_total"] ?? 0);
$marked_today = $present_today + $absent_today;
$today_percentage = $marked_today > 0 ? round($present_today / $marked_today * 100, 1) : 0;

$trend_stmt = $conn->prepare(
    "SELECT attendance_date,
            SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) AS present_total,
            COUNT(*) AS marked_total
     FROM attendance
     WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
       AND (? = 'admin' OR course_id IN (
            SELECT c.id FROM courses c
            INNER JOIN lecturers l ON l.id = c.lecturer_id
            WHERE l.user_id = ?
       ))
     GROUP BY attendance_date ORDER BY attendance_date"
);
$trend_stmt->bind_param("si", $role, $user_id);
$trend_stmt->execute();
$trend_rows = $trend_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$trend_stmt->close();
$trend = [];
foreach ($trend_rows as $row) {
    $trend[$row["attendance_date"]] = $row;
}
$trend_max = 0;
foreach ($trend_rows as $row) {
    $trend_max = max($trend_max, (int) $row["present_total"]);
}

$recent_students = [];
if ($role === "admin") {
    $recent_students = $conn->query(
        "SELECT registration_number, full_name, created_at
         FROM students ORDER BY created_at DESC LIMIT 5"
    )->fetch_all(MYSQLI_ASSOC);
}

require "config/includes/header.php";
require "config/includes/sidebar.php";
?>
<main class="main-content" id="main-content">
    <div class="page-heading"><div><h1>Dashboard</h1><p>Welcome, <?= e($_SESSION["full_name"] ?? "User") ?>. Here is your attendance overview.</p></div><div class="date-display"><?= e(date("l, d M Y")) ?></div></div>
    <section class="stats-grid">
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">ST</div><div><p>Students</p><h2 class="stat-value" data-count="<?= (int) $totals["students"] ?>"><?= (int) $totals["students"] ?></h2></div></article>
        <?php if ($role === "admin"): ?><article class="stat-card"><div class="stat-icon" aria-hidden="true">LC</div><div><p>Lecturers</p><h2 class="stat-value" data-count="<?= (int) $totals["lecturers"] ?>"><?= (int) $totals["lecturers"] ?></h2></div></article><?php endif; ?>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">CR</div><div><p>Assigned courses</p><h2 class="stat-value" data-count="<?= (int) $totals["courses"] ?>"><?= (int) $totals["courses"] ?></h2></div></article>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">AT</div><div><p>Attendance records</p><h2 class="stat-value" data-count="<?= (int) $totals["attendance"] ?>"><?= (int) $totals["attendance"] ?></h2></div></article>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">✓</div><div><p>Present today</p><h2 class="stat-value" data-count="<?= $present_today ?>"><?= $present_today ?></h2></div></article>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">×</div><div><p>Absent today</p><h2 class="stat-value" data-count="<?= $absent_today ?>"><?= $absent_today ?></h2></div></article>
        <article class="stat-card"><div class="stat-icon" aria-hidden="true">%</div><div><p>Today's attendance rate</p><h2><?= number_format($today_percentage, 1) ?>%</h2></div></article>
    </section>

    <div class="dashboard-columns">
        <section class="welcome-card"><h2>Attendance trend</h2><p>Daily present marks for the last seven days.</p>
            <div class="trend-chart" role="img" aria-label="Present attendance records for the last seven days">
                <?php for ($offset = 6; $offset >= 0; $offset--): $day = date("Y-m-d", strtotime("-" . $offset . " days")); $value = (int) ($trend[$day]["present_total"] ?? 0); $bar = $trend_max > 0 && $value > 0 ? max(5, (int) round($value / $trend_max * 100)) : 3; ?>
                    <div class="trend-column" title="<?= e(date("d M", strtotime($day)) . ": " . $value . " present") ?>"><span class="trend-value"><?= $value ?></span><div class="trend-bar" style="--bar-height: <?= $bar ?>%"></div><span class="trend-label"><?= e(date("D", strtotime($day))) ?></span></div>
                <?php endfor; ?>
            </div>
        </section>
        <section class="welcome-card"><h2>Quick actions</h2><p>Open a common task.</p>
            <div class="quick-actions"><a href="attendance.php" class="btn btn-primary">Record attendance</a><a href="reports.php" class="btn btn-secondary">View reports</a><?php if ($role === "admin"): ?><a href="students.php" class="btn btn-secondary">Add student</a><a href="courses.php" class="btn btn-secondary">Add course</a><?php endif; ?></div>
        </section>
    </div>

    <section class="welcome-card records-card"><h2>Recent attendance activity</h2><div class="table-container"><table><thead><tr><th>Date</th><th>Registration</th><th>Student</th><th>Course</th><th>Status</th><th>Recorded by</th></tr></thead><tbody>
        <?php if (!$recent): ?><tr><td colspan="6" class="empty-state">No attendance records yet. Record attendance to see recent activity.</td></tr><?php endif; ?>
        <?php foreach ($recent as $record): ?><tr><td><?= e(date("d M Y", strtotime($record["attendance_date"]))) ?></td><td><?= e($record["registration_number"]) ?></td><td><?= e($record["student_name"]) ?></td><td><?= e($record["course_code"]) ?></td><td><span class="status-badge status-<?= strtolower(e($record["status"])) ?>"><?= e($record["status"]) ?></span></td><td><?= e($record["recorder"] ?: "—") ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section>

    <?php if ($role === "admin"): ?><section class="welcome-card records-card"><h2>Recent student registrations</h2><div class="table-container"><table><thead><tr><th>Registration</th><th>Student</th><th>Registered</th></tr></thead><tbody>
        <?php if (!$recent_students): ?><tr><td colspan="3" class="empty-state">No students registered yet.</td></tr><?php endif; ?>
        <?php foreach ($recent_students as $student): ?><tr><td><?= e($student["registration_number"]) ?></td><td><?= e($student["full_name"]) ?></td><td><?= e(date("d M Y", strtotime($student["created_at"]))) ?></td></tr><?php endforeach; ?>
    </tbody></table></div></section><?php endif; ?>
</main>
<?php require "config/includes/footer.php"; ?>
