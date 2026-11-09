<?php
require_once __DIR__ . '/bootstrap/license_check.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
} else {
    header("Location: login.php");
}
exit();