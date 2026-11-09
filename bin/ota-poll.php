<?php
declare(strict_types=1);

/**
 * SpectraOps Client OTA Update Poller CLI Runner (Client Reference Runner)
 * 
 * Usage:
 *   php bin/ota-poll.php [--target-path=/path/to/client] [--force]
 */

require_once dirname(__DIR__) . '/client-sdk/OtaUpdateService.php';

use App\Services\OtaUpdateService;

$startTime = microtime(true);
$timestamp = gmdate('Y-m-d H:i:s') . ' UTC';

echo "====================================================\n";
echo "[{$timestamp}] Starting SpectraOps Client OTA Update Poll...\n";

// Parse CLI flags
$forceApply = in_array('--force', $argv, true) || in_array('--apply-all', $argv, true);
$autoApplyOnly = !$forceApply;

$targetPath = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--target-path=')) {
        $targetPath = substr($arg, 14);
    }
}

if ($targetPath === null && is_dir('c:/xampp/htdocs/Prism EDU')) {
    $targetPath = 'c:/xampp/htdocs/Prism EDU';
} elseif ($targetPath === null) {
    $targetPath = dirname(__DIR__);
}

try {
    $updater = new OtaUpdateService($targetPath);
    $result = $updater->pollAndApply($autoApplyOnly);

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    echo "Status: Complete in {$elapsed}ms\n";
    echo "  - Target: {$targetPath}\n";
    echo "  - Result Status: {$result['status']}\n";
    echo "  - Applied: " . ($result['applied'] ? 'YES' : 'NO') . "\n";
    echo "  - Message: {$result['message']}\n";

    if (!empty($result['installed_version'])) {
        echo "  - Installed Version: v{$result['installed_version']}\n";
        echo "  - Backup Created: {$result['backup_path']}\n";
        echo "  - Closed-Loop Receipt Confirmed: " . ($result['receipt_confirmed'] ? 'YES' : 'NO') . "\n";
    }

    echo "====================================================\n";
    exit(0);

} catch (\Throwable $e) {
    echo "CRITICAL OTA ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    echo "====================================================\n";
    exit(1);
}
