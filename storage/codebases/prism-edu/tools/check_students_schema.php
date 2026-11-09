<?php
require_once __DIR__ . '/../core/init.php';

$stmt = $pdo->query('DESCRIBE students');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

$data = $pdo->query('SELECT * FROM students LIMIT 1')->fetch(PDO::FETCH_ASSOC);
print_r($data);
