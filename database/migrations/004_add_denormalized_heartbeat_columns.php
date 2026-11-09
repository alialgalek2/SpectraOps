<?php
declare(strict_types=1);

/**
 * Migration 004: Add Denormalized Heartbeat Columns to central_deployments
 * 
 * Remediation for M2: Denormalizes latest telemetry values directly onto
 * central_deployments to eliminate expensive full-table aggregate joins on dashboard loads.
 */

$sqlitePaths = [
    dirname(__DIR__) . '/fleet.sqlite',
    dirname(__DIR__, 2) . '/central_fleet_db',
    dirname(__DIR__, 2) . '/public/api/v1/central_fleet_db'
];

$columnsToAddSqlite = [
    'memory_usage_mb' => 'REAL DEFAULT 0',
    'db_latency_ms' => 'REAL DEFAULT 0',
    'storage_free_gb' => 'REAL DEFAULT 0',
    'active_users_24h' => 'INTEGER DEFAULT 0',
    'load_avg' => 'REAL DEFAULT NULL'
];

foreach ($sqlitePaths as $p) {
    if (file_exists($p)) {
        try {
            $pdo = new PDO('sqlite:' . $p, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $cols = $pdo->query('PRAGMA table_info(central_deployments)')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($cols, 'name');

            foreach ($columnsToAddSqlite as $col => $definition) {
                if (!in_array($col, $names, true)) {
                    $pdo->exec("ALTER TABLE central_deployments ADD COLUMN {$col} {$definition}");
                    echo "[SQLite] Added {$col} to central_deployments in {$p}\n";
                }
            }
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
    $columnsToAddMysql = [
        'memory_usage_mb' => 'FLOAT DEFAULT 0',
        'db_latency_ms' => 'FLOAT DEFAULT 0',
        'storage_free_gb' => 'FLOAT DEFAULT 0',
        'active_users_24h' => 'INT DEFAULT 0',
        'load_avg' => 'FLOAT DEFAULT NULL'
    ];

    foreach ($columnsToAddMysql as $col => $definition) {
        $stmt = $mysql->query("SHOW COLUMNS FROM central_deployments LIKE '{$col}'");
        if ($stmt->rowCount() === 0) {
            $mysql->exec("ALTER TABLE central_deployments ADD COLUMN {$col} {$definition}");
            echo "[MySQL] Added column '{$col}' to central_deployments.\n";
        }
    }
} catch (\Throwable $e) {
    echo "[MySQL Info] " . $e->getMessage() . "\n";
}
