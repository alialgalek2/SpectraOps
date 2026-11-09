<?php
/**
 * Comprehensive Verification Suite for Extended LMS Features
 */

require_once __DIR__ . '/../core/init.php';

echo "========================================================\n";
echo "       LMS EXTENDED MODULE VERIFICATION SUITE           \n";
echo "========================================================\n\n";

$passed = 0;
$failed = 0;

function assert_test($description, $condition) {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] " . $description . "\n";
        $passed++;
    } else {
        echo "[FAIL] " . $description . "\n";
        $failed++;
    }
}

// 1. Database Schema Checks
echo "--- 1. DATABASE SCHEMA INTEGRITY ---\n";
$tables = ['courses', 'curriculum_units', 'curriculum_topics', 'course_materials', 'course_discussions', 'course_discussion_replies'];
foreach ($tables as $t) {
    $exists = $pdo->query("SHOW TABLES LIKE '$t'")->rowCount() > 0;
    assert_test("Table '$t' exists in schema", $exists);
}

// Check extended columns on existing tables
$col_check_assignments = $pdo->query("SHOW COLUMNS FROM assignments LIKE 'deadline_type'")->rowCount() > 0;
assert_test("Column 'deadline_type' on table 'assignments' exists", $col_check_assignments);

$col_check_submissions = $pdo->query("SHOW COLUMNS FROM assignment_submissions LIKE 'is_late'")->rowCount() > 0;
assert_test("Column 'is_late' on table 'assignment_submissions' exists", $col_check_submissions);

$col_check_announcements = $pdo->query("SHOW COLUMNS FROM announcements LIKE 'is_urgent'")->rowCount() > 0;
assert_test("Column 'is_urgent' on table 'announcements' exists", $col_check_announcements);

// 2. Course Catalog & Curriculum Mapping Logic
echo "\n--- 2. COURSE CATALOG & CURRICULUM LOGIC ---\n";
$course = $pdo->query("SELECT * FROM courses LIMIT 1")->fetch();
assert_test("Seeded academic course exists", !empty($course));

if ($course) {
    $units = $pdo->prepare("SELECT * FROM curriculum_units WHERE course_id = ?");
    $units->execute([$course['course_id']]);
    $units_res = $units->fetchAll();
    assert_test("Curriculum units bound to course found (" . count($units_res) . ")", count($units_res) > 0);

    if (!empty($units_res)) {
        $topics = $pdo->prepare("SELECT * FROM curriculum_topics WHERE unit_id = ?");
        $topics->execute([$units_res[0]['unit_id']]);
        $topics_res = $topics->fetchAll();
        assert_test("Curriculum topics with learning objectives found (" . count($topics_res) . ")", count($topics_res) > 0);
    }
}

// 3. Digital Resource Repository Logic
echo "\n--- 3. LEARNING MATERIALS & REPOSITORY LOGIC ---\n";
$materials = $pdo->query("SELECT * FROM course_materials")->fetchAll();
assert_test("Course materials records exist (" . count($materials) . ")", count($materials) > 0);
if (!empty($materials)) {
    $m = $materials[0];
    assert_test("Material has release scheduling & visibility lock attributes", isset($m['publish_type']) && isset($m['visibility']));
}

// 4. Assignments, Homework & CA Sync Logic
echo "\n--- 4. ASSIGNMENTS & CA GRADEBOOK SYNC LOGIC ---\n";
$assignment = $pdo->query("SELECT * FROM assignments LIMIT 1")->fetch();
assert_test("Course assignment exists", !empty($assignment));

if ($assignment) {
    assert_test("Assignment configured with max_score and deadline_type", isset($assignment['max_score']) && isset($assignment['deadline_type']));
}

// Check Continuous Assessment (CA) field in grades table
$has_ca_score = $pdo->query("SHOW COLUMNS FROM grades LIKE 'ca_score'")->rowCount() > 0;
assert_test("Gradebook table 'grades' contains 'ca_score' for continuous assessment aggregation", $has_ca_score);

// 5. Discussion Boards Logic
echo "\n--- 5. TOPIC DISCUSSION BOARDS LOGIC ---\n";
$threads = $pdo->query("SELECT * FROM course_discussions")->fetchAll();
assert_test("Course discussion threads exist (" . count($threads) . ")", count($threads) > 0);
if (!empty($threads)) {
    $t = $threads[0];
    assert_test("Thread supports moderation locking (is_locked)", isset($t['is_locked']));
}

// 6. Announcements & Noticeboard Stream
echo "\n--- 6. ANNOUNCEMENTS & URGENT BROADCAST LOGIC ---\n";
$announcements = $pdo->query("SELECT * FROM announcements")->fetchAll();
assert_test("Announcements table accessible", is_array($announcements));

// 7. PHP Syntax Verification for all LMS Files
echo "\n--- 7. PHP SYNTAX & FILE INTEGRITY CHECKS ---\n";
$files_to_lint = [
    'modules/LMS/index.php',
    'modules/LMS/courses.php',
    'modules/LMS/materials.php',
    'modules/LMS/assignments.php',
    'modules/LMS/discussions.php',
    'modules/LMS/announcements.php',
    'modules/LMS/student_portal.php',
    'modules/LMS/parent_portal.php',
    'docs.php'
];

foreach ($files_to_lint as $f) {
    $path = __DIR__ . '/../' . $f;
    $output = [];
    $return_var = 0;
    exec("php -l \"$path\"", $output, $return_var);
    assert_test("File '$f' syntax clean", $return_var === 0);
}

// 8. Simulated Execution of LMS Webpages as Admin
echo "\n--- 8. SIMULATED PAGE RENDERING IN SESSION CONTEXT ---\n";

// Login session setup for admin
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';
$_SESSION['full_name'] = 'System Administrator';

// Test courses.php rendering
ob_start();
try {
    include __DIR__ . '/../modules/LMS/courses.php';
    $output = ob_get_clean();
    assert_test("courses.php executes without uncaught exception", strlen($output) > 500 && strpos($output, 'Course Catalog') !== false);
} catch (\Throwable $e) {
    ob_end_clean();
    assert_test("courses.php executes cleanly: " . $e->getMessage(), false);
}

// Test materials.php rendering
ob_start();
try {
    include __DIR__ . '/../modules/LMS/materials.php';
    $output = ob_get_clean();
    assert_test("materials.php executes without uncaught exception", strlen($output) > 500 && strpos($output, 'Digital Repository') !== false);
} catch (\Throwable $e) {
    ob_end_clean();
    assert_test("materials.php executes cleanly: " . $e->getMessage(), false);
}

// Test discussions.php rendering
ob_start();
try {
    include __DIR__ . '/../modules/LMS/discussions.php';
    $output = ob_get_clean();
    assert_test("discussions.php executes without uncaught exception", strlen($output) > 500 && strpos($output, 'Topic Discussion Boards') !== false);
} catch (\Throwable $e) {
    ob_end_clean();
    assert_test("discussions.php executes cleanly: " . $e->getMessage(), false);
}

// Test announcements.php rendering
ob_start();
try {
    include __DIR__ . '/../modules/LMS/announcements.php';
    $output = ob_get_clean();
    assert_test("announcements.php executes without uncaught exception", strlen($output) > 500 && strpos($output, 'Class Stream') !== false);
} catch (\Throwable $e) {
    ob_end_clean();
    assert_test("announcements.php executes cleanly: " . $e->getMessage(), false);
}

// Test index.php rendering
ob_start();
try {
    include __DIR__ . '/../modules/LMS/index.php';
    $output = ob_get_clean();
    assert_test("index.php executes without uncaught exception", strlen($output) > 500 && strpos($output, 'LMS & Learning') !== false);
} catch (\Throwable $e) {
    ob_end_clean();
    assert_test("index.php executes cleanly: " . $e->getMessage(), false);
}

echo "\n========================================================\n";
echo "VERIFICATION SUMMARY: $passed PASSED, $failed FAILED\n";
echo "========================================================\n";

if ($failed > 0) {
    exit(1);
}
exit(0);
