<?php
require_once __DIR__ . '/../core/init.php';

try {
    // 1. Add parent_id column to students if not exists
    $colCheck = $pdo->query("SHOW COLUMNS FROM students LIKE 'parent_id'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE students ADD COLUMN parent_id INT(11) NULL DEFAULT NULL AFTER user_id");
        echo "Column parent_id added to students table successfully.\n";
    } else {
        echo "Column parent_id already exists in students table.\n";
    }

    // 2. Sync existing mappings from parent_student_map into students.parent_id
    $pdo->exec("
        UPDATE students s
        JOIN parent_student_map psm ON s.student_id = psm.student_id
        SET s.parent_id = COALESCE(psm.parent_user_id, psm.parent_id)
        WHERE s.parent_id IS NULL
    ");
    echo "Synced parent_student_map records into students.parent_id.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
