<?php
/**
 * Master Verification Suite: Dynamic Android APK System Link Synchronization
 */
require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../services/ApkBuilderService.php';

use App\Services\ApkBuilderService;

$builder = new ApkBuilderService();

echo "========================================================\n";
echo "   MASTER VERIFICATION: ANDROID APK DYNAMIC SYNC        \n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assertTest(string $desc, bool $condition, string $detail = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $desc\n";
        $passed++;
    } else {
        echo "[FAIL] $desc " . ($detail ? "($detail)" : "") . "\n";
        $failed++;
    }
}

// --- 1. SYSTEM URL RESOLUTION & NORMALIZATION ---
echo "--- 1. SYSTEM URL RESOLUTION & NORMALIZATION ---\n";
$lanIp = ApkBuilderService::getLocalLanIp();
assertTest("getLocalLanIp() resolves host PC LAN IP", !empty($lanIp) && filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4), "Got: " . ($lanIp ?? 'NULL'));

$_SERVER['HTTPS'] = 'off';
$_SERVER['HTTP_HOST'] = 'localhost:8080';
$_SERVER['SERVER_PORT'] = '8080';
$urlLocalhost = ApkBuilderService::detectSystemUrl();
assertTest("detectSystemUrl() substitutes localhost with PC LAN IP ($lanIp)", $urlLocalhost === "http://$lanIp:8080/school_system", "Got: $urlLocalhost");

$_SERVER['HTTP_HOST'] = '127.0.0.1:8080';
$urlLoopback = ApkBuilderService::detectSystemUrl();
assertTest("detectSystemUrl() substitutes 127.0.0.1 with PC LAN IP ($lanIp)", $urlLoopback === "http://$lanIp:8080/school_system", "Got: $urlLoopback");

$_SERVER['HTTP_HOST'] = '192.168.1.30:8080';
$url1 = ApkBuilderService::detectSystemUrl();
assertTest("detectSystemUrl() with direct LAN IP & port 8080", $url1 === 'http://192.168.1.30:8080/school_system', "Got: $url1");

$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = 'portal.myinstitution.edu';
$_SERVER['SERVER_PORT'] = '443';
$url2 = ApkBuilderService::detectSystemUrl();
assertTest("detectSystemUrl() with HTTPS domain preserves domain", $url2 === 'https://portal.myinstitution.edu/school_system', "Got: $url2");

$_SERVER['HTTPS'] = 'off';
unset($_SERVER['HTTPS']);
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
$_SERVER['HTTP_HOST'] = 'cloud.school.org:8443';
$url3 = ApkBuilderService::detectSystemUrl();
assertTest("detectSystemUrl() behind SSL reverse proxy", $url3 === 'https://cloud.school.org:8443/school_system', "Got: $url3");
unset($_SERVER['HTTP_X_FORWARDED_PROTO']);

// --- 2. APK CONFIG EXTRACTION & PARSING ---
echo "\n--- 2. APK CONFIG EXTRACTION & PARSING ---\n";
$config = $builder->getApkConfig();
assertTest("getApkConfig() returns valid array", is_array($config) && !empty($config['server_url']), json_encode($config));
assertTest("getApkConfig() contains app_version", !empty($config['app_version']));

// --- 3. DYNAMIC REGENERATION & MATCHING ENGINE ---
echo "\n--- 3. DYNAMIC REGENERATION & MATCHING ENGINE ---\n";
$targetUrl = 'http://192.168.1.30:8080/school_system';
$syncResult = $builder->ensureApkMatches($targetUrl, 'PRISM EDU');
assertTest("ensureApkMatches() succeeds", $syncResult['success'] === true, $syncResult['message'] ?? '');
assertTest("ensureApkMatches() outputs exact server_url", ($syncResult['server_url'] ?? '') === $targetUrl);

// Test idempotency: second call should be instantaneous cache hit
$t0 = microtime(true);
$cacheHit = $builder->ensureApkMatches($targetUrl, 'PRISM EDU');
$elapsedMs = (microtime(true) - $t0) * 1000;
assertTest("ensureApkMatches() cache hit is fast (< 50ms)", $elapsedMs < 50, "Elapsed: {$elapsedMs}ms");
assertTest("ensureApkMatches() flags already_matched on cache hit", !empty($cacheHit['already_matched']));

// --- 4. CRYPTOGRAPHIC SIGNATURE INTEGRITY ---
echo "\n--- 4. CRYPTOGRAPHIC SIGNATURE INTEGRITY ---\n";
$apksigner = $builder->resolveTool('apksigner');
assertTest("apksigner build tool found on system", !empty($apksigner) && file_exists($apksigner), "Tool: $apksigner");

$apkPath = $builder->getOutputApkPath();
$cmd = sprintf('%s verify --verbose %s 2>&1', escapeshellarg($apksigner), escapeshellarg($apkPath));
exec($cmd, $sigOutput, $sigCode);
$sigText = implode("\n", $sigOutput);
assertTest("apksigner verify exits with code 0", $sigCode === 0, "Exit: $sigCode");
assertTest("Verified using v2 scheme (APK Signature Scheme v2)", str_contains($sigText, 'Verified using v2 scheme (APK Signature Scheme v2): true'));
assertTest("Verified using v3 scheme (APK Signature Scheme v3)", str_contains($sigText, 'Verified using v3 scheme (APK Signature Scheme v3): true'));

// --- 5. LIVE HTTP ENDPOINT PROBES ---
echo "\n--- 5. LIVE HTTP ENDPOINT PROBES ---\n";
$context = stream_context_create([
    'http' => [
        'ignore_errors' => true,
        'timeout' => 5
    ]
]);

// 5.1 Probe download_apk.php?action=check via localhost
$checkUrl = "http://192.168.1.30:8080/school_system/download_apk.php?action=check";
$checkJson = file_get_contents($checkUrl, false, $context);
$checkData = json_decode($checkJson, true);
assertTest("download_apk.php?action=check returns valid JSON", is_array($checkData) && ($checkData['status'] ?? '') === 'ok');
assertTest("Diagnostic API confirms matches_system_url is true", ($checkData['matches_system_url'] ?? false) === true, "matches_system_url: " . var_export($checkData['matches_system_url'] ?? null, true));
assertTest("Diagnostic API confirms detected_system_url", ($checkData['detected_system_url'] ?? '') === 'http://192.168.1.30:8080/school_system');

// 5.2 Probe binary download HEAD request
$headContext = stream_context_create([
    'http' => [
        'method' => 'HEAD',
        'ignore_errors' => true,
        'timeout' => 5
    ]
]);
$fp = fopen("http://192.168.1.30:8080/school_system/download_apk.php", 'r', false, $headContext);
$headHeaders = $http_response_header ?? [];
if ($fp) fclose($fp);

$hasApkMime = false;
$hasAttachment = false;
$has200Ok = false;
foreach ($headHeaders as $hdr) {
    if (str_contains($hdr, '200 OK')) $has200Ok = true;
    if (str_contains($hdr, 'application/vnd.android.package-archive')) $hasApkMime = true;
    if (str_contains($hdr, 'PrismEDU-App.apk')) $hasAttachment = true;
}
assertTest("download_apk.php HEAD returns HTTP 200 OK", $has200Ok);
assertTest("download_apk.php delivers APK MIME type header", $hasApkMime);
assertTest("download_apk.php delivers PrismEDU-App.apk attachment header", $hasAttachment);

// 5.3 Verify login page integration
$loginHtml = file_get_contents("http://192.168.1.30:8080/school_system/login.php", false, $context);
assertTest("login.php banner links to download_apk.php", str_contains($loginHtml, 'download_apk.php'));
assertTest("login.php banner displays auto-configured system link badge", str_contains($loginHtml, '192.168.1.30:8080'));

echo "\n========================================================\n";
echo "VERIFICATION SUMMARY: $passed PASSED, $failed FAILED\n";
echo "========================================================\n";
