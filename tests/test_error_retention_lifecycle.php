<?php
declare(strict_types=1);

/**
 * Test Suite: Telemetry Error Log Retention Lifecycle
 * Verifies:
 * 1. Errors resolved <= 30 days ago stay 'resolved'.
 * 2. Errors resolved > 30 days ago transition to 'archived'.
 * 3. Errors resolved/archived > 60 days ago are permanently purged.
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

echo "========================================================\n";
echo "Testing Error Log Retention Lifecycle (30d Archive, 60d Delete)\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();
$service = new FleetService($pdo);

// Ensure test deployment exists
$dep = $pdo->query("SELECT id FROM central_deployments LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$dep) {
    $prod = $pdo->query("SELECT id FROM central_products LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $prodId = $prod ? (int)$prod['id'] : 1;
    $reg = $service->registerDeployment($prodId, "Retention Test Corp", "retention.test.com");
    $depId = (int)$reg['id'];
} else {
    $depId = (int)$dep['id'];
}

$now = time();
$dateRecentResolved = gmdate('Y-m-d H:i:s', $now - (10 * 86400)); // 10 days ago (should stay resolved)
$dateOver30d        = gmdate('Y-m-d H:i:s', $now - (35 * 86400)); // 35 days ago (should be archived)
$dateOver60d        = gmdate('Y-m-d H:i:s', $now - (65 * 86400)); // 65 days ago (should be deleted)

// 1. Seed test records
$ins = $pdo->prepare("
    INSERT INTO central_error_logs (
        deployment_id, error_hash, error_level, status, error_message, resolved, resolved_at, first_seen_at, last_seen_at
    ) VALUES (?, ?, 'ERROR', 'resolved', ?, 1, ?, ?, ?)
");

$hash1 = md5("err_recent_" . $now);
$hash2 = md5("err_35d_" . $now);
$hash3 = md5("err_65d_" . $now);

$ins->execute([$depId, $hash1, "Recent resolved error (10d)", $dateRecentResolved, $dateRecentResolved, $dateRecentResolved]);
$idRecent = (int)$pdo->lastInsertId();

$ins->execute([$depId, $hash2, "Archive candidate error (35d)", $dateOver30d, $dateOver30d, $dateOver30d]);
$idArchived = (int)$pdo->lastInsertId();

$ins->execute([$depId, $hash3, "Purge candidate error (65d)", $dateOver60d, $dateOver60d, $dateOver60d]);
$idPurged = (int)$pdo->lastInsertId();

echo "[SETUP] Seeded 3 test errors: #{$idRecent} (10d), #{$idArchived} (35d), #{$idPurged} (65d)\n";

// 2. Run lifecycle retention manager
$summary = $service->lifecycleErrorLogs(30, 60);

echo "[EXEC] lifecycleErrorLogs returned: " . json_encode($summary) . "\n\n";

// 3. Assertions
// A: Recent error (10 days old) must still be 'resolved' and NOT deleted
$checkRecent = $pdo->prepare("SELECT status FROM central_error_logs WHERE id = ?");
$checkRecent->execute([$idRecent]);
$statusRecent = $checkRecent->fetchColumn();
assert($statusRecent === 'resolved', "10-day resolved error should remain 'resolved'");
echo " [PASS] Test 1: Error resolved 10d ago retained status 'resolved' (actual: {$statusRecent})\n";

// B: 35-day-old error must now be 'archived'
$checkArch = $pdo->prepare("SELECT status FROM central_error_logs WHERE id = ?");
$checkArch->execute([$idArchived]);
$statusArch = $checkArch->fetchColumn();
assert($statusArch === 'archived', "35-day resolved error should have transitioned to 'archived'");
echo " [PASS] Test 2: Error resolved 35d ago successfully transitioned to 'archived' (actual: {$statusArch})\n";

// C: 65-day-old error must be permanently deleted
$checkPurged = $pdo->prepare("SELECT COUNT(*) FROM central_error_logs WHERE id = ?");
$checkPurged->execute([$idPurged]);
$countPurged = (int)$checkPurged->fetchColumn();
assert($countPurged === 0, "65-day error should have been permanently deleted");
echo " [PASS] Test 3: Error resolved 65d ago was permanently purged from database\n";

// 4. Test idempotency (running lifecycle again should not fail)
$summary2 = $service->lifecycleErrorLogs(30, 60);
echo " [PASS] Test 4: Idempotent re-run passed without exception\n";

// Cleanup test fixtures
$pdo->prepare("DELETE FROM central_error_logs WHERE id IN (?, ?)")->execute([$idRecent, $idArchived]);
echo "[CLEANUP] Test fixtures cleaned up successfully.\n";

echo "\n========================================================\n";
echo "All Central Error Log Retention Tests PASSED!\n";
echo "========================================================\n";
