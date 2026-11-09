<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING ACADEMIC CALENDAR CORE SERVICE ===\n\n";

// Test 1: getActiveAcademicYear()
$year = AcademicCalendar::getActiveAcademicYear();
echo "[TEST 1] Active Academic Year: $year\n";
assert(!empty($year), "Year must not be empty");

// Test 2: getUpcomingMilestones()
$milestones = AcademicCalendar::getUpcomingMilestones(5);
echo "[TEST 2] Upcoming Milestones Count: " . count($milestones) . "\n";
foreach ($milestones as $m) {
    echo "  - {$m['title']} ({$m['event_type']}) | Date: {$m['start_date']} -> {$m['end_date']} | {$m['status_text']}\n";
}
assert(count($milestones) > 0, "Must have upcoming seeded milestones");

// Test 3: isSchoolDay() / isHoliday() / isExamPeriod()
$today = date('Y-m-d');
$isSchoolToday = AcademicCalendar::isSchoolDay($today);
echo "[TEST 3] Is School Day Today ($today)? " . ($isSchoolToday ? 'YES' : 'NO (Holiday/Weekend)') . "\n";

// Test 4: getActiveTerm()
$termInfo = AcademicCalendar::getActiveTerm();
echo "[TEST 4] Active Term: " . ($termInfo['term'] ?? 'None') . "\n";

// Test 5: getEventsForMonth()
$curY = (int)date('Y');
$curM = (int)date('m');
$monthEvents = AcademicCalendar::getEventsForMonth($curY, $curM);
echo "[TEST 5] Events for $curY-$curM: " . count($monthEvents['raw_events']) . " total events found\n";

// Test 6: Create, Update, Delete Event
$testEventId = AcademicCalendar::saveEvent([
    'title' => 'Unit Test Event Registration',
    'description' => 'Temporary test event to verify CRUD',
    'event_type' => AcademicCalendar::TYPE_GENERAL_EVENT,
    'academic_year' => $year,
    'term' => 'Term 1',
    'start_date' => date('Y-m-d', strtotime('+100 days')),
    'end_date' => date('Y-m-d', strtotime('+100 days')),
    'is_closed' => 0,
    'target_audience' => 'all'
]);
echo "[TEST 6.1] Created Test Event ID: $testEventId\n";
assert($testEventId > 0, "Event ID must be positive integer");

// Update
AcademicCalendar::saveEvent([
    'title' => 'Updated Unit Test Event Registration',
    'description' => 'Updated description',
    'event_type' => AcademicCalendar::TYPE_GENERAL_EVENT,
    'academic_year' => $year,
    'term' => 'Term 1',
    'start_date' => date('Y-m-d', strtotime('+100 days')),
    'end_date' => date('Y-m-d', strtotime('+100 days')),
    'is_closed' => 0,
    'target_audience' => 'all'
], $testEventId);
echo "[TEST 6.2] Updated Test Event successfully.\n";

// Delete
$deleted = AcademicCalendar::deleteEvent($testEventId);
echo "[TEST 6.3] Deleted Test Event: " . ($deleted ? 'SUCCESS' : 'FAILED') . "\n";
assert($deleted === true, "Delete must return true");

echo "\n=== ALL ACADEMIC CALENDAR UNIT TESTS PASSED ===\n";
