<?php
require_once __DIR__ . '/../core/init.php';

echo "=== ASSIGNMENTS COUNT ===\n";
$asn = $pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
echo "Assignments: $asn\n";

$sub = $pdo->query("SELECT COUNT(*) FROM assignment_submissions")->fetchColumn();
echo "Submissions: $sub\n";

if ($asn > 0) {
    echo "\n=== SAMPLE ASSIGNMENTS ===\n";
    $samples = $pdo->query("SELECT a.*, c.class_name, s.subject_name, u.full_name as teacher_name FROM assignments a JOIN classes c ON a.class_id = c.class_id JOIN subjects s ON a.subject_id = s.subject_id LEFT JOIN users u ON a.teacher_id = u.user_id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($samples);
}

if ($sub > 0) {
    echo "\n=== SAMPLE SUBMISSIONS ===\n";
    $sub_samples = $pdo->query("SELECT subm.*, st.admission_no, u.full_name as student_name FROM assignment_submissions subm JOIN students st ON subm.student_id = st.student_id JOIN users u ON st.user_id = u.user_id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    print_r($sub_samples);
}
