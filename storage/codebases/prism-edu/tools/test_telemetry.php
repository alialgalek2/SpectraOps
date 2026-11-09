<?php
$hubUrl = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ? rtrim(getenv('TELEMETRY_HUB_URL'), '/') . '/api/v1/telemetry/ingest.php' : 'http://127.0.0.1:8080/fleet-hub/public/api/v1/telemetry/ingest.php');
$token = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: null);
if (empty($token)) {
    die("Telemetry token not configured in environment.\n");
}
$payload = json_encode(['heartbeat' => ['version' => '2.5.0']]);

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n" .
                     "Authorization: Bearer $token\r\n" .
                     "User-Agent: FleetTelemetryReporter/2.5.0\r\n",
        'method'  => 'POST',
        'content' => $payload,
    ],
];
$context  = stream_context_create($options);
$result = file_get_contents($hubUrl, false, $context);
if ($result === false) {
    echo "Error sending request.\n";
} else {
    echo "Response: $result\n";
}
