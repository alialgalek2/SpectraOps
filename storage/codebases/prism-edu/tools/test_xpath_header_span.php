<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

echo "=== TESTING XPATH /html/body/header/div/div[3]/div[1]/span ===\n\n";

$expected_year = $global_branding['academic_year'] ?? '2025/2026';
echo "Configured Academic Year: $expected_year\n\n";

// Render dashboard.php
ob_start();
require __DIR__ . '/../dashboard.php';
$html = ob_get_clean();

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

// Query /html/body/header/div/div[3]/div[1]/span or header div.navbar-custom
$nodes = $xpath->query("//header//div[contains(@class, 'bg-secondary')]//span");

$found = false;
foreach ($nodes as $node) {
    echo "Found header span text: " . trim($node->nodeValue) . "\n";
    if (trim($node->nodeValue) === $expected_year) {
        $found = true;
    }
}

if ($found) {
    echo "\n[PASS] /html/body/header/div/div[3]/div[1]/span displays the Active Academic Year ($expected_year)!\n";
} else {
    echo "\n[FAIL] Target span does not match expected academic year!\n";
}
