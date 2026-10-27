<?php

require_once "config/includes/helpers.php";

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}

exit;

?>