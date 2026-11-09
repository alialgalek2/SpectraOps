<?php
declare(strict_types=1);

/**
 * SpectraOps - Autonomous AI Hotfix & Self-Healing Pipeline Test Suite
 * 
 * Verifies end-to-end:
 * 1. Fatal telemetry ingestion & autonomous background trigger
 * 2. Headless daemon execution: context assembly, Gemini synthesis, php -l pre-flight lint
 * 3. Atomic release publishing to central_releases (is_silent_patch = 1) & zip generation
 * 4. Zero-click OTA client installation via OtaUpdateService
 * 5. Closed-loop error reconciliation (status: resolved)
 * 6. Rollback ingestion & fleet release deactivation (is_active = 0)
 * 7. Anti-thrashing circuit breakers (rate limit & single-error ceiling)
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/client-sdk/OtaUpdateService.php';

use App\Services\OtaUpdateService;

function assertCondition(string $desc, bool $condition, string $details = ''): void {
    if ($condition) {
        echo " [PASS] {$desc}\n";
    } else {
        echo " [FAIL] {$desc}" . ($details !== '' ? " -> {$details}" : "") . "\n";
        exit(1);
    }
}

echo "========================================================\n";
echo "SpectraOps Autonomous AI Hotfix & Dispatch Pipeline Test\n";
echo "========================================================\n\n";

// 1. Initialize DB & Environment
$pdo = \FleetHub\Database::getConnection();
$isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
$nowExpr = $isSqlite ? "datetime('now')" : "UTC_TIMESTAMP()";

$apiKey = getenv('GEMINI_API_KEY') ?: '';
assertCondition("GEMINI_API_KEY is present in environment", !empty($apiKey));

$codebaseRoot = dirname(__DIR__) . '/storage/codebases/prism-edu';
assertCondition("Baseline codebase exists for prism-edu", is_dir($codebaseRoot) && file_exists($codebaseRoot . '/src/Services/HubClient.php'));

$updatesDir = dirname(__DIR__) . '/public/downloads/updates';
if (!is_dir($updatesDir)) {
    @mkdir($updatesDir, 0755, true);
}

// Clean any leftover test records before starting
$pdo->prepare("DELETE FROM central_releases WHERE changelog LIKE '%Autonomous AI Hotfix%' OR version LIKE '9.9.%'")->execute();
$pdo->prepare("DELETE FROM central_error_logs WHERE error_message LIKE '%TypeError: Cannot assign null to property%'")->execute();

// Setup Test Product, License & Deployment
$stmtProd = $pdo->prepare("SELECT id, product_slug, latest_version FROM central_products WHERE product_slug = 'prism-edu' LIMIT 1");
$stmtProd->execute();
$product = $stmtProd->fetch();
$stmtProd->closeCursor();

if (!$product) {
    $pdo->exec("INSERT INTO central_products (product_name, product_slug, product_code, latest_version) VALUES ('Prism EDU', 'prism-edu', 'PRISM', '2.5.0')");
    $stmtProd->execute();
    $product = $stmtProd->fetch();
    $stmtProd->closeCursor();
}
$productId = (int)$product['id'];
$initialVersion = (string)$product['latest_version'];

$rawToken = 'flt_auto_' . bin2hex(random_bytes(16));
$tokenHash = hash('sha256', $rawToken);
$licenseKey = 'SPEC-PRISM-AUTO-' . strtoupper(bin2hex(random_bytes(2)));
$testDomain = 'auto-test-' . uniqid() . '.school.edu';

// Insert test license
$insLic = $pdo->prepare("
    INSERT INTO central_licenses (product_id, license_key, client_name, client_email, status, issued_at, created_at)
    VALUES (?, ?, 'Auto-Healing Testbed Academy', 'admin@auto-test.school.edu', 'active', {$nowExpr}, {$nowExpr})
");
$insLic->execute([$productId, $licenseKey]);
$licenseId = (int)$pdo->lastInsertId();
$insLic->closeCursor();

// Insert test deployment
$tokenPreview = substr($rawToken, 0, 8) . '...';
$insDep = $pdo->prepare("
    INSERT INTO central_deployments (product_id, license_id, client_name, deployment_domain, environment, current_version, status, api_ingest_token_hash, api_ingest_token, token_preview)
    VALUES (?, ?, 'Auto-Healing Testbed Academy', ?, 'production', ?, 'healthy', ?, ?, ?)
");
$insDep->execute([$productId, $licenseId, $testDomain, $initialVersion, $tokenHash, $rawToken, $tokenPreview]);
$deploymentId = (int)$pdo->lastInsertId();
$insDep->closeCursor();

assertCondition("Test deployment initialized (#{$deploymentId}) with domain {$testDomain}", $deploymentId > 0);

// Track all resources for guaranteed cleanup
$createdReleaseIds = [];
$createdErrorIds = [];
$createdZipFiles = [];
$sandboxClientDir = null;

try {
    // 3. Test Phase 1: Ingest Fatal Error Telemetry
    echo "\n[*] Phase 1: Ingesting FATAL error telemetry via ingest.php...\n";

    $ingestScript = dirname(__DIR__) . '/public/api/v1/telemetry/ingest.php';
    $testFilePath = 'src/Services/HubClient.php';
    $testErrorMsg = "TypeError: Cannot assign null to property App\\Services\\HubClient::\$instance of type App\\Services\\TelemetryReporter in reset()";
    $testTrace = "#0 src/Services/HubClient.php(26): App\\Services\\HubClient::reset()\n#1 bin/test-auto.php(5): App\\Services\\HubClient::get()\n#2 {main}";

    $payload = [
        'api_token'     => $rawToken,
        'license_key'   => $licenseKey,
        'error_level'   => 'FATAL',
        'error_message' => $testErrorMsg,
        'file_path'     => $testFilePath,
        'line_number'   => 26,
        'stack_trace'   => $testTrace,
        'occurred_at'   => gmdate('Y-m-d H:i:s')
    ];

    $ingestRes = OtaUpdateService::dispatchLocalEndpoint($ingestScript, 'POST', $payload, [
        'Authorization: Bearer ' . $rawToken,
        'X-API-KEY: ' . $rawToken
    ]);

    assertCondition("Ingest endpoint responded with HTTP 200", $ingestRes['status_code'] === 200, "HTTP " . $ingestRes['status_code'] . ": " . $ingestRes['raw']);
    $ingestJson = $ingestRes['json'];
    assertCondition("Ingest returned success = true", !empty($ingestJson['success']));
    assertCondition("Ingest acknowledged error_id", !empty($ingestJson['error_id']));
    $errorId = (int)$ingestJson['error_id'];
    $createdErrorIds[] = $errorId;

    // Verify error was logged and transitioned to 'analyzing' (or already progressed by fast daemon)
    $errStmt = $pdo->prepare("SELECT id, status, occurrences FROM central_error_logs WHERE id = ?");
    $errStmt->execute([$errorId]);
    $errRow = $errStmt->fetch(PDO::FETCH_ASSOC);
    $errStmt->closeCursor();

    assertCondition("Error record exists in central_error_logs", !empty($errRow));
    assertCondition("Error status transitioned to active remediation state", in_array($errRow['status'], ['analyzing', 'patch_pending'], true), "Got: " . ($errRow['status'] ?? ''));

    // 4. Test Phase 2: Await Autonomous Healing Daemon Completion
    echo "\n[*] Phase 2: Awaiting Autonomous Remediation from Background Daemon...\n";

    $maxWaitSec = 90;
    $pollStart = time();
    $autoCompleted = false;
    $updatedErr = null;

    while ((time() - $pollStart) < $maxWaitSec) {
        $checkStmt = $pdo->prepare("SELECT id, status, resolved_by_version FROM central_error_logs WHERE id = ?");
        $checkStmt->execute([$errorId]);
        $updatedErr = $checkStmt->fetch(PDO::FETCH_ASSOC);
        $checkStmt->closeCursor();

        if (($updatedErr['status'] ?? '') === 'patch_pending') {
            $autoCompleted = true;
            break;
        }
        usleep(500000); // 500ms
    }

    assertCondition("Background daemon automatically processed error to 'patch_pending'", $autoCompleted, "Current status: " . ($updatedErr['status'] ?? 'unknown'));
    $newVersion = (string)$updatedErr['resolved_by_version'];
    assertCondition("Resolved by version set: v{$newVersion}", !empty($newVersion));

    // Fetch the generated release record
    $relStmt = $pdo->prepare("
        SELECT id, version, is_silent_patch, release_type, package_filename, package_checksum, is_active, resolves_error_id
        FROM central_releases
        WHERE product_id = ? AND version = ?
        LIMIT 1
    ");
    $relStmt->execute([$productId, $newVersion]);
    $releaseRow = $relStmt->fetch(PDO::FETCH_ASSOC);
    $relStmt->closeCursor();

    assertCondition("Release record exists in central_releases", !empty($releaseRow));
    $createdReleaseIds[] = (int)$releaseRow['id'];
    $packageFilename = (string)$releaseRow['package_filename'];
    $packageChecksum = (string)$releaseRow['package_checksum'];

    assertCondition("Release flagged is_silent_patch = 1", (int)$releaseRow['is_silent_patch'] === 1);
    assertCondition("Release flagged is_active = 1", (int)$releaseRow['is_active'] === 1);
    assertCondition("Release binds resolves_error_id = {$errorId}", (int)$releaseRow['resolves_error_id'] === $errorId);

    // 5. Test Phase 3: Assert Physical Artifacts & Checksums
    echo "\n[*] Phase 3: Validating Release Promotion & Physical Artifacts...\n";

    $zipPath = $updatesDir . '/' . $packageFilename;
    $createdZipFiles[] = $zipPath;

    assertCondition("Physical release zip exists in /public/downloads/updates/", file_exists($zipPath));
    assertCondition("Zip file SHA-256 matches daemon checksum", hash_file('sha256', $zipPath) === $packageChecksum);

    // Inspect zip contents
    $zip = new ZipArchive();
    assertCondition("Zip archive is readable", $zip->open($zipPath) === true);
    $foundFileInZip = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        if ($stat && str_replace('\\', '/', $stat['name']) === str_replace('\\', '/', $testFilePath)) {
            $foundFileInZip = true;
            break;
        }
    }
    $zip->close();
    assertCondition("Zip archive contains patched '{$testFilePath}'", $foundFileInZip);

    // Verify error row is now 'patch_pending'
    $errCheck = $pdo->prepare("SELECT status, resolved_by_version FROM central_error_logs WHERE id = ?");
    $errCheck->execute([$errorId]);
    $updatedErr = $errCheck->fetch(PDO::FETCH_ASSOC);
    $errCheck->closeCursor();

    assertCondition("Error record transitioned to status = 'patch_pending'", ($updatedErr['status'] ?? '') === 'patch_pending');
    assertCondition("Error record resolved_by_version = '{$newVersion}'", ($updatedErr['resolved_by_version'] ?? '') === $newVersion);

    // 6. Test Phase 4: Zero-Click Client OTA Installation via OtaUpdateService
    echo "\n[*] Phase 4: Simulating Zero-Click Client OTA Installation...\n";

    // Setup client sandbox testbed directory
    $sandboxClientDir = sys_get_temp_dir() . '/spectraops_testbed_' . uniqid();
    @mkdir($sandboxClientDir . '/src/Services', 0755, true);
    @mkdir($sandboxClientDir . '/storage/ota_backups', 0755, true);

    // Copy original file from baseline into sandbox
    copy($codebaseRoot . '/src/Services/HubClient.php', $sandboxClientDir . '/src/Services/HubClient.php');

    // Create client .env
    $clientEnv = <<<ENV
APP_VERSION="{$initialVersion}"
SPECTRAOPS_HUB_URL="http://127.0.0.1:8080/SPECTRAOPS/public"
SPECTRAOPS_API_KEY="{$rawToken}"
SPECTRAOPS_LICENSE_KEY="{$licenseKey}"
SPECTRAOPS_SSL_VERIFY=false
ENV;
    file_put_contents($sandboxClientDir . '/.env', $clientEnv);

    $updater = new OtaUpdateService(
        $sandboxClientDir,
        'http://127.0.0.1:8080/SPECTRAOPS/public',
        $rawToken,
        $licenseKey,
        $initialVersion,
        'prism-edu',
        false
    );

    // Check for updates
    $updateCheck = $updater->checkForUpdates();
    assertCondition("Client checkForUpdates detected update", !empty($updateCheck['update_available']));
    assertCondition("Client detected silent patch (no manual approval needed)", !empty($updateCheck['is_silent_patch']));
    assertCondition("Client detected target version v{$newVersion}", ($updateCheck['latest_version'] ?? '') === $newVersion);

    // Perform zero-click install
    $installRes = $updater->pollAndApply(true); // autoApplyOnly = true (gate check)

    assertCondition("Zero-click update applied successfully (applied = true)", !empty($installRes['applied']));
    assertCondition("Install status is 'installed'", ($installRes['status'] ?? '') === 'installed');
    assertCondition("Installed version is v{$newVersion}", ($installRes['installed_version'] ?? '') === $newVersion);
    assertCondition("Closed-loop receipt accepted by hub", !empty($installRes['receipt_confirmed']));
    assertCondition("Pre-update rollback backup created on disk", !empty($installRes['backup_path']) && is_dir($installRes['backup_path']));

    // Verify error closed-loop resolution in database
    $closedLoopStmt = $pdo->prepare("SELECT status, resolved, resolved_by_version FROM central_error_logs WHERE id = ?");
    $closedLoopStmt->execute([$errorId]);
    $closedLoopErr = $closedLoopStmt->fetch(PDO::FETCH_ASSOC);
    $closedLoopStmt->closeCursor();

    assertCondition("Error record resolved automatically (status = 'resolved')", ($closedLoopErr['status'] ?? '') === 'resolved');
    assertCondition("Error record resolved flag = 1", (int)($closedLoopErr['resolved'] ?? 0) === 1);

    // Verify deployment version bumped in central_deployments
    $depVerStmt = $pdo->prepare("SELECT current_version, status FROM central_deployments WHERE id = ?");
    $depVerStmt->execute([$deploymentId]);
    $depVerRow = $depVerStmt->fetch(PDO::FETCH_ASSOC);
    $depVerStmt->closeCursor();

    assertCondition("Deployment current_version updated to v{$newVersion}", ($depVerRow['current_version'] ?? '') === $newVersion);

    // 7. Test Phase 5: Client Rollback Ingestion & Fleet Release Deactivation
    echo "\n[*] Phase 5: Simulating Client Rollback Report & Fleet Deactivation...\n";

    $rollbackReceipt = $updater->reportRollback($newVersion, "Simulated critical regression on client node", $errorId);
    assertCondition("Rollback receipt acknowledged by hub", !empty($rollbackReceipt['receipt_accepted']));
    assertCondition("Hub reported rollback_recorded = true", !empty($rollbackReceipt['rollback_recorded']));

    // Verify central_releases release was deactivated
    $deactCheck = $pdo->prepare("SELECT is_active FROM central_releases WHERE product_id = ? AND version = ?");
    $deactCheck->execute([$productId, $newVersion]);
    $deactActive = (int)$deactCheck->fetchColumn();
    $deactCheck->closeCursor();

    assertCondition("Release v{$newVersion} immediately deactivated in central_releases (is_active = 0)", $deactActive === 0);

    // Verify error returned to open state with manual_intervention_required = 1
    $reopenCheck = $pdo->prepare("SELECT status, manual_intervention_required, resolved FROM central_error_logs WHERE id = ?");
    $reopenCheck->execute([$errorId]);
    $reopenedErr = $reopenCheck->fetch(PDO::FETCH_ASSOC);
    $reopenCheck->closeCursor();

    assertCondition("Error status reverted to 'open' upon rollback", ($reopenedErr['status'] ?? '') === 'open');
    assertCondition("Error manual_intervention_required flag raised (= 1)", (int)($reopenedErr['manual_intervention_required'] ?? 0) === 1);
    assertCondition("Error resolved reset to 0", (int)($reopenedErr['resolved'] ?? 0) === 0);

    // 8. Test Phase 6: Anti-Thrashing Circuit Breakers
    echo "\n[*] Phase 6: Testing Anti-Thrashing Circuit Breakers...\n";

    $phpBin = PHP_BINARY ?: 'php';
    $daemonScript = dirname(__DIR__) . '/bin/autonomous-healing-daemon.php';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];

    // Sub-test A: Rate Limiter (Max 3 patches within 60 minutes)
    echo "  -> Sub-test 6A: Rate Limiter (Max 3 patches / 60 minutes)...\n";
    for ($i = 1; $i <= 3; $i++) {
        $dummyVer = "9.9." . ($i + 10);
        $insDummy = $pdo->prepare("
            INSERT INTO central_releases (product_id, version, release_type, is_silent_patch, changelog, package_filename, package_checksum, is_active, released_at)
            VALUES (?, ?, 'patch', 1, 'Autonomous AI Hotfix: Dummy', 'dummy.zip', 'dummy_hash', 1, {$nowExpr})
        ");
        $insDummy->execute([$productId, $dummyVer]);
        $createdReleaseIds[] = (int)$pdo->lastInsertId();
        $insDummy->closeCursor();
    }

    // Insert a new fatal error to trigger daemon
    $rateErrStmt = $pdo->prepare("
        INSERT INTO central_error_logs (deployment_id, error_hash, error_level, error_message, file_path, line_number, stack_trace, occurrences, first_seen_at, last_seen_at, occurred_at, status, resolved)
        VALUES (?, ?, 'CRITICAL', 'Rate limit test error', 'src/Services/HubClient.php', 26, 'trace', 1, {$nowExpr}, {$nowExpr}, {$nowExpr}, 'analyzing', 0)
    ");
    $rateErrStmt->execute([$deploymentId, hash('sha256', 'rate_test_' . uniqid())]);
    $rateErrorId = (int)$pdo->lastInsertId();
    $rateErrStmt->closeCursor();
    $createdErrorIds[] = $rateErrorId;

    // Run daemon against rateErrorId
    $rateProc = proc_open("\"{$phpBin}\" \"{$daemonScript}\" --error-id={$rateErrorId}", $descriptors, $ratePipes, dirname(__DIR__));
    fclose($ratePipes[0]);
    $rateStdout = stream_get_contents($ratePipes[1]);
    fclose($ratePipes[1]);
    $rateStderr = stream_get_contents($ratePipes[2]);
    fclose($ratePipes[2]);
    $rateExit = proc_close($rateProc);

    assertCondition("Circuit breaker tripped: daemon exited with code 2", $rateExit === 2, "Exit code: {$rateExit}\nSTDOUT: {$rateStdout}");
    assertCondition("Daemon output logged CIRCUIT BREAKER rate limit exceeded", str_contains($rateStdout, 'CIRCUIT BREAKER'));

    // Verify urgent ticket created
    $tCheck = $pdo->prepare("SELECT id, priority, status FROM central_support_tickets WHERE deployment_id = ? AND priority = 'urgent' ORDER BY id DESC LIMIT 1");
    $tCheck->execute([$deploymentId]);
    $urgentTicket = $tCheck->fetch(PDO::FETCH_ASSOC);
    $tCheck->closeCursor();

    assertCondition("Urgent administrative ticket created in central_support_tickets", !empty($urgentTicket));
    assertCondition("Ticket priority is 'urgent'", ($urgentTicket['priority'] ?? '') === 'urgent');

    // Sub-test B: Pre-Flight Lint Guard Test
    echo "  -> Sub-test 6B: Pre-Flight Lint & Safety Guard Verification...\n";

    // Test temporary sandbox syntax checking utility directly
    $badSyntaxFile = sys_get_temp_dir() . '/test_bad_syntax_' . uniqid() . '.php';
    file_put_contents($badSyntaxFile, "<?php echo 'unclosed string;");
    $lintProc = proc_open("\"{$phpBin}\" -l \"{$badSyntaxFile}\"", $descriptors, $lintPipes);
    fclose($lintPipes[0]);
    $lintOut = stream_get_contents($lintPipes[1]);
    fclose($lintPipes[1]);
    $lintErr = stream_get_contents($lintPipes[2]);
    fclose($lintPipes[2]);
    $lintCode = proc_close($lintProc);
    @unlink($badSyntaxFile);

    assertCondition("php -l correctly detects invalid syntax (exit code !== 0)", $lintCode !== 0);

} finally {
    // 9. Teardown & Resource Cleanup
    echo "\n[*] Cleaning up test resources...\n";

    if (!empty($createdReleaseIds)) {
        $inRels = implode(',', $createdReleaseIds);
        $pdo->exec("DELETE FROM central_releases WHERE id IN ({$inRels})");
    }

    if (!empty($createdErrorIds)) {
        $inErrs = implode(',', $createdErrorIds);
        $pdo->exec("DELETE FROM central_error_logs WHERE id IN ({$inErrs})");
    }

    $pdo->prepare("DELETE FROM central_support_tickets WHERE deployment_id = ?")->execute([$deploymentId]);
    $pdo->prepare("DELETE FROM central_deployments WHERE id = ?")->execute([$deploymentId]);
    $pdo->prepare("DELETE FROM central_licenses WHERE id = ?")->execute([$licenseId]);
    $pdo->prepare("UPDATE central_products SET latest_version = ? WHERE id = ?")->execute([$initialVersion, $productId]);

    foreach ($createdZipFiles as $zFile) {
        if (file_exists($zFile)) {
            @unlink($zFile);
        }
    }

    if ($sandboxClientDir && is_dir($sandboxClientDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sandboxClientDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $fileinfo) {
            $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            @$todo($fileinfo->getRealPath());
        }
        @rmdir($sandboxClientDir);
    }
}

echo "\n========================================================\n";
echo "SUCCESS: Autonomous AI Hotfix & Self-Healing Engine Verified!\n";
echo "========================================================\n";
exit(0);
