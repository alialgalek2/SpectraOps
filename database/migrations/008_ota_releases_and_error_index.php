<?php
declare(strict_types=1);

/**
 * Migration 008: Ensure central_releases table and error logs composite index
 * 
 * 1. Ensures table central_releases exists with:
 *    - id INT AUTO_INCREMENT PRIMARY KEY
 *    - product_id INT NOT NULL (FK -> central_products(id) ON DELETE CASCADE)
 *    - version VARCHAR(32) NOT NULL
 *    - changelog TEXT DEFAULT NULL
 *    - package_filename VARCHAR(255) NOT NULL
 *    - package_checksum VARCHAR(64) NOT NULL (SHA-256)
 *    - is_active TINYINT(1) DEFAULT 1
 *    - released_at DATETIME DEFAULT CURRENT_TIMESTAMP
 *    - UNIQUE KEY uq_product_version (product_id, version)
 * 2. Ensures central_error_logs has index on (deployment_id, error_level)
 */

$sqlitePaths = [
    dirname(__DIR__) . '/fleet.sqlite',
    dirname(__DIR__, 2) . '/central_fleet_db',
    dirname(__DIR__, 2) . '/public/api/v1/central_fleet_db'
];

foreach ($sqlitePaths as $p) {
    if (file_exists($p)) {
        try {
            $pdo = new PDO('sqlite:' . $p, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->exec("PRAGMA foreign_keys = ON;");

            // 1. Ensure central_releases table exists
            $pdo->exec("
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
            ");
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_product_version ON central_releases(product_id, version);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_releases_prod_ver ON central_releases(product_id, version);");

            // 2. Ensure central_error_logs has index on (deployment_id, error_level)
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_error_logs_deploy_level ON central_error_logs(deployment_id, error_level);");

            echo "[SQLite] Ensured central_releases and idx_error_logs_deploy_level on {$p}\n";
        } catch (\Throwable $e) {
            echo "[SQLite Notice on {$p}] " . $e->getMessage() . "\n";
        }
    }
}

// MySQL Migration
try {
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db;charset=utf8mb4';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';

    $mysql = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1. Ensure central_releases table exists
    $mysql->exec("
        CREATE TABLE IF NOT EXISTS central_releases (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            version VARCHAR(32) NOT NULL,
            changelog TEXT DEFAULT NULL,
            package_filename VARCHAR(255) NOT NULL,
            package_checksum VARCHAR(64) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
            UNIQUE KEY uq_product_version (product_id, version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    // Check if uq_product_version exists, if not add it
    $stmt = $mysql->query("SHOW INDEX FROM central_releases WHERE Key_name = 'uq_product_version'");
    if ($stmt->rowCount() === 0) {
        try {
            $mysql->exec("ALTER TABLE central_releases ADD UNIQUE KEY uq_product_version (product_id, version)");
            echo "[MySQL] Added unique key uq_product_version to central_releases.\n";
        } catch (\Throwable $ex) {
            echo "[MySQL Notice uq_product_version] " . $ex->getMessage() . "\n";
        }
    }

    // 2. Ensure central_error_logs has index on (deployment_id, error_level)
    $stmt = $mysql->query("SHOW INDEX FROM central_error_logs WHERE Key_name = 'idx_error_logs_deploy_level'");
    if ($stmt->rowCount() === 0) {
        try {
            $mysql->exec("ALTER TABLE central_error_logs ADD INDEX idx_error_logs_deploy_level (deployment_id, error_level)");
            echo "[MySQL] Added composite index idx_error_logs_deploy_level to central_error_logs.\n";
        } catch (\Throwable $ex) {
            echo "[MySQL Notice idx_error_logs_deploy_level] " . $ex->getMessage() . "\n";
        }
    } else {
        echo "[MySQL] Composite index idx_error_logs_deploy_level already exists.\n";
    }

    echo "[MySQL] Migration 008 completed successfully.\n";
} catch (\Throwable $e) {
    echo "[MySQL Notice] " . $e->getMessage() . "\n";
}
