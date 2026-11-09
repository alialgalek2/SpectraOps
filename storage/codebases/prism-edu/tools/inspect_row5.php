<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Admin User';

$stmt = $pdo->query("SELECT * FROM employees ORDER BY employee_id ASC LIMIT 10");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Total employees fetched: " . count($rows) . "\n";
foreach ($rows as $i => $r) {
    echo ($i + 1) . ": ID={$r['employee_id']}, Code={$r['employee_code']}, Name={$r['full_name']}, Cat={$r['category']}, Job={$r['job_title']}, UserID={$r['user_id']}, Role=" . ($r['portal_role'] ?? 'none') . ", Username=" . ($r['portal_username'] ?? 'none') . "\n";
}
