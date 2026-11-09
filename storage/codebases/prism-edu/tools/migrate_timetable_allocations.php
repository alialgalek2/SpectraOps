<?php
require_once __DIR__ . '/../core/init.php';

echo "=== MIGRATING TIMETABLE_ALLOCATIONS SCHEMA ===\n";

try {
    // 1. Check if allocation_type column exists
    $colCheck = $pdo->query("SHOW COLUMNS FROM timetable_allocations LIKE 'allocation_type'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE timetable_allocations ADD COLUMN allocation_type ENUM('class', 'activity', 'break') DEFAULT 'class' AFTER subject_name");
        echo "  [OK] Added allocation_type column.\n";
    } else {
        echo "  [OK] allocation_type column already exists.\n";
    }

    // 2. Modify teacher_id and room_number to be NULLable
    $pdo->exec("ALTER TABLE timetable_allocations MODIFY teacher_id INT NULL");
    echo "  [OK] Modified teacher_id to INT NULL.\n";

    $pdo->exec("ALTER TABLE timetable_allocations MODIFY room_number VARCHAR(50) NULL");
    echo "  [OK] Modified room_number to VARCHAR(50) NULL.\n";

    // 3. Verify columns
    $cols = $pdo->query("DESCRIBE timetable_allocations")->fetchAll(PDO::FETCH_ASSOC);
    print_r($cols);

    echo "\n=== MIGRATION COMPLETED SUCCESSFULLY ===\n";
} catch (Exception $e) {
    echo "Error during migration: " . $e->getMessage() . "\n";
}
