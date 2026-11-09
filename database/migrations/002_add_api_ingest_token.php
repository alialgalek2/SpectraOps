<?php
declare(strict_types=1);

$sqlitePaths = [
    dirname(__DIR__) . '/fleet.sqlite',
    dirname(__DIR__, 2) . '/central_fleet_db',
    dirname(__DIR__, 2) . '/public/api/v1/central_fleet_db'
];

foreach ($sqlitePaths as $p) {
    if (file_exists($p)) {
        try {
            $pdo = new PDO('sqlite:' . $p);
            $cols = $pdo->query('PRAGMA table_info(central_deployments)')->fetchAll(PDO::FETCH_ASSOC);
            $names = array_column($cols, 'name');
            if (!in_array('api_ingest_token', $names, true)) {
                $pdo->exec('ALTER TABLE central_deployments ADD COLUMN api_ingest_token TEXT DEFAULT NULL');
                echo "Added api_ingest_token to $p\n";
            } else {
                echo "api_ingest_token already exists in $p\n";
            }
        } catch (Exception $e) {
            echo "Error on $p: " . $e->getMessage() . "\n";
        }
    }
}

// Check MySQL
try {
    $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db;charset=utf8mb4', 'root', '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $stmt = $mysql->query("SHOW COLUMNS FROM central_deployments LIKE 'api_ingest_token'");
    if ($stmt->rowCount() === 0) {
        $mysql->exec("ALTER TABLE central_deployments ADD COLUMN api_ingest_token VARCHAR(128) DEFAULT NULL");
        echo "[MySQL] Column api_ingest_token added.\n";
    } else {
        echo "[MySQL] Column api_ingest_token already exists.\n";
    }
} catch (\Throwable $e) {
    echo "[MySQL Info] " . $e->getMessage() . "\n";
}
