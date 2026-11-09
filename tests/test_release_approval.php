<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for Release Approval & Artifact Publishing Workflow
 * 
 * Tests:
 * 1. Method enforcement (POST only, 405 on GET)
 * 2. Authorization enforcement (401 without auth, 403 with invalid CSRF)
 * 3. Input validation (product_id, version SemVer, staging_filename)
 * 4. Version progression & collision checks (version_compare > latest_version, 409 on duplicate)
 * 5. Directory traversal prevention (basename sanitization)
 * 6. Artifact promotion from /storage/hotfixes/{slug}/staging/ to /public/downloads/updates/
 * 7. Database transaction (central_releases row, central_products.latest_version, central_error_logs status)
 * 8. Immediate consumability via /api/v1/updates/check.php
 */

require_once dirname(__DIR__) . '/src/Database.php';

use FleetHub\Database;

class ReleaseApprovalTestSuite {
    private PDO $pdo;
    private int $passed = 0;
    private int $failed = 0;
    private string $appRoot;

    public function __construct() {
        $this->appRoot = dirname(__DIR__);
        $this->pdo = Database::getConnection();
    }

    public function run(): void {
        echo "====================================================================\n";
        echo "SPECTRAOPS RELEASE APPROVAL & ARTIFACT PUBLISHING TEST SUITE\n";
        echo "====================================================================\n\n";

        $this->pdo->prepare("UPDATE central_products SET latest_version = '1.0.0' WHERE id = 1")->execute();

        $this->testMethodEnforcement();
        $this->testAuthorizationAndCsrf();
        $this->testInputValidation();
        $this->testVersionCollisionAndProgression();
        $this->testDirectoryTraversalPrevention();
        $this->testEndToEndApprovalAndArtifactPromotion();
        $this->testCheckPhpConsumability();

        echo "\n====================================================================\n";
        echo "SUMMARY\n";
        echo "====================================================================\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        if ($this->failed > 0) {
            echo "RESULT: FAIL - Some tests encountered errors.\n";
            exit(1);
        } else {
            echo "RESULT: SUCCESS - All release approval requirements verified!\n";
            exit(0);
        }
    }

    private function assert(bool $cond, string $msg): void {
        if ($cond) {
            $this->passed++;
            echo "  [PASS] {$msg}\n";
        } else {
            $this->failed++;
            echo "  [FAIL] {$msg}\n";
        }
    }

    private function invokeEndpoint(string $method, array $payload, array $headers = [], array $session = []): array {
        // Run endpoint in an isolated PHP process to simulate real HTTP execution
        $scriptPath = $this->appRoot . '/public/api/v1/releases/approve.php';
        $sessionExport = var_export($session, true);
        $serverVars = [
            'REQUEST_METHOD' => $method,
            'REMOTE_ADDR'    => '127.0.0.1'
        ];
        foreach ($headers as $k => $v) {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $k));
            $serverVars[$headerKey] = $v;
        }

        $serverExport = var_export($serverVars, true);
        $payloadJson = json_encode($payload);

        $code = <<<PHP
<?php
if (!empty({$sessionExport})) {
    @session_start();
    \$_SESSION = {$sessionExport};
}
\$_SERVER = array_merge(\$_SERVER, {$serverExport});
\$_POST = json_decode('{$payloadJson}', true) ?: [];

require '{$scriptPath}';
PHP;

        $tempScript = sys_get_temp_dir() . '/test_exec_' . uniqid() . '.php';
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
            return ['code' => 500, 'body' => '', 'data' => []];
        }

        // Feed input to stdin
        fwrite($pipes[0], $payloadJson);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_get_status($process);
        proc_close($process);
        @unlink($tempScript);

        $json = json_decode((string)$stdout, true) ?? [];
        return [
            'raw'    => $stdout,
            'stderr' => $stderr,
            'data'   => $json
        ];
    }

    private function testMethodEnforcement(): void {
        echo "[TEST 1] HTTP Method Enforcement\n";
        $res = $this->invokeEndpoint('GET', []);
        $this->assert(str_contains($res['raw'], 'Method Not Allowed') || ($res['data']['error'] ?? '') === 'Method Not Allowed. Only POST requests are accepted.', "Rejects GET requests with 405 Method Not Allowed");
    }

    private function testAuthorizationAndCsrf(): void {
        echo "\n[TEST 2] Authorization & CSRF Enforcement\n";
        
        // 1. Unauthenticated request
        $resUnauth = $this->invokeEndpoint('POST', ['product_id' => 1], [], []);
        $this->assert(($resUnauth['data']['success'] ?? true) === false && str_contains($resUnauth['data']['error'] ?? '', 'Unauthorized'), "Rejects unauthenticated requests with 401 Unauthorized");

        // 2. Session authenticated without CSRF token
        $session = ['fleet_user_id' => 1, 'csrf_token' => 'valid_csrf_token_abc123'];
        $resNoCsrf = $this->invokeEndpoint('POST', ['product_id' => 1], [], $session);
        $this->assert(($resNoCsrf['data']['success'] ?? true) === false && str_contains($resNoCsrf['data']['error'] ?? '', 'CSRF'), "Rejects session request missing CSRF token with 403 Forbidden");

        // 3. Session authenticated with invalid CSRF token
        $resBadCsrf = $this->invokeEndpoint('POST', ['product_id' => 1, 'csrf_token' => 'wrong_token'], [], $session);
        $this->assert(($resBadCsrf['data']['success'] ?? true) === false && str_contains($resBadCsrf['data']['error'] ?? '', 'CSRF'), "Rejects session request with invalid CSRF token");

        // 4. API Bearer token authentication allows access without browser session
        putenv("FLEET_SECRET_KEY=test_admin_secret_999");
        $resApi = $this->invokeEndpoint('POST', ['product_id' => 0], ['Authorization' => 'Bearer test_admin_secret_999'], []);
        $this->assert(($resApi['data']['error'] ?? '') !== 'Unauthorized. Admin operator session or valid authorization token required.', "API Bearer token authenticates successfully");
    }

    private function testInputValidation(): void {
        echo "\n[TEST 3] Input Parameter Validation\n";
        $session = ['fleet_user_id' => 1, 'csrf_token' => 'valid_csrf_123'];
        $basePayload = ['csrf_token' => 'valid_csrf_123'];

        // Missing product_id
        $res1 = $this->invokeEndpoint('POST', array_merge($basePayload, ['product_id' => 0]), [], $session);
        $this->assert(str_contains($res1['data']['error'] ?? '', 'product_id'), "Validates product_id is a positive integer");

        // Invalid version SemVer
        $res2 = $this->invokeEndpoint('POST', array_merge($basePayload, ['product_id' => 1, 'version' => 'invalid-version-string']), [], $session);
        $this->assert(str_contains($res2['data']['error'] ?? '', 'SemVer'), "Validates version is a valid SemVer string");

        // Missing staging_filename
        $res3 = $this->invokeEndpoint('POST', array_merge($basePayload, ['product_id' => 1, 'version' => '2.5.20', 'staging_filename' => '']), [], $session);
        $this->assert(str_contains($res3['data']['error'] ?? '', 'staging_filename'), "Validates staging_filename is required");

        // Non-zip file
        $res4 = $this->invokeEndpoint('POST', array_merge($basePayload, ['product_id' => 1, 'version' => '2.5.20', 'staging_filename' => 'patch.exe']), [], $session);
        $this->assert(str_contains($res4['data']['error'] ?? '', '.zip'), "Validates staging_filename must end with .zip");
    }

    private function testVersionCollisionAndProgression(): void {
        echo "\n[TEST 4] Version Collision & SemVer Progression Checks\n";

        // Query product latest_version
        $stmt = $this->pdo->query("SELECT latest_version FROM central_products WHERE id = 1");
        $currentLatest = (string)$stmt->fetchColumn();
        if ($currentLatest === '') {
            $currentLatest = '2.5.0';
            $this->pdo->query("UPDATE central_products SET latest_version = '2.5.0' WHERE id = 1");
        }

        $session = ['fleet_user_id' => 1, 'csrf_token' => 'valid_csrf_123'];
        $basePayload = [
            'csrf_token'        => 'valid_csrf_123',
            'product_id'        => 1,
            'staging_filename'  => 'mock_patch.zip'
        ];

        // Version lower than current latest_version
        $resLower = $this->invokeEndpoint('POST', array_merge($basePayload, ['version' => '1.0.0']), [], $session);
        $this->assert(str_contains($resLower['data']['error'] ?? '', 'strictly greater'), "Enforces new version must be strictly greater than latest_version ({$currentLatest})");

        // Version equal to current latest_version
        $resEqual = $this->invokeEndpoint('POST', array_merge($basePayload, ['version' => $currentLatest]), [], $session);
        $this->assert(str_contains($resEqual['data']['error'] ?? '', 'strictly greater'), "Rejects version equal to current latest_version");
    }

    private function testDirectoryTraversalPrevention(): void {
        echo "\n[TEST 5] Directory Traversal Prevention\n";
        $session = ['fleet_user_id' => 1, 'csrf_token' => 'valid_csrf_123'];

        // Malicious path traversal attempts
        $maliciousNames = [
            '../../../../etc/passwd.zip',
            '..\\..\\windows\\system32\\calc.zip',
            'sub/../../secret.zip'
        ];

        foreach ($maliciousNames as $badName) {
            $res = $this->invokeEndpoint('POST', [
                'csrf_token'       => 'valid_csrf_123',
                'product_id'       => 1,
                'version'          => '99.0.0',
                'staging_filename' => $badName
            ], [], $session);

            // Must sanitize using basename() and fail safe with staging archive not found
            $expectedBase = basename($badName);
            $this->assert(str_contains($res['data']['error'] ?? '', $expectedBase), "Safely sanitizes '{$badName}' to '{$expectedBase}' without traversal");
        }
    }

    private function testEndToEndApprovalAndArtifactPromotion(): void {
        echo "\n[TEST 6] End-to-End Release Approval & Artifact Promotion Pipeline\n";

        // Setup test product and staging artifact
        $productSlug = 'prism-edu';
        $newVersion = '2.5.99';

        // Pre-cleanup in case previous test created record
        $this->pdo->prepare("DELETE FROM central_releases WHERE product_id = 1 AND version = ?")->execute([$newVersion]);
        $this->pdo->prepare("UPDATE central_products SET latest_version = '2.5.16' WHERE id = 1")->execute();

        $stagingDir = $this->appRoot . "/storage/hotfixes/{$productSlug}/staging";
        if (!is_dir($stagingDir)) {
            mkdir($stagingDir, 0755, true);
        }

        $stagingFile = 'ai_patch_prism-edu_v2.5.99_' . time() . '.zip';
        $stagingPath = $stagingDir . '/' . $stagingFile;

        // Build valid test zip
        $zip = new ZipArchive();
        $zip->open($stagingPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('app/Controllers/HotfixController.php', "<?php // Hotfix v{$newVersion}\nreturn true;");
        $zip->close();

        $originalChecksum = hash_file('sha256', $stagingPath);
        $this->assert(file_exists($stagingPath), "Created temporary AI hotfix artifact in staging storage: {$stagingFile}");

        // Create an open error log to test resolves_error_id closure
        $errorHash = hash('sha256', 'TestException:' . time());
        $this->pdo->prepare("
            INSERT INTO central_error_logs (
                deployment_id, error_hash, error_level, status, error_message, file_path, line_number,
                first_seen_at, last_seen_at, occurred_at
            ) VALUES (
                8, ?, 'ERROR', 'open', 'Uncaught TestException in HotfixController',
                '/app/Controllers/HotfixController.php', 42,
                CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ")->execute([$errorHash]);
        $testErrorId = (int)$this->pdo->lastInsertId();
        $this->assert($testErrorId > 0, "Created test open error log with ID: {$testErrorId}");

        // Execute Approval Request
        $session = ['fleet_user_id' => 1, 'csrf_token' => 'test_approval_csrf'];
        $payload = [
            'csrf_token'        => 'test_approval_csrf',
            'product_id'        => 1,
            'version'           => $newVersion,
            'staging_filename'  => $stagingFile,
            'resolves_error_id' => $testErrorId,
            'release_type'      => 'patch',
            'is_silent_patch'   => 1,
            'notes'             => 'Automated Gemini AI Hotfix for TestException in HotfixController'
        ];

        $res = $this->invokeEndpoint('POST', $payload, [], $session);

        $this->assert(($res['data']['success'] ?? false) === true, "Approve endpoint returns success = true");
        $this->assert(($res['data']['version'] ?? '') === $newVersion, "Approve endpoint returns correct version {$newVersion}");
        $this->assert(($res['data']['package_filename'] ?? '') === "{$productSlug}-v{$newVersion}.zip", "Returns promoted package filename: {$productSlug}-v{$newVersion}.zip");
        $this->assert(($res['data']['package_checksum'] ?? '') === $originalChecksum, "Promoted package checksum matches original staging checksum");

        // Verify promoted file on disk in /public/downloads/updates/
        $promotedPath = $this->appRoot . "/public/downloads/updates/{$productSlug}-v{$newVersion}.zip";
        $this->assert(file_exists($promotedPath), "Artifact promoted to final destination: /public/downloads/updates/{$productSlug}-v{$newVersion}.zip");

        // Verify database state: central_releases
        $stmtRel = $this->pdo->prepare("SELECT * FROM central_releases WHERE product_id = 1 AND version = ?");
        $stmtRel->execute([$newVersion]);
        $release = $stmtRel->fetch(PDO::FETCH_ASSOC);

        $this->assert($release !== false, "Release record inserted into central_releases");
        $this->assert($release['release_type'] === 'patch', "Release type recorded as 'patch'");
        $this->assert((int)$release['is_silent_patch'] === 1, "is_silent_patch recorded as 1");
        $this->assert((int)$release['resolves_error_id'] === $testErrorId, "resolves_error_id linked to error log #{$testErrorId}");
        $this->assert($release['package_checksum'] === $originalChecksum, "package_checksum saved in database");

        // Verify product latest_version updated
        $stmtProd = $this->pdo->prepare("SELECT latest_version FROM central_products WHERE id = 1");
        $stmtProd->execute();
        $this->assert($stmtProd->fetchColumn() === $newVersion, "central_products.latest_version updated to {$newVersion}");

        // Verify central_error_logs updated to patch_pending
        $stmtErr = $this->pdo->prepare("SELECT status, resolved_by_version FROM central_error_logs WHERE id = ?");
        $stmtErr->execute([$testErrorId]);
        $errRecord = $stmtErr->fetch(PDO::FETCH_ASSOC);
        $this->assert($errRecord['status'] === 'patch_pending', "central_error_logs.status transitioned to 'patch_pending'");
        $this->assert($errRecord['resolved_by_version'] === $newVersion, "central_error_logs.resolved_by_version set to {$newVersion}");

        // Cleanup test artifacts
        @unlink($promotedPath);
        if (file_exists($stagingPath)) {
            @unlink($stagingPath);
        }
    }

    private function testCheckPhpConsumability(): void {
        echo "\n[TEST 7] Client OTA Check Consumability via /api/v1/updates/check.php\n";

        // Create a release for prism-edu v2.5.100 and ensure it is offered by check.php
        $testVersion = '2.5.100';
        $testFilename = 'prism-edu-v2.5.100.zip';
        $testFile = $this->appRoot . '/public/downloads/updates/' . $testFilename;
        file_put_contents($testFile, "PK mock update content");
        $checksum = hash_file('sha256', $testFile);

        // Update product latest_version and insert into central_releases
        $this->pdo->prepare("UPDATE central_products SET latest_version = ? WHERE id = 1")->execute([$testVersion]);
        $this->pdo->prepare("DELETE FROM central_releases WHERE product_id = 1 AND version = ?")->execute([$testVersion]);
        $this->pdo->prepare("
            INSERT INTO central_releases (
                product_id, version, release_type, is_silent_patch, changelog, package_filename, package_checksum, is_active, released_at
            ) VALUES (
                1, ?, 'patch', 1, 'OTA Verification Release', ?, ?, 1, CURRENT_TIMESTAMP
            )
        ")->execute([$testVersion, $testFilename, $checksum]);

        $testRawToken = 'SPEC-PRISM-88A6-D474';
        $testTokenHash = hash('sha256', $testRawToken);
        $this->pdo->prepare("UPDATE central_deployments SET api_ingest_token_hash = ?, current_version = '1.0.0' WHERE id = 8")->execute([$testTokenHash]);

        // Query check.php using CLI simulation for Deployment #8
        $checkScript = $this->appRoot . '/public/api/v1/updates/check.php';
        $payload = [
            'current_version' => '1.0.0',
            'product_slug'    => 'prism-edu',
            'api_token'       => $testRawToken
        ];

        $payloadJson = json_encode($payload);
        $wrapperCode = <<<PHP
<?php
\$_SERVER['REQUEST_METHOD'] = 'POST';
\$_SERVER['HTTP_X_API_KEY'] = '{$testRawToken}';
\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
\$_POST = json_decode('{$payloadJson}', true) ?: [];
require '{$checkScript}';
PHP;

        $tempCheckScript = sys_get_temp_dir() . '/test_check_' . uniqid() . '.php';
        file_put_contents($tempCheckScript, $wrapperCode);

        $cmd = "php -d display_errors=0 {$tempCheckScript}";
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $this->appRoot);
        fwrite($pipes[0], $payloadJson);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($tempCheckScript);

        $checkData = json_decode((string)$output, true) ?? [];

        $this->assert(($checkData['update_available'] ?? false) === true, "check.php detects that update is available");
        $this->assert(($checkData['latest_version'] ?? '') === $testVersion, "check.php reports latest_version = {$testVersion}");
        $this->assert(str_contains($checkData['download_url'] ?? '', $testFilename), "check.php serves correct download_url containing {$testFilename}");
        $this->assert(($checkData['checksum'] ?? '') === $checksum, "check.php reports accurate cryptographic checksum");
        $this->assert(($checkData['is_silent_patch'] ?? false) === true, "check.php flags silent patch for automated zero-downtime application");

        // Clean up
        @unlink($testFile);
        $this->pdo->prepare("DELETE FROM central_releases WHERE product_id = 1 AND version = ?")->execute([$testVersion]);
        $this->pdo->prepare("UPDATE central_products SET latest_version = '2.5.16' WHERE id = 1")->execute();
    }
}

$suite = new ReleaseApprovalTestSuite();
$suite->run();
