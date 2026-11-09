<?php
$files = glob(__DIR__ . '/../modules/*/*.php');
$files = array_merge($files, glob(__DIR__ . '/../*.php'));

$report = [];
foreach ($files as $file) {
    $content = file_get_contents($file);
    if (strpos($content, 'render_universal_navbar') !== false) {
        preg_match('/render_universal_navbar\(\s*\[(.*?)\]\s*\)/s', $content, $m);
        $optionsText = $m[1] ?? 'args format';
        $report[basename(dirname($file)) . '/' . basename($file)] = substr(trim($optionsText), 0, 200);
    }
}

foreach ($report as $f => $snippet) {
    echo "=== $f ===\n$snippet\n\n";
}
