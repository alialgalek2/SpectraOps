<?php
// modules/Finance/migrations/create_finance_tables.php
require_once __DIR__ . '/../../../core/init.php';

try {
    // 1. Bank and Cash Accounts Table
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_accounts (
        account_id INT AUTO_INCREMENT PRIMARY KEY,
        account_name VARCHAR(100) NOT NULL, -- e.g., 'Bank Khartoum - Main', 'Cash Safe', 'Faisal Islamic Bank'
        account_number VARCHAR(100) NULL,
        account_type ENUM('Bank', 'Cash', 'Mobile Money') DEFAULT 'Bank',
        current_balance DECIMAL(12,2) DEFAULT 0.00,
        currency VARCHAR(10) DEFAULT 'SDG',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // 2. Ensure fee_invoices has compatible columns (total_amount / amount_due, paid_amount / amount_paid)
    $pdo->exec("CREATE TABLE IF NOT EXISTS fee_invoices (
        invoice_id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        term_name VARCHAR(100) NOT NULL, -- e.g., 'Term 1 2026'
        total_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        paid_amount DECIMAL(10,2) DEFAULT 0.00,
        amount_due DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        amount_paid DECIMAL(10,2) DEFAULT 0.00,
        status ENUM('Unpaid', 'Partially Paid', 'Paid') DEFAULT 'Unpaid',
        due_date DATE NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (student_id),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Ensure total_amount and paid_amount columns exist in fee_invoices if table already existed
    try { $pdo->exec("ALTER TABLE fee_invoices ADD COLUMN total_amount DECIMAL(10,2) DEFAULT 0.00 AFTER term_name"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE fee_invoices ADD COLUMN paid_amount DECIMAL(10,2) DEFAULT 0.00 AFTER total_amount"); } catch (Exception $e) {}
    try { $pdo->exec("UPDATE fee_invoices SET total_amount = amount_due WHERE total_amount = 0 AND amount_due > 0"); } catch (Exception $e) {}
    try { $pdo->exec("UPDATE fee_invoices SET paid_amount = amount_paid WHERE paid_amount = 0 AND amount_paid > 0"); } catch (Exception $e) {}

    // 3. Transactions Ledger Table (Incomes, Expenses, Payroll, Transfers)
    $pdo->exec("CREATE TABLE IF NOT EXISTS financial_transactions (
        transaction_id INT AUTO_INCREMENT PRIMARY KEY,
        account_id INT NOT NULL,
        transaction_type ENUM('Income', 'Expense', 'Payroll') NOT NULL,
        category VARCHAR(100) NOT NULL, -- e.g., 'Tuition Fee', 'Staff Salary', 'Maintenance', 'Utilities'
        amount DECIMAL(10,2) NOT NULL,
        reference_id INT NULL, -- links to invoice_id or employee_id if applicable
        description TEXT NULL,
        transaction_date DATE NOT NULL,
        recorded_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (account_id),
        INDEX (transaction_type),
        INDEX (transaction_date),
        FOREIGN KEY (account_id) REFERENCES finance_accounts(account_id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Seed Initial Accounts if empty
    $accCount = (int)$pdo->query("SELECT COUNT(*) FROM finance_accounts")->fetchColumn();
    if ($accCount === 0) {
        $pdo->exec("INSERT INTO finance_accounts (account_name, account_number, account_type, current_balance, currency) VALUES
            ('Bank of Khartoum - Main Treasury', 'SD29BOK1002938491', 'Bank', 4500000.00, 'SDG'),
            ('Campus Vault / Cash in Hand', 'CASH-VAULT-01', 'Cash', 750000.00, 'SDG'),
            ('Bankak Mobile Money Gateway', '0912345678', 'Mobile Money', 1250000.00, 'SDG'),
            ('Faisal Islamic Bank - Operational', 'SD44FIB8829103948', 'Bank', 2800000.00, 'SDG')
        ");
    }

    echo "Financial Module Tables (`finance_accounts`, `fee_invoices`, `financial_transactions`) created and verified successfully!\n";
} catch (Exception $e) {
    echo "Error creating financial tables: " . $e->getMessage() . "\n";
}
