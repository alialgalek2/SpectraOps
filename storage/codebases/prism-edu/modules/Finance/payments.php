<?php
require_once __DIR__ . '/../../core/init.php';

// Security: Strictly accessible ONLY to parents and admin
Auth::requireRole(['parent', 'admin']);

$success = "";
$error = "";

// Fetch school bank accounts
$banks = $pdo->query("SELECT * FROM bank_accounts")->fetchAll();

// Fetch children linked to this parent or all students for selection
$studentsStmt = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'student'");
$students = $studentsStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment'])) {
    $bank_id    = $_POST['bank_id'] ?? '';
    $student_id = $_POST['student_id'] ?? '';
    $ref_no     = trim($_POST['reference_number'] ?? '');
    $amount     = trim($_POST['amount'] ?? '');
    $payer      = trim($_POST['payer_name'] ?? '');

    if (!empty($bank_id) && !empty($student_id) && !empty($ref_no) && !empty($amount) && !empty($payer)) {
        try {
            // Optional: Find any pending/unpaid invoice for this student to link automatically
            $invStmt = $pdo->prepare("SELECT invoice_id FROM fee_invoices WHERE student_id = ? AND status != 'Paid' ORDER BY due_date ASC LIMIT 1");
            $invStmt->execute([$student_id]);
            $unpaid_invoice = $invStmt->fetch();
            $invoice_id = $unpaid_invoice ? $unpaid_invoice['invoice_id'] : null;

            // Insert transaction record into bank reconciliation queue
            $stmt = $pdo->prepare("INSERT INTO bank_transactions (bank_id, student_id, invoice_id, reference_number, amount, payer_name, status) VALUES (?, ?, ?, ?, ?, ?, 'Pending Verification')");
            $stmt->execute([$bank_id, $student_id, $invoice_id, $ref_no, $amount, $payer]);
            
            $success = "Bank deposit slip submitted successfully! The finance department will verify and post it to the ledger.";
        } catch (\PDOException $e) {
            $error = "Duplicate reference number or system error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required payment details.";
    }
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'تقديم إيصال السداد البنكي' : 'Submit Bank Payment' ?> - <?= __('portal_title') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">
<!-- Universal Top Navbar -->
<?php $back_url = (Auth::role() === 'parent') ? '../LMS/parent_portal.php' : 'fees_portal.php'; ?>
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'تقديم إيصال السداد البنكي' : 'Fee Payment Submission',
    'back_url' => $back_url,
    'extra_nav_items' => []
]) ?>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8 col-lg-7">
                <div class="card border border-border bg-card shadow-sm rounded-xl overflow-hidden">
                    <div class="card-header bg-primary text-white font-heading fw-bold py-3">
                        <i class="fa-solid fa-money-bill-transfer me-2"></i><?= is_rtl() ? 'تقديم إشعار تحويل / إيداع بنكي' : 'Submit Bank Transfer / Deposit Slip' ?>
                    </div>
                    <div class="card-body p-4">
                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
                                <i class="fa-solid fa-circle-check me-2"></i><?= htmlspecialchars($success) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اختر الطالب (الابن / الابنة):' : 'Select Student (Child):' ?></label>
                                <select name="student_id" class="form-select" required>
                                    <option value="">-- <?= is_rtl() ? 'اختر الطالب المسجل' : 'Choose Enrolled Child' ?> --</option>
                                    <?php foreach ($students as $stu): ?>
                                        <option value="<?= $stu['user_id'] ?>"><?= htmlspecialchars($stu['full_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الحساب البنكي للمدرسة المحول إليه:' : 'Select School Bank Account Deposited To:' ?></label>
                                <select name="bank_id" class="form-select" required>
                                    <option value="">-- <?= is_rtl() ? 'اختر الحساب المصرفي للمؤسسة' : 'Choose Institution Bank Account' ?> --</option>
                                    <?php foreach ($banks as $b): ?>
                                        <option value="<?= $b['bank_id'] ?>"><?= htmlspecialchars($b['bank_name']) ?> (A/C: <?= htmlspecialchars($b['account_number']) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'رقم الإشعار / المعاملة / رقم الإيداع:' : 'Bank Reference Number / Transaction ID / Deposit Slip Number:' ?></label>
                                <input type="text" name="reference_number" class="form-control" placeholder="e.g. TRX-99823411" required>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'المبلغ المسدد ($):' : 'Amount Paid ($):' ?></label>
                                    <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم المودع (ولي الأمر):' : 'Payer Full Name (Parent / Guardian):' ?></label>
                                    <input type="text" name="payer_name" class="form-control" value="<?= htmlspecialchars($_SESSION['full_name'] ?? '') ?>" required>
                                </div>
                            </div>

                            <button type="submit" name="submit_payment" class="btn btn-primary w-100 fw-bold py-2.5 shadow-sm rounded-lg mt-2">
                                <i class="fa-solid fa-paper-plane me-1"></i> <?= is_rtl() ? 'إرسال الإشعار للمطابقة والاعتماد' : 'Submit Receipt for Verification' ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>