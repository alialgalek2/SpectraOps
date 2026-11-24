<?php
declare(strict_types=1);

while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed. Use POST.'], JSON_UNESCAPED_SLASHES);
    exit;
}

require_once dirname(__DIR__, 3) . '/src/Database.php';
require_once dirname(__DIR__, 3) . '/src/Services/HandshakeService.php';

try {
    $pdo = \FleetHub\Database::getConnection();
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database unavailable.'], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$payload = (!empty($raw) ? json_decode($raw, true) : null);
if (!is_array($payload)) {
    $payload = !empty($_POST) ? $_POST : [];
}

$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$handler = new \FleetHub\Services\HandshakeService($pdo);
[$statusCode, $response] = $handler->handle($payload, $headers, 'handshake');

http_response_code($statusCode);
echo json_encode($response, JSON_UNESCAPED_SLASHES);
