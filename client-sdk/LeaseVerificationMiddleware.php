<?php
declare(strict_types=1);

namespace FleetHub\ClientSdk;

use RuntimeException;
use Throwable;

/**
 * Client-Side Cryptographic Lease Verification Middleware
 * 
 * Enforces a rolling 2-hour HMAC-SHA256 lease token on incoming client HTTP requests,
 * validates cryptographic integrity, enforces an offline grace period,
 * and triggers background non-blocking lease renewal.
 * 
 * Token Format:
 *   Base64UrlEncoded(JSON_Payload) . "." . HMAC_SHA256_Signature
 * 
 * Payload Schema:
 *   {
 *     "deployment_id": 8,
 *     "license_id": 2,
 *     "status": "active",
 *     "expires_at": 1793000000
 *   }
 */
class LeaseVerificationMiddleware {
    private string $appRoot;
    private string $leaseSalt;
    private string $hubUrl;
    private string $licenseKey;
    private int $gracePeriodSeconds;
    private int $renewalWindowSeconds;
    private ?int $expectedDeploymentId;
    private ?int $expectedLicenseId;

    public const DEFAULT_GRACE_PERIOD_SECONDS = 259200; // 72 hours
    public const DEFAULT_RENEWAL_WINDOW_SECONDS = 1800; // 30 minutes
    public const RENEWAL_DEBOUNCE_SECONDS = 60;        // 60 seconds

    public function __construct(
        ?string $appRoot = null,
        ?string $leaseSalt = null,
        ?string $hubUrl = null,
        ?string $licenseKey = null,
        int $gracePeriodSeconds = self::DEFAULT_GRACE_PERIOD_SECONDS,
        int $renewalWindowSeconds = self::DEFAULT_RENEWAL_WINDOW_SECONDS,
        ?int $expectedDeploymentId = null,
        ?int $expectedLicenseId = null
    ) {
        $this->appRoot = rtrim($appRoot ?? dirname(__DIR__, 2), DIRECTORY_SEPARATOR);
        $this->loadEnvironment();

        $this->leaseSalt = $leaseSalt 
            ?? (string)(getenv('SPECTRAOPS_LEASE_SALT') ?: ($_ENV['SPECTRAOPS_LEASE_SALT'] ?? ''));

        $this->hubUrl = rtrim($hubUrl 
            ?? (string)(getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: 'http://127.0.0.1:8080/SPECTRAOPS/public')), '/');

        $this->licenseKey = $licenseKey 
            ?? (string)(getenv('SPECTRAOPS_LICENSE_KEY') ?: ($_ENV['SPECTRAOPS_LICENSE_KEY'] ?? ''));

        $this->gracePeriodSeconds = $gracePeriodSeconds > 0 ? $gracePeriodSeconds : self::DEFAULT_GRACE_PERIOD_SECONDS;
        $this->renewalWindowSeconds = $renewalWindowSeconds > 0 ? $renewalWindowSeconds : self::DEFAULT_RENEWAL_WINDOW_SECONDS;
        $this->expectedDeploymentId = $expectedDeploymentId;
        $this->expectedLicenseId = $expectedLicenseId;
    }

    /**
     * Primary HTTP Request Entry Point.
     * Evaluates bypass rules, verifies the local license lock, handles grace period,
     * triggers non-blocking renewal if nearing expiry, and halts unauthorized traffic.
     */
    public function handle(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $sapi = php_sapi_name();

        if ($this->shouldBypass($uri, $sapi)) {
            return true;
        }

        $check = $this->verifyCurrentInstallation();

        if ($check['valid']) {
            // If in offline grace period, attach informative response header
            if (!empty($check['in_grace_period'])) {
                $remHours = max(0, round(($check['grace_expires_at'] - time()) / 3600, 1));
                if (!headers_sent()) {
                    header("X-SpectraOps-Grace-Period: Active; RemainingHours={$remHours}");
                    header("X-SpectraOps-Lease-Status: GracePeriod");
                }
            } else {
                if (!headers_sent()) {
                    header("X-SpectraOps-Lease-Status: Active");
                }
            }

            // Expose validated payload to downstream application
            $_SERVER['SPECTRAOPS_LEASE_PAYLOAD'] = $check['payload'] ?? [];

            // Trigger non-blocking background renewal if token needs refresh
            if (!empty($check['needs_renewal'])) {
                $this->triggerBackgroundRenewal();
            }

            return true;
        }

        // Enforcement: Determine client type and halt
        $action = $this->determineEnforcementAction($check, $uri, $this->getRequestHeaders());

        if ($action['type'] === 'json_response') {
            http_response_code($action['http_code']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($action['payload'], JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Browser redirect to activation
        if (!headers_sent()) {
            header('Location: ' . $action['target_url']);
        }
        echo "<script>window.location.href=" . json_encode($action['target_url']) . ";</script>";
        exit;
    }

    /**
     * Verifies the lease token currently stored in storage/license.lock.
     */
    public function verifyCurrentInstallation(): array {
        $lockFile = $this->getLockFilePath();

        if (!file_exists($lockFile)) {
            return [
                'valid'  => false,
                'code'   => 'MISSING_LOCK',
                'reason' => 'License lock file does not exist. Application requires activation.'
            ];
        }

        $raw = (string)file_get_contents($lockFile);
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return [
                'valid'  => false,
                'code'   => 'CORRUPTED_LOCK',
                'reason' => 'License lock file is corrupted or not valid JSON.'
            ];
        }

        $token = (string)($data['lease_token'] ?? '');
        if (trim($token) === '') {
            return [
                'valid'  => false,
                'code'   => 'MISSING_TOKEN',
                'reason' => 'License lock file does not contain a lease_token.'
            ];
        }

        // If lease_salt was not provided via env, attempt to read from lock file
        if ($this->leaseSalt === '' && !empty($data['lease_salt'])) {
            $this->leaseSalt = (string)$data['lease_salt'];
        }
        if ($this->licenseKey === '' && !empty($data['license_key'])) {
            $this->licenseKey = (string)$data['license_key'];
        }
        if ($this->leaseSalt === '' && $this->licenseKey !== '') {
            $this->leaseSalt = hash('sha256', $this->licenseKey);
        }

        $result = $this->verifyToken($token);
        if (!$result['valid'] && $result['code'] === 'MALFORMED_TOKEN' && $this->hubUrl !== '' && $this->licenseKey !== '') {
            $renew = $this->renewLease();
            if (!empty($renew['renewed']) && !empty($renew['lease_token'])) {
                return $this->verifyToken($renew['lease_token']);
            }
        }

        return $result;
    }

    /**
     * Cryptographically verifies a lease token string.
     * Validates HMAC-SHA256 signature, payload schema, active status,
     * expiration, and offline grace period.
     */
    public function verifyToken(string $token): array {
        $token = trim($token);
        if ($token === '') {
            return [
                'valid'  => false,
                'code'   => 'EMPTY_TOKEN',
                'reason' => 'Lease token is empty.'
            ];
        }

        if (!str_contains($token, '.')) {
            return [
                'valid'  => false,
                'code'   => 'MALFORMED_TOKEN',
                'reason' => 'Token format must be Base64Url(Payload).Signature.'
            ];
        }

        [$encodedPayload, $providedSignature] = explode('.', $token, 2);

        // 1. Verify cryptographic HMAC-SHA256 signature
        $expectedSignature = hash_hmac('sha256', $encodedPayload, $this->leaseSalt);
        if (!hash_equals($expectedSignature, $providedSignature)) {
            return [
                'valid'  => false,
                'code'   => 'INVALID_SIGNATURE',
                'reason' => 'Cryptographic lease signature mismatch. Token is forged or invalid for this installation.'
            ];
        }

        // 2. Decode and validate JSON payload
        $jsonStr = self::base64UrlDecode($encodedPayload);
        $payload = json_decode($jsonStr, true);

        if (!is_array($payload)) {
            return [
                'valid'  => false,
                'code'   => 'MALFORMED_PAYLOAD',
                'reason' => 'Decoded lease payload is not valid JSON.'
            ];
        }

        // Required schema keys
        if (!isset($payload['status']) || !isset($payload['expires_at'])) {
            return [
                'valid'  => false,
                'code'   => 'INVALID_SCHEMA',
                'reason' => 'Payload missing mandatory status or expires_at keys.'
            ];
        }

        // 3. Status validation
        $status = strtolower(trim((string)$payload['status']));
        if ($status !== 'active') {
            return [
                'valid'   => false,
                'code'    => 'LICENSE_INACTIVE',
                'status'  => strtoupper($status),
                'reason'  => "Commercial license status is '{$status}'.",
                'payload' => $payload
            ];
        }

        // 4. Optional deployment and license binding checks
        if ($this->expectedDeploymentId !== null && isset($payload['deployment_id'])) {
            if ((int)$payload['deployment_id'] !== $this->expectedDeploymentId) {
                return [
                    'valid'  => false,
                    'code'   => 'DEPLOYMENT_MISMATCH',
                    'reason' => "Lease deployment ID ({$payload['deployment_id']}) does not match authorized node (#{$this->expectedDeploymentId})."
                ];
            }
        }

        if ($this->expectedLicenseId !== null && isset($payload['license_id'])) {
            if ((int)$payload['license_id'] !== $this->expectedLicenseId) {
                return [
                    'valid'  => false,
                    'code'   => 'LICENSE_MISMATCH',
                    'reason' => "Lease license ID ({$payload['license_id']}) does not match authorized license (#{$this->expectedLicenseId})."
                ];
            }
        }

        // 5. Expiration & Offline Grace Period Logic
        $now = time();
        $expiresAt = (int)$payload['expires_at'];
        $graceExpiresAt = $expiresAt + $this->gracePeriodSeconds;

        // Case A: Nominal Active Lease (now <= expiresAt)
        if ($now <= $expiresAt) {
            $secondsUntilExpiry = $expiresAt - $now;
            $needsRenewal = ($secondsUntilExpiry <= $this->renewalWindowSeconds);

            return [
                'valid'           => true,
                'status'          => 'ACTIVE',
                'in_grace_period' => false,
                'needs_renewal'   => $needsRenewal,
                'expires_at'      => $expiresAt,
                'seconds_left'    => $secondsUntilExpiry,
                'payload'         => $payload
            ];
        }

        // Case B: Offline Grace Period (expiresAt < now <= graceExpiresAt)
        if ($now <= $graceExpiresAt) {
            $secondsRemainingInGrace = $graceExpiresAt - $now;

            return [
                'valid'            => true,
                'status'           => 'GRACE_PERIOD',
                'in_grace_period'  => true,
                'needs_renewal'    => true,
                'expires_at'       => $expiresAt,
                'grace_expires_at' => $graceExpiresAt,
                'grace_seconds'    => $secondsRemainingInGrace,
                'payload'          => $payload
            ];
        }

        // Case C: Hard Expiration (now > graceExpiresAt)
        return [
            'valid'            => false,
            'status'           => 'EXPIRED',
            'code'             => 'LEASE_EXPIRED',
            'reason'           => 'Cryptographic lease has expired and offline grace period has lapsed.',
            'expires_at'       => $expiresAt,
            'grace_expires_at' => $graceExpiresAt,
            'payload'          => $payload
        ];
    }

    /**
     * Determines whether incoming route should bypass lease verification.
     */
    public function shouldBypass(string $uri, string $sapi): bool {
        // CLI executions (cron, migrations, tests, worker queues)
        if ($sapi === 'cli') {
            return true;
        }

        $cleanUri = parse_url($uri, PHP_URL_PATH) ?? $uri;

        // Activation wizard & setup endpoints
        if (str_contains($cleanUri, 'activation.php')) {
            return true;
        }

        // Static assets and public resources
        $staticPrefixes = [
            '/assets/',
            '/uploads/',
            '/public/css/',
            '/public/js/',
            '/public/images/',
            '/public/fonts/',
            '/favicon.ico',
            '/robots.txt',
            '/manifest.json'
        ];

        foreach ($staticPrefixes as $prefix) {
            if (str_starts_with($cleanUri, $prefix) || str_contains($cleanUri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determines enforcement action: JSON HTTP 403 for API/AJAX or 302 Redirect for browser.
     */
    public function determineEnforcementAction(array $failure, string $uri, array $headers = []): array {
        $isApi = false;

        $accept = strtolower((string)($headers['accept'] ?? ''));
        $xReq   = strtolower((string)($headers['x-requested-with'] ?? ''));

        if (str_contains($accept, 'application/json') || $xReq === 'xmlhttprequest') {
            $isApi = true;
        } elseif (str_starts_with($uri, '/api/') || str_contains($uri, '/api/v1/')) {
            $isApi = true;
        }

        $activationUrl = $this->getActivationUrl();

        if ($isApi) {
            return [
                'type'      => 'json_response',
                'http_code' => 403,
                'payload'   => [
                    'success'        => false,
                    'error'          => $failure['reason'] ?? 'Commercial license verification failure.',
                    'code'           => $failure['code'] ?? 'LEASE_INVALID',
                    'activation_url' => $activationUrl
                ]
            ];
        }

        $redirectUrl = $activationUrl . '?reason=' . urlencode(strtolower((string)($failure['code'] ?? 'lease_expired')));
        return [
            'type'       => 'redirect',
            'target_url' => $redirectUrl
        ];
    }

    /**
     * Non-blocking background lease renewal.
     * Debounces requests to prevent stampedes, and invokes renewal asynchronously
     * without delaying the current HTTP response.
     */
    public function triggerBackgroundRenewal(): bool {
        if (!$this->canAttemptRenewal()) {
            return false;
        }

        $this->markRenewalAttempt();

        // Execution strategy: FastCGI finished request hook or shutdown hook
        if (function_exists('fastcgi_finish_request')) {
            register_shutdown_function(function() {
                fastcgi_finish_request();
                $this->renewLease();
            });
            return true;
        }

        // Detached background invocation
        $renewScript = $this->appRoot . '/bin/renew-lease.php';
        if (file_exists($renewScript)) {
            if (DIRECTORY_SEPARATOR === '\\') {
                // Windows detached process
                @pclose(@popen("start /B php " . escapeshellarg($renewScript) . " > NUL 2>&1", "r"));
            } else {
                // Linux / Unix detached process
                @exec("php " . escapeshellarg($renewScript) . " > /dev/null 2>&1 &");
            }
            return true;
        }

        // Inline shutdown function fallback
        register_shutdown_function(function() {
            $this->renewLease();
        });
        return true;
    }

    /**
     * Synchronous lease renewal against SpectraOps Central Hub.
     */
    public function renewLease(): array {
        if ($this->hubUrl === '' || $this->licenseKey === '') {
            return ['renewed' => false, 'error' => 'Missing Hub URL or License Key configuration.'];
        }

        $ingestUrl = $this->hubUrl . '/api/v1/telemetry/ingest.php';
        $payload = [
            'metrics' => [
                'php_version' => PHP_VERSION,
                'os_info'     => php_uname('s') . ' ' . php_uname('r')
            ]
        ];

        $ch = curl_init($ingestUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->licenseKey,
                'X-API-KEY: ' . $this->licenseKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);

        $res = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $json = json_decode((string)$res, true);

        if ($httpCode === 200 && is_array($json) && !empty($json['success'])) {
            $newToken = (string)($json['structured_lease_token'] ?? ($json['lease_token'] ?? ''));
            $newSalt = (string)($json['lease_salt'] ?? '');

            if ($newToken !== '') {
                $this->updateLockToken($newToken, $this->licenseKey, $newSalt);
                return [
                    'renewed'     => true,
                    'lease_token' => $newToken,
                    'expires_at'  => $json['lease_expires'] ?? 0
                ];
            }
        }

        return [
            'renewed'   => false,
            'http_code' => $httpCode,
            'error'     => $json['error'] ?? 'Hub rejected lease renewal request.'
        ];
    }

    /**
     * Atomically writes new lease token to storage/license.lock.
     */
    public function updateLockToken(string $token, ?string $licenseKey = null, ?string $leaseSalt = null): bool {
        $lockFile = $this->getLockFilePath();
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $existing = [];
        if (file_exists($lockFile)) {
            $existing = json_decode((string)file_get_contents($lockFile), true) ?: [];
        }

        $existing['lease_token'] = $token;
        $existing['updated_at']  = gmdate('Y-m-d H:i:s');
        if ($licenseKey !== null) {
            $existing['license_key'] = $licenseKey;
        }
        if ($leaseSalt !== null && $leaseSalt !== '') {
            $existing['lease_salt'] = $leaseSalt;
            $this->leaseSalt = $leaseSalt;
        }

        $tempLock = $lockFile . '.tmp.' . uniqid();
        $jsonStr = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents($tempLock, $jsonStr) !== false) {
            return @rename($tempLock, $lockFile);
        }

        return false;
    }

    /**
     * Debounce check for lease renewal attempts (minimum 60s between triggers).
     */
    public function canAttemptRenewal(): bool {
        $stampFile = $this->appRoot . '/storage/lease_renewal.stamp';
        if (!file_exists($stampFile)) {
            return true;
        }

        $last = (int)@file_get_contents($stampFile);
        return (time() - $last) >= self::RENEWAL_DEBOUNCE_SECONDS;
    }

    public function markRenewalAttempt(): void {
        $dir = $this->appRoot . '/storage';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents($dir . '/lease_renewal.stamp', (string)time());
    }

    /**
     * Generates a signed lease token from payload and salt.
     */
    public static function generateToken(array $payload, string $salt): string {
        $jsonStr = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $base64UrlPayload = self::base64UrlEncode($jsonStr);
        $signature = hash_hmac('sha256', $base64UrlPayload, $salt);
        return "{$base64UrlPayload}.{$signature}";
    }

    /**
     * Decodes unverified payload portion of token.
     */
    public static function decodeTokenPayload(string $token): ?array {
        if (!str_contains($token, '.')) return null;
        [$p] = explode('.', $token, 2);
        $json = self::base64UrlDecode($p);
        return json_decode($json, true);
    }

    public static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return (string)base64_decode(strtr($data, '-_', '+/'));
    }

    private function getLockFilePath(): string {
        return $this->appRoot . '/storage/license.lock';
    }

    private function getActivationUrl(): string {
        $base = '';
        if (defined('BASE_URL')) {
            $base = BASE_URL;
        } elseif (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], '/school_system')) {
            $base = '/school_system';
        }
        return ($base ? rtrim($base, '/') : '') . '/activation.php';
    }

    private function getRequestHeaders(): array {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $normalized = [];
                foreach ($headers as $k => $v) {
                    $normalized[strtolower((string)$k)] = (string)$v;
                }
                return $normalized;
            }
        }

        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headerName = strtolower(str_replace('_', '-', substr($k, 5)));
                $headers[$headerName] = (string)$v;
            }
        }
        return $headers;
    }

    private function loadEnvironment(): void {
        $envFile = $this->appRoot . '/.env';
        if (!file_exists($envFile)) return;

        $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\n\r\0\x0B\"'");
                if (getenv($k) === false) {
                    putenv("{$k}={$v}");
                    $_ENV[$k] = $v;
                    $_SERVER[$k] = $v;
                }
            }
        }
    }
}
