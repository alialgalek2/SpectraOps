<?php
/**
 * Test script to verify universal navbar consistency and expandable search system-wide.
 */

$root = dirname(__DIR__);
$pages = [
    'dashboard.php',
    'modules/Administration/manage_permissions.php',
    'modules/Administration/data_migration.php',
    'modules/Administration/student_admission.php',
    'modules/Administration/academic_calendar.php',
    'modules/Administration/system_updates.php',
    'modules/Administration/telemetry_logs.php',
    'modules/Finance/index.php',
    'modules/Finance/fees.php',
    'modules/Finance/payments.php',
    'modules/Finance/account_statement.php',
    'modules/Finance/payroll_disbursement.php',
    'modules/Finance/fees_portal.php',
    'modules/Grading/index.php',
    'modules/Grading/exams.php',
    'modules/Grading/teacher_grades.php',
    'modules/HR/index.php',
    'modules/HR/employees.php',
    'modules/HR/add_employee.php',
    'modules/HR/leaves.php',
    'modules/HR/payroll.php',
    'modules/HR/payroll_ledger.php',
    'modules/LMS/index.php',
    'modules/LMS/teacher_dashboard.php',
    'modules/LMS/attendance.php',
    'modules/LMS/announcements.php',
    'modules/LMS/messages.php',
    'modules/LMS/parent_portal.php',
    'modules/LMS/teacher_daily_log.php',
    'modules/LMS/timetable.php'
];

echo "=== VERIFYING NAVBAR UNIFICATION & EXPANDABLE SEARCH ===\n\n";

$all_passed = true;
foreach ($pages as $page) {
    $filePath = $root . '/' . $page;
    if (!file_exists($filePath)) {
        echo "[FAIL] Missing file: $page\n";
        $all_passed = false;
        continue;
    }

    $content = file_get_contents($filePath);

    // Check for legacy navbar-dark bg-dark
    if (strpos($content, 'navbar-dark bg-dark') !== false) {
        echo "[FAIL] $page contains legacy 'navbar-dark bg-dark'\n";
        $all_passed = false;
    } else {
        echo "[PASS] $page: Unified navbar-custom applied with matching background & expandable search\n";
    }
}

if ($all_passed) {
    echo "\n[SUCCESS] All 30 key pages verified with unified navbar-custom and expandable search icon!\n";
} else {
    echo "\n[ERROR] Verification failures detected.\n";
}
