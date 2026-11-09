<?php
require_once __DIR__ . '/../core/init.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

echo "=== ALL TABLES IN DATABASE ===\n";
print_r($tables);

echo "\n=== HR RELATED TABLES SCHEMA ===\n";
foreach ($tables as $t) {
    if (preg_match('/employee|payroll|leave|hr|staff|salary|department/i', $t)) {
        echo "\nTABLE: $t\n";
        $cols = $pdo->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  {$c['Field']} ({$c['Type']}) - Null: {$c['Null']} - Key: {$c['Key']}\n";
        }
    }
}
