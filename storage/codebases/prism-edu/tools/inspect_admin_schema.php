<?php
require_once __DIR__ . '/../core/init.php';

$tables_to_check = ['campuses', 'classes', 'academic_calendar', 'institution_branding', 'students', 'parent_student_map', 'parents', 'student_attendance', 'audit_logs', 'roles', 'permissions', 'role_permissions'];

foreach ($tables_to_check as $tbl) {
    echo "=== TABLE: $tbl ===\n";
    try {
        $cols = $pdo->query("DESCRIBE $tbl")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  {$c['Field']} ({$c['Type']}) {$c['Null']} {$c['Key']} {$c['Default']}\n";
        }
    } catch (\Throwable $e) {
        echo "  [ERROR: " . $e->getMessage() . "]\n";
    }
}
