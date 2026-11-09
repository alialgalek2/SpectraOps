<?php
/**
 * HR Module - Academic & Administrative Departments Management
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin, School Admin, HR, or Finance
Auth::requireRole(['admin', 'school_admin', 'hr', 'finance']);

$user_role = Auth::role();
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_msg_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// -------------------------------------------------------------
// 1. Handle Form Submissions (Create / Edit / Delete)
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_department') {
        $dept_id = intval($_POST['department_id'] ?? 0);
        $name = trim($_POST['department_name'] ?? '');
        $code = strtoupper(trim($_POST['department_code'] ?? ''));
        $head_id = !empty($_POST['head_user_id']) ? intval($_POST['head_user_id']) : null;
        $budget = floatval($_POST['budget'] ?? 0.00);
        $description = trim($_POST['description'] ?? '');

        if (!empty($name)) {
            try {
                if ($dept_id > 0) {
                    $stmt = $pdo->prepare("UPDATE departments SET department_name = ?, department_code = ?, head_user_id = ?, budget = ?, description = ? WHERE department_id = ?");
                    $stmt->execute([$name, $code, $head_id, $budget, $description, $dept_id]);
                    $_SESSION['flash_msg'] = is_rtl() ? "تم تحديث بيانات القسم بنجاح!" : "Department details updated successfully!";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO departments (department_name, department_code, head_user_id, budget, description) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$name, $code, $head_id, $budget, $description]);
                    $_SESSION['flash_msg'] = is_rtl() ? "تم إضافة القسم الجديد بنجاح!" : "New department added successfully!";
                }
                $_SESSION['flash_msg_type'] = "success";
            } catch (\PDOException $e) {
                $_SESSION['flash_msg'] = "Database Error: " . $e->getMessage();
                $_SESSION['flash_msg_type'] = "danger";
            }
        } else {
            $_SESSION['flash_msg'] = is_rtl() ? "يرجى كتابة اسم القسم." : "Department name is required.";
            $_SESSION['flash_msg_type'] = "warning";
        }
        header("Location: departments.php");
        exit();
    }

    if ($action === 'delete_department') {
        $dept_id = intval($_POST['department_id'] ?? 0);
        if ($dept_id > 0) {
            try {
                // Clear department_id from employees first
                $pdo->prepare("UPDATE employees SET department_id = NULL WHERE department_id = ?")->execute([$dept_id]);
                $pdo->prepare("DELETE FROM departments WHERE department_id = ?")->execute([$dept_id]);
                $_SESSION['flash_msg'] = is_rtl() ? "تم حذف القسم بنجاح." : "Department deleted successfully.";
                $_SESSION['flash_msg_type'] = "success";
            } catch (\PDOException $e) {
                $_SESSION['flash_msg'] = "Error deleting department: " . $e->getMessage();
                $_SESSION['flash_msg_type'] = "danger";
            }
        }
        header("Location: departments.php");
        exit();
    }

    if ($action === 'assign_employee') {
        $emp_id = intval($_POST['employee_id'] ?? 0);
        $dept_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
        if ($emp_id > 0) {
            $pdo->prepare("UPDATE employees SET department_id = ? WHERE employee_id = ?")->execute([$dept_id, $emp_id]);
            $_SESSION['flash_msg'] = is_rtl() ? "تم تعيين الموظف إلى القسم بنجاح." : "Employee assigned to department successfully.";
            $_SESSION['flash_msg_type'] = "success";
        }
        header("Location: departments.php");
        exit();
    }
}

// -------------------------------------------------------------
// 2. Fetch Data
// -------------------------------------------------------------
$departments = [];
$total_budget = 0.0;
try {
    $deptStmt = $pdo->query("
        SELECT d.*, 
               u.full_name AS head_name,
               (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.department_id) AS staff_count
        FROM departments d
        LEFT JOIN users u ON d.head_user_id = u.user_id
        ORDER BY d.department_name ASC
    ");
    $departments = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($departments as $d) {
        $total_budget += floatval($d['budget']);
    }
} catch (\Exception $e) {}

// Fetch eligible department heads (Teachers & Admins)
$potential_heads = [];
try {
    $potential_heads = $pdo->query("SELECT user_id, full_name, role FROM users WHERE role IN ('teacher', 'admin', 'school_admin', 'hr') AND (is_deleted = 0 OR is_deleted IS NULL) ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {}

// Fetch all employees for assignment modal
$all_employees = [];
try {
    $all_employees = $pdo->query("SELECT employee_id, full_name, category, job_title, department_id FROM employees WHERE status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {}

$total_assigned_staff = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE department_id IS NOT NULL AND status = 'Active'")->fetchColumn();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'الأقسام الأكاديمية والإدارية' : 'Academic & Administrative Departments' ?> - <?= __('portal_title') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'الأقسام الأكاديمية والإدارية' : 'Departments & Faculty Units',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="employees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-users me-1 text-primary"></i> ' . (is_rtl() ? 'دليل الموظفين' : 'Staff') . '</a>',
        '<a href="staff_attendance.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-clipboard-user me-1 text-primary"></i> ' . (is_rtl() ? 'الحضور والدوام' : 'Attendance') . '</a>'
    ]
]) ?>

<div class="container-fluid px-4 py-4">

    <!-- Flash Alert Notification -->
    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-xs mb-4" role="alert">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-info' ?> fs-5"></i>
            <div><?= htmlspecialchars($msg) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header Actions Bar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h4 class="fw-bold font-heading text-foreground mb-1">
                <i class="fa-solid fa-sitemap text-primary me-2"></i><?= is_rtl() ? 'هيكل الأقسام والوحدات الوظيفية' : 'Organizational Departments & Faculty Units' ?>
            </h4>
            <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'إدارة الهيكل التنظيمي للمدرسة، تحديد رؤساء الأقسام، الموازنات، وتسكين الكوادر.' : 'Manage departmental structure, department chairs, operating budgets, and faculty staff allocations.' ?></p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-outline-border btn-sm rounded-lg px-3 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#assignStaffModal">
                <i class="fa-solid fa-user-tag me-1 text-primary"></i> <?= is_rtl() ? 'تسكين موظف في قسم' : 'Assign Staff Member' ?>
            </button>
            <button type="button" class="btn btn-primary btn-sm rounded-lg px-3 py-2 fw-bold shadow-xs" data-bs-toggle="modal" data-bs-target="#addDeptModal">
                <i class="fa-solid fa-plus me-1"></i> <?= is_rtl() ? 'إضافة قسم جديد' : 'New Department' ?>
            </button>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي الأقسام' : 'Total Departments' ?></span>
                    <div class="action-icon-box icon-box-primary" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-sitemap"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-foreground"><?= count($departments) ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'أكاديمية وإدارية' : 'Active units' ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الكوادر المسكنة' : 'Assigned Staff' ?></span>
                    <div class="action-icon-box icon-box-purple" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-foreground"><?= $total_assigned_staff ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'موظف مرتبط بقسم' : 'Faculty & Staff allocated' ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الموازنة التشغيلية المعتمدة' : 'Total Department Budgets' ?></span>
                    <div class="action-icon-box icon-box-warning" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-coins"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-foreground">$<?= number_format($total_budget, 2) ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'مجموع موازنات الأقسام' : 'Allocated fiscal funds' ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'رؤساء الأقسام المعينين' : 'Appointed Chairs' ?></span>
                    <div class="action-icon-box icon-box-secondary" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-user-tie"></i>
                    </div>
                </div>
                <?php
                $heads_count = 0;
                foreach ($departments as $d) {
                    if (!empty($d['head_user_id'])) $heads_count++;
                }
                ?>
                <div class="fs-4 fw-bold font-heading text-foreground"><?= $heads_count ?> / <?= count($departments) ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'أقسام لها قيادة معينة' : 'Departments with Head' ?></div>
            </div>
        </div>
    </div>

    <!-- Departments Grid / Table -->
    <div class="card border border-border bg-card shadow-sm rounded-xl overflow-hidden">
        <div class="card-header bg-card py-3 px-4 border-bottom border-border d-flex justify-content-between align-items-center">
            <h6 class="fw-bold font-heading text-foreground mb-0">
                <i class="fa-solid fa-list-check me-2 text-primary"></i><?= is_rtl() ? 'قائمة الأقسام وسجلات الكادر' : 'Department Roster & Headcount' ?>
            </h6>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-secondary small text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3"><?= is_rtl() ? 'القسم' : 'Department Name' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'الرمز' : 'Code' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'رئيس القسم' : 'Head of Department' ?></th>
                        <th class="py-3 text-center"><?= is_rtl() ? 'الكادر' : 'Staff Count' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'الموازنة' : 'Budget' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'الوصف' : 'Description' ?></th>
                        <th class="px-4 py-3 text-end"><?= is_rtl() ? 'الإجراءات' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($departments)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted-foreground">
                                <i class="fa-solid fa-sitemap fs-2 mb-2 d-block opacity-50"></i>
                                <?= is_rtl() ? 'لا توجد أقسام مسجلة حالياً.' : 'No departments found. Click "New Department" to get started.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($departments as $dept): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="fw-bold text-foreground"><?= htmlspecialchars($dept['department_name']) ?></div>
                                </td>
                                <td class="py-3">
                                    <span class="badge bg-secondary text-primary border border-border px-2 py-1 font-mono font-bold">
                                        <?= htmlspecialchars($dept['department_code'] ?: '—') ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <?php if (!empty($dept['head_name'])): ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fa-solid fa-user-tie text-primary small"></i>
                                            <span class="fw-semibold text-foreground"><?= htmlspecialchars($dept['head_name']) ?></span>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted-foreground small fst-italic"><?= is_rtl() ? 'غير معين' : 'Unassigned' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 text-center">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1 fw-bold">
                                        <?= $dept['staff_count'] ?> <?= is_rtl() ? 'موظف' : 'members' ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <span class="fw-semibold text-foreground">$<?= number_format(floatval($dept['budget']), 2) ?></span>
                                </td>
                                <td class="py-3">
                                    <span class="text-muted-foreground small"><?= htmlspecialchars(mb_strimwidth($dept['description'] ?? '', 0, 50, '...')) ?></span>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-outline-border" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#addDeptModal"
                                                data-dept-id="<?= $dept['department_id'] ?>"
                                                data-dept-name="<?= htmlspecialchars($dept['department_name']) ?>"
                                                data-dept-code="<?= htmlspecialchars($dept['department_code']) ?>"
                                                data-dept-head="<?= $dept['head_user_id'] ?>"
                                                data-dept-budget="<?= $dept['budget'] ?>"
                                                data-dept-desc="<?= htmlspecialchars($dept['description'] ?? '') ?>"
                                                onclick="populateDeptModal(this)">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'هل أنت متأكد من حذف هذا القسم؟ سيتم فك ارتباط الموظفين به.' : 'Are you sure you want to delete this department? Employees will be unlinked.' ?>');">
                                            <input type="hidden" name="action" value="delete_department">
                                            <input type="hidden" name="department_id" value="<?= $dept['department_id'] ?>">
                                            <button type="submit" class="btn btn-outline-border text-destructive">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Add / Edit Department -->
<div class="modal fade" id="addDeptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border shadow-lg rounded-xl">
            <form method="POST">
                <input type="hidden" name="action" value="save_department">
                <input type="hidden" name="department_id" id="modalDeptId" value="0">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground font-heading" id="modalDeptTitle">
                        <i class="fa-solid fa-sitemap text-primary me-2"></i><?= is_rtl() ? 'إضافة قسم جديد' : 'New Department' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'اسم القسم' : 'Department Name' ?> <span class="text-danger">*</span></label>
                        <input type="text" name="department_name" id="modalDeptName" class="form-control" required placeholder="e.g. Mathematics & Computer Science">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'كود / رمز القسم' : 'Department Code' ?></label>
                            <input type="text" name="department_code" id="modalDeptCode" class="form-control text-uppercase" placeholder="e.g. MATH">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'الموازنة ($)' : 'Operating Budget ($)' ?></label>
                            <input type="number" step="0.01" name="budget" id="modalDeptBudget" class="form-control" placeholder="0.00">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'رئيس القسم' : 'Head of Department (HOD)' ?></label>
                        <select name="head_user_id" id="modalDeptHead" class="form-select">
                            <option value=""><?= is_rtl() ? '-- اختياري / بدون رئيس --' : '-- Optional / Unassigned --' ?></option>
                            <?php foreach ($potential_heads as $ph): ?>
                                <option value="<?= $ph['user_id'] ?>"><?= htmlspecialchars($ph['full_name']) ?> (<?= ucfirst($ph['role']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'الوصف وملاحظات العمل' : 'Description & Mission' ?></label>
                        <textarea name="description" id="modalDeptDesc" rows="3" class="form-control" placeholder="<?= is_rtl() ? 'وصف مختصر لأهداف ونشاطات القسم...' : 'Brief description of scope, faculty duties, and focus...' ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-border py-2.5">
                    <button type="button" class="btn btn-outline-border btn-sm px-3" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold"><?= __('save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Assign Staff to Department -->
<div class="modal fade" id="assignStaffModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border shadow-lg rounded-xl">
            <form method="POST">
                <input type="hidden" name="action" value="assign_employee">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground font-heading">
                        <i class="fa-solid fa-user-tag text-primary me-2"></i><?= is_rtl() ? 'تسكين موظف في قسم' : 'Assign Employee to Department' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'اختر الموظف' : 'Select Employee' ?> <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select" required>
                            <option value=""><?= is_rtl() ? '-- اختر موظفاً --' : '-- Choose Employee --' ?></option>
                            <?php foreach ($all_employees as $ae): ?>
                                <option value="<?= $ae['employee_id'] ?>"><?= htmlspecialchars($ae['full_name']) ?> (<?= htmlspecialchars($ae['job_title'] ?: $ae['category']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'تعيين إلى القسم' : 'Assign to Department' ?> <span class="text-danger">*</span></label>
                        <select name="department_id" class="form-select" required>
                            <option value=""><?= is_rtl() ? '-- اختر القسم --' : '-- Choose Department --' ?></option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['department_id'] ?>"><?= htmlspecialchars($d['department_name']) ?> (<?= htmlspecialchars($d['department_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer border-top border-border py-2.5">
                    <button type="button" class="btn btn-outline-border btn-sm px-3" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold"><?= __('update') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function populateDeptModal(btn) {
    document.getElementById('modalDeptId').value = btn.getAttribute('data-dept-id') || '0';
    document.getElementById('modalDeptName').value = btn.getAttribute('data-dept-name') || '';
    document.getElementById('modalDeptCode').value = btn.getAttribute('data-dept-code') || '';
    document.getElementById('modalDeptHead').value = btn.getAttribute('data-dept-head') || '';
    document.getElementById('modalDeptBudget').value = btn.getAttribute('data-dept-budget') || '0.00';
    document.getElementById('modalDeptDesc').value = btn.getAttribute('data-dept-desc') || '';
    document.getElementById('modalDeptTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-primary me-2"></i>' + (btn.getAttribute('data-dept-id') ? '<?= is_rtl() ? 'تعديل بيانات القسم' : 'Edit Department' ?>' : '<?= is_rtl() ? 'إضافة قسم جديد' : 'New Department' ?>');
}
</script>
</body>
</html>
