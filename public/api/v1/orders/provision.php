<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once dirname(__DIR__, 4) . '/src/Database.php';
require_once dirname(__DIR__, 4) . '/src/Services/VendPulseLicenseService.php';
require_once dirname(__DIR__, 4) . '/src/Services/InstallerDistributionService.php';
require_once dirname(__DIR__, 4) . '/src/Services/OrderProvisioningService.php';

use FleetHub\Database;
use App\Services\OrderProvisioningService;
use App\Services\VendPulseLicenseService;
use App\Services\InstallerDistributionService;

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        echo json_encode([
            'success' => false,
            'error' => 'Method Not Allowed. Use HTTP POST with application/json payload.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Extract Webhook Secret Header (case-insensitive across FastCGI, Apache, Nginx)
    $incomingSecret = null;
    if (!empty($_SERVER['HTTP_X_SPECTRAOPS_WEBHOOK_SECRET'])) {
        $incomingSecret = (string)$_SERVER['HTTP_X_SPECTRAOPS_WEBHOOK_SECRET'];
    } elseif (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $k => $v) {
            if (strcasecmp($k, 'X-SpectraOps-Webhook-Secret') === 0) {
                $incomingSecret = (string)$v;
                break;
            }
        }
    }

    $pdo = Database::getConnection();
    $service = new OrderProvisioningService($pdo);

    if (!$service->verifyWebhookSecret($incomingSecret)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Unauthorized: Invalid or missing X-SpectraOps-Webhook-Secret header.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $rawInput = file_get_contents('php://input');
    $payload = json_decode((string)$rawInput, true);

    if (!is_array($payload)) {
        if (!empty($_POST)) {
            $payload = $_POST;
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Malformed request: Expected valid JSON request body.',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $result = $service->provisionOrder($payload);

    $statusCode = !empty($result['idempotent']) ? 200 : 201;
    http_response_code($statusCode);

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal order provisioning error: ' . $e->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
