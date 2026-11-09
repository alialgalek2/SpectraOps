<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once dirname(__DIR__, 3) . '/src/Database.php';
require_once dirname(__DIR__, 3) . '/src/Services/VendPulseLicenseService.php';

use FleetHub\Database;
use App\Services\VendPulseLicenseService;

try {
    $pdo = Database::getConnection();
    $service = new VendPulseLicenseService($pdo);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? ($_POST['action'] ?? 'status');

    if ($method === 'GET' && ($action === 'public_key' || $action === 'export_key')) {
        $meta = $service->exportPublicKeyMetadata();
        echo json_encode([
            'success' => true,
            'algorithm' => $meta['algorithm'],
            'key_type' => $meta['key_type'],
            'bits' => $meta['bits'],
            'fingerprint' => $meta['public_key_fingerprint'],
            'public_key' => $meta['public_key'],
            'timestamp' => $meta['export_time'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST' && $action === 'verify') {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: $_POST;

        $bundle = $body['bundle'] ?? $body['license'] ?? $body;
        $hwid = $body['hwid'] ?? $body['machine_hwid'] ?? null;

        $result = $service->verifyLicenseBundle($bundle, $hwid);
        http_response_code($result['valid'] ? 200 : 422);
        echo json_encode([
            'success' => $result['valid'],
            'status' => $result['status'],
            'message' => $result['message'] ?? $result['error'],
            'payload' => $result['payload'] ?? null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'POST' && ($action === 'generate' || $action === 'issue')) {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true) ?: $_POST;

        $clientName = trim((string)($body['client_name'] ?? ''));
        if ($clientName === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'client_name is required.'], JSON_PRETTY_PRINT);
            exit;
        }

        $tier = strtoupper((string)($body['tier'] ?? 'SUPERMARKET'));
        $usageMode = strtoupper((string)($body['usage_mode'] ?? $body['mode'] ?? 'RETAIL_SHOP'));
        $counters = (int)($body['max_counters'] ?? 1);
        $hwid = $body['machine_hwid'] ?? null;
        $expires = $body['expires_at'] ?? null;
        $allowedMods = $body['allowed_modules'] ?? null;

        $issued = $service->issueLicense([
            'client_name' => $clientName,
            'tier' => $tier,
            'usage_mode' => $usageMode,
            'max_counters' => $counters,
            'machine_hwid' => $hwid,
            'expires_at' => $expires,
            'allowed_modules' => $allowedMods,
        ]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'license' => $issued,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($method === 'GET' && $action === 'list') {
        $search = $_GET['search'] ?? null;
        $tier = $_GET['tier'] ?? null;
        $usageMode = $_GET['usage_mode'] ?? $_GET['mode'] ?? null;
        $status = $_GET['status'] ?? null;

        $list = $service->listLicenses($search, $tier, $status, $usageMode);
        echo json_encode([
            'success' => true,
            'count' => count($list),
            'licenses' => $list,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Default status & info
    $stats = $service->getStatistics();
    echo json_encode([
        'success' => true,
        'service' => 'VendPulse Offline Cryptographic Licensing Service',
        'issuer' => VendPulseLicenseService::ISSUER,
        'algorithm' => VendPulseLicenseService::ALGORITHM,
        'statistics' => $stats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
