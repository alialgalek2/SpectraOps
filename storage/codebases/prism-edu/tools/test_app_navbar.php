<?php
/**
 * Test Suite: Android WebView Navigation Bar & Detection Logic
 */

echo "=== Testing Android App & WebView Navigation Bar ===\n";

$passCount = 0;
$failCount = 0;

function assert_test($description, $condition) {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $description\n";
        $passCount++;
    } else {
        echo "  [FAIL] $description\n";
        $failCount++;
    }
}

// 1. Test detection logic function
require_once __DIR__ . '/../core/init.php';

// Test desktop mode
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
$_GET['view'] = 'desktop';
$_SESSION['is_android_app'] = false;
assert_test("Desktop mode correctly identified as false", is_android_app() === false);

// Test User-Agent detection (PrismApp)
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36 PrismApp/2.5.0';
unset($_GET['view']);
unset($_SESSION['is_android_app']);
assert_test("User-Agent with PrismApp/2.5.0 identified as true", is_android_app() === true);

// Test HTTP_X_REQUESTED_WITH header
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14)';
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'com.algale.schoolportal';
unset($_GET['view']);
unset($_SESSION['is_android_app']);
assert_test("HTTP_X_REQUESTED_WITH header identified as true", is_android_app() === true);

// Test query flag view=app
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Generic)';
$_GET['view'] = 'app';
unset($_SESSION['is_android_app']);
assert_test("Query param view=app identified as true", is_android_app() === true);

// 2. Test Component Output
$_SESSION['full_name'] = 'Dr. Sarah Connor';
$_SESSION['role'] = 'school_admin';
$active_title = 'Examination Center';
$isAndroidApp = true;

ob_start();
include __DIR__ . '/../components/app_navbar.php';
$html = ob_get_clean();

assert_test("Navbar contains .prism-app-topbar", strpos($html, 'class="prism-app-topbar') !== false);
assert_test("Navbar has .is-native-app class", strpos($html, 'is-native-app') !== false);
assert_test("Navbar contains PRISM brand title", strpos($html, 'PRISM') !== false);
assert_test("Navbar contains .badge-dot", strpos($html, 'badge-dot') !== false);
assert_test("Navbar contains active module pill with title", strpos($html, 'Examination Center') !== false);
assert_test("Navbar contains search quick button", strpos($html, 'id="searchQuickBtn"') !== false);
assert_test("Navbar contains notification quick button with count 3", strpos($html, 'id="notifQuickBtn"') !== false && strpos($html, 'class="badge-count">3<') !== false);
assert_test("Navbar contains user avatar button", strpos($html, 'id="appUserSheetBtn"') !== false);
assert_test("Bottom sheet overlay exists", strpos($html, 'id="userSheetOverlay"') !== false);
assert_test("Bottom sheet container exists", strpos($html, 'id="userSheet"') !== false);
assert_test("Sheet drag handle exists", strpos($html, 'class="sheet-drag-handle"') !== false);
assert_test("Sheet displays correct user name", strpos($html, 'Dr. Sarah Connor') !== false);
assert_test("Sheet displays correct user role", strpos($html, 'School_admin') !== false || strpos($html, 'school_admin') !== false);
assert_test("Sheet displays active online badge", strpos($html, 'Online') !== false);
assert_test("Sheet grid contains Profile item", strpos($html, 'Profile') !== false);
assert_test("Sheet grid contains RBAC item", strpos($html, 'RBAC') !== false);
assert_test("Sheet grid contains Alerts item", strpos($html, 'Alerts') !== false);
assert_test("Sheet grid contains Settings item", strpos($html, 'Settings') !== false);
assert_test("Sheet footer contains Sign Out button", strpos($html, 'btn-app-logout') !== false && strpos($html, 'Sign Out') !== false);

// 3. Test Assets files
$cssExists = file_exists(__DIR__ . '/../assets/css/app-navbar.css');
assert_test("app-navbar.css file exists", $cssExists);
if ($cssExists) {
    $css = file_get_contents(__DIR__ . '/../assets/css/app-navbar.css');
    assert_test("app-navbar.css contains .prism-app-topbar styling", strpos($css, '.prism-app-topbar') !== false);
    assert_test("app-navbar.css contains .app-bottom-sheet styling", strpos($css, '.app-bottom-sheet') !== false);
}

$jsExists = file_exists(__DIR__ . '/../assets/js/app-navbar.js');
assert_test("app-navbar.js file exists", $jsExists);
if ($jsExists) {
    $js = file_get_contents(__DIR__ . '/../assets/js/app-navbar.js');
    assert_test("app-navbar.js binds click on appUserSheetBtn", strpos($js, 'appUserSheetBtn') !== false);
    assert_test("app-navbar.js toggles active class on userSheet", strpos($js, 'active') !== false);
}

// 4. Test render_universal_navbar integration
$_GET['view'] = 'app';
$_SESSION['is_android_app'] = true;
$uniNavbar = render_universal_navbar(['active_title' => 'Student Rosters']);
assert_test("render_universal_navbar outputs prism-app-topbar when is_android_app() is true", strpos($uniNavbar, 'prism-app-topbar') !== false);
assert_test("render_universal_navbar outputs userSheet when is_android_app() is true", strpos($uniNavbar, 'userSheet') !== false);

echo "\nSummary: $passCount passed, $failCount failed.\n";
if ($failCount === 0) {
    echo "SUCCESS: All tests passed flawlessly!\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}
