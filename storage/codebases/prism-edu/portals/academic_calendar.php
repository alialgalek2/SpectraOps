<?php
require_once __DIR__ . '/../core/init.php';
Auth::requireLogin();

if (Auth::hasRole('admin') || Auth::hasRole('school_admin')) {
    header("Location: " . BASE_URL . "/modules/Administration/academic_calendar.php");
} else {
    header("Location: " . BASE_URL . "/modules/LMS/calendar.php");
}
exit();
