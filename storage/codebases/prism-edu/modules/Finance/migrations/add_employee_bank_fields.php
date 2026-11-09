<?php
// modules/Finance/migrations/add_employee_bank_fields.php
require_once __DIR__ . '/../../../core/init.php';

try {
    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN bank_name VARCHAR(100) NULL AFTER base_salary");
    } catch (Exception $e) {}

    try {
        $pdo->exec("ALTER TABLE employees ADD COLUMN bank_account_number VARCHAR(100) NULL AFTER bank_name");
    } catch (Exception $e) {}

    // Sync bank_account_no and bank_account_number if both exist
    try {
        $pdo->exec("UPDATE employees SET bank_account_number = bank_account_no WHERE (bank_account_number IS NULL OR bank_account_number = '') AND bank_account_no IS NOT NULL");
    } catch (Exception $e) {}

    echo "Employee bank payout fields added & verified successfully!\n";
} catch (Exception $e) {
    echo "Error updating table: " . $e->getMessage() . "\n";
}
