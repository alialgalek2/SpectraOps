<?php
require 'core/init.php';

$count = $pdo->query("SELECT COUNT(*) FROM student_attendance")->fetchColumn();
echo "student_attendance rows: " . $count . "\n";

$rows = $pdo->query("SELECT * FROM student_attendance LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
