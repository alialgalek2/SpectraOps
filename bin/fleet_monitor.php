<?php
declare(strict_types=1);

/**
 * Fleet Health Monitor & Stale Node Sweeper
 * Recommended Cron Execution: Every 5 or 10 minutes
 * Example:
 *   * /5 * * * * php /var/www/fleet-hub/bin/fleet_monitor.php >> /var/log/fleet_monitor.log 2>&1
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\FleetService;

$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "====================================================\n";
echo "[{$timestamp}] Starting Fleet Health Monitor Check...\n";

try {
    $service = new FleetService();
    $report = $service->runFleetMonitorCheck();

    $elapsed = round((microtime(true) - $startTime) * 1000, 2);

    echo "Status: Complete in {$elapsed}ms\n";
    echo "  - Nodes marked unreachable (> 2h inactive): {$report['unreachable_marked']}\n";
    echo "  - Nodes marked warning (30m-2h inactive):   {$report['warning_marked']}\n";
    echo "  - Historical telemetry rows pruned (> 60d): {$report['pruned_heartbeats']}\n";
    echo "====================================================\n";
    exit(0);
} catch (\Throwable $e) {
    echo "CRITICAL ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
