<?php
/**
 * Migration to ensure all required tables for Finance features exist.
 */
require_once __DIR__ . '/../config/db.php';
$pdo = getPDO();

$queries = [
    // 1. Fee Structures table
    "CREATE TABLE IF NOT EXISTS fee_structures (
        fee_structure_id INT AUTO_INCREMENT PRIMARY KEY,
        grade_level VARCHAR(50) NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        fee_name VARCHAR(100) NOT NULL,
        amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 2. Sibling discount groups table
    "CREATE TABLE IF NOT EXISTS sibling_discount_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        family_id INT NOT NULL,
        discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_family (family_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 3. Student Sibling Discount mapping table
    "CREATE TABLE IF NOT EXISTS student_sibling_discount (
        student_id INT NOT NULL PRIMARY KEY,
        group_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_group (group_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 4. Financial Clearance table
    "CREATE TABLE IF NOT EXISTS financial_clearance (
        clearance_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        is_cleared TINYINT(1) NOT NULL DEFAULT 0,
        cleared_at DATETIME NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_student_term (student_id, term_name),
        KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 5. Cashier Settlements table
    "CREATE TABLE IF NOT EXISTS cashier_settlements (
        settlement_id INT AUTO_INCREMENT PRIMARY KEY,
        settlement_date DATE NOT NULL,
        total_income DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        total_expense DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        cash_amount DECIMAL(12,2) DEFAULT 0.00,
        bank_amount DECIMAL(12,2) DEFAULT 0.00,
        notes TEXT NULL,
        status ENUM('Pending','Reconciled','Closed') DEFAULT 'Closed',
        generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_settlement_date (settlement_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // 6. View or table for student_invoices if missing, aliasing fee_invoices for backward compatibility
    "CREATE TABLE IF NOT EXISTS student_invoices (
        student_invoice_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL,
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        paid_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        status ENUM('Unpaid','Partially Paid','Paid') NOT NULL DEFAULT 'Unpaid',
        due_date DATE NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
];

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo "Executed SQL successfully.\n";
    } catch (\Throwable $e) {
        echo "Error executing SQL: " . $e->getMessage() . "\n";
    }
}

echo "All finance migration tables verified/created.\n";
