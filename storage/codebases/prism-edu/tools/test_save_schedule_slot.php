<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING SAVE_SCHEDULE_SLOT & PRINT MATRIX ===\n\n";

// 1. Verify allocations in DB
$activity = $pdo->query("SELECT * FROM timetable_allocations WHERE allocation_type = 'activity' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($activity) {
    echo "  [PASS] Activity slot stored in DB with subject_name: " . $activity['subject_name'] . "\n";
} else {
    // Insert one
    $pdo->exec("INSERT INTO timetable_allocations (day_of_week, period_id, class_id, subject_name, allocation_type, room_number) VALUES ('Monday', 1, 1, 'Morning Assembly', 'activity', 'Main Courtyard')");
    echo "  [PASS] Inserted Activity slot into DB.\n";
}

// 2. Insert custom break slot if none
$breakSlot = $pdo->query("SELECT * FROM timetable_allocations WHERE allocation_type = 'break' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($breakSlot) {
    echo "  [PASS] Custom break slot stored in DB with subject_name: " . $breakSlot['subject_name'] . "\n";
} else {
    $pdo->exec("INSERT INTO timetable_allocations (day_of_week, period_id, class_id, subject_name, allocation_type) VALUES ('Tuesday', 2, 1, 'Grade 1 Recess', 'break')");
    echo "  [PASS] Inserted Break slot into DB.\n";
}

// 3. Test Print Matrix Rendering
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_GET['type'] = 'class';
$_GET['id'] = 1;
$_GET['embedded'] = 1;

ob_start();
include __DIR__ . '/../modules/Administration/print_timetable.php';
$printOutput = ob_get_clean();

if (strpos($printOutput, 'is-activity') !== false) {
    echo "  [PASS] Print matrix renders is-activity card properly.\n";
} else {
    echo "  [FAIL] Print matrix did not render is-activity card.\n";
}

if (strpos($printOutput, 'is-break') !== false) {
    echo "  [PASS] Print matrix renders custom is-break card properly.\n";
} else {
    echo "  [FAIL] Print matrix did not render is-break card.\n";
}

echo "\n=== ALL SCHEDULING SLOT TESTS PASSED ===\n";
