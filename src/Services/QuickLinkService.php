<?php
declare(strict_types=1);

namespace FleetHub\Services;

use FleetHub\FleetService;
use InvalidArgumentException;
use PDO;
use RuntimeException;

require_once dirname(__DIR__) . '/FleetService.php';

/**
 * Unified "One-Click Deployment Link & Connection Bundle" service.
 * Atomically provisions a bound deployment + active license and generates
 * the downloadable spectraops.json connection bundle.
 */
class QuickLinkService {
    private PDO $pdo;

    private const ALLOWED_ENVIRONMENTS = ['demo', 'staging', 'production', 'development'];
    private const ALLOWED_LICENSE_TIERS = ['perpetual', 'subscription', 'trial'];

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Normalize a deployment domain URL:
     * - Trims whitespace and strips trailing slashes
     * - Preserves http:// or https:// (lowercased); defaults to https:// if omitted
     * - Lowercases scheme and host
     * - Validates via FleetService::isValidPublicDomain()
     */
    public static function normalizeDomain(string $domain): string {
        $trimmed = rtrim(trim($domain), '/');
        if ($trimmed === '') {
            throw new InvalidArgumentException('Deployment domain is required.');
        }

        if (!preg_match('#^https?://#i', $trimmed)) {
            $trimmed = 'https://' . $trimmed;
        }

        $parts = parse_url($trimmed);
        if ($parts === false || empty($parts['host'])) {
            throw new InvalidArgumentException("Invalid deployment domain '{$domain}'.");
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = isset($parts['path']) ? rtrim($parts['path'], '/') : '';

        $normalized = "{$scheme}://{$host}{$port}{$path}";

        if (!FleetService::isValidPublicDomain($normalized)) {
            throw new InvalidArgumentException(
                "Invalid deployment domain '{$domain}'. Must be a valid public hostname (raw IPs, private networks, and localhost are rejected)."
            );
        }

        return $normalized;
    }

    /**
     * Atomically create a new deployment (status: pending_handshake) and bind
     * an active license record in a single database transaction.
     *
     * @param array{
     *   product_slug?: string,
     *   product_id?: int,
     *   client_name: string,
     *   client_email?: string,
     *   domain: string,
     *   environment?: string,
     *   license_tier?: string,
     *   expiry_days?: int|null,
     *   max_seats?: int
     * } $params
     */
    public function linkDeployment(array $params): array {
        $productSlug = trim((string)($params['product_slug'] ?? ''));
        $productId = (int)($params['product_id'] ?? 0);
        $clientName = trim((string)($params['client_name'] ?? ''));
        $rawDomain = (string)($params['domain'] ?? ($params['deployment_domain'] ?? ''));
        $environment = strtolower(trim((string)($params['environment'] ?? 'production')));
        $licenseTier = strtolower(trim((string)($params['license_tier'] ?? 'perpetual')));
        $expiryDays = isset($params['expiry_days']) && $params['expiry_days'] !== '' && $params['expiry_days'] !== null
            ? (int)$params['expiry_days']
            : null;
        $maxSeats = max(0, (int)($params['max_seats'] ?? 0));

        if ($clientName === '') {
            throw new InvalidArgumentException('Client name is required.');
        }

        if (!in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            throw new InvalidArgumentException(
                "Invalid environment '{$environment}'. Allowed: " . implode(', ', self::ALLOWED_ENVIRONMENTS)
            );
        }

        if (!in_array($licenseTier, self::ALLOWED_LICENSE_TIERS, true)) {
            $licenseTier = 'perpetual';
        }

        $normalizedDomain = self::normalizeDomain($rawDomain);
        $host = strtolower((string)(parse_url($normalizedDomain, PHP_URL_HOST) ?: 'client.local'));

        $clientEmail = trim((string)($params['client_email'] ?? ''));
        if ($clientEmail === '') {
            $clientEmail = "ops@{$host}";
        }

        // Resolve product by slug or ID
        if ($productSlug !== '') {
            $stmt = $this->pdo->prepare("SELECT id, product_slug, product_code, product_name, latest_version FROM central_products WHERE product_slug = ? LIMIT 1");
            $stmt->execute([$productSlug]);
        } elseif ($productId > 0) {
            $stmt = $this->pdo->prepare("SELECT id, product_slug, product_code, product_name, latest_version FROM central_products WHERE id = ? LIMIT 1");
            $stmt->execute([$productId]);
        } else {
            throw new InvalidArgumentException('Either product_slug or product_id is required.');
        }

        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            throw new RuntimeException('Requested product does not exist in central_products.');
        }

        $resolvedProductId = (int)$product['id'];
        $resolvedSlug = (string)$product['product_slug'];
        $productCode = strtoupper(trim((string)($product['product_code'] ?? '')));
        if ($productCode === '') {
            $productCode = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $resolvedSlug), 0, 4)) ?: 'SPEC';
        }
        $latestVersion = trim((string)($product['latest_version'] ?? '1.0.0')) ?: '1.0.0';

        // Compute expiry timestamp if applicable
        if ($expiryDays !== null && $expiryDays > 0) {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + ($expiryDays * 86400));
            if ($licenseTier === 'perpetual') {
                $licenseTier = 'subscription';
            }
        } elseif ($licenseTier === 'trial') {
            $expiresAt = gmdate('Y-m-d H:i:s', time() + (30 * 86400));
        } else {
            $expiresAt = null;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "UTC_TIMESTAMP()";

        // Generate credentials prior to DB writes
        $licenseKey = sprintf(
            'SPEC-%s-%s-%s',
            $productCode,
            strtoupper(bin2hex(random_bytes(2))),
            strtoupper(bin2hex(random_bytes(2)))
        );
        $leaseSalt = bin2hex(random_bytes(16));
        $deploymentKey = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $deploymentKey);
        $tokenPreview = substr($deploymentKey, 0, 8) . '...' . substr($deploymentKey, -4);
        $bundleTokenEnc = self::encryptBundleToken($deploymentKey);

        $startedTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            // 1. Insert bound active license
            $licStmt = $this->pdo->prepare("
                INSERT INTO central_licenses
                (product_id, license_key, client_name, client_email, license_type, max_seats, expires_at, status, lease_salt, issued_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, {$nowFunc}, {$nowFunc})
            ");
            $licStmt->execute([
                $resolvedProductId,
                $licenseKey,
                $clientName,
                $clientEmail,
                $licenseTier,
                $maxSeats,
                $expiresAt,
                $leaseSalt,
            ]);
            $licenseId = (int)$this->pdo->lastInsertId();

            // 2. Insert deployment in pending_handshake state
            $depStmt = $this->pdo->prepare("
                INSERT INTO central_deployments
                (product_id, license_id, client_name, deployment_domain, environment, api_ingest_token_hash, token_preview, bundle_token_enc, current_version, status, status_reason, is_active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_handshake', 'Awaiting initial handshake via spectraops.json', 1, {$nowFunc}, {$nowFunc})
            ");
            $depStmt->execute([
                $resolvedProductId,
                $licenseId,
                $clientName,
                $normalizedDomain,
                $environment,
                $tokenHash,
                $tokenPreview,
                $bundleTokenEnc,
                $latestVersion,
            ]);
            $deploymentId = (int)$this->pdo->lastInsertId();

            if ($startedTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $licenseToken = self::computeLicenseToken($licenseKey, $normalizedDomain, $productCode, $expiresAt);
        $bundle = self::formatBundle(
            $resolvedSlug,
            $deploymentId,
            $deploymentKey,
            $licenseToken,
            $environment
        );

        return [
            'deployment_id'   => $deploymentId,
            'license_id'      => $licenseId,
            'product_id'      => $resolvedProductId,
            'product_slug'    => $resolvedSlug,
            'product_name'    => (string)$product['product_name'],
            'client_name'     => $clientName,
            'client_email'    => $clientEmail,
            'domain'          => $normalizedDomain,
            'environment'     => $environment,
            'status'          => 'pending_handshake',
            'deployment_key'  => $deploymentKey,
            'token_preview'   => $tokenPreview,
            'license_key'     => $licenseKey,
            'license_token'   => $licenseToken,
            'license_tier'    => $licenseTier,
            'expires_at'      => $expiresAt,
            'bundle'          => $bundle,
        ];
    }

    /**
     * Retrieve (or rotate if missing) the spectraops.json connection bundle for a deployment.
     */
    public function buildConnectionBundle(int $deploymentId): array {
        $stmt = $this->pdo->prepare("
            SELECT d.id, d.product_id, d.license_id, d.client_name, d.deployment_domain,
                   d.environment, d.bundle_token_enc, d.status,
                   p.product_slug, p.product_code,
                   l.license_key, l.expires_at
            FROM central_deployments d
            JOIN central_products p ON d.product_id = p.id
            LEFT JOIN central_licenses l ON d.license_id = l.id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$deploymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException("Deployment #{$deploymentId} not found.");
        }

        $deploymentKey = null;
        if (!empty($row['bundle_token_enc'])) {
            $deploymentKey = self::decryptBundleToken((string)$row['bundle_token_enc']);
        }

        // If no encrypted bundle key exists (e.g. legacy deployment), rotate and store a fresh key
        if ($deploymentKey === null || $deploymentKey === '') {
            $deploymentKey = bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $deploymentKey);
            $tokenPreview = substr($deploymentKey, 0, 8) . '...' . substr($deploymentKey, -4);
            $bundleTokenEnc = self::encryptBundleToken($deploymentKey);

            $upd = $this->pdo->prepare("
                UPDATE central_deployments
                SET api_ingest_token_hash = ?, token_preview = ?, bundle_token_enc = ?
                WHERE id = ?
            ");
            $upd->execute([$tokenHash, $tokenPreview, $bundleTokenEnc, $deploymentId]);
        }

        $productCode = strtoupper(trim((string)($row['product_code'] ?? 'SPEC')));
        $licenseKey = (string)($row['license_key'] ?? ("DEP-{$deploymentId}"));
        $expiresAt = $row['expires_at'] ?? null;
        $licenseToken = self::computeLicenseToken(
            $licenseKey,
            (string)$row['deployment_domain'],
            $productCode,
            $expiresAt !== null ? (string)$expiresAt : null
        );

        return self::formatBundle(
            (string)$row['product_slug'],
            (int)$row['id'],
            $deploymentKey,
            $licenseToken,
            (string)($row['environment'] ?: 'production')
        );
    }

    public static function formatBundle(
        string $productSlug,
        int $deploymentId,
        string $deploymentKey,
        string $licenseToken,
        string $environment,
        int $heartbeatIntervalSec = 300
    ): array {
        $hubUrl = rtrim((string)(getenv('SPECTRAOPS_HUB_URL') ?: 'https://spectraops.algaletech.com'), '/');

        return [
            'hub_url'                => $hubUrl,
            'api_base'               => $hubUrl . '/api/v1',
            'product_slug'           => $productSlug,
            'deployment_id'          => (string)$deploymentId,
            'deployment_key'         => $deploymentKey,
            'license_token'          => $licenseToken,
            'environment'            => $environment,
            'heartbeat_interval_sec' => $heartbeatIntervalSec,
        ];
    }

    public static function computeLicenseToken(
        string $licenseKey,
        string $domain,
        string $productCode,
        ?string $expiresAt
    ): string {
        $cryptSecret = getenv('SPECTRAOPS_CRYPT_SECRET_KEY') ?: 'SPECTRAOPS_CRYPT_SECRET_KEY';
        $rawPayload = "{$licenseKey}|{$domain}|{$productCode}|" . ($expiresAt ?? 'PERPETUAL');
        return hash_hmac('sha256', $rawPayload, $cryptSecret);
    }

    private static function getBundleEncryptionKey(): string {
        $secret = getenv('SPECTRAOPS_CRYPT_SECRET_KEY')
            ?: (getenv('APP_KEY') ?: 'SPECTRAOPS_CRYPT_SECRET_KEY');
        return hash('sha256', $secret, true);
    }

    private static function encryptBundleToken(string $plain): string {
        if (!function_exists('openssl_encrypt')) {
            return 'b64:' . base64_encode($plain);
        }
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'aes-256-cbc', self::getBundleEncryptionKey(), OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Failed to encrypt deployment bundle token.');
        }
        return 'v1:' . base64_encode($iv . $cipher);
    }

    private static function decryptBundleToken(string $encoded): ?string {
        if (str_starts_with($encoded, 'b64:')) {
            $decoded = base64_decode(substr($encoded, 4), true);
            return $decoded !== false ? $decoded : null;
        }
        if (!str_starts_with($encoded, 'v1:') || !function_exists('openssl_decrypt')) {
            return null;
        }
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false || strlen($raw) <= 16) {
            return null;
        }
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::getBundleEncryptionKey(), OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : null;
    }

    public static function getMasterReleaseDir(string $productSlug): string {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', strtolower(trim($productSlug)));
        if ($safe === '') {
            throw new InvalidArgumentException('Invalid product slug.');
        }
        return dirname(__DIR__, 2) . '/storage/releases/' . $safe;
    }

    public static function getMasterReleaseZipPath(string $productSlug): ?string {
        $path = self::getMasterReleaseDir($productSlug) . '/master.zip';
        return is_file($path) ? $path : null;
    }

    public static function saveMasterReleaseZip(string $productSlug, string $uploadedTmpPath): string {
        if (!is_file($uploadedTmpPath)) {
            throw new RuntimeException('Uploaded ZIP file not found.');
        }
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($uploadedTmpPath) !== true) {
            throw new RuntimeException('Invalid ZIP archive uploaded.');
        }
        $zip->close();

        $dir = self::getMasterReleaseDir($productSlug);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $dest = $dir . '/master.zip';
        if (!@move_uploaded_file($uploadedTmpPath, $dest)) {
            if (!@copy($uploadedTmpPath, $dest)) {
                throw new RuntimeException('Failed to store master release ZIP.');
            }
        }
        return $dest;
    }

    /**
     * Build a client-ready Full App Bundle (.zip) by copying the product's master.zip
     * and injecting the deployment's spectraops.json and canonical client-sdk files.
     *
     * @return array{path:string,filename:string}
     */
    public function buildFullAppBundleZip(int $deploymentId): array {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive extension is required.');
        }

        $stmt = $this->pdo->prepare("
            SELECT d.id, d.client_name, p.product_slug
            FROM central_deployments d
            JOIN central_products p ON d.product_id = p.id
            WHERE d.id = ?
            LIMIT 1
        ");
        $stmt->execute([$deploymentId]);
        $dep = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dep) {
            throw new RuntimeException("Deployment #{$deploymentId} not found.");
        }

        $slug = (string)$dep['product_slug'];
        $masterPath = self::getMasterReleaseZipPath($slug);
        if ($masterPath === null) {
            throw new RuntimeException("No master release ZIP uploaded yet for product '{$slug}'. Upload one on the Products page first.");
        }

        $bundle = $this->buildConnectionBundle($deploymentId);
        $bundleJson = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $tmpZip = tempnam(sys_get_temp_dir(), 'so_full_');
        if ($tmpZip === false || !@copy($masterPath, $tmpZip)) {
            throw new RuntimeException('Failed to create temporary bundle archive.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            throw new RuntimeException('Failed to open master release ZIP.');
        }

        // Detect if all entries share a single top-level directory (e.g. "prismedu/")
        $prefix = '';
        $topDirs = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($i));
            $parts = explode('/', ltrim($name, '/'));
            if (!empty($parts[0])) {
                $topDirs[$parts[0]] = true;
            }
            if (count($topDirs) > 1) {
                break;
            }
        }
        if (count($topDirs) === 1) {
            $only = array_key_first($topDirs);
            if ($only !== 'client-sdk' && $only !== 'spectraops.json') {
                // Only use prefix if the single top entry is actually a directory
                $hasSlash = false;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (str_starts_with(str_replace('\\', '/', (string)$zip->getNameIndex($i)), $only . '/')) {
                        $hasSlash = true;
                        break;
                    }
                }
                if ($hasSlash) {
                    $prefix = $only . '/';
                }
            }
        }

        $zip->addFromString($prefix . 'spectraops.json', $bundleJson);

        $sdkDir = dirname(__DIR__, 2) . '/client-sdk';
        foreach (['SpectraTelemetryGateway.php', 'SpectraSupportTicket.php'] as $sdkFile) {
            $fullSdk = $sdkDir . '/' . $sdkFile;
            if (is_file($fullSdk)) {
                $zip->addFile($fullSdk, $prefix . 'client-sdk/' . $sdkFile);
            }
        }

        $zip->close();

        $safeClient = preg_replace('/[^a-zA-Z0-9._-]+/', '-', strtolower((string)$dep['client_name']));
        $safeClient = trim((string)$safeClient, '-') ?: ('deployment-' . $deploymentId);
        $filename = "{$slug}-{$safeClient}-bundle.zip";

        return [
            'path'     => $tmpZip,
            'filename' => $filename,
        ];
    }
}
