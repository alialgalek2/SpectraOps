<?php
/**
 * Test Suite: Auth Security & Rate Limiting Verification
 * - 5-Minute Inactivity Auto-Logout
 * - 5-Attempt Login Rate Limiter & 5-Minute Cooldown
 */

require_once __DIR__ . '/../core/init.php';

$testResults = [
    'passed' => 0,
    'failed' => 0,
    'tests' => []
];

function assertCheck(string $testName, bool $condition, string $detail = '') {
    global $testResults;
    if ($condition) {
        $testResults['passed']++;
        echo "  [PASS] $testName" . ($detail ? " ($detail)" : "") . "\n";
        $testResults['tests'][] = ['status' => 'PASS', 'name' => $testName, 'detail' => $detail];
    } else {
        $testResults['failed']++;
        echo "  [FAIL] $testName" . ($detail ? " ($detail)" : "") . "\n";
        $testResults['tests'][] = ['status' => 'FAIL', 'name' => $testName, 'detail' => $detail];
    }
}

echo "================================================================================\n";
echo "           AUTHENTICATION SECURITY & RATE LIMITING TEST SUITE                   \n";
echo "================================================================================\n\n";

// -----------------------------------------------------------------------------
// PART 1: LOGIN SECURITY SERVICE & 5-ATTEMPT COOLDOWN
// -----------------------------------------------------------------------------
echo "--- PART 1: 5-ATTEMPT RETRY LIMIT & 5-MINUTE COOLDOWN ---\n";

assertCheck(
    "LoginSecurity Class Exists",
    class_exists('LoginSecurity'),
    class_exists('LoginSecurity') ? "Class loaded" : "Class not found"
);

if (class_exists('LoginSecurity')) {
    $testIdentifier = 'test_security_user_' . time();
    $testIp = '192.168.99.' . rand(1, 250);

    // Initial state: not locked, 5 attempts remaining
    $status = LoginSecurity::checkLockout($testIdentifier, $testIp);
    assertCheck("Initial State: Not Locked", !$status['is_locked']);
    assertCheck("Initial State: 5 Attempts Remaining", $status['attempts_left'] === 5, "Left: {$status['attempts_left']}");

    // Record 4 failed attempts
    for ($i = 1; $i <= 4; $i++) {
        LoginSecurity::recordFailedAttempt($testIdentifier, $testIp);
        $st = LoginSecurity::checkLockout($testIdentifier, $testIp);
        $expectedLeft = 5 - $i;
        assertCheck("Attempt $i Failed: Not Locked", !$st['is_locked']);
        assertCheck("Attempt $i Failed: $expectedLeft Attempts Left", $st['attempts_left'] === $expectedLeft, "Left: {$st['attempts_left']}");
    }

    // 5th failed attempt -> MUST LOCK
    LoginSecurity::recordFailedAttempt($testIdentifier, $testIp);
    $lockStatus = LoginSecurity::checkLockout($testIdentifier, $testIp);
    assertCheck("Attempt 5 Failed: Is Locked", $lockStatus['is_locked']);
    assertCheck("Attempt 5 Failed: 0 Attempts Left", $lockStatus['attempts_left'] === 0, "Left: {$lockStatus['attempts_left']}");
    assertCheck(
        "Attempt 5 Failed: Cooldown >= 290s and <= 300s",
        $lockStatus['remaining_seconds'] >= 290 && $lockStatus['remaining_seconds'] <= 300,
        "Remaining: {$lockStatus['remaining_seconds']}s"
    );

    // Successful login reset
    LoginSecurity::recordSuccessfulLogin($testIdentifier, $testIp);
    $postSuccessStatus = LoginSecurity::checkLockout($testIdentifier, $testIp);
    assertCheck("After Success Login: Lock Cleared", !$postSuccessStatus['is_locked']);
    assertCheck("After Success Login: Reset to 5 Attempts", $postSuccessStatus['attempts_left'] === 5, "Left: {$postSuccessStatus['attempts_left']}");
}

// -----------------------------------------------------------------------------
// PART 2: 5-MINUTE INACTIVITY SESSION AUTO-LOGOUT
// -----------------------------------------------------------------------------
echo "\n--- PART 2: 5-MINUTE SESSION INACTIVITY TIMEOUT ---\n";

assertCheck(
    "Auth Inactivity Timeout Constant Defined as 300s",
    defined('Auth::INACTIVITY_TIMEOUT') && Auth::INACTIVITY_TIMEOUT === 300,
    defined('Auth::INACTIVITY_TIMEOUT') ? "Value: " . Auth::INACTIVITY_TIMEOUT : "Not defined"
);

// Active Session Check (< 300s)
$_SESSION['user_id'] = 9999;
$_SESSION['username'] = 'security_test';
$_SESSION['role'] = 'admin';
$_SESSION['last_activity'] = time() - 100; // 100s ago (< 300s)

$isActive = Auth::check();
assertCheck("Active Session (100s elapsed): Still Logged In", $isActive === true);
assertCheck("Active Session: last_activity Refreshed", isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] <= 2));

// Expired Session Check (> 300s)
$_SESSION['user_id'] = 9999;
$_SESSION['username'] = 'security_test';
$_SESSION['role'] = 'admin';
$_SESSION['last_activity'] = time() - 305; // 305s ago (> 300s)

$isExpired = Auth::check();
assertCheck("Expired Session (305s elapsed): Logged Out", $isExpired === false);
assertCheck("Expired Session: User ID Cleared", !isset($_SESSION['user_id']));
assertCheck("Expired Session: Timeout Flag Set", !empty($_SESSION['timeout_logout']));

// Test IP-only lockout check
$testIpOnly = '10.0.99.' . rand(1, 250);
$ipCheck = LoginSecurity::checkLockout('', $testIpOnly);
assertCheck("IP-Only Check (empty identifier): Not Locked Initially", !$ipCheck['is_locked']);
for ($j = 1; $j <= 5; $j++) {
    LoginSecurity::recordFailedAttempt('blank_user_' . $j, $testIpOnly);
}
$ipCheckAfter = LoginSecurity::checkLockout('', $testIpOnly);
assertCheck("IP-Only Check: Locked After 5 Failures from Same IP", $ipCheckAfter['is_locked']);
assertCheck("IP-Only Check: Lockout Remaining Seconds <= 300", $ipCheckAfter['remaining_seconds'] <= 300 && $ipCheckAfter['remaining_seconds'] > 290);

// Verify inactivity_watcher.js script contents
$watcherJs = file_get_contents(__DIR__ . '/../assets/js/inactivity_watcher.js');
assertCheck("Inactivity Watcher JS: 300s Timeout Configured", strpos($watcherJs, 'TIMEOUT_SECONDS = 300') !== false);
assertCheck("Inactivity Watcher JS: 270s Warning Threshold Configured", strpos($watcherJs, 'WARNING_SECONDS = 270') !== false);
assertCheck("Inactivity Watcher JS: PrismApp Topbar Selector Present", strpos($watcherJs, '.prism-app-topbar') !== false);
assertCheck("Inactivity Watcher JS: Duplicate Guard Present", strpos($watcherJs, '__inactivityWatcherInitialized') !== false);

echo "\n================================================================================\n";
echo "TEST RESULTS: Passed: {$testResults['passed']}, Failed: {$testResults['failed']}\n";
echo "================================================================================\n";

