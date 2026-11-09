<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'");
header('X-Frame-Options: DENY');

require_once dirname(__DIR__, 3) . '/src/Database.php';
require_once dirname(__DIR__, 3) . '/src/FleetService.php';
require_once dirname(__DIR__, 3) . '/src/OperatorAuth.php';

use FleetHub\Database;
use FleetHub\FleetService;
use FleetHub\OperatorAuth;

$action = $_GET['action'] ?? 'overview';
$method = $_SERVER['REQUEST_METHOD'];

// This endpoint is for the operator console only. Client machines use the
// token-authenticated telemetry and update endpoints instead.
OperatorAuth::requireOperatorApi($method !== 'GET');

try {
    $service = new FleetService();

    switch ($action) {
        case 'overview':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $overview = $service->getFleetOverview();
            echo json_encode(['status' => 'success', 'data' => $overview]);
            break;

        case 'deployments':
            if ($method === 'GET') {
                $productId = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : null;
                $status = isset($_GET['status']) ? (string)$_GET['status'] : null;
                $search = isset($_GET['search']) ? (string)$_GET['search'] : null;

                $deployments = $service->getDeployments($productId, $status, $search);
                echo json_encode(['status' => 'success', 'data' => $deployments]);
            } elseif ($method === 'POST') {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true) ?? $_POST;

                $subAction = $input['sub_action'] ?? '';
                if ($subAction === 'delete') {
                    $depId = (int)($input['deployment_id'] ?? $_GET['deployment_id'] ?? 0);
                    if ($depId <= 0) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Valid deployment_id is required.']);
                        break;
                    }
                    $deleted = $service->deleteDeployment($depId);
                    if ($deleted) {
                        echo json_encode(['status' => 'success', 'message' => 'Deployment system removed successfully.']);
                    } else {
                        http_response_code(404);
                        echo json_encode(['status' => 'error', 'message' => 'Deployment not found or already removed.']);
                    }
                    break;
                }

                $productId = (int)($input['product_id'] ?? 0);
                $clientName = trim((string)($input['client_name'] ?? ''));
                $domain = trim((string)($input['deployment_domain'] ?? ''));
                $env = trim((string)($input['environment'] ?? 'production'));
                $version = trim((string)($input['current_version'] ?? '1.0.0'));

                if ($productId <= 0 || $clientName === '' || $domain === '') {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Product, Client Name, and Deployment Domain are required.']);
                    break;
                }

                $newDep = $service->registerDeployment($productId, $clientName, $domain, $env, $version);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Deployment registered successfully. Store this token securely; it cannot be recovered.',
                    'data' => $newDep
                ]);
            } elseif ($method === 'DELETE') {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true) ?? [];
                $depId = (int)($input['deployment_id'] ?? $_GET['deployment_id'] ?? 0);

                if ($depId <= 0) {
                    http_response_code(400);
                    echo json_encode(['status' => 'error', 'message' => 'Valid deployment_id is required.']);
                    break;
                }

                $deleted = $service->deleteDeployment($depId);
                if ($deleted) {
                    echo json_encode(['status' => 'success', 'message' => 'Deployment system removed successfully.']);
                } else {
                    http_response_code(404);
                    echo json_encode(['status' => 'error', 'message' => 'Deployment not found or already removed.']);
                }
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;

        case 'errors':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $depId = isset($_GET['deployment_id']) && $_GET['deployment_id'] !== '' ? (int)$_GET['deployment_id'] : null;
            $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

            $errors = $service->getErrorLogs($depId, $limit);
            echo json_encode(['status' => 'success', 'data' => $errors]);
            break;

        case 'metrics':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $depId = (int)($_GET['deployment_id'] ?? 0);
            if ($depId <= 0) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Valid deployment_id is required.']);
                exit;
            }

            $metrics = $service->getDeploymentMetrics($depId, 24);
            echo json_encode(['status' => 'success', 'data' => $metrics]);
            break;

        case 'check_health':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $report = $service->runFleetMonitorCheck();
            echo json_encode(['status' => 'success', 'data' => $report]);
            break;

        case 'pair':
            if ($method !== 'POST') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $raw = file_get_contents('php://input');
            $input = json_decode($raw, true) ?? $_POST;

            $productId = (int)($input['product_id'] ?? 0);
            $clientName = trim((string)($input['client_name'] ?? ''));
            $domain = trim((string)($input['deployment_domain'] ?? ''));
            $apiKey = trim((string)($input['api_key'] ?? ''));
            $env = trim((string)($input['environment'] ?? 'production'));
            $version = trim((string)($input['current_version'] ?? '1.0.0'));

            if ($productId <= 0 || $clientName === '' || $domain === '' || $apiKey === '') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Product, Client Name, Deployment Domain, and Client API Key are required.']);
                exit;
            }

            $paired = $service->pairDeploymentWithKey($productId, $clientName, $domain, $apiKey, $env, $version);
            echo json_encode([
                'status' => 'success',
                'message' => 'Client deployment paired successfully.',
                'data' => $paired
            ]);
            break;

        case 'releases':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $productId = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : null;
            $releases = $service->getReleases($productId);
            echo json_encode(['status' => 'success', 'data' => $releases]);
            break;

        case 'check_update':
            if ($method !== 'GET') {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
                exit;
            }
            $slug = trim((string)($_GET['product_slug'] ?? 'prism-edu'));
            $curVer = trim((string)($_GET['current_version'] ?? '1.0.0'));

            $latest = $service->getLatestReleaseBySlug($slug);
            if (!$latest) {
                echo json_encode([
                    'status' => 'success',
                    'has_update' => false,
                    'current_version' => $curVer,
                    'message' => 'No active release found for product'
                ]);
                exit;
            }

            $hasUpdate = version_compare($latest['version'], $curVer, '>');
            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $downloadUrl = "{$scheme}://{$host}/downloads/updates/" . rawurlencode($latest['package_filename']);

            echo json_encode([
                'status' => 'success',
                'has_update' => $hasUpdate,
                'current_version' => $curVer,
                'latest_version' => $latest['version'],
                'package_filename' => $latest['package_filename'],
                'package_checksum' => $latest['package_checksum'],
                'package_url' => $downloadUrl,
                'changelog' => $latest['changelog'],
                'released_at' => $latest['released_at'],
            ]);
            break;

        case 'licenses':
            require_once dirname(__DIR__, 3) . '/src/Services/LicenseManager.php';
            $pdo = Database::getConnection();
            $licMgr = new \App\Services\LicenseManager($pdo);

            if ($method === 'GET') {
                $productId = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? (int)$_GET['product_id'] : null;
                $licenses = $licMgr->listLicenses($productId);
                echo json_encode(['status' => 'success', 'data' => $licenses]);
            } elseif ($method === 'POST') {
                $raw = file_get_contents('php://input');
                $input = json_decode($raw, true) ?? $_POST;
                $subAction = trim((string)($input['sub_action'] ?? $input['action'] ?? 'issue'));
                $licenseId = (int)($input['license_id'] ?? 0);

                if ($subAction === 'suspend') {
                    if ($licenseId <= 0) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Valid license_id is required.']);
                        exit;
                    }
                    $reason = trim((string)($input['reason'] ?? ''));
                    $licMgr->suspendLicense($licenseId, $reason);
                    echo json_encode(['status' => 'success', 'message' => 'License and bound deployment suspended successfully.']);
                } elseif ($subAction === 'revoke') {
                    if ($licenseId <= 0) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Valid license_id is required.']);
                        exit;
                    }
                    $reason = trim((string)($input['reason'] ?? ''));
                    $licMgr->revokeLicense($licenseId, $reason);
                    echo json_encode(['status' => 'success', 'message' => 'License and bound deployment revoked permanently.']);
                } elseif ($subAction === 'renew') {
                    if ($licenseId <= 0) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Valid license_id is required.']);
                        exit;
                    }
                    $expiresAt = !empty($input['expires_at']) ? trim((string)$input['expires_at']) : null;
                    $type = !empty($input['license_type']) ? trim((string)$input['license_type']) : null;
                    $reason = trim((string)($input['reason'] ?? ''));
                    $renewed = $licMgr->renewLicense($licenseId, $expiresAt, $type, $reason);
                    echo json_encode(['status' => 'success', 'message' => 'License and deployment renewed successfully.', 'data' => $renewed]);
                } elseif ($subAction === 'reactivate') {
                    if ($licenseId <= 0) {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Valid license_id is required.']);
                        exit;
                    }
                    $licMgr->reactivateLicense($licenseId);
                    echo json_encode(['status' => 'success', 'message' => 'License and deployment reactivated successfully.']);
                } else {
                    $productId = (int)($input['product_id'] ?? 0);
                    $clientName = trim((string)($input['client_name'] ?? ''));
                    $clientEmail = trim((string)($input['client_email'] ?? ''));
                    $type = (string)($input['license_type'] ?? 'perpetual');
                    $expiresAt = !empty($input['expires_at']) ? (string)$input['expires_at'] : null;
                    $seats = (int)($input['max_seats'] ?? 0);

                    if ($productId <= 0 || $clientName === '' || $clientEmail === '') {
                        http_response_code(400);
                        echo json_encode(['status' => 'error', 'message' => 'Product, client name, and email are required.']);
                        exit;
                    }

                    $issued = $licMgr->issueLicense($productId, $clientName, $clientEmail, $type, $expiresAt, $seats);
                    echo json_encode(['status' => 'success', 'data' => $issued]);
                }
            } else {
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
            }
            break;


        default:
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Endpoint action not found']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
