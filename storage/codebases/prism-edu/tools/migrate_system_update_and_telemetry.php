<?php
require_once __DIR__ . '/../config/db.php';

echo "=== MIGRATING SYSTEM UPDATE & TELEMETRY SCHEMAS ===\n\n";

// 1. Table: system_version
$pdo->exec("CREATE TABLE IF NOT EXISTS system_version (
    version_id INT AUTO_INCREMENT PRIMARY KEY,
    version_number VARCHAR(32) NOT NULL,
    build_number INT NOT NULL,
    release_name VARCHAR(128) NOT NULL,
    release_notes TEXT NULL,
    migration_version INT NOT NULL DEFAULT 1,
    is_current TINYINT(1) DEFAULT 1,
    installed_by VARCHAR(100) DEFAULT 'System',
    installed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(32) DEFAULT 'applied'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// Seed baseline version if empty
$vCount = (int)$pdo->query("SELECT COUNT(*) FROM system_version")->fetchColumn();
if ($vCount === 0) {
    $stmt = $pdo->prepare("INSERT INTO system_version (version_number, build_number, release_name, release_notes, migration_version, is_current, installed_by) VALUES (?, ?, ?, ?, ?, 1, 'Initial Setup')");
    $stmt->execute([
        'v2.4.1',
        2410,
        'EduMaster Enterprise LTS',
        'Stable core foundation with Academic Calendar, Universal Navbar, Sortable Dashboard, and Dual-Language (AR/EN) support.',
        1
    ]);
    echo "[OK] Initialized baseline system_version record (v2.4.1 Build 2410).\n";
} else {
    echo "[INFO] system_version table already seeded ($vCount records).\n";
}

// 2. Table: system_telemetry_logs
$pdo->exec("CREATE TABLE IF NOT EXISTS system_telemetry_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    error_hash VARCHAR(64) NOT NULL UNIQUE,
    error_code VARCHAR(64) NOT NULL,
    error_level VARCHAR(32) NOT NULL,
    module_name VARCHAR(64) NOT NULL DEFAULT 'Core',
    sanitized_message TEXT NOT NULL,
    sanitized_trace MEDIUMTEXT NULL,
    file_path VARCHAR(255) NULL,
    line_number INT NULL,
    php_version VARCHAR(32) NULL,
    os_platform VARCHAR(64) NULL,
    occurrence_count INT DEFAULT 1,
    first_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sync_status VARCHAR(32) DEFAULT 'staged',
    synced_at DATETIME NULL,
    INDEX idx_sync_status (sync_status),
    INDEX idx_module (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

echo "[OK] system_telemetry_logs table created successfully.\n";

// 3. Create migrations tracking table if not exists
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id INT AUTO_INCREMENT PRIMARY KEY,
    migration_name VARCHAR(255) NOT NULL UNIQUE,
    batch_version INT NOT NULL,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

echo "[OK] schema_migrations table created successfully.\n";

echo "\n=== MIGRATION COMPLETED ===\n";
