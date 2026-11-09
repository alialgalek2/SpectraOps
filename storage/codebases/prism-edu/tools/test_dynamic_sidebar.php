<?php
/**
 * Test Suite: Dynamic Contextual Sidebar & Always-Collapsed Verification
 */

require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../core/Layout.php';

$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';

echo "=== Testing Dynamic Contextual Sidebar Per Page ===\n\n";

$passCount = 0;
$failCount = 0;

function assert_test($label, $condition, $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo "  [PASS] $label\n";
        $passCount++;
    } else {
        echo "  [FAIL] $label $details\n";
        $failCount++;
    }
}

// 1. Test Administration: users.php
$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/Administration/users.php';
$adminUsersHtml = Layout::renderSidebar();

assert_test("Admin users.php: Sidebar starts collapsed & compact", 
    strpos($adminUsersHtml, 'class="app-sidebar sidebar-collapsed sidebar-compact"') !== false);
assert_test("Admin users.php: Contains User Accounts link", 
    strpos($adminUsersHtml, 'modules/Administration/users.php') !== false);
assert_test("Admin users.php: User Accounts link is marked ACTIVE", 
    preg_match('/href="[^"]*modules\/Administration\/users\.php"[^>]*class="[^"]*active bg-primary/', $adminUsersHtml) === 1);
assert_test("Admin users.php: Contains Roles & Permissions link", 
    strpos($adminUsersHtml, 'modules/Administration/manage_permissions.php') !== false);
assert_test("Admin users.php: Contains Admissions Desk link", 
    strpos($adminUsersHtml, 'modules/Administration/admissions.php') !== false);
assert_test("Admin users.php: Contains Classes link", 
    strpos($adminUsersHtml, 'modules/Administration/classes.php') !== false);
assert_test("Admin users.php: Contains Switch Portal section with other modules", 
    strpos($adminUsersHtml, 'Switch Portal') !== false || strpos($adminUsersHtml, 'الأنظمة الأخرى') !== false);
assert_test("Admin users.php: Switch Portal includes Main Dashboard", 
    strpos($adminUsersHtml, 'dashboard.php') !== false);
assert_test("Admin users.php: Switch Portal includes LMS module", 
    strpos($adminUsersHtml, 'modules/LMS/index.php') !== false);

// 2. Test Grading: teacher_grades.php
$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/Grading/teacher_grades.php';
$gradingHtml = Layout::renderSidebar();

assert_test("Grading teacher_grades.php: Contains Teacher Grades Entry link", 
    strpos($gradingHtml, 'modules/Grading/teacher_grades.php') !== false);
assert_test("Grading teacher_grades.php: Teacher Grades link is marked ACTIVE", 
    preg_match('/href="[^"]*modules\/Grading\/teacher_grades\.php"[^>]*class="[^"]*active bg-primary/', $gradingHtml) === 1);
assert_test("Grading teacher_grades.php: Contains Report Cards link", 
    strpos($gradingHtml, 'modules/Grading/report_cards.php') !== false);
assert_test("Grading teacher_grades.php: Contains Grade Matrix link", 
    strpos($gradingHtml, 'modules/Grading/matrix.php') !== false);
assert_test("Grading teacher_grades.php: Contains Exams link", 
    strpos($gradingHtml, 'modules/Grading/exams.php') !== false);

// 3. Test LMS: assignments.php
$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/LMS/assignments.php';
$lmsHtml = Layout::renderSidebar();

assert_test("LMS assignments.php: Contains Assignments link", 
    strpos($lmsHtml, 'modules/LMS/assignments.php') !== false);
assert_test("LMS assignments.php: Assignments link is marked ACTIVE", 
    preg_match('/href="[^"]*modules\/LMS\/assignments\.php"[^>]*class="[^"]*active bg-primary/', $lmsHtml) === 1);
assert_test("LMS assignments.php: Contains Courses link", 
    strpos($lmsHtml, 'modules/LMS/courses.php') !== false);
assert_test("LMS assignments.php: Contains Attendance link", 
    strpos($lmsHtml, 'modules/LMS/attendance.php') !== false);
assert_test("LMS assignments.php: Contains Materials link", 
    strpos($lmsHtml, 'modules/LMS/materials.php') !== false);

// 4. Test Finance: fee_structures.php
$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/Finance/fee_structures.php';
$financeHtml = Layout::renderSidebar();

assert_test("Finance fee_structures.php: Contains Fee Structures link", 
    strpos($financeHtml, 'modules/Finance/fee_structures.php') !== false);
assert_test("Finance fee_structures.php: Fee Structures link is marked ACTIVE", 
    preg_match('/href="[^"]*modules\/Finance\/fee_structures\.php"[^>]*class="[^"]*active bg-primary/', $financeHtml) === 1);
assert_test("Finance fee_structures.php: Contains Fees Portal link", 
    strpos($financeHtml, 'modules/Finance/fees_portal.php') !== false);
assert_test("Finance fee_structures.php: Contains Payments link", 
    strpos($financeHtml, 'modules/Finance/payments.php') !== false);

// 5. Test HR: employees.php
$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/modules/HR/employees.php';
$hrHtml = Layout::renderSidebar();

assert_test("HR employees.php: Contains Employees link", 
    strpos($hrHtml, 'modules/HR/employees.php') !== false);
assert_test("HR employees.php: Employees link is marked ACTIVE", 
    preg_match('/href="[^"]*modules\/HR\/employees\.php"[^>]*class="[^"]*active bg-primary/', $hrHtml) === 1);
assert_test("HR employees.php: Contains Staff Attendance link", 
    strpos($hrHtml, 'modules/HR/staff_attendance.php') !== false);
assert_test("HR employees.php: Contains Leaves link", 
    strpos($hrHtml, 'modules/HR/leaves.php') !== false);
assert_test("HR employees.php: Contains Payroll link", 
    strpos($hrHtml, 'modules/HR/payroll.php') !== false);

// 6. Test Dashboard: dashboard.php
$GLOBALS['app_sidebar_rendered'] = false;
$_SERVER['SCRIPT_NAME'] = '/school_system/dashboard.php';
$dashHtml = Layout::renderSidebar();
assert_test("Dashboard: Sidebar is strictly suppressed (empty string)", empty($dashHtml));

// 7. Verify Always Collapsed default in JS
$themeJs = file_get_contents(__DIR__ . '/../assets/js/theme_toggle.js');
assert_test("theme_toggle.js initializes isCollapsed as true unconditionally", 
    strpos($themeJs, 'var isCollapsed = true; // Always start collapsed on opening any page') !== false);

$layoutPhp = file_get_contents(__DIR__ . '/../core/Layout.php');
assert_test("Layout.php initializes isCollapsed as true unconditionally", 
    strpos($layoutPhp, 'let isCollapsed = true; // Always start collapsed on opening any page') !== false);

echo "\nSummary: $passCount passed, $failCount failed.\n";
if ($failCount === 0) {
    echo "SUCCESS: Dynamic contextual sidebar and always-collapsed behavior verified across all modules!\n";
    exit(0);
} else {
    echo "FAILURE: One or more checks failed.\n";
    exit(1);
}
