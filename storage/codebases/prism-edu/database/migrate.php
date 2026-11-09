<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/app.php';
require_once __DIR__ . '/Migrator.php';

use Database\Migrator;

$migrator = new Migrator();
$res = $migrator->runPendingMigrations();

if (PHP_SAPI === 'cli') {
    echo ($res['message'] ?? 'Done.') . "\n";
    if (!empty($res['applied'])) {
        echo "Applied migrations:\n - " . implode("\n - ", $res['applied']) . "\n";
    }
}
