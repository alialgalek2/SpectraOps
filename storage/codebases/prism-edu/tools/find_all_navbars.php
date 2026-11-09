<?php
$allFiles = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(dirname(__DIR__), RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        if (strpos($path, 'vendor') === false && strpos($path, 'scratch') === false) {
            $allFiles[] = $path;
        }
    }
}

echo "=== SCANNING NAVBAR BRANDS ACROSS ALL FILES ===\n\n";

foreach ($allFiles as $file) {
    $content = file_get_contents($file);
    $rel = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file);

    // Look for <nav or <header containing navbar-brand or brand
    if (preg_match('/(<nav[^>]*>.*?<\/nav>|<header[^>]*>.*?<\/header>)/s', $content, $m)) {
        $navSnippet = $m[1];
        if (preg_match('/(<a[^>]*class="[^"]*navbar-brand[^"]*"[^>]*>.*?<\/a>|<a[^>]*href="[^"]*dashboard\.php"[^>]*>.*?<\/a>)/s', $navSnippet, $brandMatch)) {
            echo "FILE: $rel\n";
            echo "BRAND HTML:\n" . trim($brandMatch[1]) . "\n";
            echo str_repeat("-", 80) . "\n";
        }
    }
}
