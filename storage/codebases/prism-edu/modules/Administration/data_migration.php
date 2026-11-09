<?php
require_once __DIR__ . '/../../core/init.php';
// Strict Security check: Must be logged in AND role must be admin
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

if (($_SESSION['role'] ?? '') !== 'admin') {
    $_SESSION['flash_msg'] = is_rtl() ? "تم رفض الوصول: مركز ترحيل البيانات مخصص لمدير النظام فقط." : "Access Denied: The Data Migration Hub is restricted to Administrators only.";
    $_SESSION['flash_msg_type'] = "danger";
    header("Location: ../../dashboard.php");
    exit();
}

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "success";
$report = $_SESSION['migration_report'] ?? null;
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type'], $_SESSION['migration_report']);

// Handle Full Database Backup Export (.SQL)
if (isset($_GET['action']) && $_GET['action'] === 'export_backup') {
    try {
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sqlDump = "-- ========================================================\n";
        $sqlDump .= "-- Algale School Management System - Full Database Backup\n";
        $sqlDump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n";
        $sqlDump .= "-- ========================================================\n\n";
        $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        foreach ($tables as $t) {
            $createTable = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
            $sqlDump .= "DROP TABLE IF EXISTS `$t`;\n";
            $sqlDump .= $createTable['Create Table'] . ";\n\n";

            $rows = $pdo->query("SELECT * FROM `$t`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = array_keys($rows[0]);
                $colList = implode("`, `", $cols);
                
                foreach ($rows as $r) {
                    $vals = array_map(function($v) use ($pdo) {
                        if ($v === null) return 'NULL';
                        return $pdo->quote($v);
                    }, array_values($r));
                    $valList = implode(", ", $vals);
                    $sqlDump .= "INSERT INTO `$t` (`$colList`) VALUES ($valList);\n";
                }
                $sqlDump .= "\n";
            }
        }
        $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="school_db_backup_' . date('Ymd_His') . '.sql"');
        header('Pragma: no-cache');
        echo $sqlDump;
        exit();
    } catch (Exception $e) {
        $_SESSION['flash_msg'] = "Backup generation failed: " . $e->getMessage();
        $_SESSION['flash_msg_type'] = "danger";
        header("Location: data_migration.php");
        exit();
    }
}

// Handle Migration Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_migration') {
    $migration_type     = $_POST['migration_type'] ?? 'students';
    $conflict_mode      = $_POST['conflict_mode'] ?? 'skip'; // skip, update, abort
    $auto_create_entity = isset($_POST['auto_create_entity']);

    if (!isset($_FILES['data_file']) || $_FILES['data_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_msg'] = is_rtl() ? "يرجى اختيار ملف صالح للرفع." : "Please select a valid file to upload.";
        $_SESSION['flash_msg_type'] = "danger";
        header("Location: data_migration.php");
        exit();
    }

    $filePath = $_FILES['data_file']['tmp_name'];
    $fileName = $_FILES['data_file']['name'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $rep = [
        'type'     => $migration_type,
        'total'    => 0,
        'inserted' => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'errors'   => []
    ];

    try {
        $pdo->beginTransaction();

        // 1. Direct SQL File Importer
        if ($ext === 'sql' || $migration_type === 'sql') {
            $sqlContent = file_get_contents($filePath);
            $queries = array_filter(array_map('trim', explode(";\n", $sqlContent)));
            
            foreach ($queries as $q) {
                if (!empty($q) && substr($q, 0, 2) !== '--') {
                    $pdo->exec($q);
                    $rep['inserted']++;
                }
            }
            $rep['total'] = $rep['inserted'];
        } 
        else {
            // 2. CSV / JSON Importers
            $records = [];

            if ($ext === 'json') {
                $jsonStr = file_get_contents($filePath);
                $records = json_decode($jsonStr, true) ?? [];
            } else {
                // Parse CSV with UTF-8 BOM removal and auto delimiter check
                $handle = fopen($filePath, "r");
                if ($handle) {
                    $firstLine = fgets($handle);
                    // Remove BOM
                    $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
                    $delimiter = (strpos($firstLine, ';') !== false) ? ';' : ((strpos($firstLine, "\t") !== false) ? "\t" : ',');
                    
                    $headers = str_getcsv(trim($firstLine), $delimiter);
                    $headers = array_map(function($h) { return trim(strtolower(trim($h)), "\"' \t\n\r\0\x0B"); }, $headers);

                    while (($row = fgetcsv($handle, 4096, $delimiter)) !== false) {
                        if (count($row) === 1 && empty($row[0])) continue;
                        $record = [];
                        foreach ($headers as $idx => $headerName) {
                            $record[$headerName] = trim($row[$idx] ?? '');
                        }
                        $records[] = $record;
                    }
                    fclose($handle);
                }
            }

            $rep['total'] = count($records);

            // PROCESS RECORDS BASED ON MIGRATION TYPE
            foreach ($records as $rowIndex => $r) {
                $rowNum = $rowIndex + 2; // accounts for header

                try {
                    // MODULE A: STUDENTS
                    if ($migration_type === 'students') {
                        $adm_no   = trim($r['admission_no'] ?? $r['admission_number'] ?? $r['student_id'] ?? '');
                        $fullname = trim($r['full_name'] ?? $r['name'] ?? $r['student_name'] ?? '');
                        $gender   = ucfirst(strtolower(trim($r['gender'] ?? 'Male')));
                        if (!in_array($gender, ['Male', 'Female'])) $gender = 'Male';
                        $dob      = !empty($r['dob']) ? date('Y-m-d', strtotime($r['dob'])) : null;
                        $className = trim($r['class_name'] ?? $r['class'] ?? $r['grade'] ?? 'General');
                        $phone    = trim($r['phone'] ?? $r['phone_number'] ?? '');
                        $email    = trim($r['email'] ?? '');
                        $parentName = trim($r['parent_name'] ?? $r['guardian_name'] ?? '');
                        $parentPhone = trim($r['parent_phone'] ?? '');

                        if (empty($adm_no) || empty($fullname)) {
                            throw new Exception("Row #$rowNum: Admission No and Full Name are mandatory.");
                        }

                        // Auto-create or lookup Class
                        $classId = 1;
                        if (!empty($className)) {
                            $stmtCls = $pdo->prepare("SELECT class_id FROM classes WHERE class_name = ? LIMIT 1");
                            $stmtCls->execute([$className]);
                            $cRow = $stmtCls->fetch();
                            if ($cRow) {
                                $classId = $cRow['class_id'];
                            } elseif ($auto_create_entity) {
                                $insCls = $pdo->prepare("INSERT INTO classes (class_name) VALUES (?)");
                                $insCls->execute([$className]);
                                $classId = $pdo->lastInsertId();
                            }
                        }

                        // Check existing student by admission_no
                        $checkStu = $pdo->prepare("SELECT s.student_id, s.user_id, u.username FROM students s JOIN users u ON s.user_id = u.user_id WHERE s.admission_no = ? LIMIT 1");
                        $checkStu->execute([$adm_no]);
                        $existingStudent = $checkStu->fetch();

                        if ($existingStudent) {
                            if ($conflict_mode === 'skip') {
                                $rep['skipped']++;
                                continue;
                            } elseif ($conflict_mode === 'abort') {
                                throw new Exception("Row #$rowNum: Duplicate student admission no '$adm_no' detected.");
                            } elseif ($conflict_mode === 'update') {
                                $uStmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
                                $uStmt->execute([$fullname, $phone, $existingStudent['user_id']]);
                                
                                $sStmt = $pdo->prepare("UPDATE students SET class_id = ?, gender = ?, dob = ? WHERE student_id = ?");
                                $sStmt->execute([$classId, $gender, $dob, $existingStudent['student_id']]);
                                $rep['updated']++;
                                continue;
                            }
                        }

                        // Create new Student User Account
                        $username = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $adm_no));
                        if (empty($username)) $username = 'std_' . time() . '_' . rand(100, 999);
                        
                        // Ensure unique username
                        $chkU = $pdo->prepare("SELECT user_id FROM users WHERE username = ? LIMIT 1");
                        $chkU->execute([$username]);
                        if ($chkU->fetch()) {
                            $username .= '_' . rand(10, 99);
                        }

                        $userEmail = !empty($email) ? $email : "$username@school.local";
                        $chkE = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                        $chkE->execute([$userEmail]);
                        if ($chkE->fetch()) {
                            $userEmail = $username . rand(10,99) . "@school.local";
                        }

                        $hashedPass = password_hash("Student123!", PASSWORD_DEFAULT);
                        $insUser = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, role_id, phone, admission_no, status) VALUES (?, ?, ?, ?, 'student', 4, ?, ?, 'Active')");
                        $insUser->execute([$username, $hashedPass, $fullname, $userEmail, $phone, $adm_no]);
                        $newUserId = $pdo->lastInsertId();

                        // Insert into Students
                        $qrHash = md5($adm_no . $fullname . time());
                        $insStu = $pdo->prepare("INSERT INTO students (user_id, admission_no, class_id, gender, dob, qr_code_hash, enrollment_status) VALUES (?, ?, ?, ?, ?, ?, 'Active')");
                        $insStu->execute([$newUserId, $adm_no, $classId, $gender, $dob, $qrHash]);
                        $newStudentId = $pdo->lastInsertId();

                        // Handle Parent creation & mapping if provided
                        if (!empty($parentName)) {
                            $pUsername = 'p_' . $username;
                            $pEmail = !empty($r['parent_email']) ? $r['parent_email'] : "$pUsername@school.local";
                            
                            $chkPE = $pdo->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
                            $chkPE->execute([$pEmail]);
                            if ($chkPE->fetch()) $pEmail = $pUsername . rand(10,99) . "@school.local";

                            $insPUser = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, role_id, phone, status) VALUES (?, ?, ?, ?, 'parent', 5, ?, 'Active')");
                            $insPUser->execute([$pUsername, $hashedPass, $parentName, $pEmail, $parentPhone]);
                            $pUserId = $pdo->lastInsertId();

                            $insParent = $pdo->prepare("INSERT INTO parents (user_id) VALUES (?)");
                            $insParent->execute([$pUserId]);
                            $pId = $pdo->lastInsertId();

                            $insMap = $pdo->prepare("INSERT INTO parent_student_map (parent_id, student_id, relationship) VALUES (?, ?, 'Guardian')");
                            $insMap->execute([$pId, $newStudentId]);
                        }

                        $rep['inserted']++;
                    }

                    // MODULE B: TEACHERS & ALLOCATIONS
                    elseif ($migration_type === 'teachers') {
                        $fullname = trim($r['full_name'] ?? $r['name'] ?? '');
                        $username = trim($r['username'] ?? '');
                        $email    = trim($r['email'] ?? '');
                        $phone    = trim($r['phone'] ?? '');
                        $classesAssigned = trim($r['assigned_classes'] ?? $r['classes'] ?? '');
                        $subjectsAssigned = trim($r['assigned_subjects'] ?? $r['subjects'] ?? '');

                        if (empty($fullname)) {
                            throw new Exception("Row #$rowNum: Teacher Full Name is mandatory.");
                        }

                        if (empty($username)) {
                            $parts = explode(' ', strtolower(preg_replace('/[^a-zA-Z0-9 ]/', '', $fullname)));
                            $username = (count($parts) > 1) ? $parts[0] . '.' . end($parts) : $parts[0] . rand(10, 99);
                        }

                        if (empty($email)) {
                            $email = "$username@school.edu";
                        }

                        $chkTeach = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ? LIMIT 1");
                        $chkTeach->execute([$username, $email]);
                        $existingTeach = $chkTeach->fetch();

                        $teacherUserId = 0;
                        if ($existingTeach) {
                            if ($conflict_mode === 'skip') {
                                $rep['skipped']++;
                                continue;
                            } elseif ($conflict_mode === 'abort') {
                                throw new Exception("Row #$rowNum: Duplicate teacher account '$username' detected.");
                            } else {
                                $teacherUserId = $existingTeach['user_id'];
                                $uStmt = $pdo->prepare("UPDATE users SET full_name = ?, phone = ? WHERE user_id = ?");
                                $uStmt->execute([$fullname, $phone, $teacherUserId]);
                                $rep['updated']++;
                            }
                        } else {
                            $hashedPass = password_hash("Teacher123!", PASSWORD_DEFAULT);
                            $insUser = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role, role_id, phone, status) VALUES (?, ?, ?, ?, 'teacher', 2, ?, 'Active')");
                            $insUser->execute([$username, $hashedPass, $fullname, $email, $phone]);
                            $teacherUserId = $pdo->lastInsertId();
                            $rep['inserted']++;
                        }

                        // Process assigned classes & subjects
                        if (!empty($classesAssigned) && !empty($subjectsAssigned)) {
                            $cList = array_filter(array_map('trim', explode(',', $classesAssigned)));
                            $sList = array_filter(array_map('trim', explode(',', $subjectsAssigned)));

                            foreach ($cList as $cName) {
                                // Lookup/Create class
                                $stmtC = $pdo->prepare("SELECT class_id FROM classes WHERE class_name = ? LIMIT 1");
                                $stmtC->execute([$cName]);
                                $cIdRow = $stmtC->fetch();
                                $cId = 0;
                                if ($cIdRow) {
                                    $cId = $cIdRow['class_id'];
                                } elseif ($auto_create_entity) {
                                    $insC = $pdo->prepare("INSERT INTO classes (class_name) VALUES (?)");
                                    $insC->execute([$cName]);
                                    $cId = $pdo->lastInsertId();
                                }

                                foreach ($sList as $sName) {
                                    // Lookup/Create subject
                                    $stmtS = $pdo->prepare("SELECT subject_id FROM subjects WHERE subject_name = ? OR subject_code = ? LIMIT 1");
                                    $stmtS->execute([$sName, $sName]);
                                    $sIdRow = $stmtS->fetch();
                                    $sId = 0;
                                    if ($sIdRow) {
                                        $sId = $sIdRow['subject_id'];
                                    } elseif ($auto_create_entity) {
                                        $sCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $sName), 0, 4)) . rand(10,99);
                                        $insS = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code) VALUES (?, ?)");
                                        $insS->execute([$sName, $sCode]);
                                        $sId = $pdo->lastInsertId();
                                    }

                                    if ($cId > 0 && $sId > 0) {
                                        $chkAlloc = $pdo->prepare("SELECT allocation_id FROM teacher_allocations WHERE teacher_user_id = ? AND class_id = ? AND subject_id = ?");
                                        $chkAlloc->execute([$teacherUserId, $cId, $sId]);
                                        if (!$chkAlloc->fetch()) {
                                            $insAlloc = $pdo->prepare("INSERT INTO teacher_allocations (teacher_user_id, class_id, subject_id) VALUES (?, ?, ?)");
                                            $insAlloc->execute([$teacherUserId, $cId, $sId]);
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // MODULE C: CLASSES
                    elseif ($migration_type === 'classes') {
                        $cName   = trim($r['class_name'] ?? $r['grade'] ?? '');
                        $section = trim($r['section'] ?? '');
                        $fullName = !empty($section) ? "$cName ($section)" : $cName;

                        if (empty($fullName)) {
                            throw new Exception("Row #$rowNum: Class name cannot be empty.");
                        }

                        $chk = $pdo->prepare("SELECT class_id FROM classes WHERE class_name = ? LIMIT 1");
                        $chk->execute([$fullName]);
                        if ($chk->fetch()) {
                            $rep['skipped']++;
                        } else {
                            $ins = $pdo->prepare("INSERT INTO classes (class_name) VALUES (?)");
                            $ins->execute([$fullName]);
                            $rep['inserted']++;
                        }
                    }

                    // MODULE D: SUBJECTS
                    elseif ($migration_type === 'subjects') {
                        $sName = trim($r['subject_name'] ?? $r['name'] ?? '');
                        $sCode = trim($r['subject_code'] ?? $r['code'] ?? '');

                        if (empty($sName)) {
                            throw new Exception("Row #$rowNum: Subject name cannot be empty.");
                        }
                        if (empty($sCode)) {
                            $sCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $sName), 0, 4)) . rand(10,99);
                        }

                        $chk = $pdo->prepare("SELECT subject_id FROM subjects WHERE subject_code = ? OR subject_name = ? LIMIT 1");
                        $chk->execute([$sCode, $sName]);
                        if ($chk->fetch()) {
                            if ($conflict_mode === 'update') {
                                $u = $pdo->prepare("UPDATE subjects SET subject_name = ? WHERE subject_code = ?");
                                $u->execute([$sName, $sCode]);
                                $rep['updated']++;
                            } else {
                                $rep['skipped']++;
                            }
                        } else {
                            $ins = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code) VALUES (?, ?)");
                            $ins->execute([$sName, $sCode]);
                            $rep['inserted']++;
                        }
                    }

                    // MODULE E: FEE INVOICES & BALANCES
                    elseif ($migration_type === 'fees') {
                        $adm_no     = trim($r['admission_no'] ?? '');
                        $inv_no     = trim($r['invoice_number'] ?? $r['invoice_no'] ?? '');
                        $term       = trim($r['term'] ?? 'Term 1');
                        $year       = trim($r['academic_year'] ?? date('Y'));
                        $amount     = floatval($r['total_amount'] ?? $r['amount'] ?? 0);
                        $status     = ucfirst(strtolower(trim($r['status'] ?? 'Unpaid')));
                        if (!in_array($status, ['Unpaid', 'Partial', 'Paid'])) $status = 'Unpaid';
                        $dueDate    = !empty($r['due_date']) ? date('Y-m-d', strtotime($r['due_date'])) : date('Y-m-d', strtotime('+30 days'));

                        if (empty($adm_no) || $amount <= 0) {
                            throw new Exception("Row #$rowNum: Admission No and positive Amount are required.");
                        }

                        $sStmt = $pdo->prepare("SELECT student_id FROM students WHERE admission_no = ? LIMIT 1");
                        $sStmt->execute([$adm_no]);
                        $sRow = $sStmt->fetch();
                        if (!$sRow) {
                            throw new Exception("Row #$rowNum: Student with admission no '$adm_no' not found.");
                        }
                        $stuId = $sRow['student_id'];

                        if (empty($inv_no)) {
                            $inv_no = 'INV-' . $year . '-' . rand(1000, 9999);
                        }

                        $chkInv = $pdo->prepare("SELECT invoice_id FROM invoices WHERE invoice_number = ? LIMIT 1");
                        $chkInv->execute([$inv_no]);
                        if ($chkInv->fetch()) {
                            if ($conflict_mode === 'update') {
                                $u = $pdo->prepare("UPDATE invoices SET total_amount = ?, status = ?, due_date = ? WHERE invoice_number = ?");
                                $u->execute([$amount, $status, $dueDate, $inv_no]);
                                $rep['updated']++;
                            } else {
                                $rep['skipped']++;
                            }
                        } else {
                            $ins = $pdo->prepare("INSERT INTO invoices (invoice_number, student_id, term, academic_year, subtotal, total_amount, status, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                            $ins->execute([$inv_no, $stuId, $term, $year, $amount, $amount, $status, $dueDate]);
                            $rep['inserted']++;
                        }
                    }

                } catch (Exception $rowEx) {
                    $rep['errors'][] = $rowEx->getMessage();
                    if ($conflict_mode === 'abort') {
                        throw $rowEx;
                    }
                }
            }
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = is_rtl() ? "اكتملت عملية الترحيل واستيراد البيانات بنجاح!" : "Data migration executed successfully!";
        $_SESSION['flash_msg_type'] = "success";
        $_SESSION['migration_report'] = $rep;
        header("Location: data_migration.php");
        exit();

    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['flash_msg'] = "Migration Error: " . $e->getMessage();
        $_SESSION['flash_msg_type'] = "danger";
        $_SESSION['migration_report'] = $rep;
        header("Location: data_migration.php");
        exit();
    }
}

// Fetch current counts
$cur_students = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0 OR is_deleted IS NULL")->fetchColumn();
$cur_teachers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
$cur_classes  = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
$cur_subjects = (int)$pdo->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('data_migration') ?> - School Management System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => __('data_migration'),
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="student_admission.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-user-graduate me-1 text-primary"></i> ' . __('student_admission') . '</a>'
    ]
]) ?>

<div class="container-fluid py-4 px-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="badge rounded-full font-medium px-3 py-1 mb-2 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                    <i class="fa-solid fa-cloud-arrow-up text-emerald-300 me-1"></i> Automated Data Migration Engine
                </span>
                <h2 class="fw-bold mb-1 text-white"><i class="fa-solid fa-cloud-arrow-up me-2"></i><?= __('data_migration') ?></h2>
                <p class="lead mb-0 text-white opacity-90 fs-6"><?= is_rtl() ? 'استيراد وترحيل السجلات الأكاديمية والطلاب والمعلمين من الأنظمة القديمة وجداول Excel دون فقدان أي بيانات.' : 'Effortlessly import legacy student rosters, teachers, classes, grades, and invoices from CSV/Excel or SQL.' ?></p>
            </div>
            <div>
                <a href="data_migration.php?action=export_backup" class="btn btn-warning fw-bold text-dark px-4 py-2 rounded-lg shadow-sm">
                    <i class="fa-solid fa-download me-1"></i> <?= __('backup_system') ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Live System Counts Stat Cards (Dashboard Theme) -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Students -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= __('total_students') ?>
                    </div>
                    <div class="action-icon-box icon-box-primary" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                </div>
                <div class="stat-value my-1"><?= number_format($cur_students) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend text-primary">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= is_rtl() ? 'سجلات الطلاب المسجلة' : 'Active Student Roster' ?></span>
                    </div>
                    <div class="sparkline-container">
                        <span class="sparkline-bar" style="height: 40%;"></span>
                        <span class="sparkline-bar" style="height: 60%;"></span>
                        <span class="sparkline-bar active" style="height: 50%;"></span>
                        <span class="sparkline-bar active" style="height: 85%;"></span>
                        <span class="sparkline-bar active" style="height: 70%;"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Teaching Faculty -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= __('teaching_staff') ?>
                    </div>
                    <div class="action-icon-box icon-box-success" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-chalkboard-user"></i>
                    </div>
                </div>
                <div class="stat-value my-1"><?= number_format($cur_teachers) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend text-tertiary">
                        <i class="fa-solid fa-arrow-trend-up"></i>
                        <span><?= is_rtl() ? 'الكادر التعليمي النشط' : 'Active Teaching Faculty' ?></span>
                    </div>
                    <div class="sparkline-container">
                        <span class="sparkline-bar tertiary" style="height: 35%;"></span>
                        <span class="sparkline-bar tertiary" style="height: 55%;"></span>
                        <span class="sparkline-bar tertiary" style="height: 70%;"></span>
                        <span class="sparkline-bar tertiary" style="height: 90%;"></span>
                        <span class="sparkline-bar tertiary" style="height: 80%;"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Classes & Sections -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= __('classes_and_sections') ?>
                    </div>
                    <div class="action-icon-box icon-box-info" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-school"></i>
                    </div>
                </div>
                <div class="stat-value my-1"><?= number_format($cur_classes) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend text-info">
                        <i class="fa-solid fa-building-columns"></i>
                        <span><?= is_rtl() ? 'الفصول والشعب المعتمدة' : 'Academic Classrooms' ?></span>
                    </div>
                    <div class="sparkline-container">
                        <span class="sparkline-bar active" style="height: 50%;"></span>
                        <span class="sparkline-bar active" style="height: 70%;"></span>
                        <span class="sparkline-bar active" style="height: 60%;"></span>
                        <span class="sparkline-bar active" style="height: 80%;"></span>
                        <span class="sparkline-bar active" style="height: 95%;"></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Curriculum Subjects -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= __('curriculum_subjects') ?>
                    </div>
                    <div class="action-icon-box icon-box-purple" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-book-open"></i>
                    </div>
                </div>
                <div class="stat-value my-1"><?= number_format($cur_subjects) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend" style="color: #8b5cf6;">
                        <i class="fa-solid fa-layer-group"></i>
                        <span><?= is_rtl() ? 'المقررات الدراسية' : 'Catalog Course Offerings' ?></span>
                    </div>
                    <div class="sparkline-container">
                        <span class="sparkline-bar" style="height: 45%; background-color: rgba(139, 92, 246, 0.4);"></span>
                        <span class="sparkline-bar" style="height: 65%; background-color: rgba(139, 92, 246, 0.6);"></span>
                        <span class="sparkline-bar" style="height: 55%; background-color: rgba(139, 92, 246, 0.75);"></span>
                        <span class="sparkline-bar" style="height: 85%; background-color: #8b5cf6;"></span>
                        <span class="sparkline-bar" style="height: 90%; background-color: #8b5cf6;"></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Migration Execution Summary Card (If available) -->
    <?php if ($report): ?>
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3 pb-2 border-bottom border-border flex-wrap gap-2">
            <h5 class="fw-bold font-heading mb-0 text-foreground">
                <i class="fa-solid fa-square-poll-vertical text-success me-2"></i>
                <?= __('migration_results') ?> (<?= strtoupper($report['type']) ?>)
            </h5>
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1.5 rounded-full font-medium">
                <i class="fa-solid fa-circle-check me-1"></i> <?= is_rtl() ? 'اكتمل الترحيل' : 'Completed' ?>
            </span>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-3 col-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= __('total_rows') ?></div>
                        <div class="action-icon-box icon-box-primary" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-table-list"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1"><?= number_format($report['total']) ?></div>
                    <div class="stat-trend text-primary mt-2">
                        <i class="fa-solid fa-file-lines"></i>
                        <span><?= is_rtl() ? 'إجمالي السجلات' : 'Parsed Records' ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= __('rows_inserted') ?></div>
                        <div class="action-icon-box icon-box-success" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1 text-success"><?= number_format($report['inserted']) ?></div>
                    <div class="stat-trend text-success mt-2">
                        <i class="fa-solid fa-plus"></i>
                        <span><?= is_rtl() ? 'سجلات جديدة أضيفت' : 'New Records Created' ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= __('rows_updated') ?></div>
                        <div class="action-icon-box icon-box-info" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-arrows-rotate"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1 text-info"><?= number_format($report['updated']) ?></div>
                    <div class="stat-trend text-info mt-2">
                        <i class="fa-solid fa-pen-to-square"></i>
                        <span><?= is_rtl() ? 'سجلات تم تحديثها' : 'Existing Updated' ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="stat-card-modern h-100">
                    <div class="d-flex align-items-start justify-content-between mb-2">
                        <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= __('rows_skipped') ?></div>
                        <div class="action-icon-box icon-box-warning" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem; border-radius: var(--radius-md);">
                            <i class="fa-solid fa-forward-step"></i>
                        </div>
                    </div>
                    <div class="stat-value my-1 text-warning"><?= number_format($report['skipped']) ?></div>
                    <div class="stat-trend text-warning mt-2">
                        <i class="fa-solid fa-shield"></i>
                        <span><?= is_rtl() ? 'تم تخطيها (تكرار)' : 'Duplicates Skipped' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($report['errors'])): ?>
            <div class="alert alert-warning mb-0">
                <h6 class="fw-bold text-danger mb-2"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= __('errors_encountered') ?> (<?= count($report['errors']) ?>):</h6>
                <ul class="mb-0 small text-danger">
                    <?php foreach (array_slice($report['errors'], 0, 10) as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                    <?php if (count($report['errors']) > 10): ?>
                        <li><em>...and <?= count($report['errors']) - 10 ?> more errors.</em></li>
                    <?php endif; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Navigation Tabs for Migration Modules -->
    <ul class="nav nav-pills mb-4 gap-2" id="migrationTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active px-4 py-2 rounded-3" id="import-tab" data-bs-toggle="pill" data-bs-target="#import-pane" type="button" role="tab">
                <i class="fa-solid fa-file-import me-1"></i> <?= is_rtl() ? 'استيراد وترحيل ملف' : 'Upload & Import' ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link px-4 py-2 rounded-3" id="templates-tab" data-bs-toggle="pill" data-bs-target="#templates-pane" type="button" role="tab">
                <i class="fa-solid fa-file-csv me-1"></i> <?= __('download_templates') ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link px-4 py-2 rounded-3" id="backup-tab" data-bs-toggle="pill" data-bs-target="#backup-pane" type="button" role="tab">
                <i class="fa-solid fa-shield-halved me-1"></i> <?= is_rtl() ? 'النسخ الاحتياطي والترحيل الشامل' : 'Backup & SQL Importer' ?>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="migrationTabsContent">
        
        <!-- PANE 1: Upload & Import Form -->
        <div class="tab-pane fade show active" id="import-pane" role="tabpanel" aria-labelledby="import-tab">
            <div class="card card-custom p-4 bg-white shadow-sm">
                <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-cloud-arrow-up me-2 text-primary"></i><?= is_rtl() ? 'معالج ترحيل البيانات من الأنظمة السابقة' : 'Legacy System Data Migration Wizard' ?></h5>
                <p class="text-muted small"><?= is_rtl() ? 'حدد نوع البيانات المُراد ترحيلها وارفع ملف CSV أو JSON المُصدَّر من النظام القديم.' : 'Select the data category you wish to migrate and upload your exported CSV or JSON file.' ?></p>

                <form method="POST" action="" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="run_migration">

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary"><?= __('migration_type') ?>:</label>
                            <select name="migration_type" class="form-select" id="migrationTypeSelect" required>
                                <option value="students" selected>🎓 <?= __('import_students') ?></option>
                                <option value="teachers">👨‍🏫 <?= __('import_teachers') ?></option>
                                <option value="classes">🏫 <?= __('import_classes') ?></option>
                                <option value="subjects">📚 <?= __('import_subjects') ?></option>
                                <option value="fees">💳 <?= __('import_fees') ?></option>
                                <option value="sql">⚙️ <?= __('import_sql') ?></option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary"><?= __('duplicate_handling') ?>:</label>
                            <select name="conflict_mode" class="form-select" required>
                                <option value="skip" selected>⏩ <?= __('mode_skip') ?></option>
                                <option value="update">🔄 <?= __('mode_update') ?></option>
                                <option value="abort">🛑 <?= __('mode_abort') ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-bold small text-uppercase text-secondary"><?= __('upload_file') ?> (.CSV, .JSON, .SQL):</label>
                        <input type="file" name="data_file" class="form-control" accept=".csv,.json,.sql,.txt" required>
                        <div class="form-text small text-muted">
                            <?= is_rtl() ? 'يدعم الملفات المفصولة بفواصل (Comma) أو فواصل منقوطة (Semicolon) المتوافقة مع Microsoft Excel و Google Sheets.' : 'Supports CSV files with UTF-8 encoding, comma or semicolon delimiters, compatible with Microsoft Excel and Google Sheets.' ?>
                        </div>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="auto_create_entity" id="autoCreateCheck" checked>
                        <label class="form-check-label fw-bold small text-dark" for="autoCreateCheck">
                            <?= __('auto_create_classes') ?>
                        </label>
                    </div>

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary fw-bold px-5 py-2 shadow-sm" onclick="return confirm('<?= is_rtl() ? 'هل أنت مستعد لبدء معالجة واستيراد هذا الملف؟' : 'Are you ready to execute this migration?' ?>');">
                            <i class="fa-solid fa-play me-2"></i> <?= __('start_migration') ?>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="document.getElementById('templates-tab').click()">
                            <i class="fa-solid fa-download me-1"></i> <?= __('download_templates') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- PANE 2: Sample CSV Templates -->
        <div class="tab-pane fade" id="templates-pane" role="tabpanel" aria-labelledby="templates-tab">
            <div class="card card-custom p-4 bg-white shadow-sm">
                <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-file-csv me-2 text-success"></i><?= __('download_templates') ?></h5>
                <p class="text-muted small"><?= is_rtl() ? 'حمل النماذج الجاهزة لملئها ببيانات نظامك القديم قبل رفعها.' : 'Download ready-to-use standardized sample templates pre-filled with the expected headers and sample rows.' ?></p>

                <div class="row g-3">
                    <!-- Template 1: Students -->
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h6 class="fw-bold text-dark"><i class="fa-solid fa-user-graduate me-2 text-primary"></i><?= __('import_students') ?></h6>
                                <p class="small text-muted mb-3">Columns: <code>admission_no, full_name, gender, dob, class_name, phone, email, parent_name, parent_phone</code></p>
                            </div>
                            <a href="download_template.php?type=students" class="btn btn-sm btn-outline-primary fw-bold w-100">
                                <i class="fa-solid fa-download me-1"></i> template_students.csv
                            </a>
                        </div>
                    </div>

                    <!-- Template 2: Teachers -->
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h6 class="fw-bold text-dark"><i class="fa-solid fa-chalkboard-user me-2 text-success"></i><?= __('import_teachers') ?></h6>
                                <p class="small text-muted mb-3">Columns: <code>full_name, username, email, phone, assigned_classes, assigned_subjects</code></p>
                            </div>
                            <a href="download_template.php?type=teachers" class="btn btn-sm btn-outline-success fw-bold w-100">
                                <i class="fa-solid fa-download me-1"></i> template_teachers.csv
                            </a>
                        </div>
                    </div>

                    <!-- Template 3: Classes -->
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h6 class="fw-bold text-dark"><i class="fa-solid fa-school me-2 text-info"></i><?= __('import_classes') ?></h6>
                                <p class="small text-muted mb-3">Columns: <code>class_name, section</code></p>
                            </div>
                            <a href="download_template.php?type=classes" class="btn btn-sm btn-outline-info fw-bold w-100">
                                <i class="fa-solid fa-download me-1"></i> template_classes.csv
                            </a>
                        </div>
                    </div>

                    <!-- Template 4: Subjects -->
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h6 class="fw-bold text-dark"><i class="fa-solid fa-book-bookmark me-2 text-purple" style="color: #6f42c1;"></i><?= __('import_subjects') ?></h6>
                                <p class="small text-muted mb-3">Columns: <code>subject_name, subject_code</code></p>
                            </div>
                            <a href="download_template.php?type=subjects" class="btn btn-sm btn-outline-secondary fw-bold w-100">
                                <i class="fa-solid fa-download me-1"></i> template_subjects.csv
                            </a>
                        </div>
                    </div>

                    <!-- Template 5: Fees & Invoices -->
                    <div class="col-md-4 col-sm-6">
                        <div class="p-3 border rounded-3 bg-light h-100 d-flex flex-column justify-content-between">
                            <div>
                                <h6 class="fw-bold text-dark"><i class="fa-solid fa-file-invoice-dollar me-2 text-danger"></i><?= __('import_fees') ?></h6>
                                <p class="small text-muted mb-3">Columns: <code>admission_no, invoice_number, term, academic_year, total_amount, status, due_date</code></p>
                            </div>
                            <a href="download_template.php?type=fees" class="btn btn-sm btn-outline-danger fw-bold w-100">
                                <i class="fa-solid fa-download me-1"></i> template_fees.csv
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PANE 3: Backup & Raw SQL Migration -->
        <div class="tab-pane fade" id="backup-pane" role="tabpanel" aria-labelledby="backup-tab">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card card-custom p-4 bg-white shadow-sm h-100 border-start border-warning border-4">
                        <h5 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-download me-2 text-warning"></i><?= is_rtl() ? 'تصدير نسخة احتياطية كاملة' : 'Export Full System Database (.SQL)' ?></h5>
                        <p class="text-muted small"><?= is_rtl() ? 'قم بتحميل نسخة احتياطية كاملة لجميع جداول النظام والسجلات قبل إجراء عمليات الترحيل الكبيرة لضمان الأمان التام.' : 'Download an exact snapshot of your entire database schema and records for safekeeping.' ?></p>
                        <div class="mt-4">
                            <a href="data_migration.php?action=export_backup" class="btn btn-warning fw-bold text-dark px-4 py-2 shadow-sm w-100">
                                <i class="fa-solid fa-file-shield me-2"></i> <?= __('backup_system') ?>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card card-custom p-4 bg-white shadow-sm h-100 border-start border-dark border-4">
                        <h5 class="fw-bold mb-2 text-dark"><i class="fa-solid fa-code me-2 text-dark"></i><?= is_rtl() ? 'استيراد سكريبت SQL مخصص' : 'Execute Raw SQL Migration Script' ?></h5>
                        <p class="text-muted small"><?= is_rtl() ? 'إذا كان لديك سكريبت SQL مُصدَّر من نظام MySQL آخر، يمكنك تشغيله مباشرة داخل بيئة آمنة.' : 'Upload and run structured MySQL migration files directly into the active database.' ?></p>
                        <div class="mt-4">
                            <button type="button" class="btn btn-dark fw-bold px-4 py-2 shadow-sm w-100" onclick="document.getElementById('migrationTypeSelect').value='sql'; document.getElementById('import-tab').click();">
                                <i class="fa-solid fa-terminal me-2"></i> <?= is_rtl() ? 'فتح معالج سكريبت SQL' : 'Open SQL Script Uploader' ?>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <?= render_platform_footer() ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
