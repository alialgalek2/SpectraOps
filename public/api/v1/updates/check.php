<?php
declare(strict_types=1);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=UTF-8');

if (file_exists(dirname(__DIR__, 4) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 4) . '/vendor/autoload.php';
}
require_once dirname(__DIR__, 4) . '/src/Database.php';
require_once dirname(__DIR__, 4) . '/src/RateLimiter.php';
require_once dirname(__DIR__, 4) . '/src/Services/UpdateSigner.php';

use FleetHub\Database;
use FleetHub\RateLimiter;
use FleetHub\Services\UpdateSigner;

// 1. Resilient Database Connection
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;dbname=central_fleet_db;charset=utf8mb4", "fleet_user", "FLEET_SECRET_KEY", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
    } catch (\Throwable $e2) {
        http_response_code(500);
        echo json_encode(['update_available' => false, 'error' => 'Hub database offline.']);
        exit;
    }
}

// 2. Rate Limiting Protection (60 requests/min per IP)
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (str_contains($clientIp, ',')) {
    $clientIp = trim(explode(',', $clientIp)[0]);
}
$limiter = new RateLimiter($pdo);
if ($limiter->tooManyAttempts('update_check:' . $clientIp, 60, 60)) {
    http_response_code(429);
    echo json_encode(['update_available' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
}
$limiter->hit('update_check:' . $clientIp, 60);

// 3. Read Raw Input (supports php://input and CLI stdin for testbeds)
$rawInput = file_get_contents('php://input');
if ($rawInput === '' || $rawInput === false) {
    if (PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_METHOD'])) {
        $rawInput = @file_get_contents('php://stdin');
    }
}
$payload = json_decode((string)$rawInput, true) ?? [];
if (!empty($_POST)) {
    $payload = array_merge($_POST, $payload);
}
if (!empty($_GET)) {
    $payload = array_merge($_GET, $payload);
}

// 4. Multi-Fallback Token Extraction (Bearer Header, Custom Header, Body, or Query)
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (empty($authHeader) && function_exists('getallheaders')) {
    $all = getallheaders();
    $authHeader = $all['Authorization'] ?? $all['authorization'] ?? '';
}
if (empty($authHeader) && function_exists('apache_request_headers')) {
    $all = apache_request_headers();
    foreach ($all as $k => $v) {
        if (strcasecmp((string)$k, 'Authorization') === 0) {
            $authHeader = (string)$v;
            break;
        }
    }
}

$token = null;
if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $token = trim($matches[1]);
}
if (empty($token)) {
    $token = $_SERVER['HTTP_X_API_KEY'] ?? ($_SERVER['HTTP_X_API_TOKEN'] ?? ($_SERVER['HTTP_X_INGEST_TOKEN'] ?? null));
}
if (empty($token)) {
    $token = $payload['api_key'] ?? ($payload['api_token'] ?? ($payload['license_key'] ?? ($payload['ingest_token'] ?? ($payload['token'] ?? null))));
}
if (empty($token) && !empty($_GET['token'])) {
    $token = (string)$_GET['token'];
}
if (empty($token) && !empty($_GET['license_key'])) {
    $token = (string)$_GET['license_key'];
}
$token = is_string($token) ? trim($token) : '';

if (empty($token)) {
    http_response_code(401);
    echo json_encode(['update_available' => false, 'error' => 'Missing authorization token.']);
    exit;
}

// 5. Validate Deployment & Identify Product
$tokenHash = hash('sha256', $token);
$stmt = $pdo->prepare("
    SELECT d.id AS deployment_id, d.product_id, d.is_active, d.status AS deployment_status,
           l.id AS license_id, l.status AS license_status, l.license_key,
           p.product_slug, p.product_code, p.product_name
    FROM central_deployments d
    JOIN central_products p ON d.product_id = p.id
    LEFT JOIN central_licenses l ON d.license_id = l.id
    WHERE (d.api_ingest_token_hash = :hash OR d.api_ingest_token_hash = :raw_token OR d.api_ingest_token = :raw_token OR l.license_key = :raw_token)
    LIMIT 1
");
$stmt->execute([':hash' => $tokenHash, ':raw_token' => $token]);
$deployment = $stmt->fetch();

if (!$deployment) {
    http_response_code(403);
    echo json_encode(['update_available' => false, 'error' => 'Unrecognized or unauthorized deployment token.']);
    exit;
}

$licenseStatus = strtolower((string)($deployment['license_status'] ?? 'active'));
$deployStatus = strtolower((string)($deployment['deployment_status'] ?? 'healthy'));
$isActive = (int)($deployment['is_active'] ?? 1);

if ($isActive !== 1 || $licenseStatus === 'suspended' || $licenseStatus === 'revoked' || $deployStatus === 'suspended') {
    http_response_code(403);
    echo json_encode(['update_available' => false, 'error' => 'License is not in active standing.']);
    exit;
}

// 6. Determine Client Current Version (Normalized - strip accidental 'v'/'V')
$rawCurrentVer = trim((string)($payload['current_version'] ?? ($payload['version'] ?? ($payload['app_version'] ?? '0.0.0'))));
$cleanCurrentVer = ltrim($rawCurrentVer, 'vV');
if ($cleanCurrentVer === '') {
    $cleanCurrentVer = '0.0.0';
}

// Touch last_heartbeat_at & record current_version on valid check
try {
    $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
    $nowExpr = $isSqlite ? "datetime('now')" : "UTC_TIMESTAMP()";
    $depId = (int)$deployment['deployment_id'];

    if ($cleanCurrentVer !== '0.0.0') {
        $pdo->prepare("
            UPDATE central_deployments 
            SET last_heartbeat_at = {$nowExpr}, 
                current_version = ?, 
                updated_at = {$nowExpr} 
            WHERE id = ?
        ")->execute([$cleanCurrentVer, $depId]);

        // Auto-reconcile pending patch errors for this deployment
        $checkPending = $pdo->prepare("
            SELECT id, resolved_by_version FROM central_error_logs 
            WHERE deployment_id = ? AND status = 'patch_pending'
        ");
        $checkPending->execute([$depId]);
        $pendingErrs = $checkPending->fetchAll(PDO::FETCH_ASSOC);

        $toResolve = [];
        foreach ($pendingErrs as $pErr) {
            $errVer = ltrim(trim((string)($pErr['resolved_by_version'] ?? '')), 'vV');
            if ($errVer === '' || version_compare($cleanCurrentVer, $errVer, '>=')) {
                $toResolve[] = (int)$pErr['id'];
            }
        }
        if (!empty($toResolve)) {
            $inClause = implode(',', $toResolve);
            $pdo->exec("
                UPDATE central_error_logs 
                SET status = 'resolved', resolved = 1, resolved_at = {$nowExpr} 
                WHERE id IN ({$inClause}) AND deployment_id = {$depId}
            ");
        }
    } else {
        $pdo->prepare("UPDATE central_deployments SET last_heartbeat_at = {$nowExpr}, updated_at = {$nowExpr} WHERE id = ?")->execute([$depId]);
    }
} catch (\Throwable $e) {}

// Verify product slug match if provided
$rawProductSlug = trim((string)($payload['product_slug'] ?? ($payload['slug'] ?? '')));
if ($rawProductSlug !== '') {
    $cleanSlug = strtolower($rawProductSlug);
    if ($cleanSlug !== strtolower($deployment['product_slug']) && $cleanSlug !== strtolower($deployment['product_code'])) {
        http_response_code(400);
        echo json_encode(['update_available' => false, 'error' => 'Deployment does not match product slug.']);
        exit;
    }
}

// 7. Fetch the Latest Active Release for this Product
$publicDir = str_replace('\\', '/', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
$updatesDir = rtrim($publicDir, '/\\') . '/downloads/updates';

$stmtRel = $pdo->prepare("
    SELECT id, version, release_type, is_silent_patch, resolves_error_id, changelog, package_filename, package_checksum, released_at
    FROM central_releases
    WHERE product_id = ? AND is_active = 1
    ORDER BY released_at DESC, id DESC
    LIMIT 20
");
$stmtRel->execute([$deployment['product_id']]);
$candidateReleases = $stmtRel->fetchAll();

$latestRelease = null;
foreach ($candidateReleases as $candidate) {
    $pkg = trim((string)($candidate['package_filename'] ?? ''));
    if ($pkg !== '') {
        $filePath = $updatesDir . '/' . $pkg;
        if (!file_exists($filePath)) {
            // Defensive safeguard: skip release record if physical package file is missing on disk
            continue;
        }
    }
    $latestRelease = $candidate;
    break;
}

if (!$latestRelease) {
    $resp = [
        'update_available' => false,
        'message'          => 'No published releases found for this system.',
        'checked_at'       => gmdate('c')
    ];
    if (!empty($_GET['debug'])) {
        $resp['debug'] = [
            'deployment_id'         => (int)$deployment['deployment_id'],
            'product_id'            => (int)$deployment['product_id'],
            'product_slug'          => $deployment['product_slug'],
            'clean_current_version' => $cleanCurrentVer
        ];
    }
    echo json_encode($resp, JSON_UNESCAPED_SLASHES);
    exit;
}

$cleanLatestVer = ltrim((string)$latestRelease['version'], 'vV');

// 8. SemVer Comparison: is Latest > Current?
$isNewer = version_compare($cleanLatestVer, $cleanCurrentVer, '>');

// Auto-detect Host scheme, domain, and sub-path for the download link.
// SPECTRAOPS_PUBLIC_URL env var overrides everything (recommended for production).
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

$publicBaseUrl = rtrim((string)(getenv('SPECTRAOPS_PUBLIC_URL') ?: ''), '/');
if (empty($publicBaseUrl)) {
    // Derive the base URL from the physical path of this script relative to DOCUMENT_ROOT.
    // e.g. /SPECTRAOPS/public/api/v1/updates/check.php → /SPECTRAOPS/public
    $docRoot   = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $scriptDir = dirname(__FILE__);
    // Normalize separators and find how many levels up "public/" sits
    $scriptDir = str_replace('\\', '/', $scriptDir);
    $docRoot   = str_replace('\\', '/', $docRoot);
    $publicDir = str_replace('\\', '/', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
    $subPath   = str_replace($docRoot, '', $publicDir);
    $subPath   = '/' . ltrim(str_replace('\\', '/', $subPath), '/');
    $publicBaseUrl = "{$scheme}://{$host}" . rtrim($subPath, '/');
}
$downloadUrl = $publicBaseUrl . '/downloads/updates/' . rawurlencode((string)$latestRelease['package_filename']);

$checksum = (string)$latestRelease['package_checksum'];
$signature = '';
try {
    if (class_exists(UpdateSigner::class)) {
        $signer = new UpdateSigner();
        $signature = $signer->signChecksum($checksum);
    }
} catch (\Throwable $sigEx) {
    error_log("[Update Check] Warning: Unable to sign release checksum: " . $sigEx->getMessage());
}

if ($isNewer) {
    // Parse SemVer parts: [MAJOR, MINOR, PATCH]
    $currParts = array_map('intval', explode('.', $cleanCurrentVer));
    $lateParts = array_map('intval', explode('.', $cleanLatestVer));

    // Pad with zeroes if missing parts
    while (count($currParts) < 3) $currParts[] = 0;
    while (count($lateParts) < 3) $lateParts[] = 0;

    // Check if MAJOR or MINOR bumped
    $isMajorOrMinor = ($lateParts[0] > $currParts[0]) || ($lateParts[1] > $currParts[1]);

    $updateType = !empty($latestRelease['release_type']) ? (string)$latestRelease['release_type'] : ($isMajorOrMinor ? 'major' : 'patch');
    $isSilentPatch = isset($latestRelease['is_silent_patch']) ? (bool)$latestRelease['is_silent_patch'] : (!$isMajorOrMinor);
    $requiresManualApproval = !$isSilentPatch;

    $response = [
        'update_available'         => true,
        'update_type'              => $updateType,            // 'patch' | 'minor' | 'major'
        'is_silent_patch'          => $isSilentPatch,
        'requires_manual_approval' => $requiresManualApproval,
        'latest_version'           => $cleanLatestVer,
        'current_version'          => $cleanCurrentVer,
        'changelog'                => $latestRelease['changelog'] ?? 'Routine stability release.',
        'download_url'             => $downloadUrl,
        'checksum'                 => $latestRelease['package_checksum'],
        'package_checksum'         => $latestRelease['package_checksum'],
        'signature'                => $signature,
        'resolves_error_id'        => !empty($latestRelease['resolves_error_id']) ? (int)$latestRelease['resolves_error_id'] : null,
        'released_at'              => $latestRelease['released_at'],
        'release'                  => [
            'version'                  => $cleanLatestVer,
            'latest_version'           => $cleanLatestVer,
            'update_type'              => $updateType,
            'is_silent_patch'          => $isSilentPatch,
            'requires_manual_approval' => $requiresManualApproval,
            'download_url'             => $downloadUrl,
            'checksum'                 => $latestRelease['package_checksum'],
            'package_checksum'         => $latestRelease['package_checksum'],
            'signature'                => $signature,
            'resolves_error_id'        => !empty($latestRelease['resolves_error_id']) ? (int)$latestRelease['resolves_error_id'] : null,
            'released_at'              => $latestRelease['released_at']
        ]
    ];
} else {
    $response = [
        'update_available' => false,
        'latest_version'   => $cleanLatestVer,
        'current_version'  => $cleanCurrentVer,
        'message'          => "System is running the latest release (v{$cleanCurrentVer})."
    ];
}

// 9. Verbose Debug Output if ?debug=1 is requested
if (!empty($_GET['debug'])) {
    $response['debug'] = [
        'deployment_id'         => (int)$deployment['deployment_id'],
        'product_id'            => (int)$deployment['product_id'],
        'product_slug'          => $deployment['product_slug'],
        'raw_current_version'   => $rawCurrentVer,
        'clean_current_version' => $cleanCurrentVer,
        'raw_latest_version'    => $latestRelease['version'],
        'clean_latest_version'  => $cleanLatestVer,
        'is_newer'              => $isNewer,
        'token_auth_channel'    => !empty($authHeader) ? 'authorization_header' : (!empty($_SERVER['HTTP_X_API_KEY']) ? 'x_api_key_header' : 'payload_body'),
        'server_time_utc'       => gmdate('c')
    ];
}

http_response_code(200);
echo json_encode($response, JSON_UNESCAPED_SLASHES);
exit;
