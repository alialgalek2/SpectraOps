<?php
require_once __DIR__ . '/../core/init.php';

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

echo "=== TESTING ACADEMIC YEAR FIELD VISIBILITY ===\n\n";

// Test 1: institution_settings.php
ob_start();
require __DIR__ . '/../modules/Administration/institution_settings.php';
$html1 = ob_get_clean();

if (strpos($html1, 'name="academic_year"') !== false && strpos($html1, 'Active Academic / School Year') !== false) {
    echo "[PASS] modules/Administration/institution_settings.php has visible 'Active Academic / School Year' input field.\n";
} else {
    echo "[FAIL] modules/Administration/institution_settings.php missing input field.\n";
}

// Test 2: index.php?tab=branding
$_GET['tab'] = 'branding';
ob_start();
require __DIR__ . '/../modules/Administration/index.php';
$html2 = ob_get_clean();

if (strpos($html2, 'name="academic_year"') !== false && strpos($html2, 'Active Academic / School Year') !== false) {
    echo "[PASS] modules/Administration/index.php?tab=branding has visible 'Active Academic / School Year' input field.\n";
} else {
    echo "[FAIL] modules/Administration/index.php?tab=branding missing input field.\n";
}

echo "\n=== COMPLETED ===\n";
