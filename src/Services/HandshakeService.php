<?php
declare(strict_types=1);

namespace FleetHub\Services;

use PDO;

/**
 * Handles /api/v1/handshake and /api/v1/heartbeat requests from client nodes
 * provisioned with spectraops.json.
 */
class HandshakeService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Process a handshake or heartbeat payload and return [httpStatus, responseArray].
     */
    public function handle(array $payload, array $headers = [], string $mode = 'handshake'): array {
        $deploymentKey = $this->extractDeploymentKey($payload, $headers);
        if ($deploymentKey === '') {
            return [401, [
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
                   p.product_slug, p.product_code,
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
            return [403, [
                'success' => false,
                'error'   => 'Invalid or unrecognized deployment_key.',
            ]];
        }

        if ($deploymentId !== null && $deploymentId > 0 && (int)$node['id'] !== $deploymentId) {
            return [403, [
                'success' => false,
                'error'   => 'deployment_id does not match the provided deployment_key.',
            ]];
        }

        // Verify product_slug if supplied
        $productSlug = trim((string)($payload['product_slug'] ?? ''));
        if ($productSlug !== '' && strcasecmp($productSlug, (string)$node['product_slug']) !== 0) {
            return [403, [
                'success' => false,
                'error'   => 'product_slug mismatch for this deployment.',
            ]];
        }

        // Verify bound license status & expiration
        $licenseValid = true;
        if (!empty($node['license_id'])) {
            $licStatus = strtolower((string)($node['license_status'] ?? 'active'));
            if (in_array($licStatus, ['suspended', 'revoked'], true)) {
                return [403, [
                    'success'       => false,
                    'status'        => $licStatus,
                    'license_valid' => false,
                    'error'         => "Bound license is {$licStatus}.",
                ]];
            }
            if (!empty($node['license_expires_at']) && strtotime((string)$node['license_expires_at']) < time()) {
                return [403, [
                    'success'       => false,
                    'status'        => 'expired',
                    'license_valid' => false,
                    'error'         => 'Bound license has expired.',
                ]];
            }
        }

        // Extract optional metadata & telemetry
        $telemetry = is_array($payload['telemetry'] ?? null) ? $payload['telemetry'] : [];
        $appVersion = trim((string)($payload['app_version'] ?? ($payload['version'] ?? ($telemetry['app_version'] ?? ''))));
        $phpVersion = trim((string)($payload['php_version'] ?? ($telemetry['php_version'] ?? '')));
        $osInfo     = trim((string)($payload['os_info'] ?? ($telemetry['os_info'] ?? '')));
        $liveDomain = trim((string)($payload['live_domain'] ?? ($payload['domain'] ?? '')));
        $serverIp   = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ($_SERVER['REMOTE_ADDR'] ?? '')));
        if (str_contains($serverIp, ',')) {
            $serverIp = trim(explode(',', $serverIp)[0]);
        }

        $memMb   = isset($telemetry['memory_usage_mb']) ? (float)$telemetry['memory_usage_mb'] : (isset($payload['memory_usage_mb']) ? (float)$payload['memory_usage_mb'] : null);
        $latMs   = isset($telemetry['db_latency_ms']) ? (int)$telemetry['db_latency_ms'] : (isset($payload['db_latency_ms']) ? (int)$payload['db_latency_ms'] : null);
        $diskGb  = isset($telemetry['storage_free_gb']) ? (float)$telemetry['storage_free_gb'] : (isset($telemetry['disk_free_gb']) ? (float)$telemetry['disk_free_gb'] : null);
        $users24 = isset($telemetry['active_users_24h']) ? (int)$telemetry['active_users_24h'] : null;
        $loadAvg = isset($telemetry['load_avg']) ? (float)$telemetry['load_avg'] : null;

        $isSqlite = ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        $nowExpr = $isSqlite ? "datetime('now')" : "UTC_TIMESTAMP()";
        $reason = $mode === 'heartbeat' ? 'Heartbeat received' : 'Handshake verified';

        $upd = $this->pdo->prepare("
            UPDATE central_deployments
            SET status = 'online',
                status_reason = :reason,
                last_heartbeat_at = {$nowExpr},
                paired_at = COALESCE(paired_at, {$nowExpr}),
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
            ':reason'   => $reason,
            ':app_ver'  => $appVersion,
            ':app_ver2' => $appVersion,
            ':php_ver'  => $phpVersion,
            ':php_ver2' => $phpVersion,
            ':os_info'  => $osInfo,
            ':os_info2' => $osInfo,
            ':srv_ip'   => $serverIp,
            ':srv_ip2'  => $serverIp,
            ':mem_mb'   => $memMb,
            ':lat_ms'   => $latMs,
            ':disk_gb'  => $diskGb,
            ':users24'  => $users24,
            ':load_avg' => $loadAvg,
            ':id'       => (int)$node['id'],
        ]);

        if (!empty($telemetry)) {
            try {
                $hb = $this->pdo->prepare("
                    INSERT INTO central_heartbeats (deployment_id, memory_usage_mb, db_latency_ms, storage_free_gb, active_users_24h, load_avg, recorded_at)
                    VALUES (?, ?, ?, ?, ?, ?, {$nowExpr})
                ");
                $hb->execute([
                    (int)$node['id'],
                    $memMb ?? 0.0,
                    $latMs ?? 0,
                    $diskGb ?? 0.0,
                    $users24 ?? 0,
                    $loadAvg,
                ]);
            } catch (\Throwable) {
                // Non-fatal if telemetry insert fails
            }
        }

        return [200, [
            'success'       => true,
            'status'        => 'online',
            'license_valid' => $licenseValid,
            'deployment_id' => (int)$node['id'],
            'product_slug'  => (string)$node['product_slug'],
            'environment'   => (string)($node['environment'] ?: 'production'),
            'message'       => $reason,
        ]];
    }

    private function extractDeploymentKey(array $payload, array $headers): string {
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
