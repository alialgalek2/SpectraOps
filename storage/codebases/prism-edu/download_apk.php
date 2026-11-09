<?php
/**
 * Dynamic Android APK Distribution & Synchronization Endpoint
 * 
 * Ensures the downloaded mobile application is always pre-configured
 * with the exact matching server URL (protocol, host, port, base path)
 * and institutional branding of the system serving the request.
 */

require_once __DIR__ . '/core/init.php';
require_once __DIR__ . '/services/ApkBuilderService.php';

use App\Services\ApkBuilderService;

try {
    $builder = new ApkBuilderService();
    $systemUrl = ApkBuilderService::detectSystemUrl();

    // 1. Resolve school name from settings, session, or default
    $schoolName = 'PRISM EDU';
    if (!empty($_SESSION['school_name'])) {
        $schoolName = $_SESSION['school_name'];
    } elseif (isset($pdo)) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM school_settings WHERE setting_key = 'school_name' LIMIT 1");
            $val = $stmt ? $stmt->fetchColumn() : null;
            if (!empty($val)) {
                $schoolName = $val;
            }
        } catch (\Throwable $e) {
            // Fall back to default
        }
    }

    // 2. Diagnostics reveal infrastructure and build details; administrators only.
    if (isset($_GET['action']) && $_GET['action'] === 'check') {
        if (!Auth::hasRole(['admin', 'school_admin'])) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Administrator access is required.']);
            exit;
        }
        header('Content-Type: application/json');
        $currentConfig = $builder->getApkConfig();
        $apkPath = $builder->getOutputApkPath();
        $exists = file_exists($apkPath);
        $matches = false;
        if ($exists && $currentConfig) {
            $matches = rtrim($currentConfig['server_url'] ?? '', '/') === rtrim($systemUrl, '/');
        }

        echo json_encode([
            'status'               => 'ok',
            'detected_system_url'  => $systemUrl,
            'school_name'          => $schoolName,
            'apk_exists'           => $exists,
            'apk_path'             => $apkPath,
            'apk_file_size'        => $exists ? filesize($apkPath) : 0,
            'apk_internal_config'  => $currentConfig,
            'matches_system_url'   => $matches,
            'checked_at'           => date('c')
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 3. Ensure the APK on disk matches the active caller system URL
    $syncResult = $builder->ensureApkMatches($systemUrl, $schoolName);
    $outputApk = $builder->getOutputApkPath();

    if (!$syncResult['success'] || !file_exists($outputApk)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Unable to generate mobile application package.\n";
        echo "Error: " . ($syncResult['message'] ?? 'Unknown error') . "\n";
        exit;
    }

    // 4. Stream the signed APK binary to client
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.android.package-archive');
    header('Content-Disposition: attachment; filename="PrismEDU-App.apk"');
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: public, must-revalidate, max-age=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($outputApk));

    readfile($outputApk);
    exit;
} catch (\Throwable $e) {
    error_log('APK download/build error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'status'  => 'error',
        'message' => 'The mobile application package could not be prepared. Please contact an administrator.'
    ]);
    exit;
}
