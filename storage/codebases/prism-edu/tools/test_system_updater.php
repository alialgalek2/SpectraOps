<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING SYSTEM UPDATE ARCHITECTURE ===\n\n";

// 1. Current Version
$current = SystemUpdater::getCurrentVersion();
echo "Current version: {$current['version_number']} (Build {$current['build_number']}) - {$current['release_name']}\n";
assert(!empty($current['version_number']), "Version number should not be empty");
assert(is_numeric($current['build_number']), "Build number should be numeric");
echo "[PASS] Baseline Version Tracking verified.\n\n";

// 2. Remote Version Polling
$updateCheck = SystemUpdater::checkForUpdates(true);
echo "Update Check Result:\n";
echo "Has Update: " . ($updateCheck['has_update'] ? 'YES' : 'NO') . "\n";
echo "Latest Version: {$updateCheck['latest_version']} (Build {$updateCheck['latest_build']})\n";
assert($updateCheck['success'] === true, "Update check should be successful");
assert($updateCheck['latest_build'] >= $current['build_number'], "Latest build should be >= current build");
echo "[PASS] Remote Version Polling verified.\n\n";

// 3. Migration Discovery
$migrations = SystemUpdater::getMigrationFiles();
echo "Discovered Migration Files (" . count($migrations) . "):\n";
foreach ($migrations as $name => $path) {
    echo " • $name\n";
}
assert(count($migrations) > 0, "At least 1 migration script should be discovered");
echo "[PASS] Migration Discovery verified.\n\n";

// 4. Migration Execution
$migResult = SystemUpdater::runPendingMigrations();
echo "Migration Execution Result: " . json_encode($migResult) . "\n";
assert($migResult['success'] === true, "Migrations run should succeed");
echo "[PASS] Automated Migration Execution verified.\n\n";

// 5. Apply Update
$deployResult = SystemUpdater::applyUpdate(
    'v2.5.0',
    2500,
    'EduMaster Nova Patch Release',
    ['Master System Update Architecture', 'Privacy-First Telemetry Engine'],
    'Test Suite Runner'
);

echo "Deploy Result: " . json_encode($deployResult) . "\n";
assert($deployResult['success'] === true, "Deploy update should succeed");

// Re-verify current version after upgrade
$newVersion = SystemUpdater::getCurrentVersion();
echo "Updated current version: {$newVersion['version_number']} (Build {$newVersion['build_number']})\n";
assert($newVersion['version_number'] === 'v2.5.0', "Active version should now be v2.5.0");
assert((int)$newVersion['build_number'] === 2500, "Active build should now be 2500");
echo "[PASS] Automated Patch Deployment & Version Bumping verified.\n\n";

// 6. Version History
$history = SystemUpdater::getVersionHistory();
echo "Version History entries: " . count($history) . "\n";
assert(count($history) >= 2, "History should contain both baseline and upgraded versions");
echo "[PASS] Version History & Audit Trail verified.\n\n";

echo "=== ALL SYSTEM UPDATER TESTS PASSED ===\n";
