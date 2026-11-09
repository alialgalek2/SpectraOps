<?php
declare(strict_types=1);

namespace FleetHub\Database;

use PDO;

class SchemaMigrator {
    public static function migrate(PDO $pdo): void {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            self::migrateSqlite($pdo);
        } else {
            self::migrateMysql($pdo);
        }
    }

    private static function migrateSqlite(PDO $pdo): void {
        // 1. central_licenses: lease_salt
        $licCols = array_column($pdo->query("PRAGMA table_info(central_licenses)")->fetchAll(), 'name');
        if (!in_array('lease_salt', $licCols, true)) {
            $pdo->exec("ALTER TABLE central_licenses ADD COLUMN lease_salt TEXT DEFAULT NULL;");
        }

        // Generate lease_salt for any licenses missing one
        $licenses = $pdo->query("SELECT id, lease_salt FROM central_licenses WHERE lease_salt IS NULL OR lease_salt = ''")->fetchAll();
        foreach ($licenses as $lic) {
            $salt = bin2hex(random_bytes(32));
            $upd = $pdo->prepare("UPDATE central_licenses SET lease_salt = ? WHERE id = ?");
            $upd->execute([$salt, $lic['id']]);
        }

        // 2. central_deployments: disk_free_gb, open_error_count
        $depCols = array_column($pdo->query("PRAGMA table_info(central_deployments)")->fetchAll(), 'name');
        if (!in_array('disk_free_gb', $depCols, true)) {
            $pdo->exec("ALTER TABLE central_deployments ADD COLUMN disk_free_gb REAL DEFAULT 0.0;");
            $pdo->exec("UPDATE central_deployments SET disk_free_gb = COALESCE(storage_free_gb, 0.0) WHERE disk_free_gb = 0.0;");
        }
        if (!in_array('open_error_count', $depCols, true)) {
            $pdo->exec("ALTER TABLE central_deployments ADD COLUMN open_error_count INTEGER DEFAULT 0;");
        }

        // 3. central_releases: release_type, is_silent_patch, resolves_error_id
        $relCols = array_column($pdo->query("PRAGMA table_info(central_releases)")->fetchAll(), 'name');
        if (!in_array('release_type', $relCols, true)) {
            $pdo->exec("ALTER TABLE central_releases ADD COLUMN release_type TEXT DEFAULT 'patch';");
        }
        if (!in_array('is_silent_patch', $relCols, true)) {
            $pdo->exec("ALTER TABLE central_releases ADD COLUMN is_silent_patch INTEGER DEFAULT 1;");
        }
        if (!in_array('resolves_error_id', $relCols, true)) {
            $pdo->exec("ALTER TABLE central_releases ADD COLUMN resolves_error_id INTEGER DEFAULT NULL;");
        }

        // Update open_error_count for deployments
        try {
            $deps = $pdo->query("SELECT id FROM central_deployments")->fetchAll(PDO::FETCH_COLUMN);
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM central_error_logs WHERE deployment_id = ? AND status = 'open'");
            $updDep = $pdo->prepare("UPDATE central_deployments SET open_error_count = ? WHERE id = ?");
            foreach ($deps as $did) {
                $countStmt->execute([$did]);
                $openCount = (int)$countStmt->fetchColumn();
                $updDep->execute([$openCount, $did]);
            }
        } catch (\Throwable) {}
    }

    private static function migrateMysql(PDO $pdo): void {
        // 1. central_licenses: lease_salt
        $licCols = array_column($pdo->query("SHOW COLUMNS FROM central_licenses")->fetchAll(), 'Field');
        if (!in_array('lease_salt', $licCols, true)) {
            $pdo->exec("ALTER TABLE central_licenses ADD COLUMN lease_salt VARCHAR(64) DEFAULT NULL AFTER status;");
        }

        // Generate lease_salt for existing licenses
        $licenses = $pdo->query("SELECT id, lease_salt FROM central_licenses WHERE lease_salt IS NULL OR lease_salt = ''")->fetchAll();
        foreach ($licenses as $lic) {
            $salt = bin2hex(random_bytes(32));
            $upd = $pdo->prepare("UPDATE central_licenses SET lease_salt = ? WHERE id = ?");
            $upd->execute([$salt, $lic['id']]);
        }

        // 2. central_deployments: api_ingest_token, disk_free_gb, open_error_count
        $depCols = array_column($pdo->query("SHOW COLUMNS FROM central_deployments")->fetchAll(), 'Field');
        if (!in_array('api_ingest_token', $depCols, true)) {
            $pdo->exec("ALTER TABLE central_deployments ADD COLUMN api_ingest_token VARCHAR(128) DEFAULT NULL AFTER api_ingest_token_hash;");
        }
        if (!in_array('disk_free_gb', $depCols, true)) {
            $pdo->exec("ALTER TABLE central_deployments ADD COLUMN disk_free_gb DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER storage_free_gb;");
            $pdo->exec("UPDATE central_deployments SET disk_free_gb = COALESCE(storage_free_gb, 0.00) WHERE disk_free_gb = 0.00;");
        }
        if (!in_array('open_error_count', $depCols, true)) {
            $pdo->exec("ALTER TABLE central_deployments ADD COLUMN open_error_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER active_users_24h;");
        }

        // 3. central_releases: release_type, is_silent_patch, resolves_error_id
        $relCols = array_column($pdo->query("SHOW COLUMNS FROM central_releases")->fetchAll(), 'Field');
        if (!in_array('release_type', $relCols, true)) {
            $pdo->exec("ALTER TABLE central_releases ADD COLUMN release_type ENUM('patch', 'minor', 'major') NOT NULL DEFAULT 'patch' AFTER version;");
        }
        if (!in_array('is_silent_patch', $relCols, true)) {
            $pdo->exec("ALTER TABLE central_releases ADD COLUMN is_silent_patch TINYINT(1) NOT NULL DEFAULT 1 AFTER release_type;");
        }
        if (!in_array('resolves_error_id', $relCols, true)) {
            $pdo->exec("ALTER TABLE central_releases ADD COLUMN resolves_error_id BIGINT DEFAULT NULL AFTER changelog;");
            try {
                $pdo->exec("ALTER TABLE central_releases ADD CONSTRAINT fk_release_error FOREIGN KEY (resolves_error_id) REFERENCES central_error_logs(id) ON DELETE SET NULL;");
            } catch (\Throwable) {}
        }

        // Update open_error_count for deployments
        try {
            $deps = $pdo->query("SELECT id FROM central_deployments")->fetchAll(PDO::FETCH_COLUMN);
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM central_error_logs WHERE deployment_id = ? AND status = 'open'");
            $updDep = $pdo->prepare("UPDATE central_deployments SET open_error_count = ? WHERE id = ?");
            foreach ($deps as $did) {
                $countStmt->execute([$did]);
                $openCount = (int)$countStmt->fetchColumn();
                $updDep->execute([$openCount, $did]);
            }
        } catch (\Throwable) {}
    }
}
