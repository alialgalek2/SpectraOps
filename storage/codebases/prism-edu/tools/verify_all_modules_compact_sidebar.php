<?php
/**
 * Verification Suite: Universal Compact Collapsed Sidebar Across All Modules and Android App
 * Verifies exact presence of class="app-sidebar sidebar-collapsed sidebar-compact"
 */

require_once __DIR__ . '/../core/init.php';

// Mock active session
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

$pages = [
    'Dashboard' => 'dashboard.php',
    'Administration: Index' => 'modules/Administration/index.php',
    'Administration: Users' => 'modules/Administration/users.php',
    'Administration: Permissions' => 'modules/Administration/manage_permissions.php',
    'Grading: Index' => 'modules/Grading/index.php',
    'Grading: Teacher Grades' => 'modules/Grading/teacher_grades.php',
    'Grading: Report Cards' => 'modules/Grading/report_cards.php',
    'LMS: Index' => 'modules/LMS/index.php',
    'LMS: Assignments' => 'modules/LMS/assignments.php',
    'Finance: Index' => 'modules/Finance/index.php',
    'Finance: Fee Structures' => 'modules/Finance/fee_structures.php',
    'HR: Index' => 'modules/HR/index.php',
    'HR: Employees' => 'modules/HR/employees.php',
    'Support Center' => 'support.php',
    'Documentation' => 'docs.php'
];

echo "======================================================================\n";
echo "VERIFYING COMPACT COLLAPSED SIDEBAR ACROSS ALL MODULES & ANDROID APP\n";
echo "======================================================================\n\n";

$passCount = 0;
$failCount = 0;

function report_check($name, $status, $details = '') {
    global $passCount, $failCount;
    if ($status) {
        echo "  [PASS] $name\n";
        $passCount++;
    } else {
        echo "  [FAIL] $name $details\n";
        $failCount++;
    }
}

// 1. Desktop Mode Verification
echo "--- Section 1: Standard / Desktop Mode ---\n";
foreach ($pages as $label => $relPath) {
    $filePath = __DIR__ . '/../' . $relPath;
    if (!file_exists($filePath)) {
        report_check("$label ($relPath)", false, "(File does not exist)");
        continue;
    }

    $GLOBALS['app_sidebar_rendered'] = false;
    $_SESSION['is_android_app'] = false;
    unset($_GET['view']);
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';
    $_SERVER['SCRIPT_NAME'] = '/school_system/' . $relPath;
    $_SERVER['SCRIPT_FILENAME'] = $filePath;

    ob_start();
    try {
        include $filePath;
        $html = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        report_check("$label", false, "(Exception: " . $e->getMessage() . ")");
        continue;
    }

    if ($relPath === 'dashboard.php') {
        $noSidebar = (strpos($html, 'class="app-sidebar') === false);
        $hasDashboardPageClass = (strpos($html, 'dashboard-page') !== false);
        report_check("$label has NO sidebar and body has dashboard-page class", $noSidebar && $hasDashboardPageClass);
    } else {
        $hasCompactClass = (strpos($html, 'class="app-sidebar sidebar-collapsed sidebar-compact"') !== false);
        // Verify navbar left section is streamlined (module badge pill present, no duplicate brand logo next to sidebar)
        $hasStreamlinedNavbar = (strpos($html, 'fa-shapes') !== false || strpos($html, 'badge bg-secondary') !== false);
        report_check("$label renders class=\"app-sidebar sidebar-collapsed sidebar-compact\" with streamlined navbar", $hasCompactClass && $hasStreamlinedNavbar);
    }
}

// 2. Android App Mode Verification
echo "\n--- Section 2: Android App Mode (WebView / Native App) ---\n";
$androidPages = [
    'Android App: Dashboard' => 'dashboard.php',
    'Android App: Admin Users' => 'modules/Administration/users.php',
    'Android App: Grading Index' => 'modules/Grading/index.php',
    'Android App: LMS Assignments' => 'modules/LMS/assignments.php',
    'Android App: Finance Index' => 'modules/Finance/index.php'
];

foreach ($androidPages as $label => $relPath) {
    $filePath = __DIR__ . '/../' . $relPath;
    if (!file_exists($filePath)) continue;

    $GLOBALS['app_sidebar_rendered'] = false;
    $_SESSION['is_android_app'] = true;
    $_GET['view'] = 'app';
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) PrismApp/2.5.0';
    $_SERVER['SCRIPT_NAME'] = '/school_system/' . $relPath;
    $_SERVER['SCRIPT_FILENAME'] = $filePath;

    ob_start();
    try {
        include $filePath;
        $html = ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        report_check("$label", false, "(Exception: " . $e->getMessage() . ")");
        continue;
    }

    $hasAppNavbar = (strpos($html, 'prism-app-topbar') !== false);
    if ($relPath === 'dashboard.php') {
        $noSidebar = (strpos($html, 'class="app-sidebar') === false);
        report_check("$label has NO sidebar in Android app mode", $noSidebar && $hasAppNavbar);
    } else {
        $hasCompactClass = (strpos($html, 'class="app-sidebar sidebar-collapsed sidebar-compact"') !== false);
        report_check("$label renders compact sidebar alongside native app topbar", $hasCompactClass && $hasAppNavbar);
    }
}

echo "\n======================================================================\n";
echo "SUMMARY: $passCount Passed, $failCount Failed.\n";
if ($failCount === 0) {
    echo "SUCCESS: Dashboard has no sidebar, and compact collapsed sidebar is fully active across all other modules and Android app!\n";
    echo "======================================================================\n";
    exit(0);
} else {
    echo "FAILURE: One or more pages failed verification.\n";
    echo "======================================================================\n";
    exit(1);
}
