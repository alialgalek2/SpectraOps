<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TABLE: parents ===\n";
$stmt = $pdo->query("SELECT * FROM parents");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== TABLE: parent_student_map ===\n";
$stmt2 = $pdo->query("SELECT * FROM parent_student_map");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== TABLE: roles ===\n";
$stmt3 = $pdo->query("SELECT * FROM roles");
print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== TABLE: users (All Active Accounts) ===\n";
$stmt4 = $pdo->query("SELECT user_id, username, email, full_name, role, role_id, status FROM users");
print_r($stmt4->fetchAll(PDO::FETCH_ASSOC));
