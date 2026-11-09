<?php
/**
 * Finance Module - Tuition Invoices & Fee Ledger
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'finance']);

$role = Auth::role();
$user_id = Auth::id();
$msg = "";
$msg_type = "success";

if ($role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_invoice') {
    $student_id = intval($_POST['student_id']);
    $term_name  = trim($_POST['term_name']);
    $amount_due = floatval($_POST['amount_due']);
    $due_date   = trim($_POST['due_date']);

    if ($student_id > 0 && !empty($term_name) && $amount_due > 0) {
        $stmt = $pdo->prepare("INSERT INTO fee_invoices (student_id, term_name, amount_due, due_date, status) VALUES (?, ?, ?, ?, 'Unpaid')");
        $stmt->execute([$student_id, $term_name, $amount_due, $due_date]);
        $msg = "Fee invoice generated successfully!";
    } else {
        $msg = "Please fill in all mandatory invoice details.";
        $msg_type = "danger";
    }
}

$students = $pdo->query("SELECT s.student_id, u.full_name, c.class_name FROM students s JOIN users u ON s.user_id = u.user_id JOIN classes c ON s.class_id = c.class_id ORDER BY u.full_name ASC")->fetchAll();
$invoices = $pdo->query("
    SELECT f.*, u.full_name AS student_name, c.class_name 
    FROM fee_invoices f 
    JOIN students s ON f.student_id = s.student_id 
    JOIN users u ON s.user_id = u.user_id 
    JOIN classes c ON s.class_id = c.class_id 
    ORDER BY f.created_at DESC
")->fetchAll();
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('fees_and_finance') ?> - <?= is_rtl() ? 'فواتير الرسوم' : 'Tuition Invoices' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'هيكل الرسوم والالتزامات المالية' : 'Tuition & Fee Structures',
    'back_url' => 'fees_portal.php',
    'extra_nav_items' => [
        '<a href="fees_portal.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-building-columns me-1 text-primary"></i> ' . (is_rtl() ? 'الخزينة' : 'Treasury & Accounts') . '</a>',
        '<a href="payments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-receipt me-1 text-primary"></i> ' . (is_rtl() ? 'المدفوعات' : 'Payments') . '</a>'
    ]
]) ?>

<div class="container py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <h2 class="fw-bold font-heading mb-1 text-white"><i class="fa-solid fa-receipt me-2"></i><?= is_rtl() ? 'هياكل الرسوم وفواتير الطلاب' : 'Fee Management & Financial Ledger' ?></h2>
        <p class="mb-0 text-white-50 small"><?= is_rtl() ? 'إصدار الفواتير الدراسية، متابعة حالة التحصيل، وتدقيق المستحقات المالية.' : 'Issue tuition invoices, track payment status, and monitor student balances.' ?></p>
    </div>

    <?php if ($role === 'admin'): ?>
    <div class="card border border-border bg-card shadow-sm rounded-xl mb-4">
        <div class="card-header bg-card border-bottom border-border py-3 fw-bold text-foreground">
            <i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i><?= is_rtl() ? 'إصدار فاتورة رسوم جديدة' : 'Issue New Fee Invoice' ?>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="" class="row g-3">
                <input type="hidden" name="action" value="add_invoice">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الطالب:' : 'Student:' ?></label>
                    <select name="student_id" class="form-select" required>
                        <?php foreach ($students as $stu): ?>
                            <option value="<?= $stu['student_id'] ?>"><?= htmlspecialchars($stu['full_name']) ?> (<?= htmlspecialchars($stu['class_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الفصل / عنوان الرسوم:' : 'Term / Fee Title:' ?></label>
                    <input type="text" name="term_name" class="form-control" placeholder="<?= is_rtl() ? 'مثال: رسوم الفصل الدراسي الأول 2026' : 'E.g., Fall Term Tuition 2026' ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'المبلغ المستحق ($):' : 'Amount Due ($):' ?></label>
                    <input type="number" step="0.01" name="amount_due" class="form-control" placeholder="500.00" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'تاريخ الاستحقاق:' : 'Due Date:' ?></label>
                    <input type="date" name="due_date" class="form-control" required>
                </div>
                <div class="col-12 mt-3">
                    <button type="submit" class="btn btn-primary fw-bold px-4 py-2"><i class="fa-solid fa-paper-plane me-2"></i> <?= is_rtl() ? 'توليد الفاتورة' : 'Generate Invoice' ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
        <h5 class="fw-bold font-heading text-foreground mb-3"><i class="fa-solid fa-receipt text-success me-2"></i><?= is_rtl() ? 'سجل فواتير الطلاب' : 'Student Invoices Ledger' ?></h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= is_rtl() ? 'اسم الطالب' : 'Student Name' ?></th>
                        <th><?= is_rtl() ? 'الصف الدراسي' : 'Class' ?></th>
                        <th><?= is_rtl() ? 'الفصل / الوصف' : 'Term / Description' ?></th>
                        <th><?= is_rtl() ? 'المبلغ المستحق' : 'Amount Due' ?></th>
                        <th><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
                        <th><?= is_rtl() ? 'تاريخ الاستحقاق' : 'Due Date' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                        <tr>
                            <td class="fw-bold text-foreground"><?= htmlspecialchars($inv['student_name']) ?></td>
                            <td><?= htmlspecialchars($inv['class_name']) ?></td>
                            <td><?= htmlspecialchars($inv['term_name']) ?></td>
                            <td class="fw-bold text-destructive">$<?= number_format($inv['amount_due'], 2) ?></td>
                            <td>
                                <span class="badge bg-<?= $inv['status'] === 'Paid' ? 'success' : ($inv['status'] === 'Partially Paid' ? 'warning text-dark' : 'danger') ?>">
                                    <?= htmlspecialchars($inv['status']) ?>
                                </span>
                            </td>
                            <td class="small text-muted-foreground"><?= htmlspecialchars($inv['due_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($invoices)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted-foreground py-4"><?= is_rtl() ? 'لا توجد فواتير رسوم مسجلة حالياً.' : 'No tuition invoices recorded.' ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>