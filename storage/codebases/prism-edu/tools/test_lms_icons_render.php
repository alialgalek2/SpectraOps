<?php
/**
 * Systematic Test Suite for LMS Module Icon Display (Multi-Role)
 */
require_once __DIR__ . '/../core/init.php';

$lmsPages = [
    'modules/LMS/index.php'              => 'admin',
    'modules/LMS/assignments.php'        => 'teacher',
    'modules/LMS/attendance.php'         => 'teacher',
    'modules/LMS/timetable.php'          => 'admin',
    'modules/LMS/student_portal.php'     => 'student',
    'modules/LMS/parent_portal.php'      => 'parent',
    'modules/LMS/teacher_dashboard.php'  => 'teacher',
    'modules/LMS/teacher_daily_log.php'  => 'teacher',
    'modules/LMS/calendar.php'           => 'admin',
    'modules/LMS/announcements.php'      => 'admin',
    'modules/LMS/messages.php'           => 'teacher'
];

$faCss = file_get_contents(__DIR__ . '/../assets/vendor/fontawesome/css/all.min.css');
$themeCss = file_get_contents(__DIR__ . '/../assets/css/theme.css');

echo "=== LMS ICON DISPLAY VERIFICATION SUITE (MULTI-ROLE) ===\n\n";

// 1. Verify theme.css has proper font-weight & font-family rules
$hasSolidWeight = strpos($themeCss, 'font-weight: 900 !important;') !== false;
$hasSolidFamily = strpos($themeCss, 'font-family: "Font Awesome 6 Free" !important;') !== false;
$hasBrandsFamily = strpos($themeCss, 'font-family: "Font Awesome 6 Brands" !important;') !== false;

echo "[CSS RULE AUDIT]\n";
echo "  theme.css Solid font-family rule: " . ($hasSolidFamily ? "PASS" : "FAIL") . "\n";
echo "  theme.css Solid font-weight: 900:  " . ($hasSolidWeight ? "PASS" : "FAIL") . "\n";
echo "  theme.css Brands font-family rule: " . ($hasBrandsFamily ? "PASS" : "FAIL") . "\n\n";

$totalIconsAllPages = 0;
$allPass = true;

foreach ($lmsPages as $pageRel => $testRole) {
    $_SESSION['user_id'] = ($testRole === 'parent' ? 2 : ($testRole === 'student' ? 3 : 9));
    $_SESSION['username'] = $testRole . '_tester';
    $_SESSION['role'] = $testRole;
    $_SESSION['role_id'] = ($testRole === 'parent' ? 5 : ($testRole === 'student' ? 4 : ($testRole === 'teacher' ? 2 : 1)));
    $_SESSION['full_name'] = 'Test ' . ucfirst($testRole);

    $fullPath = __DIR__ . '/../' . $pageRel;
    if (!file_exists($fullPath)) {
        echo "FAIL: File not found $pageRel\n";
        $allPass = false;
        continue;
    }

    ob_start();
    try {
        include $fullPath;
        $html = ob_get_clean();
        
        // Count Font Awesome icons
        preg_match_all('/<i\s+class=[\'"]([^\'"]*fa-[^\'"]*)[\'"]/i', $html, $matches);
        $iconCount = count($matches[1]);
        $totalIconsAllPages += $iconCount;

        $hasFaLink = (strpos($html, 'font-awesome') !== false || strpos($html, 'all.min.css') !== false);
        $hasThemeLink = strpos($html, 'theme.css') !== false;

        echo "Page: " . str_pad($pageRel, 36) . " [Role: " . str_pad($testRole, 7) . "] => $iconCount icons | FA Link: " . ($hasFaLink ? 'OK' : 'MISSING') . " | Theme: " . ($hasThemeLink ? 'OK' : 'MISSING') . "\n";
        
        // Verify every icon class in rendered HTML exists in FA6 Free CSS
        foreach ($matches[1] as $cls) {
            $classes = preg_split('/\s+/', trim($cls));
            foreach ($classes as $c) {
                if (preg_match('/^fa-([a-z0-9-]+)$/', $c, $m)) {
                    $ic = $m[1];
                    if (!in_array($ic, ['solid', 'regular', 'brands', 'fw', '2x', '3x', '4x', '5x', 'lg', 'sm', 'xs', 'spin', 'pulse', 'stack', 'stack-1x', 'stack-2x', 'inverse'])) {
                        if (strpos($faCss, '.fa-' . $ic . ':before') === false && strpos($faCss, '.fa-' . $ic . '::before') === false) {
                            echo "  [WARNING] Missing glyph definition for .fa-$ic in '$cls'\n";
                            $allPass = false;
                        }
                    }
                }
            }
        }
    } catch (Throwable $t) {
        ob_end_clean();
        echo "Page: " . str_pad($pageRel, 36) . " => ERROR: " . $t->getMessage() . "\n";
        $allPass = false;
    }
}

echo "\n============================================\n";
echo "Total LMS Icons Rendered & Verified: $totalIconsAllPages\n";
echo "Overall Status: " . ($allPass ? "100% PASS - ALL ICONS VALID & DISPLAY RULES LOCKED" : "ISSUES FOUND") . "\n";
