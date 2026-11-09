<?php
declare(strict_types=1);

namespace FleetHub;

use PDO;
use RuntimeException;

require_once __DIR__ . '/Database.php';

class FleetService {
    private PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        if ($pdo instanceof PDO) {
            $this->pdo = $pdo;
            return;
        }

        $dbClass = __NAMESPACE__ . '\\Database';
        if (class_exists($dbClass)) {
            $this->pdo = $dbClass::getConnection();
            return;
        }

        throw new RuntimeException("FleetHub database service unavailable.");
    }

    /**
     * Authenticate a deployment using an ingest token.
     * We hash the incoming token with SHA-256 and look up by hash.
     */
    public function authenticateToken(string $token): ?array {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $tokenHash = hash('sha256', $token);

        $stmt = $this->pdo->prepare("
            SELECT d.id, d.product_id, d.client_name, d.deployment_domain, d.current_version, d.status, p.product_slug, p.latest_version
            FROM central_deployments d
            JOIN central_products p ON d.product_id = p.id
            LEFT JOIN central_licenses l ON d.license_id = l.id
            WHERE (d.api_ingest_token_hash = :hash OR l.license_key = :raw_key)
            LIMIT 1
        ");
        $stmt->execute([':hash' => $tokenHash, ':raw_key' => $token]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /**
     * Validate that a deployment domain is a plausible, safe public hostname.
     * Rejects raw IPs, localhost/loopback, private ranges (10.x, 172.16-31.x, 192.168.x),
     * link-local/cloud-metadata (169.254.x.x), and malformed hostnames.
     */
    public static function isValidPublicDomain(string $domain): bool {
        $clean = trim($domain);
        // Strip scheme if present
        $clean = preg_replace('#^https?://#i', '', $clean);
        // Strip trailing slash, path, or port
        $clean = preg_replace('#[/:]+.*$#', '', $clean);

        if ($clean === '' || strlen($clean) > 253) {
            return false;
        }

        $lower = strtolower($clean);
        $isLocalReq = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) 
            || in_array(getenv('APP_ENV') ?: '', ['local', 'development'], true);

        if ($lower === 'localhost' || str_ends_with($lower, '.localhost') || str_ends_with($lower, '.local') || $clean === '127.0.0.1') {
            return $isLocalReq;
        }

        // Check if it's an IP address (IPv4 or IPv6)
        if (filter_var($clean, FILTER_VALIDATE_IP)) {
            // Reject all raw IPs per requirement C7
            return false;
        }

        // Validate valid RFC 1123 hostname syntax with at least one dot
        if (!preg_match('/^(?!-)[A-Za-z0-9-]{1,63}(?<!-)(\.(?!-)[A-Za-z0-9-]{1,63}(?<!-))+$/', $clean)) {
            return false;
        }

        // Explicitly block cloud metadata endpoints
        if (str_contains($lower, 'metadata.google.internal') || str_starts_with($lower, '169.254.')) {
            return false;
        }

        return true;
    }

    /**
     * Register a new client deployment in the fleet registry.
     * Generates a secure random token, stores its SHA-256 hash, and returns the raw token ONCE.
     */
    public function registerDeployment(
        int $productId,
        string $clientName,
        string $domain,
        string $environment = 'production',
        string $initialVersion = '1.0.0'
    ): array {
        $domain = trim($domain);
        if (!self::isValidPublicDomain($domain)) {
            throw new \InvalidArgumentException("Invalid deployment domain '{$domain}'. Must be a valid public hostname (raw IPs, private networks, and localhost are rejected).");
        }

        // Generate secure 32-byte hex token
        $rawToken = 'flt_' . bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $rawToken);
        $preview = substr($rawToken, 0, 7) . '...' . substr($rawToken, -4);

        $stmt = $this->pdo->prepare("
            INSERT INTO central_deployments (
                product_id, client_name, deployment_domain, environment,
                api_ingest_token_hash, token_preview, current_version, status, status_reason, created_at
            ) VALUES (
                :product_id, :client_name, :domain, :env,
                :token_hash, :preview, :version, 'healthy', 'Newly registered deployment', " . $this->nowFunc() . "
            )
        ");

        $stmt->execute([
            ':product_id' => $productId,
            ':client_name' => trim($clientName),
            ':domain' => trim($domain),
            ':env' => $environment,
            ':token_hash' => $tokenHash,
            ':preview' => $preview,
            ':version' => trim($initialVersion),
        ]);

        $deploymentId = (int)$this->pdo->lastInsertId();

        return [
            'id' => $deploymentId,
            'client_name' => $clientName,
            'deployment_domain' => $domain,
            'token' => $rawToken,
            'token_preview' => $preview,
            'environment' => $environment,
        ];
    }

    /**
     * Pair a client deployment using a key generated directly by the client instance.
     * (Reverse Registration / API Key Pairing)
     */
    public function pairDeploymentWithKey(
        int $productId,
        string $clientName,
        string $domain,
        string $apiKey,
        string $environment = 'production',
        string $initialVersion = '1.0.0'
    ): array {
        $domain = trim($domain);
        if (!self::isValidPublicDomain($domain)) {
            throw new \InvalidArgumentException("Invalid deployment domain '{$domain}'. Must be a valid public hostname (raw IPs, private networks, and localhost are rejected).");
        }

        $rawKey = trim($apiKey);
        if ($rawKey === '') {
            throw new \InvalidArgumentException("Client API Key cannot be empty.");
        }
        $tokenHash = hash('sha256', $rawKey);
        $preview = strlen($rawKey) > 12
            ? substr($rawKey, 0, 8) . '...' . substr($rawKey, -4)
            : substr($rawKey, 0, 4) . '***';

        // Check if deployment already exists with this token hash
        $checkStmt = $this->pdo->prepare("SELECT id FROM central_deployments WHERE api_ingest_token_hash = :hash LIMIT 1");
        $checkStmt->execute([':hash' => $tokenHash]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $depId = (int)$existing['id'];
            $updStmt = $this->pdo->prepare("
                UPDATE central_deployments SET
                    product_id = :product_id,
                    client_name = :client_name,
                    deployment_domain = :domain,
                    environment = :env,
                    token_preview = :preview,
                    status = 'healthy',
                    status_reason = 'Re-paired via client deployment key',
                    paired_at = " . $this->nowFunc() . ",
                    is_active = 1,
                    updated_at = " . $this->nowFunc() . "
                WHERE id = :id
            ");
            $updStmt->execute([
                ':product_id' => $productId,
                ':client_name' => trim($clientName),
                ':domain' => trim($domain),
                ':env' => $environment,
                ':preview' => $preview,
                ':id' => $depId,
            ]);
            $isNew = false;
        } else {
            $stmt = $this->pdo->prepare("
                INSERT INTO central_deployments (
                    product_id, client_name, deployment_domain, environment,
                    api_ingest_token_hash, token_preview, current_version, status,
                    status_reason, paired_at, is_active, created_at, updated_at
                ) VALUES (
                    :product_id, :client_name, :domain, :env,
                    :token_hash, :preview, :version, 'healthy',
                    'Paired via client deployment key', " . $this->nowFunc() . ", 1, " . $this->nowFunc() . ", " . $this->nowFunc() . "
                )
            ");
            $stmt->execute([
                ':product_id' => $productId,
                ':client_name' => trim($clientName),
                ':domain' => trim($domain),
                ':env' => $environment,
                ':token_hash' => $tokenHash,
                ':preview' => $preview,
                ':version' => trim($initialVersion),
            ]);
            $depId = (int)$this->pdo->lastInsertId();
            $isNew = true;
        }

        return [
            'id' => $depId,
            'client_name' => $clientName,
            'deployment_domain' => $domain,
            'token_preview' => $preview,
            'is_new' => $isNew,
            'paired_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Permanently remove a monitored deployment and all associated telemetry records.
     */
    public function deleteDeployment(int $deploymentId): bool {
        if ($deploymentId <= 0) {
            return false;
        }

        // 1. Unbind and reconcile any attached license
        try {
            $depStmt = $this->pdo->prepare("SELECT license_id FROM central_deployments WHERE id = ?");
            $depStmt->execute([$deploymentId]);
            $licId = $depStmt->fetchColumn();
            if ($licId) {
                // Detach license from this deployment
                $this->pdo->prepare("UPDATE central_deployments SET license_id = NULL WHERE id = ?")->execute([$deploymentId]);
                // If the license is active, check if other deployments are attached; if none, revert to unactivated
                $cntStmt = $this->pdo->prepare("SELECT COUNT(*) FROM central_deployments WHERE license_id = ? AND id != ?");
                $cntStmt->execute([$licId, $deploymentId]);
                $otherCount = (int)$cntStmt->fetchColumn();
                if ($otherCount === 0) {
                    $this->pdo->prepare("UPDATE central_licenses SET status = 'unactivated' WHERE id = ? AND status = 'active'")->execute([$licId]);
                }
            }
        } catch (\Throwable $e) {
            error_log("[FleetService] Notice during license detachment for deployment {$deploymentId}: " . $e->getMessage());
        }

        // 2. Cascade delete child records in auxiliary tables defensively
        $childTables = [
            'central_heartbeats',
            'central_error_logs',
            'central_support_tickets'
        ];

        foreach ($childTables as $tbl) {
            try {
                $this->pdo->prepare("DELETE FROM {$tbl} WHERE deployment_id = ?")->execute([$deploymentId]);
            } catch (\Throwable $e) {
                error_log("[FleetService] Notice deleting child records from {$tbl} for deployment {$deploymentId}: " . $e->getMessage());
            }
        }

        // 3. Delete deployment itself
        try {
            $stmt = $this->pdo->prepare("DELETE FROM central_deployments WHERE id = ?");
            $stmt->execute([$deploymentId]);
            return $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log("[FleetService] Error deleting deployment {$deploymentId}: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update support ticket status ('resolved', 'closed', 'open') and synchronize with deployed system.
     */
    public function updateTicketStatus(int $ticketId, string $status = 'resolved'): array {
        $allowed = ['open', 'in_progress', 'resolved', 'closed'];
        if (!in_array($status, $allowed, true)) {
            $status = 'resolved';
        }

        $stmt = $this->pdo->prepare("
            SELECT t.*, d.deployment_domain, d.api_ingest_token_hash, d.client_name 
            FROM central_support_tickets t
            JOIN central_deployments d ON t.deployment_id = d.id
            WHERE t.id = ?
        ");
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch();

        if (!$ticket) {
            return ['success' => false, 'error' => 'Ticket not found'];
        }

        $isSqlite = ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
        $nowFunc = $isSqlite ? "datetime('now')" : "NOW()";
        $upd = $this->pdo->prepare("UPDATE central_support_tickets SET status = ?, updated_at = {$nowFunc} WHERE id = ?");
        $upd->execute([$status, $ticketId]);

        $ticketRef = $ticket['ticket_reference'];
        $domain = $ticket['deployment_domain'] ?? '';
        $clientSynced = false;
        $syncMethod = 'none';

        // HTTP Webhook / Sync API Push to deployed system (C6, C7)
        if (!empty($domain)) {
            $cleanDomain = preg_replace('#^https?://#i', '', rtrim($domain, '/'));
            $cleanDomain = preg_replace('#[/:]+.*$#', '', $cleanDomain);

            if (self::isValidPublicDomain($cleanDomain)) {
                $endpoint = 'https://' . $cleanDomain . '/api/v1/support/sync_ticket.php';

                // Send only non-sensitive ticket sync details (do not echo api_ingest_token_hash)
                $payload = json_encode([
                    'ticket_number' => $ticketRef,
                    'status'        => $status
                ]);

                if (function_exists('curl_init')) {
                    $ch = curl_init($endpoint);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    $res = @curl_exec($ch);
                    $code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    if ($code >= 200 && $code < 300) {
                        $clientSynced = true;
                        $syncMethod = 'http_webhook';
                    }
                }
            }
        }

        return [
            'success'          => true,
            'ticket_id'        => $ticketId,
            'ticket_reference' => $ticketRef,
            'status'           => $status,
            'client_name'      => $ticket['client_name'],
            'client_synced'    => $clientSynced,
            'sync_method'      => $syncMethod
        ];
    }

    /**
     * Publish a software release (.zip package) to central_releases and update catalog latest_version.
     */
    public function publishRelease(
        int $productId,
        string $version,
        string $changelog,
        string $packageFilename,
        string $checksum,
        bool $isActive = true,
        ?int $resolvesErrorId = null
    ): array {
        $version = trim($version);
        if ($version === '') {
            throw new \InvalidArgumentException("Release version cannot be empty.");
        }
        if (trim($packageFilename) === '') {
            throw new \InvalidArgumentException("Package filename cannot be empty.");
        }
        if (trim($checksum) === '') {
            throw new \InvalidArgumentException("Package checksum cannot be empty.");
        }

        // Check if release exists for product and version
        $checkStmt = $this->pdo->prepare("SELECT id FROM central_releases WHERE product_id = ? AND version = ?");
        $checkStmt->execute([$productId, $version]);
        $existing = $checkStmt->fetch();

        if ($existing) {
            $releaseId = (int)$existing['id'];
            $upd = $this->pdo->prepare("
                UPDATE central_releases SET
                    changelog = :changelog,
                    resolves_error_id = :resolves_error_id,
                    package_filename = :filename,
                    package_checksum = :checksum,
                    is_active = :active,
                    released_at = " . $this->nowFunc() . "
                WHERE id = :id
            ");
            $upd->execute([
                ':changelog' => $changelog,
                ':resolves_error_id' => $resolvesErrorId,
                ':filename' => $packageFilename,
                ':checksum' => $checksum,
                ':active' => $isActive ? 1 : 0,
                ':id' => $releaseId,
            ]);
        } else {
            $ins = $this->pdo->prepare("
                INSERT INTO central_releases (
                    product_id, version, changelog, resolves_error_id, package_filename, package_checksum, is_active, released_at
                ) VALUES (
                    :product_id, :version, :changelog, :resolves_error_id, :filename, :checksum, :active, " . $this->nowFunc() . "
                )
            ");
            $ins->execute([
                ':product_id' => $productId,
                ':version' => $version,
                ':changelog' => $changelog,
                ':resolves_error_id' => $resolvesErrorId,
                ':filename' => $packageFilename,
                ':checksum' => $checksum,
                ':active' => $isActive ? 1 : 0,
            ]);
            $releaseId = (int)$this->pdo->lastInsertId();
        }

        // Closed-Loop: If this release resolves an error log, mark error status
        if ($resolvesErrorId !== null && $resolvesErrorId > 0) {
            try {
                // Check if target deployment is already running this version or newer
                $depCheck = $this->pdo->prepare("
                    SELECT d.current_version 
                    FROM central_error_logs e
                    JOIN central_deployments d ON e.deployment_id = d.id
                    WHERE e.id = ?
                ");
                $depCheck->execute([$resolvesErrorId]);
                $depCurrentVer = ltrim(trim((string)$depCheck->fetchColumn()), 'vV');
                $targetVer = ltrim(trim($version), 'vV');

                $alreadyPatched = ($depCurrentVer !== '' && version_compare($depCurrentVer, $targetVer, '>='));
                $newStatus = $alreadyPatched ? 'resolved' : 'patch_pending';
                $resolvedFlag = $alreadyPatched ? 1 : 0;

                $errUpd = $this->pdo->prepare("
                    UPDATE central_error_logs 
                    SET status = :status,
                        resolved = :resolved,
                        resolved_by_version = :ver,
                        resolved_at = CASE WHEN :already_patched = 1 THEN " . $this->nowFunc() . " ELSE resolved_at END
                    WHERE id = :err_id AND (status != 'resolved' OR status IS NULL)
                ");
                $errUpd->execute([
                    ':status' => $newStatus,
                    ':resolved' => $resolvedFlag,
                    ':ver' => $version,
                    ':already_patched' => $alreadyPatched ? 1 : 0,
                    ':err_id' => $resolvesErrorId
                ]);
            } catch (\Throwable $errEx) {
                error_log("[FleetService] Failed to update resolved error log status: " . $errEx->getMessage());
            }
        }

        // Update product latest_version
        $updProd = $this->pdo->prepare("UPDATE central_products SET latest_version = :ver WHERE id = :id");
        $updProd->execute([':ver' => $version, ':id' => $productId]);

        return [
            'id' => $releaseId,
            'product_id' => $productId,
            'version' => $version,
            'package_filename' => $packageFilename,
            'package_file' => $packageFilename,
            'package_checksum' => $checksum,
            'checksum_sha256' => $checksum,
            'is_active' => $isActive ? 1 : 0,
            'released_at' => date('Y-m-d H:i:s'),
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Reconciles error logs that are in 'patch_pending' status against deployment versions.
     * If the deployment is already running a version >= resolved_by_version (or equal),
     * the error is automatically transitioned to 'resolved'.
     *
     * @param int|null $deploymentId Optional deployment ID to restrict reconciliation to.
     * @return int[] List of error log IDs that were transitioned to resolved.
     */
    public function reconcilePatchPendingErrors(?int $deploymentId = null): array {
        $sql = "
            SELECT e.id, e.deployment_id, e.resolved_by_version, d.current_version
            FROM central_error_logs e
            JOIN central_deployments d ON e.deployment_id = d.id
            WHERE e.status = 'patch_pending' AND e.resolved_by_version IS NOT NULL AND e.resolved_by_version != ''
        ";
        $params = [];
        if ($deploymentId !== null && $deploymentId > 0) {
            $sql .= " AND e.deployment_id = :deployment_id";
            $params[':deployment_id'] = $deploymentId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $reconciledIds = [];
        foreach ($candidates as $row) {
            $depVer = ltrim(trim((string)($row['current_version'] ?? '')), 'vV');
            $fixVer = ltrim(trim((string)($row['resolved_by_version'] ?? '')), 'vV');

            if ($depVer === '' || $fixVer === '') {
                continue;
            }

            // If current deployment version is >= version that resolved this error
            if (version_compare($depVer, $fixVer, '>=')) {
                $reconciledIds[] = (int)$row['id'];
            }
        }

        if (!empty($reconciledIds)) {
            $inClause = implode(',', $reconciledIds);
            $this->pdo->exec("
                UPDATE central_error_logs 
                SET status = 'resolved', resolved = 1, resolved_at = " . $this->nowFunc() . "
                WHERE id IN ({$inClause})
            ");
        }

        return $reconciledIds;
    }

    /**
     * Get all published releases.
     */
    public function getReleases(?int $productId = null): array {
        $sql = "
            SELECT r.*,
                   r.released_at AS created_at,
                   r.package_checksum AS checksum_sha256,
                   r.package_filename AS package_file,
                   p.product_name, p.product_slug
            FROM central_releases r
            JOIN central_products p ON r.product_id = p.id
        ";
        $params = [];
        if ($productId !== null && $productId > 0) {
            $sql .= " WHERE r.product_id = :product_id";
            $params[':product_id'] = $productId;
        }
        $sql .= " ORDER BY r.released_at DESC, r.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the latest active release for a product by ID.
     */
    public function getLatestRelease(int $productId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT r.*,
                   r.released_at AS created_at,
                   r.package_checksum AS checksum_sha256,
                   r.package_filename AS package_file,
                   p.product_name, p.product_slug
            FROM central_releases r
            JOIN central_products p ON r.product_id = p.id
            WHERE r.product_id = ? AND r.is_active = 1
            ORDER BY r.released_at DESC, r.id DESC
            LIMIT 1
        ");
        $stmt->execute([$productId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Get the latest active release for a product by slug.
     */
    public function getLatestReleaseBySlug(string $productSlug): ?array {
        $stmt = $this->pdo->prepare("
            SELECT r.*,
                   r.released_at AS created_at,
                   r.package_checksum AS checksum_sha256,
                   r.package_filename AS package_file,
                   p.product_name, p.product_slug
            FROM central_releases r
            JOIN central_products p ON r.product_id = p.id
            WHERE p.product_slug = ? AND r.is_active = 1
            ORDER BY r.released_at DESC, r.id DESC
            LIMIT 1
        ");
        $stmt->execute([$productSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }




    /**
     * Process incoming telemetry payload containing optional heartbeat and/or error batches.
     */
    public function ingestTelemetry(int $deploymentId, array $payload, ?string $clientIp = null): array {
        $result = [
            'deployment_id' => $deploymentId,
            'heartbeat_recorded' => false,
            'errors_ingested' => 0,
            'new_status' => null,
        ];

        $hasErrors = !empty($payload['errors']) && is_array($payload['errors']);
        $hasHeartbeat = !empty($payload['heartbeat']) && is_array($payload['heartbeat']);

        if ($hasErrors) {
            // Server-side cap of 100 errors per batch
            $payload['errors'] = array_slice($payload['errors'], 0, 100);
        }

        $this->pdo->beginTransaction();

        try {
            // 1. Ingest Heartbeat if present
            if ($hasHeartbeat) {
                $hb = $payload['heartbeat'];

                $memUsage = max(0.0, (float)($hb['memory_usage_mb'] ?? 0));
                $dbLatency = max(0.0, (float)($hb['db_latency_ms'] ?? 0));
                $storageFree = max(0.0, (float)($hb['storage_free_gb'] ?? 0));
                $activeUsers = max(0, min(10000000, (int)($hb['active_users_24h'] ?? 0)));
                $loadAvg = isset($hb['load_avg']) ? max(0.0, (float)$hb['load_avg']) : null;

                // Determine deployment health status based on metrics and error presence
                $status = 'healthy';
                $statusReason = 'Operating normally';

                if ($hasErrors) {
                    $status = 'warning';
                    $statusReason = 'Errors reported in telemetry batch';
                }

                if ($dbLatency > 1500 || ($storageFree > 0 && $storageFree < 1.0)) {
                    $status = 'critical';
                    $statusReason = ($dbLatency > 1500) ? 'High DB Latency (' . round($dbLatency) . 'ms)' : 'Critically low disk space (< 1GB)';
                } elseif ($dbLatency > 500 || ($storageFree > 0 && $storageFree < 3.0)) {
                    if ($status !== 'critical') {
                        $status = 'warning';
                        $statusReason = ($dbLatency > 500) ? 'Elevated DB latency (' . round($dbLatency) . 'ms)' : 'Low disk space (< 3GB)';
                    }
                }

                $stmtDeploy = $this->pdo->prepare("
                    UPDATE central_deployments SET 
                        current_version = :version,
                        php_version = :php_ver,
                        mysql_version = :mysql_ver,
                        os_info = :os,
                        server_ip = :ip,
                        memory_usage_mb = :mem,
                        db_latency_ms = :latency,
                        storage_free_gb = :storage,
                        active_users_24h = :users,
                        load_avg = :load,
                        last_heartbeat_at = " . $this->nowFunc() . ",
                        status = :status,
                        status_reason = :status_reason,
                        updated_at = " . $this->nowFunc() . "
                    WHERE id = :id
                ");

                $stmtDeploy->execute([
                    ':version' => (string)($hb['version'] ?? '1.0.0'),
                    ':php_ver' => isset($hb['php_version']) ? (string)$hb['php_version'] : null,
                    ':mysql_ver' => isset($hb['mysql_version']) ? (string)$hb['mysql_version'] : null,
                    ':os' => isset($hb['os_info']) ? (string)$hb['os_info'] : null,
                    ':ip' => $clientIp,
                    ':mem' => $memUsage,
                    ':latency' => $dbLatency,
                    ':storage' => $storageFree,
                    ':users' => $activeUsers,
                    ':load' => $loadAvg,
                    ':status' => $status,
                    ':status_reason' => $statusReason,
                    ':id' => $deploymentId,
                ]);

                $stmtHb = $this->pdo->prepare("
                    INSERT INTO central_heartbeats (
                        deployment_id, memory_usage_mb, db_latency_ms, storage_free_gb, active_users_24h, load_avg, recorded_at
                    ) VALUES (
                        :deployment_id, :mem, :latency, :storage, :users, :load, " . $this->nowFunc() . "
                    )
                ");

                $stmtHb->execute([
                    ':deployment_id' => $deploymentId,
                    ':mem' => $memUsage,
                    ':latency' => $dbLatency,
                    ':storage' => $storageFree,
                    ':users' => $activeUsers,
                    ':load' => $loadAvg,
                ]);

                $result['heartbeat_recorded'] = true;
                $result['new_status'] = $status;
            }

            // 2. Ingest Error Logs with Fingerprinting (Deduplication)
            if ($hasErrors) {
                foreach ($payload['errors'] as $err) {
                    if (!is_array($err)) {
                        continue;
                    }

                    $level = strtoupper((string)($err['level'] ?? 'EXCEPTION'));
                    $message = (string)($err['message'] ?? 'Unknown Error');
                    $file = isset($err['file']) ? (string)$err['file'] : null;
                    $line = isset($err['line']) ? (int)$err['line'] : null;
                    $trace = isset($err['trace']) ? (string)$err['trace'] : null;
                    $uri = isset($err['uri']) ? (string)$err['uri'] : null;
                    $occurredAt = isset($err['timestamp']) ? (string)$err['timestamp'] : date('Y-m-d H:i:s');

                    // Compute error fingerprint hash with numeric normalization (H6)
                    $normalized = preg_replace('/\b\d+\b/', '#', $message);
                    $errorHash = hash('sha256', "{$normalized}:{$file}:{$line}");

                    // Check if identical active error already exists for this deployment
                    $stmtCheck = $this->pdo->prepare("
                        SELECT id, occurrences FROM central_error_logs
                        WHERE deployment_id = :dep_id AND error_hash = :hash AND resolved = 0
                        LIMIT 1
                    ");
                    $stmtCheck->execute([
                        ':dep_id' => $deploymentId,
                        ':hash' => $errorHash,
                    ]);
                    $existing = $stmtCheck->fetch();

                    if ($existing) {
                        // Deduplicate: increment occurrences and update last_seen_at
                        $stmtUpdateErr = $this->pdo->prepare("
                            UPDATE central_error_logs SET 
                                occurrences = occurrences + 1,
                                last_seen_at = :last_seen,
                                error_message = :msg,
                                stack_trace = :trace,
                                request_uri = :uri
                            WHERE id = :id
                        ");
                        $stmtUpdateErr->execute([
                            ':last_seen' => $occurredAt,
                            ':msg' => $message,
                            ':trace' => $trace,
                            ':uri' => $uri,
                            ':id' => $existing['id'],
                        ]);
                    } else {
                        // Insert new error record
                        $stmtInsertErr = $this->pdo->prepare("
                            INSERT INTO central_error_logs (
                                deployment_id, error_hash, error_level, error_message,
                                file_path, line_number, stack_trace, request_uri,
                                ip_address, occurrences, first_seen_at, last_seen_at, resolved, created_at
                            ) VALUES (
                                :dep_id, :hash, :level, :msg,
                                :file, :line, :trace, :uri,
                                :ip, 1, :first_seen, :last_seen, 0, " . $this->nowFunc() . "
                            )
                        ");
                        $stmtInsertErr->execute([
                            ':dep_id' => $deploymentId,
                            ':hash' => $errorHash,
                            ':level' => $level,
                            ':msg' => $message,
                            ':file' => $file,
                            ':line' => $line,
                            ':trace' => $trace,
                            ':uri' => $uri,
                            ':ip' => $clientIp,
                            ':first_seen' => $occurredAt,
                            ':last_seen' => $occurredAt,
                        ]);
                    }

                    $result['errors_ingested']++;
                }

                // If error-only batch (no heartbeat object), update last_heartbeat_at to keep node online in registry
                if (!$hasHeartbeat) {
                    $stmtTouch = $this->pdo->prepare("
                        UPDATE central_deployments 
                        SET last_heartbeat_at = " . $this->nowFunc() . ",
                            status = CASE WHEN status = 'critical' THEN 'critical' ELSE 'warning' END,
                            status_reason = 'Active errors reported in telemetry',
                            updated_at = " . $this->nowFunc() . "
                        WHERE id = ?
                    ");
                    $stmtTouch->execute([$deploymentId]);
                    $result['new_status'] = 'warning';
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException("Telemetry processing error: " . $e->getMessage(), 0, $e);
        }

        return $result;
    }

    /**
     * Get aggregate statistics for the fleet dashboard.
     */
    public function getFleetOverview(): array {
        // Product counts
        $stmtProducts = $this->pdo->query("SELECT COUNT(*) FROM central_products");
        $totalProducts = (int)$stmtProducts->fetchColumn();

        // Deployment counts by status
        $stmtStatus = $this->pdo->query("
            SELECT status, COUNT(*) as count 
            FROM central_deployments 
            GROUP BY status
        ");
        $statusCounts = [
            'healthy' => 0,
            'warning' => 0,
            'critical' => 0,
            'unreachable' => 0,
        ];
        $totalDeployments = 0;
        while ($row = $stmtStatus->fetch()) {
            $st = (string)$row['status'];
            $cnt = (int)$row['count'];
            $statusCounts[$st] = $cnt;
            $totalDeployments += $cnt;
        }

        // Active errors in the last 24 hours
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmtErrors = $this->pdo->query("
                SELECT COUNT(*) FROM central_error_logs 
                WHERE resolved = 0 AND last_seen_at >= datetime('now', '-24 hours')
            ");
        } else {
            $stmtErrors = $this->pdo->query("
                SELECT COUNT(*) FROM central_error_logs 
                WHERE resolved = 0 AND last_seen_at >= NOW() - INTERVAL 24 HOUR
            ");
        }
        $recentErrors = (int)$stmtErrors->fetchColumn();

        // Products list with deployment counters
        $stmtProdList = $this->pdo->query("
            SELECT p.id, p.product_slug, p.product_name, p.latest_version,
                   COUNT(d.id) as deployment_count
            FROM central_products p
            LEFT JOIN central_deployments d ON p.id = d.product_id
            GROUP BY p.id, p.product_slug, p.product_name, p.latest_version
            ORDER BY p.product_name ASC
        ");
        $products = $stmtProdList->fetchAll();

        return [
            'total_products' => $totalProducts,
            'total_deployments' => $totalDeployments,
            'healthy_count' => $statusCounts['healthy'],
            'warning_count' => $statusCounts['warning'],
            'critical_count' => $statusCounts['critical'],
            'unreachable_count' => $statusCounts['unreachable'],
            'recent_errors_24h' => $recentErrors,
            'products' => $products,
        ];
    }

    /**
     * Get deployment list with filtering.
     */
    public function getDeployments(?int $productId = null, ?string $status = null, ?string $search = null): array {
        $sql = "
            SELECT d.id, d.product_id, d.client_name, d.deployment_domain, d.environment,
                   d.token_preview, d.current_version, d.php_version, d.mysql_version, d.os_info,
                   d.status, d.status_reason, d.last_heartbeat_at, d.created_at,
                   p.product_slug, p.product_name, p.latest_version as target_version
            FROM central_deployments d
            JOIN central_products p ON d.product_id = p.id
            WHERE 1=1
        ";
        $params = [];

        if ($productId !== null) {
            $sql .= " AND d.product_id = :product_id";
            $params[':product_id'] = $productId;
        }

        if ($status !== null && $status !== 'all') {
            $sql .= " AND d.status = :status";
            $params[':status'] = $status;
        }

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (d.client_name LIKE :search OR d.deployment_domain LIKE :search)";
            $params[':search'] = '%' . trim($search) . '%';
        }

        $sql .= " ORDER BY 
            CASE d.status 
                WHEN 'critical' THEN 1 
                WHEN 'warning' THEN 2 
                WHEN 'unreachable' THEN 3 
                ELSE 4 
            END ASC, 
            d.last_heartbeat_at DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $deployments = $stmt->fetchAll();

        // Enrich with version status
        foreach ($deployments as &$dep) {
            $dep['is_outdated'] = version_compare((string)$dep['current_version'], (string)$dep['target_version'], '<');
        }

        return $deployments;
    }

    /**
     * Get recent errors grouped with deployment and product details.
     */
    public function getErrorLogs(?int $deploymentId = null, int $limit = 50): array {
        $sql = "
            SELECT e.id, e.deployment_id, e.error_hash, e.error_level, e.error_message,
                   e.file_path, e.line_number, e.stack_trace, e.request_uri, e.occurrences,
                   e.first_seen_at, e.last_seen_at, e.resolved,
                   d.client_name, d.deployment_domain, p.product_name
            FROM central_error_logs e
            JOIN central_deployments d ON e.deployment_id = d.id
            JOIN central_products p ON d.product_id = p.id
            WHERE 1=1
        ";
        $params = [];

        if ($deploymentId !== null) {
            $sql .= " AND e.deployment_id = :dep_id";
            $params[':dep_id'] = $deploymentId;
        }

        $sql .= " ORDER BY e.last_seen_at DESC LIMIT :limit";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get recent telemetry time-series metrics for a deployment.
     */
    public function getDeploymentMetrics(int $deploymentId, int $limit = 24): array {
        $stmt = $this->pdo->prepare("
            SELECT id, memory_usage_mb, db_latency_ms, storage_free_gb, active_users_24h, load_avg, recorded_at
            FROM central_heartbeats
            WHERE deployment_id = :dep_id
            ORDER BY recorded_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':dep_id', $deploymentId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        return array_reverse($rows); // return chronological order for charts
    }

    /**
     * Background fleet health checker and stale heartbeat sweeper.
     */
    public function runFleetMonitorCheck(): array {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            // Unreachable: last heartbeat older than 2 hours (or never had a heartbeat AND registered > 2 hours ago)
            $stmtUnreachable = $this->pdo->prepare("
                UPDATE central_deployments 
                SET status = 'unreachable', 
                    status_reason = 'No heartbeat received in over 2 hours'
                WHERE (last_heartbeat_at < datetime('now', '-2 hours') OR (last_heartbeat_at IS NULL AND created_at < datetime('now', '-2 hours')))
                  AND status != 'unreachable'
            ");
            $stmtUnreachable->execute();
            $unreachableMarked = $stmtUnreachable->rowCount();

            // Warning: last heartbeat between 30 mins and 2 hours
            $stmtWarning = $this->pdo->prepare("
                UPDATE central_deployments 
                SET status = 'warning',
                    status_reason = 'Heartbeat overdue (> 30 minutes)'
                WHERE last_heartbeat_at BETWEEN datetime('now', '-2 hours') AND datetime('now', '-30 minutes')
                  AND status = 'healthy'
            ");
            $stmtWarning->execute();
            $warningMarked = $stmtWarning->rowCount();

            // Prune heartbeats older than 60 days in chunks of 1000 (M1)
            $prunedRows = 0;
            do {
                $stmtPrune = $this->pdo->prepare("
                    DELETE FROM central_heartbeats 
                    WHERE id IN (
                        SELECT id FROM central_heartbeats 
                        WHERE recorded_at < datetime('now', '-60 days')
                        LIMIT 1000
                    )
                ");
                $stmtPrune->execute();
                $deleted = $stmtPrune->rowCount();
                $prunedRows += $deleted;
            } while ($deleted === 1000);
        } else {
            // MySQL syntax
            $stmtUnreachable = $this->pdo->prepare("
                UPDATE central_deployments 
                SET status = 'unreachable', 
                    status_reason = 'No heartbeat received in over 2 hours'
                WHERE (last_heartbeat_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR OR (last_heartbeat_at IS NULL AND created_at < UTC_TIMESTAMP() - INTERVAL 2 HOUR))
                  AND status != 'unreachable'
            ");
            $stmtUnreachable->execute();
            $unreachableMarked = $stmtUnreachable->rowCount();

            $stmtWarning = $this->pdo->prepare("
                UPDATE central_deployments 
                SET status = 'warning',
                    status_reason = 'Heartbeat overdue (> 30 minutes)'
                WHERE last_heartbeat_at BETWEEN UTC_TIMESTAMP() - INTERVAL 2 HOUR AND UTC_TIMESTAMP() - INTERVAL 30 MINUTE
                  AND status = 'healthy'
            ");
            $stmtWarning->execute();
            $warningMarked = $stmtWarning->rowCount();

            // Prune heartbeats older than 60 days in chunks of 1000 (M1)
            $prunedRows = 0;
            do {
                $stmtPrune = $this->pdo->prepare("
                    DELETE FROM central_heartbeats 
                    WHERE recorded_at < UTC_TIMESTAMP() - INTERVAL 60 DAY
                    LIMIT 1000
                ");
                $stmtPrune->execute();
                $deleted = $stmtPrune->rowCount();
                $prunedRows += $deleted;
            } while ($deleted === 1000);
        }

        // Automatic error log retention lifecycle (Archive >30d, Delete >60d)
        $errorLifecycle = $this->lifecycleErrorLogs(30, 60);

        return [
            'unreachable_marked' => $unreachableMarked,
            'warning_marked'     => $warningMarked,
            'pruned_heartbeats'  => $prunedRows,
            'heartbeats_pruned'  => $prunedRows,
            'archived_errors'    => $errorLifecycle['archived_count'],
            'deleted_errors'     => $errorLifecycle['deleted_count'],
            'checked_at'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Error Log Lifecycle Retention Manager:
     * - Archives resolved errors older than 30 days (status -> 'archived').
     * - Permanently purges archived or resolved errors older than 60 days.
     *
     * @param int $archiveDays Days after resolution before archiving (default 30)
     * @param int $deleteDays  Days after resolution before permanent deletion (default 60)
     * @return array{archived_count: int, deleted_count: int, archive_threshold: string, delete_threshold: string, executed_at: string}
     */
    public function lifecycleErrorLogs(int $archiveDays = 30, int $deleteDays = 60): array {
        $archiveDate = gmdate('Y-m-d H:i:s', time() - ($archiveDays * 86400));
        $deleteDate  = gmdate('Y-m-d H:i:s', time() - ($deleteDays * 86400));

        // 1. Archive resolved errors older than 30 days
        $stmtArchive = $this->pdo->prepare("
            UPDATE central_error_logs
            SET status = 'archived'
            WHERE (status = 'resolved' OR resolved = 1)
              AND status != 'archived'
              AND (
                  (resolved_at IS NOT NULL AND resolved_at <= :archive_date)
                  OR (resolved_at IS NULL AND last_seen_at <= :archive_date_fallback)
              )
        ");
        $stmtArchive->execute([
            ':archive_date'          => $archiveDate,
            ':archive_date_fallback' => $archiveDate,
        ]);
        $archivedCount = $stmtArchive->rowCount();

        // 2. Permanently delete archived or resolved errors older than 60 days
        $stmtDelete = $this->pdo->prepare("
            DELETE FROM central_error_logs
            WHERE (status IN ('resolved', 'archived') OR resolved = 1)
              AND (
                  (resolved_at IS NOT NULL AND resolved_at <= :delete_date)
                  OR (resolved_at IS NULL AND last_seen_at <= :delete_date_fallback)
              )
        ");
        $stmtDelete->execute([
            ':delete_date'          => $deleteDate,
            ':delete_date_fallback' => $deleteDate,
        ]);
        $deletedCount = $stmtDelete->rowCount();

        return [
            'archived_count'    => $archivedCount,
            'deleted_count'     => $deletedCount,
            'archive_threshold' => $archiveDate,
            'delete_threshold'  => $deleteDate,
            'executed_at'       => gmdate('c'),
        ];
    }

    private function nowFunc(): string {
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        return ($driver === 'sqlite') ? "datetime('now')" : "UTC_TIMESTAMP()";
    }
}
