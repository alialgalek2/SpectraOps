<?php
$lmsDir = 'C:/xampp/htdocs/school_system/modules/LMS';
$files = glob($lmsDir . '/*.php');

$allIcons = [];
$missingSolidPrefix = [];
$customIconWrappers = [];

foreach ($files as $file) {
    $content = file_get_contents($file);
    $bn = basename($file);

    // Find all <i> and <span> icon tags
    preg_match_all('/<i\s+class=[\'"]([^\'"]+)[\'"]/i', $content, $m);
    foreach ($m[1] as $cls) {
        $allIcons[$bn][] = $cls;
        
        // Check if fa-xxx is used without fa-solid, fa-regular, fa-brands, fas, far, fab
        $classes = explode(' ', $cls);
        $hasFaPrefix = false;
        $iconName = '';
        foreach ($classes as $c) {
            $c = trim($c);
            if (in_array($c, ['fa', 'fas', 'far', 'fab', 'fa-solid', 'fa-regular', 'fa-brands'])) {
                $hasFaPrefix = true;
            }
            if (strpos($c, 'fa-') === 0 && !in_array($c, ['fa-solid', 'fa-regular', 'fa-brands', 'fa-fw', 'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-lg', 'fa-sm', 'fa-xs', 'fa-spin', 'fa-pulse'])) {
                $iconName = $c;
            }
        }
        if (!$hasFaPrefix && !empty($iconName)) {
            $missingSolidPrefix[$bn][] = [
                'full_class' => $cls,
                'icon' => $iconName
            ];
        }
    }

    // Find icon container classes in css or html
    if (preg_match_all('/\.(icon-[a-z0-9_-]+|avatar-[a-z0-9_-]+|badge-[a-z0-9_-]+|lms-[a-z0-9_-]+)\s*\{([^}]+)\}/i', $content, $cm)) {
        foreach ($cm[1] as $idx => $cname) {
            $customIconWrappers[$bn][$cname] = $cm[2][$idx];
        }
    }
}

echo "=== LMS ICONS AUDIT ===\n\n";
echo "Files scanned: " . count($files) . "\n\n";

if (!empty($missingSolidPrefix)) {
    echo "=== ICONS MISSING FA-SOLID / FA-REGULAR / FAS PREFIX ===\n";
    foreach ($missingSolidPrefix as $bn => $list) {
        echo "File: $bn (" . count($list) . " instances):\n";
        foreach ($list as $item) {
            echo "   - '{$item['full_class']}' (icon: {$item['icon']})\n";
        }
    }
    echo "\n";
} else {
    echo "All <i> tags have fa prefixes!\n\n";
}

// Let's check against FA CSS
$faCss = file_get_contents('C:/xampp/htdocs/school_system/assets/vendor/fontawesome/css/all.min.css');
$invalidIcons = [];

foreach ($allIcons as $bn => $classesList) {
    foreach ($classesList as $cls) {
        $parts = explode(' ', $cls);
        foreach ($parts as $p) {
            $p = trim($p);
            if (strpos($p, 'fa-') === 0 && !in_array($p, ['fa-solid', 'fa-regular', 'fa-brands', 'fa-fw', 'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-lg', 'fa-sm', 'fa-xs', 'fa-spin', 'fa-pulse', 'fa-stack', 'fa-stack-1x', 'fa-stack-2x', 'fa-inverse'])) {
                $icon = substr($p, 3);
                if (strpos($faCss, '.fa-' . $icon . ':before') === false && strpos($faCss, '.fa-' . $icon . '::before') === false) {
                    $invalidIcons[$bn][$icon] = $cls;
                }
            }
        }
    }
}

if (!empty($invalidIcons)) {
    echo "=== INVALID / NON-EXISTENT ICONS IN LMS FILES ===\n";
    foreach ($invalidIcons as $bn => $icons) {
        echo "File: $bn:\n";
        foreach ($icons as $ic => $fullCls) {
            echo "   - fa-$ic (in class: '$fullCls')\n";
        }
    }
    echo "\n";
} else {
    echo "All icon names exist in Font Awesome 6 Free!\n\n";
}

// Check custom styles inside LMS files
echo "=== INLINE STYLE / WRAPPER AUDIT IN LMS FILES ===\n";
foreach ($files as $file) {
    $content = file_get_contents($file);
    $bn = basename($file);
    // Look for styles affecting .icon, .card-icon, i, fa
    if (preg_match_all('/(<style[^>]*>.*?<\/style>)/is', $content, $sm)) {
        foreach ($sm[1] as $st) {
            if (preg_match('/(font-family|\.icon|\.card-icon|\.badge|\.avatar|width:\s*\d+px;\s*height:\s*\d+px)/i', $st)) {
                echo "File $bn has custom style block touching icons/wrappers.\n";
            }
        }
    }
}
