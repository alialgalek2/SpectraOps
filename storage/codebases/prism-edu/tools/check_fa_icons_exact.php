<?php
$faCss = file_get_contents('C:/xampp/htdocs/school_system/assets/vendor/fontawesome/css/all.min.css');

$iconsToCheck = [
    'bullhorn',
    'calendar-days',
    'book-journal-whills',
    'journal-whills',
    'user-graduate',
    'chalkboard-user',
    'file-signature',
    'hands-holding-child',
    'file-pen',
    'book-open',
    'clipboard-user',
    'clock-rotate-left',
    'hourglass-half',
    'book-bookmark',
    'users'
];

echo "=== CHECKING EXACT ICON NAMES IN FA 6.4.0 CSS ===\n";
foreach ($iconsToCheck as $name) {
    $found = (strpos($faCss, '.fa-' . $name . ':before') !== false || strpos($faCss, '.fa-' . $name . '::before') !== false);
    echo str_pad("fa-$name", 25) . " => " . ($found ? "EXISTS" : "MISSING / INVALID") . "\n";
}
