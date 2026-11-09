<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

echo "========================================================\n";
echo "Testing Embedded Deployment Node Feed in Fleet Overview\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();

// Verify Deployment 8 exists
$stmt = $pdo->prepare("SELECT id, product_id, client_name, deployment_domain FROM central_deployments WHERE id = 8");
$stmt->execute();
$dep = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dep) {
    echo "[FAIL] Deployment 8 (Prism EDU Local Testbed) not found in database.\n";
    exit(1);
}
echo "[PASS] Deployment 8 verified: {$dep['client_name']} (Product ID: {$dep['product_id']})\n";

// Test 1: Render dashboard.php?page=overview&product_id=1
$_GET = [
    'page' => 'overview',
    'product_id' => '1'
];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = 'dashboard.php?page=overview&product_id=1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$_SESSION = [
    'fleet_user_id' => 1,
    'fleet_user_name' => 'Operator Admin',
    'fleet_user_role' => 'admin',
    'fleet_admin_logged' => true,
    'csrf_token' => 'test_csrf_token_12345'
];

ob_start();
require dirname(__DIR__) . '/public/dashboard.php';
$html = ob_get_clean();

$checks = [
    'Deployment Node Feed Section' => (strpos($html, 'Client Deployment Node') !== false || strpos($html, 'Deployment Node Feed') !== false),
    'Node Client Name' => strpos($html, 'Prism EDU Local Testbed') !== false,
    'Node Domain' => strpos($html, 'localhost:8080/school_system') !== false,
    'License Authorization Card' => strpos($html, 'Cryptographic License Authorization') !== false,
    'License Status Control (Suspend/Reactivate)' => (strpos($html, 'Suspend License') !== false || strpos($html, 'Reactivate License') !== false),
    'Support Tickets Feed' => strpos($html, 'Client Support Tickets') !== false,
    'Telemetry Error Stream' => strpos($html, 'Telemetry Error Stream') !== false,
    'Split Grid Layout' => strpos($html, 'split-grid') !== false,
    'AI Hotfix Trigger' => strpos($html, 'triggerAiFix') !== false,
    'Dispatch Hotfix Control' => strpos($html, 'Dispatch Hotfix') !== false,
    'Embedded Feed Anchor or Link' => strpos($html, '#deployment-feed') !== false
];

$allPassed = true;
foreach ($checks as $label => $pass) {
    if ($pass) {
        echo "[PASS] Feed Check: '{$label}' found in dashboard output.\n";
    } else {
        echo "[FAIL] Feed Check: '{$label}' is MISSING in dashboard output.\n";
        $allPassed = false;
    }
}

// Check that client link in overview table does not open deployment_view.php
if (strpos($html, 'href="deployment_view.php?id=8"') !== false) {
    echo "[FAIL] Found legacy separate window link 'deployment_view.php?id=8' in table.\n";
    $allPassed = false;
} else {
    echo "[PASS] No separate window link 'deployment_view.php?id=8' found in overview table.\n";
}

if (!$allPassed) {
    echo "\n[FAIL] Pre-implementation tests failed as expected.\n";
    exit(1);
}

echo "\n========================================================\n";
echo "All Embedded Deployment Node Feed Tests Passed!\n";
echo "========================================================\n";
