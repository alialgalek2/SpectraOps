<?php
/**
 * HR & Finance Module - Automated Payroll Processing & Printable Payslips
 * Integrates with Personnel & Employees Sub-System (Teachers & Non-Portal Staff)
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'hr', 'finance', 'teacher']);

$user_role = Auth::role();
$user_id   = Auth::id();
$is_payroll_admin = in_array($user_role, ['admin', 'hr', 'finance']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$current_period = $_GET['period'] ?? date('F Y');

// -------------------------------------------------------------
// 1. Single-Click Automated Payroll Generation (All Employees)
// -------------------------------------------------------------
if ($is_payroll_admin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_payroll') {
    $period = trim($_POST['pay_period'] ?? $current_period);

    try {
        $pdo->beginTransaction();
        
        // Fetch all active employees (Portal and Non-Portal staff)
        $employees = $pdo->query("
            SELECT employee_id, user_id, employee_code, full_name, category, job_title, base_salary, payment_method 
            FROM employees 
            WHERE status = 'Active'
            ORDER BY category ASC, full_name ASC
        ")->fetchAll();

        $generated = 0;

        $checkStmt = $pdo->prepare("SELECT payroll_id FROM staff_payroll WHERE (employee_id = ? OR (staff_user_id = ? AND staff_user_id IS NOT NULL)) AND pay_period = ?");
        $insertStmt = $pdo->prepare("
            INSERT INTO staff_payroll (employee_id, staff_user_id, pay_period, base_salary, bonuses, deductions, child_tuition_deductions, payment_status, payment_method, disbursement_date)
            VALUES (?, ?, ?, ?, ?, ?, 0.00, 'Pending', ?, NULL)
        ");

        $advanceStmt = $pdo->prepare("
            SELECT advance_id, monthly_deduction, remaining_balance 
            FROM staff_advances 
            WHERE employee_id = ? AND status = 'Approved' AND remaining_balance > 0
            LIMIT 1
        ");

        foreach ($employees as $emp) {
            $checkStmt->execute([$emp['employee_id'], $emp['user_id'], $period]);
            if (!$checkStmt->fetch()) {
                $base = floatval($emp['base_salary']);
                if ($base <= 0) {
                    $base = in_array($emp['category'], ['Administrative']) ? 2800.00 : ($emp['category'] === 'Teacher' ? 1850.00 : 850.00);
                }

                // Standard allowances & statutory deductions
                $bonus = $emp['category'] === 'Teacher' ? 250.00 : ($emp['category'] === 'Administrative' ? 300.00 : 100.00);
                $deduct = round($base * 0.05, 2); // 5% tax/insurance

                // Check active salary advance deduction
                $advanceStmt->execute([$emp['employee_id']]);
                $activeAdv = $advanceStmt->fetch(PDO::FETCH_ASSOC);
                if ($activeAdv) {
                    $advCut = min(floatval($activeAdv['remaining_balance']), floatval($activeAdv['monthly_deduction']));
                    $deduct += $advCut;
                }

                $insertStmt->execute([$emp['employee_id'], $emp['user_id'], $period, $base, $bonus, $deduct, $emp['payment_method']]);
                $generated++;
            }
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = "Automated payroll processed! Generated $generated payslip records for $period across all academic and non-portal staff.";
        $_SESSION['flash_msg_type'] = "success";
        header("Location: payroll.php?period=" . urlencode($period));
        exit();
    } catch (\PDOException $e) {
        $pdo->rollBack();
        $msg = "Error processing payroll: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// -------------------------------------------------------------
// 2. Handle Disburse / Mark as Paid
// -------------------------------------------------------------
if ($is_payroll_admin && isset($_GET['disburse_id'])) {
    $pid = intval($_GET['disburse_id']);

    // Check if payroll record employee has active advance and update remaining balance
    $pRecord = $pdo->prepare("SELECT employee_id FROM staff_payroll WHERE payroll_id = ?");
    $pRecord->execute([$pid]);
    $empId = $pRecord->fetchColumn();
    if ($empId) {
        $advCheck = $pdo->prepare("SELECT advance_id, monthly_deduction, remaining_balance FROM staff_advances WHERE employee_id = ? AND status = 'Approved' AND remaining_balance > 0 LIMIT 1");
        $advCheck->execute([$empId]);
        $advRow = $advCheck->fetch(PDO::FETCH_ASSOC);
        if ($advRow) {
            $cut = min(floatval($advRow['remaining_balance']), floatval($advRow['monthly_deduction']));
            $newBal = max(0, floatval($advRow['remaining_balance']) - $cut);
            $newStatus = ($newBal <= 0) ? 'Repaid' : 'Approved';
            $pdo->prepare("UPDATE staff_advances SET remaining_balance = ?, status = ? WHERE advance_id = ?")->execute([$newBal, $newStatus, $advRow['advance_id']]);
        }
    }

    $pdo->prepare("UPDATE staff_payroll SET payment_status = 'Disbursed', disbursement_date = NOW() WHERE payroll_id = ?")->execute([$pid]);
    $_SESSION['flash_msg'] = "Payroll disbursement #$pid verified and marked as PAID.";
    $_SESSION['flash_msg_type'] = "success";
    header("Location: payroll.php?period=" . urlencode($current_period));
    exit();
}

// -------------------------------------------------------------
// 3. Fetch Payroll Records
// -------------------------------------------------------------
if ($user_role === 'teacher') {
    $payStmt = $pdo->prepare("
        SELECT p.*, 
               COALESCE(e.full_name, u.full_name) as staff_name,
               COALESCE(e.employee_code, CONCAT('USR-', u.user_id)) as emp_code,
               COALESCE(e.category, 'Teacher') as staff_category,
               COALESCE(e.job_title, 'Teacher') as staff_job_title,
               COALESCE(p.payment_method, e.payment_method, 'Bank Transfer') as pay_method,
               e.bank_name, e.bank_account_no
        FROM staff_payroll p
        LEFT JOIN employees e ON p.employee_id = e.employee_id
        LEFT JOIN users u ON p.staff_user_id = u.user_id
        WHERE p.staff_user_id = ? OR (e.user_id = ? AND e.user_id IS NOT NULL)
        ORDER BY p.created_at DESC
    ");
    $payStmt->execute([$user_id, $user_id]);
} else {
    $payStmt = $pdo->prepare("
        SELECT p.*, 
               COALESCE(e.full_name, u.full_name, 'Staff Member') as staff_name,
               COALESCE(e.employee_code, CONCAT('EMP-', p.payroll_id)) as emp_code,
               COALESCE(e.category, u.role, 'Support') as staff_category,
               COALESCE(e.job_title, u.role, 'Staff') as staff_job_title,
               COALESCE(p.payment_method, e.payment_method, 'Cash') as pay_method,
               e.bank_name, e.bank_account_no
        FROM staff_payroll p
        LEFT JOIN employees e ON p.employee_id = e.employee_id
        LEFT JOIN users u ON p.staff_user_id = u.user_id
        WHERE p.pay_period = ?
        ORDER BY staff_category ASC, staff_name ASC
    ");
    $payStmt->execute([$current_period]);
}
$payrolls = $payStmt->fetchAll();

// Total Summary Metrics
$totalDisbursed = 0;
$totalPending   = 0;
$totalNetVolume = 0;
foreach ($payrolls as $p) {
    $net = ($p['base_salary'] + $p['bonuses']) - ($p['deductions'] + $p['child_tuition_deductions']);
    $totalNetVolume += $net;
    if ($p['payment_status'] === 'Disbursed') $totalDisbursed += $net;
    else $totalPending += $net;
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('staff_payroll') ?> - HR &amp; Finance Hub</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .payslip-paper {
            background: white;
            border: 2px solid #2d3436;
            border-radius: 8px;
        }
        @media print {
            body * { visibility: hidden; }
            #printablePayslip, #printablePayslip * { visibility: visible; }
            #printablePayslip { position: absolute; left: 0; top: 0; width: 100%; }
            .modal-footer, .btn-close { display: none !important; }
        }
    </style>
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => __('staff_payroll') . ' & Compensation',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="employees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-users-gear me-1 text-primary"></i> ' . (is_rtl() ? 'دليل الموظفين' : 'Staff Directory') . '</a>',
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

    <!-- Hero Header -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2 class="fw-bold font-heading mb-1 text-white"><i class="fa-solid fa-money-check-dollar me-2"></i><?= __('staff_payroll') ?> &amp; Salary Ledger</h2>
                <p class="mb-0 text-white-50 small"><?= is_rtl() ? 'معالجة الرواتب الآلية لكافة الكوادر التعليمية، الإدارية، السائقين وعمال الخدمات مع طباعة قسائم الرواتب.' : 'Automated salary sheet processing, cash & bank transfers, allowances, and printable payslips for all employees.' ?></p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <!-- Period Selector -->
                <form method="GET" class="d-flex gap-2 align-items-center bg-white bg-opacity-15 px-3 py-2 rounded-lg border border-white border-opacity-25 shadow-xs">
                    <label class="fw-bold small text-white text-nowrap mb-0"><i class="fa-solid fa-calendar-days me-1 text-warning"></i><?= is_rtl() ? 'دورة الرواتب:' : 'Pay Period:' ?></label>
                    <select name="period" class="form-select form-select-sm fw-bold border-0 bg-white text-dark rounded-md shadow-xs" onchange="this.form.submit()" style="min-width: 140px;">
                        <?php 
                        for ($i = 0; $i < 6; $i++): 
                            $p = date('F Y', strtotime("-$i months"));
                        ?>
                            <option value="<?= $p ?>" <?= $p === $current_period ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endfor; ?>
                    </select>
                </form>

                <?php if ($is_payroll_admin): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="generate_payroll">
                        <input type="hidden" name="pay_period" value="<?= htmlspecialchars($current_period) ?>">
                        <button type="submit" class="btn btn-light text-primary fw-bold px-3 py-2 rounded-lg shadow-xs">
                            <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i> Auto-Generate Payroll
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 4-Column Responsive KPI Stats Cards Grid (Unified Dashboard Theme) -->
    <div class="row g-3 mb-4">
        <!-- 1. Total Payroll Volume -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= is_rtl() ? 'إجمالي مسير الرواتب' : 'Total Payroll Volume' ?>
                    </div>
                    <div class="action-icon-box icon-box-primary" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                </div>
                <div class="stat-value my-1">$<?= number_format($totalNetVolume, 2) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend text-primary">
                        <i class="fa-solid fa-calendar-check"></i>
                        <span><?= htmlspecialchars($current_period) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Disbursed (Paid Out) -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= is_rtl() ? 'الرواتب المصروفة' : 'Disbursed (Paid Out)' ?>
                    </div>
                    <div class="action-icon-box icon-box-success" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                </div>
                <div class="stat-value my-1 text-success">$<?= number_format($totalDisbursed, 2) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend text-success">
                        <i class="fa-solid fa-check-double"></i>
                        <span><?= $totalNetVolume > 0 ? round(($totalDisbursed / $totalNetVolume) * 100, 1) : 0 ?>% <?= is_rtl() ? 'تم الصرف والتحويل' : 'Settled & Transferred' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Pending Disbursement -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= is_rtl() ? 'بانتظار الصرف' : 'Pending Disbursement' ?>
                    </div>
                    <div class="action-icon-box icon-box-warning" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                </div>
                <div class="stat-value my-1 text-warning">$<?= number_format($totalPending, 2) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend text-warning">
                        <i class="fa-solid fa-clock"></i>
                        <span><?= is_rtl() ? 'مستحقات قيد المراجعة' : 'Awaiting Disbursement' ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4. Staff on Payroll -->
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-modern h-100">
                <div class="d-flex align-items-start justify-content-between mb-2">
                    <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                        <?= is_rtl() ? 'الكوادر بالمسير' : 'Staff on Payroll' ?>
                    </div>
                    <div class="action-icon-box icon-box-purple" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-users"></i>
                    </div>
                </div>
                <div class="stat-value my-1 text-purple"><?= count($payrolls) ?></div>
                <div class="d-flex align-items-center justify-content-between mt-2">
                    <div class="stat-trend text-purple">
                        <i class="fa-solid fa-user-check"></i>
                        <span><?= is_rtl() ? 'سجلات الكوادر المستحقة' : 'Active Staff Records' ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payroll Records Table -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
            <h5 class="fw-bold mb-0 text-foreground"><i class="fa-solid fa-receipt me-2 text-primary"></i>Staff Salary Ledger &amp; Payslips (<?= count($payrolls) ?> Records)</h5>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <form method="GET" class="d-flex align-items-center gap-2 m-0">
                    <label class="fw-semibold small text-muted-foreground text-nowrap mb-0 d-none d-md-inline"><?= is_rtl() ? 'الدورة:' : 'Cycle:' ?></label>
                    <select name="period" class="form-select form-select-sm fw-semibold border-border bg-card text-foreground" onchange="this.form.submit()">
                        <?php 
                        for ($i = 0; $i < 6; $i++): 
                            $p = date('F Y', strtotime("-$i months"));
                        ?>
                            <option value="<?= $p ?>" <?= $p === $current_period ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endfor; ?>
                    </select>
                </form>
                <a href="employees.php" class="btn btn-outline-border btn-sm fw-bold"><i class="fa-solid fa-user-plus me-1 text-primary"></i> Manage Staff Profiles</a>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width: 8%;">Code</th>
                        <th style="width: 22%;">Personnel Full Name</th>
                        <th style="width: 14%;">Category &amp; Role</th>
                        <th style="width: 10%;">Base Salary</th>
                        <th style="width: 10%;">Allowances</th>
                        <th style="width: 10%;">Deductions</th>
                        <th style="width: 10%;">Net Salary</th>
                        <th style="width: 10%;" class="text-center">Status &amp; Method</th>
                        <th style="width: 6%;" class="text-center">Payslip</th>
                        <?php if ($is_payroll_admin): ?>
                            <th style="width: 10%;" class="text-center">Disburse</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payrolls)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-5">
                                <i class="fa-solid fa-wallet fs-1 d-block mb-2 text-secondary"></i>
                                No payroll records found for <?= htmlspecialchars($current_period) ?>.<br>
                                Click <strong>"Auto-Generate <?= htmlspecialchars($current_period) ?> Payroll"</strong> above to compute monthly compensation.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payrolls as $p): ?>
                            <?php $net = ($p['base_salary'] + $p['bonuses']) - ($p['deductions'] + $p['child_tuition_deductions']); ?>
                            <tr>
                                <td><code><?= htmlspecialchars($p['emp_code']) ?></code></td>
                                <td class="fw-bold">
                                    <span class="text-dark"><?= htmlspecialchars($p['staff_name']) ?></span>
                                    <small class="d-block text-muted"><?= htmlspecialchars($p['staff_job_title']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($p['staff_category']) ?></span>
                                </td>
                                <td>$<?= number_format($p['base_salary'], 2) ?></td>
                                <td class="text-success">+$<?= number_format($p['bonuses'], 2) ?></td>
                                <td class="text-danger">-$<?= number_format($p['deductions'], 2) ?></td>
                                <td class="fw-bold fs-6 text-primary">$<?= number_format($net, 2) ?></td>
                                <td class="text-center">
                                    <?php if ($p['payment_status'] === 'Disbursed'): ?>
                                        <span class="badge bg-success"><i class="fa-solid fa-circle-check me-1"></i> Disbursed</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><i class="fa-solid fa-clock me-1"></i> Pending</span>
                                    <?php endif; ?>
                                    <small class="d-block text-muted"><?= htmlspecialchars($p['pay_method']) ?></small>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-outline-dark btn-sm fw-bold" onclick="showPayslip(<?= htmlspecialchars(json_encode($p)) ?>)">
                                        <i class="fa-solid fa-print"></i>
                                    </button>
                                </td>
                                <?php if ($is_payroll_admin): ?>
                                    <td class="text-center">
                                        <?php if ($p['payment_status'] === 'Pending'): ?>
                                            <a href="?disburse_id=<?= $p['payroll_id'] ?>" class="btn btn-sm btn-success fw-bold" onclick="return confirm('Authorize salary disbursement for <?= htmlspecialchars(addslashes($p['staff_name'])) ?>?');">
                                                <i class="fa-solid fa-money-bill-transfer me-1"></i> Pay
                                            </a>
                                        <?php else: ?>
                                            <small class="text-muted"><i class="fa-solid fa-calendar-check text-success me-1"></i> <?= date('M d', strtotime($p['disbursement_date'])) ?></small>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Payslip Modal -->
<div class="modal fade" id="payslipModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-body p-4" id="printablePayslip">
                <div class="payslip-paper p-4">
                    <div class="text-center border-bottom pb-3 mb-3">
                        <h4 class="fw-bold text-dark mb-0 text-uppercase">OFFICIAL SALARY DISBURSEMENT PAYSLIP</h4>
                        <p class="text-muted small mb-0">Algale International Academy - HR &amp; Personnel Ledger</p>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <small class="text-muted d-block text-uppercase fw-bold">Employee Name &amp; Code</small>
                            <strong class="fs-5 text-dark" id="psName">—</strong>
                            <div class="text-muted small" id="psJob">—</div>
                        </div>
                        <div class="col-6 text-end">
                            <small class="text-muted d-block text-uppercase fw-bold">Pay Period &amp; Method</small>
                            <strong class="fs-5 text-primary" id="psPeriod">—</strong>
                            <div class="text-muted small" id="psMethod">—</div>
                        </div>
                    </div>
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Earnings &amp; Allowances</th>
                                <th class="text-end">Amount</th>
                                <th>Deductions &amp; Taxes</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Base Monthly Salary</td>
                                <td class="text-end" id="psBase">$0.00</td>
                                <td>Statutory Income Tax &amp; Insurance</td>
                                <td class="text-end text-danger" id="psDeduct">$0.00</td>
                            </tr>
                            <tr>
                                <td>Housing, Transport &amp; Duties Allowance</td>
                                <td class="text-end" id="psBonus">$0.00</td>
                                <td>Tuition / Other Deductions</td>
                                <td class="text-end text-danger">$0.00</td>
                            </tr>
                            <tr class="table-primary fw-bold">
                                <td colspan="3" class="text-uppercase">Total Net Disbursed Salary</td>
                                <td class="text-end fs-5 text-primary" id="psNet">$0.00</td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="d-flex justify-content-between text-muted small pt-3 border-top mt-4">
                        <span>Authorized By: HR &amp; Finance Comptroller</span>
                        <span>Disbursement Status: <strong>OFFICIALLY VERIFIED</strong></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                <button type="button" class="btn btn-dark fw-bold" onclick="window.print();"><i class="fa-solid fa-print me-1"></i> Print Payslip</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showPayslip(data) {
    document.getElementById('psName').innerText = data.staff_name + ' (' + data.emp_code + ')';
    document.getElementById('psJob').innerText = data.staff_category + ' — ' + data.staff_job_title;
    document.getElementById('psPeriod').innerText = data.pay_period;
    document.getElementById('psMethod').innerText = 'Disbursement Method: ' + data.pay_method;
    document.getElementById('psBase').innerText = '$' + parseFloat(data.base_salary).toFixed(2);
    document.getElementById('psBonus').innerText = '+$' + parseFloat(data.bonuses).toFixed(2);
    document.getElementById('psDeduct').innerText = '-$' + parseFloat(data.deductions).toFixed(2);

    const net = (parseFloat(data.base_salary) + parseFloat(data.bonuses)) - (parseFloat(data.deductions) + parseFloat(data.child_tuition_deductions));
    document.getElementById('psNet').innerText = '$' + net.toFixed(2);

    new bootstrap.Modal(document.getElementById('payslipModal')).show();
}
</script>
</body>
</html>
