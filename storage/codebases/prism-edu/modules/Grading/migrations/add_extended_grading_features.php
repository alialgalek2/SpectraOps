<?php
/**
 * Database Schema Migration for Extended Examinations, Grading & Reports Module
 */

require_once __DIR__ . '/../../../core/init.php';

echo "Starting Examinations & Grading extended schema migration...\n";

try {

    // 1. Exam Series & Term Hierarchy
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_series (
            series_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            series_code VARCHAR(50) NOT NULL UNIQUE,
            academic_year VARCHAR(30) NOT NULL,
            term VARCHAR(50) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            weight_percent DECIMAL(5,2) DEFAULT 100.00,
            status ENUM('Upcoming', 'Active', 'Under Review', 'Completed', 'Published') DEFAULT 'Active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. Grading Scales
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grading_scales (
            scale_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            scale_type ENUM('letter', 'gpa_4', 'gpa_5', 'descriptive') NOT NULL DEFAULT 'letter',
            is_default TINYINT(1) DEFAULT 0,
            description VARCHAR(255) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 3. Grading Scale Tiers
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grading_scale_tiers (
            tier_id INT AUTO_INCREMENT PRIMARY KEY,
            scale_id INT NOT NULL,
            grade_letter VARCHAR(20) NOT NULL,
            min_percentage DECIMAL(5,2) NOT NULL,
            max_percentage DECIMAL(5,2) NOT NULL,
            gpa_point DECIMAL(4,2) DEFAULT 0.00,
            performance_band VARCHAR(50) NULL,
            remark VARCHAR(100) NULL,
            KEY idx_scale_id (scale_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 4. Grade Weightage Policies
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS grade_weightage_policies (
            policy_id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(150) NOT NULL,
            academic_year VARCHAR(30) NOT NULL,
            term VARCHAR(50) NOT NULL,
            class_id INT NULL,
            subject_id INT NULL,
            ca_weight DECIMAL(5,2) DEFAULT 20.00,
            midterm_weight DECIMAL(5,2) DEFAULT 30.00,
            final_weight DECIMAL(5,2) DEFAULT 50.00,
            ranking_method ENUM('standard', 'dense') DEFAULT 'standard',
            show_rank_on_report TINYINT(1) DEFAULT 1,
            clearance_threshold DECIMAL(10,2) DEFAULT 0.00,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 5. Exam Halls
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_halls (
            hall_id INT AUTO_INCREMENT PRIMARY KEY,
            hall_name VARCHAR(100) NOT NULL,
            building VARCHAR(100) NULL,
            capacity INT NOT NULL DEFAULT 40,
            hall_type ENUM('hall', 'lab', 'classroom') DEFAULT 'hall'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 6. Exam Timetables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_timetables (
            timetable_id INT AUTO_INCREMENT PRIMARY KEY,
            series_id INT NOT NULL,
            subject_id INT NOT NULL,
            class_id INT NOT NULL,
            hall_id INT NOT NULL,
            exam_date DATE NOT NULL,
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            invigilator_id INT NULL,
            relief_invigilator_id INT NULL,
            status ENUM('Scheduled', 'In Progress', 'Completed', 'Cancelled') DEFAULT 'Scheduled',
            KEY idx_series (series_id),
            KEY idx_subject (subject_id),
            KEY idx_class (class_id),
            KEY idx_hall (hall_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 7. Exam Seating Allocations
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_seating_allocations (
            allocation_id INT AUTO_INCREMENT PRIMARY KEY,
            timetable_id INT NOT NULL,
            student_id INT NOT NULL,
            hall_id INT NOT NULL,
            desk_number INT NOT NULL,
            attendance_status ENUM('Present', 'Absent', 'Exempt', 'Medical Leave', 'Disqualified') DEFAULT 'Present',
            KEY idx_timetable (timetable_id),
            KEY idx_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 8. Exam Moderation Logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exam_moderation_logs (
            log_id INT AUTO_INCREMENT PRIMARY KEY,
            series_id INT NOT NULL,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            stage ENUM('draft', 'department_review', 'locked') NOT NULL,
            moderated_by INT NOT NULL,
            action_note TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 9. Alter existing 'grades' table with extended columns
    $existing_cols = $pdo->query("SHOW COLUMNS FROM grades")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('series_id', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN series_id INT NULL AFTER academic_year;");
    }
    if (!in_array('paper1_score', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN paper1_score DECIMAL(5,2) NULL AFTER exam_score;");
    }
    if (!in_array('paper2_score', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN paper2_score DECIMAL(5,2) NULL AFTER paper1_score;");
    }
    if (!in_array('coursework_score', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN coursework_score DECIMAL(5,2) NULL AFTER paper2_score;");
    }
    if (!in_array('special_condition', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN special_condition ENUM('NONE', 'AB', 'EX', 'ML', 'DQ') DEFAULT 'NONE' AFTER grade_letter;");
    }
    if (!in_array('moderation_status', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN moderation_status ENUM('draft', 'reviewed', 'locked') DEFAULT 'draft' AFTER special_condition;");
    }
    if (!in_array('rank_in_class', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN rank_in_class INT NULL AFTER moderation_status;");
    }
    if (!in_array('gpa_points', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN gpa_points DECIMAL(4,2) NULL AFTER rank_in_class;");
    }
    if (!in_array('conduct_rating', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN conduct_rating VARCHAR(50) DEFAULT 'Excellent' AFTER gpa_points;");
    }
    if (!in_array('teacher_comment', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN teacher_comment TEXT NULL AFTER conduct_rating;");
    }
    if (!in_array('principal_comment', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN principal_comment TEXT NULL AFTER teacher_comment;");
    }
    if (!in_array('verification_hash', $existing_cols)) {
        $pdo->exec("ALTER TABLE grades ADD COLUMN verification_hash VARCHAR(64) NULL AFTER principal_comment;");
    }

    // 10. Seed Default Grading Scales & Tiers
    $scale_count = $pdo->query("SELECT count(*) FROM grading_scales")->fetchColumn();
    if ($scale_count == 0) {
        // Standard Letter & GPA Scale
        $pdo->exec("
            INSERT INTO grading_scales (scale_id, name, scale_type, is_default, description) VALUES
            (1, 'Standard International (Letter & 4.0 GPA)', 'gpa_4', 1, 'Standard academic grading scale with A* through F and 4.0 quality point conversion.');
        ");

        $pdo->exec("
            INSERT INTO grading_scale_tiers (scale_id, grade_letter, min_percentage, max_percentage, gpa_point, performance_band, remark) VALUES
            (1, 'A*', 95.00, 100.00, 4.00, 'Distinction', 'Outstanding Academic Excellence'),
            (1, 'A',  90.00, 94.99,  3.80, 'Distinction', 'Excellent Mastery'),
            (1, 'B+', 85.00, 89.99,  3.50, 'Merit', 'Very Good Performance'),
            (1, 'B',  80.00, 84.99,  3.00, 'Merit', 'Good Performance'),
            (1, 'C+', 75.00, 79.99,  2.50, 'Pass', 'Above Average'),
            (1, 'C',  70.00, 74.99,  2.00, 'Pass', 'Satisfactory Competency'),
            (1, 'D',  50.00, 69.99,  1.00, 'Pass', 'Minimum Passing Standard'),
            (1, 'F',  0.00,  49.99,  0.00, 'Fail', 'Unsatisfactory / Unsatisfied Requirements');
        ");
    }

    // 11. Seed Default Exam Series
    $series_count = $pdo->query("SELECT count(*) FROM exam_series")->fetchColumn();
    if ($series_count == 0) {
        $pdo->exec("
            INSERT INTO exam_series (title, series_code, academic_year, term, start_date, end_date, weight_percent, status) VALUES
            ('Term 1 Final Examination', 'EXAM-2025-T1-FINAL', '2025/2026', 'Term 1', '2025-11-15', '2025-11-25', 50.00, 'Active'),
            ('Mid-Term Assessment Series', 'EXAM-2025-T1-MID', '2025/2026', 'Term 1', '2025-10-10', '2025-10-18', 30.00, 'Completed');
        ");
    }

    // 12. Seed Default Weightage Policy
    $policy_count = $pdo->query("SELECT count(*) FROM grade_weightage_policies")->fetchColumn();
    if ($policy_count == 0) {
        $pdo->exec("
            INSERT INTO grade_weightage_policies (title, academic_year, term, ca_weight, midterm_weight, final_weight, ranking_method, show_rank_on_report, clearance_threshold) VALUES
            ('Standard Secondary Weightage Formula', '2025/2026', 'Term 1', 20.00, 30.00, 50.00, 'standard', 1, 0.00);
        ");
    }

    // 13. Seed Default Exam Halls
    $hall_count = $pdo->query("SELECT count(*) FROM exam_halls")->fetchColumn();
    if ($hall_count == 0) {
        $pdo->exec("
            INSERT INTO exam_halls (hall_id, hall_name, building, capacity, hall_type) VALUES
            (1, 'Grand Examination Hall A', 'Main Building 2nd Floor', 60, 'hall'),
            (2, 'Computer Science Lab 1', 'Science & Tech Wing', 30, 'lab'),
            (3, 'Multi-Purpose Room 104', 'Academic Block A', 35, 'classroom');
        ");
    }

    // 14. Seed Sample Exam Timetable & Invigilation Entry
    $tt_count = $pdo->query("SELECT count(*) FROM exam_timetables")->fetchColumn();
    if ($tt_count == 0) {
        $teacher = $pdo->query("SELECT user_id FROM users WHERE role = 'teacher' LIMIT 1")->fetch();
        $teacher_id = $teacher ? (int)$teacher['user_id'] : 1;
        $class = $pdo->query("SELECT class_id FROM classes LIMIT 1")->fetch();
        $class_id = $class ? (int)$class['class_id'] : 1;
        $subject = $pdo->query("SELECT subject_id FROM subjects LIMIT 1")->fetch();
        $subject_id = $subject ? (int)$subject['subject_id'] : 1;
        $series = $pdo->query("SELECT series_id FROM exam_series LIMIT 1")->fetch();
        $series_id = $series ? (int)$series['series_id'] : 1;

        $pdo->exec("
            INSERT INTO exam_timetables (series_id, subject_id, class_id, hall_id, exam_date, start_time, end_time, invigilator_id, status) VALUES
            ($series_id, $subject_id, $class_id, 1, '2025-11-16', '09:00:00', '11:00:00', $teacher_id, 'Scheduled');
        ");
        $new_tt_id = $pdo->lastInsertId();

        // Seed Sample Seating Allocation for class students
        $students = $pdo->query("SELECT student_id FROM students WHERE class_id = $class_id LIMIT 10")->fetchAll();
        $desk = 1;
        foreach ($students as $st) {
            $sid = (int)$st['student_id'];
            $pdo->exec("
                INSERT INTO exam_seating_allocations (timetable_id, student_id, hall_id, desk_number, attendance_status) VALUES
                ($new_tt_id, $sid, 1, $desk, 'Present');
            ");
            $desk++;
        }
    }

    echo "Examinations & Grading schema migration successfully completed!\n";

} catch (\Throwable $e) {
    echo "Migration error: " . $e->getMessage() . "\n";
    exit(1);
}
