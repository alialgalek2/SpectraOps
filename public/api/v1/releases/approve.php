<?php
declare(strict_types=1);

/**
 * SpectraOps Release Approval & Artifact Publishing Endpoint
 * 
 * POST /api/v1/releases/approve.php
 * 
 * Approves an AI-generated hotfix artifact from staging, promotes it to the
 * public updates directory, records the release in central_releases, updates
 * product latest_version, and marks the associated error log as patch_pending.
 */

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/json; charset=UTF-8');

require_once dirname(__DIR__, 4) . '/src/Database.php';

use FleetHub\Database;

// Enforce POST method
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error'   => 'Method Not Allowed. Only POST requests are accepted.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 1. Session Setup & Admin Authorization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// Parse Input (supports application/json, php://stdin, and application/x-www-form-urlencoded / $_POST)
$rawInput = file_get_contents('php://input');
if ($rawInput === '' || $rawInput === false) {
    if (PHP_SAPI === 'cli' || empty($_SERVER['REQUEST_METHOD'])) {
        $rawInput = @file_get_contents('php://stdin');
    }
}
$jsonInput = json_decode((string)$rawInput, true);
$input = (is_array($jsonInput) && !empty($jsonInput)) ? $jsonInput : (!empty($_POST) ? $_POST : (is_array($jsonInput) ? $jsonInput : []));

// Check Authorization: Session Admin OR API Bearer Token
$isSessionAuthed = !empty($_SESSION['fleet_user_id']) || !empty($_SESSION['fleet_admin_logged']);

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (empty($authHeader) && function_exists('getallheaders')) {
    $all = getallheaders();
    $authHeader = $all['Authorization'] ?? ($all['authorization'] ?? '');
}
$bearerToken = '';
if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
    $bearerToken = trim($matches[1]);
}

$isApiAuthed = false;
if ($bearerToken !== '') {
    $adminKey = (string)(getenv('FLEET_SECRET_KEY') ?: (getenv('ADMIN_API_KEY') ?: ''));
    if ($adminKey !== '' && hash_equals($adminKey, $bearerToken)) {
        $isApiAuthed = true;
    }
}

if (!$isSessionAuthed && !$isApiAuthed) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized. Admin operator session or valid authorization token required.'
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// CSRF Validation (Enforced for session-authenticated browser requests)
if ($isSessionAuthed && !$isApiAuthed) {
    $sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
    $providedCsrf = (string)($input['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));

    if ($sessionCsrf === '' || $providedCsrf === '' || !hash_equals($sessionCsrf, $providedCsrf)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Forbidden. Invalid or missing CSRF token.'
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// 2. Validate Request Parameters
$productId = isset($input['product_id']) ? (int)$input['product_id'] : 0;
$version = isset($input['version']) ? trim((string)$input['version']) : '';
$stagingFilename = isset($input['staging_filename']) ? trim((string)$input['staging_filename']) : '';
$resolvesErrorId = !empty($input['resolves_error_id']) ? (int)$input['resolves_error_id'] : null;
$releaseType = strtolower(trim((string)($input['release_type'] ?? 'patch')));
$isSilentPatch = isset($input['is_silent_patch']) ? (bool)$input['is_silent_patch'] : ($releaseType === 'patch');
$notes = trim((string)($input['notes'] ?? ($input['changelog'] ?? '')));

if ($productId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Parameter product_id must be a positive integer.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$cleanVersion = ltrim($version, 'vV');
if ($cleanVersion === '' || !preg_match('/^\d+(\.\d+)*(-[0-9A-Za-z.-]+)?$/', $cleanVersion)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Parameter version must be a valid SemVer string (e.g. 1.0.5).'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($stagingFilename === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Parameter staging_filename is required.'], JSON_UNESCAPED_SLASHES);
    exit;
}

if (!in_array($releaseType, ['patch', 'minor', 'major'], true)) {
    $releaseType = 'patch';
}

// 3. Database Connection
try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()], JSON_UNESCAPED_SLASHES);
    exit;
}

// 4. Product Lookup & Verification
$stmtProd = $pdo->prepare("SELECT id, product_name, product_slug, latest_version FROM central_products WHERE id = ?");
$stmtProd->execute([$productId]);
$product = $stmtProd->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Target product ID {$productId} not found."], JSON_UNESCAPED_SLASHES);
    exit;
}

$productSlug = (string)$product['product_slug'];
$currentLatestVersion = ltrim((string)($product['latest_version'] ?? '0.0.0'), 'vV');

// 5. Version Collision & Progression Checks
// Ensure version is strictly greater than latest_version
if ($currentLatestVersion !== '0.0.0' && version_compare($cleanVersion, $currentLatestVersion, '<=')) {
    http_response_code(422);
    echo json_encode([
        'success'        => false,
        'error'          => "Version [{$cleanVersion}] must be strictly greater than current latest version [{$currentLatestVersion}].",
        'latest_version' => $currentLatestVersion,
        'given_version'  => $cleanVersion
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Ensure no active release exists with the same (product_id, version)
$stmtCheckRel = $pdo->prepare("SELECT id FROM central_releases WHERE product_id = ? AND version = ? AND is_active = 1");
$stmtCheckRel->execute([$productId, $cleanVersion]);
if ($stmtCheckRel->fetch()) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'error'   => "An active release with version [{$cleanVersion}] already exists for product [{$productSlug}]."
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 6. Artifact Verification (Directory Traversal Prevention & Checksum)
$safeFilename = basename($stagingFilename);
if ($safeFilename === '' || !str_ends_with(strtolower($safeFilename), '.zip')) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'Invalid staging filename. Archive must be a .zip file.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$appRoot = dirname(__DIR__, 4);
$primaryStagingPath = $appRoot . "/storage/hotfixes/{$productSlug}/staging/{$safeFilename}";

// Candidate paths for staging discovery
$candidatePaths = [
    $primaryStagingPath,
    $appRoot . "/storage/hotfixes/staging/{$safeFilename}",
    $appRoot . "/public/downloads/updates/{$safeFilename}",
    $appRoot . "/downloads/updates/{$safeFilename}"
];

$stagingPath = null;
foreach ($candidatePaths as $candidate) {
    if (file_exists($candidate) && is_file($candidate) && is_readable($candidate)) {
        $stagingPath = $candidate;
        break;
    }
}

if ($stagingPath === null) {
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'error'   => "Staging archive not found on disk at /storage/hotfixes/{$productSlug}/staging/{$safeFilename}",
        'searched_paths' => $candidatePaths
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$actualHash = hash_file('sha256', $stagingPath);
if ($actualHash === false || strlen($actualHash) !== 64) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to calculate cryptographic SHA-256 hash of staging artifact.'], JSON_UNESCAPED_SLASHES);
    exit;
}

// 7. Artifact Promotion: Move to /public/downloads/updates/{product_slug}-v{version}.zip
$publicFilename = "{$productSlug}-v{$cleanVersion}.zip";
$updatesDir = $appRoot . '/public/downloads/updates';

if (!is_dir($updatesDir)) {
    if (!@mkdir($updatesDir, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => "Failed to create publication directory: {$updatesDir}"], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$finalPackagePath = $updatesDir . '/' . $publicFilename;

// Move or copy staging file to final destination atomically
if (realpath($stagingPath) !== realpath($finalPackagePath)) {
    $promoted = @rename($stagingPath, $finalPackagePath);
    if (!$promoted) {
        // Fallback for cross-filesystem moves
        if (@copy($stagingPath, $finalPackagePath)) {
            @unlink($stagingPath);
            $promoted = true;
        }
    }

    if (!$promoted || !file_exists($finalPackagePath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => "Failed to promote artifact from staging to {$finalPackagePath}"], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// Re-verify published package integrity
$publishedHash = hash_file('sha256', $finalPackagePath);
if ($publishedHash === false || !hash_equals($actualHash, $publishedHash)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Checksum verification mismatch after promoting artifact.'], JSON_UNESCAPED_SLASHES);
    exit;
}

// 8. Database Transaction
try {
    $pdo->beginTransaction();

    $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
    $nowExpr = $isSqlite ? "datetime('now')" : "UTC_TIMESTAMP()";

    // Insert into central_releases
    $stmtIns = $pdo->prepare("
        INSERT INTO central_releases (
            product_id, version, release_type, is_silent_patch, changelog,
            resolves_error_id, package_filename, package_checksum, is_active, released_at
        ) VALUES (
            :product_id, :version, :release_type, :is_silent_patch, :changelog,
            :resolves_error_id, :package_filename, :package_checksum, 1, {$nowExpr}
        )
    ");

    $stmtIns->execute([
        ':product_id'        => $productId,
        ':version'           => $cleanVersion,
        ':release_type'      => $releaseType,
        ':is_silent_patch'   => $isSilentPatch ? 1 : 0,
        ':changelog'         => ($notes !== '') ? $notes : "Hotfix release v{$cleanVersion}",
        ':resolves_error_id' => ($resolvesErrorId && $resolvesErrorId > 0) ? $resolvesErrorId : null,
        ':package_filename'  => $publicFilename,
        ':package_checksum'  => $publishedHash
    ]);

    $releaseId = (int)$pdo->lastInsertId();

    // Update central_products.latest_version
    $stmtUpdProd = $pdo->prepare("
        UPDATE central_products 
        SET latest_version = :version, updated_at = {$nowExpr} 
        WHERE id = :id
    ");
    $stmtUpdProd->execute([
        ':version' => $cleanVersion,
        ':id'      => $productId
    ]);

    // If resolves_error_id supplied, update central_error_logs
    if ($resolvesErrorId !== null && $resolvesErrorId > 0) {
        $stmtUpdErr = $pdo->prepare("
            UPDATE central_error_logs
            SET status = 'patch_pending',
                resolved_by_version = :version
            WHERE id = :error_id
        ");
        $stmtUpdErr->execute([
            ':version'  => $cleanVersion,
            ':error_id' => $resolvesErrorId
        ]);
    }

    $pdo->commit();

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database transaction failed: ' . $e->getMessage()
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// 9. Success Response Payload (HTTP 201 Created)
http_response_code(201);
echo json_encode([
    'success'          => true,
    'release_id'       => $releaseId,
    'version'          => $cleanVersion,
    'package_filename' => $publicFilename,
    'package_checksum' => $publishedHash,
    'message'          => 'Release successfully published and staged for OTA delivery.'
], JSON_UNESCAPED_SLASHES);
