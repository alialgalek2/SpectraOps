<?php
require_once __DIR__ . '/../core/init.php';

// Mock session
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

echo "=== TEST 1: Basic Single-Row Navbar Render ===\n";
$html1 = render_universal_navbar([
    'active_title' => 'Test Page',
    'back_url' => 'index.php'
]);

assert_true(strpos($html1, 'flex-nowrap') !== false, "Must have flex-nowrap in container");
assert_true(strpos($html1, 'navbar-custom') !== false, "Must have navbar-custom class");
assert_true(strpos($html1, 'flex-wrap') === false, "Must NEVER have flex-wrap");
echo "PASS: Test 1 Basic render is single-row\n\n";

echo "=== TEST 2: Filter cross-module and duplicate buttons ===\n";
// Simulate being in Finance module: /school_system/modules/Finance/fees_portal.php
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/Finance/fees_portal.php';

$dirty_nav_items = [
    '<a href="index.php">Duplicate Back</a>',
    '<a href="../../dashboard.php">Dashboard Redundant</a>',
    '<a href="../HR/payroll_ledger.php">Unrelated HR Button</a>',
    '<a href="../LMS/parent_portal.php">Unrelated LMS Button</a>',
    '<a href="fees_portal.php">Self Link</a>',
    '<a href="payroll_disbursement.php">Valid In-Module Action 1</a>',
    '<a href="fees.php">Valid In-Module Action 2</a>',
    '<a href="daily_settlement.php">Valid In-Module Action 3 (Should be capped)</a>'
];

$html2 = render_universal_navbar([
    'active_title' => 'Finance Treasury',
    'back_url' => 'index.php',
    'extra_nav_items' => $dirty_nav_items
]);

assert_true(strpos($html2, 'Unrelated HR Button') === false, "HR cross-module button MUST be dropped");
assert_true(strpos($html2, 'Unrelated LMS Button') === false, "LMS cross-module button MUST be dropped");
assert_true(strpos($html2, 'Duplicate Back') === false, "Duplicate back button MUST be dropped");
assert_true(strpos($html2, 'Self Link') === false, "Self link MUST be dropped");
assert_true(strpos($html2, 'Valid In-Module Action 1') !== false, "Valid in-module action 1 MUST be kept");
assert_true(strpos($html2, 'Valid In-Module Action 2') !== false, "Valid in-module action 2 MUST be kept");
assert_true(strpos($html2, 'Valid In-Module Action 3') === false, "Action 3 MUST be capped to maintain single-row");

echo "PASS: Test 2 Dynamic engine accurately cleans and caps unrelated/duplicate items!\n\n";

function assert_true($cond, $msg) {
    if (!$cond) {
        echo "FAIL: $msg\n";
        exit(1);
    }
}

echo "ALL TESTS PASSED SUCCESSFULLY!\n";
