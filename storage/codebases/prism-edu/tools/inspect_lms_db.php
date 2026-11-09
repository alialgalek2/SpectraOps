<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TABLE: daily_logs ===\n";
$cols = $pdo->query("SHOW FULL COLUMNS FROM `daily_logs`")->fetchAll(PDO::FETCH_ASSOC);
foreach ($cols as $c) {
    echo "  - {$c['Field']} ({$c['Type']}) " . ($c['Null'] === 'YES' ? 'NULL' : 'NOT NULL') . " Default: {$c['Default']}\n";
}
