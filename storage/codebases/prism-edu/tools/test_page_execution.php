<?php
$pagesToTest = [
    'dashboard.php',
    'modules/Administration/index.php',
    'modules/Administration/add_user.php',
    'modules/Administration/student_admission.php',
    'modules/Administration/student_profile.php',
    'modules/Administration/users.php',
    'modules/Administration/classes.php',
    'modules/Administration/subjects.php',
    'modules/Administration/branding.php',
    'modules/Administration/institution_settings.php',
    'modules/Administration/manage_permissions.php',
    'modules/Administration/analytics.php',
    'modules/Administration/change_password.php',
    'modules/Administration/data_migration.php',
    'modules/Administration/license_activate.php',
    'modules/Administration/license_details.php',
    'modules/Administration/academic_calendar.php',
    'modules/Administration/system_updates.php',
    'modules/Administration/telemetry_logs.php',
    'modules/Finance/index.php',
    'modules/Finance/fees.php',
    'modules/Finance/fees_portal.php',
    'modules/Finance/payments.php',
    'modules/Finance/payroll_disbursement.php',
    'modules/Finance/account_statement.php',
    'modules/Grading/index.php',
    'modules/Grading/teacher_grades.php',
    'modules/Grading/exams.php',
    'modules/Grading/report_cards.php',
    'modules/Grading/transcript.php',
    'modules/HR/index.php',
    'modules/HR/employees.php',
    'modules/HR/add_employee.php',
    'modules/HR/hr_dashboard.php',
    'modules/HR/payroll.php',
    'modules/HR/payroll_ledger.php',
    'modules/HR/leaves.php',
    'modules/HR/teacher_dashboard.php',
    'modules/LMS/index.php',
    'modules/LMS/teacher_dashboard.php',
    'modules/LMS/announcements.php',
    'modules/LMS/attendance.php',
    'modules/LMS/timetable.php',
    'modules/LMS/messages.php',
    'modules/LMS/student_portal.php',
    'modules/LMS/parent_portal.php',
    'modules/LMS/teacher_daily_log.php',
    'modules/LMS/calendar.php',
    'portals/calendar.php',
    'portals/academic_calendar.php',
    'portals/system_updates.php',
    'portals/telemetry_logs.php',
    'portals/users.php',
    'portals/classes.php',
    'portals/subjects.php',
    'portals/branding.php',
    'api/update_server.php',
    'api/system_update_action.php',
    'api/telemetry_dispatch.php',
    'api/telemetry_collector.php'
];

echo "=== EXECUTING SIMULATED PAGE RUNS ===\n\n";

$runnerTpl = <<<'PHP'
<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '1');

session_start();
$_SESSION = [
    'user_id' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'role_id' => 1,
    'full_name' => 'System Administrator',
    'email' => 'admin@school.edu',
    'lang' => 'en'
];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/school_system/TARGET_PAGE';

ob_start();
try {
    include __DIR__ . '/../TARGET_PAGE';
    ob_end_clean();
    echo "SUCCESS\n";
} catch (Throwable $e) {
    ob_end_clean();
    echo "EXCEPTION: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
PHP;

$runnerFile = __DIR__ . '/_temp_runner.php';
$errorsFound = 0;

foreach ($pagesToTest as $page) {
    $fullPath = __DIR__ . '/../' . $page;
    if (!file_exists($fullPath)) {
        echo "[FILE NOT FOUND] $page\n";
        $errorsFound++;
        continue;
    }

    $code = str_replace('TARGET_PAGE', $page, $runnerTpl);
    file_put_contents($runnerFile, $code);

    $out = [];
    $ret = 0;
    exec("php " . escapeshellarg($runnerFile) . " 2>&1", $out, $ret);
    $outStr = trim(implode("\n", $out));

    if (strpos($outStr, 'SUCCESS') !== false) {
        echo "[OK] $page\n";
    } elseif (strpos($outStr, 'EXCEPTION') !== false) {
        echo "[EXCEPTION] $page: $outStr\n";
        $errorsFound++;
    } else {
        // Truncate output to prevent spam
        $snippet = substr($outStr, 0, 150);
        if ($ret === 0) {
            echo "[OK (rendered)] $page\n";
        } else {
            echo "[FAIL (Code $ret)] $page: $snippet\n";
            $errorsFound++;
        }
    }
}

if (file_exists($runnerFile)) {
    @unlink($runnerFile);
}

echo "\nSummary: " . count($pagesToTest) . " pages tested, $errorsFound errors found.\n";
