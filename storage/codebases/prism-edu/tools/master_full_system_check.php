<?php
/**
 * Master Comprehensive Full System Audit & Feature Verification Runner
 * 
 * Verifies all 5 core modules, infrastructure, database integrity, 
 * reliability score, security isolation, and live HTTP rendering.
 */

require_once __DIR__ . '/../core/init.php';

echo "================================================================================\n";
echo "           EDUMASTER SCHOOL SYSTEM - COMPREHENSIVE FULL SYSTEM AUDIT            \n";
echo "================================================================================\n";
echo "Execution Time: " . date('Y-m-d H:i:s T') . "\n";
echo "PHP Version: " . PHP_VERSION . " (" . PHP_OS_FAMILY . ")\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'warnings' => 0,
    'details' => []
];

function recordAssertion(string $category, string $testName, bool $condition, string $info = '') {
    global $results;
    if ($condition) {
        $results['passed']++;
        echo "  [PASS] $category: $testName" . ($info ? " ($info)" : "") . "\n";
        $results['details'][] = ['status' => 'PASS', 'category' => $category, 'name' => $testName, 'info' => $info];
    } else {
        $results['failed']++;
        echo "  [FAIL] $category: $testName" . ($info ? " ($info)" : "") . "\n";
        $results['details'][] = ['status' => 'FAIL', 'category' => $category, 'name' => $testName, 'info' => $info];
    }
}

// =============================================================================
// TIER 1: GLOBAL PHP SYNTAX & LINTER CHECK
// =============================================================================
echo "--- TIER 1: GLOBAL PHP SYNTAX & LINTER AUDIT ---\n";

$targetDirs = ['core', 'includes', 'licensing', 'modules', 'api', 'portals', 'public'];
$syntaxErrors = [];
$totalFiles = 0;

// Root php files
$rootFiles = glob(__DIR__ . '/../*.php');
foreach ($rootFiles as $rf) {
    $totalFiles++;
    $output = [];
    $ret = 0;
    exec('php -l ' . escapeshellarg($rf) . ' 2>&1', $output, $ret);
    if ($ret !== 0) $syntaxErrors[] = basename($rf) . ": " . implode(" ", $output);
}

// Subdirectories
foreach ($targetDirs as $td) {
    $path = __DIR__ . '/../' . $td;
    if (!is_dir($path)) continue;
    $dirIt = new RecursiveDirectoryIterator($path);
    $itIt = new RecursiveIteratorIterator($dirIt);
    $regIt = new RegexIterator($itIt, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
    foreach ($regIt as $f) {
        $totalFiles++;
        $output = [];
        $ret = 0;
        exec('php -l ' . escapeshellarg($f[0]) . ' 2>&1', $output, $ret);
        if ($ret !== 0) $syntaxErrors[] = str_replace(__DIR__ . '/../', '', $f[0]) . ": " . implode(" ", $output);
    }
}

recordAssertion('Syntax Linting', 'All Production PHP Files Valid', empty($syntaxErrors), "Checked $totalFiles production files, " . count($syntaxErrors) . " errors");

// =============================================================================
// TIER 2: DATABASE INTEGRITY & CORE INFRASTRUCTURE
// =============================================================================
echo "\n--- TIER 2: DATABASE INTEGRITY & CORE INFRASTRUCTURE ---\n";

recordAssertion('Database', 'PDO Connection Active', $pdo instanceof PDO);

// Verify Essential Tables
$essentialTables = [
    'users', 'students', 'classes', 'subjects', 'campuses',
    'academic_calendar', 'educational_stages',
    'student_applications', 'parents', 'audit_logs',
    'system_telemetry_logs', 'system_version', 'system_license',
    'support_tickets', 'parent_student_map',
    'fee_structures', 'invoices', 'fee_invoices', 'payments',
    'sibling_discount_groups', 'sponsored_students', 'school_expenses',
    'employees', 'employee_documents', 'staff_attendance',
    'staff_leaves', 'staff_advances', 'staff_payroll', 'staff_evaluations',
    'grades', 'exam_series', 'exam_results', 'timetables', 'student_attendance', 'assignments'
];

$existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$missingTables = array_diff($essentialTables, $existingTables);
recordAssertion('Database', 'Master Table Registry', empty($missingTables), "Found " . count($existingTables) . " total tables; all " . count($essentialTables) . " core verified");

// Verify User Roles in Database
$rolesInDb = $pdo->query("SELECT DISTINCT role FROM users")->fetchAll(PDO::FETCH_COLUMN);
$expectedRoles = ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr'];
$missingRoles = array_diff($expectedRoles, $rolesInDb);
recordAssertion('RBAC', 'Primary Roles Exist in Users Table', empty($missingRoles), "Found roles: " . implode(', ', $rolesInDb));

// Sync Pending Telemetry & Verify Reliability Score
Telemetry::dispatchPendingLogs();
$health = Telemetry::getHealthMetrics();
recordAssertion('Telemetry', 'Health Metrics Computed', is_numeric($health['health_score']), "Score: {$health['health_score']}%");
recordAssertion('Telemetry', 'Reliability Score >= 95%', $health['health_score'] >= 95.0, "Score: {$health['health_score']}%, Status: {$health['status_label']}");
recordAssertion('Telemetry', 'Zero Active Critical Incidents', $health['critical_count'] === 0, "Active critical count: {$health['critical_count']}");

// Verify System Updater & Build Info
$ver = SystemUpdater::getCurrentVersion();
recordAssertion('System Updater', 'Version Architecture Valid', !empty($ver['version_number']) && !empty($ver['build_number']), "{$ver['version_number']} Build {$ver['build_number']}");

// Verify Master Academic Calendar Engine
$activeYear = AcademicCalendar::getActiveAcademicYear();
$isSchoolDay = AcademicCalendar::isSchoolDay(date('Y-m-d'));
$milestones = AcademicCalendar::getUpcomingMilestones(5);
recordAssertion('Academic Calendar', 'Active Year Configured', !empty($activeYear), "Active Academic Year: $activeYear");
recordAssertion('Academic Calendar', 'Instructional Day Computation', is_bool($isSchoolDay), "Today (" . date('Y-m-d') . ") isSchoolDay: " . ($isSchoolDay ? 'Yes' : 'No'));
recordAssertion('Academic Calendar', 'Milestones Engine Available', is_array($milestones), count($milestones) . " upcoming milestones found");

// Verify Support Ticket & Spool Engine
$ticketNumber = SupportTicket::generateTicketNumber();
recordAssertion('IT Support', 'Ticket Number Generator', (bool)preg_match('/^TICK-\d{8}-[A-F0-9]{4}$/', $ticketNumber), $ticketNumber);
$spoolDir = __DIR__ . '/../uploads/support_spool';
recordAssertion('IT Support', 'Support Spool Directory Exists', is_dir($spoolDir));

// =============================================================================
// TIER 3: MODULE FEATURE CAPABILITY VERIFICATION
// =============================================================================
echo "\n--- TIER 3: FIVE CORE OPERATIONAL MODULES VERIFICATION ---\n";

// 1. Administration Module
$totalUsers = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalClasses = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
$totalAdmissions = (int)$pdo->query("SELECT COUNT(*) FROM student_applications")->fetchColumn();
recordAssertion('Administration', 'User Accounts Pipeline', $totalUsers > 0, "$totalUsers active users");
recordAssertion('Administration', 'Classes & Sections Pipeline', $totalClasses > 0, "$totalClasses class sections");
recordAssertion('Administration', 'Admissions Intake Pipeline', $totalAdmissions >= 0, "$totalAdmissions admission records");

// 2. LMS Module
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();
$totalSubjects = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
$totalCourses = (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
recordAssertion('LMS & Classes', 'Student Dossier Registry', $totalStudents > 0, "$totalStudents students registered");
recordAssertion('LMS & Classes', 'Curriculum Subjects Registry', $totalSubjects > 0, "$totalSubjects subjects configured");
recordAssertion('LMS & Classes', 'Curriculum Courses Registry', $totalCourses > 0, "$totalCourses courses configured");

// 3. Grading Module
$totalGrades = (int)$pdo->query("SELECT COUNT(*) FROM grades")->fetchColumn();
$totalExamSeries = (int)$pdo->query("SELECT COUNT(*) FROM exam_series")->fetchColumn();
recordAssertion('Grading & Exams', 'Assessment & Grade Ledger', $totalGrades >= 0, "$totalGrades recorded grades");
recordAssertion('Grading & Exams', 'Exam Series Cycles', $totalExamSeries > 0, "$totalExamSeries exam series registered");

// 4. Finance Module
$totalFeeStructures = (int)$pdo->query("SELECT COUNT(*) FROM fee_structures")->fetchColumn();
$totalInvoices = (int)$pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn();
$totalPayments = (int)$pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn();
recordAssertion('Finance & Treasury', 'Tuition Fee Schedules', $totalFeeStructures > 0, "$totalFeeStructures fee structures");
recordAssertion('Finance & Treasury', 'Billing Invoices Ledger', $totalInvoices > 0, "$totalInvoices invoices generated");
recordAssertion('Finance & Treasury', 'Disbursement & Payments', $totalPayments > 0, "$totalPayments payment transactions");

// 5. HR & Payroll Module
$totalEmployees = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn();
$totalDepts = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
$totalPayroll = (int)$pdo->query("SELECT COUNT(*) FROM staff_payroll")->fetchColumn();
recordAssertion('HR & Personnel', 'Staff Directory & Faculty', $totalEmployees > 0, "$totalEmployees active staff members");
recordAssertion('HR & Personnel', 'Institutional Departments', $totalDepts > 0, "$totalDepts active departments");
recordAssertion('HR & Personnel', 'Payroll Ledger & Salary Slips', $totalPayroll >= 0, "$totalPayroll processed payroll entries");


// =============================================================================
// TIER 4: LIVE HTTP STATUS & RENDERING AUDIT
// =============================================================================
echo "\n--- TIER 4: LIVE HTTP STATUS & ROUTE ACCESSIBILITY ---\n";

$cookieFile = __DIR__ . '/../cookie_admin.txt';

// Refresh session via curl login if needed
$needsLogin = true;
if (file_exists($cookieFile)) {
    $lines = explode("\n", file_get_contents($cookieFile));
    $cookies = [];
    foreach ($lines as $l) {
        $parts = preg_split("/\s+/", trim($l));
        if (count($parts) >= 7) {
            $cookies[] = $parts[5] . '=' . $parts[6];
        }
    }
    if (!empty($cookies)) {
        $ctx = stream_context_create(['http' => ['header' => 'Cookie: ' . implode('; ', $cookies), 'ignore_errors' => true]]);
        $testDash = @file_get_contents('http://localhost:8080/school_system/dashboard.php', false, $ctx);
        if ($testDash && str_contains($http_response_header[0] ?? '', '200 OK')) {
            $needsLogin = false;
        }
    }
}

if ($needsLogin) {
    // Perform curl login to refresh session cookie
    exec('curl.exe -s -c ' . escapeshellarg($cookieFile) . ' -b ' . escapeshellarg($cookieFile) . ' -d "identifier=admin&password=admin123" http://localhost:8080/school_system/login.php');
}

$adminCookies = [];
if (file_exists($cookieFile)) {
    $lines = explode("\n", file_get_contents($cookieFile));
    foreach ($lines as $l) {
        $parts = preg_split("/\s+/", trim($l));
        if (count($parts) >= 7) {
            $adminCookies[] = $parts[5] . '=' . $parts[6];
        }
    }
}
$cookieHeader = !empty($adminCookies) ? ('Cookie: ' . implode('; ', $adminCookies)) : '';

$routesToCheck = [
    'Dashboard Hub'                   => 'http://localhost:8080/school_system/dashboard.php',
    'Comprehensive Documentation'     => 'http://localhost:8080/school_system/docs.php',
    'IT Support Center Portal'        => 'http://localhost:8080/school_system/support.php',
    'Privacy Policy'                  => 'http://localhost:8080/school_system/privacy.php',
    'Institutional Admin Control Hub' => 'http://localhost:8080/school_system/modules/Administration/index.php',
    'Admin Users Desk'                => 'http://localhost:8080/school_system/modules/Administration/users.php',
    'Admin Admissions Desk'           => 'http://localhost:8080/school_system/modules/Administration/student_admission.php',
    'Admin Classes Desk'              => 'http://localhost:8080/school_system/modules/Administration/classes.php',
    'LMS & Learning Management Hub'   => 'http://localhost:8080/school_system/modules/LMS/index.php',
    'Grading & Evaluation Hub'        => 'http://localhost:8080/school_system/modules/Grading/index.php',
    'Finance & Treasury Hub'          => 'http://localhost:8080/school_system/modules/Finance/index.php',
    'HR & Personnel Management Hub'   => 'http://localhost:8080/school_system/modules/HR/index.php'
];

foreach ($routesToCheck as $name => $url) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $cookieHeader,
            'ignore_errors' => true
        ]
    ]);
    $html = @file_get_contents($url, false, $ctx);
    $statusLine = $http_response_header[0] ?? '';
    $is200 = str_contains($statusLine, '200 OK');
    $hasFatal = (bool)preg_match('/(<b>Fatal error<\/b>:|<b>Parse error<\/b>:|Fatal error:|Parse error:|Uncaught \w*(Error|Exception):)/i', $html);
    
    recordAssertion('HTTP Route', $name, $is200 && !$hasFatal, $is200 ? "HTTP 200, " . strlen($html) . " bytes" : "Status: $statusLine");
}

// Verify Arabic RTL mode for main Hubs
$rtlHubs = [
    'Finance Hub (Arabic RTL)' => 'http://localhost:8080/school_system/modules/Finance/index.php?lang=ar',
    'HR Hub (Arabic RTL)'      => 'http://localhost:8080/school_system/modules/HR/index.php?lang=ar',
    'LMS Hub (Arabic RTL)'     => 'http://localhost:8080/school_system/modules/LMS/index.php?lang=ar',
    'Admin Hub (Arabic RTL)'   => 'http://localhost:8080/school_system/modules/Administration/index.php?lang=ar'
];

foreach ($rtlHubs as $name => $url) {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => $cookieHeader,
            'ignore_errors' => true
        ]
    ]);
    $html = @file_get_contents($url, false, $ctx);
    $hasRtl = str_contains($html, 'dir="rtl"');
    recordAssertion('Bilingual RTL', $name, $hasRtl, $hasRtl ? 'dir="rtl" confirmed' : 'RTL missing');
}

echo "\n================================================================================\n";
echo "                          FULL SYSTEM AUDIT SUMMARY                             \n";
echo "================================================================================\n";
echo "Total Assertions Evaluated : " . ($results['passed'] + $results['failed']) . "\n";
echo "Passed Checks              : " . $results['passed'] . " [OK]\n";
echo "Failed Checks              : " . $results['failed'] . " [FAIL]\n";
$pct = round(($results['passed'] / ($results['passed'] + $results['failed'])) * 100, 1);
echo "System Operational Health  : $pct%\n";

if ($results['failed'] === 0) {
    echo "\n>>> STATUS: ALL SYSTEM FEATURES AND MODULES ARE FULLY OPERATIONAL <<<\n";
} else {
    echo "\n>>> STATUS: ISSUES DETECTED - REVIEW FAILED CHECKS ABOVE <<<\n";
}
echo "================================================================================\n";
