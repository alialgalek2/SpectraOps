<?php
/**
 * Developer Telemetry Receiving Endpoint
 * In production, this endpoint resides on the central developer infrastructure.
 * In local/self-contained deployment, it validates and acknowledges anonymous error bundles.
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);

if (!$payload || empty($payload['logs'])) {
    echo json_encode([
        'success' => true,
        'status'  => 'acknowledged',
        'message' => 'Empty or heartbeat payload received'
    ]);
    exit();
}

$count = count($payload['logs']);

// Acknowledge received anonymous telemetry logs
echo json_encode([
    'success'         => true,
    'status'          => 'received',
    'records_ingested'=> $count,
    'system_version'  => $payload['system_version'] ?? 'unknown',
    'timestamp'       => date('c')
]);
