<?php
declare(strict_types=1);

/**
 * Migration 005: Add Composite Index (status, last_heartbeat_at) on central_deployments
 * 
 * Remediation for M3: Provides high-efficiency index coverage for the stale-node
 * fleet monitor health sweep query.
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
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_deployments_status_heartbeat ON central_deployments(status, last_heartbeat_at)");
            echo "[SQLite] Created index idx_deployments_status_heartbeat on {$p}\n";
        } catch (\Throwable $e) {
            echo "[SQLite Notice on {$p}] " . $e->getMessage() . "\n";
        }
    }
}

// MySQL Migration
try {
    $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $stmt = $mysql->query("SHOW INDEX FROM central_deployments WHERE Key_name = 'idx_status_heartbeat'");
    if ($stmt->rowCount() === 0) {
        $mysql->exec("CREATE INDEX idx_status_heartbeat ON central_deployments (status, last_heartbeat_at)");
        echo "[MySQL] Created index idx_status_heartbeat on central_deployments.\n";
    }
} catch (\Throwable $e) {
    echo "[MySQL Info] " . $e->getMessage() . "\n";
}
