<?php
require_once dirname(__DIR__) . '/src/Database.php';
$pdo = \FleetHub\Database::getConnection();
$products = $pdo->query('SELECT id, product_slug, product_code, product_name, latest_version FROM central_products')->fetchAll(PDO::FETCH_ASSOC);
echo "=== PRODUCTS ===\n";
print_r($products);

$users = $pdo->query('SELECT id, name, email, role FROM central_users')->fetchAll(PDO::FETCH_ASSOC);
echo "=== OPERATOR USERS ===\n";
print_r($users);
