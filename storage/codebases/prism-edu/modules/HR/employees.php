<?php
/**
 * HR Module - Staff & Personnel Sub-System (Employees & Document Archiving)
 * Separates System Portal Users from Non-Portal HR Personnel (Drivers, Cleaners, Guards)
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'hr', 'finance']);

$user_role = Auth::role();
$user_id   = Auth::id();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$upload_dir = __DIR__ . '/../../uploads/hr_documents';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// -------------------------------------------------------------
// 1. Handle New Staff Registration
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_employee') {
    $full_name       = trim($_POST['full_name'] ?? '');
    $national_id     = trim($_POST['national_id'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $category        = $_POST['category'] ?? 'Support';
    $job_title       = trim($_POST['job_title'] ?? 'Staff');
    $employment_type = $_POST['employment_type'] ?? 'Full-time';
    $hire_date       = !empty($_POST['hire_date']) ? $_POST['hire_date'] : date('Y-m-d');
    $base_salary     = floatval($_POST['base_salary'] ?? 0);
    $payment_method  = $_POST['payment_method'] ?? 'Cash';
    $bank_name       = trim($_POST['bank_name'] ?? '');
    $bank_account_no = trim($_POST['bank_account_no'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');
    $grant_portal    = isset($_POST['grant_portal_access']) && $_POST['grant_portal_access'] == '1';

    if (!empty($full_name) && !empty($job_title)) {
        try {
            $pdo->beginTransaction();

            $linked_user_id = null;

            // If Portal Access is requested, provision a user account
            if ($grant_portal) {
                $portal_username = trim($_POST['portal_username'] ?? '');
                $portal_password = $_POST['portal_password'] ?? '';
                $portal_role     = $_POST['portal_role'] ?? 'teacher';

                if (empty($portal_username) || empty($portal_password)) {
                    throw new Exception("Username and Password are required when granting system login access.");
                }

                // Check username uniqueness
                $chkUser = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
                $chkUser->execute([$portal_username]);
                if ($chkUser->fetch()) {
                    throw new Exception("Username '$portal_username' is already taken. Please choose another.");
                }

                $role_map = ['admin' => 1, 'teacher' => 2, 'finance' => 3, 'hr' => 13];
                $role_id  = $role_map[$portal_role] ?? 2;
                $hashed   = password_hash($portal_password, PASSWORD_BCRYPT);

                $insUser = $pdo->prepare("
                    INSERT INTO users (username, password, full_name, email, phone, role, role_id, status, must_change_password, is_deleted)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', 0, 0)
                ");
                $insUser->execute([$portal_username, $hashed, $full_name, $email, $phone, $portal_role, $role_id]);
                $linked_user_id = $pdo->lastInsertId();
            }

            // Generate unique employee code (e.g. EMP-104)
            $countTotal = (int)$pdo->query("SELECT COUNT(*) FROM employees")->fetchColumn() + 101;
            $emp_code = "EMP-" . str_pad($countTotal, 3, '0', STR_PAD_LEFT);
            
            // Check collision
            while ($pdo->query("SELECT employee_id FROM employees WHERE employee_code = '$emp_code'")->fetch()) {
                $countTotal++;
                $emp_code = "EMP-" . str_pad($countTotal, 3, '0', STR_PAD_LEFT);
            }

            $insEmp = $pdo->prepare("
                INSERT INTO employees (
                    employee_code, user_id, full_name, national_id, phone, email, address,
                    category, job_title, employment_type, hire_date, base_salary, payment_method,
                    bank_name, bank_account_no, notes, status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
            ");
            $insEmp->execute([
                $emp_code, $linked_user_id, $full_name, $national_id, $phone, $email, $address,
                $category, $job_title, $employment_type, $hire_date, $base_salary, $payment_method,
                $bank_name, $bank_account_no, $notes
            ]);

            $new_emp_id = $pdo->lastInsertId();

            // Handle optional initial document upload
            if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
                $doc_title = trim($_POST['initial_doc_title'] ?? 'Initial Employment Document');
                $doc_type  = $_POST['initial_doc_type'] ?? 'Contract';
                $doc_exp   = !empty($_POST['initial_doc_expiry']) ? $_POST['initial_doc_expiry'] : null;

                $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['document_file']['name']);
                $dest = $upload_dir . '/' . $file_name;

                if (move_uploaded_file($_FILES['document_file']['tmp_name'], $dest)) {
                    $doc_path = 'uploads/hr_documents/' . $file_name;
                    $size_str = round($_FILES['document_file']['size'] / 1024, 1) . ' KB';

                    $insDoc = $pdo->prepare("
                        INSERT INTO employee_documents (employee_id, document_title, document_type, file_path, file_size, expiry_date)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $insDoc->execute([$new_emp_id, $doc_title, $doc_type, $doc_path, $size_str, $doc_exp]);
                }
            }

            $pdo->commit();
            $_SESSION['flash_msg'] = is_rtl() ? "تم تسجيل الموظف ($full_name) برقم $emp_code بنجاح!" : "Staff member ($full_name) registered with Code $emp_code successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: employees.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error registering staff: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please provide both Full Name and Job Title.";
        $msg_type = "danger";
    }
}

// -------------------------------------------------------------
// 2. Handle Document Upload for Existing Employee
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    $emp_id    = intval($_POST['employee_id'] ?? 0);
    $doc_title = trim($_POST['document_title'] ?? '');
    $doc_type  = trim($_POST['document_type'] ?? 'General');
    $doc_exp   = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $doc_notes = trim($_POST['document_notes'] ?? '');

    if ($emp_id > 0 && !empty($doc_title) && isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $file_name = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['doc_file']['name']);
        $dest = $upload_dir . '/' . $file_name;

        if (move_uploaded_file($_FILES['doc_file']['tmp_name'], $dest)) {
            $doc_path = 'uploads/hr_documents/' . $file_name;
            $size_str = round($_FILES['doc_file']['size'] / 1024, 1) . ' KB';

            $ins = $pdo->prepare("
                INSERT INTO employee_documents (employee_id, document_title, document_type, file_path, file_size, expiry_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->execute([$emp_id, $doc_title, $doc_type, $doc_path, $size_str, $doc_exp, $doc_notes]);

            $_SESSION['flash_msg'] = is_rtl() ? "تم رفع وأرشفة الوثيقة بنجاح!" : "Document archived successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: employees.php?emp_id=$emp_id#docs");
            exit();
        } else {
            $msg = "Failed to save uploaded file.";
            $msg_type = "danger";
        }
    } else {
        $msg = "Please select a valid file and provide a document title.";
        $msg_type = "danger";
    }
}

// -------------------------------------------------------------
// 3. Handle Employee Update / Edit
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_employee') {
    $emp_id          = intval($_POST['employee_id'] ?? 0);
    $full_name       = trim($_POST['full_name'] ?? '');
    $national_id     = trim($_POST['national_id'] ?? '');
    $phone           = trim($_POST['phone'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $address         = trim($_POST['address'] ?? '');
    $category        = $_POST['category'] ?? 'Support';
    $job_title       = trim($_POST['job_title'] ?? '');
    $employment_type = $_POST['employment_type'] ?? 'Full-time';
    $base_salary     = floatval($_POST['base_salary'] ?? 0);
    $payment_method  = $_POST['payment_method'] ?? 'Cash';
    $bank_name       = trim($_POST['bank_name'] ?? '');
    $bank_account_no = trim($_POST['bank_account_no'] ?? '');
    $status          = $_POST['status'] ?? 'Active';
    $notes           = trim($_POST['notes'] ?? '');

    if ($emp_id > 0 && !empty($full_name) && !empty($job_title)) {
        try {
            $upd = $pdo->prepare("
                UPDATE employees 
                SET full_name = ?, national_id = ?, phone = ?, email = ?, address = ?,
                    category = ?, job_title = ?, employment_type = ?, base_salary = ?,
                    payment_method = ?, bank_name = ?, bank_account_no = ?, status = ?, notes = ?
                WHERE employee_id = ?
            ");
            $upd->execute([
                $full_name, $national_id, $phone, $email, $address,
                $category, $job_title, $employment_type, $base_salary,
                $payment_method, $bank_name, $bank_account_no, $status, $notes, $emp_id
            ]);

            $_SESSION['flash_msg'] = "Employee profile #$emp_id updated successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: employees.php");
            exit();
        } catch (Exception $e) {
            $msg = "Error updating employee: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// -------------------------------------------------------------
// 4. Handle Delete Document
// -------------------------------------------------------------
if (isset($_GET['delete_doc_id'])) {
    $doc_id = intval($_GET['delete_doc_id']);
    $doc = $pdo->query("SELECT file_path FROM employee_documents WHERE document_id = $doc_id")->fetch();
    if ($doc) {
        $real_path = __DIR__ . '/../../' . $doc['file_path'];
        if (file_exists($real_path)) {
            @unlink($real_path);
        }
        $pdo->exec("DELETE FROM employee_documents WHERE document_id = $doc_id");
        $_SESSION['flash_msg'] = "Document deleted.";
        $_SESSION['flash_msg_type'] = "warning";
        header("Location: employees.php");
        exit();
    }
}

// -------------------------------------------------------------
// 5. Query Filters & Data Fetching
// -------------------------------------------------------------
$filter_category = $_GET['cat'] ?? 'all';
$filter_search   = trim($_GET['q'] ?? '');

$sql = "
    SELECT e.*, u.username as portal_username, u.role as portal_role,
           (SELECT COUNT(*) FROM employee_documents d WHERE d.employee_id = e.employee_id) as doc_count
    FROM employees e
    LEFT JOIN users u ON e.user_id = u.user_id
    WHERE 1=1
";
$params = [];

if ($filter_category !== 'all') {
    $sql .= " AND e.category = ?";
    $params[] = $filter_category;
}

if (!empty($filter_search)) {
    $sql .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ? OR e.phone LIKE ? OR e.national_id LIKE ? OR e.job_title LIKE ?)";
    $term = "%$filter_search%";
    $params = array_merge($params, [$term, $term, $term, $term, $term]);
}

$sql .= " ORDER BY e.category ASC, e.employee_id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll();

// KPI Metrics
$totalStaffCount   = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();
$portalUsersCount  = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE user_id IS NOT NULL AND status = 'Active'")->fetchColumn();
$nonPortalCount    = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE user_id IS NULL AND status = 'Active'")->fetchColumn();
$totalMonthlySalary= (float)$pdo->query("SELECT SUM(base_salary) FROM employees WHERE status = 'Active'")->fetchColumn();

// Category stats for pill badges
$catCounts = $pdo->query("SELECT category, COUNT(*) as c FROM employees WHERE status = 'Active' GROUP BY category")->fetchAll(PDO::FETCH_KEY_PAIR);
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'شؤون وسجلات الموظفين والعمال' : 'Personnel & Staff Sub-System' ?> - HR Hub</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .cat-pill {
            padding: 8px 16px;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.2s ease;
            border: 1px solid var(--border);
            color: var(--muted-foreground);
            background: var(--card);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .cat-pill:hover, .cat-pill.active {
            background: var(--primary);
            color: var(--primary-foreground);
            border-color: var(--primary);
            box-shadow: 0 4px 10px var(--ring);
        }
        .badge-cat-teacher { background-color: #7C3AED; color: white; }
        .badge-cat-driver { background-color: #D97706; color: white; }
        .badge-cat-cleaner { background-color: #059669; color: white; }
        .badge-cat-security { background-color: #0284C7; color: white; }
        .badge-cat-admin { background-color: var(--primary); color: white; }
        .badge-cat-support { background-color: #64748B; color: white; }
    </style>
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'شؤون الموظفين والكوادر المساعدة' : 'HR & Personnel Sub-System',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => [
        '<a href="leaves.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-person-walking-arrow-right me-1 text-primary"></i> ' . __('staff_leaves') . '</a>',
        '<a href="payroll.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-money-bill-wave me-1 text-primary"></i> ' . __('staff_payroll') . '</a>'
    ]
]) ?>

<div class="container-fluid px-4 py-4">

    <!-- Flash Messages -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Welcome Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 mb-2">
                    <span class="badge rounded-full font-medium px-3 py-1 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                        <i class="fa-solid fa-shield-halved text-emerald-300 me-1"></i> Dedicated Personnel Sub-System: Active
                    </span>
                </div>
                <h2 class="fw-bold font-heading mb-1 text-white">
                    <i class="fa-solid fa-people-roof me-2"></i><?= is_rtl() ? 'سجل الكوادر الأكاديمية والخدمات المساندة' : 'Personnel Registry & Document Archive' ?>
                </h2>
                <p class="mb-0 text-white-50 small">
                    <?= is_rtl() 
                        ? 'إدارة عقود السائقين، عمال النظافة، الحراسات الأمنية والمعلمين مع أرشفة الوثائق الرسمية والربط المالي للرواتب.' 
                        : 'Unified separation between Software Login Accounts and HR Personnel records (Drivers, Cleaners, Security, Teachers).' ?>
                </p>
            </div>
            <button class="btn btn-light text-primary fw-bold px-3 py-2 rounded-lg shadow-xs" data-bs-toggle="modal" data-bs-target="#createEmployeeModal">
                <i class="fa-solid fa-user-plus me-1"></i> <?= is_rtl() ? 'تسجيل موظف / عامل جديد' : 'Register New Staff' ?>
            </button>
        </div>
    </div>

    <!-- 4-Column Responsive KPI Cards Grid -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي الكادر النشط' : 'Total Active Staff' ?></span>
                    <div class="bg-accent text-primary p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem;">
                        <i class="fa-solid fa-users-gear fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $totalStaffCount ?> <small class="fs-6 text-muted-foreground"><?= is_rtl() ? 'موظف' : 'Staff' ?></small></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-emerald-500 fw-semibold"><i class="fa-solid fa-circle-check me-1"></i> Active</span>
                    <span class="text-muted-foreground">· On Payroll</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'مستخدمو البوابة (تسجيل دخول)' : 'Portal App Users' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(16,185,129,0.12); color: #10B981;">
                        <i class="fa-solid fa-lock-open fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $portalUsersCount ?> <small class="fs-6 text-muted-foreground">Users</small></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-success fw-semibold"><i class="fa-solid fa-shield-halved me-1"></i> Teachers/Admins</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'كوادر مساندة (سجلات فقط)' : 'Non-Portal Staff' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(245,158,11,0.12); color: #F59E0B;">
                        <i class="fa-solid fa-id-card-clip fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $nonPortalCount ?> <small class="fs-6 text-muted-foreground">Staff</small></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-warning fw-semibold"><i class="fa-solid fa-truck-front me-1"></i> Drivers/Cleaners/Guards</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'كتلة الرواتب الأساسية' : 'Monthly Base Payroll' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(2,132,199,0.12); color: #0284C7;">
                        <i class="fa-solid fa-file-invoice-dollar fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1">$<?= number_format($totalMonthlySalary, 2) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold"><i class="fa-solid fa-calendar-check me-1"></i> Monthly</span>
                    <span class="text-muted-foreground">· Net Obligation</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Filter Pills & Search Bar -->
    <div class="card border-border rounded-xl p-3 bg-card shadow-sm mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="employees.php?cat=all" class="cat-pill <?= $filter_category === 'all' ? 'active' : '' ?>">
                    <i class="fa-solid fa-layer-group"></i> All (<?= $totalStaffCount ?>)
                </a>
                <a href="employees.php?cat=Teacher" class="cat-pill <?= $filter_category === 'Teacher' ? 'active' : '' ?>">
                    <i class="fa-solid fa-chalkboard-user text-primary"></i> Teachers (<?= $catCounts['Teacher'] ?? 0 ?>)
                </a>
                <a href="employees.php?cat=Driver" class="cat-pill <?= $filter_category === 'Driver' ? 'active' : '' ?>">
                    <i class="fa-solid fa-bus text-warning"></i> Drivers (<?= $catCounts['Driver'] ?? 0 ?>)
                </a>
                <a href="employees.php?cat=Cleaner" class="cat-pill <?= $filter_category === 'Cleaner' ? 'active' : '' ?>">
                    <i class="fa-solid fa-broom text-success"></i> Cleaners (<?= $catCounts['Cleaner'] ?? 0 ?>)
                </a>
                <a href="employees.php?cat=Security" class="cat-pill <?= $filter_category === 'Security' ? 'active' : '' ?>">
                    <i class="fa-solid fa-shield text-info"></i> Security (<?= $catCounts['Security'] ?? 0 ?>)
                </a>
                <a href="employees.php?cat=Administrative" class="cat-pill <?= $filter_category === 'Administrative' ? 'active' : '' ?>">
                    <i class="fa-solid fa-briefcase text-primary"></i> Administrative (<?= $catCounts['Administrative'] ?? 0 ?>)
                </a>
            </div>

            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="cat" value="<?= htmlspecialchars($filter_category) ?>">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="<?= is_rtl() ? 'بحث بالاسم، الكود، الهاتف أو الهوية...' : 'Search name, code, phone, NID...' ?>" value="<?= htmlspecialchars($filter_search) ?>" style="min-width: 260px;">
                <button type="submit" class="btn btn-dark btn-sm fw-bold px-3">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Main Employees Table -->
    <div class="card card-custom p-4 bg-white">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 8%;">Code</th>
                        <th style="width: 22%;">Personnel Full Name</th>
                        <th style="width: 15%;">Category &amp; Role</th>
                        <th style="width: 12%;">Contact &amp; NID</th>
                        <th style="width: 13%;" class="text-center">System Portal Access</th>
                        <th style="width: 10%;">Base Salary</th>
                        <th style="width: 8%;" class="text-center">Docs</th>
                        <th style="width: 12%;" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="fa-solid fa-folder-open fs-1 d-block mb-2 text-secondary"></i>
                                No personnel records matching current filter criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $emp): ?>
                            <?php 
                            $catClass = 'badge-cat-support';
                            $catIcon = 'fa-user';
                            switch ($emp['category']) {
                                case 'Teacher': $catClass = 'badge-cat-teacher'; $catIcon = 'fa-chalkboard-user'; break;
                                case 'Driver': $catClass = 'badge-cat-driver'; $catIcon = 'fa-bus'; break;
                                case 'Cleaner': $catClass = 'badge-cat-cleaner'; $catIcon = 'fa-broom'; break;
                                case 'Security': $catClass = 'badge-cat-security'; $catIcon = 'fa-shield'; break;
                                case 'Administrative': $catClass = 'badge-cat-admin'; $catIcon = 'fa-briefcase'; break;
                            }
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($emp['employee_code']) ?></code></td>
                                <td>
                                    <strong class="text-dark d-block"><?= htmlspecialchars($emp['full_name']) ?></strong>
                                    <small class="text-muted"><?= htmlspecialchars($emp['job_title']) ?></small>
                                </td>
                                <td>
                                    <span class="badge <?= $catClass ?> px-2 py-1">
                                        <i class="fa-solid <?= $catIcon ?> me-1"></i> <?= htmlspecialchars($emp['category']) ?>
                                    </span>
                                    <span class="badge bg-light text-dark border ms-1"><?= $emp['employment_type'] ?></span>
                                </td>
                                <td>
                                    <div><i class="fa-solid fa-phone small text-muted me-1"></i> <?= htmlspecialchars($emp['phone'] ?: '—') ?></div>
                                    <?php if (!empty($emp['national_id'])): ?>
                                        <small class="text-muted">NID: <?= htmlspecialchars($emp['national_id']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($emp['user_id'])): ?>
                                        <?php
                                            $roleMap = [
                                                'admin'        => function_exists('is_rtl') && is_rtl() ? 'مسؤول النظام' : 'Admin',
                                                'school_admin' => function_exists('is_rtl') && is_rtl() ? 'إدارة المدرسة' : 'School Admin',
                                                'hr'           => function_exists('is_rtl') && is_rtl() ? 'مسؤول الموارد البشرية' : 'HR Manager',
                                                'finance'      => function_exists('is_rtl') && is_rtl() ? 'المسؤول المالي' : 'Finance',
                                                'teacher'      => function_exists('is_rtl') && is_rtl() ? 'معلم' : 'Teacher'
                                            ];
                                            $rawRole = strtolower($emp['portal_role'] ?? '');
                                            $displayRole = $roleMap[$rawRole] ?? ucwords(str_replace('_', ' ', $emp['portal_role'] ?? 'User'));
                                        ?>
                                        <span class="portal-badge-active" title="<?= htmlspecialchars($displayRole . ' (@' . ($emp['portal_username'] ?? '') . ')') ?>">
                                            <i class="fa-solid fa-circle-check text-success"></i>
                                            <span><?= htmlspecialchars($displayRole) ?></span>
                                            <span class="portal-username-tag">@<?= htmlspecialchars($emp['portal_username'] ?? '') ?></span>
                                        </span>
                                    <?php else: ?>
                                        <span class="portal-badge-inactive" title="<?= function_exists('is_rtl') && is_rtl() ? 'سجل شؤون موظفين فقط - بدون حساب دخول' : 'Strictly HR Personnel Record - No Login Account' ?>">
                                            <i class="fa-solid fa-user-slash text-muted"></i>
                                            <span><?= function_exists('is_rtl') && is_rtl() ? 'سجل موظف فقط' : 'Personnel Record Only' ?></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong class="text-dark">$<?= number_format($emp['base_salary'], 2) ?></strong>
                                    <div class="text-muted small"><?= $emp['payment_method'] ?></div>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-info fw-bold" onclick="openDocsModal(<?= $emp['employee_id'] ?>, '<?= htmlspecialchars(addslashes($emp['full_name'])) ?>', '<?= $emp['category'] ?>')">
                                        <i class="fa-solid fa-folder-closed me-1"></i> <?= $emp['doc_count'] ?>
                                    </button>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" title="View 360 Profile" onclick="openViewModal(<?= htmlspecialchars(json_encode($emp)) ?>)">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <button class="btn btn-outline-secondary" title="Edit Record" onclick="openEditModal(<?= htmlspecialchars(json_encode($emp)) ?>)">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
        </div>
    </div>

    <?= render_platform_footer() ?>
</div>

<!-- ============================================================= -->
<!-- MODAL 1: Create Employee with "Grant Portal Access" Toggle    -->
<!-- ============================================================= -->
<div class="modal fade" id="createEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_employee">
                
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus text-warning me-2"></i><?= is_rtl() ? 'تسجيل موظف أو كادر مساند جديد' : 'Register New Staff / Personnel' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <h6 class="fw-bold text-primary text-uppercase border-bottom pb-2 mb-3">1. Personal &amp; Identity Details</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Full Legal Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required placeholder="e.g. Tariq Al-Mansoor">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">National ID / Residence No</label>
                            <input type="text" name="national_id" class="form-control" placeholder="e.g. NID-84920194">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Mobile Phone Number</label>
                            <input type="text" name="phone" class="form-control" placeholder="e.g. +249 912 345 678">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email Address</label>
                            <input type="email" name="email" class="form-control" placeholder="e.g. tariq.driver@school.internal">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Residential Address</label>
                            <input type="text" name="address" class="form-control" placeholder="e.g. Nile District, Khartoum">
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary text-uppercase border-bottom pb-2 mb-3">2. Employment &amp; Compensation</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Staff Category <span class="text-danger">*</span></label>
                            <select name="category" id="createEmpCategory" class="form-select fw-bold" onchange="adaptDocHints(this.value)">
                                <option value="Teacher">Teacher (Academic)</option>
                                <option value="Driver">Driver (Transportation)</option>
                                <option value="Cleaner">Cleaner / Custodian</option>
                                <option value="Security">Security / Campus Guard</option>
                                <option value="Administrative">Administrative Officer</option>
                                <option value="Support" selected>General Support Staff</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold">Job Title / Designation <span class="text-danger">*</span></label>
                            <input type="text" name="job_title" class="form-control" required placeholder="e.g. School Bus Driver (Route 4)">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Employment Type</label>
                            <select name="employment_type" class="form-select">
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Base Monthly Salary ($)</label>
                            <input type="number" step="0.01" name="base_salary" class="form-control" value="850.00" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash">Cash at Vault</option>
                                <option value="Bank Transfer">Bank Wire Transfer</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Hire Date</label>
                            <input type="date" name="hire_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>

                    <!-- Toggle Section: Grant System Login Access -->
                    <div class="p-3 bg-light rounded-3 border border-2 border-primary mb-4">
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" id="grantPortalSwitch" name="grant_portal_access" value="1" onchange="togglePortalFields(this.checked)">
                            <label class="form-check-label fw-bold text-dark fs-6" for="grantPortalSwitch">
                                <i class="fa-solid fa-key text-primary me-1"></i> Grant System Login Access (Create Portal Account)
                            </label>
                        </div>
                        <p class="text-muted small mb-0">
                            Leave <strong>UNCHECKED</strong> for non-portal personnel (Drivers, Cleaners, Security guards) to keep login tables secure. Check <strong>ONLY</strong> for Teachers and Administrators who require software access.
                        </p>

                        <div id="portalCredentialBox" style="display: none;" class="mt-3 pt-3 border-top">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-uppercase">Portal Username <span class="text-danger">*</span></label>
                                    <input type="text" name="portal_username" id="portal_username" class="form-control" placeholder="e.g. t.mansoor">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-uppercase">Initial Password <span class="text-danger">*</span></label>
                                    <input type="password" name="portal_password" id="portal_password" class="form-control" placeholder="Minimum 6 characters">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-uppercase">System Role</label>
                                    <select name="portal_role" class="form-select">
                                        <option value="teacher">Teacher Portal</option>
                                        <option value="hr">HR Manager</option>
                                        <option value="finance">Finance Manager</option>
                                        <option value="admin">System Administrator</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h6 class="fw-bold text-primary text-uppercase border-bottom pb-2 mb-3">3. Initial Compliance Document (Optional)</h6>
                    <div id="docHintBox" class="alert alert-info py-2 small mb-3">
                        <i class="fa-solid fa-circle-info me-1"></i> Recommended for <span id="docHintCat">General Support</span>: <strong>Contract &amp; National ID Copy</strong>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Document Title</label>
                            <input type="text" name="initial_doc_title" class="form-control" placeholder="e.g. Heavy Driver License 2026">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Document Type</label>
                            <select name="initial_doc_type" id="initial_doc_type" class="form-select">
                                <option value="Contract">Employment Contract</option>
                                <option value="National ID">National ID / Passport</option>
                                <option value="Driver License">Driver's License</option>
                                <option value="Vehicle Insurance">Vehicle Insurance</option>
                                <option value="Medical Clearance">Medical / Health Certificate</option>
                                <option value="Background Check">Police Background Clearance</option>
                                <option value="Academic Degree">Academic Degree / Diploma</option>
                                <option value="CV">Curriculum Vitae (CV)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small">Expiry Date (If applicable)</label>
                            <input type="date" name="initial_doc_expiry" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">Select File (PDF, PNG, JPG, DOCX)</label>
                            <input type="file" name="document_file" class="form-control" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx">
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success fw-bold px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Complete Staff Registration
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 2: Document Archiving & Uploads Console                 -->
<!-- ============================================================= -->
<div class="modal fade" id="docsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-folder-open text-info me-2"></i>Document Archive &amp; Compliance</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-0 text-dark" id="docsEmpName">Staff Name</h5>
                        <span class="badge bg-primary" id="docsEmpCat">Category</span>
                    </div>
                </div>

                <!-- Upload New Document Form -->
                <div class="card p-3 bg-light border mb-4">
                    <h6 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-cloud-arrow-up text-primary me-2"></i>Upload New Verification Document</h6>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_document">
                        <input type="hidden" name="employee_id" id="uploadDocEmpId">

                        <div class="row g-2 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label small fw-bold">Document Title <span class="text-danger">*</span></label>
                                <input type="text" name="document_title" class="form-control form-control-sm" required placeholder="e.g. Commercial License 2026">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">Doc Type</label>
                                <select name="document_type" id="modalDocTypeSelect" class="form-select form-select-sm">
                                    <option value="Driver License">Driver's License</option>
                                    <option value="Vehicle Insurance">Vehicle Insurance</option>
                                    <option value="National ID">National ID / Passport</option>
                                    <option value="Medical Clearance">Medical Clearance</option>
                                    <option value="Background Check">Background Check</option>
                                    <option value="Academic Degree">Degree / Certificate</option>
                                    <option value="Contract">Employment Contract</option>
                                    <option value="CV">CV / Resume</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small fw-bold">Expiry Date</label>
                                <input type="date" name="expiry_date" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small fw-bold">File</label>
                                <input type="file" name="doc_file" class="form-control form-control-sm" required>
                            </div>
                            <div class="col-12 text-end mt-2">
                                <button type="submit" class="btn btn-primary btn-sm fw-bold px-3">
                                    <i class="fa-solid fa-upload me-1"></i> Upload &amp; Archive
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Existing Documents Table Placeholder (Populated via AJAX or Render) -->
                <h6 class="fw-bold mb-2 text-dark">Archived Documents on Record</h6>
                <div id="docsListContainer">
                    <p class="text-muted small">Loading documents...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 3: Employee 360 Overview Modal                          -->
<!-- ============================================================= -->
<div class="modal fade" id="viewEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-address-card me-2"></i>Employee 360 Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewModalBody">
                <!-- Injected via JS -->
            </div>
        </div>
    </div>
</div>

<!-- ============================================================= -->
<!-- MODAL 4: Edit Employee Modal                                  -->
<!-- ============================================================= -->
<div class="modal fade" id="editEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit_employee">
                <input type="hidden" name="employee_id" id="edit_emp_id">

                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square text-warning me-2"></i>Edit Personnel Profile</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Full Legal Name</label>
                            <input type="text" name="full_name" id="edit_full_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">National ID</label>
                            <input type="text" name="national_id" id="edit_national_id" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Phone</label>
                            <input type="text" name="phone" id="edit_phone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold">Email</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Category</label>
                            <select name="category" id="edit_category" class="form-select fw-bold">
                                <option value="Teacher">Teacher</option>
                                <option value="Driver">Driver</option>
                                <option value="Cleaner">Cleaner</option>
                                <option value="Security">Security</option>
                                <option value="Administrative">Administrative</option>
                                <option value="Support">Support</option>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label fw-bold">Job Title</label>
                            <input type="text" name="job_title" id="edit_job_title" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Status</label>
                            <select name="status" id="edit_status" class="form-select fw-bold">
                                <option value="Active">Active</option>
                                <option value="On Leave">On Leave</option>
                                <option value="Terminated">Terminated</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Base Salary ($)</label>
                            <input type="number" step="0.01" name="base_salary" id="edit_base_salary" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Payment Method</label>
                            <select name="payment_method" id="edit_payment_method" class="form-select">
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold">Employment Type</label>
                            <select name="employment_type" id="edit_employment_type" class="form-select">
                                <option value="Full-time">Full-time</option>
                                <option value="Part-time">Part-time</option>
                                <option value="Contract">Contract</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Address</label>
                            <input type="text" name="address" id="edit_address" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold">Administrative Notes</label>
                            <textarea name="notes" id="edit_notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePortalFields(checked) {
    const box = document.getElementById('portalCredentialBox');
    const u = document.getElementById('portal_username');
    const p = document.getElementById('portal_password');
    if (checked) {
        box.style.display = 'block';
        u.required = true;
        p.required = true;
    } else {
        box.style.display = 'none';
        u.required = false;
        p.required = false;
    }
}

function adaptDocHints(cat) {
    const hintCat = document.getElementById('docHintCat');
    const hintBox = document.getElementById('docHintBox');
    const sel = document.getElementById('initial_doc_type');
    
    if (cat === 'Driver') {
        hintCat.innerText = 'Drivers';
        hintBox.innerHTML = '<i class="fa-solid fa-bus me-1"></i> Recommended for <strong>Drivers</strong>: Heavy Commercial Driver License, Vehicle Insurance Papers, National ID.';
        sel.value = 'Driver License';
    } else if (cat === 'Cleaner') {
        hintCat.innerText = 'Cleaners';
        hintBox.innerHTML = '<i class="fa-solid fa-broom me-1"></i> Recommended for <strong>Cleaners</strong>: National ID / Residence Card, Medical Clearance & Health Card.';
        sel.value = 'Medical Clearance';
    } else if (cat === 'Security') {
        hintCat.innerText = 'Security';
        hintBox.innerHTML = '<i class="fa-solid fa-shield me-1"></i> Recommended for <strong>Security</strong>: National ID, Police Background Check, First Aid & Safety Training Certificate.';
        sel.value = 'Background Check';
    } else if (cat === 'Teacher') {
        hintCat.innerText = 'Teachers';
        hintBox.innerHTML = '<i class="fa-solid fa-chalkboard-user me-1"></i> Recommended for <strong>Teachers</strong>: Academic Degrees, Teaching Certification, Curriculum Vitae (CV).';
        sel.value = 'Academic Degree';
    } else {
        hintCat.innerText = 'Staff';
        hintBox.innerHTML = '<i class="fa-solid fa-circle-info me-1"></i> Recommended for <strong>Staff</strong>: Employment Contract, National ID Copy.';
        sel.value = 'Contract';
    }
}

function openDocsModal(empId, empName, empCat) {
    document.getElementById('docsEmpName').innerText = empName;
    document.getElementById('docsEmpCat').innerText = empCat;
    document.getElementById('uploadDocEmpId').value = empId;
    
    const container = document.getElementById('docsListContainer');
    container.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Loading archived documents...</div>';
    
    // Fetch docs via simple script or endpoint
    fetch('../../api/get_employee_docs.php?employee_id=' + empId)
        .then(r => r.json())
        .then(data => {
            if (!data.docs || data.docs.length === 0) {
                container.innerHTML = '<div class="alert alert-light border text-center text-muted py-3">No compliance or verification documents uploaded yet.</div>';
            } else {
                let html = '<div class="table-responsive"><table class="table table-sm table-bordered align-middle">';
                html += '<thead class="table-light"><tr><th>Title</th><th>Type</th><th>Size</th><th>Expiry Date</th><th>Action</th></tr></thead><tbody>';
                data.docs.forEach(d => {
                    let expBadge = '<span class="badge bg-secondary">No Expiry</span>';
                    if (d.expiry_date) {
                        let expDate = new Date(d.expiry_date);
                        let today = new Date();
                        if (expDate < today) {
                            expBadge = '<span class="badge bg-danger">Expired (' + d.expiry_date + ')</span>';
                        } else {
                            expBadge = '<span class="badge bg-success">' + d.expiry_date + '</span>';
                        }
                    }
                    html += `<tr>
                        <td><strong>${d.document_title}</strong></td>
                        <td><span class="badge bg-light text-dark border">${d.document_type}</span></td>
                        <td><small class="text-muted">${d.file_size || '—'}</small></td>
                        <td>${expBadge}</td>
                        <td>
                            <a href="../../${d.file_path}" target="_blank" class="btn btn-sm btn-outline-primary py-0"><i class="fa-solid fa-download"></i> View</a>
                            <a href="employees.php?delete_doc_id=${d.document_id}" class="btn btn-sm btn-outline-danger py-0" onclick="return confirm('Delete this document?');"><i class="fa-solid fa-trash"></i></a>
                        </td>
                    </tr>`;
                });
                html += '</tbody></table></div>';
                container.innerHTML = html;
            }
        })
        .catch(err => {
            container.innerHTML = '<div class="alert alert-danger py-2">Error loading documents: ' + err.message + '</div>';
        });

    new bootstrap.Modal(document.getElementById('docsModal')).show();
}

function openViewModal(emp) {
    const roleLabels = {
        'admin': 'Admin',
        'school_admin': 'School Admin',
        'hr': 'HR Manager',
        'finance': 'Finance',
        'teacher': 'Teacher'
    };
    const roleKey = (emp.portal_role || '').toLowerCase();
    const formattedRole = roleLabels[roleKey] || (emp.portal_role ? emp.portal_role.replace(/_/g, ' ') : 'User');
    const portalStatus = emp.user_id 
        ? `<span class="portal-badge-active"><i class="fa-solid fa-circle-check text-success"></i> ${formattedRole} <span class="portal-username-tag">@${emp.portal_username || ''}</span></span>` 
        : `<span class="portal-badge-inactive"><i class="fa-solid fa-user-slash text-muted"></i> HR Personnel Record Only</span>`;

    const html = `
        <div class="row g-3">
            <div class="col-md-6">
                <small class="text-muted text-uppercase fw-bold d-block">Employee Code</small>
                <strong class="fs-5 text-primary">${emp.employee_code}</strong>
            </div>
            <div class="col-md-6">
                <small class="text-muted text-uppercase fw-bold d-block">Full Legal Name</small>
                <strong class="fs-5 text-dark">${emp.full_name}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Category</small>
                <span class="badge bg-dark">${emp.category}</span> (${emp.employment_type})
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Job Title</small>
                <strong>${emp.job_title}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Hire Date</small>
                <strong>${emp.hire_date || '—'}</strong>
            </div>
            <div class="col-12 p-3 bg-light rounded border">
                <small class="text-muted text-uppercase fw-bold d-block mb-1">System Login Access Status</small>
                ${portalStatus}
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">National ID</small>
                <strong>${emp.national_id || '—'}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Phone Number</small>
                <strong>${emp.phone || '—'}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Email</small>
                <strong>${emp.email || '—'}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Base Salary</small>
                <strong class="text-success fs-5">$${parseFloat(emp.base_salary).toFixed(2)}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Payment Method</small>
                <strong>${emp.payment_method}</strong>
            </div>
            <div class="col-md-4">
                <small class="text-muted text-uppercase fw-bold d-block">Status</small>
                <span class="badge bg-${emp.status === 'Active' ? 'success' : 'danger'}">${emp.status}</span>
            </div>
            <div class="col-12">
                <small class="text-muted text-uppercase fw-bold d-block">Residential Address</small>
                <div>${emp.address || '—'}</div>
            </div>
            <div class="col-12">
                <small class="text-muted text-uppercase fw-bold d-block">Internal Notes</small>
                <div class="p-2 bg-light rounded">${emp.notes || 'No administrative notes.'}</div>
            </div>
        </div>
    `;
    document.getElementById('viewModalBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('viewEmployeeModal')).show();
}

function openEditModal(emp) {
    document.getElementById('edit_emp_id').value = emp.employee_id;
    document.getElementById('edit_full_name').value = emp.full_name;
    document.getElementById('edit_national_id').value = emp.national_id || '';
    document.getElementById('edit_phone').value = emp.phone || '';
    document.getElementById('edit_email').value = emp.email || '';
    document.getElementById('edit_category').value = emp.category;
    document.getElementById('edit_job_title').value = emp.job_title;
    document.getElementById('edit_status').value = emp.status;
    document.getElementById('edit_base_salary').value = emp.base_salary;
    document.getElementById('edit_payment_method').value = emp.payment_method;
    document.getElementById('edit_employment_type').value = emp.employment_type;
    document.getElementById('edit_address').value = emp.address || '';
    document.getElementById('edit_notes').value = emp.notes || '';

    new bootstrap.Modal(document.getElementById('editEmployeeModal')).show();
}
</script>
</body>
</html>
