<?php
/**
 * HR Module - Main Personnel & Staff Dashboard
 * Main view for listing staff (Teachers, Cleaners, Drivers, Security, Administrative)
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'hr', 'finance']);

$user_role = Auth::role();
$user_id   = Auth::id();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$upload_dir = __DIR__ . '/../../uploads/hr_documents';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle Document Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    $emp_id    = intval($_POST['employee_id'] ?? 0);
    $doc_title = trim($_POST['document_title'] ?? '');
    $doc_type  = trim($_POST['document_type'] ?? 'General');
    $doc_exp   = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
    $doc_notes = trim($_POST['document_notes'] ?? '');

    if ($emp_id > 0 && !empty($doc_title) && isset($_FILES['doc_file']) && $_FILES['doc_file']['error'] === UPLOAD_ERR_OK) {
        $allowedMimeTypes = [
            'application/pdf', 'image/jpeg', 'image/png',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/msword'
        ];
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['doc_file']['tmp_name']);
        if ($_FILES['doc_file']['size'] > 10 * 1024 * 1024 || !in_array($mimeType, $allowedMimeTypes, true)) {
            $_SESSION['flash_msg'] = 'Upload a PDF, Word document, JPG, or PNG no larger than 10 MB.';
            $_SESSION['flash_msg_type'] = 'danger';
            header('Location: hr_dashboard.php');
            exit();
        }
        $file_name = bin2hex(random_bytes(16)) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['doc_file']['name']));
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
            header("Location: hr_dashboard.php");
            exit();
        }
    }
}

// Handle Delete Document.  This is POST-only and the file path remains private.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_document') {
    $doc_id = intval($_POST['document_id'] ?? 0);
    $docStmt = $pdo->prepare('SELECT file_path FROM employee_documents WHERE document_id = ?');
    $docStmt->execute([$doc_id]);
    $doc = $docStmt->fetch();
    if ($doc) {
        $real_path = __DIR__ . '/../../' . $doc['file_path'];
        if (file_exists($real_path)) @unlink($real_path);
        $deleteStmt = $pdo->prepare('DELETE FROM employee_documents WHERE document_id = ?');
        $deleteStmt->execute([$doc_id]);
        $_SESSION['flash_msg'] = "Document removed from personnel archive.";
        $_SESSION['flash_msg_type'] = "warning";
        header("Location: hr_dashboard.php");
        exit();
    }
}

// Query Filters & Search
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
    <title><?= is_rtl() ? 'شؤون وسجلات الموظفين والعمال' : 'HR & Personnel Dashboard' ?></title>
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
        '<a href="add_employee.php" class="btn btn-primary btn-sm fw-bold"><i class="fa-solid fa-user-plus me-1"></i> ' . (is_rtl() ? 'إضافة موظف' : 'Add Employee') . '</a>',
        '<a href="payroll_ledger.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i> ' . __('staff_payroll') . '</a>',
        '<a href="leaves.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-person-walking-arrow-right me-1 text-primary"></i> ' . __('staff_leaves') . '</a>'
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
            <div class="d-flex gap-2 flex-wrap">
                <a href="add_employee.php" class="btn btn-light text-primary fw-bold px-3 py-2 rounded-lg shadow-xs">
                    <i class="fa-solid fa-user-plus me-1"></i> <?= is_rtl() ? 'تسجيل موظف جديد' : 'Register New Staff' ?>
                </a>
                <a href="payroll_ledger.php" class="btn btn-outline-light fw-bold px-3 py-2 rounded-lg">
                    <i class="fa-solid fa-money-bill-wave me-1"></i> <?= is_rtl() ? 'مسير الرواتب' : 'Payroll Ledger' ?>
                </a>
            </div>
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
            </div>
        </div>
    </div>

    <!-- Category Filter Pills & Search Bar -->
    <div class="card border-border rounded-xl p-3 bg-card shadow-sm mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <a href="hr_dashboard.php?cat=all" class="cat-pill <?= $filter_category === 'all' ? 'active' : '' ?>">
                    <i class="fa-solid fa-layer-group"></i> All (<?= $totalStaffCount ?>)
                </a>
                <a href="hr_dashboard.php?cat=Teacher" class="cat-pill <?= $filter_category === 'Teacher' ? 'active' : '' ?>">
                    <i class="fa-solid fa-chalkboard-user text-primary"></i> Teachers (<?= $catCounts['Teacher'] ?? 0 ?>)
                </a>
                <a href="hr_dashboard.php?cat=Driver" class="cat-pill <?= $filter_category === 'Driver' ? 'active' : '' ?>">
                    <i class="fa-solid fa-bus text-warning"></i> Drivers (<?= $catCounts['Driver'] ?? 0 ?>)
                </a>
                <a href="hr_dashboard.php?cat=Cleaner" class="cat-pill <?= $filter_category === 'Cleaner' ? 'active' : '' ?>">
                    <i class="fa-solid fa-broom text-success"></i> Cleaners (<?= $catCounts['Cleaner'] ?? 0 ?>)
                </a>
                <a href="hr_dashboard.php?cat=Security" class="cat-pill <?= $filter_category === 'Security' ? 'active' : '' ?>">
                    <i class="fa-solid fa-shield text-info"></i> Security (<?= $catCounts['Security'] ?? 0 ?>)
                </a>
                <a href="hr_dashboard.php?cat=Administrative" class="cat-pill <?= $filter_category === 'Administrative' ? 'active' : '' ?>">
                    <i class="fa-solid fa-briefcase text-primary"></i> Administrative (<?= $catCounts['Administrative'] ?? 0 ?>)
                </a>
            </div>

            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="cat" value="<?= htmlspecialchars($filter_category) ?>">
                <input type="text" name="q" class="form-control form-control-sm rounded-lg" placeholder="<?= is_rtl() ? 'بحث بالاسم، الكود، الهاتف أو الهوية...' : 'Search name, code, phone, NID...' ?>" value="<?= htmlspecialchars($filter_search) ?>" style="min-width: 260px;">
                <button type="submit" class="btn btn-primary btn-sm fw-bold px-3 rounded-lg">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </button>
            </form>
        </div>
    </div>

    <!-- Employee Roster Table -->
    <div class="card border-border rounded-xl p-4 bg-card shadow-sm mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold font-heading text-foreground mb-0">
                <i class="fa-solid fa-users me-2 text-primary"></i>Personnel Directory (<?= count($employees) ?> records)
            </h5>
            <a href="add_employee.php" class="btn btn-sm btn-primary fw-bold rounded-lg">
                <i class="fa-solid fa-user-plus me-1"></i> Add Staff
            </a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Photo</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Code &amp; Name</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Category</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Job Title / Dept</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Phone &amp; NID</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Salary</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold text-center">Docs</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted-foreground py-5">
                                <i class="fa-solid fa-folder-open fs-2 mb-2 d-block opacity-50"></i>
                                No personnel records found matching current category filter.
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
                                <td><code><?= htmlspecialchars($emp['employee_code'] ?: ('EMP-' . $emp['employee_id'])) ?></code></td>
                                <td>
                                    <strong class="text-dark d-block"><?= htmlspecialchars($emp['full_name']) ?></strong>
                                    <small class="text-muted"><?= htmlspecialchars($emp['job_title'] ?? $emp['category']) ?></small>
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
                                        <a href="add_employee.php?edit_id=<?= $emp['employee_id'] ?>" class="btn btn-outline-secondary" title="Edit Profile">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= render_platform_footer() ?>
</div>

<!-- Document Archiving Modal -->
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

                <h6 class="fw-bold mb-2 text-dark">Archived Documents on Record</h6>
                <div id="docsListContainer">
                    <p class="text-muted small">Loading documents...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Employee 360 Modal -->
<div class="modal fade" id="viewEmployeeModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-address-card me-2"></i>Employee 360 Record</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewModalBody"></div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openDocsModal(empId, empName, empCat) {
    document.getElementById('docsEmpName').innerText = empName;
    document.getElementById('docsEmpCat').innerText = empCat;
    document.getElementById('uploadDocEmpId').value = empId;
    
    const container = document.getElementById('docsListContainer');
    container.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div> Loading archived documents...</div>';
    
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
                        <td><strong>${d.document_title || d.document_type}</strong></td>
                        <td><span class="badge bg-light text-dark border">${d.document_type}</span></td>
                        <td><small class="text-muted">${d.file_size || '—'}</small></td>
                        <td>${expBadge}</td>
                        <td>
                            <a href="../../api/download_employee_document.php?id=${d.document_id}" target="_blank" class="btn btn-sm btn-outline-primary py-0"><i class="fa-solid fa-download"></i> View</a>
                            <form method="POST" action="hr_dashboard.php" class="d-inline" onsubmit="return confirm('Delete this document?');">
                                <input type="hidden" name="action" value="delete_document">
                                <input type="hidden" name="document_id" value="${d.document_id}">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger py-0"><i class="fa-solid fa-trash"></i></button>
                            </form>
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
                <strong class="fs-5 text-primary">${emp.employee_code || ('EMP-' + emp.employee_id)}</strong>
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
                <strong>${emp.job_title || emp.category}</strong>
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
                <strong>${emp.payment_method || 'Cash'}</strong>
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
</script>
</body>
</html>
