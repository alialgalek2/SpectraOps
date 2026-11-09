<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getPDO();
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($tables, JSON_PRETTY_PRINT);
