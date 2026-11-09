<?php
require_once __DIR__ . '/../src/Database.php';

$pdo = \FleetHub\Database::getConnection();
$stmt = $pdo->query("
    SELECT d.id, d.client_name, d.product_id, d.api_ingest_token_hash, d.license_id, l.license_key, l.status AS license_status
    FROM central_deployments d
    LEFT JOIN central_licenses l ON d.license_id = l.id
    WHERE d.id = 8
");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$sRow = $pdo->query("SELECT id, client_name, api_ingest_token_hash, license_id FROM central_deployments WHERE id = 8")->fetch(PDO::FETCH_ASSOC);
echo "SQLite Dep 8: " . json_encode($sRow) . "\n";

try {
    $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db', 'fleet_user', 'FLEET_SECRET_KEY');
    $mRows = $mysql->query("SELECT id, client_name, api_ingest_token_hash, license_id FROM central_deployments")->fetchAll(PDO::FETCH_ASSOC);
    echo "MySQL Deployments:\n" . json_encode($mRows, JSON_PRETTY_PRINT) . "\n";
} catch (\Throwable $e) {
    echo "MySQL error: " . $e->getMessage() . "\n";
}
