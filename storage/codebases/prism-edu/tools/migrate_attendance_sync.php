<?php
require 'core/init.php';

$pdo->exec("
CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `class_id` INT NOT NULL,
    `status` VARCHAR(50) NOT NULL DEFAULT 'Present',
    `attendance_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_student_class_date` (`student_id`, `class_id`, `attendance_date`),
    INDEX `idx_updated_at` (`updated_at`),
    INDEX `idx_student_id` (`student_id`),
    INDEX `idx_class_id` (`class_id`),
    INDEX `idx_attendance_date` (`attendance_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

$pdo->exec("
INSERT IGNORE INTO `attendance` (`student_id`, `class_id`, `status`, `attendance_date`, `created_at`, `updated_at`)
SELECT `student_id`, `class_id`, `status`, `date`, `created_at`, `created_at`
FROM `student_attendance`;
");

echo "Attendance table ensured successfully. Rows: " . $pdo->query("SELECT COUNT(*) FROM attendance")->fetchColumn() . "\n";
