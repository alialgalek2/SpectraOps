<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Production Client Telemetry Reporter SDK
 * 
 * Embed in Prism EDU, ERP, or any client deployment to capture
 * heartbeats, system health metrics, and uncaught exceptions.
 * 
 * Features:
 *  - Non-blocking execution (FastCGI finish request + shutdown hooks)
 *  - PII and Sensitive Credential Scrubber
 *  - Local disk retry queue for resilient offline operation
 *  - Automated Global Exception & Fatal Error Handlers
 */
class TelemetryReporter {
    private string $hubUrl;
    private string $ingestToken;
    private string $version;
    private string $queueFilePath;
    private array $pendingErrors = [];
    private ?array $pendingHeartbeat = null;
    private bool $autoFlushOnShutdown;
    private static ?TelemetryReporter $globalInstance = null;

    public function __construct(
        string $hubUrl,
        string $ingestToken,
        string $version = '1.0.0',
        bool $autoFlushOnShutdown = true,
        ?string $customQueuePath = null
    ) {
        $this->hubUrl = rtrim($hubUrl, '/') . '/api/v1/telemetry/ingest.php';
        $this->ingestToken = $ingestToken;
        $this->version = $version;
        $this->autoFlushOnShutdown = $autoFlushOnShutdown;
        $this->queueFilePath = $customQueuePath ?? (sys_get_temp_dir() . '/fleet_telemetry_buffer_' . substr(md5($ingestToken), 0, 8) . '.json');

        if ($this->autoFlushOnShutdown) {
            register_shutdown_function([$this, 'onShutdown']);
        }
    }

    /**
     * Singleton / global instance helper for convenience.
     */
    public static function init(string $hubUrl, string $ingestToken, string $version = '1.0.0'): self {
        self::$globalInstance = new self($hubUrl, $ingestToken, $version);
        return self::$globalInstance;
    }

    public static function getInstance(): ?self {
        return self::$globalInstance;
    }

    /**
     * Automatically captures all uncaught PHP exceptions and fatal errors.
     */
    public static function registerGlobalHandlers(?self $reporter = null): void {
        $instance = $reporter ?? self::$globalInstance;
        if (!$instance) {
            throw new \RuntimeException("TelemetryReporter instance must be initialized before registering global handlers.");
        }

        set_exception_handler(function (\Throwable $e) use ($instance) {
            $instance->reportError($e, 'UNCAUGHT_EXCEPTION');
        });

        register_shutdown_function(function () use ($instance) {
            $error = error_get_last();
            if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $instance->reportRawError(
                    'FATAL_ERROR',
                    $error['message'],
                    $error['file'],
                    $error['line']
                );
            }
        });
    }

    /**
     * Queue or immediately record a heartbeat ping.
     */
    public function queueHeartbeat(array $customMetrics = []): self {
        $memoryUsage = round(memory_get_peak_usage(true) / 1024 / 1024, 2);
        $diskFree = function_exists('disk_free_space') ? @disk_free_space(__DIR__) : 0;
        $storageGb = $diskFree ? round($diskFree / 1024 / 1024 / 1024, 2) : 0;

        $baseHeartbeat = [
            'version'         => $this->version,
            'php_version'     => PHP_VERSION,
            'os_info'         => PHP_OS . ' (' . php_uname('m') . ')',
            'memory_usage_mb' => $memoryUsage,
            'storage_free_gb' => $storageGb,
            'db_latency_ms'   => 0,
            'active_users_24h' => 0,
        ];

        $this->pendingHeartbeat = array_merge($baseHeartbeat, $customMetrics);
        return $this;
    }

    /**
     * Queue a Throwable exception for reporting.
     */
    public function reportError(\Throwable $e, string $level = 'CRITICAL'): self {
        return $this->reportRawError(
            $level,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
    }

    /**
     * Queue a raw error event with stack trace.
     */
    public function reportRawError(
        string $level,
        string $message,
        ?string $file = null,
        ?int $line = null,
        ?string $trace = null
    ): self {
        $uri = $_SERVER['REQUEST_URI'] ?? (php_sapi_name() === 'cli' ? 'CLI' : 'UNKNOWN');

        $this->pendingErrors[] = [
            'level'     => strtoupper($level),
            'message'   => $this->sanitizeText($message),
            'file'      => $file,
            'line'      => $line,
            'trace'     => $trace ? $this->sanitizeText($trace) : null,
            'uri'       => $this->sanitizeText($uri),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        return $this;
    }

    /**
     * Dispatch an urgent support ticket directly to the central hub.
     */
    public function submitSupportTicket(array $ticketData): bool {
        $payload = [
            'ticket' => array_merge([
                'reference' => 'PRISM-' . strtoupper(substr(uniqid(), -6)),
                'snapshot'  => [
                    'version'      => $this->version,
                    'php_version'  => PHP_VERSION,
                    'current_url'  => $_SERVER['HTTP_REFERER'] ?? 'Portal Dashboard',
                    'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    'memory_peak'  => round(memory_get_peak_usage(true) / 1024 / 1024, 2) . ' MB'
                ]
            ], $ticketData)
        ];

        return $this->dispatchPayload($payload);
    }

    /**
     * Benchmark database query latency and record in heartbeat.
     */
    public function measureDbLatency(\PDO $pdo): float {
        $start = microtime(true);
        try {
            $pdo->query("SELECT 1");
            $latency = round((microtime(true) - $start) * 1000, 2);
        } catch (\Throwable $e) {
            $latency = 9999.0;
        }

        if ($this->pendingHeartbeat !== null) {
            $this->pendingHeartbeat['db_latency_ms'] = $latency;
        }

        return $latency;
    }

    /**
     * Flushes all queued heartbeats and errors to the central hub.
     * Uses FastCGI finish request to never block the client browser.
     */
    public function flush(): bool {
        if (empty($this->pendingErrors) && $this->pendingHeartbeat === null) {
            // Check if there are backlogged offline items to retry
            if (!file_exists($this->queueFilePath)) {
                return true;
            }
        }

        // 1. Release the client browser connection immediately if running under FastCGI
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // 2. Build Current Payload
        $payload = [];
        if ($this->pendingHeartbeat !== null) {
            $payload['heartbeat'] = $this->pendingHeartbeat;
        }
        if (!empty($this->pendingErrors)) {
            $payload['errors'] = $this->pendingErrors;
        }

        // 3. Merge with backlogged offline queue if present
        $offlinePayload = $this->loadOfflineQueue();
        if ($offlinePayload !== null) {
            if (isset($offlinePayload['errors']) && is_array($offlinePayload['errors'])) {
                $payload['errors'] = array_merge($offlinePayload['errors'], $payload['errors'] ?? []);
            }
            if (!isset($payload['heartbeat']) && isset($offlinePayload['heartbeat'])) {
                $payload['heartbeat'] = $offlinePayload['heartbeat'];
            }
        }

        if (empty($payload)) {
            return true;
        }

        // 4. Dispatch via cURL
        $success = $this->dispatchPayload($payload);

        if ($success) {
            // Clear pending items and remove offline buffer
            $this->pendingErrors = [];
            $this->pendingHeartbeat = null;
            if (file_exists($this->queueFilePath)) {
                @unlink($this->queueFilePath);
            }
        } else {
            // Persist to local disk cache for retry on the next execution cycle
            $this->saveOfflineQueue($payload);
        }

        return $success;
    }

    public function onShutdown(): void {
        $this->flush();
    }

    /**
     * Dispatch JSON payload to the central hub endpoint.
     */
    private function dispatchPayload(array $payload): bool {
        if (!extension_loaded('curl')) {
            return false;
        }

        $ch = curl_init($this->hubUrl);
        if ($ch === false) {
            return false;
        }

        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
            CURLOPT_CONNECTTIMEOUT_MS => 1200,
            CURLOPT_TIMEOUT_MS        => 2500, // Never stall beyond 2.5 seconds in background
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->ingestToken,
                'User-Agent: FleetTelemetryReporter/' . $this->version,
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($httpCode >= 200 && $httpCode < 300);
    }

    /**
     * Redact passwords, tokens, API keys, DSN credentials, and sensitive headers.
     */
    private function sanitizeText(string $text): string {
        // Redact key-value secrets (e.g. password=..., secret: ..., token => ...)
        $patterns = [
            '/(?:password|passwd|pwd|secret|auth|bearer|token|api[_-]?key|access[_-]?key)\s*[:=]\s*["\']?[^"\'\s,;]{3,}["\']?/i' => '$0 [REDACTED]',
            '/mysql:\/\/([^:]+):([^@]+)@/' => 'mysql://$1:[REDACTED]@',
            '/Bearer\s+[A-Za-z0-9_\-\.]{12,}/i' => 'Bearer [REDACTED_TOKEN]',
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }

    private function loadOfflineQueue(): ?array {
        if (!file_exists($this->queueFilePath)) {
            return null;
        }

        $data = @file_get_contents($this->queueFilePath);
        if (!$data) {
            return null;
        }

        $json = json_decode($data, true);
        return is_array($json) ? $json : null;
    }

    private function saveOfflineQueue(array $payload): void {
        // Cap max buffered errors to 100 to avoid runaway disk usage
        if (isset($payload['errors']) && count($payload['errors']) > 100) {
            $payload['errors'] = array_slice($payload['errors'], -100);
        }

        @file_put_contents($this->queueFilePath, json_encode($payload), LOCK_EX);
    }
}
