<?php
require_once __DIR__ . '/../core/init.php';

// Align role text with role_id = 5 (Parent)
$pdo->exec("UPDATE users SET role = 'parent' WHERE role_id = 5");

$stmt = $pdo->query("SELECT user_id, username, email, full_name, role, role_id, status FROM users WHERE role = 'parent' OR role_id = 5");
$res = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Current Active Parent Accounts in Database:\n";
print_r($res);
