<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../core/init.php';

$teacherId = 2;
$_SESSION['user_id'] = $teacherId;
$_SESSION['role'] = 'teacher';

$p1 = has_permission('manage_exams');
$p2 = has_permission('view_finances');

echo "manage_exams: " . ($p1 ? "TRUE" : "FALSE") . "\n";
echo "view_finances: " . ($p2 ? "TRUE" : "FALSE") . "\n";
