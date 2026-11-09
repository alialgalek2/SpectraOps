<?php
declare(strict_types=1);

/**
 * Migration: 001_add_central_releases_and_pairing.php
 * 
 * Phase 1: Database Schema Update on Fleet Command Server
 * 1. Creates central_releases for versioned software update distribution (.zip packages)
 * 2. Updates central_deployments with paired_at and is_active for reverse registration / pairing
 */

echo "=== Running Phase 1 Fleet Schema Migration ===\n";

// 1. Migrate MySQL central_fleet_db
try {
    $mysqlPdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "[MySQL] Connected to central_fleet_db successfully.\n";

    // 1.1 Create central_releases table
    $sqlCreateReleases = <<<SQL
CREATE TABLE IF NOT EXISTS central_releases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    version VARCHAR(32) NOT NULL,
    changelog TEXT DEFAULT NULL,
    package_filename VARCHAR(255) NOT NULL,
    package_checksum VARCHAR(64) NOT NULL, -- SHA-256 for download verification
    is_active TINYINT(1) DEFAULT 1,
    released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_prod_ver (product_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    $mysqlPdo->exec($sqlCreateReleases);
    echo "[MySQL] Table 'central_releases' created/verified.\n";

    // 1.2 Alter central_deployments to add paired_at and is_active (check if columns exist first)
    $stmt = $mysqlPdo->query("SHOW COLUMNS FROM central_deployments LIKE 'paired_at'");
    if ($stmt->rowCount() === 0) {
        $mysqlPdo->exec("ALTER TABLE central_deployments ADD COLUMN paired_at DATETIME DEFAULT NULL");
        echo "[MySQL] Column 'paired_at' added to central_deployments.\n";
    } else {
        echo "[MySQL] Column 'paired_at' already exists on central_deployments.\n";
    }

    $stmt = $mysqlPdo->query("SHOW COLUMNS FROM central_deployments LIKE 'is_active'");
    if ($stmt->rowCount() === 0) {
        $mysqlPdo->exec("ALTER TABLE central_deployments ADD COLUMN is_active TINYINT(1) DEFAULT 1");
        echo "[MySQL] Column 'is_active' added to central_deployments.\n";
    } else {
        echo "[MySQL] Column 'is_active' already exists on central_deployments.\n";
    }

} catch (\Throwable $e) {
    echo "[MySQL ERROR] " . $e->getMessage() . "\n";
}

// 2. Migrate SQLite fleet.sqlite databases for local dev/testing
$sqlitePaths = [
    __DIR__ . '/../fleet.sqlite',
    dirname(__DIR__) . '/central_fleet_db',
    dirname(__DIR__) . '/public/api/v1/central_fleet_db'
];

foreach ($sqlitePaths as $sqlitePath) {
    if (!file_exists($sqlitePath)) {
        continue;
    }
    try {
        $sqlitePdo = new PDO('sqlite:' . $sqlitePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        echo "[SQLite] Connected to $sqlitePath\n";

        $sqliteCreateReleases = <<<SQL
CREATE TABLE IF NOT EXISTS central_releases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_id INTEGER NOT NULL,
    version TEXT NOT NULL,
    changelog TEXT DEFAULT NULL,
    package_filename TEXT NOT NULL,
    package_checksum TEXT NOT NULL,
    is_active INTEGER DEFAULT 1,
    released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
    UNIQUE(product_id, version)
);
SQL;
        $sqlitePdo->exec($sqliteCreateReleases);
        echo "[SQLite] Table 'central_releases' created/verified in $sqlitePath.\n";

        // Check SQLite columns on central_deployments
        $cols = $sqlitePdo->query("PRAGMA table_info(central_deployments)")->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_column($cols, 'name');

        if (!in_array('paired_at', $colNames, true)) {
            $sqlitePdo->exec("ALTER TABLE central_deployments ADD COLUMN paired_at DATETIME DEFAULT NULL");
            echo "[SQLite] Column 'paired_at' added to central_deployments in $sqlitePath.\n";
        }
        if (!in_array('is_active', $colNames, true)) {
            $sqlitePdo->exec("ALTER TABLE central_deployments ADD COLUMN is_active INTEGER DEFAULT 1");
            echo "[SQLite] Column 'is_active' added to central_deployments in $sqlitePath.\n";
        }
    } catch (\Throwable $e) {
        echo "[SQLite ERROR in $sqlitePath] " . $e->getMessage() . "\n";
    }
}

echo "=== Migration Complete ===\n";
