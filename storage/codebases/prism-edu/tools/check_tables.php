<?php
require 'core/init.php';

echo "=== student_attendance ===\n";
print_r($pdo->query("DESCRIBE student_attendance")->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== All tables with 'attendance' in name ===\n";
print_r($pdo->query("SHOW TABLES LIKE '%attendance%'")->fetchAll(PDO::FETCH_COLUMN));

echo "\n=== Check if table 'attendance' exists ===\n";
print_r($pdo->query("SHOW TABLES LIKE 'attendance'")->fetchAll(PDO::FETCH_COLUMN));
