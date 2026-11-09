<?php
declare(strict_types=1);

/**
 * Migration 011: Add System Usage Mode to VendPulse Offline Licenses Table
 */

return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        try {
            $cols = array_column($pdo->query("PRAGMA table_info(central_vendpulse_licenses)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            if (!empty($cols) && !in_array('usage_mode', $cols, true)) {
                $pdo->exec("ALTER TABLE central_vendpulse_licenses ADD COLUMN usage_mode TEXT NOT NULL DEFAULT 'RETAIL_SHOP';");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_usage_mode ON central_vendpulse_licenses(usage_mode);");
            }
        } catch (\Throwable $e) {
            error_log("[Migration 011 SQLite Error] " . $e->getMessage());
        }
    } else {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM central_vendpulse_licenses LIKE 'usage_mode'");
            if ($stmt && $stmt->rowCount() === 0) {
                $pdo->exec("ALTER TABLE central_vendpulse_licenses ADD COLUMN usage_mode ENUM('RETAIL_SHOP', 'SUPERMARKET', 'RESTAURANT') NOT NULL DEFAULT 'RETAIL_SHOP' AFTER tier;");
                try {
                    $pdo->exec("ALTER TABLE central_vendpulse_licenses ADD INDEX idx_vp_usage_mode (usage_mode);");
                } catch (\Throwable) {}
            }
        } catch (\Throwable $e) {
            error_log("[Migration 011 MySQL Error] " . $e->getMessage());
        }
    }
};
