<?php
require_once __DIR__ . '/../core/init.php';
$stmt = $pdo->query('SELECT user_id, username, role, full_name FROM users');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
