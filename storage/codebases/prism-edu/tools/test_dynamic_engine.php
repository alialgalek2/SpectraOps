<?php
/**
 * Automated Verification Script for DynamicDashboardEngine & Drag-Drop Module System
 */

require_once __DIR__ . '/../core/init.php';

echo "=======================================================\n";
echo "1. Testing DynamicDashboardEngine Class & Permissions\n";
echo "=======================================================\n";

if (!class_exists('DynamicDashboardEngine')) {
    echo "[FAIL] DynamicDashboardEngine class does not exist!\n";
    exit(1);
}
echo "[PASS] DynamicDashboardEngine class exists.\n";

// Test for Admin role
$adminModules = DynamicDashboardEngine::getPermittedModules($pdo, 1, 'admin');
echo "Admin Permitted Modules Count: " . count($adminModules) . "\n";
foreach ($adminModules as $m) {
    echo " - [{$m['id']}] {$m['title']} -> {$m['landing_url']} (Subcats: " . count($m['subcategories']) . ")\n";
}
if (count($adminModules) < 4) {
    echo "[FAIL] Expected at least 4 modules for Admin.\n";
    exit(1);
}
echo "[PASS] Admin modules verified.\n";

// Test for Student role
$studentModules = DynamicDashboardEngine::getPermittedModules($pdo, 999, 'student');
echo "\nStudent Permitted Modules Count: " . count($studentModules) . "\n";
foreach ($studentModules as $m) {
    echo " - [{$m['id']}] {$m['title']} -> {$m['landing_url']}\n";
    if (in_array($m['id'], ['module_administration', 'module_hr'])) {
        echo "[FAIL] Student should NOT have access to {$m['id']}!\n";
        exit(1);
    }
}
echo "[PASS] Student permissions filtering verified.\n";

// Test for HR role
$hrModules = DynamicDashboardEngine::getPermittedModules($pdo, 998, 'hr');
echo "\nHR Permitted Modules Count: " . count($hrModules) . "\n";
$hrHasHr = false;
foreach ($hrModules as $m) {
    echo " - [{$m['id']}] {$m['title']}\n";
    if ($m['id'] === 'module_hr') $hrHasHr = true;
    if ($m['id'] === 'module_grading') {
        echo "[FAIL] HR should NOT have access to Grading!\n";
        exit(1);
    }
}
if (!$hrHasHr) {
    echo "[FAIL] HR role must have access to module_hr.\n";
    exit(1);
}
echo "[PASS] HR permissions filtering verified.\n";

echo "\n=======================================================\n";
echo "2. Testing Column Existence & Sequence Persistence\n";
echo "=======================================================\n";

DynamicDashboardEngine::ensureModuleOrderColumnExists($pdo);
$colCheck = $pdo->query("SHOW COLUMNS FROM users LIKE 'dashboard_modules_order'")->fetch();
if (!$colCheck) {
    echo "[FAIL] Column dashboard_modules_order was not created!\n";
    exit(1);
}
echo "[PASS] Column dashboard_modules_order verified in users table.\n";

// Test custom order persistence
$adminUser = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
$testUserId = $adminUser ? (int)$adminUser['user_id'] : 1;
echo "Testing with Admin User ID: $testUserId\n";

$testOrder = ['module_hr', 'module_lms', 'module_finance', 'module_administration'];
$stmt = $pdo->prepare("UPDATE users SET dashboard_modules_order = ? WHERE user_id = ?");
$stmt->execute([json_encode($testOrder), $testUserId]);

$savedRaw = DynamicDashboardEngine::getUserModuleOrder($pdo, $testUserId);
echo "Direct DB Read Order: " . json_encode($savedRaw) . "\n";

$reordered = DynamicDashboardEngine::getPermittedModules($pdo, $testUserId, 'admin');
echo "Reordered First Module: {$reordered[0]['id']}\n";
if ($reordered[0]['id'] !== 'module_hr') {
    echo "[FAIL] Expected first module to be module_hr based on custom order!\n";
    exit(1);
}
echo "[PASS] Custom Drag-and-Drop sequence reordering verified!\n";

// Reset for cleanliness
$stmt = $pdo->prepare("UPDATE users SET dashboard_modules_order = NULL WHERE user_id = ?");
$stmt->execute([$testUserId]);

echo "\n=======================================================\n";
echo "ALL TESTS PASSED SUCCESSFULLY!\n";
echo "=======================================================\n";
