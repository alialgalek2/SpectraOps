<?php
/**
 * Systematic Icon & FontAwesome Display Audit
 */

$rootDir = dirname(__DIR__);
$phpFiles = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir));
foreach ($iterator as $file) {
    if ($file->isDir()) continue;
    $path = $file->getPathname();
    if (strpos($path, 'vendor') !== false || strpos($path, '.git') !== false || strpos($path, 'scratch') !== false) {
        continue;
    }
    if (pathinfo($path, PATHINFO_EXTENSION) === 'php') {
        $phpFiles[] = $path;
    }
}

echo "Total PHP files scanned: " . count($phpFiles) . "\n\n";

$pagesWithoutFA = [];
$allIconsFound = [];

foreach ($phpFiles as $file) {
    $content = file_get_contents($file);
    $rel = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $file);

    // Check if it's an HTML-rendering page
    if (stripos($content, '<!DOCTYPE html>') !== false || stripos($content, '<html') !== false) {
        if (stripos($content, 'font-awesome') === false && stripos($content, 'all.min.css') === false) {
            $pagesWithoutFA[] = $rel;
        }
    }

    // Extract all <i class="..."> tags
    if (preg_match_all('/<i\s+[^>]*class=["\']([^"\']+)["\']/i', $content, $matches)) {
        foreach ($matches[1] as $classStr) {
            $classes = explode(' ', $classStr);
            foreach ($classes as $c) {
                $c = trim($c);
                if (strpos($c, 'fa-') === 0 && !in_array($c, ['fa-solid', 'fa-regular', 'fa-brands', 'fa-fw', 'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-xs', 'fa-sm', 'fa-lg', 'fa-xl', 'fa-2xl', 'fa-spin', 'fa-pulse', 'fa-rotate-90', 'fa-rotate-180', 'fa-rotate-270', 'fa-flip-horizontal', 'fa-flip-vertical', 'fa-beat', 'fa-fade', 'fa-bounce', 'fa-flip', 'fa-shake', 'fa-spin-reverse'])) {
                    $allIconsFound[$c] = ($allIconsFound[$c] ?? 0) + 1;
                }
            }
        }
    }

    // Extract icon definitions in PHP arrays (e.g. 'icon' => 'fa-...')
    if (preg_match_all('/[\'"]icon[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $content, $matches)) {
        foreach ($matches[1] as $iconStr) {
            $parts = explode(' ', $iconStr);
            foreach ($parts as $p) {
                $p = trim($p);
                if (strpos($p, 'fa-') === 0 && !in_array($p, ['fa-solid', 'fa-regular', 'fa-brands', 'fa-fw', 'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x', 'fa-xs', 'fa-sm', 'fa-lg', 'fa-xl', 'fa-2xl'])) {
                    $allIconsFound[$p] = ($allIconsFound[$p] ?? 0) + 1;
                }
            }
        }
    }
}

echo "=== 1. HTML PAGES MISSING FONT-AWESOME CDN ===\n";
if (empty($pagesWithoutFA)) {
    echo "All HTML pages correctly include Font Awesome 6!\n";
} else {
    foreach ($pagesWithoutFA as $p) {
        echo " - [MISSING FA] $p\n";
    }
}

echo "\n=== 2. UNIQUE ICONS DETECTED (" . count($allIconsFound) . ") ===\n";
ksort($allIconsFound);
foreach ($allIconsFound as $icon => $count) {
    echo " $icon ($count times)\n";
}
