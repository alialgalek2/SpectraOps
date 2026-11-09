<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TABLE: subjects ===\n";
try {
    $stmt = $pdo->query("DESCRIBE subjects");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $c) {
        echo "  - {$c['Field']} ({$c['Type']})\n";
    }
} catch (Exception $e) {
    echo "  Table missing or error: " . $e->getMessage() . "\n";
}
