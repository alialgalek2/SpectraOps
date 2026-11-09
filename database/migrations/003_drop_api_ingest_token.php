<?php
declare(strict_types=1);

/**
 * Migration 003: Drop Plaintext api_ingest_token Column
 * 
 * Remediation for C4: Removes the plaintext token column from central_deployments
 * in both SQLite and MySQL databases to guarantee tokens exist exclusively as
 * SHA-256 hashes.
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
            $cols = $pdo->query('PRAGMA table_info(central_deployments)')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($cols, 'name');
            if (in_array('api_ingest_token', $names, true)) {
                $pdo->exec('ALTER TABLE central_deployments DROP COLUMN api_ingest_token');
                echo "[SQLite] Dropped column 'api_ingest_token' from $p\n";
            } else {
                echo "[SQLite] Column 'api_ingest_token' does not exist in $p\n";
            }
        } catch (\Throwable $e) {
            echo "[SQLite Notice on $p] " . $e->getMessage() . "\n";
        }
    }
}

// MySQL Migration
try {
    $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $stmt = $mysql->query("SHOW COLUMNS FROM central_deployments LIKE 'api_ingest_token'");
    if ($stmt->rowCount() > 0) {
        $mysql->exec("ALTER TABLE central_deployments DROP COLUMN api_ingest_token");
        echo "[MySQL] Column 'api_ingest_token' successfully dropped.\n";
    } else {
        echo "[MySQL] Column 'api_ingest_token' not found (already dropped).\n";
    }
} catch (\Throwable $e) {
    echo "[MySQL Info] " . $e->getMessage() . "\n";
}
