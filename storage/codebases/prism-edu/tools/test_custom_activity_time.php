<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING ACTIVITY TIME ADJUSTMENT & SCHEDULING CONTROLLER ===\n\n";

// 1. Clean previous test entries if any
$pdo->exec("DELETE FROM timetable_allocations WHERE subject_name LIKE '%Morning Assembly%'");

// 2. Insert new activity with custom time (07:45 - 08:15)
$stmt = $pdo->prepare("
    INSERT INTO timetable_allocations (day_of_week, period_id, class_id, subject_name, allocation_type, teacher_id, room_number, start_time, end_time) 
    VALUES ('Wednesday', 1, 1, 'Morning Assembly & Drill', 'activity', NULL, 'Main Courtyard', '07:45:00', '08:15:00')
");
$stmt->execute();
$insertedId = (int)$pdo->lastInsertId();
echo "  [PASS] Inserted activity slot with custom time 07:45 - 08:15. ID: $insertedId\n";

// 3. Verify in DB
$row = $pdo->query("SELECT * FROM timetable_allocations WHERE allocation_id = $insertedId")->fetch(PDO::FETCH_ASSOC);
if ($row && $row['start_time'] === '07:45:00' && $row['end_time'] === '08:15:00' && $row['allocation_type'] === 'activity') {
    echo "  [PASS] DB record confirmed: start_time='07:45:00', end_time='08:15:00', allocation_type='activity'\n";
} else {
    echo "  [FAIL] DB record did not match expected values.\n";
    print_r($row);
    exit(1);
}

// 4. Update / Adjust the time to (07:30 - 08:20)
$updateStmt = $pdo->prepare("
    UPDATE timetable_allocations SET 
        subject_name = 'Morning Assembly (Extended Drill)',
        start_time = '07:30:00',
        end_time = '08:20:00',
        room_number = 'Main Sports Pavilion'
    WHERE allocation_id = ?
");
$updateStmt->execute([$insertedId]);
echo "  [PASS] Adjusted time for existing activity to 07:30 - 08:20.\n";

$updatedRow = $pdo->query("SELECT * FROM timetable_allocations WHERE allocation_id = $insertedId")->fetch(PDO::FETCH_ASSOC);
if ($updatedRow && $updatedRow['start_time'] === '07:30:00' && $updatedRow['end_time'] === '08:20:00' && $updatedRow['subject_name'] === 'Morning Assembly (Extended Drill)') {
    echo "  [PASS] DB record successfully verified with updated adjusted time.\n";
} else {
    echo "  [FAIL] DB record update verification failed.\n";
    print_r($updatedRow);
    exit(1);
}

// 5. Test Print Matrix rendering of the adjusted activity
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_GET = ['type' => 'class', 'id' => 1, 'embedded' => 1];

ob_start();
include __DIR__ . '/../modules/Administration/print_timetable.php';
$printOutput = ob_get_clean();

if (strpos($printOutput, 'Morning Assembly (Extended Drill)') !== false && strpos($printOutput, '07:30 AM - 08:20 AM') !== false) {
    echo "  [PASS] Print matrix properly displays adjusted activity with custom time pill '07:30 AM - 08:20 AM'!\n";
} else {
    echo "  [FAIL] Print matrix did not render the adjusted activity time pill.\n";
    exit(1);
}

// 6. Test timetable.php rendering
$_GET = ['filter_class' => 1];
ob_start();
include __DIR__ . '/../modules/LMS/timetable.php';
$timetableOutput = ob_get_clean();

if (strpos($timetableOutput, 'Morning Assembly (Extended Drill)') !== false && strpos($timetableOutput, '07:30 AM - 08:20 AM') !== false) {
    echo "  [PASS] Timetable manager table renders adjusted activity with time '07:30 AM - 08:20 AM' and edit action!\n";
} else {
    echo "  [FAIL] Timetable manager table did not render the adjusted activity.\n";
    exit(1);
}

echo "\n=== ALL ACTIVITY TIME ADJUSTMENT TESTS PASSED ===\n";
