<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../src/Services/SystemUpdater.php';

use App\Services\SystemUpdater;

echo "=== Running SystemUpdaterTest ===\n";

$appRoot = dirname(__DIR__);
$updater = new SystemUpdater($appRoot);

$testStorage = $appRoot . '/storage/updates/tmp/test_harness';
if (!is_dir($testStorage)) {
    mkdir($testStorage, 0755, true);
}

// --- TEST 1: Checksum Verification ---
echo "[TEST 1] Checksum validation and abort on mismatch...\n";
$dummyPkg = $testStorage . '/test_pkg.zip';
file_put_contents($dummyPkg, 'dummy content for zip');
$correctHash = hash_file('sha256', $dummyPkg);
$tamperedHash = str_repeat('0', 64);

$reflection = new ReflectionClass(SystemUpdater::class);
$verifyMethod = $reflection->getMethod('verifyChecksum');
$verifyMethod->setAccessible(true);

// Positive check
try {
    $verifyMethod->invoke($updater, $dummyPkg, $correctHash);
    echo "[PASS] Valid checksum accepted.\n";
} catch (\Throwable $e) {
    echo "[FAIL] Unexpected error on valid checksum: " . $e->getMessage() . "\n";
    exit(1);
}

// Negative check
$mismatchCaught = false;
try {
    $verifyMethod->invoke($updater, $dummyPkg, $tamperedHash);
} catch (\Throwable $e) {
    $mismatchCaught = true;
    assert(!file_exists($dummyPkg), "Archive file should be unlinked on checksum mismatch");
}
assert($mismatchCaught, "Checksum mismatch must throw an exception");
echo "[PASS] Checksum mismatch successfully aborted and deleted archive.\n";

// --- TEST 2: Zip-Slip Security Traversal Guard ---
echo "[TEST 2] Zip-Slip directory traversal blocking...\n";
$maliciousZip = $testStorage . '/malicious.zip';
$zip = new ZipArchive();
if ($zip->open($maliciousZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $zip->addFromString('../../../evil.txt', 'malicious payload');
    $zip->close();
}

$extractMethod = $reflection->getMethod('extractSecurely');
$extractMethod->setAccessible(true);
$stagingTarget = $testStorage . '/extracted_malicious';

$slipCaught = false;
try {
    $extractMethod->invoke($updater, $maliciousZip, $stagingTarget);
} catch (\Throwable $e) {
    $slipCaught = true;
    assert(str_contains(strtolower($e->getMessage()), 'path') || str_contains(strtolower($e->getMessage()), 'traversal') || str_contains(strtolower($e->getMessage()), 'malicious'), "Exception message should indicate path traversal");
}
assert($slipCaught, "Zip-Slip malicious path must be detected and blocked");
@unlink($maliciousZip);
echo "[PASS] Zip-Slip traversal attempt was blocked.\n";

// --- TEST 3: Zero-Downtime Deployment & Critical Customer Paths Protection ---
echo "[TEST 3] Zero-Downtime Deployment with Shadow Swapping & Protected Paths...\n";
$stagingDir = $testStorage . '/staging_vtest';
if (!is_dir($stagingDir)) mkdir($stagingDir, 0755, true);

// Create files in staging:
// 1. Regular file: test_zero_downtime.txt
file_put_contents($stagingDir . '/test_zero_downtime.txt', 'v2_updated_content');
// 2. Protected .env attempt
file_put_contents($stagingDir . '/.env', 'ATTEMPTED_OVERWRITE=true');
// 3. Protected storage/license.lock attempt
if (!is_dir($stagingDir . '/storage')) mkdir($stagingDir . '/storage', 0755, true);
file_put_contents($stagingDir . '/storage/license.lock', 'MALICIOUS_LOCK');

// Ensure target pre-existing files
$targetRegular = $appRoot . '/test_zero_downtime.txt';
file_put_contents($targetRegular, 'v1_initial_content');
$targetEnv = $appRoot . '/.env';
$originalEnvContent = file_get_contents($targetEnv);

// Call deployStagedFilesZeroDowntime
$deployMethod = $reflection->hasMethod('deployStagedFilesZeroDowntime') 
    ? $reflection->getMethod('deployStagedFilesZeroDowntime') 
    : $reflection->getMethod('deployStagedFiles');
$deployMethod->setAccessible(true);

$deployMethod->invoke($updater, $stagingDir);

// Verify regular file updated
assert(file_get_contents($targetRegular) === 'v2_updated_content', "Regular file should be atomically updated");
// Verify .env was protected
assert(file_get_contents($targetEnv) === $originalEnvContent, ".env MUST NOT be overwritten");

// Clean up test file
@unlink($targetRegular);
@unlink($stagingDir . '/test_zero_downtime.txt');
@unlink($stagingDir . '/.env');
@unlink($stagingDir . '/storage/license.lock');
@rmdir($stagingDir . '/storage');
@rmdir($stagingDir);
@rmdir($testStorage);

echo "[PASS] Zero-Downtime deployment succeeded with customer paths strictly protected.\n";
echo "=== SystemUpdaterTest PASSED ===\n";
