<?php

$current_page = basename($_SERVER["PHP_SELF"] ?? "");
$navigation_links = [
    ["href" => "dashboard.php", "label" => "Dashboard", "icon" => "DB"],
    ["href" => "attendance.php", "label" => "Attendance", "icon" => "AT"],
    ["href" => "reports.php", "label" => "Reports", "icon" => "RP"],
    ["href" => "profile.php", "label" => "Account security", "icon" => "PW"],
];

if (($_SESSION["role"] ?? "") === "admin") {
    array_splice($navigation_links, 1, 0, [
        ["href" => "students.php", "label" => "Students", "icon" => "ST"],
        ["href" => "lecturers.php", "label" => "Lecturers", "icon" => "LC"],
        ["href" => "courses.php", "label" => "Courses", "icon" => "CR"],
        ["href" => "enrollments.php", "label" => "Enrollments", "icon" => "EN"],
        ["href" => "users.php", "label" => "User accounts", "icon" => "US"],
    ]);
}

?>
<aside class="sidebar">

    <p class="sidebar-heading">MAIN MENU</p>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <?php foreach ($navigation_links as $link): ?>
            <?php $is_current = $link["href"] === $current_page; ?>
            <a
                href="<?= e($link["href"]) ?>"
                class="sidebar-link<?= $is_current ? " is-active" : "" ?>"
                <?= $is_current ? 'aria-current="page"' : "" ?>
            >
                <span class="sidebar-link-icon" aria-hidden="true">
                    <?= e($link["icon"]) ?>
                </span>
                <span><?= e($link["label"]) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

</aside>
