<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../database/Migrator.php';
require_once __DIR__ . '/../src/Services/SystemUpdater.php';

use App\Services\SystemUpdater;
use Database\Migrator;

echo "=== Running ZeroDowntimeIntegrationTest ===\n";

$appRoot = dirname(__DIR__);
$updater = new SystemUpdater($appRoot);

// Step 1: Create a mock release package
$mockDir = $appRoot . '/storage/updates/tmp/mock_release_package';
if (!is_dir($mockDir)) mkdir($mockDir, 0755, true);

// Add a test file
file_put_contents($mockDir . '/test_patch_file.txt', 'Zero-Downtime Patch Active 2.5.1');

// Add a test migration under database/migrations in the archive
$mockMigDir = $mockDir . '/database/migrations';
if (!is_dir($mockMigDir)) mkdir($mockMigDir, 0755, true);

file_put_contents($mockMigDir . '/2026_09_19_000001_test_patch_migration.php', '<?php
// Test migration executed during atomic update
$pdo = \App\Config\Database::getConnection();
$pdo->exec("CREATE TABLE IF NOT EXISTS test_patch_ledger (id INT AUTO_INCREMENT PRIMARY KEY, note VARCHAR(100)) ENGINE=InnoDB;");
');

// Zip package
$zipFile = $appRoot . '/storage/updates/tmp/update_2.5.1.zip';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
    $zip->addFile($mockDir . '/test_patch_file.txt', 'test_patch_file.txt');
    $zip->addFile($mockMigDir . '/2026_09_19_000001_test_patch_migration.php', 'database/migrations/2026_09_19_000001_test_patch_migration.php');
    $zip->close();
}

$checksum = hash_file('sha256', $zipFile);
echo "[TEST 1] Applying verified package via SystemUpdater::applyUpdate()...\n";

$result = $updater->applyUpdate($zipFile, $checksum, '2.5.1');
assert($result['success'] === true, "applyUpdate should succeed: " . ($result['error'] ?? ''));
echo "[PASS] applyUpdate succeeded: {$result['message']}\n";

// Verify file was deployed
$targetFile = $appRoot . '/test_patch_file.txt';
assert(file_exists($targetFile), "test_patch_file.txt should exist in application root");
assert(file_get_contents($targetFile) === 'Zero-Downtime Patch Active 2.5.1', "Content should match patched content");
echo "[PASS] File deployed with exact contents.\n";

// Verify migration ran
$pdo = \App\Config\Database::getConnection();
$chkTable = $pdo->query("SHOW TABLES LIKE 'test_patch_ledger'")->fetch();
assert(!empty($chkTable), "Table test_patch_ledger should have been created by the migration");
echo "[PASS] Database migration executed and verified in schema.\n";

// Clean up deployed test artifacts
@unlink($targetFile);
@unlink($appRoot . '/database/migrations/2026_09_19_000001_test_patch_migration.php');
$pdo->exec("DROP TABLE IF EXISTS test_patch_ledger");
$pdo->exec("DELETE FROM schema_migrations WHERE migration_name = '2026_09_19_000001_test_patch_migration.php'");

// Clean up mock source
@unlink($mockDir . '/test_patch_file.txt');
@unlink($mockMigDir . '/2026_09_19_000001_test_patch_migration.php');
@rmdir($mockMigDir);
@rmdir($mockDir);

echo "=== ZeroDowntimeIntegrationTest PASSED ===\n";
