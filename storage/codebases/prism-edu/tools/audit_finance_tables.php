<?php
require_once __DIR__ . '/../config/db.php';

echo "=== DATABASE TABLES IN SCHOOL_SYSTEM ===\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    if (strpos($t, 'fee') !== false || strpos($t, 'finan') !== false || strpos($t, 'pay') !== false || strpos($t, 'invoice') !== false || strpos($t, 'bank') !== false || strpos($t, 'expense') !== false) {
        echo "- [MATCH] $t\n";
    }
}

echo "\n=== SCHEMA OF FINANCE-RELATED TABLES ===\n";
$targetTables = ['fee_structures', 'student_invoices', 'fee_invoices', 'fee_payments', 'school_expenses', 'bank_accounts', 'finance_accounts', 'financial_transactions', 'fee_discounts', 'fee_waivers'];

foreach ($targetTables as $t) {
    if (in_array($t, $tables)) {
        echo "\n>>> Table `$t` EXISTS:\n";
        $cols = $pdo->query("DESCRIBE `$t`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "    {$c['Field']} ({$c['Type']})\n";
        }
    } else {
        echo "\n>>> Table `$t` DOES NOT EXIST\n";
    }
}
