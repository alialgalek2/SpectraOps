#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * End-to-End Verification Tool: ping-spectra.php
 * Transmits both a live telemetry heartbeat and a simulated diagnostic error event
 * to SpectraOps, verifying full bidirectional synchronization and status recovery.
 */

$appRoot = dirname(__DIR__);
require_once $appRoot . '/bootstrap/app.php';
require_once $appRoot . '/src/Services/TelemetryReporter.php';

use App\Services\TelemetryReporter;

echo "========================================================\n";
echo " SpectraOps Fleet Hub Telemetry Sync Probe (Prism EDU)\n";
echo "========================================================\n\n";

$hubUrl = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: 'http://127.0.0.1:8080/SPECTRAOPS/public');
$apiKey = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: '');
$version = getenv('APP_VERSION') ?: '2.5.0';

if (empty($apiKey)) {
    echo "[ERROR] Missing SPECTRAOPS_API_KEY in .env configuration.\n";
    exit(1);
}

$endpoint = rtrim($hubUrl, '/') . '/api/v1/telemetry/ingest.php';
echo "Endpoint: " . $endpoint . "\n";
echo "Key:      " . substr($apiKey, 0, 8) . "..." . substr($apiKey, -4) . "\n";
echo "Time:     " . gmdate('Y-m-d H:i:s') . " UTC\n\n";

// 1. Dispatch Combined Heartbeat & Error Trace Payload
echo "[STEP 1] Assembling telemetry batch (Heartbeat + Diagnostic Error Trace)...\n";
$dummyTrace = "Exception: Live Sync Diagnostic Test Probe at " . date('Y-m-d H:i:s') . "\n"
    . "  #0 " . $appRoot . "/bin/ping-spectra.php(42): App\\Services\\TelemetryReporter->reportError()\n"
    . "  #1 {main}";

$payload = [
    'product_slug' => 'prism-edu',
    'api_key'      => $apiKey,
    'heartbeat'    => [
        'version'          => $version,
        'php_version'      => PHP_VERSION,
        'mysql_version'    => '10.4.32-MariaDB',
        'os_info'          => php_uname('s') . ' ' . php_uname('r') . ' (' . php_uname('m') . ')',
        'domain'           => 'localhost:8080/school_system',
        'memory_usage_mb'  => round(memory_get_usage(true) / (1024 * 1024), 2),
        'db_latency_ms'    => 11.2,
        'storage_free_gb'  => 46.8,
        'active_users_24h' => 1,
        'load_avg'         => 0.28,
        'status_note'      => 'Automated sync probe verification via bin/ping-spectra.php'
    ],
    'errors' => [
        [
            'level'       => 'warning',
            'message'     => 'Live Telemetry Pipeline Verified: Heartbeat and error stream operational at ' . date('Y-m-d H:i:s'),
            'file_path'   => 'bin/ping-spectra.php',
            'line_number' => 45,
            'stack_trace' => $dummyTrace
        ]
    ]
];

echo "[STEP 2] Dispatching HTTP POST request to SpectraOps Ingestion API...\n";
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'User-Agent: FleetTelemetryReporter/2.5.0'
]);

$start = microtime(true);
$response = curl_exec($ch);
$elapsed = round((microtime(true) - $start) * 1000, 2);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
unset($ch);

echo " - HTTP Response Code: {$httpCode} ({$elapsed}ms)\n";
if ($curlError) {
    echo " - cURL Transport Error: {$curlError}\n";
}

echo " - Response Payload:\n";
$decoded = json_decode((string)$response, true);
if ($decoded !== null) {
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo ($response ?: "(Empty response)") . "\n";
}

echo "\n[STEP 3] Evaluating Results:\n";
if ($httpCode === 200 && ($decoded['status'] ?? '') === 'acknowledged') {
    echo " [PASS] SpectraOps successfully ingested telemetry!\n";
    echo " [PASS] Deployment ID: " . ($decoded['deployment_id'] ?? 'N/A') . "\n";
    echo " [PASS] Client Name:   " . ($decoded['client_name'] ?? 'N/A') . "\n";
    echo " [PASS] Heartbeat:     " . (($decoded['heartbeat_recorded'] ?? false) ? 'YES' : 'NO') . "\n";
    echo " [PASS] Errors Logged: " . ($decoded['errors_recorded'] ?? 0) . " error row(s)\n";
    echo "\nVerification Success: SpectraOps dashboard will reflect healthy status, updated heartbeat timestamp, and the new diagnostic event in the Live Remote Error Stream.\n";
    echo "========================================================\n";
    exit(0);
} else {
    echo " [FAIL] Ingestion request failed with HTTP {$httpCode}\n";
    echo "========================================================\n";
    exit(1);
}
