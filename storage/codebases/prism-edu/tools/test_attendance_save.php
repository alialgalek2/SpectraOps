<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING ATTENDANCE SAVING & MESSAGE DISPATCH ===\n";

$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';

// Check student in class 1
$student = $pdo->query("SELECT student_id, class_id FROM students LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$student) {
    echo "No students found to test.\n";
    exit();
}

$student_id = (int)$student['student_id'];
$class_id   = (int)$student['class_id'];
$date       = date('Y-m-d');
$user_id    = 1;

try {
    $pdo->beginTransaction();

    // 1. Test student_attendance upsert
    $stmt = $pdo->prepare("
        INSERT INTO student_attendance (student_id, class_id, date, status, check_in_time, tracking_method, remarks, notified_parent, recorded_by)
        VALUES (?, ?, ?, 'Absent', CURRENT_TIME(), 'Manual', 'Testing attendance save', 1, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            remarks = VALUES(remarks),
            notified_parent = VALUES(notified_parent),
            recorded_by = VALUES(recorded_by)
    ");
    $stmt->execute([$student_id, $class_id, $date, $user_id]);
    echo "  [PASS] student_attendance upsert succeeded.\n";

    // 2. Test parent alert message dispatcher
    $msgStmt = $pdo->prepare("
        INSERT INTO messages (sender_id, receiver_id, student_id, subject, message, inquiry_type)
        SELECT ?, COALESCE(psm.parent_user_id, s.parent_id, u.user_id), s.student_id, ?, ?, 'Attendance'
        FROM students s
        LEFT JOIN parent_student_map psm ON s.student_id = psm.student_id
        LEFT JOIN users u ON (s.guardian_email IS NOT NULL AND s.guardian_email != '' AND s.guardian_email = u.email)
        WHERE s.student_id = ? AND (psm.parent_user_id IS NOT NULL OR s.parent_id IS NOT NULL OR u.user_id IS NOT NULL)
        LIMIT 1
    ");
    $subj = "URGENT: Attendance Alert for {$date}";
    $body = "Dear Parent, your child was marked as Absent today ({$date}). Please contact the school office if you have any questions.";
    $msgStmt->execute([$user_id, $subj, $body, $student_id]);
    echo "  [PASS] messages dispatcher query succeeded.\n";

    $pdo->commit();
    echo "\n=== ALL ATTENDANCE CHECKS PASSED WITH 0 ERRORS ===\n";

} catch (Exception $e) {
    $pdo->rollBack();
    echo "  [FAIL] Error: " . $e->getMessage() . "\n";
}
