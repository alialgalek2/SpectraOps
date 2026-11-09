<?php
$urls = [
    'http://localhost/school_system/assets/vendor/fontawesome/css/all.min.css',
    'http://localhost/school_system/assets/vendor/fontawesome/webfonts/fa-solid-900.woff2',
    'http://localhost/school_system/assets/vendor/fontawesome/webfonts/fa-regular-400.woff2',
    'http://localhost/school_system/assets/vendor/fontawesome/webfonts/fa-brands-400.woff2',
    'http://localhost/school_system/assets/css/theme.css'
];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $contentLength = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    $err = curl_error($ch);
    unset($ch);

    echo "URL: $url\n";
    echo "  HTTP Code:    $httpCode\n";
    echo "  Content-Type: $contentType\n";
    echo "  Length:       $contentLength bytes\n";
    if ($err) echo "  Error:        $err\n";
    echo "\n";
}
