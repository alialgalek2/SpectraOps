<?php
// bin/telemetry-heartbeat.php (Runs via Server Cron every 1-2 hours)
require_once __DIR__ . '/../bootstrap/app.php';

use App\Config\Database;
use App\Telemetry\HubClient;

$reporter = HubClient::get();
if (!$reporter) exit(0);

// Measure database query latency
$dbLatencyMs = 0.0;
try {
    $start = microtime(true);
    $pdo = Database::getConnection();
    $pdo->query("SELECT 1");
    $dbLatencyMs = round((microtime(true) - $start) * 1000, 2);
} catch (\Throwable $e) {
    $dbLatencyMs = -1.0; // Indicates database connection failure
}

// Send health metrics
$reporter->sendHeartbeat([
    'db_latency_ms'    => $dbLatencyMs,
    'active_users_24h' => 150 // Pull count from active sessions table
]);
