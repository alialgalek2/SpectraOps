<?php
/**
 * Finance Discounts Module
 * Helper functions and Management UI for Multi-Child and Sibling Discounts.
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
 * Create a sibling discount group for a family.
 *
 * @param int   $familyId        The family identifier (parent_id).
 * @param float $discountPercent Discount percentage (e.g., 10.00 for 10%).
 * @return int  The newly created discount group ID.
 */
function createSiblingDiscountGroup(int $familyId, float $discountPercent): int {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "INSERT INTO sibling_discount_groups (family_id, discount_percent) 
         VALUES (:family_id, :discount_percent)
         ON DUPLICATE KEY UPDATE discount_percent = :discount_percent_upd"
    );
    $stmt->execute([
        ':family_id' => $familyId,
        ':discount_percent' => $discountPercent,
        ':discount_percent_upd' => $discountPercent
    ]);
    
    $lastId = (int)$pdo->lastInsertId();
    if ($lastId === 0) {
        $check = $pdo->prepare("SELECT id FROM sibling_discount_groups WHERE family_id = ?");
        $check->execute([$familyId]);
        $lastId = (int)$check->fetchColumn();
    }
    return $lastId;
}

/**
 * Assign a student to an existing sibling discount group.
 *
 * @param int $studentId The student identifier.
 * @param int $groupId   The sibling discount group identifier.
 */
function assignStudentToDiscountGroup(int $studentId, int $groupId): void {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "REPLACE INTO student_sibling_discount (student_id, group_id) VALUES (:student_id, :group_id)"
    );
    $stmt->execute([
        ':student_id' => $studentId,
        ':group_id'   => $groupId,
    ]);
}

/**
 * Retrieve the discount percentage applicable to a specific student.
 * Returns 0 if the student is not part of any discount group.
 *
 * @param int $studentId The student identifier.
 * @return float Discount percentage.
 */
function getStudentSiblingDiscount(int $studentId): float {
    $pdo = getPDO();
    $stmt = $pdo->prepare(
        "SELECT sdg.discount_percent FROM student_sibling_discount ssd " .
        "JOIN sibling_discount_groups sdg ON ssd.group_id = sdg.id " .
        "WHERE ssd.student_id = :student_id"
    );
    $stmt->execute([':student_id' => $studentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (float)$row['discount_percent'] : 0.0;
}

/**
 * Remove a student from any sibling discount group.
 */
function removeStudentFromDiscountGroup(int $studentId): bool {
    $pdo = getPDO();
    $stmt = $pdo->prepare("DELETE FROM student_sibling_discount WHERE student_id = ?");
    return $stmt->execute([$studentId]);
}

/**
 * Delete a sibling discount group.
 */
function deleteSiblingDiscountGroup(int $groupId): bool {
    $pdo = getPDO();
    $pdo->prepare("DELETE FROM student_sibling_discount WHERE group_id = ?")->execute([$groupId]);
    $stmt = $pdo->prepare("DELETE FROM sibling_discount_groups WHERE id = ?");
    return $stmt->execute([$groupId]);
}

// If invoked from CLI or testing script, do not render web UI
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
        $msg = function_exists('is_rtl') && is_rtl() ? "خطأ في التحقق من صحة الطلب (CSRF token)." : "Security token expired or invalid.";
        $msg_type = "danger";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_group') {
            $family_id = intval($_POST['family_id'] ?? 0);
            $discount  = floatval($_POST['discount_percent'] ?? 0.0);
            $student_ids = $_POST['student_ids'] ?? [];

            if ($family_id > 0 && $discount > 0 && $discount <= 100) {
                $groupId = createSiblingDiscountGroup($family_id, $discount);
                
                // Assign selected students if any
                if (!empty($student_ids) && is_array($student_ids)) {
                    foreach ($student_ids as $sid) {
                        assignStudentToDiscountGroup(intval($sid), $groupId);
                    }
                }

                $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl() ? "تم إنشاء مجموعة خصم الأخوة بنجاح!" : "Sibling discount group created successfully!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: discounts.php");
                exit();
            } else {
                $msg = function_exists('is_rtl') && is_rtl() ? "يرجى تحديد ولي الأمر ونسبة خصم صالحة (بين 1% و 100%)." : "Please select a family and specify a valid discount rate (between 1% and 100%).";
                $msg_type = "danger";
            }
        } elseif ($action === 'assign_student') {
            $student_id = intval($_POST['student_id'] ?? 0);
            $group_id   = intval($_POST['group_id'] ?? 0);

            if ($student_id > 0 && $group_id > 0) {
                assignStudentToDiscountGroup($student_id, $group_id);
                $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl() ? "تم تعيين الطالب في مجموعة الخصم بنجاح!" : "Student assigned to discount group successfully!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: discounts.php");
                exit();
            } else {
                $msg = function_exists('is_rtl') && is_rtl() ? "يرجى اختيار الطالب ومجموعة الخصم." : "Please choose both the student and the discount group.";
                $msg_type = "danger";
            }
        } elseif ($action === 'remove_student') {
            $student_id = intval($_POST['student_id'] ?? 0);
            if ($student_id > 0) {
                removeStudentFromDiscountGroup($student_id);
                $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl() ? "تم إلغاء خصم الطالب بنجاح." : "Student discount removed.";
                $_SESSION['flash_msg_type'] = "info";
                header("Location: discounts.php");
                exit();
            }
        } elseif ($action === 'delete_group') {
            $group_id = intval($_POST['group_id'] ?? 0);
            if ($group_id > 0) {
                deleteSiblingDiscountGroup($group_id);
                $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl() ? "تم حذف مجموعة الخصم بنجاح." : "Discount group deleted successfully.";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: discounts.php");
                exit();
            }
        }
    }
}

// Fetch Sibling Groups with Parent/Family names & student count
$groups = [];
try {
    $stmt = $pdo->query("
        SELECT g.*, 
               u.full_name as parent_name,
               u.email as parent_email,
               u.phone as parent_phone,
               COUNT(ssd.student_id) as student_count
        FROM sibling_discount_groups g
        LEFT JOIN parents p ON g.family_id = p.parent_id
        LEFT JOIN users u ON p.user_id = u.user_id
        LEFT JOIN student_sibling_discount ssd ON g.id = ssd.group_id
        GROUP BY g.id
        ORDER BY g.created_at DESC
    ");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch All Students currently receiving discounts
$discountedStudents = [];
try {
    $stmt = $pdo->query("
        SELECT ssd.student_id, ssd.group_id, ssd.created_at as assigned_at,
               g.discount_percent,
               s.admission_no,
               u.full_name as student_name,
               c.class_name,
               pu.full_name as parent_name
        FROM student_sibling_discount ssd
        JOIN sibling_discount_groups g ON ssd.group_id = g.id
        JOIN students s ON ssd.student_id = s.student_id
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        LEFT JOIN parents p ON g.family_id = p.parent_id
        LEFT JOIN users pu ON p.user_id = pu.user_id
        ORDER BY u.full_name ASC
    ");
    $discountedStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch Parents list for modal dropdown
$parentsList = [];
try {
    $parentsList = $pdo->query("
        SELECT p.parent_id, u.full_name, u.phone,
               COUNT(s.student_id) as children_count
        FROM parents p
        JOIN users u ON p.user_id = u.user_id
        LEFT JOIN students s ON p.parent_id = s.parent_id
        GROUP BY p.parent_id
        ORDER BY u.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch All Students for single assignment modal
$allStudents = [];
try {
    $allStudents = $pdo->query("
        SELECT s.student_id, s.parent_id, s.admission_no, u.full_name, c.class_name
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        WHERE s.enrollment_status = 'Active' OR s.enrollment_status IS NULL
        ORDER BY u.full_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Metrics
$totalGroups = count($groups);
$totalDiscountedStudents = count($discountedStudents);
$avgDiscount = $totalGroups > 0 ? (array_sum(array_column($groups, 'discount_percent')) / $totalGroups) : 0.0;
?>
<!DOCTYPE html>
<html <?= function_exists('get_html_dir') ? get_html_dir() : 'dir="ltr"' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= function_exists('is_rtl') && is_rtl() ? 'خصومات الأخوة والأشقاء' : 'Multi-Child Sibling Discounts' ?> - <?= function_exists('__') ? __('fees_and_finance') : 'Finance Hub' ?></title>
    <?= function_exists('get_bootstrap_css') ? get_bootstrap_css() : '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-banner {
            background: linear-gradient(135deg, #047857 0%, #10b981 100%);
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
        'active_title' => is_rtl() ? 'إدارة خصومات الأخوة والأشقاء' : 'Sibling & Multi-Child Discounts',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="fee_structures.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-layer-group me-1"></i> ' . (is_rtl() ? 'هياكل الرسوم' : 'Fee Structures') . '</a>',
            '<a href="fees.php" class="btn btn-outline-secondary btn-sm"><i class="fa-solid fa-receipt me-1"></i> ' . (is_rtl() ? 'الفواتير' : 'Invoices') . '</a>'
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
                    <i class="fa-solid fa-people-roof text-warning me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'سياسات الدعم والخصومات العائلية' : 'Family Support & Tuition Relief' ?>
                </span>
                <h2 class="fw-bold mb-1"><i class="fa-solid fa-tags me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'خصومات الأخوة وتخفيضات العائلات' : 'Multi-Child Sibling Discounts' ?></h2>
                <p class="mb-0 text-white-50"><?= (function_exists('is_rtl') && is_rtl()) ? 'تهيئة وتطبيق نسب الخصم المئوية للأسر التي لديها أكثر من طالب مسجل في المدرسة.' : 'Manage family discount groups and automate percentage tuition deductions for multi-child households.' ?></p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="button" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#createGroupModal">
                    <i class="fa-solid fa-user-group me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'إنشاء مجموعة خصم أسرة' : 'New Sibling Group' ?>
                </button>
                <button type="button" class="btn btn-light text-dark fw-bold px-3 py-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#assignStudentModal">
                    <i class="fa-solid fa-user-plus me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'تعيين طالب في مجموعة' : 'Assign Student' ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'مجموعات الأسر المشمولة' : 'Sibling Groups' ?></span>
                        <h3 class="fw-bold mb-0 text-success mt-1"><?= number_format($totalGroups) ?></h3>
                    </div>
                    <div class="p-3 bg-success bg-opacity-10 text-success rounded-circle">
                        <i class="fa-solid fa-people-roof fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'الطلاب المستفيدون من الخصم' : 'Beneficiary Students' ?></span>
                        <h3 class="fw-bold mb-0 text-primary mt-1"><?= number_format($totalDiscountedStudents) ?></h3>
                    </div>
                    <div class="p-3 bg-primary bg-opacity-10 text-primary rounded-circle">
                        <i class="fa-solid fa-graduation-cap fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card p-3 bg-white">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <span class="text-muted small fw-bold text-uppercase"><?= (function_exists('is_rtl') && is_rtl()) ? 'متوسط نسبة الخصم' : 'Average Discount Rate' ?></span>
                        <h3 class="fw-bold mb-0 text-dark mt-1"><?= number_format($avgDiscount, 1) ?>%</h3>
                    </div>
                    <div class="p-3 bg-warning bg-opacity-10 text-warning rounded-circle">
                        <i class="fa-solid fa-percent fa-lg"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Groups Table -->
    <div class="card shadow-sm border-0 mb-4 bg-white">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-users-rectangle text-success me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'مجموعات خصم الأسر والعائلات' : 'Family Sibling Discount Groups' ?>
            </h5>
            <span class="badge bg-secondary"><?= count($groups) ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'مجموعة' : 'Groups' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'ولي الأمر / العائلة' : 'Family / Guardian Name' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'معلومات الاتصال' : 'Contact Info' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'نسبة الخصم المقررة' : 'Discount Percentage' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'عدد الأبناء المشمولين' : 'Assigned Children' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'تاريخ التأسيس' : 'Created Date' ?></th>
                        <th class="text-end pe-3"><?= (function_exists('is_rtl') && is_rtl()) ? 'الإجراءات' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($groups)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="fa-solid fa-tags fa-3x mb-3 text-secondary d-block"></i>
                                <?= (function_exists('is_rtl') && is_rtl()) ? 'لا توجد مجموعات خصم مسجلة حالياً. انقر على "إنشاء مجموعة خصم أسرة" للبدء.' : 'No sibling discount groups registered yet. Click "New Sibling Group" to add one.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($groups as $idx => $g): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $idx + 1 ?></td>
                                <td class="fw-bold text-dark">
                                    <i class="fa-solid fa-user-tie text-primary me-2"></i><?= htmlspecialchars($g['parent_name'] ?? ('Family #' . $g['family_id'])) ?>
                                </td>
                                <td class="small text-muted">
                                    <?= htmlspecialchars($g['parent_phone'] ?? $g['parent_email'] ?? '—') ?>
                                </td>
                                <td>
                                    <span class="badge bg-success fs-6 fw-bold"><?= number_format($g['discount_percent'], 2) ?>%</span>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= $g['student_count'] ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'طلاب' : 'students' ?></span>
                                </td>
                                <td class="small text-muted"><?= date('Y-m-d', strtotime($g['created_at'])) ?></td>
                                <td class="text-end pe-3">
                                    <form method="POST" action="discounts.php" class="d-inline" onsubmit="return confirm('<?= (function_exists('is_rtl') && is_rtl()) ? 'هل أنت متأكد من حذف مجموعة الخصم هذه؟ سيتم إلغاء الخصم لجميع الطلاب المرتبطين.' : 'Are you sure you want to delete this discount group?' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="delete_group">
                                        <input type="hidden" name="group_id" value="<?= $g['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">
                                            <i class="fa-solid fa-trash-can me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'حذف' : 'Delete' ?>
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

    <!-- Student Allocations Table -->
    <div class="card shadow-sm border-0 bg-white">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="fw-bold mb-0 text-dark">
                <i class="fa-solid fa-user-graduate text-primary me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'الطلاب المخصص لهم خصم الأخوة' : 'Students with Active Sibling Discounts' ?>
            </h5>
            <span class="badge bg-primary"><?= count($discountedStudents) ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'طالب' : 'Students' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'اسم الطالب' : 'Student Name' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الرقم الأكاديمي' : 'Admission No' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الصف الدراسي' : 'Class' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'ولي الأمر' : 'Guardian' ?></th>
                        <th><?= (function_exists('is_rtl') && is_rtl()) ? 'الخصم المطبق' : 'Applied Sibling Discount' ?></th>
                        <th class="text-end pe-3"><?= (function_exists('is_rtl') && is_rtl()) ? 'الإجراءات' : 'Actions' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($discountedStudents)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <?= (function_exists('is_rtl') && is_rtl()) ? 'لا يوجد طلاب مخصص لهم خصم أخوة حالياً.' : 'No students assigned to sibling discount groups yet.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($discountedStudents as $idx => $st): ?>
                            <tr>
                                <td class="ps-3 text-muted"><?= $idx + 1 ?></td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($st['student_name']) ?></td>
                                <td><span class="badge bg-light text-secondary border"><?= htmlspecialchars($st['admission_no'] ?? 'N/A') ?></span></td>
                                <td><?= htmlspecialchars($st['class_name'] ?? '—') ?></td>
                                <td class="small text-muted"><?= htmlspecialchars($st['parent_name'] ?? 'Family Group') ?></td>
                                <td><span class="badge bg-success fs-6"><?= number_format($st['discount_percent'], 2) ?>%</span></td>
                                <td class="text-end pe-3">
                                    <form method="POST" action="discounts.php" class="d-inline" onsubmit="return confirm('<?= (function_exists('is_rtl') && is_rtl()) ? 'إلغاء خصم هذا الطالب؟' : 'Remove discount from this student?' ?>');">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="action" value="remove_student">
                                        <input type="hidden" name="student_id" value="<?= $st['student_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-1 px-2">
                                            <i class="fa-solid fa-user-minus me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'إلغاء الخصم' : 'Remove' ?>
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

<!-- Modal: Create Sibling Group -->
<div class="modal fade" id="createGroupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="discounts.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="create_group">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-people-roof me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'إنشاء مجموعة خصم أسرة جديدة' : 'New Sibling Discount Group' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'اختر ولي الأمر / الأسرة:' : 'Select Parent / Family:' ?></label>
                        <select name="family_id" class="form-select" required>
                            <option value="">-- <?= (function_exists('is_rtl') && is_rtl()) ? 'اختر ولي الأمر' : 'Choose Guardian' ?> --</option>
                            <?php foreach ($parentsList as $p): ?>
                                <option value="<?= $p['parent_id'] ?>"><?= htmlspecialchars($p['full_name']) ?> (<?= $p['children_count'] ?> <?= (function_exists('is_rtl') && is_rtl()) ? 'أبناء مسجلين' : 'enrolled children' ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'نسبة الخصم المئوية (%):' : 'Discount Percentage (%):' ?></label>
                        <input type="number" step="0.5" min="1" max="100" name="discount_percent" class="form-control" placeholder="15.00" required>
                        <small class="text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'مثال: 10% للابن الثاني، 20% للابن الثالث أو أكثر.' : 'E.g., 10% for 2nd child, 20% for 3rd child.' ?></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= (function_exists('is_rtl') && is_rtl()) ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-success btn-sm px-3"><i class="fa-solid fa-check me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'إنشاء المجموعة' : 'Create Group' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Assign Student to Group -->
<div class="modal fade" id="assignStudentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="discounts.php">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="assign_student">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i><?= (function_exists('is_rtl') && is_rtl()) ? 'تعيين طالب في مجموعة خصم' : 'Assign Student to Discount Group' ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'اختر الطالب:' : 'Select Student:' ?></label>
                        <select name="student_id" class="form-select" required>
                            <option value="">-- <?= (function_exists('is_rtl') && is_rtl()) ? 'اختر الطالب' : 'Choose Student' ?> --</option>
                            <?php foreach ($allStudents as $st): ?>
                                <option value="<?= $st['student_id'] ?>"><?= htmlspecialchars($st['full_name']) ?> (<?= htmlspecialchars($st['class_name'] ?? 'No class') ?> - <?= htmlspecialchars($st['admission_no'] ?? 'No ID') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted"><?= (function_exists('is_rtl') && is_rtl()) ? 'اختر مجموعة الخصم العائلية:' : 'Select Sibling Discount Group:' ?></label>
                        <select name="group_id" class="form-select" required>
                            <option value="">-- <?= (function_exists('is_rtl') && is_rtl()) ? 'اختر المجموعة' : 'Choose Group' ?> --</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['parent_name'] ?? ('Family #' . $g['family_id'])) ?> (<?= number_format($g['discount_percent'], 2) ?>% Discount)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= (function_exists('is_rtl') && is_rtl()) ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa-solid fa-save me-1"></i> <?= (function_exists('is_rtl') && is_rtl()) ? 'تأكيد التعيين' : 'Confirm Assignment' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
