<?php
/**
 * Finance Fee Structures Module
 * CRUD operations and Management UI for Fee Structures.
 */

// Safe bootstrap
if (file_exists(__DIR__ . '/../../core/init.php')) {
    require_once __DIR__ . '/../../core/init.php';
} elseif (file_exists(__DIR__ . '/../../config/db.php')) {
    require_once __DIR__ . '/../../config/db.php';
}

if (!function_exists('getPDO')) {
    function getPDO(): PDO {
        global $pdo;
        if ($pdo instanceof PDO) return $pdo;
        if (class_exists('Database')) {
            return Database::getConnection();
        }
        throw new RuntimeException('Database connection not established.');
    }
}

/**
 * Create a new fee structure.
 */
function createFeeStructure(string $gradeLevel, string $termName, string $feeName, float $amount): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare('INSERT INTO fee_structures (grade_level, term_name, fee_name, amount) VALUES (:grade, :term, :fee, :amt)');
    $stmt->execute([
        'grade' => $gradeLevel,
        'term'  => $termName,
        'fee'   => $feeName,
        'amt'   => $amount,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Retrieve fee structures for a specific grade and term.
 */
function getFeeStructures(string $gradeLevel, string $termName): array {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM fee_structures WHERE grade_level = :grade AND term_name = :term ORDER BY fee_name ASC');
    $stmt->execute(['grade' => $gradeLevel, 'term' => $termName]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Retrieve all fee structures with optional filters.
 */
function getAllFeeStructures(?string $gradeLevel = null, ?string $termName = null): array {
    $pdo = getPDO();
    $sql = 'SELECT * FROM fee_structures WHERE 1=1';
    $params = [];
    if (!empty($gradeLevel) && $gradeLevel !== 'all') {
        $sql .= ' AND grade_level = :grade';
        $params['grade'] = $gradeLevel;
    }
    if (!empty($termName) && $termName !== 'all') {
        $sql .= ' AND term_name = :term';
        $params['term'] = $termName;
    }
    $sql .= ' ORDER BY grade_level ASC, term_name ASC, fee_name ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Update an existing fee structure.
 */
function updateFeeStructure(int $feeStructureId, array $fields): bool {
    $pdo = getPDO();
    $allowed = ['grade_level', 'term_name', 'fee_name', 'amount'];
    $setParts = [];
    $params = [];
    foreach ($fields as $col => $val) {
        if (in_array($col, $allowed)) {
            $setParts[] = "{$col} = :{$col}";
            $params[$col] = $val;
        }
    }
    if (empty($setParts)) {
        return false;
    }
    $params['id'] = $feeStructureId;
    $sql = 'UPDATE fee_structures SET ' . implode(', ', $setParts) . ' WHERE fee_structure_id = :id';
    $stmt = $pdo->prepare($sql);
    return $stmt->execute($params);
}

/**
 * Delete a fee structure.
 */
function deleteFeeStructure(int $feeStructureId): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare('DELETE FROM fee_structures WHERE fee_structure_id = :id');
    return $stmt->execute(['id' => $feeStructureId]);
}

// If invoked from CLI or testing script (and not direct web entry), do not render web UI
$isDirectExecution = (php_sapi_name() !== 'cli') || (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__));
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
        $msg = is_rtl() ? "خطأ في التحقق من صحة الطلب (CSRF token)." : "Security token expired or invalid.";
        $msg_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_fee_structure') {
            $grade = trim($_POST['grade_level'] ?? '');
            $term  = trim($_POST['term_name'] ?? '');
            $fee   = trim($_POST['fee_name'] ?? '');
            $amt   = floatval($_POST['amount'] ?? 0.0);

            if (!empty($grade) && !empty($term) && !empty($fee) && $amt >= 0) {
                createFeeStructure($grade, $term, $fee, $amt);
                $_SESSION['flash_msg'] = is_rtl() ? "تم إنشاء بند هيكل الرسوم بنجاح!" : "Fee structure item created successfully!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: fee_structures.php");
                exit();
            } else {
                $msg = is_rtl() ? "يرجى ملء جميع الحقول المطلوبة بشكل صحيح." : "Please fill all required fields properly.";
                $msg_type = "danger";
            }
        } elseif ($action === 'update_fee_structure') {
            $id    = intval($_POST['fee_structure_id'] ?? 0);
            $grade = trim($_POST['grade_level'] ?? '');
            $term  = trim($_POST['term_name'] ?? '');
            $fee   = trim($_POST['fee_name'] ?? '');
            $amt   = floatval($_POST['amount'] ?? 0.0);

            if ($id > 0 && !empty($grade) && !empty($term) && !empty($fee) && $amt >= 0) {
                updateFeeStructure($id, [
                    'grade_level' => $grade,
                    'term_name'   => $term,
                    'fee_name'    => $fee,
                    'amount'      => $amt
                ]);
                $_SESSION['flash_msg'] = is_rtl() ? "تم تحديث بند الرسوم بنجاح!" : "Fee structure item updated successfully!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: fee_structures.php");
                exit();
            } else {
                $msg = is_rtl() ? "بيانات التحديث غير صالحة." : "Invalid update data provided.";
                $msg_type = "danger";
            }
        } elseif ($action === 'delete_fee_structure') {
            $id = intval($_POST['fee_structure_id'] ?? 0);
            if ($id > 0) {
                deleteFeeStructure($id);
                $_SESSION['flash_msg'] = is_rtl() ? "تم حذف بند الرسوم بنجاح!" : "Fee structure item deleted successfully!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: fee_structures.php");
                exit();
            }
        }
    }
}

// Filters & Data Fetching
$filterGrade = trim($_GET['grade_level'] ?? 'all');
$filterTerm  = trim($_GET['term_name'] ?? 'all');

$feeItems = getAllFeeStructures($filterGrade, $filterTerm);

// Dropdown options
$distinctGrades = [];
$distinctTerms = [];
try {
    $distinctGrades = $pdo->query("SELECT DISTINCT grade_level FROM fee_structures ORDER BY grade_level ASC")->fetchAll(PDO::FETCH_COLUMN);
    $distinctTerms = $pdo->query("SELECT DISTINCT term_name FROM fee_structures ORDER BY term_name ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}

// Pull known grade levels from classes table if fee_structures is empty
if (empty($distinctGrades)) {
    try {
        $distinctGrades = $pdo->query("SELECT DISTINCT CONCAT('Grade ', grade_level) FROM classes WHERE grade_level IS NOT NULL ORDER BY grade_level ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}
}
if (empty($distinctGrades)) {
    $distinctGrades = ['Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'];
}

// Pull known terms from academic_calendar
if (empty($distinctTerms)) {
    try {
        $distinctTerms = $pdo->query("SELECT DISTINCT term_name FROM academic_calendar WHERE term_name IS NOT NULL AND term_name != ''")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}
}
if (empty($distinctTerms)) {
    $distinctTerms = ['Term 1 2026', 'Term 2 2026', 'Term 3 2026', 'Academic Year 2025-2026'];
}

// Metrics
$totalItems = count($feeItems);
$totalSum   = array_sum(array_column($feeItems, 'amount'));
$gradeCount = count(array_unique(array_column($feeItems, 'grade_level')));
?>
<!DOCTYPE html>
<html <?= function_exists('get_html_dir') ? get_html_dir() : 'dir="ltr"' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= function_exists('is_rtl') && is_rtl() ? 'هياكل الرسوم الدراسية' : 'Fee Structures' ?> - <?= function_exists('__') ? __('fees_and_finance') : 'Finance Hub' ?></title>
    <?= function_exists('get_bootstrap_css') ? get_bootstrap_css() : '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-banner {
            background: linear-gradient(135deg, #1e3a8a 0%, #3b82f6 100%);
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
    </style>
</head>
<body class="bg-light">

<!-- Top Navigation -->
<?php if (function_exists('render_universal_navbar')): ?>
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'هياكل وبنود الرسوم الدراسية' : 'Tuition & Fee Structures Engine',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="fees.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-receipt me-1"></i> ' . (is_rtl() ? 'الفواتير' : 'Invoices') . '</a>',
            '<a href="discounts.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-tags me-1"></i> ' . (is_rtl() ? 'الخصومات' : 'Discounts') . '</a>'
        ]
    ]) ?>
<?php else: ?>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php"><i class="fa-solid fa-vault me-2"></i>Finance Hub</a>
            <a href="index.php" class="btn btn-outline-light btn-sm ms-auto">Back to Hub</a>
        </div>
    </nav>
<?php endif; ?>

<div class="container py-4">

    <!-- Flash Alerts -->
    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= htmlspecialchars($msg_type) ?> alert-dismissible fade show shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="badge bg-white bg-opacity-25 text-white mb-2 px-3 py-1">
                    <i class="fa-solid fa-calculator text-warning me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'محرك التسعير الدراسي والرسوم' : 'Tuition Rate & Billing Engine' ?>
                </span>
                <h2 class="fw-bold mb-1"><i class="fa-solid fa-layer-group me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'هياكل الرسوم والبنود المعتمدة' : 'Fee Structures & Item Catalog' ?></h2>
                <p class="mb-0 text-white-50"><?= (function_exists('is_rtl') && is_rtl()) ? 'إدارة بنود وتكاليف الرسوم لكل مرحلة دراسية وفصل دراسي لتوليد الفواتير التلقائية.' : 'Define base tuition rates, bus transit fees, lab fees, and class schedules for automated billing.' ?></p>
            </div>
            <div>
                <button type="button" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#addFeeModal">
                    <i class="fa-solid fa-plus-circle me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'إضافة بند رسوم جديد' : 'New Fee Structure Item' ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'إجمالي بنود الرسوم' : 'Total Fee Items' ?></span>
                        <h3 class="fw-bold mb-0 text-primary mt-1"><?= number_format($totalItems) ?></h3>
                    </div>
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-circle">
                        <i class="fa-solid fa-list-check fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'المراحل المهيأة' : 'Configured Grades' ?></span>
                        <h3 class="fw-bold mb-0 text-success mt-1"><?= number_format($gradeCount) ?></h3>
                    </div>
                    <div class="p-3 bg-success bg-opacity-10 text-success rounded-circle">
                        <i class="fa-solid fa-graduation-cap fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'مجموع الرسوم المعروضة' : 'Displayed Sum Rate' ?></span>
                        <h3 class="fw-bold mb-0 text-dark mt-1">$<?= number_format($totalSum, 2) ?></h3>
                    </div>
                    <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-circle">
                        <i class="fa-solid fa-dollar-sign fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-body p-3">
            <form method="GET" action="fee_structures.php" class="row g-2 align-items-center">
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1"><?= (function_exists('is_rtl') && is_rtl()) ? 'تصفية حسب المرحلة الدراسية:' : 'Filter Grade Level:' ?></label>
                    <select name="grade_level" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all">-- <?= (function_exists('is_rtl') && is_rtl()) ? 'جميع المراحل' : 'All Grade Levels' ?> --</option>
                        <?php foreach ($distinctGrades as $g): ?>
                            <option value="<?= htmlspecialchars($g) ?>" <?= ($filterGrade === $g) ? 'selected' : '' ?>><?= htmlspecialchars($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted mb-1"><?= (function_exists('is_rtl') && is_rtl()) ? 'تصفية حسب الفصل الدراسي:' : 'Filter Academic Term:' ?></label>
                    <select name="term_name" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all">-- <?= (function_exists('is_rtl') && is_rtl()) ? 'جميع الفصول' : 'All Terms' ?> --</option>
                        <?php foreach ($distinctTerms as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= ($filterTerm === $t) ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa-solid fa-filter me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'تطبيق الفلتر' : 'Filter' ?></button>
                    <a href="fee_structures.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-rotate-left me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'إعادة ضبط' : 'Reset' ?></a>
                </div>
            </form>
        </div>
    </div>

    <!-- Fee Structures Table -->
    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-table-list text-primary me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'جدول بنود وهياكل الرسوم' : 'Fee Structure Records' ?>
            </h5>
            <span class="badge bg-secondary"><?= count($feeItems) ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'بند' : 'Items' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'اسم بند الرسوم' : 'Fee Item Name' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'المرحلة الدراسية' : 'Grade Level' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الفصل الدراسي' : 'Term / Academic Period' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'المبلغ المحدد' : 'Standard Amount' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'تاريخ الإنشاء' : 'Created' ?></th>
                        <th class="text-end pe-3"><?= (function_exists('is_rtl') && is_rtl()) ? 'الإجراءات' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($feeItems)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-receipt fa-3x mb-3 text-secondary d-block"></i>
                                <?= (function_exists('is_rtl') && is_rtl()) ? 'لا توجد بنود رسوم مسجلة تطابق هذا الفلتر. انقر على "إضافة بند رسوم جديد" للبدء.' : 'No fee structures found matching criteria. Click "New Fee Structure Item" to create one.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($feeItems as $idx => $row): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $idx + 1 ?></td>
                                <td class="fw-bold text-dark">
                                    <i class="fa-solid fa-file-invoice-dollar text-primary me-2"></i><?= htmlspecialchars($row['fee_name']) ?>
                                </td>
                                <td>
                                    <span class="badge bg-info text-dark"><?= htmlspecialchars($row['grade_level']) ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($row['term_name']) ?></span>
                                </td>
                                <td class="fw-bold text-success">
                                    $<?= number_format($row['amount'], 2) ?>
                                </td>
                                <td class="small text-muted">
                                    <?= date('Y-m-d', strtotime($row['created_at'])) ?>
                                </td>
                                <td class="text-end pe-3">
                                    <button type="button" class="btn btn-outline-primary btn-sm edit-btn me-1" 
                                            data-id="<?= $row['fee_structure_id'] ?>"
                                            data-grade="<?= htmlspecialchars($row['grade_level']) ?>"
                                            data-term="<?= htmlspecialchars($row['term_name']) ?>"
                                            data-name="<?= htmlspecialchars($row['fee_name']) ?>"
                                            data-amount="<?= $row['amount'] ?>">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </button>
                                    <form method="POST" action="fee_structures.php" class="d-inline" onsubmit="return confirm('<?= (function_exists('is_rtl') && is_rtl()) ? 'هل أنت متأكد من حذف هذا البند من هيكل الرسوم؟' : 'Are you sure you want to delete this fee item?' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="delete_fee_structure">
                                        <input type="hidden" name="fee_structure_id" value="<?= $row['fee_structure_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="fa-solid fa-trash-can"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add Fee Structure Item -->
<div class="modal fade" id="addFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="fee_structures.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="create_fee_structure">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus-circle me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'إضافة بند رسوم جديد' : 'New Fee Structure Item' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'المرحلة الدراسية / الصف:' : 'Grade Level / Class:' ?></label>
                        <input type="text" name="grade_level" class="form-control" placeholder="<?= (function_exists('is_rtl') && is_rtl()) ? 'مثال: Grade 10 أو الصف الأول المتوسط' : 'E.g., Grade 10' ?>" required list="gradeList">
                        <datalist id="gradeList">
                            <?php foreach ($distinctGrades as $dg): ?>
                                <option value="<?= htmlspecialchars($dg) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'الفصل الدراسي / السنة:' : 'Academic Term / Period:' ?></label>
                        <input type="text" name="term_name" class="form-control" placeholder="<?= (function_exists('is_rtl') && is_rtl()) ? 'مثال: Term 1 2026' : 'E.g., Term 1 2026' ?>" required list="termList">
                        <datalist id="termList">
                            <?php foreach ($distinctTerms as $dt): ?>
                                <option value="<?= htmlspecialchars($dt) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'اسم بند الرسوم:' : 'Fee Item Name:' ?></label>
                        <input type="text" name="fee_name" class="form-control" placeholder="<?= (function_exists('is_rtl') && is_rtl()) ? 'مثال: الرسوم الدراسية، رسوم النقل، رسوم المختبر' : 'E.g., Tuition Fee, Transportation, Lab Fee' ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'المبلغ المستحق ($):' : 'Standard Amount ($):' ?></label>
                        <input type="number" step="0.01" min="0" name="amount" class="form-control" placeholder="500.00" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= (function_exists('is_rtl') && is_rtl()) ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa-solid fa-save me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'حفظ البند' : 'Save Fee Item' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Fee Structure Item -->
<div class="modal fade" id="editFeeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="fee_structures.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="update_fee_structure">
                <input type="hidden" name="fee_structure_id" id="edit_fee_structure_id">
                <div class="modal-header bg-dark text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-pen-to-square me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'تعديل بند الرسوم' : 'Edit Fee Structure Item' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'المرحلة الدراسية / الصف:' : 'Grade Level / Class:' ?></label>
                        <input type="text" name="grade_level" id="edit_grade_level" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'الفصل الدراسي / السنة:' : 'Academic Term / Period:' ?></label>
                        <input type="text" name="term_name" id="edit_term_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'اسم بند الرسوم:' : 'Fee Item Name:' ?></label>
                        <input type="text" name="fee_name" id="edit_fee_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'المبلغ المستحق ($):' : 'Standard Amount ($):' ?></label>
                        <input type="number" step="0.01" min="0" name="amount" id="edit_amount" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= (function_exists('is_rtl') && is_rtl()) ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa-solid fa-check me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'تحديث التعديلات' : 'Update Changes' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('edit_fee_structure_id').value = this.dataset.id;
        document.getElementById('edit_grade_level').value = this.dataset.grade;
        document.getElementById('edit_term_name').value = this.dataset.term;
        document.getElementById('edit_fee_name').value = this.dataset.name;
        document.getElementById('edit_amount').value = this.dataset.amount;
        new bootstrap.Modal(document.getElementById('editFeeModal')).show();
    });
});
</script>
</body>
</html>
