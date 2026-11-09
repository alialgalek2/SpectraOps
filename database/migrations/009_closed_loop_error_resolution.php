<?php
declare(strict_types=1);

/**
 * Migration 009: Closed-Loop Resolution Receipt (Receipt-Based Remediation)
 * 
 * 1. Adds status, resolved_by_version, and resolved_at to central_error_logs
 * 2. Adds resolves_error_id foreign key to central_releases
 * 3. Supports both SQLite and MySQL/MariaDB engines
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
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            $pdo->exec("PRAGMA foreign_keys = ON;");

            // 1. Migrate central_error_logs
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='central_error_logs'")->fetchAll();
            if (!empty($tables)) {
                $cols = array_column($pdo->query("PRAGMA table_info(central_error_logs)")->fetchAll(), 'name');
                
                if (!in_array('status', $cols)) {
                    $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN status TEXT DEFAULT 'open';");
                    echo "[SQLite] Added 'status' to central_error_logs on {$p}\n";
                }
                if (!in_array('resolved_by_version', $cols)) {
                    $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_by_version TEXT DEFAULT NULL;");
                    echo "[SQLite] Added 'resolved_by_version' to central_error_logs on {$p}\n";
                }
                if (!in_array('resolved_at', $cols)) {
                    $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_at DATETIME DEFAULT NULL;");
                    echo "[SQLite] Added 'resolved_at' to central_error_logs on {$p}\n";
                }

                // Sync existing resolved flag to status
                $pdo->exec("UPDATE central_error_logs SET status = 'resolved' WHERE resolved = 1 AND (status IS NULL OR status = 'open');");
            }

            // 2. Migrate central_releases
            $tablesRel = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='central_releases'")->fetchAll();
            if (!empty($tablesRel)) {
                $colsRel = array_column($pdo->query("PRAGMA table_info(central_releases)")->fetchAll(), 'name');
                if (!in_array('resolves_error_id', $colsRel)) {
                    $pdo->exec("ALTER TABLE central_releases ADD COLUMN resolves_error_id INTEGER DEFAULT NULL;");
                    echo "[SQLite] Added 'resolves_error_id' to central_releases on {$p}\n";
                }
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_releases_resolves_error ON central_releases(resolves_error_id);");
            }

            echo "[SQLite] Migration 009 completed on {$p}\n";
        } catch (\Throwable $e) {
            echo "[SQLite Notice on {$p}] " . $e->getMessage() . "\n";
        }
    }
}

// MySQL Migration
try {
    $envFile = dirname(__DIR__, 2) . '/.env';
    $host = '127.0.0.1';
    $port = '3306';
    $db = 'central_fleet_db';
    $user = 'root';
    $pass = '';

    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $k = trim($parts[0]);
                    $v = trim(trim($parts[1]), '"\'');
                    if ($k === 'DB_HOST') $host = $v;
                    if ($k === 'DB_PORT') $port = $v;
                    if ($k === 'DB_DATABASE') $db = $v;
                    if ($k === 'DB_USERNAME') $user = $v;
                    if ($k === 'DB_PASSWORD') $pass = $v;
                }
            }
        }
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $mysql = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 1. central_error_logs migration
    $stmt = $mysql->query("SHOW TABLES LIKE 'central_error_logs'");
    if ($stmt->rowCount() > 0) {
        $cols = array_column($mysql->query("SHOW COLUMNS FROM central_error_logs")->fetchAll(), 'Field');
        if (!in_array('status', $cols)) {
            $mysql->exec("ALTER TABLE central_error_logs ADD COLUMN status ENUM('open', 'patch_pending', 'resolved') DEFAULT 'open' AFTER error_level;");
            echo "[MySQL] Added 'status' column to central_error_logs.\n";
        }
        if (!in_array('resolved_by_version', $cols)) {
            $mysql->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_by_version VARCHAR(32) DEFAULT NULL AFTER status;");
            echo "[MySQL] Added 'resolved_by_version' column to central_error_logs.\n";
        }
        if (!in_array('resolved_at', $cols)) {
            $mysql->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER resolved_by_version;");
            echo "[MySQL] Added 'resolved_at' column to central_error_logs.\n";
        }
        $mysql->exec("UPDATE central_error_logs SET status = 'resolved' WHERE resolved = 1 AND (status IS NULL OR status = 'open');");
    }

    // 2. central_releases migration
    $stmtRel = $mysql->query("SHOW TABLES LIKE 'central_releases'");
    if ($stmtRel->rowCount() > 0) {
        $colsRel = array_column($mysql->query("SHOW COLUMNS FROM central_releases")->fetchAll(), 'Field');
        if (!in_array('resolves_error_id', $colsRel)) {
            $mysql->exec("ALTER TABLE central_releases ADD COLUMN resolves_error_id BIGINT DEFAULT NULL AFTER changelog;");
            echo "[MySQL] Added 'resolves_error_id' column to central_releases.\n";
        } else {
            $mysql->exec("ALTER TABLE central_releases MODIFY COLUMN resolves_error_id BIGINT DEFAULT NULL;");
        }

        try {
            // Check if constraint exists
            $checkFk = $mysql->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = '{$db}' AND TABLE_NAME = 'central_releases' AND CONSTRAINT_NAME = 'fk_release_error'")->fetchAll();
            if (empty($checkFk)) {
                $mysql->exec("ALTER TABLE central_releases ADD CONSTRAINT fk_release_error FOREIGN KEY (resolves_error_id) REFERENCES central_error_logs(id) ON DELETE SET NULL;");
                echo "[MySQL] Added foreign key constraint fk_release_error to central_releases.\n";
            }
        } catch (\Throwable $fkErr) {
            echo "[MySQL FK Notice] " . $fkErr->getMessage() . "\n";
        }
    }

    echo "[MySQL] Migration 009 completed successfully.\n";
} catch (\Throwable $e) {
    echo "[MySQL Notice] " . $e->getMessage() . "\n";
}
