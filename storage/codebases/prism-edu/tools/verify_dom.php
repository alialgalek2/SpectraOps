<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 4;
$_SESSION['role'] = 'teacher';
$_SESSION['username'] = 'teacher';
$_SESSION['full_name'] = 'Teacher Demo';

ob_start();
include __DIR__ . '/../modules/LMS/assignments.php';
$html = ob_get_clean();

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML($html);
libxml_clear_errors();

$h1 = $doc->getElementsByTagName('h1');
$styles = $doc->getElementsByTagName('style');
$body = $doc->getElementsByTagName('body');

echo "HTML size: " . strlen($html) . " bytes\n";
echo "Style tags count: " . $styles->length . "\n";
echo "Body tags count: " . $body->length . "\n";
if ($h1->length > 0) {
    echo "H1 text: " . trim($h1->item(0)->textContent) . "\n";
}
if ($body->length > 0) {
    echo "Body child elements: " . $body->item(0)->childNodes->length . "\n";
}

// Check that body is not inside style
$xpath = new DOMXPath($doc);
$bodyInsideStyle = $xpath->query('//style//body');
echo "Body elements inside style: " . $bodyInsideStyle->length . "\n";

$cards = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' metric-card ')]");
echo "Metric cards count: " . $cards->length . "\n";

$buttons = $doc->getElementsByTagName('button');
echo "Buttons count: " . $buttons->length . "\n";
