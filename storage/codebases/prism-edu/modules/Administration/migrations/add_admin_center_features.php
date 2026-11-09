<?php
/**
 * Database Migration: Admin Center Enterprise Features
 * Pillars: Institutional Setup, Admissions Lifecycle, Household Mapping,
 * Daily Attendance & Behavior, RBAC & Audit Ledger, Rollover & Alumni.
 */

require_once __DIR__ . '/../../../core/init.php';

echo "Starting Admin Center Database Migration...\n";

// Helper to safely check if a column exists
function columnExists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (\Throwable $e) {
        return false;
    }
}

// 1. CREATE TABLE: educational_stages
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `educational_stages` (
            `stage_id` INT AUTO_INCREMENT PRIMARY KEY,
            `stage_name` VARCHAR(100) NOT NULL,
            `min_grade` INT NOT NULL DEFAULT 1,
            `max_grade` INT NOT NULL DEFAULT 12,
            `description` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[OK] Table 'educational_stages' ready.\n";
} catch (\Throwable $e) {
    echo "[ERR] educational_stages: " . $e->getMessage() . "\n";
}

// 2. CREATE TABLE: student_applications (Admissions Pipeline)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_applications` (
            `application_id` INT AUTO_INCREMENT PRIMARY KEY,
            `application_no` VARCHAR(50) NOT NULL UNIQUE,
            `first_name` VARCHAR(100) NOT NULL,
            `middle_name` VARCHAR(100) NULL,
            `last_name` VARCHAR(100) NOT NULL,
            `dob` DATE NOT NULL,
            `gender` ENUM('Male', 'Female') NOT NULL,
            `nationality` VARCHAR(100) DEFAULT 'Egyptian',
            `grade_applying_for` INT NOT NULL DEFAULT 1,
            `parent_name` VARCHAR(150) NOT NULL,
            `parent_phone` VARCHAR(50) NOT NULL,
            `parent_email` VARCHAR(150) NULL,
            `placement_test_score` DECIMAL(5,2) NULL,
            `status` ENUM('Inquiry', 'Application Received', 'Documents Verified', 'Assessment Scheduled', 'Approved', 'Enrolled', 'Rejected') DEFAULT 'Application Received',
            `documents_json` TEXT NULL,
            `reviewer_notes` TEXT NULL,
            `enrolled_student_id` INT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[OK] Table 'student_applications' ready.\n";
} catch (\Throwable $e) {
    echo "[ERR] student_applications: " . $e->getMessage() . "\n";
}

// 3. CREATE TABLE: student_pickup_authorizations
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_pickup_authorizations` (
            `auth_id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `pickup_name` VARCHAR(150) NOT NULL,
            `relationship` VARCHAR(50) NOT NULL DEFAULT 'Parent',
            `phone` VARCHAR(50) NOT NULL,
            `national_id` VARCHAR(50) NULL,
            `photo_path` VARCHAR(255) NULL,
            `is_emergency_contact` TINYINT(1) DEFAULT 0,
            `status` ENUM('Active', 'Suspended', 'Revoked') DEFAULT 'Active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[OK] Table 'student_pickup_authorizations' ready.\n";
} catch (\Throwable $e) {
    echo "[ERR] student_pickup_authorizations: " . $e->getMessage() . "\n";
}

// 4. CREATE TABLE: student_transport_mappings
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_transport_mappings` (
            `mapping_id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL UNIQUE,
            `route_name` VARCHAR(100) NOT NULL,
            `bus_number` VARCHAR(50) NOT NULL,
            `pickup_point` VARCHAR(150) NOT NULL,
            `dropoff_point` VARCHAR(150) NOT NULL,
            `driver_name` VARCHAR(100) NULL,
            `driver_contact` VARCHAR(50) NULL,
            `status` ENUM('Active', 'Inactive') DEFAULT 'Active',
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[OK] Table 'student_transport_mappings' ready.\n";
} catch (\Throwable $e) {
    echo "[ERR] student_transport_mappings: " . $e->getMessage() . "\n";
}

// 5. CREATE TABLE: student_behavior_records (Discipline & Merits)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_behavior_records` (
            `record_id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `record_type` ENUM('Merit', 'Infraction') NOT NULL DEFAULT 'Merit',
            `category` VARCHAR(100) NOT NULL,
            `title` VARCHAR(200) NOT NULL,
            `description` TEXT NULL,
            `points` INT NOT NULL DEFAULT 1,
            `incident_date` DATE NOT NULL,
            `reported_by` INT NULL,
            `action_taken` VARCHAR(255) NULL,
            `status` ENUM('Reported', 'Under Review', 'Resolved', 'Closed') DEFAULT 'Reported',
            `parent_notified` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`student_id`),
            INDEX (`incident_date`),
            INDEX (`record_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[OK] Table 'student_behavior_records' ready.\n";
} catch (\Throwable $e) {
    echo "[ERR] student_behavior_records: " . $e->getMessage() . "\n";
}

// 6. CREATE TABLE: student_transfer_certificates
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_transfer_certificates` (
            `tc_id` INT AUTO_INCREMENT PRIMARY KEY,
            `tc_number` VARCHAR(50) NOT NULL UNIQUE,
            `student_id` INT NOT NULL,
            `leaving_date` DATE NOT NULL,
            `leaving_reason` VARCHAR(255) NOT NULL,
            `conduct_summary` VARCHAR(100) DEFAULT 'Good',
            `promoted_to_grade` VARCHAR(50) NULL,
            `finance_cleared` TINYINT(1) DEFAULT 0,
            `library_cleared` TINYINT(1) DEFAULT 0,
            `issued_by` INT NULL,
            `issued_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `verification_hash` VARCHAR(64) NOT NULL,
            INDEX (`student_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[OK] Table 'student_transfer_certificates' ready.\n";
} catch (\Throwable $e) {
    echo "[ERR] student_transfer_certificates: " . $e->getMessage() . "\n";
}

// 7. CREATE TABLE: student_promotions_history
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `student_promotions_history` (
            `history_id` INT AUTO_INCREMENT PRIMARY KEY,
            `student_id` INT NOT NULL,
            `from_class_id` INT NULL,
            `to_class_id` INT NULL,
            `academic_year_from` VARCHAR(50) NOT NULL,
            `academic_year_to` VARCHAR(50) NOT NULL,
            `promotion_status` ENUM('Promoted', 'Retained', 'Graduated', 'Withdrawn') NOT NULL DEFAULT 'Promoted',
            `promoted_by` INT NULL,
            `promoted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`student_id`),
            INDEX (`academic_year_to`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    echo "[OK] Table 'student_promotions_history' ready.\n";
} catch (\Throwable $e) {
    echo "[ERR] student_promotions_history: " . $e->getMessage() . "\n";
}

// 8. ALTER TABLE: classes
try {
    if (!columnExists($pdo, 'classes', 'stage_id')) {
        $pdo->exec("ALTER TABLE `classes` ADD COLUMN `stage_id` INT NULL AFTER `class_name`");
        echo "[OK] Added column 'stage_id' to 'classes'.\n";
    }
    if (!columnExists($pdo, 'classes', 'capacity_limit')) {
        $pdo->exec("ALTER TABLE `classes` ADD COLUMN `capacity_limit` INT NOT NULL DEFAULT 35 AFTER `grade_level`");
        echo "[OK] Added column 'capacity_limit' to 'classes'.\n";
    }
} catch (\Throwable $e) {
    echo "[ERR] Alter classes: " . $e->getMessage() . "\n";
}

// 9. ALTER TABLE: institution_branding
try {
    if (!columnExists($pdo, 'institution_branding', 'registration_no')) {
        $pdo->exec("ALTER TABLE `institution_branding` ADD COLUMN `registration_no` VARCHAR(100) NULL AFTER `school_name`");
        echo "[OK] Added column 'registration_no' to 'institution_branding'.\n";
    }
    if (!columnExists($pdo, 'institution_branding', 'accreditation_seal')) {
        $pdo->exec("ALTER TABLE `institution_branding` ADD COLUMN `accreditation_seal` VARCHAR(255) NULL AFTER `registration_no`");
        echo "[OK] Added column 'accreditation_seal' to 'institution_branding'.\n";
    }
    if (!columnExists($pdo, 'institution_branding', 'default_currency')) {
        $pdo->exec("ALTER TABLE `institution_branding` ADD COLUMN `default_currency` VARCHAR(10) DEFAULT 'USD' AFTER `accreditation_seal`");
        echo "[OK] Added column 'default_currency' to 'institution_branding'.\n";
    }
    if (!columnExists($pdo, 'institution_branding', 'working_days_json')) {
        $pdo->exec("ALTER TABLE `institution_branding` ADD COLUMN `working_days_json` TEXT NULL AFTER `default_currency`");
        echo "[OK] Added column 'working_days_json' to 'institution_branding'.\n";
    }
    if (!columnExists($pdo, 'institution_branding', 'operating_hours')) {
        $pdo->exec("ALTER TABLE `institution_branding` ADD COLUMN `operating_hours` VARCHAR(100) DEFAULT '07:30 - 15:30' AFTER `working_days_json`");
        echo "[OK] Added column 'operating_hours' to 'institution_branding'.\n";
    }
    if (!columnExists($pdo, 'institution_branding', 'timezone')) {
        $pdo->exec("ALTER TABLE `institution_branding` ADD COLUMN `timezone` VARCHAR(50) DEFAULT 'Africa/Cairo' AFTER `operating_hours`");
        echo "[OK] Added column 'timezone' to 'institution_branding'.\n";
    }
} catch (\Throwable $e) {
    echo "[ERR] Alter institution_branding: " . $e->getMessage() . "\n";
}

// 10. ALTER TABLE: parent_student_map
try {
    if (!columnExists($pdo, 'parent_student_map', 'is_primary_payer')) {
        $pdo->exec("ALTER TABLE `parent_student_map` ADD COLUMN `is_primary_payer` TINYINT(1) DEFAULT 0");
        echo "[OK] Added column 'is_primary_payer' to 'parent_student_map'.\n";
    }
    if (!columnExists($pdo, 'parent_student_map', 'is_primary_academic')) {
        $pdo->exec("ALTER TABLE `parent_student_map` ADD COLUMN `is_primary_academic` TINYINT(1) DEFAULT 1");
        echo "[OK] Added column 'is_primary_academic' to 'parent_student_map'.\n";
    }
    if (!columnExists($pdo, 'parent_student_map', 'is_authorized_pickup')) {
        $pdo->exec("ALTER TABLE `parent_student_map` ADD COLUMN `is_authorized_pickup` TINYINT(1) DEFAULT 1");
        echo "[OK] Added column 'is_authorized_pickup' to 'parent_student_map'.\n";
    }
    if (!columnExists($pdo, 'parent_student_map', 'has_custody_restriction')) {
        $pdo->exec("ALTER TABLE `parent_student_map` ADD COLUMN `has_custody_restriction` TINYINT(1) DEFAULT 0");
        echo "[OK] Added column 'has_custody_restriction' to 'parent_student_map'.\n";
    }
    if (!columnExists($pdo, 'parent_student_map', 'custody_notes')) {
        $pdo->exec("ALTER TABLE `parent_student_map` ADD COLUMN `custody_notes` TEXT NULL");
        echo "[OK] Added column 'custody_notes' to 'parent_student_map'.\n";
    }
} catch (\Throwable $e) {
    echo "[ERR] Alter parent_student_map: " . $e->getMessage() . "\n";
}

// 11. ALTER TABLE: audit_logs
try {
    if (!columnExists($pdo, 'audit_logs', 'old_values')) {
        $pdo->exec("ALTER TABLE `audit_logs` ADD COLUMN `old_values` TEXT NULL AFTER `details`");
        echo "[OK] Added column 'old_values' to 'audit_logs'.\n";
    }
    if (!columnExists($pdo, 'audit_logs', 'new_values')) {
        $pdo->exec("ALTER TABLE `audit_logs` ADD COLUMN `new_values` TEXT NULL AFTER `old_values`");
        echo "[OK] Added column 'new_values' to 'audit_logs'.\n";
    }
} catch (\Throwable $e) {
    echo "[ERR] Alter audit_logs: " . $e->getMessage() . "\n";
}

// 12. SEED INITIAL EDUCATIONAL STAGES & DATA
try {
    $stages_count = (int)$pdo->query("SELECT COUNT(*) FROM educational_stages")->fetchColumn();
    if ($stages_count === 0) {
        $pdo->exec("
            INSERT INTO `educational_stages` (`stage_id`, `stage_name`, `min_grade`, `max_grade`, `description`) VALUES
            (1, 'Kindergarten & Early Years', 0, 0, 'Foundational stage focusing on early childhood motor, sensory and language development.'),
            (2, 'Primary / Elementary School', 1, 6, 'Core literacy, mathematics, sciences, and introductory humanities.'),
            (3, 'Lower Secondary / Middle School', 7, 9, 'Intermediate exploratory curricula, pre-IGCSE/pre-high school foundation.'),
            (4, 'Upper Secondary / High School', 10, 12, 'Advanced diploma pathways, university preparation, and national certifications.');
        ");
        echo "[OK] Seeded 4 educational stages.\n";
    }

    // Link existing classes to educational stages based on grade_level
    $pdo->exec("
        UPDATE `classes` SET `stage_id` = CASE
            WHEN `grade_level` <= 0 THEN 1
            WHEN `grade_level` BETWEEN 1 AND 6 THEN 2
            WHEN `grade_level` BETWEEN 7 AND 9 THEN 3
            ELSE 4
        END
        WHERE `stage_id` IS NULL OR `stage_id` = 0;
    ");
    echo "[OK] Synchronized existing classes with educational stages.\n";

    // Set default working days if null in institution_branding
    $branding = $pdo->query("SELECT * FROM institution_branding LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($branding) {
        if (empty($branding['working_days_json'])) {
            $default_days = json_encode(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday']);
            $pdo->prepare("UPDATE institution_branding SET working_days_json = ?, registration_no = COALESCE(registration_no, 'REG-2026-EG-8841'), accreditation_seal = COALESCE(accreditation_seal, 'Cognia / AdvancED International Accredited') WHERE branding_id = ?")
                ->execute([$default_days, $branding['branding_id']]);
            echo "[OK] Updated institution branding defaults.\n";
        }
    }

    // Seed a sample application if table empty
    $app_count = (int)$pdo->query("SELECT COUNT(*) FROM student_applications")->fetchColumn();
    if ($app_count === 0) {
        $pdo->exec("
            INSERT INTO `student_applications` 
            (`application_no`, `first_name`, `last_name`, `dob`, `gender`, `grade_applying_for`, `parent_name`, `parent_phone`, `parent_email`, `placement_test_score`, `status`, `reviewer_notes`) VALUES
            ('APP-2026-0001', 'Omar', 'Khattab', '2015-04-12', 'Male', 5, 'Dr. Ahmed Khattab', '+20 100 123 4567', 'ahmed.khattab@example.com', 88.5, 'Documents Verified', 'Candidate passed English and Mathematics diagnostic screening.');
        ");
        echo "[OK] Seeded sample admissions application.\n";
    }

    // Seed sample behavior record if table empty
    $beh_count = (int)$pdo->query("SELECT COUNT(*) FROM student_behavior_records")->fetchColumn();
    if ($beh_count === 0) {
        $first_student_id = (int)($pdo->query("SELECT student_id FROM students LIMIT 1")->fetchColumn() ?: 1);
        $pdo->prepare("
            INSERT INTO `student_behavior_records` 
            (`student_id`, `record_type`, `category`, `title`, `description`, `points`, `incident_date`, `action_taken`, `status`, `parent_notified`) VALUES
            (?, 'Merit', 'Academic Excellence', 'First Place in Science Olympiad', 'Exemplary project presentation on renewable solar energy models.', 5, CURDATE(), 'Certificate of Distinction & Assembly Honor', 'Resolved', 1),
            (?, 'Infraction', 'Classroom Conduct', 'Unexcused Class Interruption', 'Repeated disruption during mathematics lecture after verbal warning.', -2, CURDATE(), 'Teacher-Student Counseling & Written Reflection', 'Resolved', 1);
        ")->execute([$first_student_id, $first_student_id]);
        echo "[OK] Seeded initial merit and infraction records.\n";
    }

} catch (\Throwable $e) {
    echo "[ERR] Seeding data: " . $e->getMessage() . "\n";
}

echo "Admin Center Database Migration Completed Successfully!\n";
