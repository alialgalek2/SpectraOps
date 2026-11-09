<?php
require_once __DIR__ . '/../core/init.php';

$stmt = $pdo->query('DESCRIBE messages');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
