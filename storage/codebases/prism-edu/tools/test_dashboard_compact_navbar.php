<?php
/**
 * Test Suite: Dashboard Android Compact View & WebView Validation
 */

echo "=== Verifying Dashboard Android Compact View ===\n";

$passCount = 0;
$failCount = 0;

function assert_check(string $desc, bool $cond): void {
    global $passCount, $failCount;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $passCount++;
    } else {
        echo "  [FAIL] $desc\n";
        $failCount++;
    }
}

$cookieFile = __DIR__ . '/cookie_android_test.txt';
if (file_exists($cookieFile)) @unlink($cookieFile);

// 1. Get login page with CSRF token
$loginHtml = shell_exec('curl.exe -s -c ' . escapeshellarg($cookieFile) . ' -b ' . escapeshellarg($cookieFile) . ' http://localhost:8080/school_system/login.php');
if ($loginHtml && preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginHtml, $m)) {
    $token = $m[1];
    $postData = 'identifier=admin&password=admin123&csrf_token=' . urlencode($token);
    exec('curl.exe -s -c ' . escapeshellarg($cookieFile) . ' -b ' . escapeshellarg($cookieFile) . ' -d ' . escapeshellarg($postData) . ' http://localhost:8080/school_system/login.php');
}

// 2. Request dashboard with Android User-Agent
$appCmd = 'curl.exe -s -b ' . escapeshellarg($cookieFile) . ' -H "User-Agent: Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36 PrismApp/2.5.0" "http://localhost:8080/school_system/dashboard.php"';
$htmlApp = shell_exec($appCmd) ?? '';

assert_check("Dashboard contains prism-app-topbar on Android WebView", strpos($htmlApp, 'class="prism-app-topbar') !== false);
assert_check("Dashboard contains userSheet on Android WebView", strpos($htmlApp, 'id="userSheet"') !== false);
assert_check("Dashboard contains app-compact-search-box on Android", strpos($htmlApp, 'id="appCompactSearchBox"') !== false);
assert_check("Dashboard does NOT render desktop navbar-custom on Android", strpos($htmlApp, '<header class="navbar-custom') === false);
assert_check("Dashboard contains geometric vector SVG brand mark", strpos($htmlApp, 'class="app-brand-svg"') !== false);
assert_check("Sheet contains user avatar image element", strpos($htmlApp, 'class="sheet-avatar-img"') !== false);
assert_check("Sheet contains Sign Out with logout button ID", strpos($htmlApp, 'id="appLogoutBtn"') !== false);

// 3. Request dashboard with desktop User-Agent and ?view=desktop
$deskCmd = 'curl.exe -s -b ' . escapeshellarg($cookieFile) . ' -H "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)" "http://localhost:8080/school_system/dashboard.php?view=desktop"';
$htmlDesk = shell_exec($deskCmd) ?? '';

assert_check("Desktop view contains navbar-custom", strpos($htmlDesk, 'class="navbar-custom') !== false);
assert_check("Desktop view does NOT render prism-app-topbar", strpos($htmlDesk, 'class="prism-app-topbar') === false);

echo "\nDashboard Compact View Results: $passCount passed, $failCount failed.\n";
if ($failCount > 0) exit(1);
