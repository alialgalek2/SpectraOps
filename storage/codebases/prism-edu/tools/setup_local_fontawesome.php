<?php
/**
 * Setup Local Font Awesome 6 Assets (Offline & Intranet Support)
 */

$targetDir = dirname(__DIR__) . '/assets/vendor/fontawesome';
$cssDir = $targetDir . '/css';
$webfontsDir = $targetDir . '/webfonts';

if (!is_dir($cssDir)) mkdir($cssDir, 0777, true);
if (!is_dir($webfontsDir)) mkdir($webfontsDir, 0777, true);

function fetchUrl($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && !empty($res)) {
        return $res;
    }
    return false;
}

$baseUrl = "https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0";

echo "Downloading Font Awesome 6.4.0 CSS...\n";
$cssContent = fetchUrl("$baseUrl/css/all.min.css");
if ($cssContent) {
    // Save all.min.css
    file_put_contents("$cssDir/all.min.css", $cssContent);
    echo "Saved: assets/vendor/fontawesome/css/all.min.css (" . strlen($cssContent) . " bytes)\n";
} else {
    echo "[ERROR] Failed to download all.min.css from cdnjs\n";
}

$webfonts = [
    'fa-solid-900.woff2',
    'fa-solid-900.ttf',
    'fa-regular-400.woff2',
    'fa-regular-400.ttf',
    'fa-brands-400.woff2',
    'fa-brands-400.ttf',
    'fa-v4compatibility.woff2',
    'fa-v4compatibility.ttf'
];

foreach ($webfonts as $fontFile) {
    echo "Downloading webfont: $fontFile...\n";
    $fontData = fetchUrl("$baseUrl/webfonts/$fontFile");
    if ($fontData) {
        file_put_contents("$webfontsDir/$fontFile", $fontData);
        echo " - Saved: $fontFile (" . strlen($fontData) . " bytes)\n";
    } else {
        echo " - [WARN] Could not download $fontFile\n";
    }
}

echo "\nLocal Font Awesome Setup Complete!\n";
