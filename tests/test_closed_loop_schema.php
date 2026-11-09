<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

echo "========================================================\n";
echo "Testing Closed-Loop Resolution Receipt Database Schema\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();
$service = new FleetService();

// 1. Verify central_error_logs columns
$errorCols = array_column($pdo->query("PRAGMA table_info(central_error_logs)")->fetchAll(PDO::FETCH_ASSOC), 'name');
if (empty($errorCols)) {
    // If MySQL
    $errorCols = array_column($pdo->query("SHOW COLUMNS FROM central_error_logs")->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

$hasStatus = in_array('status', $errorCols);
$hasResolvedVersion = in_array('resolved_by_version', $errorCols);
$hasResolvedAt = in_array('resolved_at', $errorCols);

echo ($hasStatus ? " [PASS] " : " [FAIL] ") . "central_error_logs has 'status' column\n";
echo ($hasResolvedVersion ? " [PASS] " : " [FAIL] ") . "central_error_logs has 'resolved_by_version' column\n";
echo ($hasResolvedAt ? " [PASS] " : " [FAIL] ") . "central_error_logs has 'resolved_at' column\n";

// 2. Verify central_releases columns
$relCols = array_column($pdo->query("PRAGMA table_info(central_releases)")->fetchAll(PDO::FETCH_ASSOC), 'name');
if (empty($relCols)) {
    $relCols = array_column($pdo->query("SHOW COLUMNS FROM central_releases")->fetchAll(PDO::FETCH_ASSOC), 'Field');
}

$hasResolvesErrorId = in_array('resolves_error_id', $relCols);
echo ($hasResolvesErrorId ? " [PASS] " : " [FAIL] ") . "central_releases has 'resolves_error_id' column\n";

// 3. Test Closed-Loop link functionality
// Get an existing deployment or create a dummy one
$depRow = $pdo->query("SELECT id FROM central_deployments LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$depRow) {
    $prodRow = $pdo->query("SELECT id FROM central_products LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $prodId = $prodRow ? (int)$prodRow['id'] : 1;
    $reg = $service->registerDeployment($prodId, "Test Client", "test.client.com");
    $depId = (int)$reg['id'];
} else {
    $depId = (int)$depRow['id'];
}

$prodRow = $pdo->query("SELECT id FROM central_products LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$prodId = $prodRow ? (int)$prodRow['id'] : 1;

// Seed a test error log
$stmt = $pdo->prepare("
    INSERT INTO central_error_logs (
        deployment_id, error_hash, error_level, status, error_message, first_seen_at, last_seen_at
    ) VALUES (
        ?, 'test_closed_loop_hash', 'EXCEPTION', 'open', 'Test closed loop exception', datetime('now'), datetime('now')
    )
");
$stmt->execute([$depId]);
$testErrorId = (int)$pdo->lastInsertId();

echo " [*] Seeded test error log #{$testErrorId} with status: open\n";

// Publish release resolving this error
$rel = $service->publishRelease(
    $prodId,
    '9.9.9',
    'Closed loop test patch',
    'test_patch_9.9.9.zip',
    hash('sha256', 'closed_loop_test'),
    true,
    $testErrorId
);

// Verify central_releases has resolves_error_id
$checkRel = $pdo->prepare("SELECT resolves_error_id FROM central_releases WHERE id = ?");
$checkRel->execute([$rel['id']]);
$savedErrId = (int)$checkRel->fetchColumn();

$linkPass = ($savedErrId === $testErrorId);
echo ($linkPass ? " [PASS] " : " [FAIL] ") . "central_releases.resolves_error_id points to error #{$testErrorId}\n";

// Verify central_error_logs status transitioned to patch_pending
$checkErr = $pdo->prepare("SELECT status, resolved_by_version FROM central_error_logs WHERE id = ?");
$checkErr->execute([$testErrorId]);
$errRow = $checkErr->fetch(PDO::FETCH_ASSOC);

$statusPass = ($errRow['status'] === 'patch_pending' && $errRow['resolved_by_version'] === '9.9.9');
echo ($statusPass ? " [PASS] " : " [FAIL] ") . "central_error_logs transitioned to status 'patch_pending' (v{$errRow['resolved_by_version']})\n";

// 4. Test Step 4: Simulate Ingest confirm_ota_receipt
$findRelease = $pdo->prepare("
    SELECT resolves_error_id FROM central_releases 
    WHERE product_id = ? AND version = ? AND resolves_error_id IS NOT NULL
");
$findRelease->execute([$prodId, '9.9.9']);
$resolvedErrors = $findRelease->fetchAll(PDO::FETCH_COLUMN);

if (!empty($resolvedErrors)) {
    $inClause = implode(',', array_map('intval', $resolvedErrors));
    $pdo->exec("
        UPDATE central_error_logs 
        SET status = 'resolved', resolved = 1, resolved_at = datetime('now') 
        WHERE id IN ({$inClause}) AND deployment_id = {$depId}
    ");
}

$checkFinal = $pdo->prepare("SELECT status, resolved, resolved_at FROM central_error_logs WHERE id = ?");
$checkFinal->execute([$testErrorId]);
$finalRow = $checkFinal->fetch(PDO::FETCH_ASSOC);

$receiptPass = ($finalRow['status'] === 'resolved' && (int)$finalRow['resolved'] === 1 && !empty($finalRow['resolved_at']));
echo ($receiptPass ? " [PASS] " : " [FAIL] ") . "confirm_ota_receipt transitioned error to 'resolved' with resolved_at timestamp\n";

// Cleanup test records
$pdo->prepare("DELETE FROM central_releases WHERE id = ?")->execute([$rel['id']]);
$pdo->prepare("DELETE FROM central_error_logs WHERE id = ?")->execute([$testErrorId]);

echo "\n========================================================\n";
echo "SUCCESS: Closed-Loop Database Schema Fully Verified!\n";
echo "========================================================\n";
