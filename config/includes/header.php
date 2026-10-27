<?php

require_once __DIR__ . "/helpers.php";

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Attendance System</title>

    <link rel="stylesheet" href="config/includes/assets/style.css">

</head>

<body class="app-body">

<a class="skip-link" href="#main-content">Skip to content</a>

<header class="topbar">

    <a href="dashboard.php" class="brand">
        <img
            class="brand-logo"
            src="config/includes/assets/zetech-logo.png"
            alt="Zetech University logo"
        >
        <span class="brand-copy">
            <strong>Zetech University</strong>
            <small>Student Attendance System</small>
        </span>
    </a>

    <div class="user-info" aria-label="Signed-in user">

        <span class="user-name">
            <?= e($_SESSION["full_name"] ?? "User") ?>
        </span>

        <span class="role-badge">
            <?= e(ucfirst($_SESSION["role"] ?? "user")) ?>
        </span>

        <form method="post" action="logout.php" class="logout-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="logout-link">Logout</button>
        </form>

    </div>

</header>

<div class="app-layout">