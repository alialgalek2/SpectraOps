<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';

$pdo = \FleetHub\Database::getConnection();

echo "========================================================\n";
echo "Testing SpectraOps Ingest API (api/v1/telemetry/ingest.php)\n";
echo "========================================================\n\n";

// 1. Fetch an active deployment
$stmt = $pdo->query("SELECT d.*, l.license_key FROM central_deployments d LEFT JOIN central_licenses l ON d.license_id = l.id WHERE d.is_active = 1 LIMIT 1");
$dep = $stmt->fetch();

if (!$dep) {
    echo "No active deployment found to test against.\n";
    exit(1);
}

echo "[*] Using deployment #{$dep['id']} ({$dep['client_name']})\n";

// Function to call ingest
function callIngest(array $headers, array $payload): array {
    $ch = curl_init('http://localhost:8080/SPECTRAOPS/public/api/v1/telemetry/ingest.php');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    $httpHeaders = ['Content-Type: application/json'];
    foreach ($headers as $k => $v) {
        $httpHeaders[] = "{$k}: {$v}";
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$res, true), 'raw' => $res];
}

// Test A: No token -> 401
$resA = callIngest([], ['version' => '2.5.0']);
assert($resA['code'] === 401, "Expected 401 for missing token, got {$resA['code']}: {$resA['raw']}");
echo " [PASS] Missing token returns HTTP 401 Unauthorized\n";

// Test B: Method D - Body Fallback Token (using license_key if present)
if (!empty($dep['license_key'])) {
    $resB = callIngest([], [
        'license_key' => $dep['license_key'],
        'version' => '2.5.1',
        'metrics' => [
            'cpu_load' => 1.25,
            'memory_usage_mb' => 245.5,
            'disk_free_gb' => 45.2,
            'active_sessions' => 14
        ],
        'errors' => [
            [
                'error_level' => 'WARNING',
                'error_message' => 'Test transient network timeout in sync service',
                'file_path' => 'src/Sync/Adapter.php',
                'line_number' => 142
            ]
        ],
        'tickets' => [
            [
                'subject' => 'Integration test ticket',
                'message' => 'Automated test ticket for resilient ingestion',
                'priority' => 'low'
            ]
        ]
    ]);
    assert($resB['code'] === 200, "Expected 200 for body token, got {$resB['code']}: {$resB['raw']}");
    assert(!empty($resB['body']['success']), "Expected success = true");
    assert(!empty($resB['body']['lease_signature']), "Expected lease_signature");
    echo " [PASS] Ingestion with body license_key succeeded with cryptographic lease: " . substr($resB['body']['lease_signature'], 0, 16) . "...\n";
    echo "        -> Heartbeat recorded: " . ($resB['body']['heartbeat_recorded'] ? 'YES' : 'NO') . "\n";
    echo "        -> Ingested errors: {$resB['body']['ingested_errors']}\n";
    assert(isset($resB['body']['error_statuses']), "Expected error_statuses in response");
    echo "        -> Error statuses returned: " . count($resB['body']['error_statuses']) . "\n";
    echo "        -> Server UTC time: {$resB['body']['server_time_utc']}\n";
}

// Test C: Header Method A - Authorization: Bearer <license_key> (tests Apache rewrite rule)
if (!empty($dep['license_key'])) {
    $resBearer = callIngest(['Authorization' => 'Bearer ' . $dep['license_key']], [
        'version' => '2.5.2',
        'metrics' => [
            'cpu_load' => 0.45,
            'memory_usage_mb' => 190.0,
            'disk_free_gb' => 50.0,
            'active_sessions' => 8
        ]
    ]);
    assert($resBearer['code'] === 200, "Expected 200 for Authorization Bearer, got {$resBearer['code']}: {$resBearer['raw']}");
    echo " [PASS] Ingestion via Authorization: Bearer header succeeded through Apache\n";
}

// Test D: Header Method C - X-API-KEY using license_key
if (!empty($dep['license_key'])) {
    $resC = callIngest(['X-API-KEY' => $dep['license_key']], [
        'version' => '2.5.2',
        'heartbeat' => [
            'memory_mb' => 310.0,
            'storage_free_gb' => 40.0,
            'active_users_24h' => 20
        ]
    ]);
    assert($resC['code'] === 200, "Expected 200 for X-API-KEY, got {$resC['code']}: {$resC['raw']}");
    echo " [PASS] Ingestion via X-API-KEY header succeeded\n";
}

// Test D: Single error format normalization
if (!empty($dep['license_key'])) {
    $resD = callIngest(['X-API-KEY' => $dep['license_key']], [
        'error_message' => 'Single crash test at root level',
        'file_path' => 'public/index.php',
        'line_number' => 10
    ]);
    assert($resD['code'] === 200, "Expected 200 for single error, got {$resD['code']}: {$resD['raw']}");
    assert($resD['body']['ingested_errors'] === 1, "Expected 1 ingested error");
    echo " [PASS] Single error at root level normalized and recorded\n";
}

echo "\n========================================================\n";
echo "SUCCESS: All Telemetry Ingest API Endpoints Verified!\n";
echo "========================================================\n";
