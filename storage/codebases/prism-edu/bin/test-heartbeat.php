#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Diagnostic Tool: Test Heartbeat Transmitter
 * Dispatches a live heartbeat probe to SpectraOps and prints raw HTTP metrics & payload.
 */

$appRoot = dirname(__DIR__);
require_once $appRoot . '/bootstrap/app.php';
require_once $appRoot . '/src/Services/TelemetryReporter.php';
require_once $appRoot . '/src/Services/HubClient.php';

use App\Services\TelemetryReporter;
use App\Services\HubClient;

echo "========================================================\n";
echo " Prism EDU -> SpectraOps Heartbeat Diagnostic Probe\n";
echo "========================================================\n\n";

$hubUrl = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: 'http://127.0.0.1:8080/SPECTRAOPS/public');
$apiKey = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: '');
$version = getenv('APP_VERSION') ?: '2.5.0';

echo "Configuration:\n";
echo " - Hub Endpoint:  {$hubUrl}/api/v1/telemetry/ingest.php\n";
echo " - API Key Token: " . substr($apiKey, 0, 8) . "..." . substr($apiKey, -4) . " (" . strlen($apiKey) . " chars)\n";
echo " - Client Version: {$version}\n\n";

if (empty($apiKey)) {
    echo "[FAIL] SPECTRAOPS_API_KEY / TELEMETRY_INGEST_TOKEN is not defined in .env\n";
    exit(1);
}

$reporter = new TelemetryReporter($hubUrl, $apiKey, 'prism-edu', $version);

echo "[1/2] Transmitting live system heartbeat via TelemetryReporter...\n";
$start = microtime(true);
$success = $reporter->sendHeartbeat([
    'diagnostic_run' => true,
    'test_timestamp' => date('Y-m-d H:i:s'),
    'status_note'    => 'Diagnostic heartbeat probe from bin/test-heartbeat.php'
]);
$elapsed = round((microtime(true) - $start) * 1000, 2);

if ($success) {
    echo "[PASS] TelemetryReporter::sendHeartbeat() returned TRUE ({$elapsed}ms)\n\n";
} else {
    echo "[FAIL] TelemetryReporter::sendHeartbeat() returned FALSE ({$elapsed}ms)\n\n";
}

echo "[2/2] Inspecting raw HTTP response from SpectraOps Ingestion API...\n";
$endpoint = rtrim($hubUrl, '/') . '/api/v1/telemetry/ingest.php';
$payload = [
    'product_slug' => 'prism-edu',
    'api_key'      => $apiKey,
    'heartbeat'    => [
        'version'          => $version,
        'php_version'      => PHP_VERSION,
        'mysql_version'    => '10.4.32-MariaDB',
        'os_info'          => php_uname('s') . ' ' . php_uname('r'),
        'domain'           => 'localhost:8080/school_system',
        'memory_usage_mb'  => round(memory_get_usage(true) / (1024 * 1024), 2),
        'db_latency_ms'    => 12.4,
        'storage_free_gb'  => 48.5,
        'active_users_24h' => 1,
        'load_avg'         => 0.35,
    ]
];

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

$responseBody = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
unset($ch);

echo " - HTTP Status Code: {$httpCode}\n";
if ($curlError) {
    echo " - cURL Error:       {$curlError}\n";
}
echo " - Response Payload: \n";
$decoded = json_decode((string)$responseBody, true);
if ($decoded !== null) {
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo ($responseBody ?: "(empty response)") . "\n";
}

echo "\n========================================================\n";
exit($httpCode === 200 ? 0 : 1);
