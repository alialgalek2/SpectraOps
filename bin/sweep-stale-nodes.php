<?php
declare(strict_types=1);

/**
 * SpectraOps Automated Stale Node Sweeper
 * 
 * Scans all registered client deployments and marks nodes unreachable
 * if no telemetry heartbeat has been received within the last 120 minutes.
 * 
 * Usage:
 *   php bin/sweep-stale-nodes.php
 */

require_once dirname(__DIR__) . '/src/Database.php';

$startTime = microtime(true);
$timestamp = gmdate('Y-m-d H:i:s') . ' UTC';

echo "====================================================\n";
echo "[{$timestamp}] Starting SpectraOps Stale Node Sweep...\n";

try {
    $pdo = \FleetHub\Database::getConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("
            UPDATE central_deployments 
            SET status = 'unreachable', 
                status_reason = 'Stale node: No telemetry heartbeat in over 120 minutes',
                updated_at = datetime('now')
            WHERE (last_heartbeat_at < datetime('now', '-120 minute') OR last_heartbeat_at IS NULL)
              AND status != 'unreachable'
        ");
    } else {
        $stmt = $pdo->prepare("
            UPDATE central_deployments 
            SET status = 'unreachable', 
                status_reason = 'Stale node: No telemetry heartbeat in over 120 minutes',
                updated_at = UTC_TIMESTAMP()
            WHERE (last_heartbeat_at < NOW() - INTERVAL 120 MINUTE OR last_heartbeat_at IS NULL)
              AND status != 'unreachable'
        ");
    }

    $stmt->execute();
    $unreachableCount = $stmt->rowCount();

    // Trigger Autonomous AI Hotfix & Self-Healing Dispatch Engine for any unhandled fleet errors
    $healingDaemon = __DIR__ . '/autonomous-healing-daemon.php';
    $phpBin = PHP_BINARY ?: 'php';
    $healingDispatched = 0;

    if (file_exists($healingDaemon)) {
        $checkStmt = $pdo->query("
            SELECT COUNT(*) FROM central_error_logs
            WHERE (resolved = 0 OR resolved IS NULL)
              AND (status IN ('open', 'analyzing') OR status IS NULL)
              AND (manual_intervention_required = 0 OR manual_intervention_required IS NULL)
              AND error_level IN ('FATAL', 'ERROR', 'CRITICAL')
        ");
        $healingDispatched = (int)($checkStmt ? $checkStmt->fetchColumn() : 0);

        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            pclose(popen("start /B \"\" \"{$phpBin}\" \"{$healingDaemon}\" --sweep-all > NUL 2>&1", "r"));
        } else {
            exec("\"{$phpBin}\" \"{$healingDaemon}\" --sweep-all > /dev/null 2>&1 &");
        }
    }

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    echo "Status: Complete in {$elapsed}ms\n";
    echo "  - Stale deployments marked unreachable: {$unreachableCount}\n";
    echo "  - Autonomous self-healing dispatched:   {$healingDispatched} incident(s)\n";
    echo "====================================================\n";
    exit(0);

} catch (\Throwable $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
