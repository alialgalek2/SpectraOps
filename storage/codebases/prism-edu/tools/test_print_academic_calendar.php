<?php
/**
 * scratch/test_print_academic_calendar.php
 * Automated Verification Suite for Academic Calendar Print & Intermediary Pre-Print Preview Layer
 */

require_once __DIR__ . '/../core/init.php';

echo "=== STARTING ACADEMIC CALENDAR PRINT & PREVIEW LAYER VERIFICATION ===\n\n";

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Admin System Test';

$all_passed = true;

// 1. Test modules/Administration/print_academic_calendar.php (Monthly Matrix Grid)
echo "[TEST 1] Testing print_academic_calendar.php (Monthly Grid & Landscape CSS)...\n";
$_GET = ['format' => 'month', 'year' => '2025/2026', 'month' => '9', 'cal_year' => '2026'];
ob_start();
include __DIR__ . '/../modules/Administration/print_academic_calendar.php';
$html_month = ob_get_clean();

if (
    strpos($html_month, 'cal-grid-matrix') !== false &&
    strpos($html_month, 'day-col-header') !== false &&
    strpos($html_month, 'size: landscape') !== false &&
    strpos($html_month, 'board-footer') !== false
) {
    echo "  [PASS] Monthly Matrix Grid rendered successfully with 1-page landscape CSS rules.\n";
} else {
    echo "  [FAIL] Monthly Matrix Grid missing required DOM elements.\n";
    $all_passed = false;
}

// 2. Test modules/Administration/print_academic_calendar.php (Full Year Milestone Ledger)
echo "\n[TEST 2] Testing print_academic_calendar.php (Full Year Ledger)...\n";
$_GET = ['format' => 'year', 'year' => '2025/2026'];
ob_start();
include __DIR__ . '/../modules/Administration/print_academic_calendar.php';
$html_year = ob_get_clean();

if (
    strpos($html_year, 'ledger-table') !== false &&
    strpos($html_year, 'Academic Event & Milestone') !== false || strpos($html_year, 'الحدث الأكاديمي') !== false
) {
    echo "  [PASS] Full Year Milestone Ledger rendered successfully.\n";
} else {
    echo "  [FAIL] Full Year Milestone Ledger missing required table structures.\n";
    $all_passed = false;
}

// 3. Test Embedded Mode (embedded=1)
echo "\n[TEST 3] Testing print_academic_calendar.php (Embedded Mode)...\n";
$_GET = ['format' => 'month', 'year' => '2025/2026', 'embedded' => '1'];
ob_start();
include __DIR__ . '/../modules/Administration/print_academic_calendar.php';
$html_emb = ob_get_clean();

if (strpos($html_emb, 'class="is-embedded"') !== false || strpos($html_emb, 'is-embedded') !== false) {
    echo "  [PASS] Embedded mode class attached successfully for iframe display.\n";
} else {
    echo "  [FAIL] Embedded mode class missing.\n";
    $all_passed = false;
}

// 4. Test LMS Forwarder modules/LMS/print_academic_calendar.php
echo "\n[TEST 4] Testing modules/LMS/print_academic_calendar.php forwarder...\n";
$_GET = ['format' => 'month', 'year' => '2025/2026'];
ob_start();
include __DIR__ . '/../modules/LMS/print_academic_calendar.php';
$html_fwd = ob_get_clean();

if (strpos($html_fwd, 'cal-grid-matrix') !== false) {
    echo "  [PASS] LMS print forwarder successfully routed to Administration print engine.\n";
} else {
    echo "  [FAIL] LMS print forwarder failed.\n";
    $all_passed = false;
}

// 5. Test Pre-Print Modal in modules/Administration/academic_calendar.php
echo "\n[TEST 5] Testing modules/Administration/academic_calendar.php Pre-Print Modal DOM...\n";
$_GET = [];
ob_start();
include __DIR__ . '/../modules/Administration/academic_calendar.php';
$html_admin_cal = ob_get_clean();

if (
    strpos($html_admin_cal, 'id="calendarPrintModal"') !== false &&
    strpos($html_admin_cal, 'id="calendarPrintPreviewFrame"') !== false &&
    strpos($html_admin_cal, 'openCalendarPrintPreview') !== false &&
    strpos($html_admin_cal, 'executeTargetedCalendarPrint') !== false
) {
    echo "  [PASS] Administration academic_calendar.php contains complete in-page Print Preview Modal & JS controls.\n";
} else {
    echo "  [FAIL] Administration academic_calendar.php missing modal or JS controls.\n";
    $all_passed = false;
}

// 6. Test Pre-Print Modal in modules/LMS/calendar.php
echo "\n[TEST 6] Testing modules/LMS/calendar.php Pre-Print Modal DOM...\n";
$_GET = [];
ob_start();
include __DIR__ . '/../modules/LMS/calendar.php';
$html_lms_cal = ob_get_clean();

if (
    strpos($html_lms_cal, 'id="calendarPrintModal"') !== false &&
    strpos($html_lms_cal, 'id="calendarPrintPreviewFrame"') !== false &&
    strpos($html_lms_cal, 'openCalendarPrintPreview') !== false &&
    strpos($html_lms_cal, 'executeTargetedCalendarPrint') !== false
) {
    echo "  [PASS] LMS calendar.php contains complete in-page Print Preview Modal & JS controls.\n";
} else {
    echo "  [FAIL] LMS calendar.php missing modal or JS controls.\n";
    $all_passed = false;
}

echo "\n======================================================\n";
if ($all_passed) {
    echo "ALL 6 ACADEMIC CALENDAR PRINT & PREVIEW TESTS PASSED WITH 100% SUCCESS!\n";
} else {
    echo "SOME TESTS FAILED! Please inspect errors above.\n";
}
echo "======================================================\n";
