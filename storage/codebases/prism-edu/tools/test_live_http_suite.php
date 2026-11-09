<?php
require_once __DIR__ . '/../test_http_endpoints.php';

$endpoints = [
    'login.php',
    'index.php',
    'test_db.php',
    'portals/admin_portal.php',
    'portals/teacher_portal.php',
    'portals/student_portal.php',
    'portals/parent_portal.php',
    'portals/finance_portal.php',
    'portals/fees_portal.php',
    'portals/exams_portal.php',
    'portals/announcements.php',
    'portals/timetable_portal.php',
    'portals/report_card.php',
    'portals/teacher_grades.php',
    'portals/teacher_daily_log.php',
    'portals/data_migration.php',
    'portals/student_admission.php',
    'portals/institution_settings.php',
    'portals/license_activate.php',
    'portals/change_password.php',
    'portals/add_user.php',
    'portals/calendar.php',
    'portals/academic_calendar.php',
    'portals/system_updates.php',
    'portals/telemetry_logs.php',
    'portals/docs.php',
    'portals/privacy.php',
    'docs.php',
    'privacy.php',
    'modules/Administration/academic_calendar.php',
    'modules/Administration/system_updates.php',
    'modules/Administration/telemetry_logs.php',
    'modules/Administration/documentation.php',
    'modules/LMS/calendar.php',
    'api/update_server.php',
    'api/system_update_action.php',
    'api/telemetry_dispatch.php',
    'api/telemetry_collector.php'
];

echo "=== LIVE APACHE ENDPOINT TESTS (PORT 8080) ===\n\n";

$failed = 0;
foreach ($endpoints as $ep) {
    $url = "http://localhost:8080/school_system/" . $ep;
    $code = test_endpoint($url);
    // 200 OK, 302 Redirect (auth guard), or 403 Forbidden (strict API auth guard) are valid production responses
    $status = in_array($code, [200, 302, 403]) ? "PASS" : "FAIL";
    if ($status === "FAIL") $failed++;
    echo sprintf("[%s] (HTTP %d) %s\n", $status, $code, $ep);
}

echo "\nLive Endpoint Suite Result: " . (count($endpoints) - $failed) . "/" . count($endpoints) . " passed.\n";
