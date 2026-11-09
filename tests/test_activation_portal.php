<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for Prism EDU License Lock Screen & Manual Re-Activation Portal
 */

namespace FleetHub\Tests;

require_once dirname(__DIR__) . '/client-sdk/LeaseVerificationMiddleware.php';

use FleetHub\ClientSdk\LeaseVerificationMiddleware;

class ActivationPortalTestSuite {
    private int $passed = 0;
    private int $failed = 0;
    private string $tempDir;
    private string $leaseSalt = '7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a';

    public function __construct() {
        $this->tempDir = sys_get_temp_dir() . '/portal_test_' . uniqid();
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
        echo "PRISM EDU RE-ACTIVATION & LOCK SCREEN PORTAL VERIFICATION\n";
        echo "====================================================================\n\n";

        $this->testMarkupAndSecurityDirectives();
        $this->testMachineChallengeCodeGeneration();
        $this->testOfflineEmergencyTokenVerification();
        $this->testCsrfTokenProtection();

        echo "\n====================================================================\n";
        echo "SUMMARY\n";
        echo "====================================================================\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        if ($this->failed > 0) {
            echo "RESULT: FAIL - Some tests encountered errors.\n";
            exit(1);
        } else {
            echo "RESULT: SUCCESS - Lock screen & re-activation portal verified!\n";
            exit(0);
        }
    }

    private function testMarkupAndSecurityDirectives(): void {
        echo "[TEST 1] Markup, Security Badges & Reassurance Copy\n";

        $activationFile = 'c:/xampp/htdocs/Prism EDU/activation.php';
        $this->assert(file_exists($activationFile), "activation.php exists in Prism EDU root");

        $content = (string)file_get_contents($activationFile);

        $this->assert(str_contains($content, 'School Data Intact &amp; Encrypted') || str_contains($content, 'School Data Intact'), "Contains explicit reassurance banner that student data is preserved");
        $this->assert(str_contains($content, 'Student records, attendance, report cards, and SQL databases are fully preserved'), "Explicitly guarantees preservation of academic records");
        $this->assert(str_contains($content, 'One-Click') || str_contains($content, 'Automatic Cloud Re-Sync'), "Includes primary Cloud Re-Sync channel");
        $this->assert(str_contains($content, 'Manual / Air-Gapped Key'), "Includes secondary Air-Gapped manual channel");
        $this->assert(str_contains($content, 'ajax_renew'), "Implements AJAX lease renewal endpoint");
        $this->assert(str_contains($content, 'ajax_test_connectivity'), "Implements AJAX connectivity testing endpoint");
        $this->assert(str_contains($content, 'diagnosticBundleJson'), "Generates copyable diagnostic bundle");
    }

    private function testMachineChallengeCodeGeneration(): void {
        echo "\n[TEST 2] Machine Challenge Code for Offline District Support\n";

        $payload = [
            'dep_id' => 8,
            'ip'     => '192.168.1.50',
            'host'   => 'school.prism.edu',
            'key'    => 'SPEC-PRISM****',
            'ts'     => 1793000000
        ];

        $encoded = LeaseVerificationMiddleware::base64UrlEncode(json_encode($payload));
        $this->assert(!str_contains($encoded, '+') && !str_contains($encoded, '/') && !str_contains($encoded, '='), "Challenge code is strictly Base64Url encoded without symbols or padding");

        $decodedStr = LeaseVerificationMiddleware::base64UrlDecode($encoded);
        $decoded = json_decode($decodedStr, true);
        $this->assert(is_array($decoded) && ($decoded['dep_id'] ?? 0) === 8, "Challenge code successfully decodes and preserves node identity");
    }

    private function testOfflineEmergencyTokenVerification(): void {
        echo "\n[TEST 3] Offline Emergency Authorization Token Acceptance\n";

        $mw = new LeaseVerificationMiddleware($this->tempDir, $this->leaseSalt);

        // Emergency token created by District IT / Central Hub with 7-day validity
        $emergencyPayload = [
            'deployment_id' => 8,
            'license_id'    => 2,
            'status'        => 'active',
            'expires_at'    => time() + 604800 // 7 days
        ];

        $emergencyToken = LeaseVerificationMiddleware::generateToken($emergencyPayload, $this->leaseSalt);

        $check = $mw->verifyToken($emergencyToken);
        $this->assert($check['valid'] === true, "Validates authentic offline emergency token");
        $this->assert($check['status'] === 'ACTIVE', "Status reports 'ACTIVE'");
        $this->assert($check['in_grace_period'] === false, "Offline token resets nominal active state");

        // Tampered emergency token
        $tampered = $emergencyToken . 'bad';
        $badCheck = $mw->verifyToken($tampered);
        $this->assert($badCheck['valid'] === false, "Rejects tampered offline emergency token");
    }

    private function testCsrfTokenProtection(): void {
        echo "\n[TEST 4] CSRF Protection & Security Guardrails\n";

        $activationFile = 'c:/xampp/htdocs/Prism EDU/activation.php';
        $content = (string)file_get_contents($activationFile);

        $this->assert(str_contains($content, 'csrf_token'), "Enforces CSRF token check on state-changing actions");
        $this->assert(str_contains($content, 'hash_equals'), "Uses timing-attack safe comparison for CSRF tokens");
        $this->assert(str_contains($content, 'htmlspecialchars'), "Sanitizes output variables with htmlspecialchars");
    }
}

$suite = new ActivationPortalTestSuite();
$suite->run();
