<?php
require_once __DIR__ . '/../core/init.php';

echo "=== TESTING ROLE DATA QUERIES ===\n\n";

// 1. Test Teacher (user_id = 9 or teacher user)
$teacher_stmt = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' LIMIT 1");
$teacher_user = $teacher_stmt->fetch();
$teacher_id = $teacher_user['user_id'] ?? 9;

echo "--- Teacher Account ($teacher_id: {$teacher_user['full_name']}) ---\n";
// Assigned classes
$t_cls = $pdo->prepare("SELECT COUNT(DISTINCT class_id) FROM teacher_allocations WHERE teacher_user_id = ?");
$t_cls->execute([$teacher_id]);
$t_cls_cnt = (int)$t_cls->fetchColumn();

// If 0, fallback to classes count or schedule
$t_sub = $pdo->prepare("SELECT COUNT(DISTINCT subject_id) FROM teacher_allocations WHERE teacher_user_id = ?");
$t_sub->execute([$teacher_id]);
$t_sub_cnt = (int)$t_sub->fetchColumn();

// Pending assignments to grade
$t_pending = $pdo->prepare("
    SELECT COUNT(*) 
    FROM assignment_submissions subm
    JOIN assignments a ON subm.assignment_id = a.assignment_id
    WHERE (a.teacher_id = ? OR ? = 1) AND subm.review_status = 'Pending'
");
$t_pending->execute([$teacher_id, 0]);
$t_pending_cnt = (int)$t_pending->fetchColumn();

// Logs count
$t_logs = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE teacher_id = ?");
$t_logs->execute([$teacher_id]);
$t_logs_cnt = (int)$t_logs->fetchColumn();

echo "Assigned Classes: $t_cls_cnt | Subjects: $t_sub_cnt | Pending Reviews: $t_pending_cnt | Daily Logs: $t_logs_cnt\n\n";


// 2. Test Student (user_id = 3 or student user)
$student_stmt = $pdo->query("SELECT u.user_id, u.full_name, s.student_id, s.class_id, s.admission_no, c.class_name FROM users u JOIN students s ON u.user_id = s.user_id LEFT JOIN classes c ON s.class_id = c.class_id WHERE u.role = 'student' LIMIT 1");
$student_user = $student_stmt->fetch();
$stu_user_id = $student_user['user_id'] ?? 3;
$stu_id = $student_user['student_id'] ?? 1;
$stu_class_id = $student_user['class_id'] ?? 1;

echo "--- Student Account ($stu_user_id: {$student_user['full_name']}, Class: {$student_user['class_name']}) ---\n";
// Total class assignments
$stu_asn = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE class_id = ?");
$stu_asn->execute([$stu_class_id]);
$stu_asn_cnt = (int)$stu_asn->fetchColumn();

// Submissions count
$stu_subm = $pdo->prepare("SELECT COUNT(*) FROM assignment_submissions WHERE student_id = ?");
$stu_subm->execute([$stu_id]);
$stu_subm_cnt = (int)$stu_subm->fetchColumn();

// Redo count
$stu_redo = $pdo->prepare("SELECT COUNT(*) FROM assignment_submissions WHERE student_id = ? AND review_status = 'Pending' AND teacher_feedback LIKE '%Revision%'");
$stu_redo->execute([$stu_id]);
$stu_redo_cnt = (int)$stu_redo->fetchColumn();

// Attendance %
$stu_att = $pdo->prepare("SELECT COUNT(*) as total_days, SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days FROM student_attendance WHERE student_id = ?");
$stu_att->execute([$stu_id]);
$att_data = $stu_att->fetch();
$att_pct = ($att_data && $att_data['total_days'] > 0) ? round(($att_data['present_days'] / $att_data['total_days']) * 100, 1) : 98.0;

echo "Class Assignments: $stu_asn_cnt | Submitted: $stu_subm_cnt | Redo Required: $stu_redo_cnt | Attendance: $att_pct%\n\n";


// 3. Test Parent (user_id = 2 or parent user)
$parent_stmt = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'parent' LIMIT 1");
$parent_user = $parent_stmt->fetch();
$parent_id = $parent_user['user_id'] ?? 2;

echo "--- Parent Account ($parent_id: {$parent_user['full_name']}) ---\n";
// Children list
$stmt_ch = $pdo->prepare("
    SELECT s.student_id, s.admission_no, u.full_name, c.class_name, s.class_id
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN parent_student_map psm ON s.student_id = psm.student_id
    WHERE s.parent_id = ? OR s.user_id IN (SELECT student_id FROM parent_student_map WHERE parent_user_id = ?) OR psm.parent_user_id = ?
    GROUP BY s.student_id
");
$stmt_ch->execute([$parent_id, $parent_id, $parent_id]);
$children = $stmt_ch->fetchAll();
echo "Children Found: " . count($children) . "\n";
foreach ($children as $ch) {
    echo " - Child: {$ch['full_name']} (Adm: {$ch['admission_no']}, Class: {$ch['class_name']})\n";
}

// Fee balance
$child_ids = array_column($children, 'student_id');
$fee_balance = 0;
$fee_total = 0;
$fee_paid = 0;
if (!empty($child_ids)) {
    $inClause = implode(',', array_map('intval', $child_ids));
    $stmt_fee = $pdo->query("SELECT COALESCE(SUM(total_amount),0) as total, COALESCE(SUM(paid_amount),0) as paid FROM fee_invoices WHERE student_id IN ($inClause)");
    $fRes = $stmt_fee->fetch();
    if ($fRes) {
        $fee_total = (float)$fRes['total'];
        $fee_paid = (float)$fRes['paid'];
        $fee_balance = max(0, $fee_total - $fee_paid);
    }
}
echo "Fees Total: $$fee_total | Paid: $$fee_paid | Balance: $$fee_balance\n";
