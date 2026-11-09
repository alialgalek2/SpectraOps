<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getPDO();
foreach (['sibling_discount_groups', 'fee_invoices', 'payments', 'parent_student_map'] as $table) {
    echo "=== TABLE: $table ===\n";
    $cols = $pdo->query("DESCRIBE $table")->fetchAll();
    foreach ($cols as $c) {
        echo "{$c['Field']} ({$c['Type']}) {$c['Null']} {$c['Key']} {$c['Default']}\n";
    }
}
