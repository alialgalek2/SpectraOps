<?php
declare(strict_types=1);

// Bypass verification for CLI executions (migrations, cron jobs, tests)
if (php_sapi_name() === 'cli') {
    return;
}

$licenseLockFile = dirname(__DIR__) . '/storage/license.lock';

// Bypass verification during activation itself or for static assets
$currentUri = $_SERVER['REQUEST_URI'] ?? '';
if (str_contains($currentUri, 'activation.php') || str_contains($currentUri, '/assets/') || str_contains($currentUri, '/uploads/')) {
    return;
}

// Determine relative base URL for activation redirect
$base = '';
if (defined('BASE_URL')) {
    $base = BASE_URL;
} elseif (isset($_SERVER['SCRIPT_NAME']) && str_contains($_SERVER['SCRIPT_NAME'], '/school_system')) {
    $base = '/school_system';
}
$activationUrl = ($base ? rtrim($base, '/') : '') . '/activation.php';

if (!file_exists($licenseLockFile)) {
    header('Location: ' . $activationUrl);
    exit;
}

$lockData = json_decode((string)file_get_contents($licenseLockFile), true);

if (empty($lockData['license_key']) || empty($lockData['lease_token'])) {
    header('Location: ' . $activationUrl);
    exit;
}
