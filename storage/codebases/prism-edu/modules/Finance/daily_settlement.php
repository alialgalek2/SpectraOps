<?php
/**
 * Finance Daily Settlement Module
 * Calculates total income and expense for a day and records a settlement row.
 * Serves as automated CLI cron task and full Cashier Settlement Management UI.
 */

// Safe bootstrap
if (!function_exists('getPDO')) {
    if (file_exists(__DIR__ . '/../../core/init.php')) {
        require_once __DIR__ . '/../../core/init.php';
    } elseif (file_exists(__DIR__ . '/../../config/db.php')) {
        require_once __DIR__ . '/../../config/db.php';
    }
}

/**
 * Fallback getPDO helper for environments where the function is not yet loaded.
 */
if (!function_exists('getPDO')) {
    function getPDO(): PDO {
        global $pdo;
        return $pdo;
    }
}

/**
 * Run daily settlement calculation and upsert into cashier_settlements table.
 *
 * @param string|null $targetDate Date in Y-m-d format (defaults to yesterday)
 * @return array Calculated settlement summary
 */
function runDailySettlement(?string $targetDate = null): array {
    $pdo = getPDO();
    $date = $targetDate ?: (new DateTime('yesterday'))->format('Y-m-d');

    // 1. Total income from financial_transactions
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE transaction_type = :type AND DATE(transaction_date) = :date');
    $stmt->execute(['type' => 'Income', 'date' => $date]);
    $txIncome = (float)$stmt->fetchColumn();

    // 2. Direct collections from payments table (tuition fees receipts)
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE DATE(payment_date) = :date');
    $stmt->execute(['date' => $date]);
    $paymentsIncome = (float)$stmt->fetchColumn();

    // Sum overall income
    $totalIncome = $txIncome + $paymentsIncome;

    // 3. Total expenses from financial_transactions (Expense and Payroll)
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE transaction_type IN ("Expense", "Payroll") AND DATE(transaction_date) = :date');
    $stmt->execute(['date' => $date]);
    $totalExpense = (float)$stmt->fetchColumn();

    // 4. Breakdown: Cash payments vs Bank / Digital payments
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE DATE(payment_date) = :date AND payment_method = "Cash"');
    $stmt->execute(['date' => $date]);
    $cashAmount = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE DATE(payment_date) = :date AND payment_method <> "Cash"');
    $stmt->execute(['date' => $date]);
    $bankAmount = (float)$stmt->fetchColumn();

    // 5. Upsert settlement record
    $stmt = $pdo->prepare('
        INSERT INTO cashier_settlements (settlement_date, total_income, total_expense, cash_amount, bank_amount, status)
        VALUES (:date, :income, :expense, :cash, :bank, "Closed")
        ON DUPLICATE KEY UPDATE 
            total_income = VALUES(total_income), 
            total_expense = VALUES(total_expense),
            cash_amount = VALUES(cash_amount),
            bank_amount = VALUES(bank_amount),
            generated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        'date'    => $date,
        'income'  => $totalIncome,
        'expense' => $totalExpense,
        'cash'    => $cashAmount,
        'bank'    => $bankAmount
    ]);

    $summary = [
        'settlement_date' => $date,
        'total_income'    => $totalIncome,
        'total_expense'   => $totalExpense,
        'net_balance'     => $totalIncome - $totalExpense,
        'cash_amount'     => $cashAmount,
        'bank_amount'     => $bankAmount,
        'status'          => 'Closed'
    ];

    if (php_sapi_name() === 'cli') {
        echo "Daily settlement for {$date} recorded: Income = {$totalIncome}, Expense = {$totalExpense}, Net = " . ($totalIncome - $totalExpense) . "\n";
    }

    return $summary;
}

// If invoked from CLI directly, execute and exit
if (php_sapi_name() === 'cli') {
    if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)) {
        $cliDate = $argv[1] ?? null;
        runDailySettlement($cliDate);
        exit(0);
    }
    // If required by a test or CLI script, do not render web UI
    return;
}

// -------------------------------------------------------------
// WEB UI & CONTROLLER LOGIC
// -------------------------------------------------------------
if (class_exists('Auth')) {
    Auth::requireRole(['admin', 'school_admin', 'finance']);
}

$pdo = getPDO();

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "success";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf)) {
        $msg = function_exists('is_rtl') && is_rtl() ? "خطأ في التحقق من صحة الطلب (CSRF token)." : "Security token expired or invalid.";
        $msg_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'run_settlement') {
            $targetDate = trim($_POST['settlement_date'] ?? date('Y-m-d'));
            if (!empty($targetDate)) {
                $res = runDailySettlement($targetDate);
                $_SESSION['flash_msg'] = (function_exists('is_rtl') && is_rtl()) 
                    ? "تم توليد وتحديث الإغلاق المالي ليوم {$targetDate} بنجاح! الإيرادات: \${$res['total_income']}, المصروفات: \${$res['total_expense']}"
                    : "Daily settlement computed successfully for {$targetDate}! Inflow: \${$res['total_income']}, Outflow: \${$res['total_expense']}";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: daily_settlement.php?date=" . urlencode($targetDate));
                exit();
            }
        } elseif ($action === 'close_settlement') {
            $targetDate = trim($_POST['settlement_date'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            if (!empty($targetDate)) {
                $stmt = $pdo->prepare("UPDATE cashier_settlements SET status = 'Closed', notes = ? WHERE settlement_date = ?");
                $stmt->execute([$notes, $targetDate]);
                $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl() ? "تم إغلاق واعتماد المطابقة اليومية لهذا التاريخ." : "Daily settlement verified and closed.";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: daily_settlement.php?date=" . urlencode($targetDate));
                exit();
            }
        }
    }
}

// Current Selected Date (default to today or yesterday)
$selectedDate = trim($_GET['date'] ?? date('Y-m-d'));

// Fetch Settlement Record for Selected Date
$currentSettlement = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM cashier_settlements WHERE settlement_date = ?");
    $stmt->execute([$selectedDate]);
    $currentSettlement = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// If not yet generated, compute on the fly for viewing
if (!$currentSettlement) {
    // Total income from transactions
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE transaction_type = "Income" AND DATE(transaction_date) = :date');
    $stmt->execute(['date' => $selectedDate]);
    $calcTxIn = (float)$stmt->fetchColumn();

    // Payments
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE DATE(payment_date) = :date');
    $stmt->execute(['date' => $selectedDate]);
    $calcPayIn = (float)$stmt->fetchColumn();

    $calcTotalIn = $calcTxIn + $calcPayIn;

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM financial_transactions WHERE transaction_type IN ("Expense", "Payroll") AND DATE(transaction_date) = :date');
    $stmt->execute(['date' => $selectedDate]);
    $calcTotalOut = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid), 0) FROM payments WHERE DATE(payment_date) = :date AND payment_method = "Cash"');
    $stmt->execute(['date' => $selectedDate]);
    $calcCash = (float)$stmt->fetchColumn();

    $currentSettlement = [
        'settlement_date' => $selectedDate,
        'total_income'    => $calcTotalIn,
        'total_expense'   => $calcTotalOut,
        'cash_amount'     => $calcCash,
        'bank_amount'     => $calcPayIn - $calcCash,
        'status'          => 'Pending',
        'generated_at'    => null,
        'notes'           => null
    ];
}

// Fetch Detailed Payments for the day
$dayPayments = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, fi.term_name, u.full_name as student_name, ru.full_name as recorder_name
        FROM payments p
        LEFT JOIN fee_invoices fi ON p.invoice_id = fi.invoice_id
        LEFT JOIN students s ON fi.student_id = s.student_id
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN users ru ON p.recorded_by = ru.user_id
        WHERE DATE(p.payment_date) = ?
        ORDER BY p.payment_date DESC
    ");
    $stmt->execute([$selectedDate]);
    $dayPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch Detailed Expenses/Outflows for the day
$dayExpenses = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name as recorder_name
        FROM financial_transactions t
        LEFT JOIN users u ON t.recorded_by = u.user_id
        WHERE DATE(t.transaction_date) = ? AND t.transaction_type IN ('Expense', 'Payroll')
        ORDER BY t.transaction_id DESC
    ");
    $stmt->execute([$selectedDate]);
    $dayExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch Historical Settlements Ledger (last 30 days)
$settlementsHistory = [];
try {
    $settlementsHistory = $pdo->query("
        SELECT * FROM cashier_settlements 
        ORDER BY settlement_date DESC 
        LIMIT 30
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

$netMovement = (float)$currentSettlement['total_income'] - (float)$currentSettlement['total_expense'];
?>
<!DOCTYPE html>
<html <?= function_exists('get_html_dir') ? get_html_dir() : 'dir="ltr"' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= function_exists('is_rtl') && is_rtl() ? 'المطابقة والإغلاق اليومي للصندوق' : 'Daily Cashier Settlement' ?> - <?= function_exists('__') ? __('fees_and_finance') : 'Finance Hub' ?></title>
    <?= function_exists('get_bootstrap_css') ? get_bootstrap_css() : '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-banner {
            background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%);
            color: #ffffff;
            border-radius: 1rem;
        }
        .stat-card {
            border: 1px solid rgba(0,0,0,0.08);
            border-radius: 0.85rem;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
        }
        @media print {
            .no-print { display: none !important; }
            .hero-banner { background: none !important; color: #000 !important; }
        }
    </style>
</head>
<body class="bg-light">

<!-- Top Navigation -->
<?php if (function_exists('render_universal_navbar')): ?>
    <div class="no-print">
        <?= render_universal_navbar([
            'active_title' => is_rtl() ? 'المطابقة والإغلاق اليومي للصندوق' : 'Daily Cashier Settlement',
            'back_url' => 'index.php',
            'extra_nav_items' => [
                '<a href="fees_portal.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-vault me-1"></i> ' . (is_rtl() ? 'الخزينة' : 'Treasury') . '</a>',
                '<a href="clearance.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-stamp me-1"></i> ' . (is_rtl() ? 'براءة الذمة' : 'Clearance') . '</a>'
            ]
        ]) ?>
    </div>
<?php else: ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 no-print">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fa-solid fa-vault me-2"></i>Finance Hub</a>
            <a href="index.php" class="btn btn-outline-light btn-sm ms-auto">Back to Hub</a>
        </div>
    </nav>
<?php endif; ?>

<div class="container py-4">

    <!-- Flash Alerts -->
    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type) ?> alert-dismissible fade show shadow-sm no-print" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="badge bg-white bg-opacity-25 text-white mb-2 px-3 py-1">
                    <i class="fa-solid fa-calendar-day text-warning me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'المطابقة والإقفال اليومي لنقاط التحصيل' : 'Daily Cashier Audit & Day-End Closing' ?>
                </span>
                <h2 class="fw-bold mb-1"><i class="fa-solid fa-cash-register me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'الإغلاق والمطابقة اليومية للصندوق' : 'Cashier Daily Settlement Sheet' ?></h2>
                <p class="mb-0 text-white-50"><?= (function_exists('is_rtl') && is_rtl()) ? 'تدقيق المقبوضات النقدية والمصرفية اليومية، المصروفات المنصرفة، وتوليد القيود المحاسبية التراكمية.' : 'Audit daily fee collections, reconcile POS/cash vault inflows with operational expenses, and record ledger snapshots.' ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap no-print">
                <form method="POST" action="daily_settlement.php" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="run_settlement">
                    <input type="hidden" name="settlement_date" value="<?= htmlspecialchars($selectedDate) ?>">
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm">
                        <i class="fa-solid fa-rotate me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'توليد / تحديث مطابقة اليوم' : 'Compute & Record Settlement' ?>
                    </button>
                </form>
                <button type="button" onclick="window.print()" class="btn btn-light text-dark fw-bold px-3 py-2 shadow-sm">
                    <i class="fa-solid fa-print me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'طباعة كشف الإغلاق' : 'Print Slip' ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Date Picker Bar -->
    <div class="card shadow-sm border-0 mb-4 bg-white no-print">
        <div class="card-body p-3">
            <form method="GET" action="daily_settlement.php" class="row g-2 align-items-center">
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1"><?= (function_exists('is_rtl') && is_rtl()) ? 'حدد تاريخ التسوية والمطابقة:' : 'Select Settlement Date:' ?></label>
                    <div class="input-group">
                        <input type="date" name="date" class="form-control" value="<?= htmlspecialchars($selectedDate) ?>" max="<?= date('Y-m-d') ?>">
                        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-magnifying-glass me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'عرض اليوم المحدد' : 'Load Date' ?></button>
                    </div>
                </div>
                <div class="col-md-6 d-flex align-items-end justify-content-md-end gap-2 mt-3 mt-md-0">
                    <a href="daily_settlement.php?date=<?= date('Y-m-d') ?>" class="btn btn-outline-secondary btn-sm <?= ($selectedDate === date('Y-m-d')) ? 'active' : '' ?>">
                        <i class="fa-solid fa-clock me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'اليوم' : 'Today' ?>
                    </a>
                    <a href="daily_settlement.php?date=<?= date('Y-m-d', strtotime('-1 day')) ?>" class="btn btn-outline-secondary btn-sm <?= ($selectedDate === date('Y-m-d', strtotime('-1 day'))) ? 'active' : '' ?>">
                        <i class="fa-solid fa-backward-step me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'أمس' : 'Yesterday' ?>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Day Summary Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="card stat-card p-3 bg-white">
                <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'إجمالي المقبوضات (الإيراد)' : 'Total Collections (Inflow)' ?></span>
                <h3 class="fw-bold mb-0 text-success mt-1">$<?= number_format($currentSettlement['total_income'], 2) ?></h3>
                <small class="text-muted"><?= count($dayPayments) ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'عملية سداد رسوم' : 'payment receipts' ?></small>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card stat-card p-3 bg-white">
                <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'إجمالي المدفوعات (المصروفات)' : 'Total Expenses (Outflow)' ?></span>
                <h3 class="fw-bold mb-0 text-danger mt-1">$<?= number_format($currentSettlement['total_expense'], 2) ?></h3>
                <small class="text-muted"><?= count($dayExpenses) ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'سند صرف وتشغيل' : 'expense entries' ?></small>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card stat-card p-3 bg-white">
                <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'صافي حركة الصندوق' : 'Net Vault Flow' ?></span>
                <h3 class="fw-bold mb-0 <?= $netMovement >= 0 ? 'text-primary' : 'text-danger' ?> mt-1">
                    <?= $netMovement >= 0 ? '+' : '' ?>$<?= number_format($netMovement, 2) ?>
                </h3>
                <small class="text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'الرصيد الفعلي لليوم' : 'Day settlement balance' ?></small>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="card stat-card p-3 bg-white">
                <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'حالة الإغلاق المالي' : 'Settlement Status' ?></span>
                <div class="mt-1">
                    <?php if (($currentSettlement['status'] ?? '') === 'Closed'): ?>
                        <span class="badge bg-success fs-6"><i class="fa-solid fa-lock me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'مغلق ومعتمد' : 'Closed / Settled' ?></span>
                    <?php else: ?>
                        <span class="badge bg-warning text-dark fs-6"><i class="fa-solid fa-clock me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'قيد المطابقة' : 'Pending Review' ?></span>
                    <?php endif; ?>
                </div>
                <small class="text-muted">
                    <?= !empty($currentSettlement['generated_at']) ? date('H:i:s', strtotime($currentSettlement['generated_at'])) : (is_rtl() ? 'غير مسجل' : 'Draft') ?>
                </small>
            </div>
        </div>
    </div>

    <!-- Day Breakdown Tables -->
    <div class="row g-4 mb-4">
        <!-- Inflows (Payments Table) -->
        <div class="col-lg-7">
            <div class="card shadow-sm border-0 bg-white h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-receipt text-success me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'تفاصيل سندات القبض والتحصيلات لليوم' : 'Collections & Payment Receipts' ?>
                    </h6>
                    <span class="badge bg-success"><?= count($dayPayments) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'رقم الإيصال' : 'Receipt #' ?></th>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الطالب' : 'Student' ?></th>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'طريقة الدفع' : 'Method' ?></th>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'المبلغ' : 'Amount' ?></th>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الوقت' : 'Time' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dayPayments)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted small">
                                        <?= (function_exists('is_rtl') && is_rtl()) ? 'لا توجد سندات قبض مسجلة في هذا اليوم.' : 'No payment receipts logged for this date.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($dayPayments as $p): ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?= htmlspecialchars($p['receipt_number']) ?></td>
                                        <td><?= htmlspecialchars($p['student_name'] ?? '—') ?></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['payment_method']) ?></span></td>
                                        <td class="fw-bold text-success">$<?= number_format($p['amount_paid'], 2) ?></td>
                                        <td class="small text-muted"><?= date('H:i', strtotime($p['payment_date'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Outflows (Expenses Table) -->
        <div class="col-lg-5">
            <div class="card shadow-sm border-0 bg-white h-100">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold mb-0 text-dark">
                        <i class="fa-solid fa-money-bill-transfer text-danger me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'المصروفات والمسحوبات المنفذة' : 'Expenses & Outflows' ?>
                    </h6>
                    <span class="badge bg-danger"><?= count($dayExpenses) ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'البند / البيان' : 'Category' ?></th>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'المبلغ' : 'Amount' ?></th>
                                <th><?= (function_exists('is_rtl') && is_rtl()) ? 'المسجل' : 'By' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dayExpenses)): ?>
                                <tr>
                                    <td colspan="3" class="text-center py-4 text-muted small">
                                        <?= (function_exists('is_rtl') && is_rtl()) ? 'لا توجد مصروفات مسجلة في هذا اليوم.' : 'No expenses recorded for this date.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($dayExpenses as $ex): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-dark"><?= htmlspecialchars($ex['category'] ?? 'Expense') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($ex['description'] ?? '') ?></small>
                                        </td>
                                        <td class="fw-bold text-danger">$<?= number_format($ex['amount'], 2) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($ex['recorder_name'] ?? 'System') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Historical Ledger -->
    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-book-journal-whills text-primary me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'سجل التسويات اليومية السابقة (آخر 30 يوماً)' : 'Historical Daily Settlement Log (Last 30 Days)' ?>
            </h5>
            <span class="badge bg-secondary"><?= count($settlementsHistory) ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'يوم مسجل' : 'Records' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3"><?= (function_exists('is_rtl') && is_rtl()) ? 'تاريخ التسوية' : 'Settlement Date' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'إجمالي المقبوضات' : 'Total Inflow' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'إجمالي المصروفات' : 'Total Outflow' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الصافي المقفل' : 'Net Flow' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'المقبوض نقداً' : 'Cash Vault' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الحالة' : 'Status' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'وقت الإغلاق' : 'Settled At' ?></th>
                        <th class="text-end pe-3 no-print"><?= (function_exists('is_rtl') && is_rtl()) ? 'عرض' : 'Action' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($settlementsHistory)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <?= (function_exists('is_rtl') && is_rtl()) ? 'لا توجد تسويات تاريخية مسجلة بعد. انقر على "توليد / تحديث مطابقة اليوم" لتسجيل تسوية.' : 'No historical settlements recorded yet. Click "Compute & Record Settlement" to register a day.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($settlementsHistory as $row): 
                            $rowNet = (float)$row['total_income'] - (float)$row['total_expense'];
                        ?>
                            <tr class="<?= ($row['settlement_date'] === $selectedDate) ? 'table-primary bg-opacity-25' : '' ?>">
                                <td class="ps-3 fw-bold text-dark">
                                    <i class="fa-solid fa-calendar me-2 text-primary"></i><?= htmlspecialchars($row['settlement_date']) ?>
                                </td>
                                <td class="fw-bold text-success">$<?= number_format($row['total_income'], 2) ?></td>
                                <td class="fw-bold text-danger">$<?= number_format($row['total_expense'], 2) ?></td>
                                <td class="fw-bold <?= $rowNet >= 0 ? 'text-primary' : 'text-danger' ?>">
                                    $<?= number_format($rowNet, 2) ?>
                                </td>
                                <td class="small text-muted">$<?= number_format($row['cash_amount'] ?? 0, 2) ?></td>
                                <td>
                                    <span class="badge bg-success"><i class="fa-solid fa-check-double me-1"></i> <?= htmlspecialchars($row['status']) ?></span>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($row['generated_at']) ?></td>
                                <td class="text-end pe-3 no-print">
                                    <a href="daily_settlement.php?date=<?= urlencode($row['settlement_date']) ?>" class="btn btn-outline-primary btn-sm py-1 px-2">
                                        <i class="fa-solid fa-eye me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'فتح اليوم' : 'View' ?>
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
