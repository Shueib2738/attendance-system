<?php

require_once "config/includes/helpers.php";

require_once "config/database.php";

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    verify_csrf_token();

    $username = trim(input_string($_POST, "username"));
    $password = input_string($_POST, "password");

    if ($username === "" || $password === "") {

        $error = "Please enter your username and password.";

    } else {

        $stmt = $conn->prepare(
            "SELECT id, full_name, username, password, role
             FROM users
             WHERE username = ? AND is_active = 1
             LIMIT 1"
        );

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user["password"])) {

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["full_name"] = $user["full_name"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["role"] = $user["role"];
            unset($_SESSION["csrf_token"]);

            header("Location: dashboard.php");
            exit;

        } else {

            $error = "Invalid username or password.";

        }

        $stmt->close();
    }
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Login | Student Attendance System</title>

    <link rel="stylesheet" href="config/includes/assets/style.css">
</head>

<body class="login-page">

    <div class="login-container">

        <div class="login-logo">
            <div class="logo-lockup">
                <img
                    src="config/includes/assets/zetech-logo.png"
                    alt="Zetech University logo"
                >
                <span>Zetech University</span>
            </div>

            <h1>Student Attendance</h1>

            <p>Secure Online Student Attendance Management</p>
        </div>

        <div class="login-card">

            <h2>Welcome Back</h2>

            <p class="login-description">
                Sign in to access your account.
            </p>

            <?php if ($error !== ""): ?>
                <div class="alert alert-error">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <div class="form-group">
                    <label for="username">Username</label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Enter your username"
                        autocomplete="username"
                        required
                    >
                </div>

                <div class="form-group">
                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary login-button">
                    Sign In
                </button>

            </form>

            <p class="login-footer">
                Secure access for authorized users only.
            </p>

        </div>

        <p class="copyright">
            &copy; <?= date("Y") ?> Student Attendance System
        </p>

    </div>

</body>
</html>