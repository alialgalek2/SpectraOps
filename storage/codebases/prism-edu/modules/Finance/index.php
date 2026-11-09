<?php
/**
 * Finance Module - Central Treasury & Financial Operations Hub
 * (Core Umbrella Architecture - LMS Layout & Unified Theme)
 */

require_once __DIR__ . '/../../core/init.php';

// Security: Allow Admins, Finance Officers, and HR
Auth::requireRole(['admin', 'school_admin', 'finance', 'hr']);

$user_role = Auth::role();
$full_name = Auth::fullName();
$success = "";
$error = "";

// Handle Bank Transaction Verification (Reconciliation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_transaction'])) {
    $tx_id = $_POST['transaction_id'];
    $action = $_POST['action_type']; // 'Verified' or 'Rejected'

    try {
        $pdo->beginTransaction();

        // Fetch transaction details
        $stmt = $pdo->prepare("SELECT * FROM bank_transactions WHERE transaction_id = ?");
        $stmt->execute([$tx_id]);
        $tx = $stmt->fetch();

        if ($tx && $tx['status'] === 'Pending Verification') {
            if ($action === 'Verified') {
                // Update transaction status
                $upd = $pdo->prepare("UPDATE bank_transactions SET status = 'Verified' WHERE transaction_id = ?");
                $upd->execute([$tx_id]);

                // Update bank account balance
                $bal = $pdo->prepare("UPDATE bank_accounts SET current_balance = current_balance + ? WHERE bank_id = ?");
                $bal->execute([$tx['amount'], $tx['bank_id']]);

                // If tied to an invoice, mark invoice as Paid
                if (!empty($tx['invoice_id'])) {
                    $inv = $pdo->prepare("UPDATE fee_invoices SET status = 'Paid' WHERE invoice_id = ?");
                    $inv->execute([$tx['invoice_id']]);
                }
                $success = is_rtl() ? "تم تأكيد الحوالة المصرفية وقيد الإيراد في السجل المالي بنجاح!" : "Bank transaction successfully verified and revenue posted to ledger!";
            } else {
                $upd = $pdo->prepare("UPDATE bank_transactions SET status = 'Rejected' WHERE transaction_id = ?");
                $upd->execute([$tx_id]);
                $success = is_rtl() ? "تم رفض التحويل المصرفي بنجاح." : "Transaction marked as rejected.";
            }
        }
        $pdo->commit();
    } catch (\Exception $e) {
        $pdo->rollBack();
        $error = "Reconciliation Error: " . $e->getMessage();
    }
}

// Fetch Bank Accounts Overview & Metrics
$banks = [];
$total_bank_balance = 0.0;
try {
    $banks = $pdo->query("SELECT * FROM bank_accounts")->fetchAll();
    foreach ($banks as $b) {
        $total_bank_balance += (float)($b['current_balance'] ?? 0);
    }
} catch (Exception $e) {}

// Fetch Pending Bank Transactions
$pending_txs = [];
try {
    $pending_txs = $pdo->query("
        SELECT t.*, u.full_name as student_name 
        FROM bank_transactions t 
        LEFT JOIN users u ON t.student_id = u.user_id 
        WHERE t.status = 'Pending Verification' 
        ORDER BY t.transaction_date DESC
    ")->fetchAll();
} catch (Exception $e) {}

$pending_count = count($pending_txs);

// Extra Live Metrics
$unpaid_invoices_count = 0;
$today_collections = 0.0;
try {
    $unpaid_invoices_count = (int)$pdo->query("SELECT COUNT(*) FROM fee_invoices WHERE status != 'Paid'")->fetchColumn();
    $today_collections = (float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'مركز العمليات المالية والخزينة' : 'Finance & Treasury Hub' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .admin-action-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-xl, 1rem);
            background: var(--card);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .admin-action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.08);
            color: inherit;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'مركز العمليات المالية والخزينة' : 'Finance & Treasury Hub',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => [
        '<a href="fees_portal.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-building-columns me-1 text-primary"></i> ' . (is_rtl() ? 'الخزينة' : 'Treasury') . '</a>',
        '<a href="fees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-receipt me-1 text-info"></i> ' . (is_rtl() ? 'الفواتير' : 'Invoices') . '</a>',
        '<a href="payments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-credit-card me-1 text-success"></i> ' . (is_rtl() ? 'المدفوعات' : 'Payments') . '</a>',
        '<a href="daily_settlement.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-cash-register me-1 text-warning"></i> ' . (is_rtl() ? 'الإقفال' : 'Settlement') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Flash Messages -->
    <?php if (!empty($success)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-wallet text-primary me-2"></i><?= is_rtl() ? 'الإدارة المالية والخزينة المركزية' : 'Finance & Treasury Management Hub' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'إصدار الفواتير وهياكل الرسوم، متابعة التحصيل والمدفوعات، مطابقة الحوالات البنكية، وصرف مسيرات الرواتب.' : 'Fee structures, student tuition invoicing, collection receipts, bank reconciliation, and payroll disbursements.' ?>
            </p>
        </div>
        <div>
            <span class="badge bg-card border border-border text-foreground px-3 py-2 fs-6 shadow-sm rounded-xl">
                <i class="fa-solid fa-user-circle text-primary me-1"></i> <?= htmlspecialchars($full_name) ?> <span class="badge bg-primary-subtle text-primary ms-1"><?= ucfirst($user_role) ?></span>
            </span>
        </div>
    </div>

    <!-- Live Metrics Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-vault"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground">$<?= number_format($total_bank_balance, 2) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'سيولة الخزينة والحسابات' : 'Vault Liquidity' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $pending_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'حوالات بانتظار التأكيد' : 'Pending Reconciliation' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $unpaid_invoices_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'فواتير قيد التحصيل' : 'Unpaid Invoices' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-cash-register"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground">$<?= number_format($today_collections, 2) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'متحصلات اليوم' : 'Today\'s Collections' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1: Treasury & Cashflow Operations -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-building-columns text-warning me-2"></i><?= is_rtl() ? 'خزينة النقدية والعمليات المصرفية' : 'Treasury & Cashflow Operations' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- 1. Treasury Desk & Cashflow Portal -->
        <div class="col-md-4 col-sm-6">
            <a href="fees_portal.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'لوحة الخزينة النقدية' : 'Treasury Desk' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'السيولة والمطابقة اليومية' : 'Cashflow & Bank Overview' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'متابعة السيولة النقدية للحسابات، التحويلات الواردة، ومطابقة قيود الخزينة.' : 'Real-time vault cashflows, bank account liquidity overview, and reconciliation controls.' ?></p>
            </a>
        </div>

        <!-- 2. Daily Cashier Settlement Sheet -->
        <div class="col-md-4 col-sm-6">
            <a href="daily_settlement.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-cash-register"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'المطابقة والإغلاق اليومي' : 'Daily Cashier Settlement' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'إقفال الصندوق والخزينة' : 'Day-End Audit & Ledger' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تدقيق المقبوضات النقدية والمصرفية اليومية، المصروفات المنصرفة، وتوليد القيود المحاسبية.' : 'Audit daily fee collections, reconcile POS/cash vault inflows with operational expenses, and record ledger snapshots.' ?></p>
            </a>
        </div>

        <!-- 3. Payroll Disbursement & Bank Accounts -->
        <div class="col-md-4 col-sm-6">
            <a href="payroll_disbursement.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-money-bill-transfer"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'صرف الرواتب والمستحقات' : 'Payroll Disbursements' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'التحويلات المصرفية للكادر' : 'Staff Salary Clearing' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'صرف مسيرات رواتب الموظفين، التحويلات البنكية، وتحديث أرصدة الحسابات.' : 'Execute salary pay-runs, issue bank transfer batches, and deduct staff disbursements.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 2: Tuition Billing & Invoicing Engine -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-layer-group text-primary me-2"></i><?= is_rtl() ? 'الرسوم المدرسية والفواتير والتحصيل' : 'Tuition Billing & Invoicing Engine' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- 4. Tuition Fee Structures Engine -->
        <div class="col-md-4 col-sm-6">
            <a href="fee_structures.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'هياكل الرسوم الدراسية' : 'Fee Structures Engine' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'المصفوفة وبنود التسعير' : 'Standard Tuition & Fee Items' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تهيئة بنود الرسوم الدراسية، رسوم النقل والأنشطة، وتخصيص الجداول للمراحل.' : 'Configure base tuition rates, bus transit fees, lab fees, and class schedules for billing.' ?></p>
            </a>
        </div>

        <!-- 5. Tuition Invoices & Student Billing -->
        <div class="col-md-4 col-sm-6">
            <a href="fees.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-receipt"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'فواتير الرسوم والتحصيل' : 'Tuition Invoices' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'إصدار ومتابعة الفواتير' : 'Student Invoicing & Status' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'إصدار الفواتير الدراسية للطلاب، متابعة حالة التحصيل، وتدقيق المستحقات المالية.' : 'Issue tuition invoices, track payment status, and monitor student balances.' ?></p>
            </a>
        </div>

        <!-- 6. Multi-Child & Sibling Discounts -->
        <div class="col-md-4 col-sm-6">
            <a href="discounts.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-tags"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'خصومات الأخوة والعائلات' : 'Sibling Discounts' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'تخفيضات الأسر والشرائح' : 'Multi-Child Fee Relief' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تهيئة مجموعات الخصم العائلي وتطبيق نسب الخصم المئوية للأسر متعددي الأبناء.' : 'Manage family discount groups and automate percentage tuition deductions for multi-child households.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 3: Collections, Clearances & Ledgers -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-clipboard-check text-success me-2"></i><?= is_rtl() ? 'المقبوضات وبراءة الذمة وكشوف الحساب' : 'Collections, Clearances & Ledgers' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- 7. Payments & Receipts -->
        <div class="col-md-4 col-sm-6">
            <a href="payments.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-credit-card"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'سندات القبض والمدفوعات' : 'Payments & Invoicing' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'تحصيل الرسوم والإيصالات' : 'Collections & Receipts' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تسجيل إيصالات السداد المباشر، توليد سندات القبض، ومتابعة الفواتير غير المسددة.' : 'Record offline collections, generate print-ready tax receipts, and track unpaid fees.' ?></p>
            </a>
        </div>

        <!-- 8. Financial Clearance Engine -->
        <div class="col-md-4 col-sm-6">
            <a href="clearance.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-stamp"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'براءة الذمة والاعتماد المالي' : 'Financial Clearance' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'اعتماد الشهادات والنتائج' : 'Report Card Release Engine' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'التحقق الآلي من سداد كافة الرسوم الدراسية قبل طباعة بطاقات الدرجات أو إصدار الشهادات.' : 'Automated validation of student financial dues before releasing report cards and academic records.' ?></p>
            </a>
        </div>

        <!-- 9. Account Statements & Ledgers -->
        <div class="col-md-4 col-sm-6">
            <a href="account_statement.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'كشف الحساب وسجل الطالب' : 'Account Statements' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'التقارير المالية الفردية' : 'Student Billing Ledgers' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'استخراج وطباعة كشوف الحسابات المالية المفصلة للطلاب وأولياء الأمور.' : 'Audit complete payment records, view ledger balance history, and export PDF statements.' ?></p>
            </a>
        </div>
    </div>

    <!-- Quick Operational Panels: Bank Balances & Pending Reconciliation -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-piggy-bank text-primary me-2"></i><?= is_rtl() ? 'الحسابات المصرفية والمطابقة الفورية' : 'Bank Accounts & Active Reconciliation' ?>
    </h5>
    <div class="row g-4 mb-4">
        <!-- Bank Accounts Overview Row -->
        <div class="col-lg-6">
            <div class="card shadow-sm border border-border bg-card rounded-xl h-100">
                <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-foreground">
                        <i class="fa-solid fa-piggy-bank me-2 text-primary"></i><?= is_rtl() ? 'أرصدة الحسابات المصرفية' : 'Institution Bank Accounts' ?>
                    </h6>
                    <span class="badge bg-secondary text-foreground border border-border"><?= count($banks) ?> <?= is_rtl() ? 'حساب' : 'Accounts' ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($banks as $bank): ?>
                            <div class="col-sm-6">
                                <div class="p-3 rounded-lg bg-secondary border border-border">
                                    <h6 class="text-muted-foreground text-uppercase small fw-bold mb-1"><?= htmlspecialchars($bank['bank_name']) ?></h6>
                                    <h4 class="fw-bold text-success mb-1">$<?= number_format($bank['current_balance'], 2) ?></h4>
                                    <p class="small text-muted-foreground mb-0"><i class="fa-solid fa-credit-card me-1"></i> <?= htmlspecialchars($bank['account_number']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($banks)): ?>
                            <div class="col-12">
                                <div class="alert alert-info mb-0"><?= is_rtl() ? 'لا توجد حسابات بنكية مسجلة حالياً.' : 'No bank accounts configured yet.' ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Bank Transfer Reconciliation Queue -->
        <div class="col-lg-6">
            <div class="card shadow-sm border border-border bg-card rounded-xl h-100">
                <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-foreground">
                        <i class="fa-solid fa-receipt me-2 text-warning"></i><?= is_rtl() ? 'قائمة مطابقة الحوالات الواردة' : 'Reconciliation Queue' ?>
                    </h6>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-warning text-dark"><?= $pending_count ?> <?= is_rtl() ? 'معلق' : 'Pending' ?></span>
                    <?php else: ?>
                        <span class="badge bg-success"><?= is_rtl() ? 'محدث' : 'Up to date' ?></span>
                    <?php endif; ?>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-secondary small text-muted-foreground">
                                <tr>
                                    <th><?= is_rtl() ? 'رقم الإشعار' : 'Ref #' ?></th>
                                    <th><?= is_rtl() ? 'المودع / الطالب' : 'Payer / Student' ?></th>
                                    <th><?= is_rtl() ? 'المبلغ' : 'Amount' ?></th>
                                    <th class="text-end"><?= is_rtl() ? 'إجراء' : 'Action' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($pending_txs, 0, 4) as $tx): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($tx['reference_number']) ?></strong></td>
                                        <td><?= htmlspecialchars($tx['payer_name']) ?></td>
                                        <td class="fw-bold text-success">$<?= number_format($tx['amount'], 2) ?></td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline-flex gap-1">
                                                <input type="hidden" name="transaction_id" value="<?= $tx['transaction_id'] ?>">
                                                <button type="submit" name="action_type" value="Verified" class="btn btn-sm btn-success fw-bold" title="Approve">
                                                    <i class="fa-solid fa-check"></i>
                                                </button>
                                                <button type="submit" name="action_type" value="Rejected" class="btn btn-sm btn-outline-danger" title="Reject">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                                <input type="hidden" name="verify_transaction" value="1">
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($pending_txs)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted-foreground py-4"><?= is_rtl() ? 'لا توجد حوالات معلقة بانتظار التأكيد.' : 'No pending bank transfers awaiting verification.' ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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