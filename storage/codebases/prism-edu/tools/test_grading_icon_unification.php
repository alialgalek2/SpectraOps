<?php
/**
 * Test Suite: Grading & Examinations Icon & Theme Unification Verification
 */

require_once __DIR__ . '/../core/init.php';

echo "========================================================\n";
echo "  GRADING & EXAMINATIONS ICON UNIFICATION TEST SUITE   \n";
echo "========================================================\n\n";

$passCount = 0;
$totalTests = 0;

function assertCheck(bool $condition, string $message): void {
    global $passCount, $totalTests;
    $totalTests++;
    if ($condition) {
        $passCount++;
        echo "  [PASS] $message\n";
    } else {
        echo "  [FAIL] $message\n";
    }
}

// Test 1: Check Grading Central Hub (modules/Grading/index.php) action cards and icon boxes
echo "[TEST 1] Testing modules/Grading/index.php action cards and icon themes...\n";
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['full_name'] = 'Administrator';

ob_start();
include __DIR__ . '/../modules/Grading/index.php';
$gradingHubOutput = ob_get_clean();

assertCheck(strpos($gradingHubOutput, 'admin-action-card') !== false, "modules/Grading/index.php uses .admin-action-card");
assertCheck(strpos($gradingHubOutput, 'action-icon-box') !== false, "modules/Grading/index.php uses .action-icon-box");
assertCheck(strpos($gradingHubOutput, 'icon-box-primary') !== false, "modules/Grading/index.php contains icon-box-primary");
assertCheck(strpos($gradingHubOutput, 'icon-box-purple') !== false, "modules/Grading/index.php contains icon-box-purple");
assertCheck(strpos($gradingHubOutput, 'icon-box-success') !== false, "modules/Grading/index.php contains icon-box-success");
assertCheck(strpos($gradingHubOutput, 'icon-box-warning') !== false, "modules/Grading/index.php contains icon-box-warning");
assertCheck(strpos($gradingHubOutput, 'icon-box-info') !== false, "modules/Grading/index.php contains icon-box-info");
assertCheck(strpos($gradingHubOutput, 'fa-square-poll-horizontal') !== false, "modules/Grading/index.php uses fa-square-poll-horizontal for Exam Marks");
assertCheck(strpos($gradingHubOutput, 'fa-chart-simple') !== false, "modules/Grading/index.php uses fa-chart-simple for 40/60 Assessment");
assertCheck(strpos($gradingHubOutput, 'fa-file-signature') !== false, "modules/Grading/index.php uses fa-file-signature for LMS Assignment Grading");
assertCheck(strpos($gradingHubOutput, 'fa-id-card-clip') !== false, "modules/Grading/index.php uses fa-id-card-clip for Report Cards");
assertCheck(strpos($gradingHubOutput, 'fa-scroll') !== false, "modules/Grading/index.php uses fa-scroll for Academic Transcripts");

// Test 2: Check teacher_grades.php layout and theme tokens
echo "\n[TEST 2] Testing modules/Grading/teacher_grades.php layout & theme tokens...\n";
$_SESSION['role'] = 'teacher';
$_SESSION['user_id'] = 2;

ob_start();
include __DIR__ . '/../modules/Grading/teacher_grades.php';
$teacherGradesOutput = ob_get_clean();

assertCheck(strpos($teacherGradesOutput, 'bg-background') !== false, "modules/Grading/teacher_grades.php uses bg-background");
assertCheck(strpos($teacherGradesOutput, 'border-border') !== false, "modules/Grading/teacher_grades.php uses theme border-border");
assertCheck(strpos($teacherGradesOutput, 'bg-card') !== false, "modules/Grading/teacher_grades.php uses theme bg-card");
assertCheck(strpos($teacherGradesOutput, 'render_universal_navbar') !== false || strpos($teacherGradesOutput, 'Student Grade Entry') !== false, "modules/Grading/teacher_grades.php renders universal navbar correctly");

// Test 3: Check exams.php layout and theme tokens
echo "\n[TEST 3] Testing modules/Grading/exams.php layout & theme tokens...\n";
ob_start();
include __DIR__ . '/../modules/Grading/exams.php';
$examsOutput = ob_get_clean();

assertCheck(strpos($examsOutput, 'bg-background') !== false, "modules/Grading/exams.php uses bg-background");
assertCheck(strpos($examsOutput, 'border-border') !== false, "modules/Grading/exams.php uses theme border-border");
assertCheck(strpos($examsOutput, 'bg-card') !== false, "modules/Grading/exams.php uses theme bg-card");

// Test 4: Role-based access testing across Student and Parent roles
echo "\n[TEST 4] Testing Student and Parent views for report_cards.php & transcript.php...\n";
$_SESSION['role'] = 'student';
$_SESSION['user_id'] = 3;

ob_start();
include __DIR__ . '/../modules/Grading/report_cards.php';
$reportCardOutput = ob_get_clean();
assertCheck(strlen($reportCardOutput) > 1000, "modules/Grading/report_cards.php renders cleanly for student");

ob_start();
include __DIR__ . '/../modules/Grading/transcript.php';
$transcriptOutput = ob_get_clean();
assertCheck(strlen($transcriptOutput) > 1000, "modules/Grading/transcript.php renders cleanly for student");

echo "\n========================================================\n";
echo "  TEST RESULTS: $passCount / $totalTests Passed\n";
echo "========================================================\n";
if ($passCount === $totalTests) {
    echo "SUCCESS: All Grading module icon unification tests passed!\n";
    exit(0);
} else {
    echo "FAILED: Some tests did not pass.\n";
    exit(1);
}
