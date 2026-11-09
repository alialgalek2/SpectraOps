<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use InvalidArgumentException;

class VendPulseLicenseService {
    public const PRODUCT_CODE = 'VP';
    public const PRODUCT_NAME = 'VENDPULSE';
    public const ISSUER = 'SpectraOps';
    public const ALGORITHM = 'SHA256withRSA';

    public const TIER_RETAIL_BASIC = 'RETAIL_BASIC';
    public const TIER_SUPERMARKET = 'SUPERMARKET';
    public const TIER_ENTERPRISE_WHOLESALE = 'ENTERPRISE_WHOLESALE';

    public const USAGE_MODE_RETAIL_SHOP = 'RETAIL_SHOP';
    public const USAGE_MODE_SUPERMARKET = 'SUPERMARKET';
    public const USAGE_MODE_RESTAURANT = 'RESTAURANT';

    private PDO $pdo;
    private ?string $privateKey = null;
    private ?string $publicKey = null;
    private string $keyDir;

    public function __construct(PDO $pdo, ?string $privateKey = null, ?string $publicKey = null) {
        $this->pdo = $pdo;
        $this->keyDir = dirname(__DIR__, 2) . '/keys';

        if (!is_dir($this->keyDir)) {
            @mkdir($this->keyDir, 0700, true);
        }

        $this->privateKey = $privateKey;
        $this->publicKey = $publicKey;

        $this->loadOrGenerateKeys();
    }

    /**
     * Get the available product operating tiers and their descriptions & modules.
     */
    public function getAvailableTiers(): array {
        return [
            self::TIER_RETAIL_BASIC => [
                'name' => 'Retail Shop (RETAIL_BASIC)',
                'badge' => 'Retail Basic',
                'description' => 'Standard counter checkout, touch-grid POS, basic inventory, receipt printing.',
                'modules' => $this->getDefaultModulesForTier(self::TIER_RETAIL_BASIC),
            ],
            self::TIER_SUPERMARKET => [
                'name' => 'Supermarket POS (SUPERMARKET)',
                'badge' => 'Supermarket',
                'description' => 'High-speed barcode stream scanning, digital weighing scale integration, multi-till cash drawer balances, cashier shifts.',
                'modules' => $this->getDefaultModulesForTier(self::TIER_SUPERMARKET),
            ],
            self::TIER_ENTERPRISE_WHOLESALE => [
                'name' => 'Wholesale & Distribution (ENTERPRISE_WHOLESALE)',
                'badge' => 'Wholesale / B2B',
                'description' => 'Multi-warehouse management, customer credit limits, B2B tax invoices, institutional document seals.',
                'modules' => $this->getDefaultModulesForTier(self::TIER_ENTERPRISE_WHOLESALE),
            ],
        ];
    }

    /**
     * Get the available system usage modes and their operational descriptions.
     */
    public function getAvailableUsageModes(): array {
        return [
            self::USAGE_MODE_RETAIL_SHOP => [
                'code' => self::USAGE_MODE_RETAIL_SHOP,
                'name' => 'Retail Shop (RETAIL_SHOP)',
                'badge' => 'Retail Shop',
                'icon' => '🛍️',
                'description' => 'Standard counter sales, barcode lookup, product variants (size, color, brand), standard receipt printing.',
            ],
            self::USAGE_MODE_SUPERMARKET => [
                'code' => self::USAGE_MODE_SUPERMARKET,
                'name' => 'Supermarket (SUPERMARKET)',
                'badge' => 'Supermarket',
                'icon' => '🛒',
                'description' => 'High-throughput barcode continuous stream scanning, digital weighing scale interface, multi-till cash drawers, cashier shift balancing.',
            ],
            self::USAGE_MODE_RESTAURANT => [
                'code' => self::USAGE_MODE_RESTAURANT,
                'name' => 'Restaurant & Hospitality (RESTAURANT)',
                'badge' => 'Restaurant',
                'icon' => '🍽️',
                'description' => 'Dining table floor plan management, kitchen order tickets (KOT), course management, split bills, and table reservations.',
            ],
        ];
    }

    /**
     * Map each operating tier to its standardized allowed modules array.
     */
    public function getDefaultModulesForTier(string $tier): array {
        return match (strtoupper(trim($tier))) {
            self::TIER_RETAIL_BASIC => [
                'catalog_grid',
                'standard_pos',
                'basic_receipt',
            ],
            self::TIER_SUPERMARKET => [
                'barcode_rapid_stream',
                'scale_comm',
                'multi_till',
                'cashier_shifts',
            ],
            self::TIER_ENTERPRISE_WHOLESALE => [
                'multi_warehouse',
                'b2b_invoices',
                'credit_accounts',
                'institutional_branding',
            ],
            default => [
                'catalog_grid',
                'standard_pos',
                'basic_receipt',
            ],
        };
    }

    /**
     * Generate unique license reference code: VP-LIC-2026-XXXXX
     */
    public function generateUniqueLicenseId(): string {
        $year = date('Y');
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $suffix = strtoupper(bin2hex(random_bytes(3))); // 6 hex chars e.g. A482F1
            $suffix5 = substr($suffix, 0, 5);
            $candidate = "VP-LIC-{$year}-{$suffix5}";

            $stmt = $this->pdo->prepare("SELECT 1 FROM central_vendpulse_licenses WHERE license_id = ? LIMIT 1");
            $stmt->execute([$candidate]);
            if (!$stmt->fetch()) {
                return $candidate;
            }
        }
        return "VP-LIC-{$year}-" . strtoupper(bin2hex(random_bytes(3)));
    }

    /**
     * Safely export the SpectraOps Public Key for packaging into VendPulse distributions.
     * Note: Private key material is never exposed or returned.
     */
    public function getPublicKey(): string {
        if (!$this->publicKey) {
            $this->loadOrGenerateKeys();
        }
        if (!$this->publicKey) {
            throw new RuntimeException("Cryptographic Public Key is unavailable.");
        }
        return trim($this->publicKey) . "\n";
    }

    /**
     * Export public key details for client distribution.
     */
    public function exportPublicKeyMetadata(): array {
        $pub = $this->getPublicKey();
        $details = openssl_pkey_get_details(openssl_pkey_get_public($pub));

        return [
            'algorithm' => self::ALGORITHM,
            'key_type' => 'RSA',
            'bits' => $details['bits'] ?? 2048,
            'public_key' => $pub,
            'public_key_fingerprint' => hash('sha256', $pub),
            'export_time' => time(),
        ];
    }

    /**
     * Construct canonical JSON payload as specified in requirement.
     */
    public function buildCanonicalPayload(array $params): array {
        $clientName = trim((string)($params['client_name'] ?? ''));
        if ($clientName === '') {
            throw new InvalidArgumentException("Client / Company Name is required.");
        }

        $tier = strtoupper(trim((string)($params['tier'] ?? self::TIER_RETAIL_BASIC)));
        if (!in_array($tier, [self::TIER_RETAIL_BASIC, self::TIER_SUPERMARKET, self::TIER_ENTERPRISE_WHOLESALE], true)) {
            $tier = self::TIER_RETAIL_BASIC;
        }

        $usageMode = strtoupper(trim((string)($params['usage_mode'] ?? self::USAGE_MODE_RETAIL_SHOP)));
        if (!in_array($usageMode, [self::USAGE_MODE_RETAIL_SHOP, self::USAGE_MODE_SUPERMARKET, self::USAGE_MODE_RESTAURANT], true)) {
            $usageMode = self::USAGE_MODE_RETAIL_SHOP;
        }

        $licenseId = trim((string)($params['license_id'] ?? ''));
        if ($licenseId === '') {
            $licenseId = $this->generateUniqueLicenseId();
        }

        $machineHwid = trim((string)($params['machine_hwid'] ?? ''));
        if ($machineHwid === '') {
            $machineHwid = null;
        }

        // Allowed modules
        $allowedModules = $params['allowed_modules'] ?? null;
        if (!is_array($allowedModules) || empty($allowedModules)) {
            $allowedModules = $this->getDefaultModulesForTier($tier);
        } else {
            $allowedModules = array_values(array_unique(array_filter(array_map('strval', $allowedModules))));
        }

        $maxCounters = max(1, (int)($params['max_counters'] ?? 1));

        $issuedAt = isset($params['issued_at']) && (int)$params['issued_at'] > 0
            ? (int)$params['issued_at']
            : time();

        $expiresAt = null;
        if (isset($params['expires_at']) && $params['expires_at'] !== null && $params['expires_at'] !== '') {
            if (is_numeric($params['expires_at'])) {
                $expiresAt = (int)$params['expires_at'] > 0 ? (int)$params['expires_at'] : null;
            } else {
                $ts = strtotime((string)$params['expires_at']);
                $expiresAt = ($ts !== false && $ts > 0) ? $ts : null;
            }
        }

        // Canonical order strictly maintained
        return [
            'issuer' => self::ISSUER,
            'product' => self::PRODUCT_NAME,
            'license_id' => $licenseId,
            'client_name' => $clientName,
            'machine_hwid' => $machineHwid,
            'tier' => $tier,
            'usage_mode' => $usageMode,
            'allowed_modules' => $allowedModules,
            'max_counters' => $maxCounters,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Deterministically encode canonical payload to JSON.
     */
    public function toCanonicalJson(array $payload): string {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Sign canonical payload with RSA private key (SHA-256).
     */
    public function signPayload(array $payload): string {
        if (!$this->privateKey) {
            $this->loadOrGenerateKeys();
        }
        if (!$this->privateKey) {
            throw new RuntimeException("Private key is not loaded or configured for license signing.");
        }

        $canonical = $this->toCanonicalJson($payload);
        $signatureBinary = '';
        $ok = openssl_sign($canonical, $signatureBinary, $this->privateKey, OPENSSL_ALGO_SHA256);

        if (!$ok) {
            $err = openssl_error_string() ?: 'unknown OpenSSL error';
            throw new RuntimeException("Cryptographic signature generation failed: {$err}");
        }

        return base64_encode($signatureBinary);
    }

    /**
     * Issues and persists an offline signed VendPulse license.
     */
    public function issueLicense(array $params): array {
        $payload = $this->buildCanonicalPayload($params);
        $signature = $this->signPayload($payload);
        $canonicalJson = $this->toCanonicalJson($payload);
        $bundleHash = hash('sha256', $canonicalJson);

        $bundle = [
            'license_bundle_version' => '1.0',
            'product' => self::PRODUCT_NAME,
            'algorithm' => self::ALGORITHM,
            'bundle_hash' => $bundleHash,
            'payload' => $payload,
            'signature' => $signature,
        ];

        $bundleJson = json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // Persist to central_vendpulse_licenses
        $stmt = $this->pdo->prepare("
            INSERT INTO central_vendpulse_licenses
            (license_id, client_name, machine_hwid, tier, usage_mode, allowed_modules, max_counters, issued_at, expires_at, signature, license_bundle, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            $payload['license_id'],
            $payload['client_name'],
            $payload['machine_hwid'],
            $payload['tier'],
            $payload['usage_mode'],
            json_encode($payload['allowed_modules'], JSON_UNESCAPED_SLASHES),
            $payload['max_counters'],
            $payload['issued_at'],
            $payload['expires_at'],
            $signature,
            $bundleJson,
        ]);

        $dbId = (int)$this->pdo->lastInsertId();

        return [
            'id' => $dbId,
            'license_id' => $payload['license_id'],
            'client_name' => $payload['client_name'],
            'machine_hwid' => $payload['machine_hwid'],
            'tier' => $payload['tier'],
            'usage_mode' => $payload['usage_mode'],
            'allowed_modules' => $payload['allowed_modules'],
            'max_counters' => $payload['max_counters'],
            'issued_at' => $payload['issued_at'],
            'expires_at' => $payload['expires_at'],
            'payload' => $payload,
            'signature' => $signature,
            'license_bundle' => $bundleJson,
            'license_bundle_b64' => base64_encode($bundleJson),
            'filename' => "vendpulse_license_{$payload['license_id']}.vpl",
        ];
    }

    /**
     * Verifies an offline license bundle against the public key.
     *
     * @param string|array $bundleInput Raw JSON string, base64 bundle string, or parsed array
     * @param string|null $expectedHwid Optional machine HWID to check device lock
     */
    public function verifyLicenseBundle(string|array $bundleInput, ?string $expectedHwid = null): array {
        try {
            $bundle = $bundleInput;
            if (is_string($bundleInput)) {
                $trimmed = trim($bundleInput);
                // Handle base64 encoded bundle
                if (!str_starts_with($trimmed, '{')) {
                    $decodedB64 = base64_decode($trimmed, true);
                    if ($decodedB64 !== false && str_starts_with(trim($decodedB64), '{')) {
                        $trimmed = trim($decodedB64);
                    }
                }
                $bundle = json_decode($trimmed, true);
            }

            if (!is_array($bundle)) {
                return [
                    'valid' => false,
                    'status' => 'invalid_format',
                    'error' => 'License bundle could not be parsed as valid JSON.',
                ];
            }

            $payload = $bundle['payload'] ?? null;
            $signatureB64 = $bundle['signature'] ?? null;

            if (!is_array($payload) || empty($signatureB64) || !is_string($signatureB64)) {
                return [
                    'valid' => false,
                    'status' => 'invalid_format',
                    'error' => 'License bundle missing payload or signature field.',
                ];
            }

            // Normalization
            $canonical = $this->toCanonicalJson($payload);
            $sigBinary = base64_decode($signatureB64, true);
            if ($sigBinary === false) {
                return [
                    'valid' => false,
                    'status' => 'invalid_signature_encoding',
                    'error' => 'License signature is not valid base64.',
                ];
            }

            $pubKey = $this->getPublicKey();
            $verifyResult = openssl_verify($canonical, $sigBinary, $pubKey, OPENSSL_ALGO_SHA256);

            if ($verifyResult !== 1) {
                return [
                    'valid' => false,
                    'status' => 'tampered',
                    'error' => 'Cryptographic signature is invalid or payload has been tampered with.',
                    'payload' => $payload,
                ];
            }

            // Expiration check
            $expiresAt = $payload['expires_at'] ?? null;
            if ($expiresAt !== null && is_numeric($expiresAt) && (int)$expiresAt > 0) {
                if ((int)$expiresAt < time()) {
                    return [
                        'valid' => false,
                        'status' => 'expired',
                        'error' => 'License expired on ' . date('Y-m-d H:i:s T', (int)$expiresAt) . '.',
                        'payload' => $payload,
                    ];
                }
            }

            // Hardware Fingerprint Lock check
            $licenseHwid = $payload['machine_hwid'] ?? null;
            if ($expectedHwid !== null && trim($expectedHwid) !== '' && !empty($licenseHwid)) {
                if (strcasecmp(trim($licenseHwid), trim($expectedHwid)) !== 0) {
                    return [
                        'valid' => false,
                        'status' => 'hwid_mismatch',
                        'error' => "Hardware fingerprint mismatch: License is bound to '{$licenseHwid}', but detected device is '{$expectedHwid}'.",
                        'payload' => $payload,
                    ];
                }
            }

            return [
                'valid' => true,
                'status' => 'valid',
                'message' => 'License is cryptographically authentic and active.',
                'usage_mode' => $payload['usage_mode'] ?? self::USAGE_MODE_RETAIL_SHOP,
                'payload' => $payload,
                'bundle' => $bundle,
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'status' => 'error',
                'error' => 'Verification exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * List all VendPulse offline licenses from database.
     */
    public function listLicenses(?string $search = null, ?string $tier = null, ?string $status = null, ?string $usageMode = null): array {
        $sql = "SELECT * FROM central_vendpulse_licenses WHERE 1=1";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $term = '%' . trim($search) . '%';
            $sql .= " AND (license_id LIKE ? OR client_name LIKE ? OR machine_hwid LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }

        if ($tier !== null && trim($tier) !== '' && $tier !== 'all') {
            $sql .= " AND tier = ?";
            $params[] = strtoupper(trim($tier));
        }

        if ($usageMode !== null && trim($usageMode) !== '' && $usageMode !== 'all') {
            $sql .= " AND usage_mode = ?";
            $params[] = strtoupper(trim($usageMode));
        }

        if ($status !== null && trim($status) !== '' && $status !== 'all') {
            $sql .= " AND status = ?";
            $params[] = strtolower(trim($status));
        }

        $sql .= " ORDER BY id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            if (!empty($r['allowed_modules'])) {
                $decoded = json_decode((string)$r['allowed_modules'], true);
                $r['modules_list'] = is_array($decoded) ? $decoded : [];
            } else {
                $r['modules_list'] = [];
            }
            $r['is_expired'] = !empty($r['expires_at']) && (int)$r['expires_at'] < time();
        }

        return $rows;
    }

    /**
     * Retrieve a license by database ID.
     */
    public function getLicenseById(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM central_vendpulse_licenses WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['modules_list'] = json_decode((string)$row['allowed_modules'], true) ?: [];
        $row['is_expired'] = !empty($row['expires_at']) && (int)$row['expires_at'] < time();
        return $row;
    }

    /**
     * Retrieve a license by reference ID string (e.g. VP-LIC-2026-XXXXX).
     */
    public function getLicenseByKey(string $licenseId): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM central_vendpulse_licenses WHERE license_id = ? LIMIT 1");
        $stmt->execute([trim($licenseId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['modules_list'] = json_decode((string)$row['allowed_modules'], true) ?: [];
        $row['is_expired'] = !empty($row['expires_at']) && (int)$row['expires_at'] < time();
        return $row;
    }

    /**
     * Alias for getLicenseByKey.
     */
    public function getLicenseByCode(string $licenseId): ?array {
        return $this->getLicenseByKey($licenseId);
    }

    /**
     * Revoke a license.
     */
    public function revokeLicense(int|string $idOrKey, ?string $reason = null): bool {
        $col = is_numeric($idOrKey) ? 'id' : 'license_id';
        $stmt = $this->pdo->prepare("UPDATE central_vendpulse_licenses SET status = 'revoked' WHERE {$col} = ?");
        return $stmt->execute([$idOrKey]);
    }

    /**
     * Reactivate a revoked or suspended license.
     */
    public function reactivateLicense(int|string $idOrKey): bool {
        $col = is_numeric($idOrKey) ? 'id' : 'license_id';
        $stmt = $this->pdo->prepare("UPDATE central_vendpulse_licenses SET status = 'active' WHERE {$col} = ?");
        return $stmt->execute([$idOrKey]);
    }

    /**
     * Permanently delete a license record.
     */
    public function deleteLicense(int $id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM central_vendpulse_licenses WHERE id = ?");
        return $stmt->execute([$id]);
    }

    /**
     * Aggregated statistics for the console overview.
     */
    public function getStatistics(): array {
        $rows = $this->pdo->query("SELECT tier, usage_mode, status, expires_at FROM central_vendpulse_licenses")->fetchAll(PDO::FETCH_ASSOC);
        $total = count($rows);
        $active = 0;
        $revoked = 0;
        $expired = 0;
        $perpetual = 0;
        $tiers = [
            self::TIER_RETAIL_BASIC => 0,
            self::TIER_SUPERMARKET => 0,
            self::TIER_ENTERPRISE_WHOLESALE => 0,
        ];
        $usageModes = [
            self::USAGE_MODE_RETAIL_SHOP => 0,
            self::USAGE_MODE_SUPERMARKET => 0,
            self::USAGE_MODE_RESTAURANT => 0,
        ];

        $now = time();
        foreach ($rows as $r) {
            $t = $r['tier'] ?? '';
            if (isset($tiers[$t])) {
                $tiers[$t]++;
            }
            $um = $r['usage_mode'] ?? self::USAGE_MODE_RETAIL_SHOP;
            if (isset($usageModes[$um])) {
                $usageModes[$um]++;
            }
            if (($r['status'] ?? '') === 'revoked') {
                $revoked++;
            } elseif (!empty($r['expires_at']) && (int)$r['expires_at'] < $now) {
                $expired++;
            } else {
                $active++;
            }

            if (empty($r['expires_at'])) {
                $perpetual++;
            }
        }

        return [
            'total' => $total,
            'active' => $active,
            'revoked' => $revoked,
            'expired' => $expired,
            'perpetual' => $perpetual,
            'tiers' => $tiers,
            'usage_modes' => $usageModes,
        ];
    }

    /**
     * Load or automatically generate cryptographic keypair for offline signing.
     */
    private function loadOrGenerateKeys(): void {
        // 1. Env vars take top priority
        $envPriv = getenv('VENDPULSE_PRIVATE_KEY') ?: getenv('SPECTRAOPS_SIGNING_KEY');
        $envPub = getenv('VENDPULSE_PUBLIC_KEY') ?: getenv('SPECTRAOPS_PUBLIC_KEY');
        if ($envPriv && $envPub) {
            $this->privateKey = $envPriv;
            $this->publicKey = $envPub;
            return;
        }

        // 2. Check dedicated vendpulse key files
        $privPath = $this->keyDir . '/vendpulse_private.pem';
        $pubPath = $this->keyDir . '/vendpulse_public.pem';

        if (file_exists($privPath) && file_exists($pubPath)) {
            $this->privateKey = (string)file_get_contents($privPath);
            $this->publicKey = (string)file_get_contents($pubPath);
            return;
        }

        // 3. Check existing release keys as fallback
        $relPriv = $this->keyDir . '/release_private.pem';
        $relPub = $this->keyDir . '/release_public.pem';
        if (file_exists($relPriv) && file_exists($relPub)) {
            $this->privateKey = (string)file_get_contents($relPriv);
            $this->publicKey = (string)file_get_contents($relPub);

            // Also copy to vendpulse keys for clean decoupling
            @file_put_contents($privPath, $this->privateKey);
            @file_put_contents($pubPath, $this->publicKey);
            return;
        }

        // 4. Generate a new RSA 2048-bit keypair
        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($res) {
            openssl_pkey_export($res, $privOut);
            $pubDetails = openssl_pkey_get_details($res);
            $pubOut = $pubDetails['key'] ?? null;

            if ($privOut && $pubOut) {
                $this->privateKey = $privOut;
                $this->publicKey = $pubOut;

                @file_put_contents($privPath, $privOut);
                @file_put_contents($pubPath, $pubOut);
                @chmod($privPath, 0600);
            }
        }
    }
}

// Namespace alias for compatibility across App\Services and FleetHub\Services
if (!class_exists(\FleetHub\Services\VendPulseLicenseService::class, false)) {
    class_alias(VendPulseLicenseService::class, \FleetHub\Services\VendPulseLicenseService::class);
}
