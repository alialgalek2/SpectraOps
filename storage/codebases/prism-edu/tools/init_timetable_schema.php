<?php
require_once __DIR__ . '/../core/init.php';

// 1. Create timetable_periods if not exists
$pdo->exec("
CREATE TABLE IF NOT EXISTS timetable_periods (
    period_id INT AUTO_INCREMENT PRIMARY KEY,
    period_name VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    sort_order INT DEFAULT 1,
    is_break TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Populate default periods if table is empty
$count = $pdo->query("SELECT COUNT(*) FROM timetable_periods")->fetchColumn();
if ($count == 0) {
    $pdo->exec("
    INSERT INTO timetable_periods (period_name, start_time, end_time, sort_order, is_break) VALUES
    ('Period 1', '08:00:00', '08:45:00', 1, 0),
    ('Period 2', '08:45:00', '09:30:00', 2, 0),
    ('Morning Break', '09:30:00', '09:50:00', 3, 1),
    ('Period 3', '09:50:00', '10:35:00', 4, 0),
    ('Period 4', '10:35:00', '11:20:00', 5, 0),
    ('Lunch Break', '11:20:00', '12:00:00', 6, 1),
    ('Period 5', '12:00:00', '12:45:00', 7, 0),
    ('Period 6', '12:45:00', '13:30:00', 8, 0);
    ");
}

// 2. Create timetable_allocations if not exists
$pdo->exec("
CREATE TABLE IF NOT EXISTS timetable_allocations (
    allocation_id INT AUTO_INCREMENT PRIMARY KEY,
    period_id INT DEFAULT NULL,
    class_id INT NOT NULL,
    subject_id INT NOT NULL,
    teacher_id INT NOT NULL,
    subject_name VARCHAR(100) DEFAULT NULL,
    day_of_week VARCHAR(20) NOT NULL,
    start_time TIME DEFAULT NULL,
    end_time TIME DEFAULT NULL,
    room_number VARCHAR(50) DEFAULT 'Room 101',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// Synchronize existing data from timetables into timetable_allocations if empty
$aCount = $pdo->query("SELECT COUNT(*) FROM timetable_allocations")->fetchColumn();
if ($aCount == 0) {
    try {
        $rows = $pdo->query("
            SELECT t.*, s.subject_name 
            FROM timetables t 
            LEFT JOIN subjects s ON t.subject_id = s.subject_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        $ins = $pdo->prepare("
            INSERT INTO timetable_allocations (class_id, subject_id, teacher_id, subject_name, day_of_week, start_time, end_time, room_number, period_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $periods = $pdo->query("SELECT * FROM timetable_periods WHERE is_break = 0 ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $i => $r) {
            $pId = !empty($periods[$i % count($periods)]['period_id']) ? $periods[$i % count($periods)]['period_id'] : 1;
            $ins->execute([
                $r['class_id'],
                $r['subject_id'],
                $r['teacher_id'],
                $r['subject_name'] ?? 'Subject',
                $r['day_of_week'],
                $r['start_time'],
                $r['end_time'],
                $r['room_no'] ?? 'Room 101',
                $pId
            ]);
        }
    } catch (Exception $e) {}
}

// Ensure parent_student_map has parent_user_id column if needed
try {
    $cols = $pdo->query("DESCRIBE parent_student_map")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('parent_user_id', $cols)) {
        $pdo->exec("ALTER TABLE parent_student_map ADD COLUMN parent_user_id INT DEFAULT NULL");
        $pdo->exec("UPDATE parent_student_map SET parent_user_id = parent_id WHERE parent_user_id IS NULL");
    }
} catch (Exception $e) {}

echo "Timetable schema sync completed successfully.\n";
