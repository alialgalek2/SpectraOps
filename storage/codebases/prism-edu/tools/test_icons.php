<?php
$rootDir = 'C:/xampp/htdocs/school_system';
$dirIterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        function ($fileInfo, $key, $iterator) {
            $fn = $fileInfo->getFilename();
            if ($fn === '.git' || $fn === 'vendor' || $fn === 'assets') {
                return false;
            }
            return true;
        }
    )
);

$icons = [];
foreach ($dirIterator as $file) {
    if ($file->isFile() && preg_match('/\.(php|html|js)$/', $file->getFilename())) {
        $content = file_get_contents($file->getPathname());
        // Match FA classes like fa-solid fa-home, fas fa-home, fa-light, etc.
        if (preg_match_all('/(?:fa[srldb]?|fa-solid|fa-regular|fa-brands|fa-light|fa-thin|fa-duotone)\s+fa-([a-z0-9-]+)/', $content, $matches)) {
            foreach ($matches[1] as $ic) {
                $icons[$ic][] = str_replace($rootDir, '', $file->getPathname());
            }
        }
        // Also match fa-xxx without preceding fa class just in case
        if (preg_match_all('/class=[\'"][^\'"]*fa-([a-z0-9-]+)[^\'"]*[\'"]/', $content, $matches)) {
            foreach ($matches[1] as $ic) {
                // ignore layout classes like fa-2x, fa-fw, fa-lg, fa-sm, fa-spin, fa-pulse, fa-stack, etc.
                if (!in_array($ic, ['2x', '3x', '4x', '5x', 'lg', 'sm', 'xs', 'fw', 'spin', 'pulse', 'stack', 'stack-1x', 'stack-2x', 'inverse', 'rotate-90', 'rotate-180', 'rotate-270', 'flip-horizontal', 'flip-vertical', 'solid', 'regular', 'brands', 'light', 'thin', 'duotone'])) {
                    $icons[$ic][] = str_replace($rootDir, '', $file->getPathname());
                }
            }
        }
    }
}

echo "Found " . count($icons) . " unique icon names across system files.\n";

$faCssPath = $rootDir . '/assets/vendor/fontawesome/css/all.min.css';
if (!file_exists($faCssPath)) {
    echo "ERROR: Font Awesome CSS not found at $faCssPath\n";
    exit(1);
}

$faCss = file_get_contents($faCssPath);
$missing = [];
$found = 0;

foreach ($icons as $ic => $fileList) {
    if (strpos($faCss, '.fa-' . $ic . ':before') !== false || strpos($faCss, '.fa-' . $ic . '::before') !== false) {
        $found++;
    } else {
        $missing[$ic] = array_values(array_unique($fileList));
    }
}

echo "Successfully matched $found / " . count($icons) . " icons against Font Awesome 6.4.0 Free.\n";

if (!empty($missing)) {
    echo "\n=== POTENTIAL MISSING / PRO / INVALID ICONS (" . count($missing) . ") ===\n";
    foreach ($missing as $ic => $files) {
        echo " - fa-$ic : used in " . implode(', ', $files) . "\n";
    }
} else {
    echo "\nAll icon classes are valid and exist in Font Awesome 6.4.0 Free CSS!\n";
}
