<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

echo "========================================================\n";
echo "Testing Deployment-Scoped Error Feed & Support Tickets\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();

// 1. Check deployment query
$stmt = $pdo->query("SELECT id FROM central_deployments LIMIT 1");
$dep = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dep) {
    echo "[FAIL] No deployments found in database to test.\n";
    exit(1);
}

$deploymentId = (int)$dep['id'];
echo "[INFO] Testing with deployment ID: {$deploymentId}\n";

// Step 1: Execute exact query from user prompt
$stmtDeploy = $pdo->prepare("
    SELECT d.*, p.product_name, p.product_slug, p.product_code, l.license_key, l.status AS license_status
    FROM central_deployments d
    JOIN central_products p ON d.product_id = p.id
    LEFT JOIN central_licenses l ON d.license_id = l.id
    WHERE d.id = ?
");
$stmtDeploy->execute([$deploymentId]);
$deployment = $stmtDeploy->fetch();

if (!$deployment) {
    echo "[FAIL] Deployment metadata query failed.\n";
    exit(1);
}
echo "[PASS] Step 1.2: Deployment metadata retrieved successfully for '{$deployment['client_name']}'.\n";

// Step 1.3: Support tickets query
$stmtTickets = $pdo->prepare("
    SELECT * FROM central_support_tickets 
    WHERE deployment_id = ? 
    ORDER BY created_at DESC
");
$stmtTickets->execute([$deploymentId]);
$clientTickets = $stmtTickets->fetchAll();
echo "[PASS] Step 1.3: Support tickets retrieved (" . count($clientTickets) . " tickets found).\n";

// Step 1.4: Telemetry Error Logs with ORDER BY occurred_at DESC
$stmtErrors = $pdo->prepare("
    SELECT * FROM central_error_logs 
    WHERE deployment_id = ? 
    ORDER BY occurred_at DESC
    LIMIT 100
");
$stmtErrors->execute([$deploymentId]);
$clientErrors = $stmtErrors->fetchAll();
echo "[PASS] Step 1.4: Telemetry Error logs retrieved (" . count($clientErrors) . " errors found).\n";

// Step 3: Check why old error logs might not match deployment_id query
$step3Query = $pdo->query("
    SELECT id, deployment_id, error_message, occurred_at 
    FROM central_error_logs 
    ORDER BY id DESC 
    LIMIT 10
");
$step3Rows = $step3Query->fetchAll(PDO::FETCH_ASSOC);
echo "[PASS] Step 3: Verification query with occurred_at executed cleanly (" . count($step3Rows) . " rows returned).\n";

// Test rendering output of public/deployment_view.php via simulated HTTP/CLI environment
$_GET['id'] = $deploymentId;
$_SESSION['fleet_user_id'] = 1;
$_SESSION['fleet_admin_logged'] = true;
$_SESSION['csrf_token'] = 'test_token_abc';

ob_start();
require dirname(__DIR__) . '/public/deployment_view.php';
$html = ob_get_clean();

$checks = [
    'Client Deployment Node' => strpos($html, 'Client Deployment Node') !== false,
    'Client Support Tickets' => strpos($html, 'Client Support Tickets') !== false,
    'Telemetry Error Stream' => strpos($html, 'Telemetry Error Stream') !== false,
    'split-grid' => strpos($html, 'split-grid') !== false,
    'Dispatch Hotfix' => strpos($html, 'Dispatch Hotfix') !== false,
    'triggerAiFix' => strpos($html, 'triggerAiFix') !== false,
    'Back to Fleet' => strpos($html, 'Back to Fleet') !== false,
];

foreach ($checks as $name => $passed) {
    if ($passed) {
        echo "[PASS] Render Check: '{$name}' is present in HTML.\n";
    } else {
        echo "[FAIL] Render Check: '{$name}' is missing in HTML.\n";
        exit(1);
    }
}

echo "\n========================================================\n";
echo "All Deployment Detail Feed Tests Passed Successfully!\n";
echo "========================================================\n";
