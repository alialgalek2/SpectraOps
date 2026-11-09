<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for Client-Side Cryptographic Lease Verification Middleware
 * 
 * Verifies:
 * 1. Base64Url token format: Base64UrlEncoded(JSON_Payload) . "." . HMAC_SHA256_Signature
 * 2. Cryptographic signature verification using lease_salt with timing-attack safe comparison
 * 3. Payload schema enforcement: deployment_id, license_id, status, expires_at
 * 4. Active status validation (rejects 'suspended', 'revoked')
 * 5. Deployment / License binding checks
 * 6. Expiration & proactive renewal window trigger (< 30 mins)
 * 7. Offline grace period enforcement (active access granted during grace period with warning)
 * 8. Hard lockout enforcement after grace period lapse (HTTP 403 / redirect to /activation.php)
 * 9. URI and CLI bypass rules (CLI, static assets, activation wizard)
 * 10. HTTP enforcement behavior (JSON 403 vs browser redirect)
 * 11. Atomic lockfile updates and renewal debouncing
 */

namespace FleetHub\Tests;

require_once dirname(__DIR__) . '/client-sdk/LeaseVerificationMiddleware.php';

use FleetHub\ClientSdk\LeaseVerificationMiddleware;

class LeaseMiddlewareTestSuite {
    private int $passed = 0;
    private int $failed = 0;
    private string $tempDir;
    private string $leaseSalt = '7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a';

    public function __construct() {
        $this->tempDir = sys_get_temp_dir() . '/lease_test_' . uniqid();
        @mkdir($this->tempDir . '/storage', 0755, true);
    }

    public function __destruct() {
        $this->cleanupDir($this->tempDir);
    }

    private function cleanupDir(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $p = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($p) ? $this->cleanupDir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function assert(bool $cond, string $msg, ?string $details = null): void {
        if ($cond) {
            $this->passed++;
            echo "  \033[32m[PASS]\033[0m {$msg}\n";
        } else {
            $this->failed++;
            echo "  \033[31m[FAIL]\033[0m {$msg}\n";
            if ($details !== null) {
                echo "         \033[33mDetails: {$details}\033[0m\n";
            }
        }
    }

    public function run(): void {
        echo "====================================================================\n";
        echo "SPECTRAOPS CLIENT-SIDE CRYPTOGRAPHIC LEASE VERIFICATION SUITE\n";
        echo "====================================================================\n\n";

        $this->testTokenGenerationAndDecoding();
        $this->testValidActiveLease();
        $this->testTamperRejection();
        $this->testStatusEnforcement();
        $this->testBindingChecks();
        $this->testRenewalWindowTrigger();
        $this->testOfflineGracePeriod();
        $this->testHardLockoutAfterGracePeriod();
        $this->testMissingOrCorruptedLock();
        $this->testBypassRules();
        $this->testHttpEnforcementActions();
        $this->testRenewalDebounceAndLockUpdate();

        echo "\n====================================================================\n";
        echo "SUMMARY\n";
        echo "====================================================================\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        if ($this->failed > 0) {
            echo "RESULT: FAIL - Some tests encountered errors.\n";
            exit(1);
        } else {
            echo "RESULT: SUCCESS - All cryptographic lease verification requirements verified!\n";
            exit(0);
        }
    }

    private function testTokenGenerationAndDecoding(): void {
        echo "[TEST 1] Base64Url Token Formatting & Decoding\n";

        $payload = [
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 7200
        ];

        $token = LeaseVerificationMiddleware::generateToken($payload, $this->leaseSalt);

        $this->assert(str_contains($token, '.'), "Token contains period separator (<payload>.<signature>)");
        $parts = explode('.', $token);
        $this->assert(count($parts) === 2, "Token splits into exactly 2 parts");
        $this->assert(!str_contains($parts[0], '+') && !str_contains($parts[0], '/') && !str_contains($parts[0], '='), "Payload part is strictly Base64Url encoded without +, /, or = padding");
        $this->assert(strlen($parts[1]) === 64, "Signature is a 64-char hex SHA-256 HMAC");

        $decoded = LeaseVerificationMiddleware::decodeTokenPayload($token);
        $this->assert(is_array($decoded), "Token payload decodes into an associative array");
        $this->assert(($decoded['deployment_id'] ?? 0) === 8, "Decoded deployment_id is 8");
        $this->assert(($decoded['license_id'] ?? 0) === 2, "Decoded license_id is 2");
        $this->assert(($decoded['status'] ?? '') === 'active', "Decoded status is 'active'");
        $this->assert(($decoded['expires_at'] ?? 0) === $payload['expires_at'], "Decoded expires_at matches original");
    }

    private function testValidActiveLease(): void {
        echo "\n[TEST 2] Cryptographic Verification of Valid Active Lease\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);
        $payload = [
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 7200 // 2 hours in the future
        ];
        $token = LeaseVerificationMiddleware::generateToken($payload, $this->leaseSalt);

        $result = $mw->verifyToken($token);

        $this->assert($result['valid'] === true, "Valid token reports valid = true");
        $this->assert($result['status'] === 'ACTIVE', "Status reports 'ACTIVE'");
        $this->assert($result['in_grace_period'] === false, "in_grace_period is false");
        $this->assert($result['needs_renewal'] === false, "needs_renewal is false when expiry > 30 minutes");
        $this->assert(($result['payload']['deployment_id'] ?? 0) === 8, "Payload deployment_id preserved");
    }

    private function testTamperRejection(): void {
        echo "\n[TEST 3] Cryptographic Tamper Rejection\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);
        $payload = [
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 7200
        ];
        $validToken = LeaseVerificationMiddleware::generateToken($payload, $this->leaseSalt);
        [$encPayload, $signature] = explode('.', $validToken, 2);

        // 1. Tamper with signature
        $tamperedSig = substr($signature, 0, -2) . 'ab';
        $resTamperedSig = $mw->verifyToken("{$encPayload}.{$tamperedSig}");
        $this->assert($resTamperedSig['valid'] === false, "Rejects tampered signature");
        $this->assert($resTamperedSig['code'] === 'INVALID_SIGNATURE', "Reports error code INVALID_SIGNATURE");

        // 2. Tamper with payload (attacker extends expires_at)
        $tamperedPayload = $payload;
        $tamperedPayload['expires_at'] = time() + 9999999;
        $tamperedEnc = LeaseVerificationMiddleware::base64UrlEncode(json_encode($tamperedPayload));
        $resTamperedPayload = $mw->verifyToken("{$tamperedEnc}.{$signature}");
        $this->assert($resTamperedPayload['valid'] === false, "Rejects modified payload with original signature");
        $this->assert($resTamperedPayload['code'] === 'INVALID_SIGNATURE', "Reports error code INVALID_SIGNATURE for payload tampering");

        // 3. Forged token signed with wrong secret salt
        $forgedToken = LeaseVerificationMiddleware::generateToken($payload, 'attacker_wrong_salt_12345');
        $resForged = $mw->verifyToken($forgedToken);
        $this->assert($resForged['valid'] === false, "Rejects token signed with wrong secret salt");
    }

    private function testStatusEnforcement(): void {
        echo "\n[TEST 4] Status Enforcement in Payload\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);

        // Suspended status
        $suspendedToken = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'suspended',
            'expires_at'    => time() + 7200
        ], $this->leaseSalt);

        $resSuspended = $mw->verifyToken($suspendedToken);
        $this->assert($resSuspended['valid'] === false, "Rejects token with status = 'suspended'");
        $this->assert($resSuspended['code'] === 'LICENSE_INACTIVE', "Reports error code LICENSE_INACTIVE");

        // Revoked status
        $revokedToken = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'revoked',
            'expires_at'    => time() + 7200
        ], $this->leaseSalt);

        $resRevoked = $mw->verifyToken($revokedToken);
        $this->assert($resRevoked['valid'] === false, "Rejects token with status = 'revoked'");
    }

    private function testBindingChecks(): void {
        echo "\n[TEST 5] Deployment & License ID Binding\n";

        // Bind middleware to deployment #8 and license #2
        $mwBound = new LeaseVerificationMiddleware(
            $this->tempDir,
            $this->leaseSalt,
            null,
            null,
            259200,
            1800,
            8, // expectedDeploymentId
            2  // expectedLicenseId
        );

        $validBoundToken = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 7200
        ], $this->leaseSalt);

        $resBound = $mwBound->verifyToken($validBoundToken);
        $this->assert($resBound['valid'] === true, "Accepts token matching bound deployment_id and license_id");

        // Stolen token from deployment #9
        $stolenToken = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 9,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 7200
        ], $this->leaseSalt);

        $resStolen = $mwBound->verifyToken($stolenToken);
        $this->assert($resStolen['valid'] === false, "Rejects token with mismatched deployment_id");
        $this->assert($resStolen['code'] === 'DEPLOYMENT_MISMATCH', "Reports error code DEPLOYMENT_MISMATCH");
    }

    private function testRenewalWindowTrigger(): void {
        echo "\n[TEST 6] Proactive Renewal Window Detection\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt, null, null, 259200, 1800); // 30 min renewal window

        // Token expiring in 15 minutes (< 30 min window)
        $tokenSoon = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 900 // 15 mins
        ], $this->leaseSalt);

        $resSoon = $mw->verifyToken($tokenSoon);
        $this->assert($resSoon['valid'] === true, "Token expiring soon is still valid");
        $this->assert($resSoon['needs_renewal'] === true, "Flags needs_renewal = true when within 30 min window");

        // Token expiring in 60 minutes (> 30 min window)
        $tokenFar = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 3600 // 60 mins
        ], $this->leaseSalt);

        $resFar = $mw->verifyToken($tokenFar);
        $this->assert($resFar['valid'] === true, "Token with ample time is valid");
        $this->assert($resFar['needs_renewal'] === false, "Flags needs_renewal = false when outside window");
    }

    private function testOfflineGracePeriod(): void {
        echo "\n[TEST 7] Offline Grace Period Enforcement\n";

        // Grace period = 72 hours (259200s)
        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt, null, null, 259200);

        // Token expired 2 hours ago (within 72 hour grace period)
        $expiredGraceToken = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() - 7200 // expired 2h ago
        ], $this->leaseSalt);

        $resGrace = $mw->verifyToken($expiredGraceToken);

        $this->assert($resGrace['valid'] === true, "Grants access during offline grace period");
        $this->assert($resGrace['status'] === 'GRACE_PERIOD', "Status indicates 'GRACE_PERIOD'");
        $this->assert($resGrace['in_grace_period'] === true, "in_grace_period flag is true");
        $this->assert($resGrace['needs_renewal'] === true, "needs_renewal flag is true");
        $this->assert($resGrace['grace_expires_at'] > time(), "Calculates remaining grace period expiration timestamp");
    }

    private function testHardLockoutAfterGracePeriod(): void {
        echo "\n[TEST 8] Hard Lockout After Grace Period Lapse\n";

        // Grace period = 72 hours (259200s)
        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt, null, null, 259200);

        // Token expired 100 hours ago (> 72 hour grace period)
        $lapsedToken = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() - 360000 // expired 100h ago
        ], $this->leaseSalt);

        $resLapsed = $mw->verifyToken($lapsedToken);

        $this->assert($resLapsed['valid'] === false, "Denies access when grace period has lapsed");
        $this->assert($resLapsed['code'] === 'LEASE_EXPIRED', "Reports error code LEASE_EXPIRED");
        $this->assert($resLapsed['status'] === 'EXPIRED', "Status reports 'EXPIRED'");
    }

    private function testMissingOrCorruptedLock(): void {
        echo "\n[TEST 9] Missing or Corrupted Lock File Detection\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);
        $lockFile = $this->tempDir . '/storage/license.lock';

        // 1. Missing lock file
        if (file_exists($lockFile)) @unlink($lockFile);
        $resMissing = $mw->verifyCurrentInstallation();
        $this->assert($resMissing['valid'] === false, "Reports invalid when license.lock is missing");
        $this->assert($resMissing['code'] === 'MISSING_LOCK', "Reports error code MISSING_LOCK");

        // 2. Corrupted JSON in lock file
        file_put_contents($lockFile, "NOT_VALID_JSON{{{");
        $resCorrupt = $mw->verifyCurrentInstallation();
        $this->assert($resCorrupt['valid'] === false, "Reports invalid when license.lock is malformed");
        $this->assert($resCorrupt['code'] === 'CORRUPTED_LOCK', "Reports error code CORRUPTED_LOCK");

        // 3. Lock missing lease_token
        file_put_contents($lockFile, json_encode(['license_key' => 'SPEC-TEST']));
        $resNoToken = $mw->verifyCurrentInstallation();
        $this->assert($resNoToken['valid'] === false, "Reports invalid when lease_token key is missing from lock");
        $this->assert($resNoToken['code'] === 'MISSING_TOKEN', "Reports error code MISSING_TOKEN");
    }

    private function testBypassRules(): void {
        echo "\n[TEST 10] Bypass Route & Environment Rules\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);

        // CLI execution
        $this->assert($mw->shouldBypass('/any/path', 'cli') === true, "Bypasses CLI environment (SAPI = 'cli')");

        // Activation wizard
        $this->assert($mw->shouldBypass('/activation.php', 'apache2handler') === true, "Bypasses /activation.php");
        $this->assert($mw->shouldBypass('/school_system/activation.php?step=2', 'fpm-fcgi') === true, "Bypasses subpath activation.php with query");

        // Static assets
        $this->assert($mw->shouldBypass('/assets/css/app.css', 'apache2handler') === true, "Bypasses /assets/ static files");
        $this->assert($mw->shouldBypass('/uploads/logo.png', 'apache2handler') === true, "Bypasses /uploads/ files");
        $this->assert($mw->shouldBypass('/favicon.ico', 'apache2handler') === true, "Bypasses favicon.ico");

        // Protected application routes
        $this->assert($mw->shouldBypass('/dashboard.php', 'apache2handler') === false, "Protects /dashboard.php");
        $this->assert($mw->shouldBypass('/grades/view.php', 'fpm-fcgi') === false, "Protects /grades/view.php");
        $this->assert($mw->shouldBypass('/api/v1/students.php', 'fpm-fcgi') === false, "Protects /api/v1/students.php");
    }

    private function testHttpEnforcementActions(): void {
        echo "\n[TEST 11] HTTP Enforcement Actions (API 403 vs Browser Redirect)\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);

        // Expired failure payload
        $failure = [
            'valid'  => false,
            'code'   => 'LEASE_EXPIRED',
            'reason' => 'Lease has expired and offline grace period has lapsed.'
        ];

        // 1. API / AJAX request
        $apiAction = $mw->determineEnforcementAction($failure, '/api/students', ['accept' => 'application/json']);
        $this->assert($apiAction['type'] === 'json_response', "API request triggers json_response");
        $this->assert($apiAction['http_code'] === 403, "API response status is HTTP 403 Forbidden");
        $this->assert($apiAction['payload']['code'] === 'LEASE_EXPIRED', "API payload contains error code");

        // 2. Standard browser navigation
        $browserAction = $mw->determineEnforcementAction($failure, '/dashboard.php', ['accept' => 'text/html']);
        $this->assert($browserAction['type'] === 'redirect', "Browser request triggers redirect");
        $this->assert(str_contains($browserAction['target_url'], 'activation.php'), "Redirect target is activation.php");
    }

    private function testRenewalDebounceAndLockUpdate(): void {
        echo "\n[TEST 12] Renewal Debouncing & Atomic Lockfile Updates\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);

        // 1. Debounce check: first attempt allowed, immediate second attempt debounced
        $this->assert($mw->canAttemptRenewal() === true, "First renewal attempt is permitted");
        $mw->markRenewalAttempt();
        $this->assert($mw->canAttemptRenewal() === false, "Immediate subsequent attempt is debounced (< 60s)");

        // 2. Atomic lock file update
        $newToken = LeaseVerificationMiddleware::generateToken([
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 7200
        ], $this->leaseSalt);

        $saved = $mw->updateLockToken($newToken, 'SPEC-PRISM-88A6-D474');
        $this->assert($saved === true, "updateLockToken succeeds");

        $lockData = json_decode((string)file_get_contents($this->tempDir . '/storage/license.lock'), true);
        $this->assert(($lockData['lease_token'] ?? '') === $newToken, "Saved lock contains updated lease_token");
        $this->assert(($lockData['license_key'] ?? '') === 'SPEC-PRISM-88A6-D474', "Saved lock contains license_key");

        // Verify current installation now passes with newly saved token
        $verifyRes = $mw->verifyCurrentInstallation();
        $this->assert($verifyRes['valid'] === true, "verifyCurrentInstallation() succeeds with newly saved lock");
    }
}

// Direct execution
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) {
    $suite = new LeaseMiddlewareTestSuite();
    $suite->run();
}
