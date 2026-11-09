<?php
require 'core/init.php';

// Update school_settings
$pdo->prepare("UPDATE school_settings SET setting_value = 'uploads/branding/prism_logo_dark_canvas.png' WHERE setting_key = 'school_logo'")->execute();

// Insert or update light/dark specific keys in school_settings
$stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM school_settings WHERE setting_key = ?");
$stmtInsert = $pdo->prepare("INSERT INTO school_settings (setting_key, setting_value) VALUES (?, ?)");
$stmtUpdate = $pdo->prepare("UPDATE school_settings SET setting_value = ? WHERE setting_key = ?");

foreach ([
    'school_logo_light' => 'uploads/branding/prism_logo_dark_canvas.png',
    'school_logo_dark'  => 'uploads/branding/prism_logo_light_transparent.png'
] as $key => $val) {
    $stmtCheck->execute([$key]);
    if ($stmtCheck->fetchColumn() > 0) {
        $stmtUpdate->execute([$val, $key]);
    } else {
        $stmtInsert->execute([$key, $val]);
    }
}

// Update institution_branding
$pdo->prepare("UPDATE institution_branding SET logo_path = 'uploads/branding/prism_logo_dark_canvas.png'")->execute();

echo "Database logo settings successfully updated.\n";
