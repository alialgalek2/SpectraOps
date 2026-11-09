<?php
/**
 * API Controller: Telemetry Dispatcher
 * Triggered via AJAX on administrative dashboard or background scheduled task.
 */

require_once __DIR__ . '/../core/init.php';

header('Content-Type: application/json; charset=UTF-8');

// Access Control: Admin / School Admin Only (or CLI cron)
$isCron = php_sapi_name() === 'cli';
if (!$isCron && !Auth::hasRole('admin') && !Auth::hasRole('school_admin')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access. Administrator permissions required.']);
    exit();
}

$batchLimit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 100);
$includePrevious = filter_var($_GET['include_previous'] ?? $_POST['include_previous'] ?? false, FILTER_VALIDATE_BOOLEAN);

$result = Telemetry::dispatchPendingLogs($batchLimit, $includePrevious);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
