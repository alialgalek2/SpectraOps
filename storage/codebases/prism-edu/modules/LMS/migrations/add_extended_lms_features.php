<?php
// modules/LMS/migrations/add_extended_lms_features.php
// Database migration for Extended LMS Capabilities:
// 1. Courses, Curriculum Units & Topics (Hierarchical Syllabi)
// 2. Course Materials & Digital Resource Repository
// 3. Extended Assignments (Scoring models, Rubrics, Soft/Hard Deadlines)
// 4. Submission Tracking (Late flags, Delay minutes, Numeric scores)
// 5. Course Topic Discussions & Replies

require_once __DIR__ . '/../../../core/init.php';

try {
    // 1. Courses Master Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS courses (
        course_id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        academic_year VARCHAR(50) NOT NULL DEFAULT '2025/2026',
        term VARCHAR(50) NOT NULL DEFAULT 'Term 1',
        course_code VARCHAR(50) NULL,
        course_name VARCHAR(255) NOT NULL,
        syllabus_overview TEXT NULL,
        status ENUM('Active', 'Archived', 'Draft') NOT NULL DEFAULT 'Active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (class_id),
        INDEX (subject_id),
        INDEX (teacher_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Curriculum Units Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS curriculum_units (
        unit_id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        unit_number INT NOT NULL DEFAULT 1,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        estimated_periods INT NOT NULL DEFAULT 4,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (course_id),
        FOREIGN KEY (course_id) REFERENCES courses(course_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 3. Curriculum Topics / Lesson Plans Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS curriculum_topics (
        topic_id INT AUTO_INCREMENT PRIMARY KEY,
        unit_id INT NOT NULL,
        topic_number INT NOT NULL DEFAULT 1,
        title VARCHAR(255) NOT NULL,
        learning_objectives TEXT NULL,
        estimated_periods INT NOT NULL DEFAULT 2,
        completion_status ENUM('Not Started', 'In Progress', 'Completed') NOT NULL DEFAULT 'Not Started',
        completed_at DATETIME NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (unit_id),
        FOREIGN KEY (unit_id) REFERENCES curriculum_units(unit_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 4. Course Materials & Digital Resource Repository Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_materials (
        material_id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        unit_id INT NULL,
        topic_id INT NULL,
        teacher_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT NULL,
        material_type ENUM('pdf_slide', 'worksheet', 'web_link', 'video_embed', 'document') NOT NULL DEFAULT 'document',
        file_path VARCHAR(255) NULL,
        external_url VARCHAR(500) NULL,
        file_size_bytes BIGINT NULL DEFAULT 0,
        file_ext VARCHAR(20) NULL,
        publish_type ENUM('immediate', 'scheduled') NOT NULL DEFAULT 'immediate',
        unlock_at DATETIME NULL,
        visibility ENUM('student', 'teacher_only', 'draft') NOT NULL DEFAULT 'student',
        download_count INT NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (course_id),
        INDEX (unit_id),
        INDEX (visibility),
        FOREIGN KEY (course_id) REFERENCES courses(course_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 5. Extended Columns in assignments Table
    $cols = $pdo->query("SHOW COLUMNS FROM assignments")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('course_id', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN course_id INT NULL AFTER teacher_id, ADD INDEX (course_id)");
    }
    if (!in_array('max_score', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN max_score DECIMAL(5,2) NOT NULL DEFAULT 100.00 AFTER instructions");
    }
    if (!in_array('submission_type', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN submission_type ENUM('file_upload', 'online_text', 'both', 'physical') NOT NULL DEFAULT 'both' AFTER max_score");
    }
    if (!in_array('deadline_type', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN deadline_type ENUM('soft', 'hard') NOT NULL DEFAULT 'soft' AFTER submission_type");
    }
    if (!in_array('start_date', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN start_date DATETIME NULL AFTER due_date");
    }
    if (!in_array('cut_off_date', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN cut_off_date DATETIME NULL AFTER start_date");
    }
    if (!in_array('late_penalty_percent', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN late_penalty_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER cut_off_date");
    }
    if (!in_array('rubric_criteria', $cols)) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN rubric_criteria LONGTEXT NULL AFTER late_penalty_percent");
    }

    // 6. Extended Columns in assignment_submissions Table
    $subCols = $pdo->query("SHOW COLUMNS FROM assignment_submissions")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('score_numeric', $subCols)) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN score_numeric DECIMAL(5,2) NULL AFTER grade");
    }
    if (!in_array('is_late', $subCols)) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN is_late TINYINT(1) NOT NULL DEFAULT 0 AFTER score_numeric");
    }
    if (!in_array('delay_minutes', $subCols)) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN delay_minutes INT NOT NULL DEFAULT 0 AFTER is_late");
    }
    if (!in_array('rubric_scores', $subCols)) {
        $pdo->exec("ALTER TABLE assignment_submissions ADD COLUMN rubric_scores LONGTEXT NULL AFTER delay_minutes");
    }

    // 7. Extended Columns in announcements Table
    $annCols = $pdo->query("SHOW COLUMNS FROM announcements")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('class_id', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN class_id INT NULL DEFAULT NULL AFTER target_role, ADD INDEX (class_id)");
    }
    if (!in_array('file_path', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN file_path VARCHAR(255) NULL AFTER message");
    }
    if (!in_array('is_urgent', $annCols)) {
        $pdo->exec("ALTER TABLE announcements ADD COLUMN is_urgent TINYINT(1) NOT NULL DEFAULT 0 AFTER file_path");
    }

    // 8. Moderated Topic Discussions Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_discussions (
        discussion_id INT AUTO_INCREMENT PRIMARY KEY,
        course_id INT NOT NULL,
        topic_id INT NULL,
        teacher_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        prompt TEXT NOT NULL,
        is_locked TINYINT(1) NOT NULL DEFAULT 0,
        is_pinned TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (course_id),
        INDEX (topic_id),
        FOREIGN KEY (course_id) REFERENCES courses(course_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 9. Discussion Replies Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS course_discussion_replies (
        reply_id INT AUTO_INCREMENT PRIMARY KEY,
        discussion_id INT NOT NULL,
        user_id INT NOT NULL,
        reply_text TEXT NOT NULL,
        is_pinned_answer TINYINT(1) NOT NULL DEFAULT 0,
        is_hidden TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (discussion_id),
        INDEX (user_id),
        FOREIGN KEY (discussion_id) REFERENCES course_discussions(discussion_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 10. Seed Initial Data If Courses is Empty
    $count = $pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    if ($count == 0) {
        // Fetch default class and subject
        $class = $pdo->query("SELECT class_id, class_name FROM classes LIMIT 1")->fetch();
        $subject = $pdo->query("SELECT subject_id, subject_name FROM subjects LIMIT 1")->fetch();
        $teacher = $pdo->query("SELECT user_id FROM users WHERE role = 'teacher' LIMIT 1")->fetch();
        $teacher_id = $teacher ? $teacher['user_id'] : 1;
        $class_id = $class ? $class['class_id'] : 1;
        $subject_id = $subject ? $subject['subject_id'] : 1;
        $class_name = $class ? $class['class_name'] : 'Grade 8';
        $sub_name = $subject ? $subject['subject_name'] : 'Mathematics';

        // Insert Default Course
        $insCourse = $pdo->prepare("
            INSERT INTO courses (class_id, subject_id, teacher_id, academic_year, term, course_code, course_name, syllabus_overview, status)
            VALUES (?, ?, ?, '2025/2026', 'Term 1', 'MATH-8-T1', ?, ?, 'Active')
        ");
        $insCourse->execute([
            $class_id,
            $subject_id,
            $teacher_id,
            "$class_name - $sub_name (Term 1)",
            "Comprehensive foundation in algebra, functions, coordinate geometry, and numerical theory."
        ]);
        $course_id = $pdo->lastInsertId();

        // Insert Units
        $insUnit = $pdo->prepare("INSERT INTO curriculum_units (course_id, unit_number, title, description, estimated_periods, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $insUnit->execute([$course_id, 1, 'Unit 1: Linear Equations & Inequalities', 'Solving single-variable and multi-variable linear expressions.', 8, 1]);
        $unit1_id = $pdo->lastInsertId();

        $insUnit->execute([$course_id, 2, 'Unit 2: Geometric Proofs & Coordinate Systems', 'Cartesian coordinates, slope calculations, and congruence proofs.', 10, 2]);
        $unit2_id = $pdo->lastInsertId();

        // Insert Topics
        $insTopic = $pdo->prepare("
            INSERT INTO curriculum_topics (unit_id, topic_number, title, learning_objectives, estimated_periods, completion_status, completed_at, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insTopic->execute([
            $unit1_id, 1, 'Lesson 1.1: Solving Multi-Step Equations',
            'Master the distributive property, combining like terms, and isolating variables.',
            2, 'Completed', date('Y-m-d H:i:s', strtotime('-5 days')), 1
        ]);
        $topic1_id = $pdo->lastInsertId();

        $insTopic->execute([
            $unit1_id, 2, 'Lesson 1.2: Absolute Value Inequalities',
            'Graph solution sets on the number line and formulate compound inequalities.',
            3, 'In Progress', NULL, 2
        ]);

        $insTopic->execute([
            $unit2_id, 1, 'Lesson 2.1: Distance & Midpoint Formulas',
            'Derive and apply distance and midpoint formulas in analytical coordinate geometry.',
            3, 'Not Started', NULL, 1
        ]);

        // Insert Sample Learning Materials
        $insMat = $pdo->prepare("
            INSERT INTO course_materials (course_id, unit_id, topic_id, teacher_id, title, description, material_type, external_url, visibility, publish_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insMat->execute([
            $course_id, $unit1_id, $topic1_id, $teacher_id,
            'Lecture Slides: Multi-Step Equations Breakdown',
            'Slide deck covering step-by-step problem sets and practice examples.',
            'pdf_slide', 'https://example.com/slides/algebra_unit1.pdf', 'student', 'immediate'
        ]);
        $insMat->execute([
            $course_id, $unit1_id, $topic1_id, $teacher_id,
            'Interactive Video Lecture: Visualizing Equations',
            '15-minute video walkthrough of common pitfalls and checking solutions.',
            'video_embed', 'https://www.youtube.com/watch?v=sample123', 'student', 'immediate'
        ]);

        // Insert Sample Discussion Thread
        $insDisc = $pdo->prepare("
            INSERT INTO course_discussions (course_id, topic_id, teacher_id, title, prompt, is_locked, is_pinned)
            VALUES (?, ?, ?, ?, ?, 0, 1)
        ");
        $insDisc->execute([
            $course_id, $topic1_id, $teacher_id,
            'Discussion: What is the fastest method to clear fractional coefficients?',
            'Share your preferred workflow when encountering equations with fractional terms: Cross-multiplication vs. Multiplying by the LCM. Explain why with an example!',
        ]);
        $disc_id = $pdo->lastInsertId();

        $insReply = $pdo->prepare("INSERT INTO course_discussion_replies (discussion_id, user_id, reply_text, is_pinned_answer) VALUES (?, ?, ?, ?)");
        $insReply->execute([
            $disc_id, $teacher_id,
            'Top Recommendation: Always multiply the entire equation by the Least Common Multiple (LCM) of all denominators first. It eliminates fractions in a single step!',
            1
        ]);
    }

    echo "LMS Extended Capabilities Migration successfully executed!\n";
} catch (Exception $e) {
    echo "Migration Error: " . $e->getMessage() . "\n";
    exit(1);
}
