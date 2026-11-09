<?php
// Set up simulated web session
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../core/init.php';

$files = [
    'fee_structures.php',
    'discounts.php',
    'clearance.php',
    'daily_settlement.php',
    'index.php'
];

foreach ($files as $file) {
    $_SERVER['SCRIPT_FILENAME'] = realpath(__DIR__ . '/../modules/Finance/' . $file);
    $_SERVER['PHP_SELF'] = '/school_system/modules/Finance/' . $file;
    ob_start();
    include __DIR__ . '/../modules/Finance/' . $file;
    $html = ob_get_clean();
    preg_match('/<title>(.*?)<\/title>/', $html, $m);
    $title = $m[1] ?? 'None';
    echo "$file: OK (" . strlen($html) . " bytes) - Title: $title\n";
}
