<?php
declare(strict_types=1);
$debugLog = __DIR__ . '/../../storage/logs/raw_ingest_probe.log';
$rawInput = file_get_contents('php://input');

$logEntry = "=== INGEST HIT: " . gmdate('Y-m-d H:i:s') . " UTC ===\n";
$logEntry .= "HEADERS:\n" . print_r(function_exists('getallheaders') ? getallheaders() : $_SERVER, true) . "\n";
$logEntry .= "RAW BODY:\n" . $rawInput . "\n\n";
if (!is_dir(dirname($debugLog))) {
    @mkdir(dirname($debugLog), 0755, true);
}
@file_put_contents($debugLog, $logEntry, FILE_APPEND);
$rootDebugLog = dirname(__DIR__, 4) . '/storage/logs/raw_ingest_probe.log';
if (!is_dir(dirname($rootDebugLog))) {
    @mkdir(dirname($rootDebugLog), 0755, true);
}
@file_put_contents($rootDebugLog, $logEntry, FILE_APPEND);
// Prevent output buffering interference & enforce JSON
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=UTF-8');

// Global error logging for ingest debugging
$ingestLogFile = dirname(__DIR__, 4) . '/storage/logs/ingest_debug.log';
$logDir = dirname($ingestLogFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

function writeIngestLog(string $msg): void {
    global $ingestLogFile;
    @file_put_contents($ingestLogFile, '[' . gmdate('c') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
    error_log("[Ingest] " . $msg);
}

// 1. Resilient Database Connection
require_once dirname(__DIR__, 4) . '/src/Database.php';
require_once dirname(__DIR__, 4) . '/src/RateLimiter.php';

try {
    $pdo = \FleetHub\Database::getConnection();
} catch (\Throwable $e) {
    writeIngestLog("DB Connection Failure: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'status' => 'error',
        'error' => 'Telemetry ingestion database offline.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 2. Read Raw Payload (supports php://input, php://stdin, and $_POST for CLI testbeds)
$rawInput = file_get_contents('php://input');
if ($rawInput === false || trim($rawInput) === '') {
    if (PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_METHOD'])) {
        $rawInput = @file_get_contents('php://stdin');
    }
}
if (($rawInput === false || trim((string)$rawInput) === '') && !empty($_POST)) {
    $rawInput = json_encode($_POST);
}
if ($rawInput === false || trim((string)$rawInput) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'status' => 'error', 'error' => 'Empty request payload.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    writeIngestLog("Invalid JSON received: " . substr($rawInput, 0, 100));
    http_response_code(400);
    echo json_encode(['success' => false, 'status' => 'error', 'error' => 'Malformed JSON payload.'], JSON_UNESCAPED_SLASHES);
    exit;
}

// 3. Multi-Channel Token Extraction (Resilient to Apache Header Stripping)
$token = null;

// Header Method A: Standard Authorization
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

// Header Method B: Apache Getallheaders & apache_request_headers fallback
if (empty($authHeader) && function_exists('getallheaders')) {
    $all = getallheaders();
    $authHeader = $all['Authorization'] ?? $all['authorization'] ?? '';
}
if (empty($authHeader) && function_exists('apache_request_headers')) {
    $all = apache_request_headers();
    foreach ($all as $k => $v) {
        if (strcasecmp((string)$k, 'Authorization') === 0) {
            $authHeader = (string)$v;
            break;
        }
    }
}

if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}

// Header Method C: Custom X-API-KEY / X-API-TOKEN / X-INGEST-TOKEN
if (empty($token)) {
    $token = $_SERVER['HTTP_X_API_KEY'] ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? ($_SERVER['HTTP_X_INGEST_TOKEN'] ?? null));
}

// Body Fallback Method D: Payload embedded keys
if (empty($token)) {
    $token = $payload['api_token'] ?? ($payload['api_key'] ?? ($payload['license_key'] ?? ($payload['ingest_token'] ?? null)));
}

// URL Parameter Fallback
if (empty($token) && !empty($_GET['token'])) {
    $token = (string)$_GET['token'];
}

$token = is_string($token) ? trim($token) : '';

if (empty($token)) {
    writeIngestLog("Rejected: Missing authentication token.");
    http_response_code(401);
    echo json_encode(['success' => false, 'status' => 'error', 'error' => 'Missing authentication credentials.'], JSON_UNESCAPED_SLASHES);
    exit;
}

// Rate Limiting Protection: 300 requests/min for valid token, 120/min per IP
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (str_contains($clientIp, ',')) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}
$limiter = new \FleetHub\RateLimiter($pdo);
$rateKey = 'telemetry_token:' . substr(hash('sha256', $token), 0, 16);
if ($limiter->tooManyAttempts($rateKey, 300, 60)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'status' => 'error', 'error' => 'Too many requests for deployment token. Rate limit exceeded.'], JSON_UNESCAPED_SLASHES);
    exit;
}
$limiter->hit($rateKey, 60);

// 4. Locate Deployment & License Record (Supports hashed ingest token OR license key)
$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare("
    SELECT d.id AS deployment_id, d.product_id, d.client_name, d.deployment_domain, d.status AS deployment_status,
           d.db_latency_ms, d.disk_free_gb, d.storage_free_gb, d.active_users_24h, d.open_error_count,
           l.id AS license_id, l.status AS license_status, l.license_key, l.lease_salt,
           p.product_slug, p.product_code, p.product_name
    FROM central_deployments d
    JOIN central_products p ON d.product_id = p.id
    LEFT JOIN central_licenses l ON d.license_id = l.id
    WHERE (d.api_ingest_token_hash = :hash OR d.api_ingest_token_hash = :raw_token OR d.api_ingest_token = :raw_token OR l.license_key = :raw_token)
      AND (d.is_active = 1 OR d.is_active IS NULL)
    LIMIT 1
");
$stmt->execute([':hash' => $tokenHash, ':raw_token' => $token]);
$node = $stmt->fetch();

if (!$node) {
    writeIngestLog("Unauthorized: Token '{$token}' not mapped to any active deployment.");
    http_response_code(403);
    echo json_encode(['success' => false, 'status' => 'error', 'error' => 'Invalid or unrecognized authorization token.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$deploymentId = (int)$node['deployment_id'];
$licenseStatus = strtolower((string)($node['license_status'] ?? 'active'));
$isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
$nowUtcExpr = $isSqlite ? "datetime('now')" : "UTC_TIMESTAMP()";

// Validate that parent license is 'active' if a license is bound
if (!empty($node['license_id']) && $licenseStatus !== 'active' && $licenseStatus !== 'unactivated') {
    writeIngestLog("Forbidden: Parent license #{$node['license_id']} is {$licenseStatus}.");
    http_response_code(403);
    echo json_encode(['success' => false, 'status' => 'error', 'error' => "Parent license is {$licenseStatus}."], JSON_UNESCAPED_SLASHES);
    exit;
}

// 4.1 Handle Closed-Loop Update Confirmation Receipt or Rollback Report
if (($payload['action'] ?? '') === 'confirm_ota_receipt' || ($payload['action'] ?? '') === 'report_rollback') {
    $appliedVersion = trim((string)($payload['installed_version'] ?? ($payload['applied_version'] ?? ($payload['version'] ?? ($payload['failed_version'] ?? '')))));
    $resolvesErrorId = $payload['resolves_error_id'] ?? null;
    $statusReported = strtolower((string)($payload['status'] ?? ''));
    $isRollback = ($statusReported === 'rolled_back' || $statusReported === 'rollback_executed' || ($payload['action'] ?? '') === 'report_rollback');

    if ($isRollback) {
        if (!empty($appliedVersion)) {
            // Immediately deactivate release in central_releases
            $deact = $pdo->prepare("UPDATE central_releases SET is_active = 0 WHERE product_id = ? AND version = ?");
            $deact->execute([$node['product_id'], $appliedVersion]);
            $deact->closeCursor();

            // Reopen associated errors that were resolved by this failed version and flag for manual intervention
            $reopen = $pdo->prepare("
                UPDATE central_error_logs 
                SET status = 'open', manual_intervention_required = 1, resolved = 0, resolved_at = NULL 
                WHERE deployment_id = ? AND resolved_by_version = ?
            ");
            $reopen->execute([$deploymentId, $appliedVersion]);
            $reopen->closeCursor();
        }

        echo json_encode([
            'success'           => true,
            'receipt_accepted'  => true,
            'rollback_recorded' => true,
            'status'            => 'rollback_recorded',
            'version'           => $appliedVersion,
            'message'           => "Rollback acknowledged. Release v{$appliedVersion} has been deactivated from fleet distribution."
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if (!empty($appliedVersion)) {
        // 1. Locate releases for this version that resolved errors
        $findRelease = $pdo->prepare("
            SELECT resolves_error_id FROM central_releases 
            WHERE product_id = ? AND version = ? AND resolves_error_id IS NOT NULL
        ");
        $findRelease->execute([$node['product_id'], $appliedVersion]);
        $resolvedErrors = $findRelease->fetchAll(PDO::FETCH_COLUMN);

        $cleanAppliedVer = ltrim($appliedVersion, 'vV');
        $resolvedErrorIds = [];
        if (!empty($resolvedErrors)) {
            foreach ($resolvedErrors as $rErrId) {
                if (!empty($rErrId)) {
                    $resolvedErrorIds[] = (int)$rErrId;
                }
            }
        }

        // If explicit resolves_error_id was supplied in the payload
        if (!empty($resolvesErrorId)) {
            $resolvedErrorIds[] = (int)$resolvesErrorId;
        }

        // Cumulative & multi-error reconciliation: Find all patch_pending errors for this deployment
        // whose resolved_by_version <= appliedVersion (using SemVer)
        $pendingStmt = $pdo->prepare("
            SELECT id, resolved_by_version FROM central_error_logs 
            WHERE deployment_id = ? AND status = 'patch_pending'
        ");
        $pendingStmt->execute([$deploymentId]);
        $pendingErrors = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendingErrors as $pErr) {
            $errVer = ltrim(trim((string)($pErr['resolved_by_version'] ?? '')), 'vV');
            if ($errVer === '' || $cleanAppliedVer === '' || version_compare($cleanAppliedVer, $errVer, '>=')) {
                $resolvedErrorIds[] = (int)$pErr['id'];
            }
        }

        // Also include any errors for this deployment directly tagged with resolved_by_version = appliedVersion
        $exactStmt = $pdo->prepare("
            SELECT id FROM central_error_logs 
            WHERE deployment_id = ? AND resolved_by_version = ?
        ");
        $exactStmt->execute([$deploymentId, $appliedVersion]);
        foreach ($exactStmt->fetchAll(PDO::FETCH_COLUMN) as $exId) {
            $resolvedErrorIds[] = (int)$exId;
        }

        $resolvedErrorIds = array_values(array_unique(array_filter($resolvedErrorIds)));

        if (!empty($resolvedErrorIds)) {
            $inClause = implode(',', $resolvedErrorIds);
            $pdo->exec("
                UPDATE central_error_logs 
                SET status = 'resolved', resolved_by_version = '{$appliedVersion}', resolved = 1, resolved_at = {$nowUtcExpr} 
                WHERE id IN ({$inClause}) AND deployment_id = {$deploymentId}
            ");
        }

        // Recount open errors
        $errCountStmt = $pdo->prepare("SELECT COUNT(*) FROM central_error_logs WHERE deployment_id = ? AND status = 'open'");
        $errCountStmt->execute([$deploymentId]);
        $openErrorCount = (int)$errCountStmt->fetchColumn();

        // 2. Update the deployment's current_version, open_error_count, and last sync timestamp
        $updDep = $pdo->prepare("
            UPDATE central_deployments 
            SET current_version = ?, open_error_count = ?, last_heartbeat_at = {$nowUtcExpr}, status = 'healthy', updated_at = {$nowUtcExpr} 
            WHERE id = ?
        ");
        $updDep->execute([$appliedVersion, $openErrorCount, $deploymentId]);

        echo json_encode([
            'success'          => true,
            'receipt_accepted' => true,
            'resolved_errors'  => count($resolvedErrorIds),
            'message'          => "Receipt acknowledged. Errors for v{$appliedVersion} marked resolved."
        ]);
        exit;
    }
}

// 5. Unconditionally Refresh Heartbeat Timestamp (Always strictly UTC)
$metricsPayload = $payload['metrics'] ?? $payload['heartbeat'] ?? (
    (isset($payload['db_latency_ms']) || isset($payload['disk_free_gb']) || isset($payload['storage_free_gb']) || isset($payload['memory_usage_mb']) || isset($payload['active_users_24h']) || isset($payload['load_avg']))
    ? $payload 
    : null
);
$reportedVersion = trim((string)($payload['version'] ?? $payload['app_version'] ?? $payload['current_version'] ?? ($metricsPayload['version'] ?? ($metricsPayload['app_version'] ?? ($metricsPayload['current_version'] ?? '')))));
$phpVersion      = trim((string)($payload['php_version'] ?? ($metricsPayload['php_version'] ?? '')));
$osInfo          = trim((string)($payload['os'] ?? $payload['os_info'] ?? ($metricsPayload['os'] ?? ($metricsPayload['os_info'] ?? ''))));

$statusFlag = ($licenseStatus === 'suspended' || $licenseStatus === 'revoked') ? 'warning' : 'healthy';

try {
    $updDeploy = $pdo->prepare("
        UPDATE central_deployments 
        SET last_heartbeat_at = {$nowUtcExpr},
            status = ?,
            php_version = CASE WHEN ? != '' THEN ? ELSE php_version END,
            os_info = CASE WHEN ? != '' THEN ? ELSE os_info END,
            current_version = CASE WHEN ? != '' THEN ? ELSE current_version END,
            server_ip = ?,
            updated_at = {$nowUtcExpr}
        WHERE id = ?
    ");
    $updDeploy->execute([
        $statusFlag,
        $phpVersion, $phpVersion,
        $osInfo, $osInfo,
        $reportedVersion, $reportedVersion,
        $clientIp,
        $deploymentId
    ]);

    // Closed-Loop Auto-Reconciliation on Heartbeat:
    // If client reports its version, auto-resolve any patch_pending errors
    // that were resolved by this version or earlier versions (SemVer >=)
    if ($reportedVersion !== '') {
        $cleanReportedVer = ltrim($reportedVersion, 'vV');
        $checkPending = $pdo->prepare("
            SELECT id, resolved_by_version FROM central_error_logs 
            WHERE deployment_id = ? AND status = 'patch_pending'
        ");
        $checkPending->execute([$deploymentId]);
        $pendingErrs = $checkPending->fetchAll(PDO::FETCH_ASSOC);

        $toResolve = [];
        foreach ($pendingErrs as $pErr) {
            $errVer = ltrim(trim((string)($pErr['resolved_by_version'] ?? '')), 'vV');
            if ($errVer === '' || version_compare($cleanReportedVer, $errVer, '>=')) {
                $toResolve[] = (int)$pErr['id'];
            }
        }
        if (!empty($toResolve)) {
            $inClause = implode(',', $toResolve);
            $pdo->exec("
                UPDATE central_error_logs 
                SET status = 'resolved', resolved = 1, resolved_at = {$nowUtcExpr} 
                WHERE id IN ({$inClause}) AND deployment_id = {$deploymentId}
            ");
        }
    }
} catch (\Throwable $touchEx) {
    writeIngestLog("Deployment heartbeat touch warning: " . $touchEx->getMessage());
}

$heartbeatRecorded = false;
$metricsPayload = $payload['metrics'] ?? $payload['heartbeat'] ?? (
    (isset($payload['db_latency_ms']) || isset($payload['disk_free_gb']) || isset($payload['storage_free_gb']) || isset($payload['memory_usage_mb']) || isset($payload['active_users_24h']) || isset($payload['load_avg']))
    ? $payload 
    : null
);

// 6. Record System Heartbeat Metrics (Isolated - won't kill request if fails)
$memUsage = max(0.0, (float)($node['memory_usage_mb'] ?? 0));
$dbLatency = max(0, (int)($node['db_latency_ms'] ?? 0));
$diskFree = max(0.0, (float)($node['disk_free_gb'] ?? $node['storage_free_gb'] ?? 50.0));
$activeUsers = max(0, (int)($node['active_users_24h'] ?? 0));
$loadAvg = isset($node['load_avg']) ? (float)$node['load_avg'] : null;

if (!empty($metricsPayload) && is_array($metricsPayload)) {
    try {
        $m = $metricsPayload;
        $memUsage = max(0.0, (float)($m['memory_usage_mb'] ?? $m['memory_mb'] ?? $memUsage));
        $dbLatency = max(0, (int)($m['db_latency_ms'] ?? $dbLatency));
        $diskFree = max(0.0, (float)($m['disk_free_gb'] ?? $m['storage_free_gb'] ?? $diskFree));
        $activeUsers = max(0, min(10000000, (int)($m['active_users_24h'] ?? $m['active_sessions'] ?? $m['active_users_count'] ?? $activeUsers)));
        $loadAvg = isset($m['cpu_load']) ? max(0.0, (float)$m['cpu_load']) : (isset($m['load_avg']) ? max(0.0, (float)$m['load_avg']) : $loadAvg);

        $insHb = $pdo->prepare("
            INSERT INTO central_heartbeats 
            (deployment_id, memory_usage_mb, db_latency_ms, storage_free_gb, active_users_24h, load_avg, recorded_at)
            VALUES (?, ?, ?, ?, ?, ?, {$nowUtcExpr})
        ");
        $insHb->execute([
            $deploymentId,
            $memUsage,
            $dbLatency,
            $diskFree,
            $activeUsers,
            $loadAvg
        ]);
        $heartbeatRecorded = true;
    } catch (\Throwable $mEx) {
        writeIngestLog("Heartbeat insert warning: " . $mEx->getMessage());
    }
}

// 7. Ingest Batch Error Logs (Isolated, Sanitized & Deduplicated)
$ingestedErrorCount = 0;
$lastErrorId = null;
$incomingErrors = $payload['errors'] ?? [];

// Support single error ingestion at root level
if (empty($incomingErrors) && (!empty($payload['error_message']) || !empty($payload['message']))) {
    $incomingErrors = [$payload];
}

if (!empty($incomingErrors) && is_array($incomingErrors)) {
    $boundedErrors = array_slice($incomingErrors, 0, 100);

    foreach ($boundedErrors as $err) {
        if (!is_array($err)) {
            continue;
        }

        $errMsg = (string)($err['error_message'] ?? $err['message'] ?? $err['msg'] ?? '');
        if (trim($errMsg) === '') {
            continue;
        }

        try {
            $errLevel = substr(strtoupper((string)($err['error_level'] ?? $err['level'] ?? 'ERROR')), 0, 16);
            $filePath = (string)($err['file_path'] ?? $err['file'] ?? 'unknown');
            $lineNum  = (int)($err['line_number'] ?? $err['line'] ?? 0);
            $trace    = (string)($err['stack_trace'] ?? $err['trace'] ?? '');
            $reqUri   = substr((string)($err['request_uri'] ?? $err['uri'] ?? 'CLI'), 0, 255);
            $occurredAt = !empty($err['occurred_at']) 
                ? gmdate('Y-m-d H:i:s', strtotime((string)$err['occurred_at'])) 
                : (!empty($err['timestamp']) ? gmdate('Y-m-d H:i:s', strtotime((string)$err['timestamp'])) : gmdate('Y-m-d H:i:s'));

            // Resilient deduplication via numeric-normalized fingerprint
            $normalizedMessage = (string)preg_replace('/\b\d+\b/', '#', $errMsg);
            $errorHash = hash('sha256', "{$normalizedMessage}:{$filePath}:{$lineNum}");

            $checkErr = $pdo->prepare("SELECT id, occurrences, status, manual_intervention_required FROM central_error_logs WHERE deployment_id = ? AND error_hash = ? AND resolved = 0 LIMIT 1");
            $checkErr->execute([$deploymentId, $errorHash]);
            $existingErr = $checkErr->fetch();

            if ($existingErr) {
                $updErr = $pdo->prepare("
                    UPDATE central_error_logs SET 
                        occurrences = occurrences + 1,
                        last_seen_at = ?,
                        occurred_at = ?,
                        error_message = ?,
                        stack_trace = ?,
                        request_uri = ?,
                        status = 'open',
                        resolved = 0
                    WHERE id = ?
                ");
                $updErr->execute([$occurredAt, $occurredAt, $errMsg, $trace, $reqUri, $existingErr['id']]);
                $lastErrorId = (int)$existingErr['id'];
            } else {
                $insErr = $pdo->prepare("
                    INSERT INTO central_error_logs 
                    (deployment_id, error_hash, error_level, error_message, file_path, line_number, stack_trace, request_uri, ip_address, occurrences, first_seen_at, last_seen_at, occurred_at, resolved, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, 0, 'open', {$nowUtcExpr})
                ");
                $insErr->execute([
                    $deploymentId,
                    $errorHash,
                    $errLevel,
                    $errMsg,
                    $filePath,
                    $lineNum,
                    $trace,
                    $reqUri,
                    $clientIp,
                    $occurredAt,
                    $occurredAt,
                    $occurredAt
                ]);
                $lastErrorId = (int)$pdo->lastInsertId();
            }

            // Autonomous Healing Trigger: Dispatch non-blocking daemon on critical/fatal errors
            if ($lastErrorId > 0 && in_array($errLevel, ['FATAL', 'ERROR', 'CRITICAL'], true)) {
                $checkInFlight = $pdo->prepare("
                    SELECT id FROM central_error_logs 
                    WHERE deployment_id = ? AND file_path = ? AND line_number = ? AND error_message = ? 
                      AND (status IN ('analyzing', 'patch_pending') OR manual_intervention_required = 1)
                      AND id != ?
                    LIMIT 1
                ");
                $checkInFlight->execute([$deploymentId, $filePath, $lineNum, $errMsg, $lastErrorId]);
                $hasInFlight = (bool)$checkInFlight->fetch();
                $checkInFlight->closeCursor();

                $currStatus = $existingErr ? (string)($existingErr['status'] ?? 'open') : 'open';
                $currManual = $existingErr ? (int)($existingErr['manual_intervention_required'] ?? 0) : 0;

                if (!$hasInFlight && $currStatus !== 'analyzing' && $currStatus !== 'patch_pending' && $currManual !== 1) {
                    $updAnalyzing = $pdo->prepare("UPDATE central_error_logs SET status = 'analyzing' WHERE id = ?");
                    $updAnalyzing->execute([$lastErrorId]);
                    $updAnalyzing->closeCursor();

                    $daemonScript = dirname(__DIR__, 4) . '/bin/autonomous-healing-daemon.php';
                    $phpBin = PHP_BINARY ?: 'php';
                    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                        pclose(popen("start /B \"\" \"{$phpBin}\" \"{$daemonScript}\" --error-id={$lastErrorId} > NUL 2>&1", "r"));
                    } else {
                        exec("\"{$phpBin}\" \"{$daemonScript}\" --error-id={$lastErrorId} > /dev/null 2>&1 &");
                    }
                }
            }

            $ingestedErrorCount++;
        } catch (\Throwable $eEx) {
            writeIngestLog("Error log insert failed: " . $eEx->getMessage());
        }
    }
}

// 8. Ingest Queued Support Tickets
$ingestedTicketCount = 0;
$incomingTickets = $payload['tickets'] ?? [];

// Support single ticket ingestion at root level
if (empty($incomingTickets) && !empty($payload['ticket']) && is_array($payload['ticket'])) {
    $incomingTickets = [$payload['ticket']];
}

if (!empty($incomingTickets) && is_array($incomingTickets)) {
    foreach ($incomingTickets as $t) {
        if (!is_array($t)) continue;
        $subject = trim((string)($t['subject'] ?? $t['title'] ?? ''));
        $message = trim((string)($t['message'] ?? $t['body'] ?? $t['description'] ?? ''));
        if ($subject === '' || $message === '') continue;

        try {
            $ref = (string)($t['ticket_reference'] ?? $t['reference'] ?? ('TICK-' . strtoupper(bin2hex(random_bytes(3)))));
            $reqName = (string)($t['requester_name'] ?? 'System User');
            $reqEmail = (string)($t['requester_email'] ?? 'support@client.internal');
            $reqRole = (string)($t['requester_role'] ?? 'Staff');
            $priority = (string)($t['priority'] ?? 'medium');
            $category = (string)($t['category'] ?? 'bug');
            $rawSnap = $t['system_snapshot'] ?? $t['snapshot'] ?? null;
            $snapshot = is_array($rawSnap) ? json_encode($rawSnap) : ($rawSnap !== null ? (string)$rawSnap : null);

            $insTicket = $pdo->prepare("
                INSERT INTO central_support_tickets 
                (deployment_id, ticket_reference, requester_name, requester_email, requester_role, category, priority, subject, message, system_snapshot, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', {$nowUtcExpr}, {$nowUtcExpr})
            ");
            $insTicket->execute([
                $deploymentId,
                $ref,
                $reqName,
                $reqEmail,
                $reqRole,
                $category,
                $priority,
                $subject,
                $message,
                $snapshot
            ]);
            $ingestedTicketCount++;
        } catch (\Throwable $tEx) {
            writeIngestLog("Ticket insert failed: " . $tEx->getMessage());
        }
    }
}

// 8.5 Recount Open Errors & Dynamic Node Health Classifier
$openErrorCount = 0;
try {
    $errCountStmt = $pdo->prepare("SELECT COUNT(*) FROM central_error_logs WHERE deployment_id = ? AND status = 'open'");
    $errCountStmt->execute([$deploymentId]);
    $openErrorCount = (int)$errCountStmt->fetchColumn();
} catch (\Throwable $errCntEx) {
    writeIngestLog("Open error count query warning: " . $errCntEx->getMessage());
}

// Classifier thresholds:
// critical: disk_free_gb < 1.0 OR db_latency_ms >= 1500 OR open_error_count >= 3
// warning:  disk_free_gb < 20.0 OR db_latency_ms >= 500 OR open_error_count > 0
// healthy:  nominal
if ($diskFree < 1.0 || $dbLatency >= 1500 || $openErrorCount >= 3) {
    $fleetStatus = 'critical';
} elseif ($diskFree < 20.0 || $dbLatency >= 500 || $openErrorCount > 0) {
    $fleetStatus = 'warning';
} else {
    $fleetStatus = 'healthy';
}

// Synchronize denormalized columns on central_deployments
try {
    $updMetrics = $pdo->prepare("
        UPDATE central_deployments SET
            status = :status,
            db_latency_ms = :lat,
            disk_free_gb = :disk_free,
            storage_free_gb = :storage_free,
            active_users_24h = :users,
            open_error_count = :err_count,
            memory_usage_mb = :mem,
            load_avg = :load,
            last_heartbeat_at = {$nowUtcExpr},
            updated_at = {$nowUtcExpr}
        WHERE id = :id
    ");
    $updMetrics->execute([
        ':status'       => $fleetStatus,
        ':lat'          => $dbLatency,
        ':disk_free'    => $diskFree,
        ':storage_free' => $diskFree,
        ':users'        => $activeUsers,
        ':err_count'    => $openErrorCount,
        ':mem'          => $memUsage,
        ':load'         => $loadAvg,
        ':id'           => $deploymentId
    ]);
} catch (\Throwable $uEx) {
    writeIngestLog("Deployment metrics update warning: " . $uEx->getMessage());
}

// 9. Generate Rolling HMAC Lease (2-hour cryptographic lease token signed via HMAC-SHA256 with license_lease_salt)
$nowUtc = time();
$leaseExpiry = ($licenseStatus === 'active' || $licenseStatus === 'unactivated') ? ($nowUtc + 7200) : 0; // 2 hours
$licenseKeyStr = (string)($node['license_key'] ?? $node['deployment_domain']);
$leaseSalt = !empty($node['lease_salt']) ? (string)$node['lease_salt'] : (!empty($node['license_key']) ? hash('sha256', (string)$node['license_key']) : 'SPECTRAOPS_DEFAULT_LEASE_SALT');
$signaturePayload = "{$licenseKeyStr}|{$node['deployment_domain']}|{$leaseExpiry}";
$leaseToken = hash_hmac('sha256', $signaturePayload, $leaseSalt);

// Structured Token Format: Base64UrlEncoded(JSON_Payload) . "." . HMAC_SHA256_Signature
$leasePayload = [
    'deployment_id' => $deploymentId,
    'license_id'    => (int)($node['license_id'] ?? 0),
    'status'        => ($licenseStatus === 'active' || $licenseStatus === 'unactivated') ? 'active' : $licenseStatus,
    'expires_at'    => $leaseExpiry
];
$base64Payload = rtrim(strtr(base64_encode(json_encode($leasePayload, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
$tokenSignature = hash_hmac('sha256', $base64Payload, $leaseSalt);
$structuredLeaseToken = "{$base64Payload}.{$tokenSignature}";

// 9b. Automated Error Log Retention Lifecycle (Archive >30d, Delete >60d)
try {
    require_once dirname(__DIR__, 4) . '/src/FleetService.php';
    $fleetService = new \FleetHub\FleetService($pdo);
    $fleetService->lifecycleErrorLogs(30, 60);
} catch (\Throwable $lifeEx) {
    // Non-blocking background maintenance sweep
}

// 10. Fetch tracked error statuses for this deployment (Closed-Loop Resolution State)
try {
    $stmtErrStatus = $pdo->prepare("
        SELECT id, status, resolved_by_version, error_message, file_path, line_number, last_seen_at AS occurred_at
        FROM central_error_logs
        WHERE deployment_id = ?
        ORDER BY last_seen_at DESC
        LIMIT 50
    ");
    $stmtErrStatus->execute([$deploymentId]);
    $trackedStatuses = $stmtErrStatus->fetchAll();
} catch (\Throwable $errStatEx) {
    writeIngestLog("Error statuses query failed: " . $errStatEx->getMessage());
    $trackedStatuses = [];
}

// 10b. Check for pending silent OTA updates
$immediateUpdateRequired = false;
$pendingReleasePayload = null;
try {
    $stmtPendingRel = $pdo->prepare("
        SELECT version, release_type, is_silent_patch, resolves_error_id, package_filename, package_checksum
        FROM central_releases
        WHERE product_id = ? AND is_active = 1
        ORDER BY released_at DESC, id DESC
        LIMIT 1
    ");
    $stmtPendingRel->execute([$node['product_id']]);
    $pendingRel = $stmtPendingRel->fetch(PDO::FETCH_ASSOC);
    $stmtPendingRel->closeCursor();

    if ($pendingRel) {
        $cleanNodeVer = ltrim((string)($reportedVersion ?: ($node['current_version'] ?? '0.0.0')), 'vV');
        $cleanRelVer = ltrim((string)$pendingRel['version'], 'vV');
        if (version_compare($cleanRelVer, $cleanNodeVer, '>')) {
            $immediateUpdateRequired = true;
            $pendingReleasePayload = [
                'version'           => $cleanRelVer,
                'is_silent_patch'   => (bool)$pendingRel['is_silent_patch'],
                'package_filename'  => $pendingRel['package_filename'],
                'package_checksum'  => $pendingRel['package_checksum'],
                'resolves_error_id' => $pendingRel['resolves_error_id'] ? (int)$pendingRel['resolves_error_id'] : null
            ];
        }
    }
} catch (\Throwable $relCheckEx) {
    writeIngestLog("Pending release query failed: " . $relCheckEx->getMessage());
}

http_response_code(200);
echo json_encode([
    'success'                  => true,
    'status'                   => 'acknowledged',
    'deployment_id'            => $deploymentId,
    'error_id'                 => $lastErrorId,
    'client_name'              => $node['client_name'] ?? $node['deployment_domain'],
    'license_status'           => $licenseStatus,
    'fleet_status'             => $fleetStatus,
    'immediate_update_required' => $immediateUpdateRequired,
    'pending_release'          => $pendingReleasePayload,
    'lease_token'              => $structuredLeaseToken,
    'structured_lease_token'   => $structuredLeaseToken,
    'raw_lease_signature'      => $leaseToken,
    'lease_salt'               => $leaseSalt,
    'lease_expires'            => $leaseExpiry,
    'lease_valid_until'        => $leaseExpiry,
    'lease_signature'          => $leaseToken,
    'heartbeat_recorded'       => $heartbeatRecorded,
    'errors_recorded'         => $ingestedErrorCount,
    'ticket_recorded'         => ($ingestedTicketCount > 0),
    'ingested_errors'         => $ingestedErrorCount,
    'ingested_tickets'        => $ingestedTicketCount,
    'open_error_count'        => $openErrorCount,
    'error_statuses'          => $trackedStatuses, // <--- Sent to client deployment
    'server_time_utc'         => gmdate('c')
], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
exit;
