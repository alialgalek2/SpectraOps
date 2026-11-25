<?php
declare(strict_types=1);

/**
 * SpectraTelemetryGateway — Canonical SpectraOps Client SDK (Standalone, PHP 8.0+)
 *
 * Drop-in single-pipe telemetry, license-lease cache, and outbox event gateway
 * shared across Prism EDU, VendPulse, BeamStock, and ArcNav.
 *
 * Usage:
 *   $gw = SpectraTelemetryGateway::fromConfig();
 *   $gw->sync(['memory_usage_mb' => 42.5, 'db_latency_ms' => 12]);
 */
class SpectraTelemetryGateway
{
    private array $config;
    private string $cacheDir;
    private string $stateFile;
    private string $outboxFile;
    private int $offlineGraceSec;
    private int $timeoutSec;

    public function __construct(array $config, ?string $storageDir = null, int $offlineGraceSec = 604800, int $timeoutSec = 8)
    {
        $this->config = $config;
        $baseDir = $storageDir !== null && $storageDir !== ''
            ? rtrim($storageDir, '/\\')
            : $this->detectStorageDir();

        $this->cacheDir = $baseDir . DIRECTORY_SEPARATOR . 'cache';
        $this->stateFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'spectra_state.json';
        $this->outboxFile = $this->cacheDir . DIRECTORY_SEPARATOR . 'spectra_outbox.json';
        $this->offlineGraceSec = max(0, $offlineGraceSec);
        $this->timeoutSec = max(2, $timeoutSec);
    }

    /**
     * Auto-locate spectraops.json and instantiate the gateway.
     *
     * @throws RuntimeException If spectraops.json cannot be found or parsed.
     */
    public static function fromConfig(?string $configPath = null, ?string $storageDir = null): self
    {
        $resolved = self::locateConfig($configPath);
        if ($resolved === null) {
            throw new RuntimeException('spectraops.json connection bundle not found.');
        }

        $raw = @file_get_contents($resolved);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['deployment_key'])) {
            throw new RuntimeException("Invalid spectraops.json bundle at {$resolved}.");
        }

        if ($storageDir === null) {
            $storageDir = dirname($resolved) . DIRECTORY_SEPARATOR . 'storage';
        }

        return new self($data, $storageDir);
    }

    /**
     * Locate spectraops.json across standard application root paths.
     */
    public static function locateConfig(?string $explicitPath = null): ?string
    {
        $candidates = [];
        if ($explicitPath !== null && $explicitPath !== '') {
            $candidates[] = $explicitPath;
        }
        $envPath = getenv('SPECTRAOPS_CONFIG');
        if (is_string($envPath) && $envPath !== '') {
            $candidates[] = $envPath;
        }

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== '') {
            $candidates[] = $cwd . DIRECTORY_SEPARATOR . 'spectraops.json';
        }
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/\\');
            $candidates[] = $docRoot . DIRECTORY_SEPARATOR . 'spectraops.json';
            $candidates[] = dirname($docRoot) . DIRECTORY_SEPARATOR . 'spectraops.json';
        }
        $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . 'spectraops.json';
        $candidates[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'spectraops.json';
        $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'spectraops.json';

        foreach ($candidates as $path) {
            if (is_string($path) && $path !== '' && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Queue an event (ticket, error, telemetry) in storage/cache/spectra_outbox.json.
     */
    public function queueEvent(string $eventType, array $payload, ?string $eventId = null): string
    {
        $id = $eventId ?: ('evt_' . bin2hex(random_bytes(8)));
        $event = [
            'event_id'   => $id,
            'event_type' => $eventType,
            'timestamp'  => gmdate('c'),
            'payload'    => $payload,
        ];

        $this->ensureCacheDir();
        $outbox = $this->readJsonFile($this->outboxFile, []);
        $outbox[] = $event;
        // Keep outbox bounded to prevent unbounded disk growth
        if (count($outbox) > 500) {
            $outbox = array_slice($outbox, -500);
        }
        $this->writeJsonFile($this->outboxFile, $outbox);

        return $id;
    }

    /**
     * Convenience helper to queue an error event.
     */
    public function reportError(string $level, string $message, string $file = '', int $line = 0, string $trace = ''): string
    {
        return $this->queueEvent('error', [
            'error_level'   => strtoupper($level),
            'error_message' => $message,
            'file_path'     => $file,
            'line_number'   => $line,
            'stack_trace'   => $trace,
            'request_uri'   => (string)($_SERVER['REQUEST_URI'] ?? ''),
        ]);
    }

    /**
     * Single-pipe POST to {api_base}/sync: updates heartbeat, flushes queued outbox events,
     * and refreshes local license state cache.
     */
    public function sync(array $telemetry = [], bool $flushOutbox = true): array
    {
        $events = $flushOutbox ? $this->readJsonFile($this->outboxFile, []) : [];
        if (!is_array($events)) {
            $events = [];
        }

        $body = [
            'deployment_id' => (string)($this->config['deployment_id'] ?? ''),
            'deployment_key' => (string)($this->config['deployment_key'] ?? ''),
            'license_token'  => (string)($this->config['license_token'] ?? ''),
            'product_slug'   => (string)($this->config['product_slug'] ?? ''),
            'environment'    => (string)($this->config['environment'] ?? 'production'),
            'live_domain'    => $this->detectLiveDomain(),
            'php_version'    => PHP_VERSION,
            'app_version'    => (string)($this->config['app_version'] ?? ($telemetry['app_version'] ?? '1.0.0')),
            'telemetry'      => $telemetry,
            'events'         => array_values($events),
        ];

        $apiBase = rtrim((string)($this->config['api_base'] ?? ($this->config['hub_url'] ?? '') . '/api/v1'), '/');
        $endpoint = $apiBase . '/sync';

        [$httpCode, $rawResponse, $transportError] = $this->postJson(
            $endpoint,
            $body,
            (string)($this->config['deployment_key'] ?? '')
        );

        $decoded = is_string($rawResponse) ? json_decode($rawResponse, true) : null;

        if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded) && (!empty($decoded['ok']) || !empty($decoded['success']))) {
            // Update local license cache
            $state = [
                'license'        => (string)($decoded['license'] ?? 'active'),
                'license_valid'  => (bool)($decoded['license_valid'] ?? true),
                'status'         => (string)($decoded['status'] ?? 'online'),
                'expires_at'     => $decoded['expires_at'] ?? null,
                'latest_version' => $decoded['latest_version'] ?? null,
                'last_sync_at'   => time(),
                'last_sync_iso'  => gmdate('c'),
            ];
            $this->ensureCacheDir();
            $this->writeJsonFile($this->stateFile, $state);

            // Remove flushed events from outbox
            if ($flushOutbox && !empty($events)) {
                $sentIds = array_column($events, 'event_id');
                $current = $this->readJsonFile($this->outboxFile, []);
                $remaining = array_values(array_filter(
                    is_array($current) ? $current : [],
                    static fn($e) => is_array($e) && !in_array($e['event_id'] ?? '', $sentIds, true)
                ));
                $this->writeJsonFile($this->outboxFile, $remaining);
            }

            return array_merge($decoded, ['synced' => true]);
        }

        return [
            'ok'            => false,
            'success'       => false,
            'synced'        => false,
            'http_code'     => $httpCode,
            'error'         => $decoded['error'] ?? ($transportError ?: 'Sync request failed.'),
            'queued_events' => count($events),
        ];
    }

    /**
     * Evaluate current license lease status using cached state + offline grace period.
     */
    public function checkLicense(bool $allowRemoteSync = true): array
    {
        $state = $this->readJsonFile($this->stateFile, []);
        $now = time();
        $lastSync = (int)($state['last_sync_at'] ?? 0);

        if ($allowRemoteSync && ($lastSync === 0 || ($now - $lastSync) >= 300)) {
            $syncRes = $this->sync([], true);
            if (!empty($syncRes['synced'])) {
                $state = $this->readJsonFile($this->stateFile, []);
            }
        }

        if (empty($state)) {
            return [
                'valid'  => true,
                'status' => 'grace_initial',
                'cached' => false,
            ];
        }

        $licenseStatus = strtolower((string)($state['license'] ?? 'active'));
        if (in_array($licenseStatus, ['suspended', 'revoked'], true) || ($state['license_valid'] ?? true) === false) {
            return [
                'valid'      => false,
                'status'     => $licenseStatus,
                'expires_at' => $state['expires_at'] ?? null,
                'cached'     => true,
            ];
        }

        if (!empty($state['expires_at']) && strtotime((string)$state['expires_at']) < $now) {
            return [
                'valid'      => false,
                'status'     => 'expired',
                'expires_at' => $state['expires_at'],
                'cached'     => true,
            ];
        }

        $withinGrace = ($now - (int)($state['last_sync_at'] ?? $now)) <= $this->offlineGraceSec;
        return [
            'valid'          => $withinGrace,
            'status'         => $withinGrace ? $licenseStatus : 'offline_grace_exceeded',
            'expires_at'     => $state['expires_at'] ?? null,
            'latest_version' => $state['latest_version'] ?? null,
            'cached'         => true,
        ];
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getOutboxCount(): int
    {
        $items = $this->readJsonFile($this->outboxFile, []);
        return is_array($items) ? count($items) : 0;
    }

    private function detectLiveDomain(): string
    {
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            return $scheme . '://' . $_SERVER['HTTP_HOST'];
        }
        return (string)($this->config['domain'] ?? '');
    }

    private function detectStorageDir(): string
    {
        $cwd = getcwd();
        if (is_string($cwd) && is_dir($cwd . DIRECTORY_SEPARATOR . 'storage')) {
            return $cwd . DIRECTORY_SEPARATOR . 'storage';
        }
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    }

    private function ensureCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    private function readJsonFile(string $path, array $default): array
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }

    private function writeJsonFile(string $path, array $data): void
    {
        @file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * @return array{0:int,1:string|null,2:string|null} [httpCode, body, error]
     */
    private function postJson(string $url, array $payload, string $bearerKey): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return [0, null, 'Failed to encode JSON payload.'];
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeoutSec,
                CURLOPT_CONNECTTIMEOUT => min(4, $this->timeoutSec),
                CURLOPT_POSTFIELDS     => $json,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $bearerKey,
                    'X-Deployment-Key: ' . $bearerKey,
                ],
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            return [$code, is_string($body) ? $body : null, $err !== '' ? $err : null];
        }

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$bearerKey}\r\nX-Deployment-Key: {$bearerKey}\r\n",
                'content'       => $json,
                'timeout'       => $this->timeoutSec,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (!empty($http_response_header[0]) && preg_match('/HTTP\/\S+\s+(\d{3})/', $http_response_header[0], $m)) {
            $code = (int)$m[1];
        }
        return [$code, is_string($body) ? $body : null, $body === false ? 'HTTP stream request failed.' : null];
    }
}
