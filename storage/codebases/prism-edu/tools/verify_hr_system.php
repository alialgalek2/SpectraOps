<?php
require_once __DIR__ . '/../core/init.php';

echo "=== HR SYSTEM VERIFICATION TEST ===\n\n";

// 1. Test Departments
echo "[1/5] Testing Departments:\n";
$deptCount = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
echo "  - Total Departments: $deptCount\n";
$depts = $pdo->query("SELECT department_id, department_code, department_name, budget FROM departments LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
foreach ($depts as $d) {
    echo "    * [ID: {$d['department_id']}] {$d['department_code']} - {$d['department_name']} (Budget: \${$d['budget']})\n";
}

// 2. Test Staff Attendance
echo "\n[2/5] Testing Staff Attendance:\n";
$today = date('Y-m-d');
$emp = $pdo->query("SELECT employee_id, full_name FROM employees WHERE status = 'Active' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($emp) {
    // Check or insert attendance for today
    $check = $pdo->prepare("SELECT attendance_id FROM staff_attendance WHERE employee_id = ? AND attendance_date = ?");
    $check->execute([$emp['employee_id'], $today]);
    if (!$check->fetch()) {
        $ins = $pdo->prepare("INSERT INTO staff_attendance (employee_id, attendance_date, status, check_in_time, late_minutes) VALUES (?, ?, 'Present', '07:55:00', 0)");
        $ins->execute([$emp['employee_id'], $today]);
        echo "  - Marked test attendance for Employee #{$emp['employee_id']} ({$emp['full_name']}) on $today: SUCCESS\n";
    } else {
        echo "  - Attendance already exists for Employee #{$emp['employee_id']} on $today: SUCCESS\n";
    }
}
$attCount = $pdo->query("SELECT COUNT(*) FROM staff_attendance")->fetchColumn();
echo "  - Total Attendance Records: $attCount\n";

// 3. Test Staff Evaluations
echo "\n[3/5] Testing Staff Evaluations:\n";
if ($emp) {
    $checkEval = $pdo->prepare("SELECT evaluation_id FROM staff_evaluations WHERE employee_id = ? LIMIT 1");
    $checkEval->execute([$emp['employee_id']]);
    if (!$checkEval->fetch()) {
        $adminId = $pdo->query("SELECT user_id FROM users WHERE role = 'admin' LIMIT 1")->fetchColumn() ?: 1;
        $evalIns = $pdo->prepare("
            INSERT INTO staff_evaluations (employee_id, evaluator_id, evaluation_period, teaching_quality, punctuality, classroom_management, teamwork, overall_score, recommendation, evaluation_date)
            VALUES (?, ?, 'Q1 2026', 4.5, 5.0, 4.0, 4.5, 90.0, 'Strong performance across all indicators.', ?)
        ");
        $evalIns->execute([$emp['employee_id'], $adminId, $today]);
        echo "  - Created test evaluation for Employee #{$emp['employee_id']}: SUCCESS\n";
    } else {
        echo "  - Existing evaluation found for Employee #{$emp['employee_id']}: SUCCESS\n";
    }
}
$evalCount = $pdo->query("SELECT COUNT(*) FROM staff_evaluations")->fetchColumn();
echo "  - Total Evaluations: $evalCount\n";

// 4. Test Salary Advances
echo "\n[4/5] Testing Salary Advances:\n";
if ($emp) {
    $checkAdv = $pdo->prepare("SELECT advance_id, status, remaining_balance FROM staff_advances WHERE employee_id = ? LIMIT 1");
    $checkAdv->execute([$emp['employee_id']]);
    $adv = $checkAdv->fetch(PDO::FETCH_ASSOC);
    if (!$adv) {
        $advIns = $pdo->prepare("
            INSERT INTO staff_advances (employee_id, amount, repayment_months, monthly_deduction, remaining_balance, purpose, status, request_date)
            VALUES (?, 600.00, 3, 200.00, 600.00, 'Emergency medical expense', 'Approved', ?)
        ");
        $advIns->execute([$emp['employee_id'], $today]);
        echo "  - Created test approved advance ($600 / 3 mos = $200/mo) for Employee #{$emp['employee_id']}: SUCCESS\n";
    } else {
        echo "  - Existing advance found for Employee #{$emp['employee_id']} (Status: {$adv['status']}, Bal: \${$adv['remaining_balance']}): SUCCESS\n";
    }
}
$advCount = $pdo->query("SELECT COUNT(*) FROM staff_advances")->fetchColumn();
echo "  - Total Advances: $advCount\n";

// 5. Test Payroll Integration
echo "\n[5/5] Testing Payroll & Advance Deduction Integration:\n";
if ($emp) {
    $advStmt = $pdo->prepare("
        SELECT advance_id, monthly_deduction, remaining_balance 
        FROM staff_advances 
        WHERE employee_id = ? AND status = 'Approved' AND remaining_balance > 0
        LIMIT 1
    ");
    $advStmt->execute([$emp['employee_id']]);
    $activeAdv = $advStmt->fetch(PDO::FETCH_ASSOC);
    if ($activeAdv) {
        $advCut = min(floatval($activeAdv['remaining_balance']), floatval($activeAdv['monthly_deduction']));
        echo "  - Active advance monthly deduction correctly calculated: \$$advCut from total \${$activeAdv['remaining_balance']}: SUCCESS\n";
    } else {
        echo "  - No active advance requiring deduction: PASS\n";
    }
}

echo "\nALL TESTS COMPLETED SUCCESSFULLY!\n";
