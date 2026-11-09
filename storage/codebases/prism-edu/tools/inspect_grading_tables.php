<?php
require_once __DIR__ . '/../core/init.php';

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "All matching tables:\n";
foreach ($tables as $t) {
    if (preg_match('/exam|grade|mark|scale|hall|invigil|report|term/i', $t)) {
        echo "- $t\n";
    }
}

echo "\n--- Columns of 'exams' ---\n";
try {
    $cols = $pdo->query("DESCRIBE exams")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} ({$c['Type']})\n";
    }
} catch (Exception $e) {
    echo "  " . $e->getMessage() . "\n";
}

echo "\n--- Columns of 'grades' ---\n";
try {
    $cols = $pdo->query("DESCRIBE grades")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  {$c['Field']} ({$c['Type']})\n";
    }
} catch (Exception $e) {
    echo "  " . $e->getMessage() . "\n";
}
