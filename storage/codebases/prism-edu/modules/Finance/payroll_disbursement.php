<?php
/**
 * modules/Finance/payroll_disbursement.php
 * Payroll Batch Disbursement, Bank Transfer Sheet & Cash Voucher Generator
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'finance']);

$user_role = Auth::role();
$user_id   = Auth::id();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "success";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Fetch Active Funding Accounts & Active Employees
$accounts = $pdo->query("SELECT * FROM finance_accounts ORDER BY account_name ASC")->fetchAll();
$employees = $pdo->query("SELECT * FROM employees WHERE status = 'Active' ORDER BY category ASC, full_name ASC")->fetchAll();

// Handle Disbursement Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_payroll') {
    $source_account_id  = intval($_POST['source_account_id'] ?? 0);
    $pay_period         = trim($_POST['pay_period'] ?? date('F Y'));
    $selected_employees = $_POST['employee_ids'] ?? [];

    if ($source_account_id > 0 && !empty($selected_employees)) {
        try {
            $pdo->beginTransaction();

            // 1. Verify source account balance
            $accStmt = $pdo->prepare("SELECT account_id, current_balance, account_name, account_type, currency FROM finance_accounts WHERE account_id = ?");
            $accStmt->execute([$source_account_id]);
            $acc = $accStmt->fetch();

            if (!$acc) {
                throw new Exception("Selected funding account was not found.");
            }

            $total_payout = 0.00;
            $payout_records = [];

            foreach ($selected_employees as $emp_id) {
                $amtStmt = $pdo->prepare("
                    SELECT employee_id, user_id, employee_code, full_name, category, job_title, 
                           base_salary, bank_name, 
                           COALESCE(bank_account_number, bank_account_no) as bank_account_number, 
                           payment_method 
                    FROM employees 
                    WHERE employee_id = ? AND status = 'Active'
                ");
                $amtStmt->execute([$emp_id]);
                $emp = $amtStmt->fetch();
                if ($emp) {
                    $base = floatval($emp['base_salary']);
                    $total_payout += $base;
                    $payout_records[] = $emp;
                }
            }

            if ($acc['current_balance'] < $total_payout) {
                throw new Exception("Insufficient funds in '{$acc['account_name']}'! Available: " . number_format($acc['current_balance'], 2) . " {$acc['currency']}, Required: " . number_format($total_payout, 2) . " {$acc['currency']}.");
            }

            // 2. Deduct from school account balance (Bank Account or Cash Safe)
            $new_balance = $acc['current_balance'] - $total_payout;
            $updAcc = $pdo->prepare("UPDATE finance_accounts SET current_balance = ? WHERE account_id = ?");
            $updAcc->execute([$new_balance, $source_account_id]);

            // 3. Record transactions in financial ledger for each staff member
            $transStmt = $pdo->prepare("
                INSERT INTO financial_transactions (account_id, transaction_type, category, amount, reference_id, description, transaction_date, recorded_by)
                VALUES (?, 'Payroll', ?, ?, ?, ?, CURDATE(), ?)
            ");

            // Also update staff_payroll table status if present
            $updStaffPay = $pdo->prepare("
                UPDATE staff_payroll 
                SET payment_status = 'Disbursed', disbursement_date = NOW(), payment_method = ?
                WHERE (employee_id = ? OR (staff_user_id = ? AND staff_user_id IS NOT NULL)) AND pay_period = ?
            ");

            foreach ($payout_records as $rec) {
                $isCash = ($acc['account_type'] === 'Cash') || empty($rec['bank_account_number']) || ($rec['payment_method'] === 'Cash');
                $catName = $isCash ? 'Staff Salary Disbursement (Cash)' : 'Staff Salary Disbursement (Bank Transfer)';
                $methodTag = $isCash ? "(Cash Payout - Physical Vault)" : "(Bank Wire - " . ($rec['bank_name'] ?: 'Bank') . ")";
                $desc = "Salary Payout for $pay_period - " . $rec['full_name'] . " $methodTag";

                $transStmt->execute([
                    $source_account_id, 
                    $catName,
                    $rec['base_salary'], 
                    $rec['employee_id'], 
                    $desc, 
                    $user_id
                ]);

                $pMethod = $isCash ? 'Cash' : 'Bank Transfer';
                $updStaffPay->execute([$pMethod, $rec['employee_id'], $rec['user_id'], $pay_period]);
            }

            $pdo->commit();
            $_SESSION['flash_msg'] = "Payroll successfully executed! Total of <strong>" . number_format($total_payout, 2) . " " . htmlspecialchars($acc['currency']) . "</strong> disbursed from {$acc['account_name']} for " . count($payout_records) . " staff members.";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: payroll_disbursement.php");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Payroll Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Please select a funding source account and at least one staff member.";
        $msg_type = "danger";
    }
}

// Calculate summary metrics
$sumBaseSalary = 0;
$bankStaffCount = 0;
$cashStaffCount = 0;

foreach ($employees as $e) {
    $sumBaseSalary += $e['base_salary'];
    $hasBank = !empty($e['bank_account_number']) || !empty($e['bank_account_no']);
    if ($hasBank && $e['payment_method'] !== 'Cash') {
        $bankStaffCount++;
    } else {
        $cashStaffCount++;
    }
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'مسير التحويلات البنكية وصرف الرواتب' : 'Payroll & Bank Disbursement Hub' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .filter-btn.active {
            background-color: var(--primary) !important;
            color: white !important;
        }
        .voucher-box {
            border: 2px dashed var(--border, #2d3436);
            background: var(--card, white);
            border-radius: var(--radius-md, 8px);
        }
        @media print {
            body { background: white !important; }
            .no-print, nav, .btn, form .no-print-section { display: none !important; }
            .card-custom { border: none !important; box-shadow: none !important; }
            #printableBankSheet { display: block !important; }
            #voucherModalBody { display: block !important; }
        }
    </style>
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<div class="no-print">
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'إدارة الصرف والتحويلات البنكية' : 'Payroll & Bank Disbursement',
    'back_url' => 'fees_portal.php',
    'extra_nav_items' => []
]) ?>
</div>

<div class="container-fluid px-4 py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm mb-4 fw-bold no-print" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="p-4 p-md-5 mb-4 hero-banner rounded-xl shadow-sm no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 mb-2">
                    <span class="badge rounded-full font-medium px-3 py-1 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                        <i class="fa-solid fa-building-columns text-warning me-1"></i> Institutional Banking &amp; Cash Payouts
                    </span>
                </div>
                <h2 class="fw-bold font-heading mb-1 text-white">
                    <i class="fa-solid fa-money-check-dollar me-2"></i><?= is_rtl() ? 'جدول التحويلات البنكية وصرف رواتب الكادر' : 'Payroll & Bank Disbursement Sheet' ?>
                </h2>
                <p class="mb-0 text-white-50 small">
                    <?= is_rtl() ? 'مراجعة بيانات الحسابات البنكية للكوادر، والخصم التلقائي من الخزينة مع تصدير جدول معتمد للبنك وسندات صرف النقدية.' : 'Review staff financial records, verify individual bank details, deduct from treasury accounts, and export ready-to-use bank transfer schedules or cash vouchers.' ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-light fw-bold text-dark px-3 py-2 shadow-sm rounded-lg" onclick="window.print();">
                    <i class="fa-solid fa-print me-1 text-primary"></i> <?= is_rtl() ? 'طباعة كشف البنك' : 'Print Bank Transfer Sheet' ?>
                </button>
            </div>
        </div>
    </div>

    <form method="POST" action="">
        <input type="hidden" name="action" value="process_payroll">

        <!-- Funding Source Settings -->
        <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4 no-print-section">
            <h5 class="fw-bold font-heading text-foreground mb-3">
                <i class="fa-solid fa-sliders me-2 text-primary"></i>1. Funding Source &amp; Period Settings
            </h5>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Funding Bank / Cash Safe Channel <span class="text-danger">*</span></label>
                    <select name="source_account_id" id="fundingSourceSelect" class="form-select fw-bold" required onchange="updateAccountHint(this)">
                        <option value="">-- Choose Liquidity Channel --</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?= $acc['account_id'] ?>" data-type="<?= $acc['account_type'] ?>" data-bal="<?= $acc['current_balance'] ?>" data-cur="<?= $acc['currency'] ?>">
                                <?= htmlspecialchars($acc['account_name']) ?> [<?= $acc['account_type'] ?>] (Balance: <?= number_format($acc['current_balance'], 2) ?> <?= htmlspecialchars($acc['currency']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div id="accountTypeHint" class="form-text small mt-1 text-muted">
                        Select a commercial bank account for wire batches or a Cash Safe for physical note disbursements.
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-uppercase text-secondary">Pay Period / Month Reference <span class="text-danger">*</span></label>
                    <input type="text" name="pay_period" class="form-control fw-bold" placeholder="E.g., August 2026" value="<?= date('F Y') ?>" required>
                </div>
            </div>
        </div>

        <!-- Staff Payout List -->
        <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <div>
                    <h5 class="fw-bold font-heading text-foreground mb-0">
                        <i class="fa-solid fa-users me-2 text-success"></i>2. Staff Payout Selection &amp; Bank Transfer Details (<?= count($employees) ?> Active Staff)
                    </h5>
                    <small class="text-muted-foreground">
                        Total Pool: <strong>$<?= number_format($sumBaseSalary, 2) ?></strong> | 
                        <span class="text-primary fw-bold"><?= $bankStaffCount ?> Bank Wire</span> | 
                        <span class="text-warning fw-bold"><?= $cashStaffCount ?> Cash Envelope</span>
                    </small>
                </div>
                <div class="d-flex gap-2 no-print">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="filterPayoutRows('all')">All (<?= count($employees) ?>)</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="filterPayoutRows('bank')">Bank (<?= $bankStaffCount ?>)</button>
                    <button type="button" class="btn btn-sm btn-outline-warning text-dark" onclick="filterPayoutRows('cash')">Cash (<?= $cashStaffCount ?>)</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll(true)"><i class="fa-solid fa-check-double me-1"></i> Select All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleSelectAll(false)"><i class="fa-solid fa-xmark me-1"></i> Deselect All</button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th width="40px" class="no-print">
                                <input type="checkbox" id="selectAllCheckbox" checked onclick="toggleSelectAll(this.checked)">
                            </th>
                            <th>Staff Code</th>
                            <th>Personnel Full Name</th>
                            <th>Category</th>
                            <th>Bank Name / Payout Method</th>
                            <th>Account / Payout Details</th>
                            <th class="text-end">Base Salary</th>
                            <th class="text-center no-print" style="width: 10%;">Voucher</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">No active personnel registered in the HR system.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($employees as $emp): ?>
                                <?php 
                                $accNo = $emp['bank_account_number'] ?: ($emp['bank_account_no'] ?? '');
                                $has_bank = !empty($accNo) && ($emp['payment_method'] !== 'Cash');
                                ?>
                                <tr class="payout-row" data-method="<?= $has_bank ? 'bank' : 'cash' ?>">
                                    <td class="no-print">
                                        <input type="checkbox" name="employee_ids[]" value="<?= $emp['employee_id'] ?>" class="emp-checkbox" checked>
                                    </td>
                                    <td><code><?= htmlspecialchars($emp['employee_code'] ?: ('EMP-' . $emp['employee_id'])) ?></code></td>
                                    <td class="fw-bold text-dark">
                                        <?= htmlspecialchars($emp['full_name']) ?>
                                        <small class="d-block text-muted"><?= htmlspecialchars($emp['job_title'] ?? $emp['category']) ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary"><?= htmlspecialchars($emp['category']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($has_bank): ?>
                                            <span class="text-dark fw-bold"><i class="fa-solid fa-building-columns text-primary me-1"></i> <?= htmlspecialchars($emp['bank_name'] ?: 'Bank Khartoum') ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark px-2 py-1"><i class="fa-solid fa-money-bill-1 me-1"></i> Cash Payout</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($has_bank): ?>
                                            <code><?= htmlspecialchars($accNo) ?></code>
                                        <?php else: ?>
                                            <em class="text-muted small">No bank account (Paid in Cash)</em>
                                        <?php endif; ?>
                                    </td>
                                    <td class="fw-bold text-end text-success fs-6">$<?= number_format($emp['base_salary'], 2) ?></td>
                                    <td class="text-center no-print">
                                        <?php if (!$has_bank): ?>
                                            <button type="button" class="btn btn-sm btn-outline-warning text-dark fw-bold" onclick="showCashVoucher(<?= htmlspecialchars(json_encode($emp)) ?>)">
                                                <i class="fa-solid fa-receipt me-1"></i> Receipt
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">Wire Advice</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top no-print">
                <a href="fees_portal.php" class="btn btn-secondary px-4 fw-bold">
                    <i class="fa-solid fa-arrow-left me-1"></i> Back to Finance Hub
                </a>
                <button type="submit" class="btn btn-success fw-bold px-5 py-2 shadow fs-5" onclick="return confirm('Are you sure you want to process and disburse payroll for all selected staff members? This will automatically update your account ledger balances.');">
                    <i class="fa-solid fa-file-invoice-dollar me-2"></i> Execute &amp; Disburse Payroll Batch
                </button>
            </div>
        </div>
    </form>
</div>

<!-- CASH RECEIPT VOUCHER MODAL -->
<div class="modal fade" id="cashVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-md">
        <div class="modal-content border-0 shadow">
            <div class="modal-body p-4" id="voucherModalBody">
                <div class="voucher-box p-4">
                    <div class="text-center border-bottom pb-2 mb-3">
                        <h5 class="fw-bold text-dark mb-0 text-uppercase">CASH SALARY PAYMENT VOUCHER</h5>
                        <small class="text-muted">Algale International Academy - Cash Safe Disbursement</small>
                    </div>

                    <div class="row g-2 small mb-3">
                        <div class="col-6">
                            <span class="text-muted d-block">Employee Code:</span>
                            <strong id="vCode" class="text-dark">—</strong>
                        </div>
                        <div class="col-6 text-end">
                            <span class="text-muted d-block">Date:</span>
                            <strong><?= date('d M Y') ?></strong>
                        </div>
                        <div class="col-12">
                            <span class="text-muted d-block">Staff Full Name:</span>
                            <strong id="vName" class="fs-6 text-primary">—</strong>
                        </div>
                        <div class="col-6">
                            <span class="text-muted d-block">Category / Job:</span>
                            <span id="vJob">—</span>
                        </div>
                        <div class="col-6 text-end">
                            <span class="text-muted d-block">Pay Period:</span>
                            <span id="vPeriod"><?= date('F Y') ?></span>
                        </div>
                    </div>

                    <div class="p-3 bg-light rounded text-center mb-3 border">
                        <small class="text-muted text-uppercase fw-bold d-block">Net Cash Disbursed</small>
                        <h3 class="fw-bold text-success mb-0" id="vAmount">$0.00</h3>
                        <small class="text-muted">(Paid directly in physical currency from school safe)</small>
                    </div>

                    <div class="pt-3 border-top small">
                        <div class="mb-4">
                            <label class="form-label fw-bold d-block mb-1">Employee Acknowledgement &amp; Signature:</label>
                            <div class="text-muted fst-italic">"I hereby acknowledge receipt of the full salary amount in cash."</div>
                            <div class="mt-3">Signature: _______________________________</div>
                        </div>
                        <div class="d-flex justify-content-between text-muted pt-2 border-top">
                            <span>Disbursed By: <strong>Vault Cashier</strong></span>
                            <span>Status: <strong>PAID IN FULL</strong></span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-light no-print">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-dark fw-bold" onclick="window.print();"><i class="fa-solid fa-print me-1"></i> Print Cash Voucher</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleSelectAll(status) {
    const checkboxes = document.querySelectorAll('.emp-checkbox');
    checkboxes.forEach(cb => cb.checked = status);
    const main = document.getElementById('selectAllCheckbox');
    if (main) main.checked = status;
}

function filterPayoutRows(type) {
    const rows = document.querySelectorAll('.payout-row');
    rows.forEach(r => {
        if (type === 'all' || r.getAttribute('data-method') === type) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}

function updateAccountHint(select) {
    const opt = select.options[select.selectedIndex];
    const hint = document.getElementById('accountTypeHint');
    if (!opt.value) {
        hint.innerHTML = 'Select a commercial bank account for wire batches or a Cash Safe for physical note disbursements.';
        return;
    }
    const type = opt.getAttribute('data-type');
    if (type === 'Cash') {
        hint.innerHTML = '<span class="text-success fw-bold"><i class="fa-solid fa-cash-register me-1"></i> Cash Safe Mode:</span> Funds will be deducted from your physical cash balance, and logged as cash salary vouchers.';
    } else {
        hint.innerHTML = '<span class="text-primary fw-bold"><i class="fa-solid fa-building-columns me-1"></i> Bank Wire Mode:</span> Funds will be deducted from institutional commercial account balance, creating electronic transfer ledger records.';
    }
}

function showCashVoucher(emp) {
    document.getElementById('vCode').innerText = emp.employee_code || ('EMP-' + emp.employee_id);
    document.getElementById('vName').innerText = emp.full_name;
    document.getElementById('vJob').innerText = emp.category + ' (' + (emp.job_title || emp.category) + ')';
    document.getElementById('vAmount').innerText = '$' + parseFloat(emp.base_salary).toFixed(2);
    new bootstrap.Modal(document.getElementById('cashVoucherModal')).show();
}
</script>
</body>
</html>
