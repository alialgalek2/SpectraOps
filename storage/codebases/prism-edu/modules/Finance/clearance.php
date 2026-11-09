<?php
/**
 * Finance Clearance Module
 * Evaluates whether a student has cleared all financial obligations for a term.
 * Serves as backend engine and web interface for Report Card Financial Clearance.
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
 * Evaluate clearance for a given student and term.
 * Returns true if cleared, false otherwise.
 */
function evaluateStudentClearance(int $studentId, string $termName): bool {
    $pdo = getPDO();

    // 1. Check fee_invoices (primary system invoice table)
    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) 
            FROM fee_invoices 
            WHERE student_id = :sid 
              AND (term_name = :term OR :term_empty = "" OR :term_all = "all")
              AND status <> "Paid"
              AND (amount_due - COALESCE(paid_amount, amount_paid, 0)) > 0
        ');
        $stmt->execute([
            'sid'        => $studentId,
            'term'       => $termName,
            'term_empty' => $termName,
            'term_all'   => strtolower($termName)
        ]);
        $unpaidFeeInvoices = (int)$stmt->fetchColumn();
        if ($unpaidFeeInvoices > 0) {
            return false;
        }
    } catch (\Throwable $e) {}

    // 2. Check student_invoices if table exists
    try {
        $stmt = $pdo->prepare('
            SELECT COUNT(*) 
            FROM student_invoices 
            WHERE student_id = :sid 
              AND (term_name = :term OR :term_empty = "" OR :term_all = "all")
              AND status <> "Paid"
              AND (total_amount - COALESCE(paid_amount, 0)) > 0
        ');
        $stmt->execute([
            'sid'        => $studentId,
            'term'       => $termName,
            'term_empty' => $termName,
            'term_all'   => strtolower($termName)
        ]);
        $unpaidStudentInvoices = (int)$stmt->fetchColumn();
        if ($unpaidStudentInvoices > 0) {
            return false;
        }
    } catch (\Throwable $e) {}

    // 3. Check for negative balances in financial_transactions linked by student reference
    try {
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM financial_transactions WHERE transaction_type = "Expense" AND reference_id = :sid');
        $stmt->execute(['sid' => $studentId]);
        $totalExpense = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM financial_transactions WHERE transaction_type = "Income" AND reference_id = :sid');
        $stmt->execute(['sid' => $studentId]);
        $totalIncome = (float)$stmt->fetchColumn();

        if (($totalIncome - $totalExpense) < 0) {
            return false;
        }
    } catch (\Throwable $e) {}

    return true;
}

/**
 * Update or insert clearance record.
 */
function updateFinancialClearance(int $studentId, string $termName, ?string $notes = null): void {
    $pdo = getPDO();
    $isCleared = evaluateStudentClearance($studentId, $termName) ? 1 : 0;
    $clearedAt = $isCleared ? date('Y-m-d H:i:s') : null;

    $sql = 'INSERT INTO financial_clearance (student_id, term_name, is_cleared, cleared_at, notes) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                is_cleared = VALUES(is_cleared), 
                cleared_at = VALUES(cleared_at),
                notes = COALESCE(VALUES(notes), notes)';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$studentId, $termName, $isCleared, $clearedAt, $notes]);
}

/**
 * Manual administrative override for clearance.
 */
function overrideFinancialClearance(int $studentId, string $termName, bool $isCleared, string $notes = ''): bool {
    $pdo = getPDO();
    $clearedInt = $isCleared ? 1 : 0;
    $clearedAt  = $isCleared ? date('Y-m-d H:i:s') : null;

    $sql = 'INSERT INTO financial_clearance (student_id, term_name, is_cleared, cleared_at, notes) 
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                is_cleared = VALUES(is_cleared), 
                cleared_at = VALUES(cleared_at),
                notes = VALUES(notes)';
    $stmt = $pdo->prepare($sql);
    return $stmt->execute([$studentId, $termName, $clearedInt, $clearedAt, $notes]);
}

// Only execute Web UI if clearance.php is the primary script directly requested
$isDirectExecution = (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));
if (!$isDirectExecution) {
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

        if ($action === 'evaluate_single') {
            $sid  = intval($_POST['student_id'] ?? 0);
            $term = trim($_POST['term_name'] ?? '');
            if ($sid > 0 && !empty($term)) {
                updateFinancialClearance($sid, $term);
                $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl() ? "تمت إعادة تقييم براءة الذمة للطالب بنجاح!" : "Student clearance re-evaluated successfully!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: clearance.php?term_name=" . urlencode($term));
                exit();
            }
        } elseif ($action === 'batch_evaluate') {
            $term = trim($_POST['term_name'] ?? '');
            if (!empty($term)) {
                // Fetch all enrolled students
                $students = $pdo->query("SELECT student_id FROM students WHERE enrollment_status = 'Active' OR enrollment_status IS NULL")->fetchAll(PDO::FETCH_COLUMN);
                $count = 0;
                foreach ($students as $sid) {
                    updateFinancialClearance((int)$sid, $term);
                    $count++;
                }
                $_SESSION['flash_msg'] = (function_exists('is_rtl') && is_rtl()) 
                    ? "تم فحص واعتماد براءة الذمة بنجاح لعدد $count طالباً للفصل: $term"
                    : "Batch clearance check completed for $count students for term: $term";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: clearance.php?term_name=" . urlencode($term));
                exit();
            }
        } elseif ($action === 'override_clearance') {
            $sid     = intval($_POST['student_id'] ?? 0);
            $term    = trim($_POST['term_name'] ?? '');
            $cleared = (($_POST['is_cleared'] ?? '0') === '1');
            $notes   = trim($_POST['notes'] ?? '');

            if ($sid > 0 && !empty($term)) {
                overrideFinancialClearance($sid, $term, $cleared, $notes);
                $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl() ? "تم تحديث حالة الاعتماد المالي والملاحظات بنجاح!" : "Financial clearance override and notes updated successfully!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: clearance.php?term_name=" . urlencode($term));
                exit();
            }
        }
    }
}

// Term selection
$selectedTerm = trim($_GET['term_name'] ?? '');
$termsList = [];
try {
    $termsList = $pdo->query("SELECT DISTINCT term_name FROM fee_invoices WHERE term_name IS NOT NULL AND term_name != ''")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}
if (empty($termsList)) {
    try {
        $termsList = $pdo->query("SELECT DISTINCT term_name FROM academic_calendar WHERE term_name IS NOT NULL AND term_name != ''")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}
}
if (empty($termsList)) {
    $termsList = ['Term 1 2026', 'Term 2 2026', 'Academic Year 2025-2026'];
}
if (empty($selectedTerm)) {
    $selectedTerm = $termsList[0];
}

// Filter by Class and Status
$filterClass  = trim($_GET['class_id'] ?? 'all');
$filterStatus = trim($_GET['status'] ?? 'all');

// Fetch Classes for dropdown
$classes = [];
try {
    $classes = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Query students and their clearance status
$query = "
    SELECT s.student_id, s.admission_no, u.full_name as student_name, c.class_name,
           fc.clearance_id, fc.is_cleared, fc.cleared_at, fc.notes,
           COALESCE((
               SELECT SUM(fi.amount_due) 
               FROM fee_invoices fi 
               WHERE fi.student_id = s.student_id AND (fi.term_name = :term OR :term_empty = '')
           ), 0) as total_invoiced,
           COALESCE((
               SELECT SUM(fi.amount_due - COALESCE(fi.paid_amount, fi.amount_paid, 0)) 
               FROM fee_invoices fi 
               WHERE fi.student_id = s.student_id AND (fi.term_name = :term_term OR :term_term_empty = '') AND fi.status <> 'Paid'
           ), 0) as outstanding_due
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN financial_clearance fc ON s.student_id = fc.student_id AND fc.term_name = :term_fc
    WHERE (s.enrollment_status = 'Active' OR s.enrollment_status IS NULL)
";

$params = [
    'term'             => $selectedTerm,
    'term_empty'       => $selectedTerm,
    'term_term'        => $selectedTerm,
    'term_term_empty'  => $selectedTerm,
    'term_fc'          => $selectedTerm
];

if (!empty($filterClass) && $filterClass !== 'all') {
    $query .= " AND s.class_id = :class_id";
    $params['class_id'] = intval($filterClass);
}

if ($filterStatus === 'cleared') {
    $query .= " AND fc.is_cleared = 1";
} elseif ($filterStatus === 'pending') {
    $query .= " AND (fc.is_cleared = 0 OR fc.is_cleared IS NULL)";
}

$query .= " ORDER BY u.full_name ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$studentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$totalEvaluated = count($studentRecords);
$totalCleared   = 0;
$totalPending   = 0;
foreach ($studentRecords as $rec) {
    if (!empty($rec['is_cleared']) && (int)$rec['is_cleared'] === 1) {
        $totalCleared++;
    } else {
        $totalPending++;
    }
}
?>
<!DOCTYPE html>
<html <?= function_exists('get_html_dir') ? get_html_dir() : 'dir="ltr"' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= function_exists('is_rtl') && is_rtl() ? 'براءة الذمة والاعتماد المالي' : 'Financial Clearance Engine' ?> - <?= function_exists('__') ? __('fees_and_finance') : 'Finance Hub' ?></title>
    <?= function_exists('get_bootstrap_css') ? get_bootstrap_css() : '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-banner {
            background: linear-gradient(135deg, #4338ca 0%, #6366f1 100%);
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
            'active_title' => is_rtl() ? 'محرك براءة الذمة والاعتماد المالي' : 'Financial Clearance Engine',
            'back_url' => 'index.php',
            'extra_nav_items' => [
                '<a href="fees.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-receipt me-1"></i> ' . (is_rtl() ? 'الفواتير' : 'Invoices') . '</a>',
                '<a href="daily_settlement.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-cash-register me-1"></i> ' . (is_rtl() ? 'المطابقة اليومية' : 'Daily Settlement') . '</a>'
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
                    <i class="fa-solid fa-certificate text-warning me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'براءة الذمة لإصدار الشهادات والنتائج' : 'Exam & Report Card Release Verification' ?>
                </span>
                <h2 class="fw-bold mb-1"><i class="fa-solid fa-stamp me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'براءة الذمة والاعتماد المالي للطلاب' : 'Student Financial Clearance Engine' ?></h2>
                <p class="mb-0 text-white-50"><?= (function_exists('is_rtl') && is_rtl()) ? 'التحقق الآلي من سداد كافة الرسوم الدراسية قبل طباعة بطاقات الدرجات أو إصدار الشهادات.' : 'Automated validation of student tuition obligations before releasing report cards and official credentials.' ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap no-print">
                <form method="POST" action="clearance.php" class="d-inline" onsubmit="return confirm('<?= (function_exists('is_rtl') && is_rtl()) ? 'تشغيل الفحص الشامل لبراءة الذمة لجميع الطلاب لهذا الفصل؟' : 'Run batch clearance verification for all students for this term?' ?>');">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="batch_evaluate">
                    <input type="hidden" name="term_name" value="<?= htmlspecialchars($selectedTerm) ?>">
                    <button type="submit" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm">
                        <i class="fa-solid fa-bolt me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'فحص واعتماد شامل' : 'Run Batch Clearance' ?>
                    </button>
                </form>
                <button type="button" onclick="window.print()" class="btn btn-light text-dark fw-bold px-3 py-2 shadow-sm">
                    <i class="fa-solid fa-print me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'طباعة القائمة' : 'Print List' ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'الطلاب المبرأة ذمتهم' : 'Cleared Students' ?></span>
                        <h3 class="fw-bold mb-0 text-success mt-1"><?= number_format($totalCleared) ?></h3>
                    </div>
                    <div class="p-3 bg-success bg-opacity-10 text-success rounded-circle">
                        <i class="fa-solid fa-circle-check fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'مطالبات مالية معلقة' : 'Pending Obligations' ?></span>
                        <h3 class="fw-bold mb-0 text-danger mt-1"><?= number_format($totalPending) ?></h3>
                    </div>
                    <div class="p-3 bg-danger bg-opacity-10 text-danger rounded-circle">
                        <i class="fa-solid fa-circle-xmark fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'إجمالي الطلاب المفحوصين' : 'Evaluated Students' ?></span>
                        <h3 class="fw-bold mb-0 text-primary mt-1"><?= number_format($totalEvaluated) ?></h3>
                    </div>
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-circle">
                        <i class="fa-solid fa-users fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter & Term Selector -->
    <div class="card shadow-sm border-0 mb-4 bg-white no-print">
        <div class="card-body p-3">
            <form method="GET" action="clearance.php" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1"><?= (function_exists('is_rtl') && is_rtl()) ? 'الفصل الدراسي المستهدف:' : 'Target Academic Term:' ?></label>
                    <select name="term_name" class="form-select form-select-sm" onchange="this.form.submit()">
                        <?php foreach ($termsList as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= ($selectedTerm === $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1"><?= (function_exists('is_rtl') && is_rtl()) ? 'تصفية حسب الصف:' : 'Filter Class:' ?></label>
                    <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all">-- <?= (function_exists('is_rtl') && is_rtl()) ? 'جميع الصفوف' : 'All Classes' ?> --</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['class_id'] ?>" <?= ($filterClass == $c['class_id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1"><?= (function_exists('is_rtl') && is_rtl()) ? 'حالة الاعتماد:' : 'Clearance Status:' ?></label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?= ($filterStatus === 'all') ? 'selected' : '' ?>><?= (function_exists('is_rtl') && is_rtl()) ? 'الكل' : 'All Statuses' ?></option>
                        <option value="cleared" <?= ($filterStatus === 'cleared') ? 'selected' : '' ?>><?= (function_exists('is_rtl') && is_rtl()) ? 'مبرأة الذمة (خالص)' : 'Cleared' ?></option>
                        <option value="pending" <?= ($filterStatus === 'pending') ? 'selected' : '' ?>><?= (function_exists('is_rtl') && is_rtl()) ? 'معلق (عليه مستحقات)' : 'Pending / Unpaid' ?></option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end gap-1">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fa-solid fa-filter me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'تطبيق' : 'Filter' ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Student Clearance Table -->
    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-clipboard-check text-indigo me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'سجل براءة الذمة للطلاب - ' . htmlspecialchars($selectedTerm) : 'Student Financial Clearance Registry - ' . htmlspecialchars($selectedTerm) ?>
            </h5>
            <span class="badge bg-secondary"><?= count($studentRecords) ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'طالب' : 'Records' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'اسم الطالب' : 'Student Name' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الرقم الأكاديمي' : 'Admission No' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الصف' : 'Class' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'إجمالي الرسوم' : 'Total Invoiced' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'المتبقي المستحق' : 'Outstanding Dues' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'حالة براءة الذمة' : 'Clearance Status' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'ملاحظات وتاريخ الاعتماد' : 'Cleared At / Notes' ?></th>
                        <th class="text-end pe-3 no-print"><?= (function_exists('is_rtl') && is_rtl()) ? 'الإجراءات' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($studentRecords)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-stamp fa-3x mb-3 text-secondary d-block"></i>
                                <?= (function_exists('is_rtl') && is_rtl()) ? 'لا توجد بيانات طلاب مطابقة للمعايير المحددة.' : 'No student records found matching current filters.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($studentRecords as $idx => $row): 
                            $isCleared = !empty($row['is_cleared']) && (int)$row['is_cleared'] === 1;
                        ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $idx + 1 ?></td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($row['student_name']) ?></td>
                                <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($row['admission_no'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($row['class_name'] ?? '—') ?></td>
                                <td>$<?= number_format($row['total_invoiced'], 2) ?></td>
                                <td>
                                    <?php if ($row['outstanding_due'] > 0): ?>
                                        <span class="fw-bold text-danger">$<?= number_format($row['outstanding_due'], 2) ?></span>
                                    <?php else: ?>
                                        <span class="text-success">$0.00</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($isCleared): ?>
                                        <span class="badge bg-success py-1 px-2"><i class="fa-solid fa-circle-check me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'مبرأ الذمة' : 'Cleared' ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger py-1 px-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'معلق الذمة' : 'Uncleared' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted">
                                    <?php if ($isCleared && !empty($row['cleared_at'])): ?>
                                        <div><i class="fa-solid fa-clock me-1"></i> <?= date('Y-m-d H:i', strtotime($row['cleared_at'])) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($row['notes'])): ?>
                                        <div class="text-dark fst-italic"><?= htmlspecialchars($row['notes']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end pe-3 no-print">
                                    <form method="POST" action="clearance.php" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="evaluate_single">
                                        <input type="hidden" name="student_id" value="<?= $row['student_id'] ?>">
                                        <input type="hidden" name="term_name" value="<?= htmlspecialchars($selectedTerm) ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm py-1 px-2 me-1" title="<?= (function_exists('is_rtl') && is_rtl()) ? 'إعادة تقييم الحساب' : 'Re-evaluate' ?>">
                                            <i class="fa-solid fa-rotate"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-outline-secondary btn-sm py-1 px-2 override-btn"
                                            data-id="<?= $row['student_id'] ?>"
                                            data-name="<?= htmlspecialchars($row['student_name']) ?>"
                                            data-cleared="<?= $isCleared ? '1' : '0' ?>"
                                            data-notes="<?= htmlspecialchars($row['notes'] ?? '') ?>"
                                            title="<?= (function_exists('is_rtl') && is_rtl()) ? 'تعديل أو اعتماد استثنائي' : 'Override / Notes' ?>">
                                        <i class="fa-solid fa-pen-nib"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Clearance Override & Notes -->
<div class="modal fade no-print" id="overrideModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="clearance.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="override_clearance">
                <input type="hidden" name="student_id" id="override_student_id">
                <input type="hidden" name="term_name" value="<?= htmlspecialchars($selectedTerm) ?>">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-stamp me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'تعديل حالة براءة الذمة للطالب' : 'Override Clearance Status' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'اسم الطالب:' : 'Student:' ?></label>
                        <input type="text" id="override_student_name" class="form-control" readonly>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'حالة الاعتماد المالي:' : 'Clearance Decision:' ?></label>
                        <select name="is_cleared" id="override_is_cleared" class="form-select" required>
                            <option value="1"><?= (function_exists('is_rtl') && is_rtl()) ? 'مبرأ الذمة (معتمد لإصدار الشهادة)' : 'Cleared (Approved to release report card)' ?></option>
                            <option value="0"><?= (function_exists('is_rtl') && is_rtl()) ? 'معلق الذمة (حجب الشهادة والنتائج)' : 'Uncleared (Hold report card & grades)' ?></option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'الملاحظات / سبب التعديل الاستثنائي:' : 'Administrative Notes / Justification:' ?></label>
                        <textarea name="notes" id="override_notes" class="form-control" rows="3" placeholder="<?= (function_exists('is_rtl') && is_rtl()) ? 'أدخل أي ملاحظات أو موافقة من الإدارة المالية...' : 'E.g., Special clearance approved by management with payment promise...' ?>"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= (function_exists('is_rtl') && is_rtl()) ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa-solid fa-save me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'حفظ الاعتماد' : 'Save Override' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.override-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('override_student_id').value = this.dataset.id;
        document.getElementById('override_student_name').value = this.dataset.name;
        document.getElementById('override_is_cleared').value = this.dataset.cleared;
        document.getElementById('override_notes').value = this.dataset.notes;
        new bootstrap.Modal(document.getElementById('overrideModal')).show();
    });
});
</script>
</body>
</html>
