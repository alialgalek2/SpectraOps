<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../database/Migrator.php';

use Database\Migrator;

echo "=== Running MigratorTest ===\n";

$pdo = \App\Config\Database::getConnection();

// Create a temporary test table
$pdo->exec("DROP TABLE IF EXISTS test_migrator_table");
$pdo->exec("CREATE TABLE test_migrator_table (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    old_code VARCHAR(50) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$migrator = new Migrator($pdo);

// Test 1: safeAddColumn adds a new column
echo "[TEST 1] safeAddColumn adds column successfully...\n";
$migrator->safeAddColumn('test_migrator_table', 'new_code', 'VARCHAR(50) NULL');

$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_migrator_table' AND COLUMN_NAME = 'new_code'");
$stmt->execute();
assert((int)$stmt->fetchColumn() === 1, "Column 'new_code' should exist in test_migrator_table");
echo "[PASS] Column created successfully.\n";

// Test 2: safeAddColumn is idempotent (does not fail when called again)
echo "[TEST 2] safeAddColumn is idempotent...\n";
$migrator->safeAddColumn('test_migrator_table', 'new_code', 'VARCHAR(50) NULL');
assert(true, "Idempotent call should not throw");
echo "[PASS] Idempotent safeAddColumn succeeded.\n";

// Test 3: backfillDataQuietly backfills in chunks
echo "[TEST 3] backfillDataQuietly backfills data across rows...\n";
// Insert test records
for ($i = 1; $i <= 25; $i++) {
    $pdo->exec("INSERT INTO test_migrator_table (name, old_code) VALUES ('Item {$i}', 'CODE-{$i}')");
}

$migrator->backfillDataQuietly('test_migrator_table', 'old_code', 'new_code', 10);

$checkStmt = $pdo->query("SELECT COUNT(*) FROM test_migrator_table WHERE new_code IS NULL OR new_code != old_code");
$mismatchCount = (int)$checkStmt->fetchColumn();
assert($mismatchCount === 0, "All rows should have new_code matching old_code");
echo "[PASS] backfillDataQuietly successfully populated {$i} rows with zero mismatches.\n";

// Clean up
$pdo->exec("DROP TABLE IF EXISTS test_migrator_table");
echo "=== MigratorTest PASSED ===\n";
