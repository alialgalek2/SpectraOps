<?php
/**
 * modules/Finance/account_statement.php
 * Official Bank / Cash Account Statement Ledger
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'finance']);

$user_role = Auth::role();
$user_id   = Auth::id();

$account_id = intval($_GET['id'] ?? 0);
if ($account_id <= 0) {
    header("Location: fees_portal.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM finance_accounts WHERE account_id = ?");
$stmt->execute([$account_id]);
$account = $stmt->fetch();

if (!$account) {
    $_SESSION['flash_msg'] = "Account not found.";
    $_SESSION['flash_msg_type'] = "danger";
    header("Location: fees_portal.php");
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');

// Fetch transactions for this account
$txStmt = $pdo->prepare("
    SELECT t.*, u.full_name as recorder_name
    FROM financial_transactions t
    LEFT JOIN users u ON t.recorded_by = u.user_id
    WHERE t.account_id = ? AND t.transaction_date BETWEEN ? AND ?
    ORDER BY t.transaction_date ASC, t.transaction_id ASC
");
$txStmt->execute([$account_id, $start_date, $end_date]);
$transactions = $txStmt->fetchAll();

// Calculate totals in range
$totalIn  = 0;
$totalOut = 0;
foreach ($transactions as $t) {
    if ($t['transaction_type'] === 'Income') {
        $totalIn += $t['amount'];
    } else {
        $totalOut += $t['amount'];
    }
}
$netMovement = $totalIn - $totalOut;
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Statement: <?= htmlspecialchars($account['account_name']) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .statement-card {
            background: var(--card, #ffffff);
            border: 1px solid var(--border, #e2e8f0);
            border-radius: var(--radius-xl, 14px);
        }
        @media print {
            body { background: white !important; }
            .no-print, nav, .btn, form { display: none !important; }
            .statement-card { border: none !important; box-shadow: none !important; }
        }
    </style>
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<div class="no-print">
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'كشف الحساب البنكي' : 'Bank Account Statement',
    'back_url' => 'fees_portal.php',
    'extra_nav_items' => []
]) ?>
</div>

<div class="container py-4" style="max-width: 1000px;">

    <!-- Filter Bar (No Print) -->
    <div class="card p-3 bg-card border border-border shadow-sm rounded-xl mb-4 no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
                <input type="hidden" name="id" value="<?= $account_id ?>">
                <label class="fw-bold small text-muted-foreground"><?= is_rtl() ? 'من:' : 'From:' ?></label>
                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= htmlspecialchars($start_date) ?>">
                <label class="fw-bold small text-muted-foreground"><?= is_rtl() ? 'إلى:' : 'To:' ?></label>
                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= htmlspecialchars($end_date) ?>">
                <button type="submit" class="btn btn-primary btn-sm fw-bold px-3"><i class="fa-solid fa-filter me-1"></i> <?= is_rtl() ? 'تصفية' : 'Filter' ?></button>
            </form>

            <div class="d-flex gap-2">
                <button onclick="window.print();" class="btn btn-outline-border btn-sm fw-bold">
                    <i class="fa-solid fa-print me-1 text-primary"></i> <?= is_rtl() ? 'طباعة الكشف' : 'Print Statement' ?>
                </button>
                <a href="fees_portal.php" class="btn btn-secondary btn-sm fw-bold">
                    <i class="fa-solid fa-arrow-left me-1"></i> <?= is_rtl() ? 'الخزينة' : 'Accounts' ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Official Printable Statement -->
    <div class="statement-card p-4 p-md-5">
        <!-- Header -->
        <div class="border-bottom pb-3 mb-4">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                <div>
                    <h3 class="fw-bold text-dark mb-0 text-uppercase">OFFICIAL ACCOUNT STATEMENT LEDGER</h3>
                    <p class="text-muted small mb-0">Algale International Academy - Financial Comptroller &amp; Treasury</p>
                </div>
                <div class="text-end">
                    <span class="badge bg-dark px-3 py-2 fs-6"><?= htmlspecialchars($account['account_type']) ?> Account</span>
                    <div class="text-muted small mt-1">Generated: <?= date('d M Y, H:i') ?></div>
                </div>
            </div>
        </div>

        <!-- Account Summary Grid -->
        <div class="row g-3 p-3 bg-light rounded-3 mb-4 border">
            <div class="col-md-3 col-6">
                <small class="text-muted text-uppercase fw-bold d-block">Account Name</small>
                <strong class="text-dark"><?= htmlspecialchars($account['account_name']) ?></strong>
            </div>
            <div class="col-md-3 col-6">
                <small class="text-muted text-uppercase fw-bold d-block">Account / IBAN</small>
                <code><?= htmlspecialchars($account['account_number'] ?: 'CASH-DRAWER') ?></code>
            </div>
            <div class="col-md-3 col-6">
                <small class="text-muted text-uppercase fw-bold d-block">Current Real-Time Balance</small>
                <strong class="text-success fs-5"><?= number_format($account['current_balance'], 2) ?> <?= htmlspecialchars($account['currency']) ?></strong>
            </div>
            <div class="col-md-3 col-6">
                <small class="text-muted text-uppercase fw-bold d-block">Statement Period</small>
                <span class="text-muted small"><?= htmlspecialchars($start_date) ?> to <?= htmlspecialchars($end_date) ?></span>
            </div>
        </div>

        <!-- Period Movement Summary -->
        <div class="row g-2 mb-4">
            <div class="col-md-4">
                <div class="p-2 border rounded text-center bg-white">
                    <small class="text-muted fw-bold d-block">Total Inflow (Income)</small>
                    <strong class="text-success fs-6">+<?= number_format($totalIn, 2) ?> <?= htmlspecialchars($account['currency']) ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-2 border rounded text-center bg-white">
                    <small class="text-muted fw-bold d-block">Total Outflow (Expenses)</small>
                    <strong class="text-danger fs-6">-<?= number_format($totalOut, 2) ?> <?= htmlspecialchars($account['currency']) ?></strong>
                </div>
            </div>
            <div class="col-md-4">
                <div class="p-2 border rounded text-center bg-white">
                    <small class="text-muted fw-bold d-block">Net Period Movement</small>
                    <strong class="<?= $netMovement >= 0 ? 'text-primary' : 'text-danger' ?> fs-6">
                        <?= $netMovement >= 0 ? '+' : '' ?><?= number_format($netMovement, 2) ?> <?= htmlspecialchars($account['currency']) ?>
                    </strong>
                </div>
            </div>
        </div>

        <!-- Transactions Table -->
        <h5 class="fw-bold mb-3 text-dark"><i class="fa-solid fa-list-check me-2 text-primary"></i>Transaction Entries (<?= count($transactions) ?>)</h5>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle">
                <thead class="table-dark">
                    <tr>
                        <th style="width: 12%;">Date</th>
                        <th style="width: 12%;">Type</th>
                        <th style="width: 18%;">Category</th>
                        <th style="width: 32%;">Description</th>
                        <th style="width: 14%;" class="text-end">Amount</th>
                        <th style="width: 12%;">Recorded By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($transactions)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                No transactions found within selected date range.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($transactions as $t): ?>
                            <tr>
                                <td><small><?= htmlspecialchars($t['transaction_date']) ?></small></td>
                                <td>
                                    <?php if ($t['transaction_type'] === 'Income'): ?>
                                        <span class="badge bg-success">Income</span>
                                    <?php elseif ($t['transaction_type'] === 'Payroll'): ?>
                                        <span class="badge bg-warning text-dark">Payroll</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Expense</span>
                                    <?php endif; ?>
                                </td>
                                <td><strong class="text-dark small"><?= htmlspecialchars($t['category']) ?></strong></td>
                                <td><small class="text-muted"><?= htmlspecialchars($t['description'] ?: '—') ?></small></td>
                                <td class="text-end fw-bold <?= $t['transaction_type'] === 'Income' ? 'text-success' : 'text-danger' ?>">
                                    <?= $t['transaction_type'] === 'Income' ? '+' : '-' ?><?= number_format($t['amount'], 2) ?>
                                </td>
                                <td><small class="text-muted"><?= htmlspecialchars($t['recorder_name'] ?? 'System') ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Footer Sign-off -->
        <div class="row pt-5 mt-4 border-top text-muted small">
            <div class="col-6">
                <div>Prepared By: <strong>Finance Department</strong></div>
                <div>Status: <strong>System Reconciled &amp; Certified</strong></div>
            </div>
            <div class="col-6 text-end">
                <div class="mb-4">Authorized Signature &amp; Official Stamp:</div>
                <div>___________________________________</div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
