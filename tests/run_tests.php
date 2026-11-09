<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for Centralized Multi-Product Telemetry & Fleet Management Hub
 * Run with: php -d extension=pdo_sqlite tests/run_tests.php
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/Services/UpdateSigner.php';
require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
require_once dirname(__DIR__) . '/src/Services/VendPulseLicenseService.php';
require_once dirname(__DIR__) . '/src/Services/InstallerDistributionService.php';
require_once dirname(__DIR__) . '/src/Services/OrderProvisioningService.php';
require_once dirname(__DIR__) . '/client-sdk/TelemetryReporter.php';
require_once dirname(__DIR__) . '/client-sdk/UpdateApplier.php';

use FleetHub\Database;
use FleetHub\FleetService;
use FleetHub\RateLimiter;
use FleetHub\Services\UpdateSigner;
use App\Services\LicenseManager;
use App\Services\VendPulseLicenseService;
use App\Services\InstallerDistributionService;
use App\Services\OrderProvisioningService;
use App\Services\TelemetryReporter;
use App\Services\UpdateApplier;

class FleetHubTestRunner {
    private int $passed = 0;
    private int $failed = 0;
    private FleetService $service;

    public function __construct() {
        // Force SQLite test database in temp location for clean isolated tests
        $testDb = sys_get_temp_dir() . '/fleet_test_' . time() . '.sqlite';
        if (file_exists($testDb)) {
            @unlink($testDb);
        }
        putenv("DB_CONNECTION=sqlite");
        putenv("DB_DATABASE={$testDb}");
        Database::reset();

        $this->service = new FleetService();
    }

    public function run(): void {
        echo "========================================================\n";
        echo "Running Fleet Hub Automated Verification Test Suite\n";
        echo "========================================================\n\n";

        $this->testDatabaseInitialization();
        $this->testDeploymentRegistrationAndTokenHashing();
        $this->testTokenAuthentication();
        $this->testTelemetryHeartbeatIngestion();
        $this->testErrorFingerprintingAndDeduplication();
        $this->testSensitiveDataRedaction();
        $this->testFleetHealthMonitorSweep();
        $this->testDeploymentDeletionAndCascadeCleanup();
        $this->testDomainSsrfValidation();
        $this->testErrorFingerprintNumericNormalization();
        $this->testUpdateCryptographicSigningAndVerification();
        $this->testDenormalizedTelemetryColumns();
        $this->testRateLimiterProtection();
        $this->testLicenseGeneration();
        $this->testLicenseActivationWorkflow();
        $this->testUserManagement();
        $this->testVendPulseOfflineCryptographicLicenses();
        $this->testOrderProvisioningAndDistribution();

        echo "\n========================================================\n";
        echo "Test Results: {$this->passed} Passed, {$this->failed} Failed\n";
        echo "========================================================\n";

        if ($this->failed > 0) {
            exit(1);
        }
        exit(0);
    }

    private function assert(string $testName, bool $condition, string $failureDetails = ''): void {
        if ($condition) {
            echo " [PASS] {$testName}\n";
            $this->passed++;
        } else {
            echo " [FAIL] {$testName} - {$failureDetails}\n";
            $this->failed++;
        }
    }

    private function testDatabaseInitialization(): void {
        $overview = $this->service->getFleetOverview();
        $this->assert(
            "Database boots and catalog contains seed products",
            $overview['total_products'] >= 3,
            "Expected >= 3 products, got " . $overview['total_products']
        );
    }

    private function testDeploymentRegistrationAndTokenHashing(): void {
        $registered = $this->service->registerDeployment(
            1, // prism-edu
            "Oakridge International Academy",
            "prism.oakridge.edu",
            "production",
            "2.5.0"
        );

        $this->assert(
            "Deployment registration returns valid ID",
            $registered['id'] > 0,
            "Got invalid ID: " . $registered['id']
        );

        $this->assert(
            "Deployment token begins with flt_ prefix",
            str_starts_with($registered['token'], 'flt_'),
            "Token is: " . $registered['token']
        );

        // Verify SHA-256 hash in DB matches
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT api_ingest_token_hash FROM central_deployments WHERE id = ?");
        $stmt->execute([$registered['id']]);
        $storedHash = $stmt->fetchColumn();

        $expectedHash = hash('sha256', $registered['token']);
        $this->assert(
            "Database stores SHA-256 hash of token (not plaintext)",
            $storedHash === $expectedHash,
            "Stored: {$storedHash} vs Expected: {$expectedHash}"
        );
    }

    private function testTokenAuthentication(): void {
        // Register deployment
        $dep = $this->service->registerDeployment(1, "St. Jude High School", "prism.stjude.edu");
        $token = $dep['token'];

        // Valid token lookup
        $auth = $this->service->authenticateToken($token);
        $this->assert(
            "Valid ingest token authenticates deployment successfully",
            $auth !== null && (int)$auth['id'] === $dep['id'],
            "Auth failed for valid token"
        );

        // Invalid / revoked token
        $badAuth = $this->service->authenticateToken("flt_invalid_or_forged_token_0000");
        $this->assert(
            "Invalid or forged token is rejected (returns null)",
            $badAuth === null,
            "Invalid token unexpectedly authenticated"
        );
    }

    private function testTelemetryHeartbeatIngestion(): void {
        $dep = $this->service->registerDeployment(1, "Greenwood College", "prism.greenwood.edu");
        $depId = $dep['id'];

        $payload = [
            'heartbeat' => [
                'version' => '2.5.1',
                'php_version' => '8.2.14',
                'mysql_version' => '8.0.35',
                'os_info' => 'Linux (x86_64)',
                'memory_usage_mb' => 124.5,
                'db_latency_ms' => 12.8,
                'storage_free_gb' => 84.2,
                'active_users_24h' => 450,
            ]
        ];

        $res = $this->service->ingestTelemetry($depId, $payload, '192.168.1.50');
        $this->assert(
            "Telemetry heartbeat is acknowledged and recorded",
            $res['heartbeat_recorded'] === true && $res['new_status'] === 'healthy',
            "Result: " . json_encode($res)
        );

        // Verify deployment row updated
        $deployments = $this->service->getDeployments(null, null, "Greenwood");
        $this->assert(
            "Deployment metadata (PHP version, OS, current version) updated in registry",
            !empty($deployments) && $deployments[0]['php_version'] === '8.2.14' && $deployments[0]['current_version'] === '2.5.1',
            "Registry data not updated properly"
        );
    }

    private function testErrorFingerprintingAndDeduplication(): void {
        $dep = $this->service->registerDeployment(1, "Beacon Prep", "prism.beaconprep.edu");
        $depId = $dep['id'];

        $errorPayload = [
            'errors' => [
                [
                    'level' => 'EXCEPTION',
                    'message' => 'Undefined variable $studentId in GradeController',
                    'file' => '/var/www/app/Controllers/GradeController.php',
                    'line' => 142,
                    'trace' => '#0 /var/www/app/index.php(32): GradeController->index()',
                    'uri' => '/api/grades/export',
                ]
            ]
        ];

        // First ingestion -> Creates error log row (occurrences = 1)
        $res1 = $this->service->ingestTelemetry($depId, $errorPayload);
        $this->assert("First error event logged", $res1['errors_ingested'] === 1);

        // Second ingestion with identical error -> Deduplicates into same row (occurrences = 2)
        $res2 = $this->service->ingestTelemetry($depId, $errorPayload);
        $this->assert("Second duplicate error event logged", $res2['errors_ingested'] === 1);

        $errors = $this->service->getErrorLogs($depId);
        $this->assert(
            "Identical error is fingerprinted and deduplicated (occurrences = 2, total rows = 1)",
            count($errors) === 1 && (int)$errors[0]['occurrences'] === 2,
            "Expected 1 grouped error with 2 occurrences, got " . count($errors) . " rows with " . ($errors[0]['occurrences'] ?? 0) . " occurrences"
        );
    }

    private function testSensitiveDataRedaction(): void {
        // Reflection to test sanitizeText in TelemetryReporter
        $reporter = new TelemetryReporter('http://127.0.0.1:8080', 'flt_dummy_token', '1.0.0', false);
        $ref = new \ReflectionClass($reporter);
        $method = $ref->getMethod('sanitizeText');

        $sensitive = "Error connecting to mysql://dbuser:superSecretP@ssword123@10.0.0.1/prism_prod with Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9";
        $sanitized = $method->invoke($reporter, $sensitive);

        $this->assert(
            "Sensitive database password in DSN is redacted",
            !str_contains($sanitized, 'superSecretP@ssword123') && str_contains($sanitized, '[REDACTED]@'),
            "Password was not redacted: {$sanitized}"
        );

        $this->assert(
            "Bearer token string is redacted",
            !str_contains($sanitized, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9') && str_contains($sanitized, '[REDACTED_TOKEN]'),
            "Bearer token was not redacted: {$sanitized}"
        );
    }

    private function testFleetHealthMonitorSweep(): void {
        $dep = $this->service->registerDeployment(1, "Stale High School", "prism.stale.edu");
        $depId = $dep['id'];

        // Artificially set last heartbeat to 3 hours ago
        $db = Database::getConnection();
        $db->exec("UPDATE central_deployments SET last_heartbeat_at = datetime('now', '-3 hours'), status = 'healthy' WHERE id = {$depId}");

        // Run monitor
        $report = $this->service->runFleetMonitorCheck();

        $this->assert(
            "Fleet monitor detects and marks node inactive for > 2h as unreachable",
            $report['unreachable_marked'] >= 1,
            "Expected >= 1 unreachable node, got: " . json_encode($report)
        );

        $this->assert(
            "Fleet monitor report contains both pruned_heartbeats and heartbeats_pruned aliases",
            isset($report['pruned_heartbeats']) && isset($report['heartbeats_pruned']) && $report['pruned_heartbeats'] === $report['heartbeats_pruned'],
            "Missing report pruning keys: " . json_encode($report)
        );

        $updated = $this->service->getDeployments(null, null, "Stale High School");
        $this->assert(
            "Deployment status is updated to 'unreachable'",
            !empty($updated) && $updated[0]['status'] === 'unreachable',
            "Deployment status: " . ($updated[0]['status'] ?? 'unknown')
        );
    }

    private function testDeploymentDeletionAndCascadeCleanup(): void {
        $dep = $this->service->registerDeployment(1, "Delete Verification Academy", "delete.prism.edu");
        $depId = (int)$dep['id'];

        // Inject telemetry, errors, and tickets linked to this deployment
        $db = Database::getConnection();
        $db->prepare("INSERT INTO central_heartbeats (deployment_id, memory_usage_mb, db_latency_ms, recorded_at) VALUES (?, 128.0, 15.2, datetime('now'))")->execute([$depId]);
        $db->prepare("INSERT INTO central_error_logs (deployment_id, error_hash, error_level, error_message, first_seen_at, last_seen_at) VALUES (?, 'hash_del_test', 'ERROR', 'Delete test error', datetime('now'), datetime('now'))")->execute([$depId]);
        $db->prepare("INSERT INTO central_support_tickets (deployment_id, ticket_reference, requester_name, requester_email, subject, message, priority, status, created_at) VALUES (?, 'TKT-DEL-01', 'Admin User', 'admin@delete.edu', 'Delete test ticket', 'Testing cascade deletion', 'normal', 'open', datetime('now'))")->execute([$depId]);

        // Execute service deletion
        $deleted = $this->service->deleteDeployment($depId);

        $this->assert(
            "Deployment deletion returns true on existing node",
            $deleted === true,
            "Expected true, got false"
        );

        // Verify deployment row is gone
        $cntDep = (int)$db->query("SELECT COUNT(*) FROM central_deployments WHERE id = {$depId}")->fetchColumn();
        $this->assert(
            "Deployment record permanently removed from central_deployments",
            $cntDep === 0,
            "Deployment record still present: count = {$cntDep}"
        );

        // Verify child telemetry & tickets are cleaned up
        $cntHb = (int)$db->query("SELECT COUNT(*) FROM central_heartbeats WHERE deployment_id = {$depId}")->fetchColumn();
        $cntErr = (int)$db->query("SELECT COUNT(*) FROM central_error_logs WHERE deployment_id = {$depId}")->fetchColumn();
        $cntTkt = (int)$db->query("SELECT COUNT(*) FROM central_support_tickets WHERE deployment_id = {$depId}")->fetchColumn();

        $this->assert(
            "Child telemetry heartbeats, error logs, and tickets cascaded and cleaned up",
            $cntHb === 0 && $cntErr === 0 && $cntTkt === 0,
            "Orphaned child records remaining: heartbeats={$cntHb}, errors={$cntErr}, tickets={$cntTkt}"
        );
    }

    private function testDomainSsrfValidation(): void {
        // Test static domain validator
        $this->assert("Rejects localhost", !FleetService::isValidPublicDomain("localhost"));
        $this->assert("Rejects 127.0.0.1", !FleetService::isValidPublicDomain("127.0.0.1"));
        $this->assert("Rejects 169.254.169.254 (cloud metadata)", !FleetService::isValidPublicDomain("169.254.169.254"));
        $this->assert("Rejects private class A (10.0.0.1)", !FleetService::isValidPublicDomain("10.0.0.1"));
        $this->assert("Rejects private class B (172.16.5.10)", !FleetService::isValidPublicDomain("172.16.5.10"));
        $this->assert("Rejects private class C (192.168.1.1)", !FleetService::isValidPublicDomain("192.168.1.1"));
        $this->assert("Accepts valid public FQDN (prism.school.edu)", FleetService::isValidPublicDomain("prism.school.edu"));
        $this->assert("Accepts valid domain with port (portal.company.com:8443)", FleetService::isValidPublicDomain("portal.company.com:8443"));

        // Test registerDeployment rejects invalid domain
        $threw = false;
        try {
            $this->service->registerDeployment(1, "SSRF Target", "169.254.169.254");
        } catch (\InvalidArgumentException $e) {
            $threw = true;
        }
        $this->assert("registerDeployment rejects internal SSRF IP/domain", $threw);
    }

    private function testErrorFingerprintNumericNormalization(): void {
        $dep = $this->service->registerDeployment(1, "Norm Academy", "norm.school.edu");
        $depId = $dep['id'];

        // Message with ID 4829
        $this->service->ingestTelemetry($depId, [
            'errors' => [
                [
                    'level' => 'ERROR',
                    'message' => 'Failed processing student ID #4829 in transaction 88102',
                    'file' => '/app/StudentService.php',
                    'line' => 95,
                    'trace' => '#0 /app/index.php(10): StudentService->save()',
                ]
            ]
        ]);

        // Message with ID 9931
        $this->service->ingestTelemetry($depId, [
            'errors' => [
                [
                    'level' => 'ERROR',
                    'message' => 'Failed processing student ID #9931 in transaction 10044',
                    'file' => '/app/StudentService.php',
                    'line' => 95,
                    'trace' => '#0 /app/index.php(10): StudentService->save()',
                ]
            ]
        ]);

        $errors = $this->service->getErrorLogs($depId);
        $this->assert(
            "Errors with varying dynamic numeric IDs are normalized and deduplicated",
            count($errors) === 1 && (int)$errors[0]['occurrences'] === 2,
            "Expected 1 grouped error with 2 occurrences, got count=" . count($errors) . ", occ=" . ($errors[0]['occurrences'] ?? 0)
        );
    }

    private function testUpdateCryptographicSigningAndVerification(): void {
        $testZip = sys_get_temp_dir() . '/test_package_' . time() . '.zip';
        file_put_contents($testZip, "PK\x03\x04TestPackageContentHere");

        // Sign package
        $signer = new UpdateSigner();
        $applier = new UpdateApplier();

        $sig = $signer->signPackage($testZip);
        $this->assert("UpdateSigner produces valid signature string", !empty($sig));

        // Verify valid package with signer
        $validSigner = $signer->verifyPackage($testZip, $sig);
        $this->assert("UpdateSigner verifies valid package signature", $validSigner === true);

        // Verify with client-side UpdateApplier
        $validClient = $applier->verifyPackage($testZip, $sig);
        $this->assert("UpdateApplier verifies signature against embedded public key", $validClient === true);

        // Tamper with package
        file_put_contents($testZip, "TAMPERED", FILE_APPEND);
        $tamperedSigner = $signer->verifyPackage($testZip, $sig);
        $tamperedClient = $applier->verifyPackage($testZip, $sig);

        $this->assert("Tampered package rejected by UpdateSigner", $tamperedSigner === false);
        $this->assert("Tampered package rejected by UpdateApplier", $tamperedClient === false);

        @unlink($testZip);
    }

    private function testDenormalizedTelemetryColumns(): void {
        $dep = $this->service->registerDeployment(1, "Telemetry Denorm School", "denorm.school.edu");
        $depId = $dep['id'];

        $this->service->ingestTelemetry($depId, [
            'heartbeat' => [
                'version' => '3.0.0',
                'memory_usage_mb' => 256.8,
                'db_latency_ms' => 9.4,
                'storage_free_gb' => 120.5,
                'active_users_24h' => 610,
                'load_avg' => 0.45
            ]
        ]);

        $db = Database::getConnection();
        $stmt = $db->query("SELECT memory_usage_mb, db_latency_ms, storage_free_gb, active_users_24h, load_avg FROM central_deployments WHERE id = {$depId}");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assert(
            "Denormalized telemetry written directly to central_deployments table",
            $row !== false && (float)$row['memory_usage_mb'] === 256.8 && (float)$row['db_latency_ms'] === 9.4 && (int)$row['active_users_24h'] === 610,
            "Row values: " . json_encode($row)
        );

        // Confirm plaintext api_ingest_token column is absent
        $columns = [];
        $res = $db->query("PRAGMA table_info(central_deployments)")->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($res as $col) {
            $columns[] = $col['name'];
        }
        $this->assert("Plaintext api_ingest_token column does not exist", !in_array('api_ingest_token', $columns, true));
    }

    private function testRateLimiterProtection(): void {
        $ip = '10.99.88.' . mt_rand(10, 99);
        $maxAttempts = 3;
        $window = 60;

        $r1 = RateLimiter::isRateLimited($ip, $maxAttempts, $window);
        $r2 = RateLimiter::isRateLimited($ip, $maxAttempts, $window);
        $r3 = RateLimiter::isRateLimited($ip, $maxAttempts, $window);
        $r4 = RateLimiter::isRateLimited($ip, $maxAttempts, $window);

        $this->assert(
            "RateLimiter allows up to max attempts then triggers rate limit",
            $r1 === false && $r2 === false && $r3 === false && $r4 === true
        );
    }

    private function testLicenseGeneration(): void {
        $db = Database::getConnection();
        $mgr = new LicenseManager($db);

        // Test issuing a license for Prism EDU (product_id = 1, prefix = PRISM)
        $issued = $mgr->issueLicense(1, 'Al-Amal Academy', 'admin@alamal.edu', 'subscription', '2027-12-31 23:59:59');

        $this->assert("License issued with valid ID", (int)$issued['license_id'] > 0);
        $this->assert("License product code matches PRISM", $issued['product_code'] === 'PRISM');
        $this->assert("License key matches format SPEC-PRISM-XXXX-XXXX", (bool)preg_match('/^SPEC-PRISM-[0-9A-F]{4}-[0-9A-F]{4}$/', $issued['license_key']));
        $this->assert("License client name preserved", $issued['client_name'] === 'Al-Amal Academy');

        // Verify database persistence
        $lic = $mgr->getLicense($issued['license_key']);
        $this->assert("Database lookup retrieves issued license", $lic !== null && $lic['status'] === 'unactivated' && $lic['license_type'] === 'subscription');

        // Invalid product ID should throw RuntimeException
        $threw = false;
        try {
            $mgr->issueLicense(9999, 'Ghost Client', 'ghost@example.com');
        } catch (\RuntimeException $e) {
            $threw = true;
        }
        $this->assert("Issuing license with nonexistent product throws RuntimeException", $threw);

        // List licenses includes the new license
        $list = $mgr->listLicenses();
        $found = false;
        foreach ($list as $item) {
            if ($item['license_key'] === $issued['license_key']) {
                $found = true;
                break;
            }
        }
        $this->assert("listLicenses() contains the issued license", $found);
    }

    private function testLicenseActivationWorkflow(): void {
        $db = Database::getConnection();
        $mgr = new LicenseManager($db);

        // 1. Issue perpetual license for POS (product_id = 2, product_code = POS)
        $issued = $mgr->issueLicense(2, 'Metro Hypermarket', 'it@metro.com', 'perpetual', null);
        $key = $issued['license_key'];
        $domain = 'pos.metrohyper.com';
        $hwSig = hash('sha256', 'hw-node-001-mac');

        // 2. First Activation Simulation
        $lic = $mgr->getLicense($key);
        $this->assert("Newly issued license has unactivated status", $lic['status'] === 'unactivated');

        // Run activation transaction directly against Database instance
        $db->beginTransaction();
        $tokenHash = hash('sha256', $key);
        $tokenPreview = substr($key, 0, 8) . '...';
        
        $ins = $db->prepare("
            INSERT INTO central_deployments 
            (product_id, license_id, client_name, deployment_domain, hardware_hash, api_ingest_token_hash, token_preview, current_version, status, last_heartbeat_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'healthy', datetime('now'), datetime('now'))
        ");
        $ins->execute([
            $lic['product_id'],
            $lic['id'],
            $lic['client_name'],
            $domain,
            $hwSig,
            $tokenHash,
            $tokenPreview,
            '1.4.2'
        ]);

        $upd = $db->prepare("UPDATE central_licenses SET status = 'active' WHERE id = ?");
        $upd->execute([$lic['id']]);
        $db->commit();

        // Check license is now active
        $activatedLic = $mgr->getLicense($key);
        $this->assert("First activation updates license status to active", $activatedLic['status'] === 'active');

        // Check deployment record created and bound
        $depStmt = $db->prepare("SELECT * FROM central_deployments WHERE license_id = ?");
        $depStmt->execute([$lic['id']]);
        $dep = $depStmt->fetch(\PDO::FETCH_ASSOC);

        $this->assert("Deployment entry created with matching domain", $dep !== false && $dep['deployment_domain'] === $domain);
        $this->assert("Deployment entry has healthy status", $dep['status'] === 'healthy');
        $this->assert("Deployment stores hashed ingest token matching key", $dep['api_ingest_token_hash'] === $tokenHash);

        // 3. Cryptographic lease signature verification
        $rawPayload = "{$key}|{$domain}|{$activatedLic['product_code']}|PERPETUAL";
        $secretKey = getenv('FLEET_CRYPT_SECRET') ?: 'SPECTRAOPS_CRYPT_SECRET_KEY';
        $leaseToken = hash_hmac('sha256', $rawPayload, $secretKey);
        $this->assert("Cryptographic lease token is a 64-char hex SHA-256 HMAC", strlen($leaseToken) === 64 && ctype_xdigit($leaseToken));

        // 4. Re-activation from same domain matches
        $this->assert("Re-activation from authorized domain matches domain lock", $dep['deployment_domain'] === $domain);

        // 5. Re-activation from different domain is rejected
        $fraudDomain = 'pos.fraudster.net';
        $domainConflict = ($dep['deployment_domain'] !== $fraudDomain);
        $this->assert("Re-activation from mismatched domain detected as violation", $domainConflict);

        // 6. Suspended license rejection
        $suspendStmt = $db->prepare("UPDATE central_licenses SET status = 'suspended' WHERE id = ?");
        $suspendStmt->execute([$lic['id']]);
        $suspendedLic = $mgr->getLicense($key);
        $this->assert("Suspended license detected and blocked", in_array($suspendedLic['status'], ['revoked', 'suspended'], true));

        // 7. Expired license rejection
        $expLicense = $mgr->issueLicense(3, 'Expired Clinic', 'admin@exp.com', 'trial', '2020-01-01 00:00:00');
        $expLicData = $mgr->getLicense($expLicense['license_key']);
        $isExpired = ($expLicData['expires_at'] && strtotime($expLicData['expires_at']) < time());
        $this->assert("Expired license timestamp correctly identified as expired", $isExpired);
    }

    private function testUserManagement(): void {
        $db = Database::getConnection();

        // 1. Create a new operator user
        $name = 'Marcus Vance';
        $email = 'marcus@ops.net';
        $pass = 'securePass123';
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $ins = $db->prepare("INSERT INTO central_users (name, email, password_hash, role) VALUES (?, ?, ?, 'viewer')");
        $ins->execute([$name, $email, $hash]);
        $newUserId = (int)$db->lastInsertId();

        $this->assert("New operator user registered with valid ID", $newUserId > 0);

        // 2. Query user and verify bcrypt authentication
        $stmt = $db->prepare("SELECT * FROM central_users WHERE id = ?");
        $stmt->execute([$newUserId]);
        $u = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assert("User details stored correctly", $u['email'] === $email && $u['role'] === 'viewer');
        $this->assert("User password authenticates via bcrypt", password_verify($pass, $u['password_hash']));

        // 3. Update user profile & promote to admin
        $upd = $db->prepare("UPDATE central_users SET name = ?, role = 'admin' WHERE id = ?");
        $upd->execute(['Marcus V. (Lead)', $newUserId]);

        $stmt->execute([$newUserId]);
        $updatedU = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assert("User name and role updated", $updatedU['name'] === 'Marcus V. (Lead)' && $updatedU['role'] === 'admin');

        // 4. Reset user password
        $newPass = 'BrandNewPassword456!';
        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $pwdUpd = $db->prepare("UPDATE central_users SET password_hash = ? WHERE id = ?");
        $pwdUpd->execute([$newHash, $newUserId]);

        $stmt->execute([$newUserId]);
        $pwdU = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assert("Password reset succeeds and new password verifies", password_verify($newPass, $pwdU['password_hash']));
        $this->assert("Old password fails verification", !password_verify($pass, $pwdU['password_hash']));

        // 5. Delete user
        $del = $db->prepare("DELETE FROM central_users WHERE id = ?");
        $del->execute([$newUserId]);

        $stmt->execute([$newUserId]);
        $deletedU = $stmt->fetch(\PDO::FETCH_ASSOC);
        $this->assert("Deleted user no longer exists in database", $deletedU === false);
    }

    private function testVendPulseOfflineCryptographicLicenses(): void {
        $db = Database::getConnection();
        $vpService = new VendPulseLicenseService($db);

        // 1. Key infrastructure
        $pubKey = $vpService->getPublicKey();
        $this->assert("VendPulse public key exists and is valid PEM", !empty($pubKey) && str_contains($pubKey, 'BEGIN PUBLIC KEY'));
        $this->assert("VendPulse public key does not leak private key material", !str_contains($pubKey, 'PRIVATE KEY'));

        // 2. Tier defaults
        $this->assert(
            "VendPulse RETAIL_BASIC has correct modules",
            $vpService->getDefaultModulesForTier('RETAIL_BASIC') === ["catalog_grid", "standard_pos", "basic_receipt"]
        );
        $this->assert(
            "VendPulse SUPERMARKET has correct modules",
            $vpService->getDefaultModulesForTier('SUPERMARKET') === ["barcode_rapid_stream", "scale_comm", "multi_till", "cashier_shifts"]
        );
        $this->assert(
            "VendPulse ENTERPRISE_WHOLESALE has correct modules",
            $vpService->getDefaultModulesForTier('ENTERPRISE_WHOLESALE') === ["multi_warehouse", "b2b_invoices", "credit_accounts", "institutional_branding"]
        );

        // 3. Issue license
        $client = "Al-Amana Supermarket - Branch 1";
        $lic = $vpService->issueLicense([
            'client_name' => $client,
            'tier' => 'SUPERMARKET',
            'max_counters' => 3,
            'machine_hwid' => 'HWID-DEVICE-XYZ-99',
            'expires_at' => time() + 86400 * 365,
        ]);

        $this->assert("VendPulse license issued with valid reference ID", str_starts_with($lic['license_id'], 'VP-LIC-2026-'));
        $this->assert("VendPulse canonical payload normalized properly", $lic['payload']['product'] === 'VENDPULSE' && $lic['payload']['max_counters'] === 3);
        $this->assert("VendPulse cryptographic signature generated", !empty($lic['signature']));

        // 4. Verify authentic license
        $res = $vpService->verifyLicenseBundle($lic['license_bundle'], 'HWID-DEVICE-XYZ-99');
        $this->assert("VendPulse authentic license verifies successfully", $res['valid'] === true);

        // 5. Tampered license rejection
        $tampered = json_decode($lic['license_bundle'], true);
        $tampered['payload']['max_counters'] = 100;
        $tamperedRes = $vpService->verifyLicenseBundle(json_encode($tampered));
        $this->assert("VendPulse tampered payload is cryptographically rejected", $tamperedRes['valid'] === false);

        // 6. Expired license rejection
        $expiredLic = $vpService->issueLicense([
            'client_name' => 'Past Store',
            'tier' => 'RETAIL_BASIC',
            'expires_at' => time() - 3600,
        ]);
        $expRes = $vpService->verifyLicenseBundle($expiredLic['license_bundle']);
        $this->assert("VendPulse expired offline license rejected", $expRes['valid'] === false && $expRes['status'] === 'expired');

        // 7. Hardware mismatch rejection
        $hwidMismatchRes = $vpService->verifyLicenseBundle($lic['license_bundle'], 'DIFFERENT-HWID-ATTEMPT');
        $this->assert("VendPulse hardware lock enforces device binding", $hwidMismatchRes['valid'] === false && $hwidMismatchRes['status'] === 'hwid_mismatch');

        // 8. Perpetual license acceptance
        $perpetual = $vpService->issueLicense([
            'client_name' => 'Perpetual Retail Co',
            'tier' => 'RETAIL_BASIC',
            'expires_at' => null,
        ]);
        $perpRes = $vpService->verifyLicenseBundle($perpetual['license_bundle']);
        $this->assert("VendPulse perpetual license accepted without expiry", $perpRes['valid'] === true && $perpRes['payload']['expires_at'] === null);

        // 9. System Usage Mode verification (RETAIL_SHOP, SUPERMARKET, RESTAURANT)
        $this->assert("VendPulse default usage mode is RETAIL_SHOP", ($perpetual['usage_mode'] ?? '') === 'RETAIL_SHOP');
        
        $restaurantLic = $vpService->issueLicense([
            'client_name' => 'Luigi Italian Trattoria',
            'tier' => 'ENTERPRISE_WHOLESALE',
            'usage_mode' => 'RESTAURANT',
            'max_counters' => 4,
        ]);
        $this->assert("VendPulse explicit RESTAURANT usage mode preserved in payload", ($restaurantLic['payload']['usage_mode'] ?? '') === 'RESTAURANT');
        $restRes = $vpService->verifyLicenseBundle($restaurantLic['license_bundle']);
        $this->assert("VendPulse RESTAURANT bundle passes cryptographic verification", $restRes['valid'] === true && ($restRes['payload']['usage_mode'] ?? '') === 'RESTAURANT');

        $tamperedMode = json_decode($restaurantLic['license_bundle'], true);
        $tamperedMode['payload']['usage_mode'] = 'SUPERMARKET';
        $tamperedModeRes = $vpService->verifyLicenseBundle(json_encode($tamperedMode));
        $this->assert("VendPulse tampering with usage_mode fails cryptographic verification", $tamperedModeRes['valid'] === false);
    }

    private function testOrderProvisioningAndDistribution(): void {
        $db = Database::getConnection();
        $secret = 'spec_whsec_unit_test_secret_123';
        $orderService = new OrderProvisioningService($db, null, null, $secret);

        // 1. Webhook Secret Authentication
        $this->assert("Webhook secret auth accepts valid secret", $orderService->verifyWebhookSecret($secret));
        $this->assert("Webhook secret auth rejects wrong secret", !$orderService->verifyWebhookSecret('wrong_secret'));
        $this->assert("Webhook secret auth rejects null secret", !$orderService->verifyWebhookSecret(null));

        // 2. Schema Validation
        $valError = false;
        try {
            $orderService->validateOrderPayload(['order_id' => 'ORD-123']);
        } catch (\InvalidArgumentException) {
            $valError = true;
        }
        $this->assert("Payload validation catches missing customer fields", $valError);

        // 3. Automated Order Ingress & Supermarket Mode Provisioning
        $orderId = 'ORD-UNIT-' . bin2hex(random_bytes(3));
        $res = $orderService->provisionOrder([
            'order_id' => $orderId,
            'customer_name' => 'Kareem Taha',
            'customer_email' => 'kareem@supermart.eg',
            'business_name' => 'Taha Fresh Supermarket',
            'purchased_product' => 'VENDPULSE',
            'usage_mode' => 'SUPERMARKET',
            'term_duration_months' => 12,
            'max_counters' => 4,
            'include_installer_bundle' => true,
        ]);

        $this->assert("Order provision succeeds with success = true", !empty($res['success']));
        $this->assert("Order provision returns valid license_id", !empty($res['license']['license_id']));
        $this->assert("License usage_mode is SUPERMARKET", ($res['license']['usage_mode'] ?? '') === 'SUPERMARKET');
        $this->assert("License max_counters is 4", (int)($res['license']['max_counters'] ?? 0) === 4);
        $this->assert("Customer client account created with client_code", !empty($res['client']['client_code']));
        $this->assert("Download portal token generated", !empty($res['distribution']['download_token']));
        $this->assert("Download portal URL includes token", str_contains($res['distribution']['portal_url'], $res['distribution']['download_token']));

        // 4. Idempotency Check
        $repeatRes = $orderService->provisionOrder([
            'order_id' => $orderId,
            'customer_name' => 'Kareem Taha',
            'customer_email' => 'kareem@supermart.eg',
            'business_name' => 'Taha Fresh Supermarket',
            'purchased_product' => 'VENDPULSE',
            'usage_mode' => 'SUPERMARKET',
        ]);
        $this->assert("Idempotent fulfillment returns idempotent = true", !empty($repeatRes['idempotent']));
        $this->assert("Idempotent order returns matching license_id", ($repeatRes['license']['license_id'] ?? '') === ($res['license']['license_id'] ?? ''));

        // 5. Token Retrieval & Download Logging
        $token = $res['distribution']['download_token'];
        $fetched = $orderService->getOrderByDownloadToken($token);
        $this->assert("Order successfully retrieved by download token", $fetched !== null && ($fetched['order_id'] ?? '') === $orderId);

        $orderService->recordDownload($token);
        $fetchedAfter = $orderService->getOrderByDownloadToken($token);
        $this->assert("Download counter incremented to 1", (int)($fetchedAfter['download_count'] ?? 0) === 1);

        // 6. Installer Distribution Assets
        $installerService = new InstallerDistributionService();
        $serverInfo = $installerService->getInstallerInfo('server');
        $clientInfo = $installerService->getInstallerInfo('client');
        $this->assert("Server installer setup binary exists on disk", file_exists($serverInfo['path']));
        $this->assert("Client installer setup binary exists on disk", file_exists($clientInfo['path']));
        $this->assert("Server installer has valid PE DOS header (MZ)", str_starts_with((string)file_get_contents($serverInfo['path'], false, null, 0, 2), "MZ"));
        $this->assert("Client installer has valid PE DOS header (MZ)", str_starts_with((string)file_get_contents($clientInfo['path'], false, null, 0, 2), "MZ"));

        // 7. Cloud Systems E-Commerce Order Provisioning
        $cloudOrderId = 'ORD-CLOUD-' . bin2hex(random_bytes(3));
        $cloudOrder = $orderService->provisionOrder([
            'order_id' => $cloudOrderId,
            'customer_name' => 'Dr. Eleanor Vance',
            'customer_email' => 'e.vance@hillhouse.edu',
            'business_name' => 'Hill House Academy',
            'purchased_product' => 'PRISM-EDU',
            'term_duration_months' => 12,
            'max_seats' => 250,
        ]);

        $this->assert("Cloud system order provisions with success = true", !empty($cloudOrder['success']));
        $this->assert("Cloud system order returns SPEC- format license key", str_starts_with((string)($cloudOrder['license']['license_id'] ?? ''), 'SPEC-PRISM-'));
        $this->assert("Cloud system order license product code matches PRISM", ($cloudOrder['license']['product'] ?? '') === 'PRISM');
        $this->assert("Cloud system order max_seats matches input 250", (int)($cloudOrder['license']['max_seats'] ?? 0) === 250);
        $this->assert("Cloud system order license is saved in central_licenses", (bool)$db->query("SELECT id FROM central_licenses WHERE license_key = " . $db->quote((string)$cloudOrder['license']['license_id']))->fetchColumn());
        $this->assert("Cloud system order portal URL contains valid token", !empty($cloudOrder['distribution']['portal_url']));

        // Cloud order idempotency
        $repeatCloud = $orderService->provisionOrder([
            'order_id' => $cloudOrderId,
            'customer_name' => 'Dr. Eleanor Vance',
            'customer_email' => 'e.vance@hillhouse.edu',
            'business_name' => 'Hill House Academy',
            'purchased_product' => 'PRISM-EDU',
        ]);
        $this->assert("Cloud system idempotent fulfillment preserves license key", ($repeatCloud['license']['license_id'] ?? '') === ($cloudOrder['license']['license_id'] ?? ''));
    }
}

// Execute runner
$runner = new FleetHubTestRunner();
$runner->run();
