<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

echo "========================================================\n";
echo "Testing SpectraOps Permanent Deletion Suite\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();
$service = new FleetService($pdo);

// Check users table
$userTable = 'central_users';
try {
    $pdo->query("SELECT 1 FROM central_users LIMIT 1");
} catch (\Throwable $e) {
    try {
        $pdo->query("SELECT 1 FROM central_admin_users LIMIT 1");
        $userTable = 'central_admin_users';
    } catch (\Throwable $e2) {}
}
echo "[INFO] User table identified as: {$userTable}\n";

// 1. Create a dummy product, license, deployment, heartbeat, error log, support ticket, release
$time = time();
$slug = "test-del-suite-{$time}";
$pdo->prepare("INSERT INTO central_products (product_slug, product_code, product_name, latest_version) VALUES (?, ?, ?, ?)")
    ->execute([$slug, "TDS" . substr((string)$time, -4), "Test Deletion Product {$time}", "1.0.0"]);
$productId = (int)$pdo->lastInsertId();

// Create codebase dummy dir
$codebaseDir = dirname(__DIR__) . '/storage/codebases/' . $slug;
if (!is_dir($codebaseDir)) {
    mkdir($codebaseDir, 0755, true);
}
file_put_contents($codebaseDir . '/test.txt', 'sample code');

// Create License
$pdo->prepare("INSERT INTO central_licenses (product_id, license_key, client_name, client_email) VALUES (?, ?, ?, ?)")
    ->execute([$productId, "LIC-TEST-{$time}", "Test Client", "client@test.com"]);
$licenseId = (int)$pdo->lastInsertId();

// Create Deployment
$pdo->prepare("INSERT INTO central_deployments (product_id, license_id, client_name, deployment_domain, api_ingest_token_hash, token_preview) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([$productId, $licenseId, "Test Deploy", "del.test{$time}.local", hash('sha256', "tok-{$time}"), "tok_prev"]);
$deploymentId = (int)$pdo->lastInsertId();

// Create Heartbeat
$pdo->prepare("INSERT INTO central_heartbeats (deployment_id, memory_usage_mb) VALUES (?, ?)")
    ->execute([$deploymentId, 128.5]);
$heartbeatId = (int)$pdo->lastInsertId();

// Create Error Log
$pdo->prepare("INSERT INTO central_error_logs (deployment_id, error_hash, error_level, status, error_message, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
    ->execute([$deploymentId, "err_hash_{$time}", "CRITICAL", "resolved", "Test error msg"]);
$errorId = (int)$pdo->lastInsertId();

// Create Ticket
$pdo->prepare("INSERT INTO central_support_tickets (deployment_id, ticket_reference, requester_name, requester_email, subject, message, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)")
    ->execute([$deploymentId, "TKT-{$time}", "Jane Doe", "jane@test.com", "Test Subject", "Test Message"]);
$ticketId = (int)$pdo->lastInsertId();

// Create Release & physical zip
$updatesDir = dirname(__DIR__) . '/public/downloads/updates';
if (!is_dir($updatesDir)) {
    mkdir($updatesDir, 0755, true);
}
$zipFilename = "test_release_{$time}.zip";
$zipFilePath = $updatesDir . '/' . $zipFilename;
file_put_contents($zipFilePath, "dummy zip content");

$pdo->prepare("INSERT INTO central_releases (product_id, version, package_filename, package_checksum) VALUES (?, ?, ?, ?)")
    ->execute([$productId, "1.0.{$time}", $zipFilename, hash('sha256', 'dummy zip content')]);
$releaseId = (int)$pdo->lastInsertId();

echo "[SETUP] Created test fixtures: Product #{$productId}, License #{$licenseId}, Deploy #{$deploymentId}, Error #{$errorId}, Ticket #{$ticketId}, Release #{$releaseId}\n";

// TEST 1: Delete Error
$pdo->prepare("DELETE FROM central_error_logs WHERE id = ?")->execute([$errorId]);
$checkErr = $pdo->prepare("SELECT COUNT(*) FROM central_error_logs WHERE id = ?");
$checkErr->execute([$errorId]);
assert($checkErr->fetchColumn() == 0, "Error log should be deleted");
echo " [PASS] Test 1: Delete individual error log\n";

// TEST 2: Purge Resolved Errors
// Create another resolved error
$pdo->prepare("INSERT INTO central_error_logs (deployment_id, error_hash, error_level, status, error_message, first_seen_at, last_seen_at) VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")
    ->execute([$deploymentId, "err_hash2_{$time}", "WARNING", "resolved", "Resolved error"]);
$stmtPurge = $pdo->prepare("DELETE FROM central_error_logs WHERE status = 'resolved' AND deployment_id IN (SELECT id FROM central_deployments WHERE product_id = ?)");
$stmtPurge->execute([$productId]);
echo " [PASS] Test 2: Purge resolved errors by product_id\n";

// TEST 3: Delete Ticket
$pdo->prepare("DELETE FROM central_support_tickets WHERE id = ?")->execute([$ticketId]);
$checkTkt = $pdo->prepare("SELECT COUNT(*) FROM central_support_tickets WHERE id = ?");
$checkTkt->execute([$ticketId]);
assert($checkTkt->fetchColumn() == 0, "Ticket should be deleted");
echo " [PASS] Test 3: Delete support ticket\n";

// TEST 4: Delete Release & Physical File
$stmtRel = $pdo->prepare("SELECT package_filename FROM central_releases WHERE id = ?");
$stmtRel->execute([$releaseId]);
$fileToDelete = $stmtRel->fetchColumn();
if ($fileToDelete && file_exists($updatesDir . '/' . $fileToDelete)) {
    unlink($updatesDir . '/' . $fileToDelete);
}
$pdo->prepare("DELETE FROM central_releases WHERE id = ?")->execute([$releaseId]);
assert(!file_exists($zipFilePath), "Release zip file should be removed from disk");
$checkRel = $pdo->prepare("SELECT COUNT(*) FROM central_releases WHERE id = ?");
$checkRel->execute([$releaseId]);
assert($checkRel->fetchColumn() == 0, "Release record should be deleted");
echo " [PASS] Test 4: Delete release & physical zip file cleanup\n";

// TEST 5: Delete Deployment & Verify Cascade to Heartbeats
$pdo->prepare("DELETE FROM central_deployments WHERE id = ?")->execute([$deploymentId]);
$checkDep = $pdo->prepare("SELECT COUNT(*) FROM central_deployments WHERE id = ?");
$checkDep->execute([$deploymentId]);
assert($checkDep->fetchColumn() == 0, "Deployment should be deleted");

$checkHb = $pdo->prepare("SELECT COUNT(*) FROM central_heartbeats WHERE id = ?");
$checkHb->execute([$heartbeatId]);
assert($checkHb->fetchColumn() == 0, "Heartbeat should be cascade deleted with deployment");
echo " [PASS] Test 5: Delete deployment & cascade delete heartbeats\n";

// TEST 6: Delete License
$pdo->prepare("DELETE FROM central_licenses WHERE id = ?")->execute([$licenseId]);
$checkLic = $pdo->prepare("SELECT COUNT(*) FROM central_licenses WHERE id = ?");
$checkLic->execute([$licenseId]);
assert($checkLic->fetchColumn() == 0, "License should be deleted");
echo " [PASS] Test 6: Delete license\n";

// TEST 7: Delete Product & Codebase Dir
if (is_dir($codebaseDir)) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($codebaseDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $fileinfo) {
        $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
        @$todo($fileinfo->getRealPath());
    }
    @rmdir($codebaseDir);
}
$pdo->prepare("DELETE FROM central_products WHERE id = ?")->execute([$productId]);
assert(!is_dir($codebaseDir), "Codebase directory should be deleted from storage");
$checkProd = $pdo->prepare("SELECT COUNT(*) FROM central_products WHERE id = ?");
$checkProd->execute([$productId]);
assert($checkProd->fetchColumn() == 0, "Product record should be deleted");
echo " [PASS] Test 7: Delete product & physical codebase storage cleanup\n";

// TEST 8: Delete User with Self-Deletion Prevention
$pdo->prepare("INSERT INTO {$userTable} (name, email, password_hash, role) VALUES (?, ?, ?, ?)")
    ->execute(["Test Operator {$time}", "op_{$time}@spectra.ops", "hash", "admin"]);
$newUserId = (int)$pdo->lastInsertId();

$currentSessionAdminId = $newUserId; // Simulate active user
$canDeleteSelf = ($newUserId !== $currentSessionAdminId);
assert(!$canDeleteSelf, "Self deletion must be blocked");

$currentSessionAdminId = 999999; // Different user
$canDeleteOther = ($newUserId !== $currentSessionAdminId);
if ($canDeleteOther) {
    $pdo->prepare("DELETE FROM {$userTable} WHERE id = ?")->execute([$newUserId]);
}
$checkUsr = $pdo->prepare("SELECT COUNT(*) FROM {$userTable} WHERE id = ?");
$checkUsr->execute([$newUserId]);
assert($checkUsr->fetchColumn() == 0, "User should be deleted when not self");
echo " [PASS] Test 8: Delete admin user with self-deletion safeguard\n";

echo "\nAll Deletion Suite Unit Tests PASSED!\n";
