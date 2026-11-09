<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/app.php';
require_once __DIR__ . '/../database/Migrator.php';

use App\Services\TelemetryReporter;
use App\Services\SystemUpdater;
use Database\Migrator;

if (!headers_sent()) {
    header('Content-Type: application/json');
}

// Helper for safe response code
function safeResponseCode(int $code): void {
    if (!headers_sent()) {
        http_response_code($code);
    }
}

// Only allow Super Admins / System Administrators directly via Auth::hasRole (bypass in CLI environment)
if (PHP_SAPI !== 'cli') {
    $hasAdminRole = (class_exists('Auth') && (Auth::hasRole('admin') || Auth::hasRole('super_admin')))
        || in_array(strtolower((string)($_SESSION['user_role'] ?? $_SESSION['role'] ?? '')), ['admin', 'super_admin'], true);

    if (!$hasAdminRole) {
        safeResponseCode(403);
        echo json_encode(['status' => 'error', 'success' => false, 'error' => 'Unauthorized access. Super Administrator privileges required.']);
        exit;
    }
}

// Parse input from JSON payload or standard query/form variables
$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody ?: '', true) ?? [];
$action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? 'check');

$hubUrl = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: 'https://support.yourcentraldomain.com');
$apiKey = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: '');
$appVer = getenv('APP_VERSION') ?: '2.5.0';

$reporter = new TelemetryReporter($hubUrl, $apiKey, 'prism-edu', $appVer);

/**
 * Semver patch helper
 */
function isPatchRelease(string $current, string $candidate): bool {
    $cur = ltrim(trim($current), 'vV');
    $cand = ltrim(trim($candidate), 'vV');
    $curParts = explode('.', $cur);
    $candParts = explode('.', $cand);
    if (count($curParts) < 3 || count($candParts) < 3) return false;
    return ((int)$candParts[0] === (int)$curParts[0] 
        && (int)$candParts[1] === (int)$curParts[1] 
        && (int)$candParts[2] > (int)$curParts[2]);
}

// ACTION: Check for available updates
if ($action === 'check') {
    try {
        $updateData = $reporter->checkForUpdates();
        if ($updateData && !empty($updateData['update_available'])) {
            $latestVer = (string)$updateData['latest_version'];
            echo json_encode([
                'status'          => 'update_available',
                'success'         => true,
                'current_version' => $appVer,
                'latest_version'  => $latestVer,
                'is_patch'        => isPatchRelease($appVer, $latestVer),
                'changelog'       => $updateData['changelog'] ?? '',
                'checksum'        => $updateData['checksum'] ?? '',
                'download_url'    => $updateData['download_url'] ?? '',
                'checked_at'      => date('Y-m-d H:i:s')
            ]);
        } else {
            echo json_encode([
                'status'          => 'up_to_date',
                'success'         => true,
                'current_version' => $appVer,
                'message'         => 'Your system is running the latest version.',
                'checked_at'      => date('Y-m-d H:i:s')
            ]);
        }
    } catch (\Throwable $e) {
        safeResponseCode(500);
        echo json_encode([
            'status'  => 'error',
            'success' => false,
            'error'   => 'Failed to query update channel: ' . $e->getMessage()
        ]);
    }
    exit;
}

// ACTION: Apply zero-downtime update
if ($action === 'apply' && ($_SERVER['REQUEST_METHOD'] === 'POST' || PHP_SAPI === 'cli')) {
    $downloadUrl = $input['download_url'] ?? $_POST['download_url'] ?? '';
    $checksum    = $input['checksum'] ?? $_POST['checksum'] ?? '';
    $newVersion  = $input['version'] ?? $_POST['version'] ?? '';

    // If manual parameters were not provided, fetch fresh verified manifest server-side
    if (empty($downloadUrl) || empty($checksum) || empty($newVersion)) {
        $updateData = $reporter->checkForUpdates();
        if (!$updateData || empty($updateData['update_available']) || empty($updateData['download_url']) || empty($updateData['checksum'])) {
            safeResponseCode(400);
            echo json_encode([
                'status'  => 'error',
                'success' => false,
                'error'   => 'No verified update manifest available from official update server.'
            ]);
            exit;
        }
        $downloadUrl = (string)$updateData['download_url'];
        $checksum    = (string)$updateData['checksum'];
        $newVersion  = (string)$updateData['latest_version'];
    }

    $appRoot = dirname(__DIR__);
    $updater = new SystemUpdater($appRoot);

    // Apply atomic zero-downtime update
    $result = $updater->applyUpdate($downloadUrl, $checksum, $newVersion);

    if ($result['success']) {
        // Run pending database migrations
        $migResult = ['applied_count' => 0, 'applied' => []];
        try {
            $migrator = new Migrator();
            $migResult = $migrator->runPendingMigrations();
        } catch (\Throwable $e) {
            error_log("[AdminUpdateController] Post-upgrade migration error: " . $e->getMessage());
        }

        // Atomically update APP_VERSION in local .env
        $envPath = $appRoot . '/.env';
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
            $envContent = preg_replace('/^APP_VERSION=.*/m', 'APP_VERSION="' . $newVersion . '"', $envContent);
            $tmpPath = $envPath . '.tmp.' . bin2hex(random_bytes(6));
            if (file_put_contents($tmpPath, $envContent, LOCK_EX) !== false) {
                @rename($tmpPath, $envPath);
            }
        }

        // Emit immediate upgraded heartbeat back to SpectraOps
        try {
            $reporter->sendHeartbeat([
                'version'     => $newVersion,
                'status_note' => "Upgraded to v{$newVersion} via Super Admin Console"
            ]);
        } catch (\Throwable $e) {
            error_log("[AdminUpdateController] Heartbeat notification error: " . $e->getMessage());
        }

        echo json_encode([
            'status'           => 'success',
            'success'          => true,
            'message'          => $result['message'] ?? "Successfully upgraded to version {$newVersion}.",
            'previous_version' => $appVer,
            'version'          => $newVersion,
            'migrations'       => $migResult,
            'applied_at'       => date('Y-m-d H:i:s')
        ]);
    } else {
        safeResponseCode(500);
        echo json_encode([
            'status'  => 'error',
            'success' => false,
            'error'   => $result['error'] ?? 'Deployment failed during in-place swap.'
        ]);
    }
    exit;
}

safeResponseCode(400);
echo json_encode(['status' => 'error', 'success' => false, 'error' => "Unsupported action '{$action}'."]);
exit;
