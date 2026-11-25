<?php
declare(strict_types=1);

namespace FleetHub\Services;

use FleetHub\FleetService;
use PDO;

require_once dirname(__DIR__) . '/FleetService.php';

/**
 * Unified single-pipe POST /api/v1/sync gateway service.
 * Authenticates deployment_key, updates fleet heartbeat/metadata,
 * idempotently processes outbox events (tickets, errors, telemetry),
 * and returns authoritative license lease state.
 */
class SyncGatewayService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Handle a /api/v1/sync payload and return [httpStatus, responseArray].
     */
    public function handle(array $payload, array $headers = []): array
    {
        $deploymentKey = $this->extractDeploymentKey($payload, $headers);
        if ($deploymentKey === '') {
            return [401, [
                'ok'      => false,
                'success' => false,
                'error'   => 'Missing deployment_key or Authorization Bearer token.',
            ]];
        }

        $deploymentId = isset($payload['deployment_id']) && $payload['deployment_id'] !== ''
            ? (int)$payload['deployment_id']
            : null;

        $tokenHash = hash('sha256', $deploymentKey);

        $stmt = $this->pdo->prepare("
            SELECT d.id, d.product_id, d.license_id, d.client_name, d.deployment_domain,
                   d.environment, d.current_version, d.php_version, d.os_info, d.status,
                   d.is_active,
                   p.product_slug, p.product_code, p.latest_version AS product_latest_version,
                   l.status AS license_status, l.expires_at AS license_expires_at, l.license_key
            FROM central_deployments d
            JOIN central_products p ON d.product_id = p.id
            LEFT JOIN central_licenses l ON d.license_id = l.id
            WHERE (d.api_ingest_token_hash = :hash OR l.license_key = :raw_key)
              AND (d.is_active = 1 OR d.is_active IS NULL)
            LIMIT 1
        ");
        $stmt->execute([':hash' => $tokenHash, ':raw_key' => $deploymentKey]);
        $node = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$node) {
            return [401, [
                'ok'      => false,
                'success' => false,
                'error'   => 'Invalid or unrecognized deployment_key.',
            ]];
        }

        if ($deploymentId !== null && $deploymentId > 0 && (int)$node['id'] !== $deploymentId) {
            return [401, [
                'ok'      => false,
                'success' => false,
                'error'   => 'deployment_id does not match the provided deployment_key.',
            ]];
        }

        $matchedId = (int)$node['id'];
        $isSqlite = ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        $nowExpr = $isSqlite ? "datetime('now')" : "UTC_TIMESTAMP()";

        // Update fleet heartbeat & metadata
        $telemetry = is_array($payload['telemetry'] ?? null) ? $payload['telemetry'] : [];
        $appVersion = trim((string)($payload['app_version'] ?? ($payload['version'] ?? ($telemetry['app_version'] ?? ''))));
        $phpVersion = trim((string)($payload['php_version'] ?? ($telemetry['php_version'] ?? '')));
        $osInfo     = trim((string)($payload['os_info'] ?? ($telemetry['os_info'] ?? '')));
        $liveDomain = trim((string)($payload['live_domain'] ?? ($payload['domain'] ?? '')));
        $serverIp   = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
        if (str_contains($serverIp, ',')) {
            $serverIp = trim(explode(',', $serverIp)[0]);
        }

        $normalizedLiveDomain = '';
        if ($liveDomain !== '') {
            $candidate = rtrim($liveDomain, '/');
            if (!preg_match('#^https?://#i', $candidate)) {
                $candidate = 'https://' . $candidate;
            }
            if (FleetService::isValidPublicDomain($candidate)) {
                $normalizedLiveDomain = $candidate;
            }
        }

        $memMb   = isset($telemetry['memory_usage_mb']) ? (float)$telemetry['memory_usage_mb'] : (isset($payload['memory_usage_mb']) ? (float)$payload['memory_usage_mb'] : null);
        $latMs   = isset($telemetry['db_latency_ms']) ? (int)$telemetry['db_latency_ms'] : (isset($payload['db_latency_ms']) ? (int)$payload['db_latency_ms'] : null);
        $diskGb  = isset($telemetry['storage_free_gb']) ? (float)$telemetry['storage_free_gb'] : (isset($telemetry['disk_free_gb']) ? (float)$telemetry['disk_free_gb'] : null);
        $users24 = isset($telemetry['active_users_24h']) ? (int)$telemetry['active_users_24h'] : null;
        $loadAvg = isset($telemetry['load_avg']) ? (float)$telemetry['load_avg'] : null;

        $upd = $this->pdo->prepare("
            UPDATE central_deployments
            SET status = 'online',
                status_reason = 'Sync verified',
                last_heartbeat_at = {$nowExpr},
                paired_at = COALESCE(paired_at, {$nowExpr}),
                deployment_domain = CASE WHEN :live_dom != '' THEN :live_dom2 ELSE deployment_domain END,
                current_version = CASE WHEN :app_ver != '' THEN :app_ver2 ELSE current_version END,
                php_version = CASE WHEN :php_ver != '' THEN :php_ver2 ELSE php_version END,
                os_info = CASE WHEN :os_info != '' THEN :os_info2 ELSE os_info END,
                server_ip = CASE WHEN :srv_ip != '' THEN :srv_ip2 ELSE server_ip END,
                memory_usage_mb = COALESCE(:mem_mb, memory_usage_mb),
                db_latency_ms = COALESCE(:lat_ms, db_latency_ms),
                storage_free_gb = COALESCE(:disk_gb, storage_free_gb),
                active_users_24h = COALESCE(:users24, active_users_24h),
                load_avg = COALESCE(:load_avg, load_avg),
                updated_at = {$nowExpr}
            WHERE id = :id
        ");
        $upd->execute([
            ':live_dom'  => $normalizedLiveDomain,
            ':live_dom2' => $normalizedLiveDomain,
            ':app_ver'   => $appVersion,
            ':app_ver2'  => $appVersion,
            ':php_ver'   => $phpVersion,
            ':php_ver2'  => $phpVersion,
            ':os_info'   => $osInfo,
            ':os_info2'  => $osInfo,
            ':srv_ip'    => $serverIp,
            ':srv_ip2'   => $serverIp,
            ':mem_mb'    => $memMb,
            ':lat_ms'    => $latMs,
            ':disk_gb'   => $diskGb,
            ':users24'   => $users24,
            ':load_avg'  => $loadAvg,
            ':id'        => $matchedId,
        ]);

        if (!empty($telemetry)) {
            $this->insertHeartbeat($matchedId, $memMb ?? 0.0, (float)($latMs ?? 0), $diskGb ?? 0.0, $users24 ?? 0, $loadAvg, $nowExpr);
        }

        // Process outbox events idempotently
        $processedEvents = 0;
        $events = is_array($payload['events'] ?? null) ? $payload['events'] : [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $eventType = strtolower(trim((string)($event['event_type'] ?? '')));
            $eventId = trim((string)($event['event_id'] ?? ''));
            $evPayload = is_array($event['payload'] ?? null) ? $event['payload'] : [];

            if ($eventType === 'ticket') {
                if ($this->processTicketEvent($matchedId, $eventId, $evPayload, $nowExpr)) {
                    $processedEvents++;
                }
            } elseif ($eventType === 'error') {
                if ($this->processErrorEvent($matchedId, $evPayload, $serverIp, $nowExpr)) {
                    $processedEvents++;
                }
            } elseif ($eventType === 'telemetry') {
                $this->insertHeartbeat(
                    $matchedId,
                    (float)($evPayload['memory_usage_mb'] ?? 0.0),
                    (float)($evPayload['db_latency_ms'] ?? 0.0),
                    (float)($evPayload['storage_free_gb'] ?? ($evPayload['disk_free_gb'] ?? 0.0)),
                    (int)($evPayload['active_users_24h'] ?? 0),
                    isset($evPayload['load_avg']) ? (float)$evPayload['load_avg'] : null,
                    $nowExpr
                );
                $processedEvents++;
            }
        }

        // Evaluate bound license lease state
        $licenseStatus = 'active';
        $licenseValid = true;
        $expiresAt = $node['license_expires_at'] ?? null;

        if (!empty($node['license_id'])) {
            $licenseStatus = strtolower((string)($node['license_status'] ?? 'active'));
            if (in_array($licenseStatus, ['suspended', 'revoked'], true)) {
                $licenseValid = false;
            } elseif (!empty($expiresAt) && strtotime((string)$expiresAt) < time()) {
                $licenseStatus = 'expired';
                $licenseValid = false;
            }
        }

        return [200, [
            'ok'               => true,
            'success'          => true,
            'license'          => $licenseStatus,
            'license_valid'    => $licenseValid,
            'status'           => 'online',
            'expires_at'       => $expiresAt ?: null,
            'latest_version'   => $node['product_latest_version'] ?? null,
            'processed_events' => $processedEvents,
        ]];
    }

    private function processTicketEvent(int $deploymentId, string $eventId, array $evPayload, string $nowExpr): bool
    {
        $subject = trim((string)($evPayload['subject'] ?? $evPayload['title'] ?? ''));
        $message = trim((string)($evPayload['message'] ?? $evPayload['body'] ?? $evPayload['description'] ?? ''));
        if ($subject === '' || $message === '') {
            return false;
        }

        $localTicketId = trim((string)($evPayload['local_ticket_id'] ?? $evPayload['ticket_reference'] ?? ''));
        $rawRef = $localTicketId !== '' ? $localTicketId : $eventId;
        if ($rawRef === '') {
            $rawRef = 'TKT-' . strtoupper(bin2hex(random_bytes(4)));
        }
        $ticketRef = strlen($rawRef) > 32 ? substr(hash('sha256', $rawRef), 0, 32) : $rawRef;

        // Idempotency check: prevent duplicate tickets on outbox retries
        $chk = $this->pdo->prepare("SELECT id FROM central_support_tickets WHERE deployment_id = ? AND ticket_reference = ? LIMIT 1");
        $chk->execute([$deploymentId, $ticketRef]);
        if ($chk->fetch()) {
            return true;
        }

        $submittedBy = trim((string)($evPayload['submitted_by'] ?? $evPayload['requester_email'] ?? 'admin@client.local'));
        $reqName = trim((string)($evPayload['requester_name'] ?? $submittedBy));
        $reqEmail = trim((string)($evPayload['requester_email'] ?? $submittedBy));
        $reqRole = trim((string)($evPayload['requester_role'] ?? 'Staff'));

        $priority = strtolower(trim((string)($evPayload['priority'] ?? 'medium')));
        if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
            $priority = 'medium';
        }
        $category = strtolower(trim((string)($evPayload['category'] ?? 'general')));

        $snapshotData = $evPayload['system_snapshot'] ?? null;
        if (!empty($evPayload['attachments']) || $eventId !== '') {
            $meta = is_array($snapshotData) ? $snapshotData : ($snapshotData !== null ? ['raw' => $snapshotData] : []);
            if ($eventId !== '') {
                $meta['event_id'] = $eventId;
            }
            if (!empty($evPayload['attachments'])) {
                $meta['attachments'] = $evPayload['attachments'];
            }
            $snapshotStr = json_encode($meta, JSON_UNESCAPED_SLASHES);
        } else {
            $snapshotStr = is_array($snapshotData) ? json_encode($snapshotData, JSON_UNESCAPED_SLASHES) : ($snapshotData !== null ? (string)$snapshotData : null);
        }

        $ins = $this->pdo->prepare("
            INSERT INTO central_support_tickets
            (deployment_id, ticket_reference, requester_name, requester_email, requester_role, subject, category, priority, message, system_snapshot, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', {$nowExpr}, {$nowExpr})
        ");
        $ins->execute([
            $deploymentId,
            $ticketRef,
            $reqName !== '' ? $reqName : 'System User',
            $reqEmail !== '' ? $reqEmail : 'support@client.local',
            $reqRole !== '' ? $reqRole : 'Staff',
            substr($subject, 0, 255),
            substr($category, 0, 64),
            $priority,
            $message,
            $snapshotStr,
        ]);

        return true;
    }

    private function processErrorEvent(int $deploymentId, array $evPayload, string $clientIp, string $nowExpr): bool
    {
        $errMsg = trim((string)($evPayload['error_message'] ?? $evPayload['message'] ?? ''));
        if ($errMsg === '') {
            return false;
        }

        $errLevel = strtoupper(trim((string)($evPayload['error_level'] ?? 'ERROR')));
        $filePath = trim((string)($evPayload['file_path'] ?? ''));
        $lineNum = isset($evPayload['line_number']) ? (int)$evPayload['line_number'] : null;
        $trace = (string)($evPayload['stack_trace'] ?? '');
        $reqUri = trim((string)($evPayload['request_uri'] ?? ''));

        $normalized = (string)preg_replace('/\b\d+\b/', '#', $errMsg);
        $errorHash = substr(hash('sha256', "{$normalized}:{$filePath}:{$lineNum}"), 0, 32);

        try {
            $chk = $this->pdo->prepare("SELECT id FROM central_error_logs WHERE deployment_id = ? AND error_hash = ? AND resolved = 0 LIMIT 1");
            $chk->execute([$deploymentId, $errorHash]);
            $existing = $chk->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $upd = $this->pdo->prepare("
                    UPDATE central_error_logs
                    SET occurrences = occurrences + 1,
                        last_seen_at = {$nowExpr},
                        occurred_at = {$nowExpr},
                        error_message = ?,
                        stack_trace = ?,
                        request_uri = ?,
                        status = 'open',
                        resolved = 0
                    WHERE id = ?
                ");
                $upd->execute([$errMsg, $trace, $reqUri, (int)$existing['id']]);
            } else {
                $ins = $this->pdo->prepare("
                    INSERT INTO central_error_logs
                    (deployment_id, error_hash, error_level, error_message, file_path, line_number, stack_trace, request_uri, ip_address, occurrences, first_seen_at, last_seen_at, occurred_at, resolved, status, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, {$nowExpr}, {$nowExpr}, {$nowExpr}, 0, 'open', {$nowExpr})
                ");
                $ins->execute([
                    $deploymentId,
                    $errorHash,
                    substr($errLevel, 0, 32),
                    $errMsg,
                    $filePath !== '' ? substr($filePath, 0, 255) : null,
                    $lineNum,
                    $trace !== '' ? $trace : null,
                    $reqUri !== '' ? substr($reqUri, 0, 255) : null,
                    $clientIp !== '' ? substr($clientIp, 0, 45) : null,
                ]);
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function insertHeartbeat(int $deploymentId, float $memMb, float $latMs, float $diskGb, int $users24, ?float $loadAvg, string $nowExpr): void
    {
        try {
            $hb = $this->pdo->prepare("
                INSERT INTO central_heartbeats (deployment_id, memory_usage_mb, db_latency_ms, storage_free_gb, active_users_24h, load_avg, recorded_at)
                VALUES (?, ?, ?, ?, ?, ?, {$nowExpr})
            ");
            $hb->execute([$deploymentId, $memMb, $latMs, $diskGb, $users24, $loadAvg]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    private function extractDeploymentKey(array $payload, array $headers): string
    {
        $auth = '';
        foreach ($headers as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) {
                $auth = (string)$v;
                break;
            }
        }
        if ($auth === '') {
            $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
        }
        if ($auth !== '' && preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
            return trim($m[1]);
        }

        $headerKey = $_SERVER['HTTP_X_DEPLOYMENT_KEY']
            ?? ($_SERVER['HTTP_X_API_KEY']
            ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? ''));
        if (is_string($headerKey) && trim($headerKey) !== '') {
            return trim($headerKey);
        }

        foreach (['deployment_key', 'api_key', 'api_token', 'ingest_token'] as $field) {
            if (!empty($payload[$field]) && is_string($payload[$field])) {
                return trim($payload[$field]);
            }
        }

        return '';
    }
}
