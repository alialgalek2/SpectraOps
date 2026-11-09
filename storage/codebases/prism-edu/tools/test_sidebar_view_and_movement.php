<?php
/**
 * Test Suite: Sidebar View Docking, Scroll Movement & Viewport Coordination
 */

$root = dirname(__DIR__);
$themeCss = file_get_contents($root . '/assets/css/theme.css');
$themeJs  = file_get_contents($root . '/assets/js/theme_toggle.js');
$layoutPhp = file_get_contents($root . '/core/Layout.php');

$passed = 0;
$failed = 0;

function assert_check(string $desc, bool $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc}\n";
        $failed++;
    }
}

echo "=== Testing Sidebar View Docking & Page Movement Sync ===\n\n";

// 1. Sidebar Viewport Docking
assert_check("theme.css: .app-sidebar has position: fixed !important", strpos($themeCss, 'position: fixed !important;') !== false);
assert_check("theme.css: .app-sidebar has top: 0, bottom: 0, height: 100vh", 
    strpos($themeCss, 'top: 0;') !== false && 
    strpos($themeCss, 'bottom: 0;') !== false && 
    strpos($themeCss, 'height: 100vh;') !== false
);
assert_check("theme.css: .app-sidebar has overflow: hidden !important (prevents header/footer displacement)", 
    strpos($themeCss, 'overflow: hidden !important;') !== false
);
assert_check("theme.css: .app-sidebar is NOT sticky in .app-layout-container", 
    strpos($themeCss, '.app-layout-container .app-sidebar') === false
);

// 2. Independent Nav Container Scrolling
assert_check("theme.css: #sidebarNavContainer has independent overflow-y: auto", 
    strpos($themeCss, '#sidebarNavContainer') !== false && 
    strpos($themeCss, 'overflow-y: auto;') !== false
);
assert_check("theme.css: #sidebarNavContainer has overscroll-behavior: contain", 
    strpos($themeCss, 'overscroll-behavior: contain;') !== false
);
assert_check("theme.css: #sidebarNavContainer has custom slim scrollbar", 
    strpos($themeCss, '#sidebarNavContainer::-webkit-scrollbar') !== false
);
assert_check("theme.css: In collapsed mode, scrollbar is hidden to keep 72px rail perfectly centered", 
    strpos($themeCss, '.app-sidebar.sidebar-collapsed #sidebarNavContainer') !== false &&
    strpos($themeCss, 'scrollbar-width: none;') !== false
);

// 3. Navbar and Brand Header Height Alignment
assert_check("theme.css: .navbar-custom has min-height: 60px", strpos($themeCss, 'min-height: 60px;') !== false);
assert_check("Layout.php: .sidebar-brand-header has height: 60px; min-height: 60px;", 
    strpos($layoutPhp, "height: 60px; min-height: 60px;") !== false
);

// 4. Synchronized Body Movement
assert_check("theme.css: body has smooth padding-left & padding-right transitions", 
    strpos($themeCss, 'transition: padding-left 0.25s cubic-bezier') !== false &&
    strpos($themeCss, 'padding-right 0.25s cubic-bezier') !== false
);
assert_check("theme.css: body.has-compact-sidebar has 72px offset for LTR", 
    strpos($themeCss, 'body.has-compact-sidebar:not([dir="rtl"])') !== false
);
assert_check("theme.css: body.has-compact-sidebar has 72px offset for RTL", 
    strpos($themeCss, 'body.has-compact-sidebar[dir="rtl"]') !== false
);
assert_check("theme.css: body.has-expanded-sidebar has 260px offset for LTR", 
    strpos($themeCss, 'body.has-expanded-sidebar:not([dir="rtl"])') !== false
);
assert_check("theme.css: body.has-expanded-sidebar has 260px offset for RTL", 
    strpos($themeCss, 'body.has-expanded-sidebar[dir="rtl"]') !== false
);
assert_check("theme.css: dashboard-page explicitly resets body offset to 0", 
    strpos($themeCss, 'body.dashboard-page') !== false
);

// 5. Mobile & Touch Screen Adaptations
assert_check("theme.css: Mobile compact rail has 64px width and z-index: 1045", 
    strpos($themeCss, 'width: 64px !important;') !== false &&
    strpos($themeCss, 'z-index: 1045 !important;') !== false
);
assert_check("theme.css: Mobile expanded drawer has overlay z-index: 1060 and elevation shadow", 
    strpos($themeCss, 'z-index: 1060 !important;') !== false &&
    strpos($themeCss, 'box-shadow: 0 10px 35px -5px') !== false
);

// 6. JavaScript Controller Polish
assert_check("theme_toggle.js: Tooltips initialized with container: 'body'", 
    strpos($themeJs, "container: 'body'") !== false
);
assert_check("theme_toggle.js: Tooltips toggle state on collapse/expand", 
    strpos($themeJs, "sidebarTooltips.forEach") !== false
);
assert_check("theme_toggle.js: Mobile outside-tap auto-collapses drawer", 
    strpos($themeJs, "window.innerWidth <= 992") !== false &&
    strpos($themeJs, "!sidebar.contains(e.target)") !== false
);
assert_check("theme_toggle.js: Esc key dismisses expanded sidebar or mobile drawer", 
    strpos($themeJs, "e.key === 'Escape'") !== false
);

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
if ($failed === 0) {
    echo "SUCCESS: All sidebar docking, scrolling, and movement tests passed!\n";
    exit(0);
} else {
    echo "ERROR: Some tests failed.\n";
    exit(1);
}
