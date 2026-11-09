<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

class LicenseManager {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Issues a dynamic product license key: SPEC-{CODE}-{XXXX}-{XXXX}
     *
     * @param int $productId Target product ID in central_products
     * @param string $clientName Name of school, organization, or client
     * @param string $clientEmail Primary contact or admin email
     * @param string $type perpetual | subscription | trial
     * @param string|null $expiresAt Expiration datetime string (or null for perpetual)
     * @param int $maxSeats Seat limit (0 for unlimited)
     * @return array
     */
    public function issueLicense(
        int $productId,
        string $clientName,
        string $clientEmail,
        string $type = 'perpetual',
        ?string $expiresAt = null,
        int $maxSeats = 0
    ): array {
        $stmt = $this->pdo->prepare("SELECT product_code, product_name, product_slug FROM central_products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException("Selected product ID #{$productId} does not exist.");
        }

        $code = strtoupper(trim((string)($product['product_code'] ?? '')));
        if ($code === '') {
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$product['product_slug']), 0, 4));
            if ($code === '') {
                $code = 'SPEC';
            }
        }

        $segment1 = strtoupper(bin2hex(random_bytes(2)));
        $segment2 = strtoupper(bin2hex(random_bytes(2)));
        $licenseKey = "SPEC-{$code}-{$segment1}-{$segment2}";

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

        $ins = $this->pdo->prepare("
            INSERT INTO central_licenses 
            (product_id, license_key, client_name, client_email, license_type, max_seats, expires_at, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'unactivated', {$nowFunc})
        ");
        $ins->execute([$productId, $licenseKey, $clientName, $clientEmail, $type, $maxSeats, $expiresAt]);

        return [
            'license_id'   => (int)$this->pdo->lastInsertId(),
            'license_key'  => $licenseKey,
            'product_name' => $product['product_name'],
            'product_code' => $code,
            'client_name'  => $clientName,
            'client_email' => $clientEmail,
            'license_type' => $type,
            'expires_at'   => $expiresAt,
            'max_seats'    => $maxSeats
        ];
    }

    /**
     * Look up license details by ID
     */
    public function getLicenseById(int $id): ?array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, p.product_slug, p.product_name, p.product_code, p.latest_version,
                   d.id AS deployment_id, d.deployment_domain, d.status AS deployment_status,
                   d.is_active AS deployment_is_active, d.last_heartbeat_at
            FROM central_licenses l
            JOIN central_products p ON l.product_id = p.id
            LEFT JOIN central_deployments d ON d.license_id = l.id
            WHERE l.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Look up license details by key
     */
    public function getLicense(string $licenseKey): ?array {
        $stmt = $this->pdo->prepare("
            SELECT l.*, p.product_slug, p.product_name, p.product_code, p.latest_version,
                   d.id AS deployment_id, d.deployment_domain, d.status AS deployment_status,
                   d.is_active AS deployment_is_active, d.last_heartbeat_at
            FROM central_licenses l
            JOIN central_products p ON l.product_id = p.id
            LEFT JOIN central_deployments d ON d.license_id = l.id
            WHERE l.license_key = ?
            LIMIT 1
        ");
        $stmt->execute([$licenseKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * List all licenses with product details and bound deployment
     */
    public function listLicenses(?int $productId = null): array {
        $sql = "
            SELECT l.*, p.product_name, p.product_code, p.product_slug,
                   d.id AS deployment_id, d.deployment_domain, d.status AS deployment_status,
                   d.is_active AS deployment_is_active, d.last_heartbeat_at,
                   (SELECT COUNT(*) FROM central_deployments cd WHERE cd.license_id = l.id) as deployment_count
            FROM central_licenses l
            JOIN central_products p ON l.product_id = p.id
            LEFT JOIN central_deployments d ON d.license_id = l.id
        ";
        $params = [];
        if ($productId !== null && $productId > 0) {
            $sql .= " WHERE l.product_id = ?";
            $params[] = $productId;
        }
        $sql .= " ORDER BY l.id DESC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Suspend an active license and temporarily deactivate its bound deployment
     */
    public function suspendLicense(int $licenseId, ?string $reason = null): bool {
        $license = $this->getLicenseById($licenseId);
        if (!$license) {
            throw new RuntimeException("License #{$licenseId} does not exist.");
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
        $reasonText = !empty($reason) ? "License suspended: {$reason}" : "License suspended by operator";

        try {
            $this->pdo->beginTransaction();

            $updLic = $this->pdo->prepare("UPDATE central_licenses SET status = 'suspended' WHERE id = ?");
            $updLic->execute([$licenseId]);

            $updDep = $this->pdo->prepare("
                UPDATE central_deployments 
                SET is_active = 0, status = 'warning', status_reason = ?, updated_at = {$nowFunc}
                WHERE license_id = ?
            ");
            $updDep->execute([$reasonText, $licenseId]);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Reactivate / Resume a suspended license and its bound deployment
     */
    public function reactivateLicense(int $licenseId): bool {
        $license = $this->getLicenseById($licenseId);
        if (!$license) {
            throw new RuntimeException("License #{$licenseId} does not exist.");
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
        $statusReason = "License reactivated by operator";

        try {
            $this->pdo->beginTransaction();

            $updLic = $this->pdo->prepare("UPDATE central_licenses SET status = 'active' WHERE id = ?");
            $updLic->execute([$licenseId]);

            $updDep = $this->pdo->prepare("
                UPDATE central_deployments 
                SET is_active = 1, status = 'healthy', status_reason = ?, updated_at = {$nowFunc}
                WHERE license_id = ?
            ");
            $updDep->execute([$statusReason, $licenseId]);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Revoke a license permanently and disable its bound deployment
     */
    public function revokeLicense(int $licenseId, ?string $reason = null): bool {
        $license = $this->getLicenseById($licenseId);
        if (!$license) {
            throw new RuntimeException("License #{$licenseId} does not exist.");
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
        $reasonText = !empty($reason) ? "License revoked: {$reason}" : "License revoked by operator";

        try {
            $this->pdo->beginTransaction();

            $updLic = $this->pdo->prepare("UPDATE central_licenses SET status = 'revoked' WHERE id = ?");
            $updLic->execute([$licenseId]);

            $updDep = $this->pdo->prepare("
                UPDATE central_deployments 
                SET is_active = 0, status = 'critical', status_reason = ?, updated_at = {$nowFunc}
                WHERE license_id = ?
            ");
            $updDep->execute([$reasonText, $licenseId]);

            $this->pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Renew a license by extending expiration or setting perpetual, and reactivate deployment
     *
     * @param int $licenseId
     * @param string|null $newExpiresAt New expiration date/time or null for perpetual
     * @param string|null $newType optional type ('perpetual', 'subscription', 'trial')
     * @param string|null $reason optional note
     * @return array Updated license details
     */
    public function renewLicense(
        int $licenseId,
        ?string $newExpiresAt = null,
        ?string $newType = null,
        ?string $reason = null
    ): array {
        $license = $this->getLicenseById($licenseId);
        if (!$license) {
            throw new RuntimeException("License #{$licenseId} does not exist.");
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

        // Determine target license status
        $targetStatus = ($license['status'] === 'unactivated' && empty($license['deployment_id'])) ? 'unactivated' : 'active';
        $targetType = $newType ?: $license['license_type'];
        if ($targetType === 'perpetual') {
            $newExpiresAt = null;
        }

        $reasonText = !empty($reason) ? "License renewed: {$reason}" : "License renewed and deployment re-activated";

        try {
            $this->pdo->beginTransaction();

            $updLic = $this->pdo->prepare("
                UPDATE central_licenses 
                SET expires_at = ?, license_type = ?, status = ?
                WHERE id = ?
            ");
            $updLic->execute([$newExpiresAt, $targetType, $targetStatus, $licenseId]);

            // Re-activate bound deployment if exists
            $updDep = $this->pdo->prepare("
                UPDATE central_deployments 
                SET is_active = 1, status = 'healthy', status_reason = ?, updated_at = {$nowFunc}
                WHERE license_id = ?
            ");
            $updDep->execute([$reasonText, $licenseId]);

            $this->pdo->commit();

            return $this->getLicenseById($licenseId) ?: [];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}

// Namespace alias for compatibility across App\Services and FleetHub\Services
if (!class_exists(\FleetHub\Services\LicenseManager::class, false)) {
    class_alias(LicenseManager::class, \FleetHub\Services\LicenseManager::class);
}
