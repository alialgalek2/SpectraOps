<?php
$files = glob(__DIR__ . '/../modules/*/*.php');

foreach ($files as $file) {
    $c = file_get_contents($file);
    if (preg_match('/\'extra_nav_items\'\s*=>\s*\[(.*?)\]/s', $c, $m)) {
        echo "File: " . basename(dirname($file)) . '/' . basename($file) . "\n";
        echo trim($m[1]) . "\n";
        echo "-----------------------------------------\n";
    }
}
