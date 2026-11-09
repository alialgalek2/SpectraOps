<?php
declare(strict_types=1);

/**
 * Migration 010: Add VendPulse Offline Licenses Table and Seed Product
 */

return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS central_vendpulse_licenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                license_id TEXT UNIQUE NOT NULL,
                client_name TEXT NOT NULL,
                machine_hwid TEXT DEFAULT NULL,
                tier TEXT NOT NULL,
                allowed_modules TEXT NOT NULL,
                max_counters INTEGER NOT NULL DEFAULT 1,
                issued_at INTEGER NOT NULL,
                expires_at INTEGER DEFAULT NULL,
                signature TEXT NOT NULL,
                license_bundle TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_license_id ON central_vendpulse_licenses(license_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_status ON central_vendpulse_licenses(status);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_tier ON central_vendpulse_licenses(tier);");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS central_vendpulse_licenses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                license_id VARCHAR(64) UNIQUE NOT NULL,
                client_name VARCHAR(191) NOT NULL,
                machine_hwid VARCHAR(128) DEFAULT NULL,
                tier VARCHAR(32) NOT NULL,
                allowed_modules TEXT NOT NULL,
                max_counters INT NOT NULL DEFAULT 1,
                issued_at BIGINT NOT NULL,
                expires_at BIGINT DEFAULT NULL,
                signature TEXT NOT NULL,
                license_bundle LONGTEXT NOT NULL,
                status ENUM('active', 'suspended', 'revoked') NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_vp_license_id (license_id),
                INDEX idx_vp_status (status),
                INDEX idx_vp_tier (tier)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }

    // Ensure VendPulse product exists in central_products
    $stmt = $pdo->prepare("SELECT id FROM central_products WHERE product_slug = 'vendpulse' OR product_code = 'VP'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $ins = $pdo->prepare("
            INSERT INTO central_products (product_slug, product_code, product_name, description, latest_version)
            VALUES ('vendpulse', 'VP', 'VendPulse POS Engine', 'Standalone retail & supermarket POS with offline cryptographic licensing', '1.0.0')
        ");
        $ins->execute();
    }
};
