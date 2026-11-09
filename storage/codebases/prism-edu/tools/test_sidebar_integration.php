<?php
/**
 * Test Suite: Compact Sidebar Universal Integration & Android Support
 */

echo "=== Testing Compact Sidebar Across Modules & Android App ===\n";

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

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../core/Layout.php';

// 1. Test Layout::renderSidebar() markup and required classes for modules
$_SESSION['role'] = 'admin';
$GLOBALS['app_sidebar_rendered'] = false;
$sidebarHtml = Layout::renderSidebar(['active_module' => 'admin']);

assert_test("Sidebar has exact required classes: class=\"app-sidebar sidebar-collapsed sidebar-compact\"", 
    strpos($sidebarHtml, 'class="app-sidebar sidebar-collapsed sidebar-compact"') !== false);
assert_test("Sidebar has id=\"appMainSidebar\"", 
    strpos($sidebarHtml, 'id="appMainSidebar"') !== false);
assert_test("Sidebar has toggle button id=\"sidebarToggleBtn\"", 
    strpos($sidebarHtml, 'id="sidebarToggleBtn"') !== false);
assert_test("Sidebar has Dashboard navigation item", 
    strpos($sidebarHtml, 'dashboard.php') !== false);
assert_test("Sidebar has Administration module item", 
    strpos($sidebarHtml, '/modules/Administration/index.php') !== false);
assert_test("Sidebar has LMS module item", 
    strpos($sidebarHtml, '/modules/LMS/index.php') !== false);
assert_test("Sidebar has Grading module item", 
    strpos($sidebarHtml, '/modules/Grading/index.php') !== false);
assert_test("Sidebar has Finance module item", 
    strpos($sidebarHtml, '/modules/Finance/index.php') !== false);
assert_test("Sidebar has HR module item", 
    strpos($sidebarHtml, '/modules/HR/index.php') !== false);
assert_test("Sidebar has Support module item", 
    strpos($sidebarHtml, '/support.php') !== false);
assert_test("Sidebar has Documentation module item", 
    strpos($sidebarHtml, '/docs.php') !== false);
assert_test("Sidebar sets has-compact-sidebar class on body", 
    strpos($sidebarHtml, 'has-compact-sidebar') !== false);

// 1b. Test that dashboard explicitly suppresses the sidebar
$GLOBALS['app_sidebar_rendered'] = false;
$dashboardSidebarHtml = Layout::renderSidebar(['active_module' => 'dashboard']);
assert_test("Layout::renderSidebar() returns empty string on dashboard", 
    empty($dashboardSidebarHtml));

// 2. Test render_universal_navbar in Desktop Mode
$GLOBALS['app_sidebar_rendered'] = false;
$_GET['view'] = 'desktop';
$_SESSION['is_android_app'] = false;
unset($_SERVER['HTTP_X_REQUESTED_WITH']);
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/Administration/index.php';

$desktopNav = render_universal_navbar(['active_title' => 'Administration Hub']);
assert_test("Desktop universal navbar contains compact sidebar", 
    strpos($desktopNav, 'class="app-sidebar sidebar-collapsed sidebar-compact"') !== false);

// 3. Test render_universal_navbar in Android App Mode
$GLOBALS['app_sidebar_rendered'] = false;
$_GET['view'] = 'app';
$_SESSION['is_android_app'] = true;
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) PrismApp/2.5.0';
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/Grading/index.php';

$appNav = render_universal_navbar(['active_title' => 'Student Examination Portal']);
assert_test("Android app universal navbar contains compact sidebar", 
    strpos($appNav, 'class="app-sidebar sidebar-collapsed sidebar-compact"') !== false);
assert_test("Android app universal navbar also contains prism-app-topbar", 
    strpos($appNav, 'prism-app-topbar') !== false);

// 4. Test render_unified_navbar for modules and dashboard
$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/Administration/index.php';
$unifiedNav = render_unified_navbar('admin');
assert_test("Unified navbar contains compact sidebar for admin module", 
    strpos($unifiedNav, 'class="app-sidebar sidebar-collapsed sidebar-compact"') !== false);

$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/dashboard.php';
$dashUnifiedNav = render_unified_navbar('dashboard');
assert_test("Unified navbar suppresses sidebar for dashboard", 
    strpos($dashUnifiedNav, 'class="app-sidebar"') === false);

// 5. Test CSS rules for Android App in app-navbar.css
$appCss = file_get_contents(__DIR__ . '/../assets/css/app-navbar.css');
assert_test("app-navbar.css defines body.is-native-app .app-sidebar.sidebar-collapsed", 
    strpos($appCss, 'body.is-native-app .app-sidebar.sidebar-collapsed') !== false);
assert_test("app-navbar.css defines body.is-native-app .app-sidebar.sidebar-compact", 
    strpos($appCss, 'body.is-native-app .app-sidebar.sidebar-compact') !== false);
assert_test("app-navbar.css sets 60px compact rail width for Android app", 
    strpos($appCss, 'width: 60px !important;') !== false);
assert_test("app-navbar.css sets 60px content padding offset for Android app LTR", 
    strpos($appCss, 'padding-left: 60px !important;') !== false);
assert_test("app-navbar.css sets 60px content padding offset for Android app RTL", 
    strpos($appCss, 'padding-right: 60px !important;') !== false);

// 6. Test CSS rules in theme.css
$themeCss = file_get_contents(__DIR__ . '/../assets/css/theme.css');
assert_test("theme.css defines .app-sidebar.sidebar-collapsed, .app-sidebar.sidebar-compact", 
    strpos($themeCss, '.app-sidebar.sidebar-collapsed,') !== false && strpos($themeCss, '.app-sidebar.sidebar-compact') !== false);
assert_test("theme.css defines 72px desktop width for compact sidebar", 
    strpos($themeCss, 'width: 72px !important;') !== false);
assert_test("theme.css retains visible compact sidebar on mobile (max-width: 992px)", 
    strpos($themeCss, 'width: 64px !important;') !== false);

// 7. Test JS controller in theme_toggle.js
$themeJs = file_get_contents(__DIR__ . '/../assets/js/theme_toggle.js');
assert_test("theme_toggle.js contains initSidebarController", 
    strpos($themeJs, 'function initSidebarController()') !== false);
assert_test("theme_toggle.js manages sidebar-collapsed and sidebar-compact classes", 
    strpos($themeJs, "'sidebar-collapsed', 'sidebar-compact'") !== false);
assert_test("theme_toggle.js binds #sidebarToggleBtn click handler", 
    strpos($themeJs, '#sidebarToggleBtn') !== false);

echo "\nSummary: $passCount passed, $failCount failed.\n";
if ($failCount === 0) {
    echo "SUCCESS: All compact sidebar tests passed!\n";
    exit(0);
} else {
    echo "FAILURE: Some tests failed.\n";
    exit(1);
}
