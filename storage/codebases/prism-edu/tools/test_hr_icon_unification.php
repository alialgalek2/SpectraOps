<?php
/**
 * Test Suite: HR & Personnel Module Icon & Theme Unification Verification
 */

require_once __DIR__ . '/../core/init.php';

echo "========================================================\n";
echo "    HR & PERSONNEL ICON UNIFICATION TEST SUITE         \n";
echo "========================================================\n\n";

$passCount = 0;
$totalTests = 0;

function assertCheck(bool $condition, string $message): void {
    global $passCount, $totalTests;
    $totalTests++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] $message\n";
    } else {
        echo "  [FAIL] $message\n";
    }
}

// Test 1: Check HR Central Hub (modules/HR/index.php) action cards and icon themes
echo "[TEST 1] Testing modules/HR/index.php action cards and icon themes...\n";
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Administrator';

ob_start();
include __DIR__ . '/../modules/HR/index.php';
$hrHubOutput = ob_get_clean();

assertCheck(strpos($hrHubOutput, 'admin-action-card') !== false, "modules/HR/index.php uses .admin-action-card");
assertCheck(strpos($hrHubOutput, 'action-icon-box') !== false, "modules/HR/index.php uses .action-icon-box");
assertCheck(strpos($hrHubOutput, 'hr-card') === false, "modules/HR/index.php eliminated old .hr-card");
assertCheck(strpos($hrHubOutput, 'bg-opacity-10') === false, "modules/HR/index.php eliminated old bg-opacity-10 icon wrappers");
assertCheck(strpos($hrHubOutput, 'icon-box-primary') !== false, "modules/HR/index.php contains icon-box-primary");
assertCheck(strpos($hrHubOutput, 'icon-box-success') !== false, "modules/HR/index.php contains icon-box-success");
assertCheck(strpos($hrHubOutput, 'icon-box-warning') !== false, "modules/HR/index.php contains icon-box-warning");
assertCheck(strpos($hrHubOutput, 'icon-box-purple') !== false, "modules/HR/index.php contains icon-box-purple");
assertCheck(strpos($hrHubOutput, 'icon-box-info') !== false, "modules/HR/index.php contains icon-box-info");
assertCheck(strpos($hrHubOutput, 'icon-box-secondary') !== false, "modules/HR/index.php contains icon-box-secondary");
assertCheck(strpos($hrHubOutput, 'fa-users-gear') !== false, "modules/HR/index.php uses fa-users-gear for Staff Directory");
assertCheck(strpos($hrHubOutput, 'fa-user-plus') !== false, "modules/HR/index.php uses fa-user-plus for Add Employee");
assertCheck(strpos($hrHubOutput, 'fa-person-walking-arrow-right') !== false, "modules/HR/index.php uses fa-person-walking-arrow-right for Staff Leaves");
assertCheck(strpos($hrHubOutput, 'fa-file-invoice-dollar') !== false, "modules/HR/index.php uses fa-file-invoice-dollar for Payroll");
assertCheck(strpos($hrHubOutput, 'fa-book-bookmark') !== false, "modules/HR/index.php uses fa-book-bookmark for Payroll Ledger");
assertCheck(strpos($hrHubOutput, 'fa-chart-pie') !== false, "modules/HR/index.php uses fa-chart-pie for HR Analytics");
assertCheck(strpos($hrHubOutput, 'bg-background') !== false, "modules/HR/index.php uses bg-background");

// Test 2: Check leaves.php layout and theme tokens
echo "\n[TEST 2] Testing modules/HR/leaves.php layout & theme tokens...\n";
ob_start();
include __DIR__ . '/../modules/HR/leaves.php';
$leavesOutput = ob_get_clean();

assertCheck(strpos($leavesOutput, 'bg-background') !== false, "modules/HR/leaves.php uses bg-background");
assertCheck(strpos($leavesOutput, 'border-border') !== false, "modules/HR/leaves.php uses theme border-border");
assertCheck(strpos($leavesOutput, 'bg-card') !== false, "modules/HR/leaves.php uses theme bg-card");

// Test 3: Check payroll.php layout and theme tokens
echo "\n[TEST 3] Testing modules/HR/payroll.php layout & theme tokens...\n";
ob_start();
include __DIR__ . '/../modules/HR/payroll.php';
$payrollOutput = ob_get_clean();

assertCheck(strpos($payrollOutput, 'bg-background') !== false, "modules/HR/payroll.php uses bg-background");
assertCheck(strpos($payrollOutput, 'border-border') !== false, "modules/HR/payroll.php uses theme border-border");
assertCheck(strpos($payrollOutput, 'bg-card') !== false, "modules/HR/payroll.php uses theme bg-card");

// Test 4: Check payroll_ledger.php layout and theme tokens
echo "\n[TEST 4] Testing modules/HR/payroll_ledger.php layout & theme tokens...\n";
ob_start();
include __DIR__ . '/../modules/HR/payroll_ledger.php';
$ledgerOutput = ob_get_clean();

assertCheck(strpos($ledgerOutput, 'bg-background') !== false, "modules/HR/payroll_ledger.php uses bg-background");
assertCheck(strpos($ledgerOutput, 'border-border') !== false, "modules/HR/payroll_ledger.php uses theme border-border");
assertCheck(strpos($ledgerOutput, 'bg-card') !== false, "modules/HR/payroll_ledger.php uses theme bg-card");

echo "\n========================================================\n";
echo "  TEST RESULTS: $passCount / $totalTests Passed\n";
echo "========================================================\n";
if ($passCount === $totalTests) {
    echo "SUCCESS: All HR & Personnel module icon unification tests passed!\n";
    exit(0);
} else {
    echo "FAILED: Some tests did not pass.\n";
    exit(1);
}
