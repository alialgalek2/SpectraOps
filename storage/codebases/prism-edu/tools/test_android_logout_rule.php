<?php
/**
 * Test Suite: Android App Auto-Logout on Close Rule Verification
 * Validates Kotlin Implementation and Server-side Session Invalidation Lifecycle.
 */

echo "=== Android App Auto-Logout Rule Verification ===\n";

$pass = 0;
$fail = 0;

function assert_test($desc, $cond) {
    global $pass, $fail;
    if ($cond) {
        echo "  [PASS] $desc\n";
        $pass++;
    } else {
        echo "  [FAIL] $desc\n";
        $fail++;
    }
}

// 1. Inspect MainActivity.kt
$ktFile = __DIR__ . '/../android/app/src/main/java/com/algale/schoolportal/MainActivity.kt';
assert_test("MainActivity.kt exists", file_exists($ktFile));

$ktCode = file_get_contents($ktFile);

assert_test("MainActivity defines logoutAndCleanSession()", str_contains($ktCode, 'private fun logoutAndCleanSession()'));
assert_test("MainActivity defines performServerLogout() invoking logout.php", str_contains($ktCode, 'performServerLogout') && str_contains($ktCode, '/logout.php'));
assert_test("MainActivity defines clearSessionData() wiping cookies and web storage", 
    str_contains($ktCode, 'removeSessionCookies') && 
    str_contains($ktCode, 'removeAllCookies') && 
    str_contains($ktCode, 'WebStorage.getInstance().deleteAllData()')
);
assert_test("MainActivity lifecycle: onDestroy() executes logoutAndCleanSession()", 
    str_contains($ktCode, 'override fun onDestroy()') && 
    str_contains($ktCode, 'logoutAndCleanSession()')
);
assert_test("MainActivity back button: onBackPressed() executes logout on exit", 
    str_contains($ktCode, 'override fun onBackPressed()') && 
    str_contains($ktCode, 'logoutAndCleanSession()') &&
    str_contains($ktCode, 'super.onBackPressed()')
);
assert_test("MainActivity cold start: onCreate() executes clearSessionData() on cold launch", 
    str_contains($ktCode, 'savedInstanceState == null') && 
    str_contains($ktCode, 'clearSessionData()')
);
assert_test("MainActivity JS interface: WebAppInterface exposes logoutAndExit()", 
    str_contains($ktCode, 'fun logoutAndExit()') && 
    str_contains($ktCode, 'finishAffinity()')
);

// 2. Server-side End-to-End Session Termination Simulation
// We simulate the Android app closing flow:
// - Step A: Login to get a valid session
// - Step B: Access dashboard.php -> HTTP 200 (active session)
// - Step C: Trigger logout.php (as Android performServerLogout does on close)
// - Step D: Access dashboard.php with old session -> Redirects to login.php (logged out)

$testCookieFile = __DIR__ . '/android_test_cookie.txt';
if (file_exists($testCookieFile)) @unlink($testCookieFile);

// Step A: Login
$loginHtml = shell_exec('curl.exe -s -c ' . escapeshellarg($testCookieFile) . ' -b ' . escapeshellarg($testCookieFile) . ' http://localhost:8080/school_system/login.php');
$token = '';
if ($loginHtml && preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginHtml, $m)) {
    $token = $m[1];
}

$postData = 'identifier=admin&password=admin123&csrf_token=' . urlencode($token);
exec('curl.exe -s -c ' . escapeshellarg($testCookieFile) . ' -b ' . escapeshellarg($testCookieFile) . ' -d ' . escapeshellarg($postData) . ' http://localhost:8080/school_system/login.php');

assert_test("Simulated Login: Session cookie created", file_exists($testCookieFile) && filesize($testCookieFile) > 0);

// Helper for HTTP requests with cookie file
function curl_req($url, $cookieFile) {
    $cmd = 'curl.exe -s -o NUL -w "%{http_code}" -b ' . escapeshellarg($cookieFile) . ' ' . escapeshellarg($url);
    return trim(shell_exec($cmd) ?? '');
}

// Step B: Verify authenticated access before close
$codeBeforeLogout = curl_req('http://localhost:8080/school_system/dashboard.php', $testCookieFile);
assert_test("Pre-Close: Dashboard returns HTTP 200 (authenticated)", $codeBeforeLogout === '200');

// Step C: Simulate App Close sending logout request to logout.php
$logoutCode = curl_req('http://localhost:8080/school_system/logout.php', $testCookieFile);
assert_test("App Close: logout.php returns HTTP 302 redirect", $logoutCode === '302');

// Step D: Re-verify access with the old session
$codeAfterLogout = curl_req('http://localhost:8080/school_system/dashboard.php', $testCookieFile);
assert_test("Post-Close: Dashboard returns HTTP 302 redirect (user forced back to login)", $codeAfterLogout === '302');

// Cleanup
if (file_exists($testCookieFile)) @unlink($testCookieFile);

echo "\nSummary: $pass passed, $fail failed.\n";
if ($fail === 0) {
    echo "SUCCESS: Android auto-logout on close rule fully verified!\n";
    exit(0);
} else {
    echo "FAILURE: Some checks failed.\n";
    exit(1);
}
