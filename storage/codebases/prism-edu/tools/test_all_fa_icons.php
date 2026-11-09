<?php
/**
 * Test all extracted FontAwesome icons against known FA6 Free icons.
 */

// Comprehensive list of Font Awesome 6 Free icon names (without fa- prefix)
$fa6Url = "https://raw.githubusercontent.com/FortAwesome/Font-Awesome/6.4.0/metadata/icons.json";

$jsonStr = @file_get_contents($fa6Url);
if (!$jsonStr) {
    // Fallback using curl if allow_url_fopen is disabled
    $ch = curl_init($fa6Url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'SchoolSystemIconAuditor/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $jsonStr = curl_exec($ch);
    curl_close($ch);
}

if (!$jsonStr) {
    echo "Could not fetch remote FA6 icons metadata, testing locally.\n";
    exit(1);
}

$metadata = json_decode($jsonStr, true);
$freeSolid = [];
$freeRegular = [];
$freeBrands = [];

foreach ($metadata as $iconName => $data) {
    $styles = $data['free'] ?? [];
    if (in_array('solid', $styles)) $freeSolid[$iconName] = true;
    if (in_array('regular', $styles)) $freeRegular[$iconName] = true;
    if (in_array('brands', $styles)) $freeBrands[$iconName] = true;
}

echo "FA6 Free Metadata Loaded:\n";
echo " - Solid icons: " . count($freeSolid) . "\n";
echo " - Regular icons: " . count($freeRegular) . "\n";
echo " - Brands icons: " . count($freeBrands) . "\n\n";

// Scan all PHP files
$rootDir = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir));
$foundIcons = [];

foreach ($iterator as $file) {
    if ($file->isDir()) continue;
    $path = $file->getPathname();
    if (strpos($path, 'vendor') !== false || strpos($path, '.git') !== false || strpos($path, 'scratch') !== false) {
        continue;
    }
    if (pathinfo($path, PATHINFO_EXTENSION) !== 'php') continue;

    $content = file_get_contents($path);
    $rel = str_replace($rootDir . DIRECTORY_SEPARATOR, '', $path);

    // match <i class="fa-solid fa-xxx ...">
    if (preg_match_all('/class=["\']([^"\']*(?:fa-solid|fa-regular|fa-brands|fas|far|fab)[^"\']*)["\']/i', $content, $matches)) {
        foreach ($matches[1] as $classStr) {
            $classes = explode(' ', $classStr);
            $style = 'solid';
            if (in_array('fa-regular', $classes) || in_array('far', $classes)) $style = 'regular';
            if (in_array('fa-brands', $classes) || in_array('fab', $classes)) $style = 'brands';

            foreach ($classes as $c) {
                if (strpos($c, 'fa-') === 0 && !in_array($c, [
                    'fa-solid', 'fa-regular', 'fa-brands', 'fa-fw', 'fa-2x', 'fa-3x', 'fa-4x', 'fa-5x',
                    'fa-xs', 'fa-2xs', 'fa-sm', 'fa-lg', 'fa-xl', 'fa-2xl', 'fa-spin', 'fa-pulse',
                    'fa-rotate-90', 'fa-rotate-180', 'fa-rotate-270', 'fa-flip-horizontal', 'fa-flip-vertical',
                    'fa-beat', 'fa-fade', 'fa-bounce', 'fa-flip', 'fa-shake'
                ])) {
                    $iconName = substr($c, 3);
                    // Skip PHP interpolation strings
                    if (strpos($iconName, '<') !== false || strpos($iconName, '?') !== false || strpos($iconName, '$') !== false) {
                        continue;
                    }
                    $foundIcons[$rel][] = ['icon' => $iconName, 'class' => $c, 'style' => $style, 'raw' => $classStr];
                }
            }
        }
    }
}

$invalidIcons = [];
foreach ($foundIcons as $file => $icons) {
    foreach ($icons as $item) {
        $icon = $item['icon'];
        $style = $item['style'];
        
        $isValid = false;
        if ($style === 'solid' && isset($freeSolid[$icon])) $isValid = true;
        elseif ($style === 'regular' && (isset($freeRegular[$icon]) || isset($freeSolid[$icon]))) $isValid = true;
        elseif ($style === 'brands' && isset($freeBrands[$icon])) $isValid = true;
        elseif (isset($freeSolid[$icon]) || isset($freeRegular[$icon]) || isset($freeBrands[$icon])) $isValid = true;

        if (!$isValid) {
            $invalidIcons[] = [
                'file' => $file,
                'icon' => $item['class'],
                'style' => $style,
                'raw' => $item['raw']
            ];
        }
    }
}

echo "=== INVALID / PRO-ONLY / BROKEN ICONS FOUND (" . count($invalidIcons) . ") ===\n";
foreach ($invalidIcons as $inv) {
    echo " [BROKEN] File: {$inv['file']}\n";
    echo "          Icon: {$inv['icon']} (Style: {$inv['style']})\n";
    echo "          Class: {$inv['raw']}\n\n";
}
