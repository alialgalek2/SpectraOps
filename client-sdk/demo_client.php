<?php
declare(strict_types=1);

/**
 * Example Client Integration Demo
 * Simulates a client deployment (e.g., Prism EDU at Oakridge Academy)
 * sending a heartbeat and reporting handled or unhandled exceptions.
 */

require_once __DIR__ . '/TelemetryReporter.php';

use App\Services\TelemetryReporter;

// 1. Initialize Reporter with your Central Hub URL and unique deployment token
$hubUrl = 'http://127.0.0.1:8080';
$ingestToken = 'YOUR_DEPLOYMENT_TOKEN_HERE'; // Replace with the token issued by Fleet Hub

$reporter = TelemetryReporter::init($hubUrl, $ingestToken, '2.5.0');

// 2. Register Global Handlers (Optional but recommended: catches uncaught fatal errors)
TelemetryReporter::registerGlobalHandlers($reporter);

// 3. Queue a Periodic Heartbeat (Peak memory, free disk, and custom metrics)
$reporter->queueHeartbeat([
    'active_users_24h' => 342,
    'db_latency_ms'   => 18.4,
    'load_avg'        => 0.45,
]);

// 4. Report an exception (handled or unhandled)
try {
    // Simulating an application error (e.g. database query failure)
    throw new RuntimeException("Database connection timeout during invoice batch export for client ID #4829");
} catch (\Throwable $e) {
    echo "Simulated error caught. Reporting to fleet hub...\n";
    $reporter->reportError($e, 'CRITICAL');
}

// 5. Explicitly flush (or let shutdown function flush automatically at the end of script)
$success = $reporter->flush();

echo $success ? "Telemetry successfully delivered to Fleet Hub!\n" : "Telemetry queued locally (Hub unreachable).\n";
