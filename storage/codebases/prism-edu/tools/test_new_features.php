<?php
require_once __DIR__ . '/../core/init.php';

echo "=== RUNNING NEW FEATURE VERIFICATIONS ===\n\n";

// 1. Test api/get_calendar_events.php
echo "[TEST 1] Testing Unified Calendar Endpoint...\n";
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

// Test events feed
$_GET['feed_type'] = 'events';
$_GET['start'] = '2026-01-01';
$_GET['end'] = '2026-12-31';
ob_start();
include __DIR__ . '/../api/get_calendar_events.php';
$jsonEvents = ob_get_clean();
$dataEvents = json_decode($jsonEvents, true);
if (is_array($dataEvents)) {
    echo "  [PASS] feed_type=events returned " . count($dataEvents) . " events.\n";
} else {
    echo "  [FAIL] feed_type=events invalid JSON: $jsonEvents\n";
}

// Test timetable feed
$_GET['feed_type'] = 'timetable';
ob_start();
include __DIR__ . '/../api/get_calendar_events.php';
$jsonTimetable = ob_get_clean();
$dataTimetable = json_decode($jsonTimetable, true);
if (is_array($dataTimetable)) {
    echo "  [PASS] feed_type=timetable returned " . count($dataTimetable) . " allocations.\n";
} else {
    echo "  [FAIL] feed_type=timetable invalid JSON: $jsonTimetable\n";
}

// Test all feed
$_GET['feed_type'] = 'all';
ob_start();
include __DIR__ . '/../api/get_calendar_events.php';
$jsonAll = ob_get_clean();
$dataAll = json_decode($jsonAll, true);
if (is_array($dataAll)) {
    echo "  [PASS] feed_type=all returned " . count($dataAll) . " total items.\n";
} else {
    echo "  [FAIL] feed_type=all invalid JSON: $jsonAll\n";
}

// 2. Test print_timetable.php rendering
echo "\n[TEST 2] Testing 2D Timetable Print Matrix...\n";
$_SESSION['role'] = 'admin';
$_GET['type'] = 'class';
$_GET['id'] = '1';
ob_start();
include __DIR__ . '/../modules/Administration/print_timetable.php';
$printOutput = ob_get_clean();
if (strpos($printOutput, 'slot-card') !== false && strpos($printOutput, 'interval-row') !== false && strpos($printOutput, '@media print') !== false) {
    echo "  [PASS] modules/Administration/print_timetable.php rendered 2D matrix successfully.\n";
} else {
    echo "  [FAIL] modules/Administration/print_timetable.php rendering issue.\n";
}

// 3. Test Portal Forwarders
echo "\n[TEST 3] Testing Module Portal Forwarders...\n";
$portals = [
    'modules/Administration/admin_portal.php',
    'modules/Finance/fees_portal.php',
    'modules/HR/hr_portal.php',
    'modules/LMS/lms_portal.php',
    'modules/Grading/exams_portal.php'
];

foreach ($portals as $portal) {
    $fullPath = __DIR__ . '/../' . $portal;
    if (file_exists($fullPath)) {
        echo "  [PASS] $portal exists.\n";
    } else {
        echo "  [FAIL] $portal does not exist.\n";
    }
}

echo "\n=== ALL VERIFICATIONS COMPLETE ===\n";
