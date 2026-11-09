<?php
require 'core/init.php';

echo "=== Applying Production Concurrency & Performance Indexes ===\n";

function safeExecute($pdo, $sql, $label) {
    try {
        $pdo->exec($sql);
        echo "  [SUCCESS] $label\n";
    } catch (Exception $e) {
        if (str_contains($e->getMessage(), 'Duplicate key') || str_contains($e->getMessage(), 'already exists')) {
            echo "  [EXISTS] $label already in place\n";
        } else {
            echo "  [INFO] $label: " . $e->getMessage() . "\n";
        }
    }
}

// 1. Users / RBAC Indexes
safeExecute($pdo, "CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role_id, status)", "users(role_id, status) index");

// 2. Students Indexes
// Ensure student_code exists and populated from admission_no if null
try {
    $pdo->exec("ALTER TABLE students ADD COLUMN IF NOT EXISTS student_code VARCHAR(50) DEFAULT NULL AFTER admission_no");
    $pdo->exec("UPDATE students SET student_code = admission_no WHERE student_code IS NULL OR student_code = ''");
    echo "  [SUCCESS] students.student_code column verified\n";
} catch (Exception $e) {
    echo "  [INFO] students.student_code: " . $e->getMessage() . "\n";
}

safeExecute($pdo, "CREATE UNIQUE INDEX IF NOT EXISTS idx_students_student_code ON students(student_code)", "students(student_code) unique index");
safeExecute($pdo, "CREATE INDEX IF NOT EXISTS idx_students_enrollment_class ON students(enrollment_status, class_id)", "students(enrollment_status, class_id) index");

// 3. Attendance Indexes
safeExecute($pdo, "CREATE INDEX IF NOT EXISTS idx_student_att_class_date ON student_attendance(class_id, date)", "student_attendance(class_id, date) index");
safeExecute($pdo, "CREATE INDEX IF NOT EXISTS idx_student_att_student_date ON student_attendance(student_id, date)", "student_attendance(student_id, date) index");

// 4. Audit / Logs Indexes
safeExecute($pdo, "CREATE INDEX IF NOT EXISTS idx_audit_created_action ON audit_logs(created_at, action)", "audit_logs(created_at, action) index");

echo "\nIndex verification complete.\n";
