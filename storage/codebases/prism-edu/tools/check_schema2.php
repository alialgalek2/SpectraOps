<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getPDO();
foreach (['financial_transactions', 'students', 'classes', 'users'] as $t) {
    echo "=== TABLE: $t ===\n";
    $cols = $pdo->query("DESCRIBE $t")->fetchAll();
    foreach ($cols as $c) {
        echo "{$c['Field']} ({$c['Type']})\n";
    }
}
