<?php
declare(strict_types=1);

namespace FleetHub\Tests;

use PDO;
use ZipArchive;
use RuntimeException;
use App\Services\OtaUpdateService;
use FleetHub\Database;

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/client-sdk/OtaUpdateService.php';

/**
 * SpectraOps Central Management Hub & Prism EDU Client Integration
 * Automated End-to-End Self-Healing OTA Lifecycle Test Suite
 * 
 * Simulates, verifies, and asserts every stage of the self-healing OTA loop:
 * - Phase 1: Telemetry Heartbeat & Lease Issuance Verification
 * - Phase 2: Error Log Telemetry Ingestion & Health Transition
 * - Phase 3: AI Diagnostic & Staging Mock Artifact Generation
 * - Phase 4: Release Approval & Artifact Promotion Pipeline
 * - Phase 5: Client Update Check Polling & SemVer Gate
 * - Phase 6: Client Execution & OTA Receipt Acknowledgment
 */
class LifecycleTest {
    private PDO $pdo;
    private string $appRoot;
    private string $clientTestDir;
    private string $leaseSalt = '7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a';
    private string $tokenHash = '7ac017acbc731606aaa850f7d560bb4cb11b3949f7f5b386c68a0baf58e639b0';
    private string $rawToken  = 'SPEC-PRISM-88A6-D474';
    private string $productSlug = 'prism-edu';
    private int $productId = 1;
    private int $deploymentId = 8;
    private ?int $capturedErrorId = null;
    private ?string $stagingZipPath = null;
    private ?string $stagingChecksum = null;
    private ?string $promotedZipPath = null;
    private int $assertionsPassed = 0;
    private bool $inTeardown = false;

    // ANSI Color Constants
    private const GREEN = "\033[32m";
    private const RED   = "\033[31m";
    private const YELLOW = "\033[33m";
    private const CYAN  = "\033[36m";
    private const BOLD  = "\033[1m";
    private const RESET = "\033[0m";

    public function __construct() {
        $this->appRoot = dirname(__DIR__);
        $this->clientTestDir = $this->appRoot . '/storage/testbed_prism_client';
        $this->pdo = Database::getConnection();

        // Register emergency cleanup on script termination
        register_shutdown_function(function() {
            if (!$this->inTeardown) {
                $this->teardown();
            }
        });
    }

    /**
     * Entry point to execute all consecutive verification phases.
     */
    public function run(): void {
        $this->printHeader();

        try {
            $this->setupFixtures();

            $this->runPhase1_HeartbeatAndLease();
            $this->runPhase2_ErrorLogIngestion();
            $this->runPhase3_StagingArtifactGeneration();
            $this->runPhase4_ReleaseApprovalAndPromotion();
            $this->runPhase5_ClientUpdateCheck();
            $this->runPhase6_ClientExecutionAndOtaReceipt();

            $this->printSummary(true);
            $this->teardown();
            exit(0);

        } catch (\Throwable $e) {
            echo "\n" . self::RED . self::BOLD . "[-] FATAL EXCEPTION: " . $e->getMessage() . self::RESET . "\n";
            echo $e->getTraceAsString() . "\n";
            $this->printSummary(false);
            $this->teardown();
            exit(1);
        }
    }

    /**
     * Halts immediately on assertion failure with formatted ANSI output.
     */
    private function assert(bool $condition, string $description, ?string $failureDetails = null): void {
        if ($condition) {
            $this->assertionsPassed++;
            echo "  " . self::GREEN . self::BOLD . "[PASS]" . self::RESET . " " . $description . "\n";
        } else {
            echo "  " . self::RED . self::BOLD . "[FAIL]" . self::RESET . " " . $description . "\n";
            if ($failureDetails !== null) {
                echo "         " . self::YELLOW . "Details: " . $failureDetails . self::RESET . "\n";
            }
            echo "\n" . self::RED . self::BOLD . "[-] EXECUTION HALTED DUE TO ASSERTION FAILURE." . self::RESET . "\n";
            $this->teardown();
            exit(1);
        }
    }

    /**
     * Resets target fixtures in database and on filesystem.
     */
    private function setupFixtures(): void {
        echo self::CYAN . "[SETUP] Initializing Target Fixtures (Product #1, Deployment #8, License {$this->rawToken})..." . self::RESET . "\n";

        // 1. Ensure Product #1 is Prism EDU with initial version 1.0.0
        $stmtProd = $this->pdo->prepare("SELECT id FROM central_products WHERE id = ?");
        $stmtProd->execute([$this->productId]);
        if (!$stmtProd->fetch()) {
            $this->pdo->prepare("
                INSERT INTO central_products (id, product_name, product_slug, product_code, latest_version, created_at, updated_at)
                VALUES (?, 'Prism EDU', 'prism-edu', 'PRISM', '1.0.0', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([$this->productId]);
        } else {
            $this->pdo->prepare("
                UPDATE central_products 
                SET product_slug = 'prism-edu', product_code = 'PRISM', latest_version = '1.0.0'
                WHERE id = ?
            ")->execute([$this->productId]);
        }

        // 2. Ensure License SPEC-PRISM-88A6-D474 is active with known lease_salt
        $stmtLic = $this->pdo->prepare("SELECT id FROM central_licenses WHERE license_key = ?");
        $stmtLic->execute([$this->rawToken]);
        $licRow = $stmtLic->fetch(PDO::FETCH_ASSOC);
        $licId = $licRow ? (int)$licRow['id'] : 8;

        if (!$licRow) {
            $this->pdo->prepare("
                INSERT INTO central_licenses (id, product_id, license_key, client_name, client_email, status, lease_salt, expires_at, created_at, updated_at)
                VALUES (?, ?, ?, 'Prism EDU Local Testbed', 'admin@prism-edu.local', 'active', ?, '2099-12-31 23:59:59', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ")->execute([$licId, $this->productId, $this->rawToken, $this->leaseSalt]);
        } else {
            $this->pdo->prepare("
                UPDATE central_licenses 
                SET status = 'active', lease_salt = ?, product_id = ?
                WHERE id = ?
            ")->execute([$this->leaseSalt, $this->productId, $licId]);
        }

        // 3. Ensure Deployment #8 is linked, initial version = 1.0.0, status = healthy
        $stmtDep = $this->pdo->prepare("SELECT id FROM central_deployments WHERE id = ?");
        $stmtDep->execute([$this->deploymentId]);
        if (!$stmtDep->fetch()) {
            $this->pdo->prepare("
                INSERT INTO central_deployments (
                    id, product_id, license_id, client_name, deployment_domain,
                    api_ingest_token_hash, current_version, status,
                    db_latency_ms, disk_free_gb, active_users_24h, open_error_count,
                    last_heartbeat_at, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, 'Prism EDU Local Testbed', 'prism-edu.local',
                    ?, '1.0.0', 'healthy',
                    12, 120.5, 350, 0,
                    CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ")->execute([$this->deploymentId, $this->productId, $licId, $this->tokenHash]);
        } else {
            $this->pdo->prepare("
                UPDATE central_deployments 
                SET product_id = ?, license_id = ?, deployment_domain = 'prism-edu.local',
                    api_ingest_token_hash = ?, current_version = '1.0.0', status = 'healthy',
                    db_latency_ms = 12, disk_free_gb = 120.5, active_users_24h = 350, open_error_count = 0,
                    last_heartbeat_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ")->execute([$this->productId, $licId, $this->tokenHash, $this->deploymentId]);
        }

        // 4. Clean up any previous test releases and resolve any open errors for deployment 8
        $this->pdo->prepare("DELETE FROM central_releases WHERE product_id = ? AND version = '1.0.1'")->execute([$this->productId]);
        $this->pdo->prepare("DELETE FROM central_error_logs WHERE deployment_id = ? AND (error_message LIKE '%calcGPA%' OR file_path LIKE '%GradebookController%')")->execute([$this->deploymentId]);
        $this->pdo->prepare("UPDATE central_error_logs SET status = 'resolved', resolved = 1 WHERE deployment_id = ? AND status = 'open'")->execute([$this->deploymentId]);

        // 5. Clean up filesystem paths
        $this->stagingZipPath = $this->appRoot . "/storage/hotfixes/{$this->productSlug}/staging/patch_test_1.0.1.zip";
        $this->promotedZipPath = $this->appRoot . "/public/downloads/updates/prism-edu-v1.0.1.zip";

        if (file_exists($this->stagingZipPath)) {
            @unlink($this->stagingZipPath);
        }
        if (file_exists($this->promotedZipPath)) {
            @unlink($this->promotedZipPath);
        }

        // 6. Setup Client Testbed directory
        $this->initClientTestbed();

        echo self::GREEN . "  [✓] Fixtures successfully loaded and initialized.\n" . self::RESET;
    }

    /**
     * Initializes mock Prism EDU client deployment directory on disk.
     */
    private function initClientTestbed(): void {
        if (!is_dir($this->clientTestDir)) {
            @mkdir($this->clientTestDir, 0755, true);
        }

        $appControllers = $this->clientTestDir . '/app/Controllers';
        if (!is_dir($appControllers)) {
            @mkdir($appControllers, 0755, true);
        }

        $storageBackups = $this->clientTestDir . '/storage/ota_backups';
        if (!is_dir($storageBackups)) {
            @mkdir($storageBackups, 0755, true);
        }

        // Write buggy client file
        $buggyContent = "<?php\n// Prism EDU Gradebook Controller v1.0.0\nclass GradebookController {\n    public function index() {\n        return Gradebook::calcGPA(); // Unhandled bug\n    }\n}\n";
        file_put_contents($appControllers . '/GradebookController.php', $buggyContent);

        // Write initial .env file
        file_put_contents($this->clientTestDir . '/.env', "APP_VERSION=\"1.0.0\"\nSPECTRAOPS_LICENSE_KEY=\"{$this->rawToken}\"\n");
    }

    /**
     * PHASE 1: Telemetry Heartbeat & Lease Issuance Verification
     */
    private function runPhase1_HeartbeatAndLease(): void {
        echo "\n" . self::BOLD . "[PHASE 1] Telemetry Heartbeat & Lease Issuance Verification" . self::RESET . "\n";

        $payload = [
            'db_latency_ms'    => 12,
            'disk_free_gb'     => 120.5,
            'active_users_24h' => 350
        ];
        $headers = [
            'Authorization: Bearer ' . $this->rawToken
        ];

        $res = $this->invokeEndpoint('public/api/v1/telemetry/ingest.php', 'POST', $payload, $headers);

        $this->assert($res['status_code'] === 200, "Response HTTP status is 200 (Got: {$res['status_code']})");
        $this->assert(!empty($res['json']['success']) && $res['json']['success'] === true, "Response payload contains success = true");
        
        $leaseToken = (string)($res['json']['lease_token'] ?? '');
        $leaseExpiry = (int)($res['json']['lease_expires'] ?? ($res['json']['lease_valid_until'] ?? 0));
        $this->assert(!empty($leaseToken) && strlen($leaseToken) >= 32, "lease_token is present and non-empty");

        // Verify HMAC-SHA256 signature using known lease_salt
        $expectedSignature = hash_hmac('sha256', "{$this->rawToken}|prism-edu.local|{$leaseExpiry}", $this->leaseSalt);
        $sigValid = hash_equals($expectedSignature, $leaseToken);
        if (!$sigValid && str_contains($leaseToken, '.')) {
            [$b64, $sig] = explode('.', $leaseToken, 2);
            $expectedStructured = hash_hmac('sha256', $b64, $this->leaseSalt);
            $sigValid = hash_equals($expectedStructured, $sig);
        }
        $this->assert($sigValid, "lease_token cryptographically verifies against HMAC-SHA256 with license lease_salt", "Expected: {$expectedSignature}, Got: {$leaseToken}");

        // Assert central_deployments.status evaluates to 'healthy'
        $stmt = $this->pdo->prepare("SELECT status, db_latency_ms, disk_free_gb, active_users_24h FROM central_deployments WHERE id = ?");
        $stmt->execute([$this->deploymentId]);
        $dep = $stmt->fetch(PDO::FETCH_ASSOC);

        $actualStatus = (string)($dep['status'] ?? '');
        $this->assert($actualStatus === 'healthy', "central_deployments.status evaluates to 'healthy' in database", "Actual: '{$actualStatus}'");
        $this->assert((int)$dep['db_latency_ms'] === 12, "central_deployments.db_latency_ms synchronized to 12ms");
        $this->assert((float)$dep['disk_free_gb'] >= 120.0, "central_deployments.disk_free_gb synchronized to 120.5GB");
    }

    /**
     * PHASE 2: Error Log Telemetry Ingestion
     */
    private function runPhase2_ErrorLogIngestion(): void {
        echo "\n" . self::BOLD . "[PHASE 2] Error Log Telemetry Ingestion & Health Transition" . self::RESET . "\n";

        $payload = [
            'action'        => 'report_error',
            'error_level'   => 'FATAL',
            'file_path'     => 'app/Controllers/GradebookController.php',
            'line_number'   => 42,
            'error_message' => 'Call to undefined method Gradebook::calcGPA()'
        ];
        $headers = [
            'Authorization: Bearer ' . $this->rawToken
        ];

        $res = $this->invokeEndpoint('public/api/v1/telemetry/ingest.php', 'POST', $payload, $headers);

        $this->assert($res['status_code'] === 200, "Error ingestion returns HTTP 200");
        $this->assert(!empty($res['json']['success']), "Error ingestion returns success = true");

        // Assert error row exists with status = 'open'
        $stmtErr = $this->pdo->prepare("
            SELECT id, error_level, file_path, line_number, error_message, status 
            FROM central_error_logs 
            WHERE deployment_id = ? AND file_path = 'app/Controllers/GradebookController.php' AND line_number = 42
            ORDER BY id DESC LIMIT 1
        ");
        $stmtErr->execute([$this->deploymentId]);
        $errRow = $stmtErr->fetch(PDO::FETCH_ASSOC);

        $this->assert(!empty($errRow), "New row inserted into central_error_logs for GradebookController.php:42");
        $this->assert(($errRow['status'] ?? '') === 'open', "central_error_logs.status is 'open'");

        $this->capturedErrorId = (int)$errRow['id'];
        echo "         " . self::CYAN . "Captured Error ID: #{$this->capturedErrorId}" . self::RESET . "\n";

        // Re-fetch central_deployments and confirm open_error_count incremented and status transitioned to warning/critical
        $stmtDep = $this->pdo->prepare("SELECT status, open_error_count FROM central_deployments WHERE id = ?");
        $stmtDep->execute([$this->deploymentId]);
        $dep = $stmtDep->fetch(PDO::FETCH_ASSOC);

        $openErrCount = (int)($dep['open_error_count'] ?? 0);
        $depStatus = (string)($dep['status'] ?? '');

        $this->assert($openErrCount >= 1, "central_deployments.open_error_count incremented (Count: {$openErrCount})");
        $this->assert(in_array($depStatus, ['warning', 'critical'], true), "central_deployments.status transitioned to '{$depStatus}'");
    }

    /**
     * PHASE 3: AI Diagnostic & Staging Mock Artifact Generation
     */
    private function runPhase3_StagingArtifactGeneration(): void {
        echo "\n" . self::BOLD . "[PHASE 3] AI Diagnostic & Staging Mock Artifact Generation" . self::RESET . "\n";

        $stagingDir = $this->appRoot . "/storage/hotfixes/{$this->productSlug}/staging";
        if (!is_dir($stagingDir)) {
            @mkdir($stagingDir, 0755, true);
        }

        $this->stagingZipPath = $stagingDir . '/patch_test_1.0.1.zip';
        if (file_exists($this->stagingZipPath)) {
            @unlink($this->stagingZipPath);
        }

        // Construct valid ZIP file containing patched GradebookController.php
        $zip = new ZipArchive();
        $opened = $zip->open($this->stagingZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $this->assert($opened === true, "Initialized staging archive at {$this->stagingZipPath}");

        $patchedCode = "<?php\n// Prism EDU Gradebook Controller v1.0.1 (Self-Healed by SpectraOps AI)\nclass GradebookController {\n    public function index() {\n        return 4.0; // Hotfixed GPA calculation\n    }\n}\n";
        $zip->addFromString('app/Controllers/GradebookController.php', $patchedCode);
        $zip->close();

        $this->assert(file_exists($this->stagingZipPath), "Staging patch file exists on disk");
        $this->assert(is_readable($this->stagingZipPath), "Staging patch file is readable");

        $this->stagingChecksum = hash_file('sha256', $this->stagingZipPath);
        $this->assert(!empty($this->stagingChecksum) && strlen($this->stagingChecksum) === 64, "Computed SHA-256 staging checksum: {$this->stagingChecksum}");
    }

    /**
     * PHASE 4: Release Approval & Artifact Promotion
     */
    private function runPhase4_ReleaseApprovalAndPromotion(): void {
        echo "\n" . self::BOLD . "[PHASE 4] Release Approval & Artifact Promotion Pipeline" . self::RESET . "\n";

        $csrfToken = bin2hex(random_bytes(32));
        $session = [
            'fleet_user_id'      => 1,
            'fleet_admin_logged' => true,
            'user_id'            => 1,
            'user_name'          => 'Test Admin',
            'user_role'          => 'admin',
            'csrf_token'         => $csrfToken
        ];
        $headers = [
            'X-CSRF-TOKEN' => $csrfToken
        ];
        $payload = [
            'product_id'        => $this->productId,
            'version'           => '1.0.1',
            'staging_filename'  => 'patch_test_1.0.1.zip',
            'resolves_error_id' => $this->capturedErrorId,
            'release_type'      => 'patch',
            'is_silent_patch'   => 1,
            'csrf_token'        => $csrfToken
        ];

        $res = $this->invokeEndpoint('public/api/v1/releases/approve.php', 'POST', $payload, $headers, $session);

        $this->assert($res['status_code'] === 201, "Release approval returns HTTP 201 Created (Got: {$res['status_code']})", $res['raw']);
        $this->assert(!empty($res['json']['success']) && $res['json']['success'] === true, "Approve endpoint returns success = true");

        // Assert artifact moved to /public/downloads/updates/prism-edu-v1.0.1.zip
        $promotedFile = $this->appRoot . '/public/downloads/updates/prism-edu-v1.0.1.zip';
        $this->assert(file_exists($promotedFile), "Archive moved to final hosted path: /public/downloads/updates/prism-edu-v1.0.1.zip");

        // Assert staging file removed
        $this->assert(!file_exists($this->stagingZipPath), "Staging file was atomically removed from /staging/");

        // Assert central_releases has a new active record with matching SHA-256 checksum
        $stmtRel = $this->pdo->prepare("
            SELECT id, version, package_filename, package_checksum, is_silent_patch, resolves_error_id, is_active
            FROM central_releases 
            WHERE product_id = ? AND version = '1.0.1' AND is_active = 1
        ");
        $stmtRel->execute([$this->productId]);
        $relRow = $stmtRel->fetch(PDO::FETCH_ASSOC);

        $this->assert(!empty($relRow), "central_releases contains active record for v1.0.1");
        $this->assert(hash_equals(strtolower($this->stagingChecksum), strtolower((string)$relRow['package_checksum'])), "central_releases.package_checksum matches staging SHA-256");
        $this->assert((int)$relRow['is_silent_patch'] === 1, "central_releases.is_silent_patch recorded as 1 (silent patch)");
        $this->assert((int)$relRow['resolves_error_id'] === $this->capturedErrorId, "central_releases.resolves_error_id correctly linked to Error #{$this->capturedErrorId}");

        // Assert central_products.latest_version updated to 1.0.1
        $stmtProd = $this->pdo->prepare("SELECT latest_version FROM central_products WHERE id = ?");
        $stmtProd->execute([$this->productId]);
        $prodLatestVer = (string)$stmtProd->fetchColumn();
        $this->assert($prodLatestVer === '1.0.1', "central_products.latest_version updated to '1.0.1' (Got: {$prodLatestVer})");

        // Assert central_error_logs.status changes to 'patch_pending' with resolved_by_version = '1.0.1'
        $stmtErr = $this->pdo->prepare("SELECT status, resolved_by_version FROM central_error_logs WHERE id = ?");
        $stmtErr->execute([$this->capturedErrorId]);
        $errStatusRow = $stmtErr->fetch(PDO::FETCH_ASSOC);

        $this->assert(($errStatusRow['status'] ?? '') === 'patch_pending', "central_error_logs.status for #{$this->capturedErrorId} transitioned to 'patch_pending'");
        $this->assert(($errStatusRow['resolved_by_version'] ?? '') === '1.0.1', "central_error_logs.resolved_by_version set to '1.0.1'");
    }

    /**
     * PHASE 5: Client Update Check Polling
     */
    private function runPhase5_ClientUpdateCheck(): void {
        echo "\n" . self::BOLD . "[PHASE 5] Client Update Check Polling & SemVer Gate" . self::RESET . "\n";

        $queryParams = [
            'product_slug'    => 'prism-edu',
            'current_version' => '1.0.0'
        ];
        $headers = [
            'Authorization: Bearer ' . $this->rawToken
        ];

        $res = $this->invokeEndpoint('public/api/v1/updates/check.php', 'GET', $queryParams, $headers);

        $this->assert($res['status_code'] === 200, "Response HTTP status is 200");
        $this->assert(!empty($res['json']['update_available']) && $res['json']['update_available'] === true, "update_available is true");

        $release = $res['json']['release'] ?? $res['json'];
        $reportedVersion = (string)($release['version'] ?? ($release['latest_version'] ?? ''));
        $this->assert($reportedVersion === '1.0.1', "release.version equals '1.0.1' (Got: {$reportedVersion})");

        $isSilent = !empty($release['is_silent_patch']);
        $this->assert($isSilent, "release.is_silent_patch is true (automated silent update)");

        $downloadUrl = (string)($release['download_url'] ?? '');
        $this->assert(str_contains($downloadUrl, 'prism-edu-v1.0.1.zip'), "release.download_url points to hosted .zip file ({$downloadUrl})");

        $pkgChecksum = (string)($release['package_checksum'] ?? ($release['checksum'] ?? ''));
        $this->assert(hash_equals(strtolower($this->stagingChecksum), strtolower($pkgChecksum)), "release.package_checksum matches original archive hash");
    }

    /**
     * PHASE 6: Client Execution & OTA Receipt Acknowledgment
     */
    private function runPhase6_ClientExecutionAndOtaReceipt(): void {
        echo "\n" . self::BOLD . "[PHASE 6] Client Execution & OTA Receipt Acknowledgment" . self::RESET . "\n";

        // 1. Instantiate OtaUpdateService pointing to client testbed
        $otaService = new OtaUpdateService(
            $this->clientTestDir,
            'http://127.0.0.1/SPECTRAOPS/public',
            $this->tokenHash,
            $this->rawToken,
            '1.0.0',
            'prism-edu'
        );

        // 2. Trigger OTA execution cycle via handle()
        $result = $otaService->handle('1.0.0', autoApplyOnly: true);

        $this->assert(!empty($result['applied']) && $result['applied'] === true, "OtaUpdateService->handle('1.0.0', autoApplyOnly: true) applied successfully");
        $this->assert(($result['installed_version'] ?? '') === '1.0.1', "Installed version reports '1.0.1'");
        $this->assert(!empty($result['backup_path']) && is_dir($result['backup_path']), "Backup snapshot directory was created under storage/ota_backups/");
        $this->assert(!empty($result['receipt_confirmed']), "Closed-loop confirm_ota_receipt acknowledged by SpectraOps Hub");

        // Assert patched file was extracted over client controller
        $targetFile = $this->clientTestDir . '/app/Controllers/GradebookController.php';
        $this->assert(file_exists($targetFile), "Patched file extracted at {$targetFile}");
        $fileContent = (string)file_get_contents($targetFile);
        $this->assert(str_contains($fileContent, 'Self-Healed by SpectraOps AI'), "Patched code successfully replaced buggy code");

        // 3. Database Assertions: central_deployments.current_version = 1.0.1
        $stmtDep = $this->pdo->prepare("SELECT current_version, status, open_error_count FROM central_deployments WHERE id = ?");
        $stmtDep->execute([$this->deploymentId]);
        $dep = $stmtDep->fetch(PDO::FETCH_ASSOC);

        $this->assert(($dep['current_version'] ?? '') === '1.0.1', "central_deployments.current_version updated to '1.0.1' (Got: {$dep['current_version']})");
        $this->assert(($dep['status'] ?? '') === 'healthy', "central_deployments.status restored to 'healthy'");

        // 4. Database Assertions: central_error_logs.status transitions from 'patch_pending' to 'resolved'
        $stmtErr = $this->pdo->prepare("SELECT status, resolved, resolved_by_version FROM central_error_logs WHERE id = ?");
        $stmtErr->execute([$this->capturedErrorId]);
        $errRow = $stmtErr->fetch(PDO::FETCH_ASSOC);

        $this->assert(($errRow['status'] ?? '') === 'resolved', "central_error_logs.status for Error #{$this->capturedErrorId} transitioned to 'resolved'");
        $this->assert((int)($errRow['resolved'] ?? 0) === 1, "central_error_logs.resolved flag set to 1");
        $this->assert(($errRow['resolved_by_version'] ?? '') === '1.0.1', "central_error_logs.resolved_by_version verified as '1.0.1'");

        // 5. Subsequent check.php with current_version=1.0.1 returns update_available: false
        $checkParams = [
            'product_slug'    => 'prism-edu',
            'current_version' => '1.0.1'
        ];
        $headers = [
            'Authorization: Bearer ' . $this->rawToken
        ];
        $checkRes = $this->invokeEndpoint('public/api/v1/updates/check.php', 'GET', $checkParams, $headers);

        $this->assert($checkRes['status_code'] === 200, "Subsequent check.php call returns HTTP 200");
        $this->assert(isset($checkRes['json']['update_available']) && $checkRes['json']['update_available'] === false, "Subsequent check.php with v1.0.1 returns update_available = false (System Up to Date)");
    }

    /**
     * Executes isolated PHP script simulating authentic HTTP request and response cycle.
     */
    private function invokeEndpoint(string $relativePath, string $method, array $payload, array $headers = [], array $session = []): array {
        $scriptPath = $this->appRoot . '/' . ltrim($relativePath, '/');
        if (!file_exists($scriptPath)) {
            throw new RuntimeException("Target endpoint script does not exist: {$scriptPath}");
        }

        $sessionExport = var_export($session, true);
        $serverVars = [
            'REQUEST_METHOD' => strtoupper($method),
            'REMOTE_ADDR'    => '127.0.0.1',
            'REQUEST_URI'    => '/' . ltrim($relativePath, '/')
        ];

        foreach ($headers as $headerLine) {
            if (str_contains($headerLine, ':')) {
                [$k, $v] = explode(':', $headerLine, 2);
                $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', trim($k)));
                $serverVars[$headerKey] = trim($v);
            }
        }

        $serverExport = var_export($serverVars, true);
        $payloadJson = json_encode($payload);
        $getExport = ($method === 'GET') ? var_export($payload, true) : '[]';
        $postExport = ($method === 'POST') ? var_export($payload, true) : '[]';

        $code = <<<PHP
<?php
if (!empty({$sessionExport})) {
    @session_start();
    \$_SESSION = {$sessionExport};
}
\$_SERVER = array_merge(\$_SERVER, {$serverExport});
\$_GET = {$getExport};
\$_POST = {$postExport};

register_shutdown_function(function() {
    \$code = http_response_code();
    echo "\n__HTTP_STATUS_CODE__:" . (\$code ?: 200) . "\n";
});

require '{$scriptPath}';
PHP;

        $tempScript = sys_get_temp_dir() . '/lifecycle_test_' . uniqid() . '.php';
        file_put_contents($tempScript, $code);

        $cmd = "php -d display_errors=0 {$tempScript}";
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $this->appRoot);
        if (!is_resource($process)) {
            @unlink($tempScript);
            return ['status_code' => 500, 'json' => null, 'raw' => '', 'stderr' => 'Failed to fork process'];
        }

        fwrite($pipes[0], $payloadJson);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($tempScript);

        $statusCode = 200;
        if (preg_match('/__HTTP_STATUS_CODE__:(\d+)/', $stdout, $m)) {
            $statusCode = (int)$m[1];
            $stdout = preg_replace('/\n?__HTTP_STATUS_CODE__:\d+\n?/', '', $stdout);
        }

        $json = json_decode(trim($stdout), true);

        return [
            'status_code' => $statusCode,
            'json'        => $json,
            'raw'         => $stdout,
            'stderr'      => $stderr
        ];
    }

    /**
     * Clean-Up & Teardown to restore system state.
     */
    public function teardown(): void {
        $this->inTeardown = true;
        echo "\n" . self::CYAN . "[TEARDOWN] Cleaning up test artifacts and restoring fixtures..." . self::RESET . "\n";

        try {
            // 1. Delete generated test release record
            $this->pdo->prepare("DELETE FROM central_releases WHERE product_id = ? AND version = '1.0.1'")->execute([$this->productId]);

            // 2. Delete test error logs
            if ($this->capturedErrorId) {
                $this->pdo->prepare("DELETE FROM central_error_logs WHERE id = ?")->execute([$this->capturedErrorId]);
            }
            $this->pdo->prepare("DELETE FROM central_error_logs WHERE deployment_id = ? AND (error_message LIKE '%calcGPA%' OR file_path LIKE '%GradebookController%')")->execute([$this->deploymentId]);

            // 3. Reset product version to baseline
            $this->pdo->prepare("UPDATE central_products SET latest_version = '1.0.0' WHERE id = ?")->execute([$this->productId]);

            // 4. Reset deployment to healthy 1.0.0
            $this->pdo->prepare("
                UPDATE central_deployments 
                SET current_version = '1.0.0', status = 'healthy', open_error_count = 0,
                    db_latency_ms = 12, disk_free_gb = 120.5, active_users_24h = 350
                WHERE id = ?
            ")->execute([$this->deploymentId]);

            // 5. Remove temporary test files
            if ($this->stagingZipPath && file_exists($this->stagingZipPath)) {
                @unlink($this->stagingZipPath);
            }
            if ($this->promotedZipPath && file_exists($this->promotedZipPath)) {
                @unlink($this->promotedZipPath);
            }

            // 6. Clean up mock client testbed
            if (is_dir($this->clientTestDir)) {
                $this->deleteDirectoryRecursive($this->clientTestDir);
            }

            echo self::GREEN . "  [✓] Teardown complete. All test entities safely purged." . self::RESET . "\n";

        } catch (\Throwable $e) {
            echo self::YELLOW . "  [!] Teardown warning: " . $e->getMessage() . self::RESET . "\n";
        }
    }

    private function deleteDirectoryRecursive(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectoryRecursive($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function printHeader(): void {
        echo self::BOLD . self::CYAN . "\n";
        echo "====================================================================\n";
        echo "SPECTRAOPS & PRISM EDU SELF-HEALING OTA LIFECYCLE TEST SUITE\n";
        echo "====================================================================\n" . self::RESET;
    }

    private function printSummary(bool $success): void {
        echo "\n" . self::BOLD . "====================================================================\n";
        echo "LIFECYCLE VERIFICATION SUMMARY\n";
        echo "====================================================================\n" . self::RESET;
        echo "Total Assertions Passed: " . self::GREEN . self::BOLD . $this->assertionsPassed . self::RESET . "\n";

        if ($success) {
            echo "Result: " . self::GREEN . self::BOLD . "[SUCCESS] All 6 self-healing lifecycle phases verified flawlessly!" . self::RESET . "\n";
        } else {
            echo "Result: " . self::RED . self::BOLD . "[FAILURE] Lifecycle test encountered failures." . self::RESET . "\n";
        }
        echo "====================================================================\n\n";
    }
}

// Allow direct CLI execution if invoked directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    $suite = new LifecycleTest();
    $suite->run();
}
