<?php
require 'core/init.php';

echo "Attendance tables:\n";
$tables = $pdo->query("SHOW TABLES LIKE '%attendance%'")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);

echo "\nColumns of students:\n";
print_r($pdo->query("DESCRIBE students")->fetchAll(PDO::FETCH_COLUMN));

echo "\nColumns of users:\n";
print_r($pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN));

echo "\nColumns of audit_logs:\n";
print_r($pdo->query("DESCRIBE audit_logs")->fetchAll(PDO::FETCH_COLUMN));
