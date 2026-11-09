#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SpectraOps Central Fleet Hub - Background Lease Renewal Worker
 * Location: bin/renew-lease.php
 *
 * Invoked by background detached processes or cron to renew rolling HMAC lease tokens.
 */

require_once dirname(__DIR__) . '/client-sdk/LeaseVerificationMiddleware.php';

$appRoot = dirname(__DIR__);
$middleware = new \FleetHub\ClientSdk\LeaseVerificationMiddleware($appRoot);

$result = $middleware->renewLease();

if (!empty($result['renewed'])) {
    if (php_sapi_name() === 'cli') {
        echo "[SUCCESS] Lease renewed successfully. New Expiration: " . ($result['expires_at'] ?? 'N/A') . PHP_EOL;
    }
    exit(0);
} else {
    if (php_sapi_name() === 'cli') {
        echo "[ERROR] Lease renewal failed: " . ($result['error'] ?? 'Unknown error') . PHP_EOL;
    }
    exit(1);
}
