<?php
require_once __DIR__ . '/../core/init.php';

$parent_user_id = 2;

$stmt_ch = $pdo->prepare("
    SELECT s.student_id, s.admission_no, s.class_id, u.full_name, c.class_name
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN parent_student_map psm ON s.student_id = psm.student_id
    LEFT JOIN parents p ON psm.parent_id = p.parent_id
    WHERE p.user_id = ? OR psm.parent_user_id = ? OR s.parent_id = ?
    GROUP BY s.student_id
");
$stmt_ch->execute([$parent_user_id, $parent_user_id, $parent_user_id]);
$children = $stmt_ch->fetchAll(PDO::FETCH_ASSOC);

echo "Children for user_id = $parent_user_id:\n";
print_r($children);
