<?php
require_once __DIR__ . '/../core/init.php';
global $pdo;
$cols = $pdo->query('DESCRIBE users')->fetchAll(PDO::FETCH_COLUMN);
echo json_encode($cols, JSON_PRETTY_PRINT);
