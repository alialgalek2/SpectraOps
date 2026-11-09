<?php
/**
 * Test Suite: PRISM EDU Unified Top Navigation & Slide-out Drawer
 * Validates Component, CSS Tokens, Client JS, and Live HTTP Integration.
 */

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../includes/lang.php';

echo "=== PRISM EDU Unified Top Navigation Verification ===\n";

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

// 1. Files existence on disk
$compFile = __DIR__ . '/../components/unified_navbar.php';
$cssFile  = __DIR__ . '/../assets/css/prism-unified-nav.css';
$jsFile   = __DIR__ . '/../assets/js/prism-unified-nav.js';

assert_test("Component file exists (components/unified_navbar.php)", file_exists($compFile));
assert_test("CSS stylesheet exists (assets/css/prism-unified-nav.css)", file_exists($cssFile));
assert_test("Client JS controller exists (assets/js/prism-unified-nav.js)", file_exists($jsFile));

// 2. Component HTML rendering & elements
ob_start();
include $compFile;
$html = ob_get_clean();

assert_test("Renders header.prism-navbar-unified", str_contains($html, 'class="prism-navbar-unified"'));
assert_test("Contains drawer toggle button #globalNavToggleBtn", str_contains($html, 'id="globalNavToggleBtn"'));
assert_test("Contains brand anchor and PRISM EDU mark", str_contains($html, 'PRISM <span class="brand-pill">EDU</span>'));
assert_test("Contains inline search input #globalSearchInput with ⌘K badge", str_contains($html, 'id="globalSearchInput"') && str_contains($html, '⌘K'));
assert_test("Contains theme toggle button #themeToggleBtn with sun & moon SVGs", str_contains($html, 'id="themeToggleBtn"') && str_contains($html, 'theme-icon-sun') && str_contains($html, 'theme-icon-moon'));
assert_test("Contains notification trigger #navNotifTrigger with count badge", str_contains($html, 'id="navNotifTrigger"') && str_contains($html, 'notif-badge'));
assert_test("Contains user menu avatar trigger #navUserMenuTrigger", str_contains($html, 'id="navUserMenuTrigger"'));
assert_test("Contains slide-out backdrop #unifiedNavBackdrop", str_contains($html, 'id="unifiedNavBackdrop"'));
assert_test("Contains offcanvas drawer aside#unifiedNavDrawer", str_contains($html, 'id="unifiedNavDrawer"'));
assert_test("Drawer contains user profile card with display name and role", str_contains($html, 'class="drawer-user-card"') && str_contains($html, 'user-display-name'));
assert_test("Drawer contains 8 fast-navigation grid items", 
    str_contains($html, 'data-i18n="dashboard"') &&
    str_contains($html, 'data-i18n="attendance"') &&
    str_contains($html, 'data-i18n="students"') &&
    str_contains($html, 'data-i18n="tuition"') &&
    str_contains($html, 'data-i18n="academics"') &&
    str_contains($html, 'data-i18n="reports"') &&
    str_contains($html, 'data-i18n="profile"') &&
    str_contains($html, 'data-i18n="settings"')
);
assert_test("Drawer contains language switcher buttons #langEnBtn and #langArBtn", str_contains($html, 'id="langEnBtn"') && str_contains($html, 'id="langArBtn"'));
assert_test("Drawer contains logout button .btn-drawer-logout", str_contains($html, 'class="btn-drawer-logout"') && str_contains($html, 'data-i18n="signOut"'));

// 3. CSS Stylesheet inspection
$cssContent = file_get_contents($cssFile);
assert_test("CSS defines theme tokens (:root and [data-theme=\"light\"])", str_contains($cssContent, '--nav-bg: #091830') && str_contains($cssContent, '[data-theme="light"]'));
assert_test("CSS defines .prism-navbar-unified sticky navigation shell", str_contains($cssContent, '.prism-navbar-unified') && str_contains($cssContent, 'position: sticky'));
assert_test("CSS defines responsive search input expansion", str_contains($cssContent, '.nav-search-container:focus-within'));
assert_test("CSS handles Sun / Moon icon switching", str_contains($cssContent, '.theme-icon-sun') && str_contains($cssContent, '.theme-icon-moon'));
assert_test("CSS defines offcanvas drawer sliding transitions", str_contains($cssContent, '.prism-nav-drawer') && str_contains($cssContent, 'transform: translateX(100%)'));
assert_test("CSS defines RTL support for Arabic language mode", str_contains($cssContent, '[dir="rtl"] .prism-nav-drawer'));

// 4. JS Controller inspection
$jsContent = file_get_contents($jsFile);
assert_test("JS binds drawer open/close and backdrop click", str_contains($jsContent, 'globalNavToggleBtn') && str_contains($jsContent, 'unifiedNavBackdrop'));
assert_test("JS supports Escape key drawer closure", str_contains($jsContent, "e.key === 'Escape'"));
assert_test("JS manages dark/light theme switching with localStorage", str_contains($jsContent, 'themeToggleBtn') && str_contains($jsContent, 'prism_theme'));
assert_test("JS manages language switching (EN/AR) with RTL dir attribute", str_contains($jsContent, 'langEnBtn') && str_contains($jsContent, 'langArBtn') && str_contains($jsContent, 'rtl'));
assert_test("JS handles ⌘K / Ctrl+K search shortcut", str_contains($jsContent, 'ctrlKey') && str_contains($jsContent, "'k'"));

// 5. Helper function render_unified_navbar()
$helperOutput = render_unified_navbar('attendance');
assert_test("render_unified_navbar() outputs CSS link, component HTML, and script tag", 
    str_contains($helperOutput, 'prism-unified-nav.css') && 
    str_contains($helperOutput, 'prism-navbar-unified') && 
    str_contains($helperOutput, 'prism-unified-nav.js')
);

// 6. Live HTTP verification
function refresh_admin_session() {
    $cookieFile = __DIR__ . '/../cookie_admin.txt';
    if (file_exists($cookieFile)) @unlink($cookieFile);
    $loginHtml = shell_exec('curl.exe -s -c ' . escapeshellarg($cookieFile) . ' -b ' . escapeshellarg($cookieFile) . ' http://localhost:8080/school_system/login.php');
    if ($loginHtml && preg_match('/name="csrf_token"\s+value="([^"]+)"/', $loginHtml, $m)) {
        $token = $m[1];
        $postData = 'identifier=admin&password=admin123&csrf_token=' . urlencode($token);
        exec('curl.exe -s -c ' . escapeshellarg($cookieFile) . ' -b ' . escapeshellarg($cookieFile) . ' -d ' . escapeshellarg($postData) . ' http://localhost:8080/school_system/login.php');
    }
}

refresh_admin_session();

function http_get($url) {
    $cookieFile = __DIR__ . '/../cookie_admin.txt';
    $codeCmd = 'curl.exe -s -o NUL -w "%{http_code}" -b ' . escapeshellarg($cookieFile) . ' ' . escapeshellarg($url);
    $status = trim(shell_exec($codeCmd) ?? '');
    $bodyCmd = 'curl.exe -s -b ' . escapeshellarg($cookieFile) . ' ' . escapeshellarg($url);
    $content = shell_exec($bodyCmd) ?? '';
    return ['status' => $status, 'length' => strlen($content), 'content' => $content];
}

$rCss = http_get('http://localhost:8080/school_system/assets/css/prism-unified-nav.css');
assert_test("HTTP 200 for assets/css/prism-unified-nav.css", $rCss['status'] === '200' && $rCss['length'] > 1000);

$rJs = http_get('http://localhost:8080/school_system/assets/js/prism-unified-nav.js');
assert_test("HTTP 200 for assets/js/prism-unified-nav.js", $rJs['status'] === '200' && $rJs['length'] > 1000);

$rDash = http_get('http://localhost:8080/school_system/dashboard.php?nav=unified');
assert_test("HTTP 200 for dashboard.php?nav=unified with unified navbar", 
    $rDash['status'] === '200' && 
    str_contains($rDash['content'], 'prism-navbar-unified') &&
    str_contains($rDash['content'], 'unifiedNavDrawer')
);

echo "\nSummary: $pass passed, $fail failed.\n";
if ($fail === 0) {
    echo "SUCCESS: All unified navbar tests passed!\n";
    exit(0);
} else {
    echo "FAILURE: Some unified navbar tests failed.\n";
    exit(1);
}
