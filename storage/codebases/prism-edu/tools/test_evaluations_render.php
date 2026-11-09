<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'System Administrator';
ob_start();
require_once __DIR__ . '/../modules/HR/evaluations.php';
$out = ob_get_clean();
echo "Evaluations Admin Render Success! Output size: " . strlen($out) . " bytes\n";

$_SESSION['role'] = 'teacher';
$_SESSION['user_id'] = 2;
$_SESSION['full_name'] = 'Teacher One';
ob_start();
require_once __DIR__ . '/../modules/HR/evaluations.php';
$out2 = ob_get_clean();
echo "Evaluations Teacher Render Success! Output size: " . strlen($out2) . " bytes\n";
