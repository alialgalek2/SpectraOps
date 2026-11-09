<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__DIR__, 4) . '/src/Database.php';

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
    $healingDaemon = dirname(__DIR__, 4) . '/bin/autonomous-healing-daemon.php';
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

    $healingMsg = ($healingDispatched > 0)
        ? " Autonomous AI self-healing triggered ({$healingDispatched} incident workers active)."
        : " Autonomous AI self-healing daemon checked (0 pending incidents).";

    $wantsJson = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || (!empty($_SERVER['CONTENT_TYPE']) && str_contains($_SERVER['CONTENT_TYPE'], 'application/json'))
        || isset($_GET['json']);

    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'success'            => true,
            'unreachable_marked' => $unreachableCount,
            'healing_dispatched' => $healingDispatched,
            'message'            => "Stale node sweep complete: {$unreachableCount} node(s) marked unreachable.{$healingMsg}"
        ]);
        exit;
    }

    $_SESSION['flash_notice'] = "Stale node sweep complete: {$unreachableCount} node(s) marked unreachable.{$healingMsg}";
    $_SESSION['flash_type'] = ($unreachableCount > 0) ? 'warn' : 'ok';

    $redirectUrl = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../../views/fleet_view.php';
    header("Location: {$redirectUrl}");
    exit;

} catch (\Throwable $e) {
    if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }

    $_SESSION['flash_notice'] = "Error running stale node sweep: " . $e->getMessage();
    $_SESSION['flash_type'] = 'crit';
    $redirectUrl = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '../../views/fleet_view.php';
    header("Location: {$redirectUrl}");
    exit;
}
