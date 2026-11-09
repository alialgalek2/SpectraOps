<?php
declare(strict_types=1);

/**
 * Migration 006: Dynamic Product Codes, Multi-Tier Licenses, and Deployment Bindings
 * 
 * Creates central_licenses table, adds product_code to central_products,
 * and adds license_id & hardware_hash bindings to central_deployments.
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

            // 1. Add product_code column to central_products if missing
            $cols = $pdo->query("PRAGMA table_info(central_products)")->fetchAll(PDO::FETCH_ASSOC);
            $hasProductCode = false;
            foreach ($cols as $col) {
                if ($col['name'] === 'product_code') {
                    $hasProductCode = true;
                    break;
                }
            }

            if (!$hasProductCode) {
                $pdo->exec("ALTER TABLE central_products ADD COLUMN product_code TEXT");
                echo "[SQLite] Added product_code column to central_products on {$p}\n";
            }
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_products_code ON central_products(product_code)");

            // Pre-seed product codes
            $pdo->exec("UPDATE central_products SET product_code = 'PRISM' WHERE product_slug = 'prism-edu' AND (product_code IS NULL OR product_code = '')");
            $pdo->exec("UPDATE central_products SET product_code = 'POS' WHERE product_slug = 'pos-pro' AND (product_code IS NULL OR product_code = '')");
            $pdo->exec("UPDATE central_products SET product_code = 'MED' WHERE product_slug = 'clinic-core' AND (product_code IS NULL OR product_code = '')");

            // 2. Create central_licenses table
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS central_licenses (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    product_id INTEGER NOT NULL,
                    license_key TEXT UNIQUE NOT NULL,
                    client_name TEXT NOT NULL,
                    client_email TEXT NOT NULL,
                    license_type TEXT DEFAULT 'perpetual',
                    max_seats INTEGER DEFAULT 0,
                    expires_at DATETIME DEFAULT NULL,
                    status TEXT DEFAULT 'unactivated',
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE
                );
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_licenses_key ON central_licenses(license_key)");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_licenses_prod ON central_licenses(product_id)");
            echo "[SQLite] Ensured central_licenses table exists on {$p}\n";

            // 3. Add license_id and hardware_hash to central_deployments if missing
            $depCols = $pdo->query("PRAGMA table_info(central_deployments)")->fetchAll(PDO::FETCH_ASSOC);
            $hasLicenseId = false;
            $hasHardwareHash = false;
            foreach ($depCols as $col) {
                if ($col['name'] === 'license_id') $hasLicenseId = true;
                if ($col['name'] === 'hardware_hash') $hasHardwareHash = true;
            }

            if (!$hasLicenseId) {
                $pdo->exec("ALTER TABLE central_deployments ADD COLUMN license_id INTEGER DEFAULT NULL");
                echo "[SQLite] Added license_id to central_deployments on {$p}\n";
            }
            if (!$hasHardwareHash) {
                $pdo->exec("ALTER TABLE central_deployments ADD COLUMN hardware_hash TEXT DEFAULT NULL");
                echo "[SQLite] Added hardware_hash to central_deployments on {$p}\n";
            }

        } catch (\Throwable $e) {
            echo "[SQLite Notice on {$p}] " . $e->getMessage() . "\n";
        }
    }
}

// MySQL Migration
try {
    // Try connecting with configured credentials or root default
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db;charset=utf8mb4';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';

    $mysql = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // 1. Add product_code to central_products if missing
    $checkCol = $mysql->query("SHOW COLUMNS FROM central_products LIKE 'product_code'")->fetchAll();
    if (empty($checkCol)) {
        $mysql->exec("ALTER TABLE central_products ADD COLUMN product_code VARCHAR(8) NULL DEFAULT NULL AFTER product_slug");
        echo "[MySQL] Added product_code column to central_products.\n";
    }

    // Pre-seed product codes
    $mysql->exec("UPDATE central_products SET product_code = 'PRISM' WHERE product_slug = 'prism-edu'");
    $mysql->exec("UPDATE central_products SET product_code = 'POS' WHERE product_slug = 'pos-pro'");
    $mysql->exec("UPDATE central_products SET product_code = 'MED' WHERE product_slug = 'clinic-core'");
    $mysql->exec("UPDATE central_products SET product_code = UPPER(SUBSTRING(product_slug, 1, 4)) WHERE product_code IS NULL OR product_code = ''");

    // Ensure unique index exists on product_code
    $checkIndex = $mysql->query("SHOW INDEX FROM central_products WHERE Column_name = 'product_code'")->fetchAll();
    if (empty($checkIndex)) {
        try {
            $mysql->exec("ALTER TABLE central_products MODIFY product_code VARCHAR(8) NOT NULL");
            $mysql->exec("ALTER TABLE central_products ADD UNIQUE INDEX uq_product_code (product_code)");
            echo "[MySQL] Added unique index uq_product_code.\n";
        } catch (\Throwable $ie) {
            echo "[MySQL Notice on index] " . $ie->getMessage() . "\n";
        }
    }

    // 2. Create central_licenses table
    $mysql->exec("
        CREATE TABLE IF NOT EXISTS central_licenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            license_key VARCHAR(64) UNIQUE NOT NULL,
            client_name VARCHAR(191) NOT NULL,
            client_email VARCHAR(191) NOT NULL,
            license_type ENUM('perpetual', 'subscription', 'trial') DEFAULT 'perpetual',
            max_seats INT DEFAULT 0,
            expires_at DATETIME DEFAULT NULL,
            status ENUM('unactivated', 'active', 'suspended', 'revoked') DEFAULT 'unactivated',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
            INDEX idx_key (license_key),
            INDEX idx_prod (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[MySQL] Ensured central_licenses table exists.\n";

    // 3. Add license_id and hardware_hash to central_deployments
    $depCols = $mysql->query("SHOW COLUMNS FROM central_deployments LIKE 'license_id'")->fetchAll();
    if (empty($depCols)) {
        $mysql->exec("ALTER TABLE central_deployments ADD COLUMN license_id INT DEFAULT NULL AFTER product_id");
        echo "[MySQL] Added license_id to central_deployments.\n";
    }

    $hwCols = $mysql->query("SHOW COLUMNS FROM central_deployments LIKE 'hardware_hash'")->fetchAll();
    if (empty($hwCols)) {
        $mysql->exec("ALTER TABLE central_deployments ADD COLUMN hardware_hash VARCHAR(64) DEFAULT NULL AFTER deployment_domain");
        echo "[MySQL] Added hardware_hash to central_deployments.\n";
    }

    echo "[MySQL] Migration 006 completed successfully.\n";
} catch (\Throwable $e) {
    echo "[MySQL Info] " . $e->getMessage() . "\n";
}
