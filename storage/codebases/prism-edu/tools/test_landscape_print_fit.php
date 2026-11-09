<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING 1-PAGE LANDSCAPE PRINT MATRIX FIT & STYLES ===\n\n";

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

// 1. Test Regular Standalone View
$_GET = ['type' => 'class', 'id' => 1];
ob_start();
include __DIR__ . '/../modules/Administration/print_timetable.php';
$htmlRegular = ob_get_clean();

// Check for single page landscape print rules
if (strpos($htmlRegular, 'size: landscape') !== false && strpos($htmlRegular, 'height: 100vh') !== false) {
    echo "  [PASS] Landscape @page rules and 100vh height boundaries present in print_timetable.php\n";
} else {
    echo "  [FAIL] Missing landscape print rules.\n";
    exit(1);
}

if (strpos($htmlRegular, 'page-break-inside: avoid') !== false) {
    echo "  [PASS] Page-break prevention rules configured for strict 1-page output.\n";
} else {
    echo "  [FAIL] Missing page-break prevention rules.\n";
    exit(1);
}

// 2. Test Embedded Mode (iframe in preview modal)
$_GET = ['type' => 'class', 'id' => 1, 'embedded' => 1];
ob_start();
include __DIR__ . '/../modules/Administration/print_timetable.php';
$htmlEmbedded = ob_get_clean();

if (strpos($htmlEmbedded, 'class="is-embedded"') !== false) {
    echo "  [PASS] is-embedded class applied to body for in-modal preview scaling.\n";
} else {
    echo "  [FAIL] is-embedded class missing on body.\n";
    exit(1);
}

if (strpos($htmlEmbedded, 'timetable-table') !== false) {
    echo "  [PASS] .timetable-table matrix rendered cleanly.\n";
} else {
    echo "  [FAIL] .timetable-table missing in output.\n";
    exit(1);
}

echo "\n=== ALL 1-PAGE LANDSCAPE PRINT VERIFICATIONS PASSED ===\n";
