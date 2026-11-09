<?php
require_once __DIR__ . '/../src/Database.php';

$pdo = \FleetHub\Database::getConnection();
$pdo->exec("UPDATE central_products SET latest_version = '2.5.1' WHERE product_slug = 'prism-edu'");
echo "SQLite latest_version set to 2.5.1\n";

try {
    $mysql = new PDO('mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db', 'fleet_user', 'FLEET_SECRET_KEY');
    $mysql->exec("UPDATE central_products SET latest_version = '2.5.1' WHERE product_slug = 'prism-edu'");
    echo "MySQL latest_version set to 2.5.1\n";
} catch (Exception $e) {
    echo "MySQL error: " . $e->getMessage() . "\n";
}
