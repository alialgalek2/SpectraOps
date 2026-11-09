<?php
/**
 * Test Update Check Endpoint: Classification of Patch vs Major Releases
 */

require_once __DIR__ . '/../src/Database.php';

echo "========================================================\n";
echo "Testing Update Check SemVer Classification\n";
echo "========================================================\n\n";

function runSemVerClassification(string $rawCurrentVer, string $rawLatestVer): array {
    $cleanCurrentVer = ltrim($rawCurrentVer, 'vV');
    $cleanLatestVer  = ltrim($rawLatestVer, 'vV');

    // SemVer comparison
    $isNewer = version_compare($cleanLatestVer, $cleanCurrentVer, '>');

    if ($isNewer) {
        // Parse SemVer parts: [MAJOR, MINOR, PATCH]
        $currParts = array_map('intval', explode('.', $cleanCurrentVer));
        $lateParts = array_map('intval', explode('.', $cleanLatestVer));

        // Pad with zeroes if missing parts
        while (count($currParts) < 3) $currParts[] = 0;
        while (count($lateParts) < 3) $lateParts[] = 0;

        // Check if MAJOR or MINOR bumped
        $isMajorOrMinor = ($lateParts[0] > $currParts[0]) || ($lateParts[1] > $currParts[1]);

        $updateType = $isMajorOrMinor ? 'major' : 'patch';
        $requiresManualApproval = $isMajorOrMinor;

        return [
            'update_available'         => true,
            'update_type'              => $updateType,
            'requires_manual_approval' => $requiresManualApproval,
            'latest_version'           => $cleanLatestVer,
            'current_version'          => $cleanCurrentVer
        ];
    } else {
        return [
            'update_available' => false,
            'latest_version'   => $cleanLatestVer,
            'current_version'  => $cleanCurrentVer,
            'message'          => "System is running the latest release (v{$cleanCurrentVer})."
        ];
    }
}

// Case 1: Patch bump (1.0.0 -> 1.0.1)
$res = runSemVerClassification('1.0.0', '1.0.1');
assert($res['update_available'] === true, "Case 1 should have update_available = true");
assert($res['update_type'] === 'patch', "Case 1 should be 'patch'");
assert($res['requires_manual_approval'] === false, "Case 1 requires_manual_approval should be false");
echo " [PASS] Patch bump (1.0.0 -> 1.0.1) classified as patch (silent)\n";

// Case 2: Minor bump (1.0.0 -> 1.1.0)
$res = runSemVerClassification('1.0.0', '1.1.0');
assert($res['update_available'] === true, "Case 2 should have update_available = true");
assert($res['update_type'] === 'major', "Case 2 should be 'major'");
assert($res['requires_manual_approval'] === true, "Case 2 requires_manual_approval should be true");
echo " [PASS] Minor bump (1.0.0 -> 1.1.0) classified as major (manual approval)\n";

// Case 3: Major bump (1.5.2 -> 2.0.0)
$res = runSemVerClassification('v1.5.2', 'v2.0.0');
assert($res['update_available'] === true, "Case 3 should have update_available = true");
assert($res['update_type'] === 'major', "Case 3 should be 'major'");
assert($res['requires_manual_approval'] === true, "Case 3 requires_manual_approval should be true");
echo " [PASS] Major bump (v1.5.2 -> v2.0.0) classified as major (manual approval)\n";

// Case 4: No update (2.0.0 -> 2.0.0)
$res = runSemVerClassification('2.0.0', '2.0.0');
assert($res['update_available'] === false, "Case 4 should have update_available = false");
echo " [PASS] Same version (2.0.0 -> 2.0.0) returns update_available = false\n";

// Case 5: Client newer (2.0.1 -> 2.0.0)
$res = runSemVerClassification('2.0.1', '2.0.0');
assert($res['update_available'] === false, "Case 5 should have update_available = false");
echo " [PASS] Newer client version returns update_available = false\n";

echo "\n========================================================\n";
echo "SUCCESS: All Update Classification Tests Passed!\n";
echo "========================================================\n";
