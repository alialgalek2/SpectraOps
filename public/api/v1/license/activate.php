<?php
declare(strict_types=1);

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once dirname(__DIR__, 4) . '/src/Database.php';
require_once dirname(__DIR__, 4) . '/src/FleetService.php';
require_once dirname(__DIR__, 4) . '/src/RateLimiter.php';

try {
    $pdo = \FleetHub\Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Central Hub database unavailable.']);
    exit;
}

// Rate Limiting (H3): 30 activation requests per minute per IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$limiter = new \FleetHub\RateLimiter($pdo);
if ($limiter->tooManyAttempts('license_activate:' . $clientIp, 30, 60)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many activation attempts. Please wait before retrying.'
    ]);
    exit;
}
$limiter->hit('license_activate:' . $clientIp, 60);

$raw = file_get_contents('php://input');
$input = !empty($raw) ? (json_decode($raw, true) ?? []) : $_POST;

$licenseKey = strtoupper(trim((string)($input['license_key'] ?? '')));
$domain     = strtolower(trim((string)($input['domain'] ?? '')));
$hwSig      = trim((string)($input['hw_sig'] ?? ''));
$version    = trim((string)($input['version'] ?? '1.0.0'));

if (empty($licenseKey) || empty($domain)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'License key and host domain are required.']);
    exit;
}

// Validate domain against SSRF attacks (C7)
if (!\FleetHub\FleetService::isValidPublicDomain($domain)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid public hostname or domain for deployment.']);
    exit;
}

// 1. Verify License against catalog
$stmt = $pdo->prepare("
    SELECT l.*, p.product_slug, p.product_name, p.product_code 
    FROM central_licenses l
    JOIN central_products p ON l.product_id = p.id
    WHERE l.license_key = ?
    LIMIT 1
");
$stmt->execute([$licenseKey]);
$license = $stmt->fetch();

if (!$license) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Invalid or unrecognized license key.']);
    exit;
}

if (in_array($license['status'], ['revoked', 'suspended'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "License is currently {$license['status']}."]);
    exit;
}

if ($license['expires_at'] && strtotime((string)$license['expires_at']) < time()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'License has expired.']);
    exit;
}

// 2. Check Existing Deployment & Domain Lock
$checkDeploy = $pdo->prepare("SELECT * FROM central_deployments WHERE license_id = ? LIMIT 1");
$checkDeploy->execute([$license['id']]);
$deployment = $checkDeploy->fetch();

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

try {
    $pdo->beginTransaction();

    if ($license['status'] === 'unactivated') {
        // First Activation: Lock domain and create deployment entry
        $tokenHash = hash('sha256', $licenseKey);
        $tokenPreview = substr($licenseKey, 0, 8) . '...';

        $ins = $pdo->prepare("
            INSERT INTO central_deployments 
            (product_id, license_id, client_name, deployment_domain, hardware_hash, api_ingest_token_hash, token_preview, current_version, status, last_heartbeat_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'healthy', {$nowFunc}, {$nowFunc})
        ");
        $ins->execute([
            $license['product_id'],
            $license['id'],
            $license['client_name'],
            $domain,
            $hwSig !== '' ? $hwSig : null,
            $tokenHash,
            $tokenPreview,
            $version
        ]);

        $upd = $pdo->prepare("UPDATE central_licenses SET status = 'active' WHERE id = ?");
        $upd->execute([$license['id']]);
    } else {
        // Re-activation / Verification: Ensure matching domain
        if ($deployment && $deployment['deployment_domain'] !== $domain) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => "License locked to domain: {$deployment['deployment_domain']}"]);
            $pdo->rollBack();
            exit;
        }

        // Update hardware hash if provided and not yet stored
        if ($deployment && $hwSig !== '' && empty($deployment['hardware_hash'])) {
            $upd = $pdo->prepare("UPDATE central_deployments SET hardware_hash = ? WHERE id = ?");
            $upd->execute([$hwSig, $deployment['id']]);
        }
    }

    $pdo->commit();

    // 3. Generate Cryptographic Lease Signature
    $cryptSecret = getenv('SPECTRAOPS_CRYPT_SECRET_KEY') ?: 'SPECTRAOPS_CRYPT_SECRET_KEY';
    $rawPayload = "{$licenseKey}|{$domain}|{$license['product_code']}|" . ($license['expires_at'] ?? 'PERPETUAL');
    $leaseToken = hash_hmac('sha256', $rawPayload, $cryptSecret);

    echo json_encode([
        'success'      => true,
        'license_key'  => $licenseKey,
        'client_name'  => $license['client_name'],
        'product'      => $license['product_name'],
        'product_slug' => $license['product_slug'],
        'lease_token'  => $leaseToken,
        'expires_at'   => $license['expires_at']
    ]);

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Activation transaction failure.']);
}
