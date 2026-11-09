<?php
$lmsFiles = [
    'index.php',
    'assignments.php',
    'attendance.php',
    'timetable.php',
    'student_portal.php',
    'parent_portal.php',
    'teacher_dashboard.php',
    'teacher_daily_log.php',
    'calendar.php',
    'announcements.php',
    'messages.php'
];

$dir = 'C:/xampp/htdocs/school_system/modules/LMS/';
$faCss = file_get_contents('C:/xampp/htdocs/school_system/assets/vendor/fontawesome/css/all.min.css');

echo "=== DEEP DIVE INTO LMS MODULE FILES ===\n\n";

foreach ($lmsFiles as $fn) {
    $filePath = $dir . $fn;
    if (!file_exists($filePath)) continue;
    $content = file_get_contents($filePath);
    
    echo "--- File: $fn ---\n";

    // 1. Check head / css includes
    $hasGetBootstrap = strpos($content, 'get_bootstrap_css()') !== false;
    $hasFaCdn = strpos($content, 'font-awesome') !== false || strpos($content, 'all.min.css') !== false;
    echo "  get_bootstrap_css(): " . ($hasGetBootstrap ? "YES" : "NO") . "\n";
    echo "  FontAwesome link:    " . ($hasFaCdn ? "YES" : "NO") . "\n";

    // 2. Extract all <i> tags
    preg_match_all('/<i\s+class=[\'"]([^\'"]+)[\'"][^>]*>/i', $content, $matches);
    $count = count($matches[1]);
    echo "  Total <i> icons:     $count\n";

    $fileIssues = [];
    foreach ($matches[1] as $cls) {
        $parts = preg_split('/\s+/', trim($cls));
        
        $hasPrefix = false;
        $iconClass = '';
        foreach ($parts as $p) {
            if (in_array($p, ['fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands'])) {
                $hasPrefix = true;
            }
            if (preg_match('/^fa-([a-z0-9-]+)$/', $p, $m)) {
                $sub = $m[1];
                if (!in_array($sub, ['solid', 'regular', 'brands', 'fw', '2x', '3x', '4x', '5x', 'lg', 'sm', 'xs', 'spin', 'pulse', 'stack', 'stack-1x', 'stack-2x', 'inverse'])) {
                    $iconClass = $sub;
                }
            }
        }

        if (!$hasPrefix && $iconClass) {
            $fileIssues[] = "Missing fa-solid/regular/brands prefix: '$cls'";
        }

        if ($iconClass) {
            if (strpos($faCss, '.fa-' . $iconClass . ':before') === false && strpos($faCss, '.fa-' . $iconClass . '::before') === false) {
                // Check if it's dynamic PHP like fa-arrow-<?= ...
                if (strpos($cls, '<?=') === false && strpos($cls, '<?php') === false) {
                    $fileIssues[] = "Unknown FA6 icon: 'fa-$iconClass' in '$cls'";
                }
            }
        }
    }

    if (empty($fileIssues)) {
        echo "  Icon Class Status:   ALL CLEAN\n";
    } else {
        echo "  Issues Found (" . count($fileIssues) . "):\n";
        foreach ($fileIssues as $iss) {
            echo "    * $iss\n";
        }
    }
    echo "\n";
}
