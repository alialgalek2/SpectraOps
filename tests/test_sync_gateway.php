<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for POST /api/v1/sync Gateway & Client SDK
 */
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Services/QuickLinkService.php';
require_once dirname(__DIR__) . '/src/Services/SyncGatewayService.php';
require_once dirname(__DIR__) . '/client-sdk/SpectraTelemetryGateway.php';
require_once dirname(__DIR__) . '/client-sdk/SpectraSupportTicket.php';

use FleetHub\Database;
use FleetHub\Services\QuickLinkService;
use FleetHub\Services\SyncGatewayService;

$passed = 0;
$failed = 0;

function assertSync(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo " [PASS] {$label}\n";
    } else {
        $failed++;
        echo " [FAIL] {$label}\n";
    }
}

echo "========================================================\n";
echo "Running Unified Sync Gateway & Client SDK Test Suite\n";
echo "========================================================\n\n";

$pdo = Database::getConnection();
$quickLink = new QuickLinkService($pdo);
$syncService = new SyncGatewayService($pdo);

$testDomain = 'https://sync-test-' . bin2hex(random_bytes(3)) . '.example.com';
$linked = $quickLink->linkDeployment([
    'product_slug' => 'prism-edu',
    'client_name'  => 'Sync Test Academy',
    'domain'       => $testDomain,
    'environment'  => 'production',
]);

$depId = (int)$linked['deployment_id'];
$depKey = (string)$linked['deployment_key'];
$licToken = (string)$linked['license_token'];

// 1. Auth checks
[$codeMissing, $resMissing] = $syncService->handle([], []);
assertSync($codeMissing === 401 && ($resMissing['ok'] ?? true) === false, 'Missing key returns HTTP 401');

[$codeInvalid, $resInvalid] = $syncService->handle(['deployment_key' => 'invalid_key_123'], []);
assertSync($codeInvalid === 401 && ($resInvalid['ok'] ?? true) === false, 'Invalid key returns HTTP 401');

// 2. Valid sync with ticket & error events
$ticketEventId = 'evt_tkt_' . bin2hex(random_bytes(4));
$localTicketId = 'TKT-' . strtoupper(bin2hex(random_bytes(3)));
$payload = [
    'deployment_id' => (string)$depId,
    'deployment_key' => $depKey,
    'license_token'  => $licToken,
    'product_slug'   => 'prism-edu',
    'environment'    => 'production',
    'live_domain'    => $testDomain,
    'php_version'    => '8.2.15',
    'app_version'    => '2.4.1',
    'telemetry'      => [
        'memory_usage_mb'  => 48.5,
        'db_latency_ms'    => 18,
        'storage_free_gb'  => 24.5,
        'active_users_24h' => 112,
    ],
    'events' => [
        [
            'event_id'   => $ticketEventId,
            'event_type' => 'ticket',
            'timestamp'  => gmdate('c'),
            'payload'    => [
                'local_ticket_id' => $localTicketId,
                'subject'         => 'Printer queue stalled',
                'message'         => 'Thermal printer queue stopped after update.',
                'priority'        => 'high',
                'category'        => 'general',
                'submitted_by'    => 'admin@synctest.edu',
                'attachments'     => ['log.txt'],
            ],
        ],
        [
            'event_id'   => 'evt_err_' . bin2hex(random_bytes(4)),
            'event_type' => 'error',
            'timestamp'  => gmdate('c'),
            'payload'    => [
                'error_level'   => 'WARNING',
                'error_message' => 'Cache directory not writable on node 42',
                'file_path'     => 'src/Cache.php',
                'line_number'   => 88,
            ],
        ],
    ],
];

[$codeOk, $resOk] = $syncService->handle($payload, ['Authorization' => 'Bearer ' . $depKey]);
assertSync($codeOk === 200, 'Valid sync returns HTTP 200');
assertSync(($resOk['ok'] ?? false) === true && ($resOk['success'] ?? false) === true, 'Sync response ok=true and success=true');
assertSync(($resOk['license'] ?? '') === 'active' && ($resOk['license_valid'] ?? false) === true, 'Sync returns active license lease');
assertSync(($resOk['status'] ?? '') === 'online', 'Sync returns status=online');
assertSync(($resOk['processed_events'] ?? 0) === 2, 'Sync processed 2 events');
assertSync(!empty($resOk['latest_version']), 'Sync returns latest_version');

// 3. Idempotent ticket retry check
[$codeRetry, $resRetry] = $syncService->handle($payload, []);
assertSync($codeRetry === 200 && ($resRetry['processed_events'] ?? 0) === 2, 'Retry sync succeeds');

$chkTkt = $pdo->prepare("SELECT COUNT(*) FROM central_support_tickets WHERE deployment_id = ? AND ticket_reference = ?");
$chkTkt->execute([$depId, $localTicketId]);
assertSync((int)$chkTkt->fetchColumn() === 1, 'Ticket deduplicated on outbox retry (count = 1)');

// 4. Verify deployment metadata updated
$chkDep = $pdo->prepare("SELECT status, php_version, current_version FROM central_deployments WHERE id = ?");
$chkDep->execute([$depId]);
$depRow = $chkDep->fetch(PDO::FETCH_ASSOC);
assertSync($depRow && $depRow['status'] === 'online', 'Deployment status updated to online');
assertSync($depRow && $depRow['php_version'] === '8.2.15', 'Deployment php_version updated');
assertSync($depRow && $depRow['current_version'] === '2.4.1', 'Deployment current_version updated');

// 5. Suspended license lease check
$pdo->prepare("UPDATE central_licenses SET status = 'suspended' WHERE id = ?")->execute([(int)$linked['license_id']]);
[$codeSusp, $resSusp] = $syncService->handle(['deployment_key' => $depKey], []);
assertSync($codeSusp === 200 && ($resSusp['license'] ?? '') === 'suspended' && ($resSusp['license_valid'] ?? true) === false, 'Suspended license returns license_valid=false');

// 6. Client SDK outbox & ticket queuing test
$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'spectra_sdk_test_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0755, true);
$gw = new SpectraTelemetryGateway($linked['bundle'], $tmpDir);
$evtId = $gw->queueEvent('ticket', [
    'local_ticket_id' => 'TKT-LOCAL-1',
    'subject'         => 'Offline queue test',
    'message'         => 'Queued while offline',
]);
assertSync($evtId !== '' && $gw->getOutboxCount() === 1, 'SpectraTelemetryGateway queues event in spectra_outbox.json');

$tktRes = SpectraSupportTicket::dispatch([
    'local_ticket_id' => 'TKT-LOCAL-2',
    'subject'         => 'Helper dispatch test',
    'message'         => 'Testing SpectraSupportTicket::dispatch',
], $gw);
assertSync(($tktRes['ok'] ?? false) === true && $gw->getOutboxCount() === 2, 'SpectraSupportTicket::dispatch queues ticket cleanly');

// 7. Master release ZIP & Full App Bundle (.zip) injection test
$sampleMasterSrc = tempnam(sys_get_temp_dir(), 'mst_');
$mz = new ZipArchive();
$mz->open($sampleMasterSrc, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$mz->addFromString('index.php', '<?php echo "app";');
$mz->close();

$storedMaster = QuickLinkService::saveMasterReleaseZip('prism-edu', $sampleMasterSrc);
assertSync(is_file($storedMaster), 'Master release ZIP stored in storage/releases/prism-edu/');

$fullBundle = $quickLink->buildFullAppBundleZip($depId);
assertSync(is_file($fullBundle['path']) && str_ends_with($fullBundle['filename'], '-bundle.zip'), 'Full App Bundle ZIP created');

$bz = new ZipArchive();
$bz->open($fullBundle['path']);
assertSync($bz->locateName('index.php') !== false, 'Full App Bundle retains base app index.php');
assertSync($bz->locateName('spectraops.json') !== false, 'Full App Bundle injects spectraops.json');
assertSync($bz->locateName('client-sdk/SpectraTelemetryGateway.php') !== false, 'Full App Bundle injects SpectraTelemetryGateway.php');
assertSync($bz->locateName('client-sdk/SpectraSupportTicket.php') !== false, 'Full App Bundle injects SpectraSupportTicket.php');
$bz->close();
@unlink($fullBundle['path']);
@unlink($sampleMasterSrc);
@unlink($storedMaster);
@rmdir(dirname($storedMaster));

// Cleanup test rows & temp directory
$pdo->prepare("DELETE FROM central_deployments WHERE id = ?")->execute([$depId]);
$pdo->prepare("DELETE FROM central_licenses WHERE id = ?")->execute([(int)$linked['license_id']]);
@unlink($tmpDir . '/cache/spectra_outbox.json');
@unlink($tmpDir . '/cache/spectra_state.json');
@rmdir($tmpDir . '/cache');
@rmdir($tmpDir);

echo "\n========================================================\n";
echo "Test Results: {$passed} Passed, {$failed} Failed\n";
echo "========================================================\n";
exit($failed > 0 ? 1 : 0);
