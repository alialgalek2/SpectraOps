<?php
/**
 * HR Module - Staff Salary Advances & Emergency Loans Portal
 * Handles loan requests, approval workflows, repayment installment schedules,
 * and integration with monthly payroll deductions.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'hr', 'finance', 'teacher']);

$user_role   = Auth::role();
$user_id     = Auth::id();
$full_name   = Auth::fullName();
$is_hr_admin = in_array($user_role, ['admin', 'school_admin', 'hr', 'finance']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Submit New Advance Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_advance') {
    $emp_id           = intval($_POST['employee_id'] ?? 0);
    $amount           = floatval($_POST['amount'] ?? 0);
    $repayment_months = max(1, intval($_POST['repayment_months'] ?? 1));
    $purpose          = trim($_POST['purpose'] ?? '');
    $request_date     = !empty($_POST['request_date']) ? $_POST['request_date'] : date('Y-m-d');

    // If staff user (not HR admin), verify they are requesting for their own linked employee profile
    if (!$is_hr_admin) {
        $check = $pdo->prepare("SELECT employee_id FROM employees WHERE user_id = ?");
        $check->execute([$user_id]);
        $emp_id = intval($check->fetchColumn());
    }

    if ($emp_id > 0 && $amount > 0 && $repayment_months >= 1) {
        $monthly_deduction = round($amount / $repayment_months, 2);
        try {
            $stmt = $pdo->prepare("
                INSERT INTO staff_advances (employee_id, amount, repayment_months, monthly_deduction, remaining_balance, purpose, status, request_date)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?)
            ");
            $stmt->execute([$emp_id, $amount, $repayment_months, $monthly_deduction, $amount, $purpose, $request_date]);

            $_SESSION['flash_msg'] = "Salary advance request for " . number_format($amount, 2) . " submitted successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: advances.php");
            exit();
        } catch (\PDOException $e) {
            $msg = "Error submitting advance request: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please provide a valid employee, loan amount (> 0), and repayment duration (at least 1 month).";
        $msg_type = "danger";
    }
}

// Handle Admin/HR/Finance Approval or Rejection
if ($is_hr_admin && isset($_GET['action_advance']) && isset($_GET['id'])) {
    $aid = intval($_GET['id']);
    $status_target = $_GET['action_advance'] === 'approve' ? 'Approved' : 'Rejected';

    try {
        $upd = $pdo->prepare("UPDATE staff_advances SET status = ?, approved_by = ? WHERE advance_id = ?");
        $upd->execute([$status_target, $user_id, $aid]);

        $_SESSION['flash_msg'] = "Advance request #$aid has been " . strtolower($status_target) . " successfully.";
        $_SESSION['flash_msg_type'] = $status_target === 'Approved' ? 'success' : 'warning';
        header("Location: advances.php");
        exit();
    } catch (\PDOException $e) {
        $msg = "Error updating advance status: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// Handle Manual Repayment Installment
if ($is_hr_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_repayment') {
    $aid         = intval($_POST['advance_id'] ?? 0);
    $pay_amount  = floatval($_POST['payment_amount'] ?? 0);

    if ($aid > 0 && $pay_amount > 0) {
        $check = $pdo->prepare("SELECT remaining_balance, status FROM staff_advances WHERE advance_id = ?");
        $check->execute([$aid]);
        $row = $check->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $new_balance = max(0, floatval($row['remaining_balance']) - $pay_amount);
            $new_status  = ($new_balance <= 0) ? 'Repaid' : 'Approved';

            $upd = $pdo->prepare("UPDATE staff_advances SET remaining_balance = ?, status = ? WHERE advance_id = ?");
            $upd->execute([$new_balance, $new_status, $aid]);

            $_SESSION['flash_msg'] = "Repayment of " . number_format($pay_amount, 2) . " recorded. New remaining balance: " . number_format($new_balance, 2);
            $_SESSION['flash_msg_type'] = "success";
            header("Location: advances.php");
            exit();
        }
    }
}

// Query Filter Parameters
$status_filter = $_GET['status'] ?? '';
$search_query  = trim($_GET['q'] ?? '');

$sql = "
    SELECT a.*, 
           COALESCE(e.full_name, e.employee_code) AS employee_name,
           e.employee_code,
           e.job_title,
           d.department_name,
           u.full_name AS approver_name
    FROM staff_advances a
    JOIN employees e ON a.employee_id = e.employee_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN users u ON a.approved_by = u.user_id
    WHERE 1=1
";
$params = [];

if (!$is_hr_admin) {
    // Regular staff can only see their own requests
    $sql .= " AND e.user_id = ? ";
    $params[] = $user_id;
}

if (!empty($status_filter)) {
    $sql .= " AND a.status = ? ";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $sql .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ? OR a.purpose LIKE ?) ";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
    $params[] = "%$search_query%";
}

$sql .= " ORDER BY a.advance_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$advances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$metric_stmt = $pdo->query("
    SELECT 
        COUNT(*) AS total_requests,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'Approved' THEN amount ELSE 0 END) AS total_approved_amount,
        SUM(CASE WHEN status = 'Approved' THEN remaining_balance ELSE 0 END) AS total_outstanding,
        SUM(CASE WHEN status = 'Repaid' THEN 1 ELSE 0 END) AS repaid_count
    FROM staff_advances
");
$metrics = $metric_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch employees list for dropdown
$emp_stmt = $pdo->query("SELECT employee_id, employee_code, full_name as name, job_title FROM employees WHERE status = 'Active' ORDER BY full_name ASC");
$employees_list = $emp_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'السلف والقروض المالية للموظفين' : 'Staff Salary Advances & Loans' ?> - <?= __('portal_title') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'السلف والقروض للموظفين' : 'Salary Advances & Loans',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="payroll.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-invoice-dollar me-1 text-primary"></i> ' . (is_rtl() ? 'كشف الرواتب' : 'Payroll') . '</a>',
        '<a href="employees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-users me-1 text-primary"></i> ' . (is_rtl() ? 'دليل الموظفين' : 'Staff') . '</a>'
    ]
]) ?>

<div class="container-fluid px-4 py-4">

    <!-- Flash Messages -->
    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type) ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-xs mb-4" role="alert">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-info' ?> fs-5"></i>
            <div><?= htmlspecialchars($msg) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Header Actions Bar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h4 class="fw-bold font-heading text-foreground mb-1">
                <i class="fa-solid fa-hand-holding-dollar text-primary me-2"></i><?= is_rtl() ? 'السلف والقروض المالية للموظفين' : 'Staff Salary Advances & Emergency Loans' ?>
            </h4>
            <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'إدارة طلبات السلف المالية، خطط السداد الشهرية، وتتبع الاستقطاع التلقائي من مسير الرواتب.' : 'Manage staff loan requests, installment schedules, and automated payroll deduction reconciliation.' ?></p>
        </div>
        <div>
            <button type="button" class="btn btn-primary btn-sm rounded-lg px-3 py-2 fw-bold shadow-xs" data-bs-toggle="modal" data-bs-target="#newAdvanceModal">
                <i class="fa-solid fa-plus me-1"></i> <?= is_rtl() ? 'طلب سلفة جديدة' : 'Request Advance' ?>
            </button>
        </div>
    </div>

    <!-- Unified KPI Metrics Cards Grid -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'المبالغ المعتمدة' : 'Total Approved' ?></span>
                    <div class="action-icon-box icon-box-primary" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-foreground">$<?= number_format($metrics['total_approved_amount'] ?? 0, 2) ?></div>
                <div class="small text-muted-foreground"><?= intval($metrics['repaid_count'] ?? 0) ?> <?= is_rtl() ? 'سلفة مسددة بالكامل' : 'fully repaid' ?></div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الرصيد المتبقي المستحق' : 'Outstanding Balance' ?></span>
                    <div class="action-icon-box icon-box-danger" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-danger">$<?= number_format($metrics['total_outstanding'] ?? 0, 2) ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'يخصم شهرياً من الرواتب' : 'Deducted via payroll' ?></div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'طلبات قيد المراجعة' : 'Pending Requests' ?></span>
                    <div class="action-icon-box icon-box-warning" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-warning"><?= intval($metrics['pending_count'] ?? 0) ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'بانتظار الاعتماد الإداري' : 'Awaiting HR/Finance' ?></div>
            </div>
        </div>

        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي طلبات السلف' : 'Total Submissions' ?></span>
                    <div class="action-icon-box icon-box-success" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-foreground"><?= intval($metrics['total_requests'] ?? 0) ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'سجل السلف التراكمي' : 'Advance ledger records' ?></div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Bar -->
    <div class="card border border-border rounded-xl p-3 bg-card shadow-sm mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-transparent border-border text-muted-foreground"><i class="fa-solid fa-search"></i></span>
                    <input type="text" name="q" class="form-control form-control-sm bg-background text-foreground border-border rounded-end" placeholder="<?= is_rtl() ? 'بحث باسم الموظف أو الكود أو السبب...' : 'Search by staff name, code, or purpose...' ?>" value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
            <div class="col-md-4">
                <select name="status" class="form-select form-select-sm bg-background text-foreground border-border rounded-lg">
                    <option value=""><?= is_rtl() ? '-- جميع الحالات --' : '-- All Statuses --' ?></option>
                    <option value="Pending" <?= $status_filter === 'Pending' ? 'selected' : '' ?>><?= is_rtl() ? 'قيد المراجعة' : 'Pending' ?></option>
                    <option value="Approved" <?= $status_filter === 'Approved' ? 'selected' : '' ?>><?= is_rtl() ? 'معتمد / ساري' : 'Approved / Active' ?></option>
                    <option value="Repaid" <?= $status_filter === 'Repaid' ? 'selected' : '' ?>><?= is_rtl() ? 'مسدد بالكامل' : 'Repaid' ?></option>
                    <option value="Rejected" <?= $status_filter === 'Rejected' ? 'selected' : '' ?>><?= is_rtl() ? 'مرفوض' : 'Rejected' ?></option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1 rounded-lg fw-semibold">
                    <i class="fa-solid fa-filter me-1"></i> <?= is_rtl() ? 'تصفية' : 'Filter' ?>
                </button>
                <?php if (!empty($status_filter) || !empty($search_query)): ?>
                    <a href="advances.php" class="btn btn-outline-border btn-sm rounded-lg" title="<?= is_rtl() ? 'إلغاء التصفية' : 'Reset' ?>"><i class="fa-solid fa-rotate-left"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Advances Data Table -->
    <div class="card border border-border bg-card shadow-sm rounded-xl overflow-hidden">
        <div class="card-header bg-card py-3 px-4 border-bottom border-border d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold font-heading text-foreground mb-0">
                    <i class="fa-solid fa-file-invoice-dollar me-2 text-primary"></i><?= is_rtl() ? 'سجل السلف والقروض المالية' : 'Staff Salary Advances Ledger' ?>
                </h6>
                <span class="badge bg-secondary text-foreground border border-border rounded-pill px-2.5 py-1 small"><?= count($advances) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-secondary small text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="py-3"><?= is_rtl() ? 'الموظف' : 'Employee' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'مبلغ السلفة' : 'Loan Amount' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'مدة السداد' : 'Duration' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'القسط الشهري' : 'Monthly Cut' ?></th>
                        <th class="py-3" style="min-width: 150px;"><?= is_rtl() ? 'الرصيد المتبقي' : 'Remaining Balance' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'تاريخ الطلب' : 'Request Date' ?></th>
                        <th class="px-4 py-3 text-end"><?= is_rtl() ? 'إجراءات' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($advances)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted-foreground">
                                <i class="fa-solid fa-hand-holding-dollar fs-2 mb-2 d-block opacity-50"></i>
                                <?= is_rtl() ? 'لا توجد طلبات سلف أو قروض مسجلة تطابق الشروط.' : 'No salary advances or loan applications found.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($advances as $idx => $adv): ?>
                            <?php
                            $amt = floatval($adv['amount']);
                            $bal = floatval($adv['remaining_balance']);
                            $pct_repaid = ($amt > 0) ? round((($amt - $bal) / $amt) * 100) : 0;

                            $badge_class = 'bg-warning-subtle text-warning border border-warning-subtle';
                            if ($adv['status'] === 'Approved') $badge_class = 'bg-primary-subtle text-primary border border-primary-subtle';
                            elseif ($adv['status'] === 'Repaid') $badge_class = 'bg-success-subtle text-success border border-success-subtle';
                            elseif ($adv['status'] === 'Rejected') $badge_class = 'bg-danger-subtle text-danger border border-danger-subtle';
                            ?>
                            <tr>
                                <td class="px-4 py-3 fw-bold text-muted-foreground font-mono" style="font-size: 0.8rem;"><?= $adv['advance_id'] ?></td>
                                <td class="py-3">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="avatar-badge flex-shrink-0 bg-primary-subtle text-primary border border-primary-subtle rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 2.25rem; height: 2.25rem; font-size: 0.85rem;">
                                            <?= mb_substr($adv['employee_name'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-foreground small"><?= htmlspecialchars($adv['employee_name']) ?></div>
                                            <span class="text-muted-foreground font-mono" style="font-size: 0.72rem;"><?= htmlspecialchars($adv['employee_code']) ?> · <?= htmlspecialchars($adv['job_title'] ?? '') ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <div class="fw-bold text-foreground font-heading">$<?= number_format($amt, 2) ?></div>
                                    <?php if (!empty($adv['purpose'])): ?>
                                        <div class="small text-muted-foreground text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($adv['purpose']) ?>">
                                            <?= htmlspecialchars($adv['purpose']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3">
                                    <span class="badge bg-secondary text-foreground border border-border px-2.5 py-1 rounded-pill small">
                                        <i class="fa-solid fa-calendar-days me-1 text-primary"></i> <?= $adv['repayment_months'] ?> <?= is_rtl() ? 'أشهر' : 'mos' ?>
                                    </span>
                                </td>
                                <td class="py-3">
                                    <div class="fw-semibold text-danger font-heading">-$<?= number_format($adv['monthly_deduction'], 2) ?>/mo</div>
                                </td>
                                <td class="py-3">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="fw-bold text-foreground font-mono">$<?= number_format($bal, 2) ?></span>
                                        <span class="text-muted-foreground"><?= $pct_repaid ?>%</span>
                                    </div>
                                    <div class="progress bg-secondary rounded-pill" style="height: 6px;">
                                        <div class="progress-bar <?= $pct_repaid >= 100 ? 'bg-success' : 'bg-primary' ?> rounded-pill" role="progressbar" style="width: <?= $pct_repaid ?>%"></div>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <span class="badge <?= $badge_class ?> rounded-pill px-2.5 py-1">
                                        <?= htmlspecialchars($adv['status']) ?>
                                    </span>
                                    <?php if (!empty($adv['approver_name'])): ?>
                                        <div class="text-muted-foreground mt-1" style="font-size: 0.7rem;">
                                            By <?= htmlspecialchars($adv['approver_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3">
                                    <span class="text-muted-foreground font-mono small"><?= htmlspecialchars($adv['request_date']) ?></span>
                                </td>
                                <td class="px-4 py-3 text-end">
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($is_hr_admin && $adv['status'] === 'Pending'): ?>
                                            <a href="advances.php?action_advance=approve&id=<?= $adv['advance_id'] ?>" class="btn btn-outline-border btn-sm rounded-lg text-success" title="<?= is_rtl() ? 'اعتماد' : 'Approve' ?>" onclick="return confirm('Approve advance request of $<?= number_format($amt, 2) ?>?');">
                                                <i class="fa-solid fa-check"></i>
                                            </a>
                                            <a href="advances.php?action_advance=reject&id=<?= $adv['advance_id'] ?>" class="btn btn-outline-border btn-sm rounded-lg text-danger ms-1" title="<?= is_rtl() ? 'رفض' : 'Reject' ?>" onclick="return confirm('Reject this advance request?');">
                                                <i class="fa-solid fa-xmark"></i>
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($is_hr_admin && $adv['status'] === 'Approved' && $bal > 0): ?>
                                            <button type="button" class="btn btn-outline-border btn-sm rounded-lg text-primary ms-1" data-bs-toggle="modal" data-bs-target="#repayModal<?= $adv['advance_id'] ?>" title="<?= is_rtl() ? 'تسديد دفعة يدوية' : 'Record Payment' ?>">
                                                <i class="fa-solid fa-coins"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Repayment Modal -->
                                    <?php if ($is_hr_admin && $adv['status'] === 'Approved' && $bal > 0): ?>
                                    <div class="modal fade" id="repayModal<?= $adv['advance_id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content bg-card border border-border shadow-lg rounded-xl">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="record_repayment">
                                                    <input type="hidden" name="advance_id" value="<?= $adv['advance_id'] ?>">
                                                    <div class="modal-header border-bottom border-border py-3 px-4">
                                                        <h5 class="modal-title fw-bold text-foreground font-heading">
                                                            <i class="fa-solid fa-coins text-primary me-2"></i><?= is_rtl() ? 'تسجيل سداد دفعة سلفة' : 'Record Loan Repayment' ?> #<?= $adv['advance_id'] ?>
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>
                                                    <div class="modal-body p-4 text-start">
                                                        <div class="p-3 bg-secondary rounded-lg border border-border mb-3">
                                                            <div class="small fw-semibold text-foreground mb-1"><?= htmlspecialchars($adv['employee_name']) ?></div>
                                                            <div class="small text-muted-foreground"><?= is_rtl() ? 'الرصيد المتبقي المستحق:' : 'Outstanding Balance:' ?> <strong class="text-danger">$<?= number_format($bal, 2) ?></strong></div>
                                                        </div>
                                                        <div class="mb-3">
                                                            <label class="form-label small fw-semibold text-foreground"><?= is_rtl() ? 'مبلغ الدفعة ($)' : 'Payment Amount ($)' ?></label>
                                                            <input type="number" step="0.01" max="<?= $bal ?>" min="1" name="payment_amount" class="form-control bg-background text-foreground border-border rounded-lg" value="<?= min($bal, $adv['monthly_deduction']) ?>" required>
                                                            <div class="form-text small text-muted-foreground"><?= is_rtl() ? 'القسط الشهري المعتاد هو:' : 'Default monthly installment is:' ?> $<?= number_format($adv['monthly_deduction'], 2) ?></div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer border-top border-border bg-secondary/30 py-2.5 px-4">
                                                        <button type="button" class="btn btn-outline-border btn-sm px-3 rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                                                        <button type="submit" class="btn btn-success btn-sm px-4 fw-bold rounded-lg shadow-xs"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'تأكيد السداد' : 'Confirm Repayment' ?></button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: New Advance Request -->
<div class="modal fade" id="newAdvanceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border shadow-lg rounded-xl">
            <form method="POST">
                <input type="hidden" name="action" value="request_advance">
                <div class="modal-header border-bottom border-border py-3 px-4">
                    <h5 class="modal-title fw-bold text-foreground font-heading">
                        <i class="fa-solid fa-hand-holding-dollar text-primary me-2"></i><?= is_rtl() ? 'تقديم طلب سلفة مالية / قرض' : 'Apply for Salary Advance / Loan' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <?php if ($is_hr_admin): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold text-foreground"><?= is_rtl() ? 'الموظف' : 'Select Employee' ?> <span class="text-danger">*</span></label>
                            <select name="employee_id" class="form-select bg-background text-foreground border-border rounded-lg" required>
                                <option value=""><?= is_rtl() ? '-- اختر الموظف --' : '-- Choose Employee --' ?></option>
                                <?php foreach ($employees_list as $emp): ?>
                                    <option value="<?= $emp['employee_id'] ?>">
                                        <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['employee_code']) ?> - <?= htmlspecialchars($emp['job_title'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="employee_id" value="0">
                        <div class="p-3 bg-secondary rounded-lg border border-border small mb-3 text-foreground">
                            <i class="fa-solid fa-user-check me-1 text-primary"></i> <?= is_rtl() ? 'يتم التقديم تحت ملفك الشخصي كـ:' : 'Submitting under your staff profile:' ?> <strong><?= htmlspecialchars($full_name) ?></strong>
                        </div>
                    <?php endif; ?>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-foreground"><?= is_rtl() ? 'المبلغ المطلوب ($)' : 'Requested Amount ($)' ?> <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="10" name="amount" id="reqAmount" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="e.g. 500.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold text-foreground"><?= is_rtl() ? 'أشهر السداد' : 'Repayment Months' ?> <span class="text-danger">*</span></label>
                            <select name="repayment_months" id="reqMonths" class="form-select bg-background text-foreground border-border rounded-lg" required>
                                <option value="1">1 <?= is_rtl() ? 'شهر (دفعة واحدة)' : 'Month (Single Cut)' ?></option>
                                <option value="2">2 <?= is_rtl() ? 'شهرين' : 'Months' ?></option>
                                <option value="3" selected>3 <?= is_rtl() ? 'أشهر' : 'Months' ?></option>
                                <option value="6">6 <?= is_rtl() ? 'أشهر' : 'Months' ?></option>
                                <option value="12">12 <?= is_rtl() ? 'شهراً (سنة)' : 'Months (1 Year)' ?></option>
                            </select>
                        </div>
                    </div>

                    <div class="p-3 bg-secondary rounded-lg border border-border mb-3 d-flex justify-content-between align-items-center">
                        <span class="small text-muted-foreground"><?= is_rtl() ? 'الاستقطاع الشهري المقدر:' : 'Estimated Monthly Deduction:' ?></span>
                        <strong class="font-heading text-primary fs-5" id="estMonthly">$0.00 / mo</strong>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-foreground"><?= is_rtl() ? 'تاريخ الطلب' : 'Request Date' ?></label>
                        <input type="date" name="request_date" class="form-control bg-background text-foreground border-border rounded-lg" value="<?= date('Y-m-d') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold text-foreground"><?= is_rtl() ? 'سبب السلفة / ملاحظات' : 'Purpose / Notes' ?></label>
                        <textarea name="purpose" class="form-control bg-background text-foreground border-border rounded-lg" rows="3" placeholder="<?= is_rtl() ? 'سبب طلب السلفة الطارئة...' : 'Reason for salary advance or emergency loan...' ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-border bg-secondary/30 py-2.5 px-4">
                    <button type="button" class="btn btn-outline-border btn-sm px-3 rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold rounded-lg shadow-xs"><i class="fa-solid fa-paper-plane me-1"></i><?= is_rtl() ? 'إرسال الطلب' : 'Submit Advance Request' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const amtInput = document.getElementById('reqAmount');
    const mthInput = document.getElementById('reqMonths');
    const estDisplay = document.getElementById('estMonthly');

    function updateEst() {
        const amt = parseFloat(amtInput?.value) || 0;
        const mths = parseInt(mthInput?.value) || 1;
        if (amt > 0 && mths > 0) {
            const deduction = (amt / mths).toFixed(2);
            estDisplay.textContent = '$' + deduction + ' / mo';
        } else {
            estDisplay.textContent = '$0.00 / mo';
        }
    }

    amtInput?.addEventListener('input', updateEst);
    mthInput?.addEventListener('change', updateEst);
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
