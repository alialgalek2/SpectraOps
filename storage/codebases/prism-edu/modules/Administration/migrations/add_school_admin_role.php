<?php
// modules/Administration/migrations/add_school_admin_role.php
require_once __DIR__ . '/../../../core/init.php';

try {
    // 1. Modify users enum to include school_admin
    $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','school_admin','teacher','student','parent','hr','finance') DEFAULT 'student'");
    echo "[+] Users table updated with 'school_admin' enum.\n";

    // 2. Insert or update in roles table
    $chk = $pdo->query("SELECT role_id FROM roles WHERE role_name IN ('school_admin', 'School Administrator')")->fetch();
    if (!$chk) {
        $stmt = $pdo->prepare("
            INSERT INTO roles (role_id, role_name, description) 
            VALUES (7, 'School Administrator', 'Access to Admissions, HR oversight, Timetable management, and Exams, but excluded from system core settings and bank ledger configurations')
        ");
        $stmt->execute();
        $roleId = 7;
        echo "[+] Inserted 'School Administrator' into roles table (ID: $roleId).\n";
    } else {
        $roleId = $chk['role_id'];
        echo "[+] 'School Administrator' role already exists (ID: $roleId).\n";
    }

    // 3. Create or update a demo school_admin user
    $username = 'school_admin';
    $password = 'admin123';
    $full_name = 'Dr. Tariq Mansour (School Director / Vice Principal)';
    $email = 'director@school.internal';
    $phone = '+249 912 998 877';

    $chkUser = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
    $chkUser->execute([$username]);
    $existing = $chkUser->fetch();

    $hashed = password_hash($password, PASSWORD_BCRYPT);

    if ($existing) {
        $userId = $existing['user_id'];
        $upd = $pdo->prepare("UPDATE users SET password = ?, full_name = ?, role = 'school_admin', role_id = ?, status = 'Active' WHERE user_id = ?");
        $upd->execute([$hashed, $full_name, $roleId, $userId]);
        echo "[+] Updated school_admin user (ID: $userId).\n";
    } else {
        $ins = $pdo->prepare("
            INSERT INTO users (username, password, full_name, email, phone, role, role_id, status, must_change_password, is_deleted)
            VALUES (?, ?, ?, ?, ?, 'school_admin', ?, 'Active', 0, 0)
        ");
        $ins->execute([$username, $hashed, $full_name, $email, $phone, $roleId]);
        $userId = $pdo->lastInsertId();
        echo "[+] Created school_admin user (ID: $userId).\n";
    }

    // 4. Link with employees record
    $chkEmp = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
    $chkEmp->execute([$userId]);
    if (!$chkEmp->fetch()) {
        $insEmp = $pdo->prepare("
            INSERT INTO employees (
                employee_code, user_id, full_name, national_id, phone, email, address,
                category, job_title, employment_type, hire_date, base_salary, payment_method,
                bank_name, bank_account_number, bank_account_no, notes, status
            ) VALUES (
                'EMP-105', ?, ?, 'NID-99218374', ?, ?, 'Al-Manshiya, Khartoum',
                'Administrative', 'School Administrator & Academic Director', 'Full-time', '2023-09-01',
                2800.00, 'Bank Transfer', 'Bank of Khartoum', 'SD99BOK771928301', 'SD99BOK771928301',
                'Academic Department Director and Admissions Supervisor', 'Active'
            )
        ");
        $insEmp->execute([$userId, $full_name, $phone, $email]);
        echo "[+] Linked employee profile EMP-105 created.\n";
    }

    echo "=== MIGRATION COMPLETE ===\n";

} catch (Exception $e) {
    echo "[-] Error: " . $e->getMessage() . "\n";
}
