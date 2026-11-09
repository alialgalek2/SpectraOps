<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;
use InvalidArgumentException;

require_once __DIR__ . '/LicenseManager.php';

class OrderProvisioningService {
    public const SUPPORTED_PRODUCTS = [
        'VENDPULSE', 'VP',
        'PRISM', 'PRISM-EDU', 'PRISM_EDU',
        'POS', 'POS-PRO', 'POS_PRO',
        'MED', 'CLINIC-CORE', 'CLINIC_CORE'
    ];

    public const VALID_USAGE_MODES = [
        VendPulseLicenseService::USAGE_MODE_RETAIL_SHOP,
        VendPulseLicenseService::USAGE_MODE_SUPERMARKET,
        VendPulseLicenseService::USAGE_MODE_RESTAURANT,
    ];

    private PDO $pdo;
    private VendPulseLicenseService $licenseService;
    private InstallerDistributionService $installerService;
    private ?string $configuredSecret = null;
    private string $publicUrl;

    public function __construct(
        PDO $pdo,
        ?VendPulseLicenseService $licenseService = null,
        ?InstallerDistributionService $installerService = null,
        ?string $configuredSecret = null,
        ?string $publicUrl = null
    ) {
        $this->pdo = $pdo;
        $this->licenseService = $licenseService ?? new VendPulseLicenseService($pdo);
        $this->installerService = $installerService ?? new InstallerDistributionService();
        $this->configuredSecret = $configuredSecret;

        $envPublic = getenv('SPECTRAOPS_PUBLIC_URL') ?: ($_ENV['SPECTRAOPS_PUBLIC_URL'] ?? '');
        $this->publicUrl = $publicUrl ?? ($envPublic !== '' ? rtrim($envPublic, '/') : 'http://127.0.0.1:8080/SPECTRAOPS/public');
    }

    /**
     * Get the configured public URL.
     */
    public function getPublicUrl(): string {
        return $this->publicUrl;
    }

    /**
     * Resolve product descriptor (slug, code, or name) to catalog item or offline POS.
     *
     * @param string $rawProduct Slug, code, or name of purchased product
     * @return array|null Product metadata with 'system_type' ('offline' | 'cloud')
     */
    public function resolveProduct(string $rawProduct): ?array {
        $clean = strtoupper(trim($rawProduct));
        if ($clean === '') {
            $clean = 'VENDPULSE';
        }

        if (in_array($clean, ['VENDPULSE', 'VP'], true)) {
            return [
                'system_type' => 'offline',
                'product_id' => null,
                'product_slug' => 'vendpulse',
                'product_code' => 'VP',
                'product_name' => 'VendPulse POS Engine',
                'latest_version' => '1.0.0',
            ];
        }

        // Search central_products catalog by product_code, product_slug, or stripped slug
        $stmt = $this->pdo->prepare("
            SELECT id, product_slug, product_code, product_name, latest_version
            FROM central_products
            WHERE UPPER(product_code) = ? 
               OR UPPER(product_slug) = ? 
               OR UPPER(REPLACE(product_slug, '-', '')) = ?
               OR UPPER(REPLACE(product_slug, '_', '')) = ?
            LIMIT 1
        ");
        $noHyphen = str_replace(['-', '_'], '', $clean);
        $stmt->execute([$clean, $clean, $noHyphen, $noHyphen]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($prod) {
            $slug = strtolower((string)$prod['product_slug']);
            $isOffline = ($slug === 'vendpulse' || strtoupper((string)$prod['product_code']) === 'VP');
            return [
                'system_type' => $isOffline ? 'offline' : 'cloud',
                'product_id' => (int)$prod['id'],
                'product_slug' => (string)$prod['product_slug'],
                'product_code' => strtoupper((string)$prod['product_code']),
                'product_name' => (string)$prod['product_name'],
                'latest_version' => (string)($prod['latest_version'] ?? '1.0.0'),
            ];
        }

        return null;
    }

    /**
     * Constant-time comparison for webhook secret key authentication.
     */
    public function verifyWebhookSecret(?string $incomingSecret): bool {
        if ($incomingSecret === null || $incomingSecret === '') {
            return false;
        }

        $expected = $this->configuredSecret 
            ?: (getenv('SPECTRAOPS_WEBHOOK_SECRET') ?: ($_ENV['SPECTRAOPS_WEBHOOK_SECRET'] ?? 'spec_whsec_test_secret_2026_pulse'));

        return hash_equals($expected, $incomingSecret);
    }

    /**
     * Validate incoming purchase order payload schema.
     */
    public function validateOrderPayload(array $payload): array {
        $orderId = trim((string)($payload['order_id'] ?? ''));
        if ($orderId === '') {
            throw new InvalidArgumentException("Missing or empty required field: order_id");
        }

        $customerName = trim((string)($payload['customer_name'] ?? ''));
        if ($customerName === '') {
            throw new InvalidArgumentException("Missing or empty required field: customer_name");
        }

        $customerEmail = trim((string)($payload['customer_email'] ?? ''));
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("Invalid or missing customer_email format: '{$customerEmail}'");
        }

        $businessName = trim((string)($payload['business_name'] ?? ''));
        if ($businessName === '') {
            $businessName = $customerName;
        }

        $rawProduct = trim((string)($payload['purchased_product'] ?? 'VENDPULSE'));
        $product = $this->resolveProduct($rawProduct);
        if (!$product) {
            throw new InvalidArgumentException("Unsupported product '{$rawProduct}'. Supported products: VENDPULSE (VP), PRISM (PRISM-EDU), POS (POS-PRO), MED (CLINIC-CORE)");
        }

        $isCloud = ($product['system_type'] === 'cloud');
        $rawUsageMode = strtoupper(trim((string)($payload['usage_mode'] ?? '')));

        if ($isCloud) {
            $usageMode = $rawUsageMode !== '' ? $rawUsageMode : 'CLOUD_HOSTED';
        } else {
            $usageMode = $rawUsageMode !== '' ? $rawUsageMode : VendPulseLicenseService::USAGE_MODE_RETAIL_SHOP;
            if (!in_array($usageMode, self::VALID_USAGE_MODES, true)) {
                throw new InvalidArgumentException("Invalid usage_mode '{$usageMode}'. Allowed modes: " . implode(', ', self::VALID_USAGE_MODES));
            }
        }

        $duration = $payload['term_duration_months'] ?? null;
        if ($duration !== null && (!is_numeric($duration) || (int)$duration < 0)) {
            throw new InvalidArgumentException("term_duration_months must be null or an integer >= 0");
        }
        $termDurationMonths = $duration !== null ? (int)$duration : null;

        $maxCounters = max(1, (int)($payload['max_seats'] ?? $payload['max_counters'] ?? 1));
        $includeBundle = (bool)($payload['include_installer_bundle'] ?? (!$isCloud));

        return [
            'order_id' => $orderId,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'business_name' => $businessName,
            'purchased_product' => ($isCloud ? $product['product_code'] : 'VENDPULSE'),
            'product_meta' => $product,
            'usage_mode' => $usageMode,
            'term_duration_months' => $termDurationMonths,
            'max_counters' => $maxCounters,
            'include_installer_bundle' => $includeBundle,
            'raw_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * Process order ingress: idempotency check, client creation, license signing & token generation.
     */
    public function provisionOrder(array $rawPayload): array {
        $validated = $this->validateOrderPayload($rawPayload);
        $orderId = $validated['order_id'];
        $product = $validated['product_meta'];

        // 1. Idempotency Check: Did we already provision this order_id?
        $existingOrder = $this->getOrderByOrderId($orderId);
        if ($existingOrder !== null) {
            $licId = (string)($existingOrder['license_id'] ?? '');
            $isExistingCloud = str_starts_with($licId, 'SPEC-') || ($existingOrder['usage_mode'] ?? '') === 'CLOUD_HOSTED';
            $portalUrl = $this->buildPortalUrl($existingOrder['download_token']);

            if ($isExistingCloud) {
                $cloudLicManager = new LicenseManager($this->pdo);
                $cloudLic = $cloudLicManager->getLicense($licId);

                return [
                    'success' => true,
                    'idempotent' => true,
                    'message' => 'Order previously provisioned. Returning existing cloud license and access portal credentials.',
                    'order' => [
                        'order_id' => $existingOrder['order_id'],
                        'status' => $existingOrder['status'],
                        'created_at' => $existingOrder['created_at'],
                    ],
                    'client' => [
                        'client_code' => $existingOrder['client_code'] ?? null,
                        'business_name' => $existingOrder['business_name'],
                        'customer_name' => $existingOrder['customer_name'],
                        'customer_email' => $existingOrder['customer_email'],
                    ],
                    'license' => [
                        'license_id' => $licId,
                        'license_key' => $licId,
                        'product' => $cloudLic['product_code'] ?? $existingOrder['purchased_product'],
                        'product_name' => $cloudLic['product_name'] ?? $existingOrder['purchased_product'],
                        'system_type' => 'cloud',
                        'tier' => 'CLOUD_ENTERPRISE',
                        'usage_mode' => $existingOrder['usage_mode'],
                        'max_seats' => (int)($cloudLic['max_seats'] ?? $existingOrder['max_counters']),
                        'issued_at' => strtotime((string)($cloudLic['issued_at'] ?? $existingOrder['created_at'])),
                        'expires_at' => !empty($cloudLic['expires_at']) ? strtotime((string)$cloudLic['expires_at']) : null,
                        'is_perpetual' => empty($cloudLic['expires_at']),
                    ],
                    'distribution' => [
                        'portal_url' => $portalUrl,
                        'download_token' => $existingOrder['download_token'],
                        'token_expires_at' => $existingOrder['token_expires_at'],
                        'include_installer_bundle' => (bool)$existingOrder['include_installer_bundle'],
                    ],
                ];
            }

            $license = $this->licenseService->getLicenseByCode($licId);
            return [
                'success' => true,
                'idempotent' => true,
                'message' => 'Order previously provisioned. Returning existing license and access portal credentials.',
                'order' => [
                    'order_id' => $existingOrder['order_id'],
                    'status' => $existingOrder['status'],
                    'created_at' => $existingOrder['created_at'],
                ],
                'client' => [
                    'client_code' => $existingOrder['client_code'] ?? null,
                    'business_name' => $existingOrder['business_name'],
                    'customer_name' => $existingOrder['customer_name'],
                    'customer_email' => $existingOrder['customer_email'],
                ],
                'license' => [
                    'license_id' => $license['license_id'] ?? $existingOrder['license_id'],
                    'product' => 'VENDPULSE',
                    'tier' => $license['tier'] ?? 'SUPERMARKET',
                    'usage_mode' => $license['usage_mode'] ?? $existingOrder['usage_mode'],
                    'max_counters' => (int)($license['max_counters'] ?? $existingOrder['max_counters']),
                    'issued_at' => (int)($license['issued_at'] ?? strtotime($existingOrder['created_at'])),
                    'expires_at' => !empty($license['expires_at']) ? (int)$license['expires_at'] : null,
                    'is_perpetual' => empty($license['expires_at']),
                    'signature' => $license['signature'] ?? '',
                    'license_bundle' => $license['license_bundle'] ?? '',
                ],
                'distribution' => [
                    'portal_url' => $portalUrl,
                    'download_token' => $existingOrder['download_token'],
                    'token_expires_at' => $existingOrder['token_expires_at'],
                    'include_installer_bundle' => (bool)$existingOrder['include_installer_bundle'],
                ],
            ];
        }

        // 2. Client Account Provisioning / Association
        $clientId = $this->findOrCreateClient(
            $validated['customer_email'],
            $validated['business_name'],
            $validated['customer_name']
        );
        $clientCode = $this->getClientCodeById($clientId);

        // 3. Cloud System Order Provisioning Flow
        if ($product['system_type'] === 'cloud') {
            $licenseType = (!empty($validated['term_duration_months']) && $validated['term_duration_months'] > 0) ? 'subscription' : 'perpetual';
            $expiresAt = (!empty($validated['term_duration_months']) && $validated['term_duration_months'] > 0)
                ? date('Y-m-d H:i:s', strtotime("+{$validated['term_duration_months']} months"))
                : null;
            $maxSeats = $validated['max_counters'];

            $cloudLicenseManager = new LicenseManager($this->pdo);
            $issuedLic = $cloudLicenseManager->issueLicense(
                (int)$product['product_id'],
                "{$validated['business_name']} ({$validated['customer_name']})",
                $validated['customer_email'],
                $licenseType,
                $expiresAt,
                $maxSeats
            );

            $licenseKey = $issuedLic['license_key'];
            $downloadToken = bin2hex(random_bytes(32));
            $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

            $stmtOrder = $this->pdo->prepare("
                INSERT INTO central_orders (
                    order_id, client_id, customer_name, customer_email, business_name,
                    purchased_product, usage_mode, term_duration_months, max_counters,
                    include_installer_bundle, license_id, download_token, token_expires_at,
                    download_count, status, raw_payload, created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    0, 'completed', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )
            ");

            $stmtOrder->execute([
                $orderId,
                $clientId,
                $validated['customer_name'],
                $validated['customer_email'],
                $validated['business_name'],
                $product['product_code'],
                $validated['usage_mode'],
                $validated['term_duration_months'],
                $maxSeats,
                0,
                $licenseKey,
                $downloadToken,
                $tokenExpiresAt,
                $validated['raw_payload'],
            ]);

            $portalUrl = $this->buildPortalUrl($downloadToken);

            return [
                'success' => true,
                'idempotent' => false,
                'message' => "Order provisioned successfully. Client account and cloud system license issued for {$product['product_name']}.",
                'order' => [
                    'order_id' => $orderId,
                    'status' => 'completed',
                    'created_at' => date('Y-m-d H:i:s'),
                ],
                'client' => [
                    'client_code' => $clientCode,
                    'business_name' => $validated['business_name'],
                    'customer_name' => $validated['customer_name'],
                    'customer_email' => $validated['customer_email'],
                ],
                'license' => [
                    'license_id' => $licenseKey,
                    'license_key' => $licenseKey,
                    'product' => $product['product_code'],
                    'product_name' => $product['product_name'],
                    'system_type' => 'cloud',
                    'tier' => 'CLOUD_ENTERPRISE',
                    'usage_mode' => $validated['usage_mode'],
                    'max_seats' => $maxSeats,
                    'license_type' => $licenseType,
                    'issued_at' => time(),
                    'expires_at' => $expiresAt ? strtotime($expiresAt) : null,
                    'is_perpetual' => empty($expiresAt),
                    'pairing_guide' => [
                        'portal_url' => $portalUrl,
                        'telemetry_hub' => $this->publicUrl,
                        'instructions' => 'Paste this license key into your cloud instance activation screen (activation.php) or Administrator Settings.',
                    ],
                ],
                'distribution' => [
                    'portal_url' => $portalUrl,
                    'download_token' => $downloadToken,
                    'token_expires_at' => $tokenExpiresAt,
                    'include_installer_bundle' => false,
                ],
            ];
        }

        // 4. Map Usage Mode to Operating Tier & Modules (Offline POS)
        $usageMode = $validated['usage_mode'];
        $tier = match ($usageMode) {
            VendPulseLicenseService::USAGE_MODE_SUPERMARKET => VendPulseLicenseService::TIER_SUPERMARKET,
            VendPulseLicenseService::USAGE_MODE_RETAIL_SHOP => VendPulseLicenseService::TIER_RETAIL_BASIC,
            VendPulseLicenseService::USAGE_MODE_RESTAURANT => VendPulseLicenseService::TIER_SUPERMARKET,
            default => VendPulseLicenseService::TIER_RETAIL_BASIC,
        };

        // Custom modules for Restaurant mode if selected
        $allowedModules = null;
        if ($usageMode === VendPulseLicenseService::USAGE_MODE_RESTAURANT) {
            $allowedModules = [
                'table_floor_plan',
                'kot_kitchen_tickets',
                'split_billing',
                'standard_pos',
                'basic_receipt',
            ];
        }

        // Calculate term expiration
        $expiresAt = null;
        if (!empty($validated['term_duration_months']) && $validated['term_duration_months'] > 0) {
            $expiresAt = strtotime("+{$validated['term_duration_months']} months");
        }

        // 5. Issue Offline Signed License Key
        $issuedLicense = $this->licenseService->issueLicense([
            'client_name' => "{$validated['business_name']} ({$validated['customer_name']})",
            'tier' => $tier,
            'usage_mode' => $usageMode,
            'max_counters' => $validated['max_counters'],
            'expires_at' => $expiresAt,
            'allowed_modules' => $allowedModules,
        ]);

        $licenseId = $issuedLicense['license_id'];

        // 6. Generate Cryptographic Download Token for Self-Service Portal
        $downloadToken = bin2hex(random_bytes(32)); // 64 hex characters
        $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));

        // 7. Persist Order in central_orders
        $stmtOrder = $this->pdo->prepare("
            INSERT INTO central_orders (
                order_id, client_id, customer_name, customer_email, business_name,
                purchased_product, usage_mode, term_duration_months, max_counters,
                include_installer_bundle, license_id, download_token, token_expires_at,
                download_count, status, raw_payload, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                0, 'completed', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");

        $stmtOrder->execute([
            $orderId,
            $clientId,
            $validated['customer_name'],
            $validated['customer_email'],
            $validated['business_name'],
            $validated['purchased_product'],
            $usageMode,
            $validated['term_duration_months'],
            $validated['max_counters'],
            $validated['include_installer_bundle'] ? 1 : 0,
            $licenseId,
            $downloadToken,
            $tokenExpiresAt,
            $validated['raw_payload'],
        ]);

        $portalUrl = $this->buildPortalUrl($downloadToken);

        return [
            'success' => true,
            'idempotent' => false,
            'message' => 'Order provisioned successfully. Client account and signed license issued.',
            'order' => [
                'order_id' => $orderId,
                'status' => 'completed',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            'client' => [
                'client_code' => $clientCode,
                'business_name' => $validated['business_name'],
                'customer_name' => $validated['customer_name'],
                'customer_email' => $validated['customer_email'],
            ],
            'license' => [
                'license_id' => $licenseId,
                'product' => 'VENDPULSE',
                'tier' => $tier,
                'usage_mode' => $usageMode,
                'max_counters' => $validated['max_counters'],
                'issued_at' => $issuedLicense['payload']['issued_at'],
                'expires_at' => $issuedLicense['payload']['expires_at'],
                'is_perpetual' => $issuedLicense['payload']['expires_at'] === null,
                'signature' => $issuedLicense['signature'],
                'license_bundle' => $issuedLicense['license_bundle'],
            ],
            'distribution' => [
                'portal_url' => $portalUrl,
                'download_token' => $downloadToken,
                'token_expires_at' => $tokenExpiresAt,
                'include_installer_bundle' => $validated['include_installer_bundle'],
            ],
        ];
    }

    /**
     * Construct customer self-service download portal URL.
     */
    public function buildPortalUrl(string $token): string {
        return "{$this->publicUrl}/portal/download.php?token=" . urlencode($token);
    }

    /**
     * Find existing client account or register a new one.
     */
    public function findOrCreateClient(string $email, string $businessName, string $contactName): int {
        $stmt = $this->pdo->prepare("SELECT id FROM central_clients WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if ($row) {
            // Update business or contact name if updated
            $upd = $this->pdo->prepare("UPDATE central_clients SET business_name = ?, contact_name = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$businessName, $contactName, $row['id']]);
            return (int)$row['id'];
        }

        $clientCode = 'CLI-VP-' . strtoupper(bin2hex(random_bytes(3)));
        $ins = $this->pdo->prepare("
            INSERT INTO central_clients (client_code, business_name, contact_name, email, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $ins->execute([$clientCode, $businessName, $contactName, $email]);

        return (int)$this->pdo->lastInsertId();
    }

    public function getClientCodeById(int $clientId): ?string {
        $stmt = $this->pdo->prepare("SELECT client_code FROM central_clients WHERE id = ? LIMIT 1");
        $stmt->execute([$clientId]);
        $res = $stmt->fetch();
        return $res ? (string)$res['client_code'] : null;
    }

    /**
     * Fetch order details by unique order_id.
     */
    public function getOrderByOrderId(string $orderId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT o.*, c.client_code, c.email as client_email
            FROM central_orders o
            LEFT JOIN central_clients c ON o.client_id = c.id
            WHERE o.order_id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        return $order ?: null;
    }

    /**
     * Fetch order details by secure customer download token.
     */
    public function getOrderByDownloadToken(string $token): ?array {
        $cleanToken = trim($token);
        if ($cleanToken === '') {
            return null;
        }

        $stmt = $this->pdo->prepare("
            SELECT o.*, c.client_code, c.email as client_email
            FROM central_orders o
            LEFT JOIN central_clients c ON o.client_id = c.id
            WHERE o.download_token = ?
            LIMIT 1
        ");
        $stmt->execute([$cleanToken]);
        $order = $stmt->fetch();
        if (!$order) {
            return null;
        }

        // Check if token has expired
        if (!empty($order['token_expires_at'])) {
            $expiry = strtotime((string)$order['token_expires_at']);
            if ($expiry !== false && $expiry < time()) {
                $order['is_expired'] = true;
            }
        }

        return $order;
    }

    /**
     * Increment download audit counter and record timestamp.
     */
    public function recordDownload(string $token): void {
        $stmt = $this->pdo->prepare("
            UPDATE central_orders
            SET download_count = download_count + 1,
                last_download_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE download_token = ?
        ");
        $stmt->execute([$token]);
    }

    /**
     * List orders for the SpectraOps dashboard with cloud deployment federation.
     */
    public function listOrders(?string $search = null, ?string $status = null, ?string $mode = null, ?string $filter = null, int $limit = 100): array {
        $sql = "
            SELECT 
                o.*, 
                c.client_code,
                c.email as client_email,
                lic.id as central_license_id,
                lic.status as license_status,
                lic.expires_at as license_expires_at,
                lic.max_seats as license_max_seats,
                lic.product_id as license_product_id,
                dep.id as deployment_id,
                dep.deployment_domain,
                dep.status as deployment_status,
                dep.current_version as deployment_version,
                dep.last_heartbeat_at as deployment_last_heartbeat,
                dep.active_users_24h as deployment_active_users,
                dep.memory_usage_mb as deployment_memory,
                dep.db_latency_ms as deployment_latency,
                dep.paired_at as deployment_paired_at,
                COALESCE(p.id, lic.product_id) as product_id,
                COALESCE(p.product_name, o.purchased_product) as product_name,
                COALESCE(p.product_slug, LOWER(o.purchased_product)) as product_slug,
                COALESCE(p.product_code, o.purchased_product) as product_code
            FROM central_orders o
            LEFT JOIN central_clients c ON o.client_id = c.id
            LEFT JOIN central_licenses lic ON lic.license_key = o.license_id
            LEFT JOIN central_deployments dep ON dep.id = (
                SELECT d.id FROM central_deployments d WHERE d.license_id = lic.id ORDER BY d.id DESC LIMIT 1
            )
            LEFT JOIN central_products p ON (UPPER(p.product_code) = UPPER(o.purchased_product) OR p.id = lic.product_id)
            WHERE 1=1
        ";
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $sql .= " AND (o.order_id LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.business_name LIKE ? OR o.license_id LIKE ? OR c.client_code LIKE ? OR dep.deployment_domain LIKE ?)";
            $s = '%' . trim($search) . '%';
            $params = array_merge($params, [$s, $s, $s, $s, $s, $s, $s]);
        }

        if ($status !== null && trim($status) !== '' && strtolower(trim($status)) !== 'all') {
            $sql .= " AND o.status = ?";
            $params[] = trim($status);
        }

        if ($mode !== null && trim($mode) !== '' && strtolower(trim($mode)) !== 'all') {
            $sql .= " AND o.usage_mode = ?";
            $params[] = trim($mode);
        }

        // Custom filter presets: cloud, deployed, pending, pos / offline
        if ($filter !== null && trim($filter) !== '' && strtolower(trim($filter)) !== 'all') {
            $f = strtolower(trim($filter));
            if ($f === 'cloud') {
                $sql .= " AND (o.license_id LIKE 'SPEC-%' OR o.usage_mode = 'CLOUD_HOSTED' OR UPPER(o.purchased_product) != 'VENDPULSE')";
            } elseif ($f === 'deployed') {
                $sql .= " AND dep.id IS NOT NULL";
            } elseif ($f === 'pending') {
                $sql .= " AND (o.license_id LIKE 'SPEC-%' OR o.usage_mode = 'CLOUD_HOSTED' OR UPPER(o.purchased_product) != 'VENDPULSE') AND dep.id IS NULL";
            } elseif ($f === 'pos' || $f === 'offline') {
                $sql .= " AND (o.license_id LIKE 'VP-%' OR UPPER(o.purchased_product) = 'VENDPULSE')";
            }
        }

        $sql .= " ORDER BY o.created_at DESC LIMIT " . (int)$limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Compute comprehensive store orders and cloud deployment stats.
     */
    public function getOrderStatistics(): array {
        $stats = [
            'total' => 0,
            'completed' => 0,
            'downloads' => 0,
            'cloud' => 0,
            'cloud_deployed' => 0,
            'cloud_pending' => 0,
            'supermarket' => 0,
            'retail' => 0,
            'restaurant' => 0,
            'offline' => 0,
        ];

        $orders = $this->listOrders(null, null, null, null, 1000);
        $stats['total'] = count($orders);

        foreach ($orders as $o) {
            if (($o['status'] ?? '') === 'completed') {
                $stats['completed']++;
            }
            $stats['downloads'] += (int)($o['download_count'] ?? 0);

            $licId = (string)($o['license_id'] ?? '');
            $mode = strtoupper((string)($o['usage_mode'] ?? ''));
            $prod = strtoupper((string)($o['purchased_product'] ?? ''));
            $isCloud = str_starts_with($licId, 'SPEC-') || $mode === 'CLOUD_HOSTED' || ($prod !== 'VENDPULSE' && $prod !== 'VP');

            if ($isCloud) {
                $stats['cloud']++;
                if (!empty($o['deployment_id'])) {
                    $stats['cloud_deployed']++;
                } else {
                    $stats['cloud_pending']++;
                }
            } else {
                $stats['offline']++;
                if ($mode === 'SUPERMARKET') {
                    $stats['supermarket']++;
                } elseif ($mode === 'RETAIL_SHOP') {
                    $stats['retail']++;
                } elseif ($mode === 'RESTAURANT') {
                    $stats['restaurant']++;
                }
            }
        }

        return $stats;
    }
}
