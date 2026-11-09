<?php
/**
 * System Diagnostics & Health Check API
 */

require_once dirname(__DIR__, 2) . '/core/init.php';

// 1. Verify Diagnostics Token
$expectedToken = getenv('DIAGNOSTICS_TOKEN');
$providedToken = $_SERVER['HTTP_X_DIAGNOSTICS_TOKEN'] ?? $_GET['token'] ?? null;

if (empty($expectedToken) || $providedToken !== $expectedToken) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: Invalid or missing Diagnostics Token']);
    exit;
}

// 2. Perform Checks
$health = [
    'status' => 'ok',
    'timestamp' => date('c'),
    'metrics' => []
];

// DB Latency
try {
    $start = microtime(true);
    $pdo->query("SELECT 1");
    $end = microtime(true);
    $health['metrics']['db_latency_ms'] = round(($end - $start) * 1000, 2);
} catch (Exception $e) {
    $health['status'] = 'degraded';
    $health['metrics']['db_latency_ms'] = -1;
    $health['errors'][] = 'Database connection failed';
}

// Storage Permissions
$uploadPath = BASE_PATH . '/uploads';
$health['metrics']['storage_writable'] = is_dir($uploadPath) && is_writable($uploadPath);
if (!$health['metrics']['storage_writable']) {
    $health['status'] = 'degraded';
    $health['errors'][] = 'Uploads directory is not writable';
}

// OPcache
$health['metrics']['opcache_enabled'] = function_exists('opcache_get_status') && opcache_get_status(false) !== false;

// Disk Space
$freeSpace = disk_free_space(BASE_PATH);
$totalSpace = disk_total_space(BASE_PATH);
if ($freeSpace !== false && $totalSpace !== false) {
    $health['metrics']['disk_free_gb'] = round($freeSpace / 1024 / 1024 / 1024, 2);
    $health['metrics']['disk_total_gb'] = round($totalSpace / 1024 / 1024 / 1024, 2);
    if ($freeSpace / $totalSpace < 0.1) {
        $health['status'] = 'degraded';
        $health['errors'][] = 'Disk space critically low (< 10%)';
    }
} else {
    $health['metrics']['disk_free_gb'] = 'unknown';
}

// Ensure correct response code
if ($health['status'] !== 'ok') {
    http_response_code(503);
}

header('Content-Type: application/json');
echo json_encode($health, JSON_PRETTY_PRINT);
exit;
