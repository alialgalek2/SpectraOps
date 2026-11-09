<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getPDO();
echo "=== PARENTS ===\n";
foreach ($pdo->query("DESCRIBE parents")->fetchAll() as $c) {
    echo "{$c['Field']} ({$c['Type']})\n";
}
