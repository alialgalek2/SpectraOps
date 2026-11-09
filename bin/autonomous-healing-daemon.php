<?php
declare(strict_types=1);

/**
 * SpectraOps Fully Autonomous AI Hotfix & Self-Healing Dispatch Daemon
 * 
 * Invoked headless by telemetry ingestion or CLI:
 *   php bin/autonomous-healing-daemon.php --error-id={id}
 * 
 * Pipeline:
 *   Context Extraction -> Anti-Thrashing Guard -> Gemini Flash Patch Synthesis ->
 *   Pre-Flight Lint (php -l) -> AST Security Guard -> Release Packaging ->
 *   Atomic Promotion (central_releases) -> Fleet Wake-Up Signal
 */

$logFile = dirname(__DIR__) . '/storage/logs/autonomous_healing.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function daemonLog(string $msg): void {
    global $logFile;
    $entry = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $msg;
    @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') {
        echo $entry . PHP_EOL;
    }
}

// 1. Parse Input Options
$shortopts = "e:h";
$longopts = ["error-id:", "sweep-all", "force", "help"];
$options = getopt($shortopts, $longopts);

$isSweepAll = isset($options['sweep-all']) || in_array('--sweep-all', $argv, true);

$errorId = 0;
if (isset($options['error-id'])) {
    $errorId = (int)$options['error-id'];
} elseif (isset($options['e'])) {
    $errorId = (int)$options['e'];
}

if ($errorId <= 0 && !$isSweepAll) {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--error-id=')) {
            $errorId = (int)substr($arg, 11);
            break;
        } elseif (is_numeric($arg) && (int)$arg > 0) {
            $errorId = (int)$arg;
            break;
        }
    }
}

if ($errorId <= 0 && !$isSweepAll) {
    daemonLog("[DAEMON ABORT] Missing or invalid --error-id argument.");
    if (PHP_SAPI === 'cli') {
        echo "Usage: php bin/autonomous-healing-daemon.php --error-id={id} OR --sweep-all\n";
    }
    exit(1);
}

// 2. Initialize Database & Context
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/services/GeminiHotfixAgent.php';

try {
    $pdo = \FleetHub\Database::getConnection();
} catch (\Throwable $dbEx) {
    daemonLog("[FATAL] Database connection failed: " . $dbEx->getMessage());
    exit(1);
}

$isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
$nowExpr = $isSqlite ? "datetime('now')" : "UTC_TIMESTAMP()";

/**
 * Sweeps all pending/open unhandled errors across the fleet and spawns autonomous healing
 */
if ($isSweepAll) {
    daemonLog("[DAEMON SWEEP] Scanning fleet for unresolved unhandled error incidents...");

    $stmtOpen = $pdo->prepare("
        SELECT id, deployment_id, file_path, line_number, error_message, error_level
        FROM central_error_logs
        WHERE (resolved = 0 OR resolved IS NULL)
          AND (status IN ('open', 'analyzing') OR status IS NULL)
          AND (manual_intervention_required = 0 OR manual_intervention_required IS NULL)
          AND error_level IN ('FATAL', 'ERROR', 'CRITICAL')
        ORDER BY id ASC
        LIMIT 25
    ");
    $stmtOpen->execute();
    $candidates = $stmtOpen->fetchAll(PDO::FETCH_ASSOC);
    $stmtOpen->closeCursor();

    $dispatched = 0;
    $phpBin = PHP_BINARY ?: 'php';
    $daemonScript = __FILE__;

    foreach ($candidates as $cand) {
        $candId = (int)$cand['id'];

        // Ensure status is marked analyzing
        $pdo->prepare("UPDATE central_error_logs SET status = 'analyzing' WHERE id = ?")->execute([$candId]);

        // Launch background daemon per error
        if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
            pclose(popen("start /B \"\" \"{$phpBin}\" \"{$daemonScript}\" --error-id={$candId} > NUL 2>&1", "r"));
        } else {
            exec("\"{$phpBin}\" \"{$daemonScript}\" --error-id={$candId} > /dev/null 2>&1 &");
        }
        $dispatched++;
        daemonLog("[DAEMON SWEEP] Dispatched autonomous healing worker for Error ID #{$candId}");
    }

    echo json_encode([
        'success'           => true,
        'action'            => 'sweep_autonomous_healing',
        'errors_dispatched' => $dispatched,
        'message'           => "Autonomous healing sweep completed: {$dispatched} error worker(s) dispatched."
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit(0);
}

daemonLog("[DAEMON START] Ingesting remediation task for Error ID #{$errorId}");

// 3. Step A: Context Extraction & Validation
$stmt = $pdo->prepare("
    SELECT e.*, 
           d.id AS deployment_id, d.deployment_domain, d.current_version AS deployment_version, 
           d.api_ingest_token, d.api_ingest_token_hash,
           p.id AS product_id, p.product_slug, p.product_name, p.latest_version AS product_latest_version
    FROM central_error_logs e
    JOIN central_deployments d ON e.deployment_id = d.id
    JOIN central_products p ON d.product_id = p.id
    WHERE e.id = ?
    LIMIT 1
");
$stmt->execute([$errorId]);
$errorRow = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();

if (!$errorRow) {
    daemonLog("[DAEMON ABORT] Error record #{$errorId} not found in database.");
    exit(1);
}

$productId = (int)$errorRow['product_id'];
$productSlug = (string)$errorRow['product_slug'];
$deploymentId = (int)$errorRow['deployment_id'];
$rawFilePath = (string)($errorRow['file_path'] ?? '');
$errorMessage = (string)($errorRow['error_message'] ?? '');
$stackTrace = (string)($errorRow['stack_trace'] ?? '');

// Skip if already resolved
if ((int)($errorRow['resolved'] ?? 0) === 1 || ($errorRow['status'] ?? '') === 'resolved') {
    daemonLog("[DAEMON SKIP] Error #{$errorId} is already marked as resolved.");
    exit(0);
}

// 4. Anti-Thrashing Circuit Breakers
// Rule A: Rate Limit - Max 3 automated hotfixes per product within rolling 60 minutes
$rolling60Expr = $isSqlite ? "datetime('now', '-60 minutes')" : "DATE_SUB(NOW(), INTERVAL 60 MINUTE)";
$rateCheckStmt = $pdo->prepare("
    SELECT COUNT(*) FROM central_releases
    WHERE product_id = ? 
      AND (is_silent_patch = 1 OR changelog LIKE '%Autonomous AI Hotfix%')
      AND released_at >= {$rolling60Expr}
");
$rateCheckStmt->execute([$productId]);
$recentPatchCount = (int)$rateCheckStmt->fetchColumn();
$rateCheckStmt->closeCursor();

if ($recentPatchCount >= 3) {
    daemonLog("[CIRCUIT BREAKER] Rate limit exceeded: {$recentPatchCount} hotfixes generated for '{$productSlug}' in the past 60 minutes.");

    // File urgent administrative support ticket
    $ticketRef = 'CIRCUIT-BREAK-' . strtoupper(bin2hex(random_bytes(3)));
    $insTicket = $pdo->prepare("
        INSERT INTO central_support_tickets
        (deployment_id, ticket_reference, requester_name, requester_email, requester_role, category, priority, subject, message, status, created_at, updated_at)
        VALUES (?, ?, 'Autonomous Healing Engine', 'system-daemon@spectraops.hub', 'System', 'incident', 'urgent', ?, ?, 'open', {$nowExpr}, {$nowExpr})
    ");
    $insTicket->execute([
        $deploymentId,
        $ticketRef,
        "Circuit Breaker Tripped: Rate Limit Exceeded for {$productSlug}",
        "Automated hotfix rate limit exceeded ({$recentPatchCount} patches created within 60 minutes) for product {$productSlug}. Auto-dispatch paused. Manual intervention required for error log #{$errorId}."
    ]);
    $insTicket->closeCursor();

    $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'open', manual_intervention_required = 1 
        WHERE id = ?
    ")->execute([$errorId]);

    daemonLog("[CIRCUIT BREAKER] Incident ticket #{$ticketRef} dispatched. Daemon execution suspended.");
    exit(2);
}

// Rule B: Single-Error Retry Ceiling - If error received automated patch within 10 min without resolution
$tenMinExpr = $isSqlite ? "datetime('now', '-10 minutes')" : "DATE_SUB(NOW(), INTERVAL 10 MINUTE)";
$recentRelCheck = $pdo->prepare("
    SELECT id, version, released_at FROM central_releases
    WHERE resolves_error_id = ? AND released_at >= {$tenMinExpr}
    ORDER BY released_at DESC LIMIT 1
");
$recentRelCheck->execute([$errorId]);
$alreadyPatchedRecent = $recentRelCheck->fetch(PDO::FETCH_ASSOC);
$recentRelCheck->closeCursor();

$occurrences = (int)($errorRow['occurrences'] ?? 1);
$prevResolvedBy = $errorRow['resolved_by_version'] ?? null;

if ($alreadyPatchedRecent || ($occurrences > 3 && !empty($prevResolvedBy))) {
    daemonLog("[RETRY CEILING] Error #{$errorId} already received automated patch or exceeded retry threshold without resolution. Marking as failed_auto_repair.");
    $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'failed_auto_repair', manual_intervention_required = 1 
        WHERE id = ?
    ")->execute([$errorId]);
    exit(3);
}

// 5. Codebase Context Assembly & Resolution
$cleanRelPath = ltrim(str_replace(['\\', '/'], '/', $rawFilePath), '/');
$baselineRoot = dirname(__DIR__) . '/storage/codebases/' . $productSlug;
$targetBaselineFile = $baselineRoot . '/' . $cleanRelPath;

if (!file_exists($targetBaselineFile) || !is_file($targetBaselineFile)) {
    // Check common subdirectories or stripped paths
    $candidatePaths = [
        $baselineRoot . '/' . basename($cleanRelPath),
        $baselineRoot . '/src/' . $cleanRelPath,
        $baselineRoot . '/' . preg_replace('#^src/#', '', $cleanRelPath)
    ];
    $found = false;
    foreach ($candidatePaths as $cand) {
        if (file_exists($cand) && is_file($cand)) {
            $targetBaselineFile = $cand;
            $cleanRelPath = ltrim(substr($cand, strlen($baselineRoot)), '/\\');
            $found = true;
            break;
        }
    }

    if (!$found) {
        daemonLog("[CONTEXT FAIL] Target file '{$cleanRelPath}' not found in clean reference codebase at {$baselineRoot}.");
        $pdo->prepare("
            UPDATE central_error_logs 
            SET status = 'failed_auto_repair', manual_intervention_required = 1 
            WHERE id = ?
        ")->execute([$errorId]);
        exit(4);
    }
}

$originalSource = file_get_contents($targetBaselineFile);
if ($originalSource === false || trim($originalSource) === '') {
    daemonLog("[CONTEXT FAIL] Unable to read baseline source file '{$targetBaselineFile}'.");
    $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'failed_auto_repair', manual_intervention_required = 1 
        WHERE id = ?
    ")->execute([$errorId]);
    exit(4);
}

// 6. Step B: Gemini 2.5 / 3.8 Flash Diagnostic & Patch Synthesis
$apiKey = getenv('GEMINI_API_KEY') ?: '';
$model = getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash';
$updatesDir = dirname(__DIR__) . '/public/downloads/updates';
if (!is_dir($updatesDir)) {
    @mkdir($updatesDir, 0755, true);
}
$codebasesDir = dirname(__DIR__) . '/storage/codebases';

$agent = new \App\Services\GeminiHotfixAgent($apiKey, $codebasesDir, $updatesDir, $model);
$currentVersion = $errorRow['product_latest_version'] ?: ($errorRow['deployment_version'] ?: '1.0.0');

daemonLog("[AI AGENT] Synthesizing patch using {$model} for {$productSlug} ({$cleanRelPath})...");
$startTime = microtime(true);

try {
    $fixResult = $agent->generateFix($productSlug, $errorMessage, $cleanRelPath, $stackTrace, $currentVersion);
} catch (\Throwable $aiEx) {
    daemonLog("[AI ERROR] Gemini patch synthesis failed: " . $aiEx->getMessage());
    $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'open', manual_intervention_required = 1 
        WHERE id = ?
    ")->execute([$errorId]);
    exit(5);
}

$elapsed = round(microtime(true) - $startTime, 2);
daemonLog("[AI AGENT] Gemini synthesized patch in {$elapsed}s.");

$patchedContent = $fixResult['patched_content'] ?? ($fixResult['corrected_code'] ?? '');
$explanation = $fixResult['explanation'] ?? ($fixResult['fix_summary'] ?? ($fixResult['root_cause'] ?? 'Autonomous AI hotfix'));
$targetFile = $fixResult['target_file'] ?? ($fixResult['fixed_file_path'] ?? $cleanRelPath);
$newVersion = $fixResult['new_version'];

if (trim($patchedContent) === '') {
    daemonLog("[AI ERROR] Gemini returned empty patch content.");
    $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'open', manual_intervention_required = 1 
        WHERE id = ?
    ")->execute([$errorId]);
    exit(5);
}

// 7. Step C: Automated Pre-Flight Lint & Safety Guardrails
$sandboxDir = dirname(__DIR__) . "/storage/hotfixes/{$productSlug}/sandbox";
if (!is_dir($sandboxDir)) {
    @mkdir($sandboxDir, 0755, true);
}

$tempSandboxFile = $sandboxDir . "/patch_{$errorId}_" . time() . "_" . bin2hex(random_bytes(4)) . ".php";
file_put_contents($tempSandboxFile, $patchedContent);

// Pre-Flight Check 1: PHP CLI Linter (php -l)
$phpBin = PHP_BINARY ?: 'php';
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w']
];
$lintProcess = proc_open("\"{$phpBin}\" -l \"{$tempSandboxFile}\"", $descriptors, $pipes);
$lintStdout = '';
$lintStderr = '';
$lintExitCode = -1;

if (is_resource($lintProcess)) {
    fclose($pipes[0]);
    $lintStdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $lintStderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $lintExitCode = proc_close($lintProcess);
}

if ($lintExitCode !== 0) {
    daemonLog("[LINT FAILED] Pre-flight syntax validation failed (Exit Code {$lintExitCode}): " . trim($lintStderr . " " . $lintStdout));
    @unlink($tempSandboxFile);
    $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'open', manual_intervention_required = 1 
        WHERE id = ?
    ")->execute([$errorId]);
    exit(6);
}
daemonLog("[LINT PASSED] Pre-flight syntax check verified zero parse errors.");

// Pre-Flight Check 2: AST / Safety Checks (Dangerous Function Rejection)
$dangerousFuncs = ['eval', 'exec', 'shell_exec', 'passthru', 'system', 'popen', 'proc_open', 'pcntl_exec'];
$dangerousDetected = false;
$detectedCall = '';

foreach ($dangerousFuncs as $func) {
    if (preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $patchedContent)) {
        if (!preg_match('/\b' . preg_quote($func, '/') . '\s*\(/i', $originalSource)) {
            $dangerousDetected = true;
            $detectedCall = $func;
            break;
        }
    }
}

if ($dangerousDetected) {
    daemonLog("[SAFETY VIOLATION] Patch introduces unauthorized execution call '{$detectedCall}()'. Rejecting patch.");
    @unlink($tempSandboxFile);
    $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'open', manual_intervention_required = 1 
        WHERE id = ?
    ")->execute([$errorId]);
    exit(7);
}

// Pre-Flight Check 3: License Verification Protection
if (!str_contains($cleanRelPath, 'License') && !str_contains($cleanRelPath, 'licensing')) {
    if (preg_match('/\b(verifyLicense|validateLicense|license_status|central_licenses|lease_token)\s*=/i', $patchedContent) &&
        !preg_match('/\b(verifyLicense|validateLicense|license_status|central_licenses|lease_token)\s*=/i', $originalSource)) {
        daemonLog("[SAFETY VIOLATION] Patch attempts unauthorized modification of license verification logic. Rejecting patch.");
        @unlink($tempSandboxFile);
        $pdo->prepare("
            UPDATE central_error_logs 
            SET status = 'open', manual_intervention_required = 1 
            WHERE id = ?
        ")->execute([$errorId]);
        exit(8);
    }
}

@unlink($tempSandboxFile);

// 8. Step D: Packaging, Promotion & Release Publication
$zipFilename = "{$productSlug}-v{$newVersion}.zip";
$zipPath = $updatesDir . '/' . $zipFilename;

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    daemonLog("[PACKAGING FAIL] Could not initialize zip archive at '{$zipPath}'.");
    exit(9);
}
$zip->addFromString($targetFile, $patchedContent);
$zip->close();

$packageChecksum = hash_file('sha256', $zipPath);
daemonLog("[PACKAGED] Built release zip '{$zipFilename}' with SHA-256: {$packageChecksum}");

// Atomic Database Transaction
$pdo->beginTransaction();
try {
    // 1. Insert into central_releases
    $insRel = $pdo->prepare("
        INSERT INTO central_releases
        (product_id, version, release_type, is_silent_patch, resolves_error_id, changelog, package_filename, package_checksum, is_active, released_at)
        VALUES (?, ?, 'patch', 1, ?, ?, ?, ?, 1, {$nowExpr})
    ");
    $insRel->execute([
        $productId,
        $newVersion,
        $errorId,
        "Autonomous AI Hotfix: " . substr($explanation, 0, 255),
        $zipFilename,
        $packageChecksum
    ]);
    $releaseId = (int)$pdo->lastInsertId();
    $insRel->closeCursor();

    // 2. Update central_products.latest_version
    $updProd = $pdo->prepare("UPDATE central_products SET latest_version = ? WHERE id = ?");
    $updProd->execute([$newVersion, $productId]);
    $updProd->closeCursor();

    // 3. Update central_error_logs: status = 'patch_pending', resolved_by_version = $newVersion
    $updErr = $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'patch_pending', resolved_by_version = ? 
        WHERE id = ?
    ");
    $updErr->execute([$newVersion, $errorId]);
    $updErr->closeCursor();

    $pdo->commit();
    daemonLog("[PROMOTED] Release #{$releaseId} (v{$newVersion}) successfully promoted to central_releases (is_silent_patch = 1).");
} catch (\Throwable $txEx) {
    $pdo->rollBack();
    daemonLog("[DB TRANSACTION FAILED] " . $txEx->getMessage());
    exit(10);
}

// 9. Step E: Real-Time Dispatch / Wake-Up Signal (Optional Push)
$domain = trim((string)($errorRow['deployment_domain'] ?? ''));
$ingestToken = (string)($errorRow['api_ingest_token'] ?? '');

if (!empty($domain) && $domain !== 'CLI' && !str_starts_with($domain, 'mock://')) {
    daemonLog("[DISPATCH] Issuing real-time OTA wake signal to node '{$domain}'...");
    $wakeUrl = (str_starts_with($domain, 'http://') || str_starts_with($domain, 'https://'))
        ? rtrim($domain, '/') . '/api/v1/ota/wake'
        : 'http://' . rtrim($domain, '/') . '/api/v1/ota/wake';

    $ch = curl_init($wakeUrl);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action'  => 'poll_now',
        'version' => $newVersion,
        'release' => [
            'version'           => $newVersion,
            'is_silent_patch'   => true,
            'package_filename'  => $zipFilename,
            'package_checksum'  => $packageChecksum,
            'resolves_error_id' => $errorId
        ]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-KEY: ' . $ingestToken,
        'Authorization: Bearer ' . $ingestToken
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    @curl_exec($ch);
    unset($ch);
}

// 10. Output Structured Result
$resultPayload = [
    'success'          => true,
    'status'           => 'published',
    'error_id'         => $errorId,
    'product_slug'     => $productSlug,
    'release_version'  => $newVersion,
    'package_filename' => $zipFilename,
    'package_checksum' => $packageChecksum,
    'target_file'      => $targetFile,
    'is_silent_patch'  => 1
];

echo json_encode($resultPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
daemonLog("[DAEMON COMPLETE] Hotfix pipeline finished successfully for Error #{$errorId}.");
exit(0);
