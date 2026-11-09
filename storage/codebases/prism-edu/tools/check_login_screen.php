<?php
$ch = curl_init('http://localhost:8080/school_system/login.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$html = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: " . $code . "\n";
echo "Has Top Header Navbar: " . (strpos($html, '<header') !== false ? 'YES' : 'NO') . "\n";
echo "Has Theme Toggle in Card: " . (strpos($html, 'theme-toggle-btn') !== false ? 'YES' : 'NO') . "\n";
echo "Has Language Switcher in Card: " . (strpos($html, 'fa-globe') !== false ? 'YES' : 'NO') . "\n";
echo "Has Theme Script: " . (strpos($html, 'theme_toggle.js') !== false ? 'YES' : 'NO') . "\n";
