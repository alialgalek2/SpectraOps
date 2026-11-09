<?php
/**
 * Redirect shim: Teacher Dashboard moved from HR Module to LMS Module.
 */
require_once __DIR__ . '/../../core/init.php';

$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: ../LMS/teacher_dashboard.php" . $qs, true, 301);
exit();