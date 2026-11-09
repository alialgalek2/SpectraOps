<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/FleetService.php';

$pdo = \FleetHub\Database::getConnection();
$service = new \FleetHub\FleetService($pdo);

echo "========================================================\n";
echo "Testing OTA Sync Issues & Reconciliation\n";
echo "========================================================\n\n";

// Helper assert function
function runAssert(string $desc, bool $condition, string $detail = ''): void {
    if ($condition) {
        echo " [PASS] {$desc}\n";
    } else {
        echo " [FAIL] {$desc}" . ($detail ? " -> {$detail}" : "") . "\n";
    }
}

// 1. Setup mock product and deployment with unique names
$testSlug = 'test-ota-' . bin2hex(random_bytes(4));
$pdo->prepare("
    INSERT INTO central_products (product_slug, product_code, product_name, latest_version)
    VALUES (?, 'TOTA', 'Test OTA Product', '1.0.0')
")->execute([$testSlug]);
$prodId = (int)$pdo->lastInsertId();

$token = 'test_token_' . bin2hex(random_bytes(8));
$tokenHash = hash('sha256', $token);
$tokenPreview = substr($token, 0, 8) . '...';

$pdo->prepare("
    INSERT INTO central_deployments (product_id, client_name, deployment_domain, api_ingest_token_hash, token_preview, current_version, status, is_active)
    VALUES (?, 'TDD OTA Testbed', 'tdd-ota.test.local', ?, ?, '1.0.0', 'healthy', 1)
")->execute([$prodId, $tokenHash, $tokenPreview]);
$testDepId = (int)$pdo->lastInsertId();

// Helper to call ingest
function callIngestTest(array $headers, array $payload): array {
    $ch = curl_init('http://localhost:8080/SPECTRAOPS/public/api/v1/telemetry/ingest.php');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $httpHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $httpHeaders[] = "{$k}: {$v}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$res, true), 'raw' => $res];
}

// Helper to call update check
function callUpdateCheckTest(array $headers, array $payload): array {
    $ch = curl_init('http://localhost:8080/SPECTRAOPS/public/api/v1/updates/check.php');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $httpHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $httpHeaders[] = "{$k}: {$v}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$res, true), 'raw' => $res];
}

$errOlderId = 0;
$errPrimaryId = 0;
$errSecondaryId = 0;
$relId = 0;

try {
    // 2. Create 3 test errors with status = 'patch_pending'
    // Error 1: resolved_by_version = 1.0.3 (older skipped patch)
    // Error 2: resolved_by_version = 1.0.4 (linked via resolves_error_id in release)
    // Error 3: resolved_by_version = 1.0.4 (secondary error for 1.0.4 without direct release link)
    $stmtInsErr = $pdo->prepare("
        INSERT INTO central_error_logs (deployment_id, error_level, error_message, file_path, error_hash, status, resolved_by_version, resolved, first_seen_at, last_seen_at)
        VALUES (?, 'CRITICAL', ?, 'test.php', ?, 'patch_pending', ?, 0, datetime('now'), datetime('now'))
    ");
    $stmtInsErr->execute([$testDepId, 'Skipped older error for v1.0.3', md5('err1_' . $token), '1.0.3']);
    $errOlderId = (int)$pdo->lastInsertId();

    $stmtInsErr->execute([$testDepId, 'Primary error for v1.0.4', md5('err2_' . $token), '1.0.4']);
    $errPrimaryId = (int)$pdo->lastInsertId();

    $stmtInsErr->execute([$testDepId, 'Secondary error for v1.0.4', md5('err3_' . $token), '1.0.4']);
    $errSecondaryId = (int)$pdo->lastInsertId();

    // Create release for 1.0.4 pointing to errPrimaryId
    $stmtRel = $pdo->prepare("
        INSERT INTO central_releases (product_id, version, changelog, resolves_error_id, package_filename, package_checksum, is_active, released_at)
        VALUES (?, '1.0.4', 'Test release 1.0.4', ?, 'test_1.0.4.zip', 'abc123hash', 1, datetime('now'))
    ");
    $stmtRel->execute([$prodId, $errPrimaryId]);
    $relId = (int)$pdo->lastInsertId();

    echo "[*] Seeded errors #{$errOlderId} (v1.0.3), #{$errPrimaryId} (v1.0.4 linked), #{$errSecondaryId} (v1.0.4 secondary)\n";

    // TEST 1: confirm_ota_receipt with v1.0.4 should resolve:
    // - errPrimaryId (direct link)
    // - errSecondaryId (same version)
    // - errOlderId (cumulative older version <= 1.0.4)
    $resReceipt = callIngestTest(['Authorization' => 'Bearer ' . $token], [
        'action' => 'confirm_ota_receipt',
        'applied_version' => '1.0.4',
        'package_checksum' => 'abc123hash'
    ]);

    runAssert("confirm_ota_receipt returned HTTP 200", $resReceipt['code'] === 200, "got " . $resReceipt['code']);

    // Check status of all 3 errors
    $errs = $pdo->query("SELECT id, status, resolved, resolved_by_version FROM central_error_logs WHERE id IN ({$errOlderId}, {$errPrimaryId}, {$errSecondaryId})")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($errs as $e) { $map[$e['id']] = $e; }

    runAssert("Primary error #{$errPrimaryId} resolved", ($map[$errPrimaryId]['status'] ?? '') === 'resolved');
    runAssert("Secondary error #{$errSecondaryId} (same version) resolved", ($map[$errSecondaryId]['status'] ?? '') === 'resolved', "status was: " . ($map[$errSecondaryId]['status'] ?? 'null'));
    runAssert("Older skipped error #{$errOlderId} (v1.0.3 <= v1.0.4) resolved", ($map[$errOlderId]['status'] ?? '') === 'resolved', "status was: " . ($map[$errOlderId]['status'] ?? 'null'));

    // TEST 2: Nested Heartbeat Telemetry version handling & auto-reconcile
    // Reset errOlderId to patch_pending with v1.0.5
    $pdo->prepare("UPDATE central_error_logs SET status = 'patch_pending', resolved = 0, resolved_by_version = '1.0.5' WHERE id = ?")->execute([$errOlderId]);
    $pdo->prepare("UPDATE central_deployments SET current_version = '1.0.4' WHERE id = ?")->execute([$testDepId]);

    // Send heartbeat with nested version '1.0.5'
    $resHb = callIngestTest(['Authorization' => 'Bearer ' . $token], [
        'heartbeat' => [
            'version' => '1.0.5',
            'php_version' => '8.2.0',
            'os_info' => 'Windows',
            'memory_usage_mb' => 30.5
        ]
    ]);
    runAssert("Heartbeat ingest returned HTTP 200", $resHb['code'] === 200);

    // Check if deployment version updated to 1.0.5
    $depRow = $pdo->query("SELECT current_version FROM central_deployments WHERE id = {$testDepId}")->fetch(PDO::FETCH_ASSOC);
    runAssert("Deployment current_version updated from nested heartbeat to 1.0.5", $depRow['current_version'] === '1.0.5', "current_version is: " . $depRow['current_version']);

    // Check if error #errOlderId auto-reconciled from patch_pending to resolved
    $errCheck = $pdo->query("SELECT status, resolved FROM central_error_logs WHERE id = {$errOlderId}")->fetch(PDO::FETCH_ASSOC);
    runAssert("Error #{$errOlderId} auto-reconciled to resolved via heartbeat", $errCheck['status'] === 'resolved', "status is: " . $errCheck['status']);

    // TEST 3: Update check auto-reconcile
    $pdo->prepare("UPDATE central_error_logs SET status = 'patch_pending', resolved = 0, resolved_by_version = '1.0.6' WHERE id = ?")->execute([$errOlderId]);
    $resCheck = callUpdateCheckTest(['Authorization' => 'Bearer ' . $token], [
        'current_version' => '1.0.6'
    ]);
    runAssert("Update check returned HTTP 200", $resCheck['code'] === 200);

    $depRow2 = $pdo->query("SELECT current_version FROM central_deployments WHERE id = {$testDepId}")->fetch(PDO::FETCH_ASSOC);
    runAssert("Deployment current_version updated via update check to 1.0.6", $depRow2['current_version'] === '1.0.6', "current_version is: " . $depRow2['current_version']);

    $errCheck2 = $pdo->query("SELECT status, resolved FROM central_error_logs WHERE id = {$errOlderId}")->fetch(PDO::FETCH_ASSOC);
    runAssert("Error #{$errOlderId} auto-reconciled to resolved via update check", $errCheck2['status'] === 'resolved', "status is: " . $errCheck2['status']);

    // TEST 4: FleetService::reconcilePatchPendingErrors
    $pdo->prepare("UPDATE central_error_logs SET status = 'patch_pending', resolved = 0, resolved_by_version = '1.0.5' WHERE id = ?")->execute([$errOlderId]);
    $pdo->prepare("UPDATE central_deployments SET current_version = '1.0.6' WHERE id = ?")->execute([$testDepId]);

    $reconciled = $service->reconcilePatchPendingErrors($testDepId);
    runAssert("FleetService::reconcilePatchPendingErrors resolved pending error", in_array($errOlderId, $reconciled, true), "reconciled IDs: " . json_encode($reconciled));

} finally {
    // Cleanup
    if (!empty($relId)) $pdo->prepare("DELETE FROM central_releases WHERE id = ?")->execute([$relId]);
    if (!empty($errOlderId)) $pdo->prepare("DELETE FROM central_error_logs WHERE id IN (?, ?, ?)")->execute([$errOlderId, $errPrimaryId, $errSecondaryId]);
    if (!empty($testDepId)) $pdo->prepare("DELETE FROM central_deployments WHERE id = ?")->execute([$testDepId]);
    if (!empty($prodId)) $pdo->prepare("DELETE FROM central_products WHERE id = ?")->execute([$prodId]);
}

echo "\n========================================================\n";
echo "SUCCESS: OTA Sync & Reconciliation Tests Complete!\n";
echo "========================================================\n";
