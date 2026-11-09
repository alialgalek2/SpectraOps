<?php
require 'core/init.php';
$s = $pdo->query("SELECT setting_key, setting_value FROM school_settings WHERE setting_key LIKE '%logo%'")->fetchAll(PDO::FETCH_ASSOC);
echo "school_settings:\n";
print_r($s);

$cols = $pdo->query("DESCRIBE institution_branding")->fetchAll(PDO::FETCH_COLUMN);
echo "Columns:\n";
print_r($cols);
