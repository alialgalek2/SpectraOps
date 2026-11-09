<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'Admin User';

ob_start();
include __DIR__ . '/../modules/HR/employees.php';
$html = ob_get_clean();

$doc = new DOMDocument();
libxml_use_internal_errors(true);
$doc->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($doc);
$rows = $xpath->query('//table/tbody/tr');

echo "Total rows: " . $rows->length . "\n";
foreach ($rows as $i => $row) {
    $tds = $xpath->query('td', $row);
    $td5 = $tds->item(4); // 0-indexed td[5]
    $td5_html = $td5 ? $doc->saveHTML($td5) : 'NONE';
    $code = $tds->item(0) ? trim($tds->item(0)->textContent) : '';
    $name = $tds->item(1) ? trim($tds->item(1)->textContent) : '';
    echo "Row " . ($i + 1) . ": [$code] $name => Col 5: " . trim(preg_replace('/\s+/', ' ', $td5_html)) . "\n";
}
