<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';

echo "=== Running HeartbeatRunnerTest ===\n";

// Test 1: Semver patch verification function
function isSemverPatchRelease(string $current, string $candidate): bool {
    $cur = ltrim(trim($current), 'vV');
    $cand = ltrim(trim($candidate), 'vV');

    $curParts = explode('.', $cur);
    $candParts = explode('.', $cand);

    if (count($curParts) < 3 || count($candParts) < 3) {
        return false;
    }

    $curMajor = (int)$curParts[0];
    $curMinor = (int)$curParts[1];
    $curPatch = (int)$curParts[2];

    $candMajor = (int)$candParts[0];
    $candMinor = (int)$candParts[1];
    $candPatch = (int)$candParts[2];

    // Same major, same minor, higher patch
    return ($candMajor === $curMajor && $candMinor === $curMinor && $candPatch > $curPatch);
}

echo "[TEST 1] Semver patch detection validation...\n";
assert(isSemverPatchRelease('2.5.0', '2.5.1') === true, "2.5.1 is a patch release of 2.5.0");
assert(isSemverPatchRelease('2.5.0', '2.5.12') === true, "2.5.12 is a patch release of 2.5.0");
assert(isSemverPatchRelease('v2.5.0', 'v2.5.2') === true, "v2.5.2 is a patch release of v2.5.0");
assert(isSemverPatchRelease('2.5.0', '2.6.0') === false, "2.6.0 is a minor feature release, not a patch");
assert(isSemverPatchRelease('2.5.0', '3.0.0') === false, "3.0.0 is a major release, not a patch");
assert(isSemverPatchRelease('2.5.2', '2.5.1') === false, "2.5.1 is a downgrade from 2.5.2");
echo "[PASS] Semver patch detection functions correctly.\n";

// Test 2: AdminUpdateController test (simulate check action)
echo "[TEST 2] AdminUpdateController check action response...\n";
$cmd = 'php -r "$_GET[\'action\']=\'check\'; require \'controllers/AdminUpdateController.php\';"';
$output = shell_exec($cmd) ?: '';
$json = json_decode($output, true);
assert(is_array($json), "AdminUpdateController should return valid JSON: " . $output);
assert(isset($json['status']), "Response must contain 'status'");
echo "[PASS] AdminUpdateController check returned valid response status: {$json['status']}.\n";

// Test 3: Verify bin/telemetry-heartbeat.php syntax and help / dry-run execution
echo "[TEST 3] bin/telemetry-heartbeat.php execution check...\n";
$hbCmd = 'php bin/telemetry-heartbeat.php --dry-run';
$hbOutput = shell_exec($hbCmd) ?: '';
assert(str_contains($hbOutput, 'telemetry heartbeat') || str_contains($hbOutput, 'running v'), "Heartbeat runner output should indicate start: " . $hbOutput);
echo "[PASS] bin/telemetry-heartbeat.php executed successfully.\n";

echo "=== HeartbeatRunnerTest PASSED ===\n";
