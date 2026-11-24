<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';
require_once dirname(__DIR__) . '/src/Services/QuickLinkService.php';
require_once dirname(__DIR__) . '/src/Services/HandshakeService.php';
require_once dirname(__DIR__) . '/client-sdk/TelemetryReporter.php';
require_once dirname(__DIR__) . '/client-sdk/HubClient.php';

use FleetHub\Database;
use FleetHub\FleetService;
use FleetHub\Services\QuickLinkService;
use FleetHub\Services\HandshakeService;
use App\Telemetry\HubClient;

$passed = 0;
$failed = 0;

function check(string $label, bool $cond): void {
    global $passed, $failed;
    if ($cond) {
        echo " [PASS] {$label}\n";
        $passed++;
    } else {
        echo " [FAIL] {$label}\n";
        $failed++;
    }
}

echo "========================================================\n";
echo "Running One-Click Deployment Link & Bundle Test Suite\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();
$fleet = new FleetService($pdo);
$quickLink = new QuickLinkService($pdo);

// 1. Domain Normalization Tests
check(
    'Normalizes https URL with trailing slashes',
    QuickLinkService::normalizeDomain('https://Demo.PrismEdu.com///') === 'https://demo.prismedu.com'
);
check(
    'Preserves http scheme and strips trailing slash',
    QuickLinkService::normalizeDomain('http://staging.vendpulse.org/') === 'http://staging.vendpulse.org'
);
check(
    'Prepends https:// when scheme is omitted',
    QuickLinkService::normalizeDomain('beamstock.example.com/') === 'https://beamstock.example.com'
);

$ssrfRejected = false;
try {
    QuickLinkService::normalizeDomain('http://127.0.0.1/');
} catch (\InvalidArgumentException $e) {
    $ssrfRejected = true;
}
check('Rejects loopback/SSRF domain during normalization', $ssrfRejected);

// 2. Atomic Provisioning Test
$res = $quickLink->linkDeployment([
    'product_slug' => 'prism-edu',
    'client_name'  => 'Westminster Demo',
    'domain'       => 'https://demo.prismedu.com/',
    'environment'  => 'demo',
    'license_tier' => 'subscription',
    'expiry_days'  => 90,
]);

check('linkDeployment returns valid deployment_id', $res['deployment_id'] > 0);
check('linkDeployment returns valid license_id', $res['license_id'] > 0);
check('deployment_key is 48-char hex (24 random bytes)', strlen($res['deployment_key']) === 48 && ctype_xdigit($res['deployment_key']));
check('Deployment status is pending_handshake', $res['status'] === 'pending_handshake');
check('Domain normalized in response', $res['domain'] === 'https://demo.prismedu.com');
check('License token is 64-char hex HMAC', strlen($res['license_token']) === 64 && ctype_xdigit($res['license_token']));

// Verify DB state
$depStmt = $pdo->prepare("SELECT * FROM central_deployments WHERE id = ?");
$depStmt->execute([$res['deployment_id']]);
$depRow = $depStmt->fetch(PDO::FETCH_ASSOC);

$licStmt = $pdo->prepare("SELECT * FROM central_licenses WHERE id = ?");
$licStmt->execute([$res['license_id']]);
$licRow = $licStmt->fetch(PDO::FETCH_ASSOC);

check('Deployment bound to created license_id', (int)$depRow['license_id'] === $res['license_id']);
check('Deployment status in DB is pending_handshake', $depRow['status'] === 'pending_handshake');
check('Deployment stores SHA-256 hash of deployment_key', $depRow['api_ingest_token_hash'] === hash('sha256', $res['deployment_key']));
check('Deployment stores encrypted bundle_token_enc (not plaintext)', !empty($depRow['bundle_token_enc']) && $depRow['bundle_token_enc'] !== $res['deployment_key']);
check('Bound license is active in central_licenses', $licRow && $licRow['status'] === 'active');

// Verify ingest authentication works with generated deployment_key
$authDep = $fleet->authenticateToken($res['deployment_key']);
check('FleetService authenticates generated deployment_key', $authDep !== null && (int)$authDep['id'] === $res['deployment_id']);

// 3. Bundle Generation & Structure Test
$bundle = $quickLink->buildConnectionBundle($res['deployment_id']);
$expectedKeys = ['hub_url', 'api_base', 'product_slug', 'deployment_id', 'deployment_key', 'license_token', 'environment', 'heartbeat_interval_sec'];
$hasAllKeys = empty(array_diff($expectedKeys, array_keys($bundle)));
check('spectraops.json bundle contains all required keys', $hasAllKeys);
check('Bundle hub_url matches default', $bundle['hub_url'] === 'https://spectraops.algaletech.com');
check('Bundle api_base matches default', $bundle['api_base'] === 'https://spectraops.algaletech.com/api/v1');
check('Bundle product_slug matches', $bundle['product_slug'] === 'prism-edu');
check('Bundle deployment_key matches provisioned key', $bundle['deployment_key'] === $res['deployment_key']);
check('Bundle license_token matches provisioned token', $bundle['license_token'] === $res['license_token']);
check('Bundle environment is demo', $bundle['environment'] === 'demo');
check('Bundle heartbeat_interval_sec is 300', $bundle['heartbeat_interval_sec'] === 300);

// 4. Client SDK Auto-Discovery Test
$tmpBundleFile = sys_get_temp_dir() . '/spectraops_test_' . bin2hex(random_bytes(4)) . '.json';
file_put_contents($tmpBundleFile, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$loadedConfig = HubClient::loadBundleConfig($tmpBundleFile);
check('HubClient::loadBundleConfig loads dropped spectraops.json', $loadedConfig !== null && $loadedConfig['deployment_key'] === $res['deployment_key']);
$reporter = HubClient::get($tmpBundleFile);
check('HubClient::get initializes TelemetryReporter from spectraops.json', $reporter !== null);
@unlink($tmpBundleFile);

// 5. Handshake & Heartbeat Handler Tests
$hs = new HandshakeService($pdo);
[$hsCode, $hsResp] = $hs->handle([
    'deployment_id' => $res['deployment_id'],
    'deployment_key' => $res['deployment_key'],
    'license_token' => $res['license_token'],
    'product_slug' => 'prism-edu',
    'live_domain' => 'https://demo.prismedu.com',
    'environment' => 'demo',
    'app_version' => '2.5.1',
    'php_version' => '8.2.12',
    'telemetry' => [
        'memory_usage_mb' => 42.5,
        'db_latency_ms' => 12,
        'storage_free_gb' => 25.4,
        'active_users_24h' => 15,
    ],
], [], 'handshake');

check('Handshake returns HTTP 200', $hsCode === 200);
check('Handshake response success=true and status=online', !empty($hsResp['success']) && $hsResp['status'] === 'online');
check('Handshake response license_valid=true', !empty($hsResp['license_valid']));

$depAfter = $pdo->prepare("SELECT status, last_heartbeat_at, current_version, php_version FROM central_deployments WHERE id = ?");
$depAfter->execute([$res['deployment_id']]);
$depAfterRow = $depAfter->fetch(PDO::FETCH_ASSOC);
check('Deployment status updated to online in DB', $depAfterRow['status'] === 'online');
check('Deployment last_heartbeat_at recorded in DB', !empty($depAfterRow['last_heartbeat_at']));
check('Deployment current_version updated from handshake', $depAfterRow['current_version'] === '2.5.1');

[$hbCode, $hbResp] = $hs->handle(
    ['deployment_id' => $res['deployment_id']],
    ['Authorization' => 'Bearer ' . $res['deployment_key']],
    'heartbeat'
);
check('Heartbeat with Authorization Bearer header returns HTTP 200', $hbCode === 200 && $hbResp['status'] === 'online');

[$badCode, $badResp] = $hs->handle(['deployment_id' => $res['deployment_id'], 'deployment_key' => 'wrong_key'], [], 'handshake');
check('Invalid deployment_key rejected with HTTP 403', $badCode === 403 && empty($badResp['success']));

// Cleanup test records
$pdo->prepare("DELETE FROM central_deployments WHERE id = ?")->execute([$res['deployment_id']]);
$pdo->prepare("DELETE FROM central_licenses WHERE id = ?")->execute([$res['license_id']]);

echo "\n========================================================\n";
echo "Test Results: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";

exit($failed > 0 ? 1 : 0);
