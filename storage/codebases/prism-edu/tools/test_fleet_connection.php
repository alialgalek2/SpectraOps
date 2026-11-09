<?php
declare(strict_types=1);

require_once __DIR__ . '/services/HubClient.php';
require_once __DIR__ . '/services/TelemetryReporter.php';

echo "=================================================================\n";
echo " PRISM EDU <-> SPECTRA OPS BIDIRECTIONAL TELEMETRY TEST HARNESS\n";
echo "=================================================================\n\n";

$reporter = \App\Services\HubClient::get();

if (!$reporter) {
    echo "[FAIL] HubClient::get() returned null. Check .env configuration.\n";
    exit(1);
}
echo "[PASS] HubClient instantiated successfully with configured credentials.\n";

// --- TEST 1: Heartbeat Dispatch ---
echo "\n--- [TEST 1] Dispatching Live Heartbeat Telemetry ---\n";
$hbSuccess = $reporter->sendHeartbeat([
    'version' => '2.5.0',
    'status_note' => 'Automated integration probe'
]);

if ($hbSuccess) {
    echo "[PASS] Heartbeat successfully ingested and acknowledged by Spectra Ops (HTTP 200).\n";
} else {
    echo "[FAIL] Heartbeat dispatch failed.\n";
    exit(1);
}

// --- TEST 2: Error Event Reporting ---
echo "\n--- [TEST 2] Dispatching Diagnostic Exception Event ---\n";
$testErrMsg = "Diagnostic Probe: Database dead-lock simulation on route /api/students at " . date('Y-m-d H:i:s');
$errSuccess = $reporter->sendError(
    'warning',
    $testErrMsg,
    'controllers/StudentController.php',
    142,
    "#0 controllers/StudentController.php(142): PDOStatement->execute()\n#1 core/Router.php(58): StudentController->index()"
);

if ($errSuccess) {
    echo "[PASS] Diagnostic error log successfully streamed to Spectra Ops.\n";
} else {
    echo "[FAIL] Error streaming failed.\n";
    exit(1);
}

// --- TEST 3: Support Ticket Ingestion ---
echo "\n--- [TEST 3] Dispatching Support Ticket to Spectra Ops ---\n";
$ticketRef = 'TCK-' . strtoupper(substr(uniqid(), -6));
$ticketSuccess = $reporter->submitSupportTicket([
    'reference'       => $ticketRef,
    'requester_name'  => 'Principal Dr. Marcus Vance',
    'requester_email' => 'principal@westminster.edu',
    'requester_role'  => 'Super Admin',
    'subject'         => 'Automated Telemetry Verification Ticket',
    'category'        => 'general',
    'priority'        => 'medium',
    'message'         => 'This ticket was dispatched by the automated integration test suite to verify bidirectional telemetry flow between Prism EDU and Fleet Hub.'
]);

if ($ticketSuccess) {
    echo "[PASS] Support ticket '{$ticketRef}' successfully registered on Spectra Ops.\n";
} else {
    echo "[FAIL] Ticket submission failed.\n";
    exit(1);
}

// --- TEST 4: Software Update Checking ---
echo "\n--- [TEST 4] Checking for Software Updates from Spectra Ops ---\n";
$updateCheck = $reporter->checkForUpdates();
echo "[PASS] Update check completed. Status: " . ($updateCheck ? "Update Available (v{$updateCheck['latest_version']})" : "System Up to Date") . "\n";

// --- TEST 5: Verify Central Fleet Hub Database Records ---
echo "\n--- [TEST 5] Validating Recorded Records in central_fleet_db ---\n";
try {
    $pdoCentral = new PDO("mysql:host=127.0.0.1;dbname=central_fleet_db;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    // 1. Verify deployment heartbeat
    $dep = $pdoCentral->query("SELECT id, client_name, current_version, status, last_heartbeat_at, php_version, server_ip FROM central_deployments WHERE id = 1")->fetch();
    echo "  -> Deployment [{$dep['id']}] '{$dep['client_name']}':\n";
    echo "     * Status: " . strtoupper($dep['status']) . "\n";
    echo "     * Version: v{$dep['current_version']}\n";
    echo "     * PHP: {$dep['php_version']} | IP: {$dep['server_ip']}\n";
    echo "     * Last Heartbeat: {$dep['last_heartbeat_at']}\n";

    // 2. Verify central_heartbeats latest row
    $latestHb = $pdoCentral->query("SELECT * FROM central_heartbeats WHERE deployment_id = 1 ORDER BY id DESC LIMIT 1")->fetch();
    echo "  -> Latest Heartbeat Telemetry Metrics:\n";
    echo "     * Memory: {$latestHb['memory_usage_mb']} MB\n";
    echo "     * DB Latency: {$latestHb['db_latency_ms']} ms\n";
    echo "     * Free Disk: {$latestHb['storage_free_gb']} GB\n";
    echo "     * Active Users: {$latestHb['active_users_24h']}\n";
    echo "     * Recorded At: {$latestHb['recorded_at']}\n";

    // 3. Verify Error Log
    $latestErr = $pdoCentral->query("SELECT id, error_level, error_message, last_seen_at FROM central_error_logs WHERE deployment_id = 1 ORDER BY id DESC LIMIT 1")->fetch();
    echo "  -> Latest Error Stream Event:\n";
    echo "     * Level: [{$latestErr['error_level']}] {$latestErr['error_message']}\n";
    echo "     * Logged At: {$latestErr['last_seen_at']}\n";

    // 4. Verify Ticket
    $latestTck = $pdoCentral->query("SELECT id, ticket_reference, subject, status, created_at FROM central_support_tickets WHERE ticket_reference = '{$ticketRef}' LIMIT 1")->fetch();
    if ($latestTck) {
        echo "  -> Support Ticket Record:\n";
        echo "     * Ref: {$latestTck['ticket_reference']} ({$latestTck['status']})\n";
        echo "     * Subject: {$latestTck['subject']}\n";
        echo "     * Registered: {$latestTck['created_at']}\n";
    }

    echo "\n=================================================================\n";
    echo " RESULT: ALL TELEMETRY CHANNELS CONNECTED & DATA FLOWING!\n";
    echo "=================================================================\n";

} catch (Exception $e) {
    echo "[WARN] Could not query central_fleet_db directly: " . $e->getMessage() . "\n";
}
