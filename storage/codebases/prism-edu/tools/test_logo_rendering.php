<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING LOGO RESOLUTION & RENDERING ===\n\n";

$logo_url = get_school_logo_url();
echo "Resolved School Logo URL: " . $logo_url . "\n";

if (empty($logo_url)) {
    echo "[FAIL] School logo URL is empty!\n";
} else {
    echo "[PASS] School logo URL is non-empty.\n";
}

$brand_html = render_navbar_brand('Admin Portal', 'index.php', 'fa-solid fa-gauge');
echo "Rendered Brand HTML:\n" . $brand_html . "\n\n";

if (strpos($brand_html, $logo_url) !== false) {
    echo "[PASS] Brand HTML contains the correct school logo URL.\n";
} else {
    echo "[FAIL] Brand HTML missing school logo URL.\n";
}

// Test live HTTP fetch on port 8080
$test_urls = [
    'http://localhost:8080/school_system/login.php',
    'http://localhost:8080/school_system/dashboard.php',
    'http://localhost:8080/school_system/modules/HR/index.php',
    'http://localhost:8080/school_system/modules/Finance/index.php',
    'http://localhost:8080/school_system/modules/Grading/index.php',
    'http://localhost:8080/school_system/modules/LMS/index.php',
];

echo "\n=== TESTING LIVE HTTP ENDPOINTS FOR LOGO ===\n";
foreach ($test_urls as $url) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 5,
            'ignore_errors' => true
        ]
    ]);
    $html = @file_get_contents($url, false, $ctx);
    if ($html === false) {
        echo "[ERR] Could not connect to $url\n";
    } else {
        $has_logo = (strpos($html, 'logo_1788427755.jpg') !== false || strpos($html, 'uploads/branding') !== false);
        echo ($has_logo ? "[PASS]" : "[INFO (redirect/login)]") . " $url - HTML length: " . strlen($html) . ($has_logo ? " (Contains Logo)" : "") . "\n";
    }
}

echo "\n=== ALL LOGO TESTS COMPLETED ===\n";
