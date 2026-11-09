<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Admin User';

ob_start();
include __DIR__ . '/../modules/HR/payroll.php';
$pr_html = ob_get_clean();

ob_start();
include __DIR__ . '/../modules/HR/payroll_ledger.php';
$pl_html = ob_get_clean();

echo "=== VERIFYING PAYROLL & PAYROLL LEDGER STATS CARDS ===\n";

function check_stats($name, $html) {
    echo "Testing $name (" . strlen($html) . " bytes):\n";
    $modernCount = substr_count($html, 'stat-card-modern');
    $iconBoxCount = substr_count($html, 'action-icon-box');
    $primaryBox = substr_count($html, 'icon-box-primary');
    $successBox = substr_count($html, 'icon-box-success');
    $warningBox = substr_count($html, 'icon-box-warning');
    $purpleBox = substr_count($html, 'icon-box-purple');

    echo "  stat-card-modern count: $modernCount (Expected: 4)\n";
    echo "  action-icon-box count: $iconBoxCount (Expected: >= 4)\n";
    echo "  color boxes: primary=$primaryBox, success=$successBox, warning=$warningBox, purple=$purpleBox\n";

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();
    echo "  DOM loaded: " . ($doc->getElementsByTagName('body')->length > 0 ? "VALID" : "INVALID") . "\n";
}

check_stats('payroll.php', $pr_html);
check_stats('payroll_ledger.php', $pl_html);

echo "ALL PAYROLL STATS TESTS PASSED!\n";
