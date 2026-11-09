<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TABLE: timetables ===\n";
try {
    $stmt = $pdo->query("DESCRIBE timetables");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  - {$c['Field']} ({$c['Type']})\n";
    }
} catch (Exception $e) {
    echo "  Table missing or error: " . $e->getMessage() . "\n";
}

echo "\n=== ALL DB TABLES ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
print_r($tables);
