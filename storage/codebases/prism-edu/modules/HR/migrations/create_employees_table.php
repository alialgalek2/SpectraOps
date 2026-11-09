<?php
// modules/HR/migrations/create_employees_table.php
// Database migration & structure setup for non-portal and portal staff

require_once __DIR__ . '/../../../core/init.php';

try {
    // 1. Employees Master Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        employee_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_code VARCHAR(50) NULL UNIQUE,
        user_id INT NULL DEFAULT NULL,
        full_name VARCHAR(150) NOT NULL,
        national_id VARCHAR(100) NULL,
        category VARCHAR(50) NOT NULL DEFAULT 'Support', -- Teacher, Driver, Cleaner, Security, Administrative, Support
        job_title VARCHAR(100) NULL,
        employment_type VARCHAR(50) DEFAULT 'Full-time', -- Full-time, Part-time, Contract
        phone VARCHAR(50) NULL,
        email VARCHAR(100) NULL,
        address TEXT NULL,
        base_salary DECIMAL(10,2) DEFAULT 0.00,
        payment_method VARCHAR(50) DEFAULT 'Cash', -- Cash, Bank Transfer, Cheque
        bank_name VARCHAR(100) NULL,
        bank_account_no VARCHAR(100) NULL,
        status ENUM('Active', 'Terminated', 'On Leave') DEFAULT 'Active',
        notes TEXT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id),
        INDEX (category),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Employee Documents Archive Table (CVs, Licenses, Insurance, IDs)
    $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
        document_id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        document_title VARCHAR(150) NULL,
        document_type VARCHAR(100) NOT NULL, -- CV, Driver License, Insurance, National ID, Certificate, Medical Clearance
        file_path VARCHAR(255) NOT NULL,
        file_size VARCHAR(50) NULL,
        expiry_date DATE NULL,
        notes VARCHAR(255) NULL,
        upload_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "HR Module Tables (`employees` and `employee_documents`) verified and created successfully!\n";
} catch (Exception $e) {
    echo "Error creating HR tables: " . $e->getMessage() . "\n";
}
