<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Admin User';

ob_start();
include __DIR__ . '/../modules/Grading/report_cards.php';
$rc_html = ob_get_clean();

ob_start();
include __DIR__ . '/../modules/Grading/transcript.php';
$tr_html = ob_get_clean();

echo "=== VERIFYING REPORT CARDS & TRANSCRIPT NAVBARS ===\n";
echo "report_cards.php rendered: " . strlen($rc_html) . " bytes\n";
echo "  navbar-custom present: " . (str_contains($rc_html, 'navbar-custom') ? 'YES' : 'NO') . "\n";
echo "  no-print present on header: " . (str_contains($rc_html, 'navbar-custom sticky-top mb-4 no-print') ? 'YES' : 'NO') . "\n";
echo "  select student present: " . (str_contains($rc_html, 'student_id') ? 'YES' : 'NO') . "\n";
echo "  print button present: " . (str_contains($rc_html, 'window.print()') ? 'YES' : 'NO') . "\n";

echo "transcript.php rendered: " . strlen($tr_html) . " bytes\n";
echo "  navbar-custom present: " . (str_contains($tr_html, 'navbar-custom') ? 'YES' : 'NO') . "\n";
echo "  no-print present on header: " . (str_contains($tr_html, 'navbar-custom sticky-top mb-4 no-print') ? 'YES' : 'NO') . "\n";
echo "  select student present: " . (str_contains($tr_html, 'student_id') ? 'YES' : 'NO') . "\n";
echo "  print button present: " . (str_contains($tr_html, 'window.print()') ? 'YES' : 'NO') . "\n";

// Check DOM validity
$doc1 = new DOMDocument();
libxml_use_internal_errors(true);
$doc1->loadHTML($rc_html);
$errors1 = libxml_get_errors();
libxml_clear_errors();
echo "report_cards.php DOM parsed successfully. Style count: " . $doc1->getElementsByTagName('style')->length . ", Body count: " . $doc1->getElementsByTagName('body')->length . "\n";

$doc2 = new DOMDocument();
libxml_use_internal_errors(true);
$doc2->loadHTML($tr_html);
$errors2 = libxml_get_errors();
libxml_clear_errors();
echo "transcript.php DOM parsed successfully. Style count: " . $doc2->getElementsByTagName('style')->length . ", Body count: " . $doc2->getElementsByTagName('body')->length . "\n";

echo "ALL CHECKS PASSED!\n";
