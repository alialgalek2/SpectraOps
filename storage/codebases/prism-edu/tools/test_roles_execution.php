<?php
$rolesToTest = [
    'school_admin' => [
        'modules/Administration/index.php',
        'modules/Administration/student_admission.php',
        'modules/Administration/student_profile.php'
    ],
    'teacher' => [
        'modules/Grading/teacher_grades.php',
        'modules/Grading/exams.php',
        'modules/LMS/teacher_dashboard.php',
        'modules/LMS/teacher_daily_log.php',
        'modules/LMS/timetable.php'
    ],
    'student' => [
        'modules/LMS/student_portal.php',
        'modules/Grading/transcript.php'
    ],
    'parent' => [
        'modules/LMS/parent_portal.php'
    ],
    'finance' => [
        'modules/Finance/index.php',
        'modules/Finance/fees_portal.php',
        'modules/Finance/payroll_disbursement.php'
    ],
    'hr' => [
        'modules/HR/index.php',
        'modules/HR/employees.php',
        'modules/HR/leaves.php',
        'modules/HR/payroll.php'
    ]
];

$runnerTpl = <<<'PHP'
<?php
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE);
ini_set('display_errors', '1');

session_start();
$_SESSION = [
    'user_id' => 1,
    'username' => 'TEST_ROLE',
    'role' => 'TEST_ROLE',
    'role_id' => 2,
    'full_name' => 'Test User',
    'email' => 'test@school.edu',
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

$runnerFile = __DIR__ . '/_temp_role_runner.php';
$errorsFound = 0;

echo "=== TESTING ROLE-SPECIFIC PORTAL ACCESS ===\n\n";

foreach ($rolesToTest as $role => $pages) {
    echo "Testing Role: [$role]\n";
    foreach ($pages as $page) {
        $code = str_replace(['TEST_ROLE', 'TARGET_PAGE'], [$role, $page], $runnerTpl);
        file_put_contents($runnerFile, $code);

        $out = [];
        $ret = 0;
        exec("php " . escapeshellarg($runnerFile) . " 2>&1", $out, $ret);
        $outStr = trim(implode("\n", $out));

        if (strpos($outStr, 'SUCCESS') !== false || $ret === 0) {
            echo "  [OK] $page\n";
        } elseif (strpos($outStr, 'EXCEPTION') !== false) {
            echo "  [EXCEPTION] $page: $outStr\n";
            $errorsFound++;
        } else {
            echo "  [FAIL] $page: " . substr($outStr, 0, 150) . "\n";
            $errorsFound++;
        }
    }
}

if (file_exists($runnerFile)) {
    @unlink($runnerFile);
}

echo "\nSummary: Role tests completed with $errorsFound errors.\n";
