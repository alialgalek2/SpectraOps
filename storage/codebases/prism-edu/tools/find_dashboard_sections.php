<?php
$content = file_get_contents('C:/xampp/htdocs/school_system/dashboard.php');
$lines = explode("\n", $content);
echo "Total lines: " . count($lines) . "\n";

foreach ($lines as $num => $line) {
    if (preg_match('/roster|student|table|card|widget|section/i', $line, $m)) {
        if (stripos($line, 'roster') !== false || stripos($line, 'student') !== false) {
            echo "Line " . ($num + 1) . ": " . trim(substr($line, 0, 120)) . "\n";
        }
    }
}
