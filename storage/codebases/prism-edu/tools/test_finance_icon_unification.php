<?php
/**
 * Verification Test: Finance Module Unified Icon & Theme Standardization
 */

require_once __DIR__ . '/../core/init.php';

echo "=== VERIFYING FINANCE MODULE ICON & THEME STANDARDIZATION ===\n\n";

$financeIndex = file_get_contents(__DIR__ . '/../modules/Finance/index.php');

// Test 1: Check for admin-action-card and action-icon-box usage
$checks = [
    'admin-action-card' => 'Uses .admin-action-card for action cards',
    'action-icon-box'   => 'Uses .action-icon-box for icon containers',
    'icon-box-warning'  => 'Uses .icon-box-warning color palette',
    'icon-box-primary'  => 'Uses .icon-box-primary color palette',
    'icon-box-success'  => 'Uses .icon-box-success color palette',
    'icon-box-info'     => 'Uses .icon-box-info color palette',
    'icon-box-purple'   => 'Uses .icon-box-purple color palette',
    'fa-building-columns' => 'Has Treasury Desk icon (fa-building-columns)',
    'fa-receipt'        => 'Has Fee Structures icon (fa-receipt)',
    'fa-credit-card'    => 'Has Payments icon (fa-credit-card)',
    'fa-file-invoice-dollar' => 'Has Account Statements icon (fa-file-invoice-dollar)',
    'fa-money-bill-transfer' => 'Has Payroll Disbursements icon (fa-money-bill-transfer)',
    'bg-background'     => 'Uses theme standard bg-background',
    'text-foreground'   => 'Uses theme text-foreground tokens'
];

$allPassed = true;
foreach ($checks as $pattern => $desc) {
    if (strpos($financeIndex, $pattern) !== false) {
        echo "[PASS] $desc\n";
    } else {
        echo "[FAIL] Missing pattern: '$pattern' ($desc)\n";
        $allPassed = false;
    }
}

// Test 2: Check that old broken solid color patterns were removed
$antiPatterns = [
    'rounded-circle bg-warning bg-opacity-10',
    'rounded-circle bg-primary bg-opacity-10',
    'rounded-circle bg-success bg-opacity-10',
    'rounded-circle bg-info bg-opacity-10',
    'rounded-circle bg-danger bg-opacity-10',
    'class="card finance-card'
];

foreach ($antiPatterns as $anti) {
    if (strpos($financeIndex, $anti) === false) {
        echo "[PASS] Clean: Removed old anti-pattern '$anti'\n";
    } else {
        echo "[FAIL] Found deprecated anti-pattern '$anti'\n";
        $allPassed = false;
    }
}

// Test 3: Test Simulated Execution of Finance Hub
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

ob_start();
try {
    include __DIR__ . '/../modules/Finance/index.php';
    $output = ob_get_clean();
    if (strlen($output) > 500 && strpos($output, 'admin-action-card') !== false) {
        echo "[PASS] modules/Finance/index.php executed and rendered cleanly (" . strlen($output) . " bytes)\n";
    } else {
        echo "[FAIL] modules/Finance/index.php rendered output was unexpected\n";
        $allPassed = false;
    }
} catch (\Throwable $e) {
    ob_end_clean();
    echo "[FAIL] Exception during render: " . $e->getMessage() . "\n";
    $allPassed = false;
}

if ($allPassed) {
    echo "\n>>> ALL FINANCE ICON UNIFICATION TESTS PASSED SUCCESSFULLY! <<<\n";
} else {
    echo "\n>>> SOME TESTS FAILED! <<<\n";
    exit(1);
}
