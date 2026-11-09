<?php
session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['full_name'] = 'System Administrator';
ob_start();
require_once __DIR__ . '/../modules/HR/advances.php';
$out = ob_get_clean();
echo "Render Success! Output size: " . strlen($out) . " bytes\n";
