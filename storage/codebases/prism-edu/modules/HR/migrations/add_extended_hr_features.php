<?php
// modules/HR/migrations/add_extended_hr_features.php
// Database migration for Extended HR Capabilities: Departments, Attendance, Evaluations & Salary Advances

require_once __DIR__ . '/../../../core/init.php';

try {
    // 1. Departments Master Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS departments (
        department_id INT AUTO_INCREMENT PRIMARY KEY,
        department_name VARCHAR(150) NOT NULL,
        department_code VARCHAR(50) NULL UNIQUE,
        head_user_id INT NULL DEFAULT NULL,
        budget DECIMAL(12,2) DEFAULT 0.00,
        description TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (head_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Ensure department_id column in employees table
    $colCheck = $pdo->query("SHOW COLUMNS FROM employees LIKE 'department_id'")->fetch();
    if (!$colCheck) {
        $pdo->exec("ALTER TABLE employees ADD COLUMN department_id INT NULL DEFAULT NULL AFTER category, ADD INDEX (department_id)");
    }

    // 3. Staff Daily Attendance & Clock-In/Out Ledger Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_attendance (
        attendance_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        attendance_date DATE NOT NULL,
        status ENUM('Present', 'Late', 'Absent', 'Half-Day', 'On Leave', 'Excused') DEFAULT 'Present',
        check_in_time TIME NULL,
        check_out_time TIME NULL,
        late_minutes INT DEFAULT 0,
        notes VARCHAR(255) NULL,
        marked_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_emp_date (employee_id, attendance_date),
        INDEX (attendance_date),
        INDEX (status),
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 4. Staff Performance Evaluations & KPI Appraisals Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_evaluations (
        evaluation_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        evaluator_id INT NOT NULL,
        evaluation_period VARCHAR(50) NOT NULL,
        teaching_quality TINYINT NOT NULL DEFAULT 5,
        punctuality TINYINT NOT NULL DEFAULT 5,
        classroom_management TINYINT NOT NULL DEFAULT 5,
        teamwork TINYINT NOT NULL DEFAULT 5,
        overall_score DECIMAL(5,2) NOT NULL DEFAULT 100.00,
        recommendation ENUM('Promotion', 'Salary Increment', 'Retain', 'Needs Training', 'Probation Extension', 'Warning') DEFAULT 'Retain',
        comments TEXT NULL,
        evaluation_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (employee_id),
        INDEX (evaluator_id),
        INDEX (evaluation_period),
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 5. Staff Salary Advances & Emergency Loans Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS staff_advances (
        advance_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        repayment_months INT NOT NULL DEFAULT 1,
        monthly_deduction DECIMAL(10,2) NOT NULL,
        remaining_balance DECIMAL(10,2) NOT NULL,
        purpose VARCHAR(255) NOT NULL,
        status ENUM('Pending', 'Approved', 'Rejected', 'Repaid') DEFAULT 'Pending',
        approved_by INT NULL,
        request_date DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (employee_id),
        INDEX (status),
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed default departments if table is empty
    $deptCount = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    if ($deptCount === 0) {
        $defaultDepts = [
            ['Mathematics & Computing', 'MATH', 'Curriculum for Math, Computer Science, and Robotics.'],
            ['Natural Sciences', 'SCI', 'Physics, Chemistry, Biology, and Laboratory practicals.'],
            ['Languages & Literature', 'LANG', 'English, Arabic, French, and Reading initiatives.'],
            ['Humanities & Social Studies', 'HUM', 'History, Geography, Civics, and Social Sciences.'],
            ['School Administration & Operations', 'ADMIN', 'Registrar, Admission, Records, and Office Management.'],
            ['Facilities & Transportation', 'FAC', 'School buses, Fleet safety, Grounds, and Security maintenance.']
        ];
        $stmtDept = $pdo->prepare("INSERT INTO departments (department_name, department_code, description) VALUES (?, ?, ?)");
        foreach ($defaultDepts as $d) {
            $stmtDept->execute($d);
        }
        echo "Default departments seeded successfully.\n";
    }

    echo "Extended HR Tables (`departments`, `staff_attendance`, `staff_evaluations`, `staff_advances`) migrated successfully!\n";
} catch (Exception $e) {
    echo "Error migrating extended HR tables: " . $e->getMessage() . "\n";
    exit(1);
}
