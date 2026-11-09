<?php
/**
 * modules/Finance/fees_portal.php
 * Financial System & Bank Accounts Hub
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Ensure user is logged in as admin or finance manager
Auth::requireRole(['admin', 'finance']);

$user_role = Auth::role();
$user_id   = Auth::id();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "success";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// 1. Handle Adding a New Bank/Cash Account
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_account') {
    $account_name   = trim($_POST['account_name'] ?? '');
    $account_number = trim($_POST['account_number'] ?? '');
    $account_type   = trim($_POST['account_type'] ?? 'Bank');
    $initial_balance= floatval($_POST['initial_balance'] ?? 0.00);
    $currency       = trim($_POST['currency'] ?? 'SDG');

    if (!empty($account_name)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO finance_accounts (account_name, account_number, account_type, current_balance, currency) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$account_name, $account_number, $account_type, $initial_balance, $currency]);
            
            $_SESSION['flash_msg'] = "Financial account '$account_name' added successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: fees_portal.php");
            exit();
        } catch (Exception $e) {
            $msg = "Error adding account: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = "Account Name is required.";
        $msg_type = "danger";
    }
}

// 2. Handle Logging an Expense / Income Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_transaction') {
    $account_id       = intval($_POST['account_id'] ?? 0);
    $transaction_type = $_POST['transaction_type'] ?? 'Expense';
    $category         = trim($_POST['category'] ?? 'General Expense');
    $amount           = floatval($_POST['amount'] ?? 0.00);
    $description      = trim($_POST['description'] ?? '');
    $transaction_date = !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y-m-d');

    if ($account_id > 0 && $amount > 0) {
        try {
            $pdo->beginTransaction();

            $insTx = $pdo->prepare("
                INSERT INTO financial_transactions (account_id, transaction_type, category, amount, description, transaction_date, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insTx->execute([$account_id, $transaction_type, $category, $amount, $description, $transaction_date, $user_id]);

            // Update Account Balance
            if ($transaction_type === 'Income') {
                $updBal = $pdo->prepare("UPDATE finance_accounts SET current_balance = current_balance + ? WHERE account_id = ?");
            } else {
                $updBal = $pdo->prepare("UPDATE finance_accounts SET current_balance = current_balance - ? WHERE account_id = ?");
            }
            $updBal->execute([$amount, $account_id]);

            $pdo->commit();
            $_SESSION['flash_msg'] = "Transaction recorded successfully! Balance updated.";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: fees_portal.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error recording transaction: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch Accounts Data
$accounts = [];
try {
    $accounts = $pdo->query("SELECT * FROM finance_accounts ORDER BY account_id ASC")->fetchAll();
} catch (Exception $e) {
    $accounts = [];
}

// Total Liquidity Metrics
$totalBankLiquidity = 0;
$totalCashLiquidity = 0;
$totalMobileLiquidity = 0;
$totalTreasury = 0;

foreach ($accounts as $acc) {
    $totalTreasury += $acc['current_balance'];
    if ($acc['account_type'] === 'Bank') $totalBankLiquidity += $acc['current_balance'];
    elseif ($acc['account_type'] === 'Cash') $totalCashLiquidity += $acc['current_balance'];
    elseif ($acc['account_type'] === 'Mobile Money') $totalMobileLiquidity += $acc['current_balance'];
}

// Fetch Recent 10 Transactions
$recentTransactions = [];
try {
    $recentTransactions = $pdo->query("
        SELECT t.*, a.account_name, a.currency, u.full_name as recorder_name
        FROM financial_transactions t
        JOIN finance_accounts a ON t.account_id = a.account_id
        LEFT JOIN users u ON t.recorded_by = u.user_id
        ORDER BY t.transaction_date DESC, t.transaction_id DESC
        LIMIT 10
    ")->fetchAll();
} catch (Exception $e) {}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'إدارة الخزينة والحسابات البنكية' : 'Financial & Fee Management Hub' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'الإدارة المالية والخزينة المدرسية' : 'Financial Management & Treasury',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => [
        '<a href="payroll_disbursement.php" class="btn btn-primary btn-sm fw-bold"><i class="fa-solid fa-money-check-dollar me-1"></i> ' . (is_rtl() ? 'مسير الرواتب البنكي' : 'Bank Payout Sheet') . '</a>',
        '<a href="fees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-receipt me-1 text-primary"></i> ' . (is_rtl() ? 'فواتير الطلاب' : 'Student Invoices') . '</a>'
    ]
]) ?>

<div class="container-fluid px-4 py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm mb-4 fw-bold" role="alert">
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
                        <i class="fa-solid fa-vault text-warning me-1"></i> Treasury &amp; Liquidity Hub: Active
                    </span>
                </div>
                <h2 class="fw-bold font-heading mb-1 text-white">
                    <i class="fa-solid fa-wallet me-2"></i><?= is_rtl() ? 'الحسابات البنكية والخزينة ومسيرات النفقات' : 'Financial System & Bank Accounts Hub' ?>
                </h2>
                <p class="mb-0 text-white-50 small">
                    <?= is_rtl() ? 'متابعة الأرصدة البنكية اللحظية، التحصيل المالي للفواتير، مسيرات الرواتب والمصروفات التشغيلية.' : 'Monitor bank accounts, track liquid cash channels, collect tuition invoices, and manage expenditures.' ?>
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-light text-primary fw-bold px-3 py-2 rounded-lg shadow-xs" data-bs-toggle="modal" data-bs-target="#recordTxModal">
                    <i class="fa-solid fa-money-bill-transfer me-1"></i> <?= is_rtl() ? 'تسجيل معاملة / مصروف' : 'Record Transaction' ?>
                </button>
                <button class="btn btn-outline-light fw-bold px-3 py-2 rounded-lg" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                    <i class="fa-solid fa-circle-plus me-1"></i> <?= is_rtl() ? 'إضافة حساب بنكي / خزينة' : 'Add Bank / Cash Account' ?>
                </button>
            </div>
        </div>
    </div>

    <!-- 4-Column Responsive Liquidity KPI Cards Grid -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي سيولة الخزينة' : 'Total Treasury Liquidity' ?></span>
                    <div class="action-icon-box icon-box-warning" style="width: 2.5rem; height: 2.5rem; min-width: 2.5rem; min-height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-vault"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($totalTreasury, 2) ?> <small class="fs-6 text-muted-foreground">SDG</small></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-emerald-500 fw-semibold"><i class="fa-solid fa-coins me-1"></i> Combined</span>
                    <span class="text-muted-foreground">· Liquid Reserves</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'أرصدة الحسابات البنكية' : 'Commercial Bank Balances' ?></span>
                    <div class="action-icon-box icon-box-success" style="width: 2.5rem; height: 2.5rem; min-width: 2.5rem; min-height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-building-columns"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($totalBankLiquidity, 2) ?> <small class="fs-6 text-muted-foreground">SDG</small></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-success fw-semibold"><i class="fa-solid fa-shield-halved me-1"></i> Insured</span>
                    <span class="text-muted-foreground">· Bank Accounts</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'السيولة النقدية / الخزينة' : 'Cash in Vault / Safe' ?></span>
                    <div class="action-icon-box icon-box-warning" style="width: 2.5rem; height: 2.5rem; min-width: 2.5rem; min-height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-cash-register"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($totalCashLiquidity, 2) ?> <small class="fs-6 text-muted-foreground">SDG</small></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-warning fw-semibold"><i class="fa-solid fa-hand-holding-dollar me-1"></i> Physical</span>
                    <span class="text-muted-foreground">· Safe Storage</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'المحافظ والتحويل الإلكتروني' : 'Mobile Money (Bankak)' ?></span>
                    <div class="action-icon-box icon-box-info" style="width: 2.5rem; height: 2.5rem; min-width: 2.5rem; min-height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-mobile-screen-button"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($totalMobileLiquidity, 2) ?> <small class="fs-6 text-muted-foreground">SDG</small></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold"><i class="fa-solid fa-bolt me-1"></i> Instant</span>
                    <span class="text-muted-foreground">· Digital Channels</span>
                </div>
            </div>
        </div>
    </div>
    <!-- Financial Operations Directory Sub-modules -->
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h5 class="fw-bold font-heading text-foreground mb-0">
            <i class="fa-solid fa-folder-tree text-primary me-2"></i><?= is_rtl() ? 'أدوات وأقسام العمليات المالية' : 'Financial Operations Directory' ?>
        </h5>
        <span class="text-muted-foreground small"><?= is_rtl() ? 'الوصول المباشر إلى أدوات الرسوم والتحصيل والمطابقة' : 'Direct access to core fee billing, clearance & settlement operations' ?></span>
    </div>

    <div class="row g-4 mb-4">
        <!-- 1. Fee Structures Engine -->
        <div class="col-xl-4 col-md-6">
            <a href="fee_structures.php" class="card admin-action-card p-4 h-100 border-primary border-opacity-25">
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

        <!-- 2. Tuition Invoices & Student Billing -->
        <div class="col-xl-4 col-md-6">
            <a href="fees.php" class="card admin-action-card p-4 h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-primary me-3">
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

        <!-- 3. Multi-Child & Sibling Discounts -->
        <div class="col-xl-4 col-md-6">
            <a href="discounts.php" class="card admin-action-card p-4 h-100 border-success border-opacity-25">
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

        <!-- 4. Financial Clearance Engine -->
        <div class="col-xl-6 col-md-6">
            <a href="clearance.php" class="card admin-action-card p-4 h-100 border-primary border-opacity-25">
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

        <!-- 5. Daily Cashier Settlement Sheet -->
        <div class="col-xl-6 col-md-6">
            <a href="daily_settlement.php" class="card admin-action-card p-4 h-100 border-warning border-opacity-25">
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
    </div>
    <!-- Accounts Overview Grid -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-border rounded-xl p-4 bg-card shadow-sm">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold font-heading text-foreground mb-0">
                        <i class="fa-solid fa-building-columns me-2 text-primary"></i><?= is_rtl() ? 'الحسابات البنكية وقنوات السيولة المسجلة' : 'Registered Bank & Cash Accounts' ?>
                    </h5>
                    <button class="btn btn-primary fw-bold btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#addAccountModal">
                        <i class="fa-solid fa-plus me-1"></i> Add Account
                    </button>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>Account ID</th>
                                <th>Account Name / Description</th>
                                <th>Account Number / IBAN</th>
                                <th>Channel Type</th>
                                <th>Currency</th>
                                <th>Current Balance</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($accounts)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No financial accounts found. Click "Add Account" to register liquidity channels.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($accounts as $acc): ?>
                                <tr>
                                    <td><code>#ACC-<?= $acc['account_id'] ?></code></td>
                                    <td class="fw-bold text-dark">
                                        <?= htmlspecialchars($acc['account_name']) ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($acc['account_number'] ?: 'CASH-DRAWER') ?></code></td>
                                    <td>
                                        <?php 
                                        $badge = 'bg-secondary';
                                        if ($acc['account_type'] === 'Bank') $badge = 'bg-primary';
                                        elseif ($acc['account_type'] === 'Cash') $badge = 'bg-success';
                                        elseif ($acc['account_type'] === 'Mobile Money') $badge = 'bg-info';
                                        ?>
                                        <span class="badge <?= $badge ?> px-2 py-1"><?= htmlspecialchars($acc['account_type']) ?></span>
                                    </td>
                                    <td><strong><?= htmlspecialchars($acc['currency'] ?? 'SDG') ?></strong></td>
                                    <td class="fw-bold fs-6 text-success"><?= number_format($acc['current_balance'], 2) ?> <?= htmlspecialchars($acc['currency'] ?? 'SDG') ?></td>
                                    <td class="text-end">
                                        <a href="account_statement.php?id=<?= $acc['account_id'] ?>" class="btn btn-sm btn-outline-primary fw-bold">
                                            <i class="fa-solid fa-file-invoice-dollar me-1"></i> Statement Ledger
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transactions Ledger -->
    <div class="card border-border rounded-xl p-4 bg-card shadow-sm mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold font-heading text-foreground mb-0"><i class="fa-solid fa-receipt me-2 text-success"></i>Recent Financial Transactions</h5>
            <button class="btn btn-sm btn-success fw-bold rounded-lg" data-bs-toggle="modal" data-bs-target="#recordTxModal">
                <i class="fa-solid fa-plus me-1"></i> Log Transaction
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Date</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Account</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Type</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Category</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Description</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Amount</th>
                        <th class="text-muted-foreground small text-uppercase fw-bold">Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentTransactions)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted-foreground py-4">
                                No recent financial ledger transactions logged.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td><small class="text-muted-foreground"><?= htmlspecialchars($tx['transaction_date']) ?></small></td>
                                <td><strong class="text-foreground"><?= htmlspecialchars($tx['account_name']) ?></strong></td>
                                <td>
                                    <?php if ($tx['transaction_type'] === 'Income'): ?>
                                        <span class="badge bg-success rounded-full"><i class="fa-solid fa-arrow-down me-1"></i> Income</span>
                                    <?php elseif ($tx['transaction_type'] === 'Payroll'): ?>
                                        <span class="badge bg-warning text-dark rounded-full"><i class="fa-solid fa-users me-1"></i> Payroll</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger rounded-full"><i class="fa-solid fa-arrow-up me-1"></i> Expense</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge bg-secondary text-foreground rounded-full border border-border"><?= htmlspecialchars($tx['category']) ?></span></td>
                                <td class="text-foreground"><?= htmlspecialchars($tx['description'] ?: '—') ?></td>
                                <td class="fw-bold <?= $tx['transaction_type'] === 'Income' ? 'text-success' : 'text-danger' ?>">
                                    <?= $tx['transaction_type'] === 'Income' ? '+' : '-' ?><?= number_format($tx['amount'], 2) ?> <?= htmlspecialchars($tx['currency']) ?>
                                </td>
                                <td><small class="text-muted-foreground"><?= htmlspecialchars($tx['recorder_name'] ?? 'System') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?= render_platform_footer() ?>
</div>

<!-- ADD ACCOUNT MODAL -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_account">
                
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-building-columns me-2"></i>Add Bank or Cash Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary">Account Name / Bank Title <span class="text-danger">*</span></label>
                        <input type="text" name="account_name" class="form-control" placeholder="E.g., Bank of Khartoum - Main Branch" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary">Account Number / IBAN</label>
                        <input type="text" name="account_number" class="form-control" placeholder="E.g., SD1234567890">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary">Account Type</label>
                            <select name="account_type" class="form-select" required>
                                <option value="Bank">Bank Account</option>
                                <option value="Cash">Cash Safe / Vault</option>
                                <option value="Mobile Money">Mobile Money (Bankak / Fawry)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary">Currency</label>
                            <select name="currency" class="form-select">
                                <option value="SDG">Sudanese Pound (SDG)</option>
                                <option value="USD">US Dollar ($)</option>
                                <option value="EUR">Euro (€)</option>
                                <option value="SAR">Saudi Riyal (SAR)</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary">Initial Opening Balance <span class="text-danger">*</span></label>
                        <input type="number" step="0.01" name="initial_balance" class="form-control fw-bold" value="0.00" required>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Save Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- RECORD TRANSACTION MODAL -->
<div class="modal fade" id="recordTxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="">
                <input type="hidden" name="action" value="record_transaction">
                
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-money-bill-transfer text-warning me-2"></i>Log Financial Transaction</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary">Liquidity Account <span class="text-danger">*</span></label>
                        <select name="account_id" class="form-select fw-bold" required>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?= $acc['account_id'] ?>">
                                    <?= htmlspecialchars($acc['account_name']) ?> (<?= number_format($acc['current_balance'], 2) ?> <?= htmlspecialchars($acc['currency']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary">Type <span class="text-danger">*</span></label>
                            <select name="transaction_type" class="form-select fw-bold" required>
                                <option value="Expense">Expense (Outflow)</option>
                                <option value="Income">Income (Inflow)</option>
                                <option value="Payroll">Staff Payroll</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary">Amount <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" name="amount" class="form-control fw-bold" required placeholder="0.00">
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary">Category</label>
                            <input type="text" name="category" class="form-control" placeholder="e.g. Utilities, Fuel, Stationery" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-uppercase text-secondary">Date</label>
                            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-uppercase text-secondary">Description / Reference Details</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Voucher number, invoice details, or vendor reference..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-dark fw-bold px-4"><i class="fa-solid fa-floppy-disk me-1"></i> Post Transaction</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
