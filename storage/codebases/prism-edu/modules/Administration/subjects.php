<?php
/**
 * Administration Module - Curriculum Subjects & Teacher Allocations Desk
 * Dedicated Standalone Page
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin or School Admin
Auth::requireRole(['admin', 'school_admin']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Add Subject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_subject') {
    $subject_name = trim($_POST['subject_name'] ?? '');
    $subject_code = trim($_POST['subject_code'] ?? '');

    if (!empty($subject_name) && !empty($subject_code)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO subjects (subject_name, subject_code) VALUES (?, ?)");
            $stmt->execute([$subject_name, $subject_code]);
            $_SESSION['flash_msg'] = is_rtl() ? "تمت إضافة المادة '$subject_name' بنجاح!" : "Subject '$subject_name' created successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: subjects.php");
            exit();
        } catch (\PDOException $e) {
            $msg = "Error adding subject: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = is_rtl() ? "يرجى تعبئة اسم المادة ورمزها." : "Please fill in all mandatory fields.";
        $msg_type = "danger";
    }
}

// Handle Teacher Allocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_teacher') {
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $class_id   = intval($_POST['class_id'] ?? 0);
    $subject_id = intval($_POST['subject_id'] ?? 0);

    if ($teacher_id > 0 && $class_id > 0 && $subject_id > 0) {
        try {
            // Check if allocation already exists
            $chk = $pdo->prepare("SELECT allocation_id FROM teacher_allocations WHERE teacher_user_id = ? AND class_id = ? AND subject_id = ?");
            $chk->execute([$teacher_id, $class_id, $subject_id]);
            if ($chk->fetch()) {
                $_SESSION['flash_msg'] = is_rtl() ? "هذا المعلم مسند لهذه المادة وهذا الفصل مسبقاً." : "This teacher is already allocated to this class and subject.";
                $_SESSION['flash_msg_type'] = "warning";
            } else {
                $stmt = $pdo->prepare("INSERT INTO teacher_allocations (teacher_user_id, class_id, subject_id) VALUES (?, ?, ?)");
                $stmt->execute([$teacher_id, $class_id, $subject_id]);
                $_SESSION['flash_msg'] = is_rtl() ? "تم توزيع المعلم على الفصل والمادة بنجاح!" : "Teacher allocated successfully!";
                $_SESSION['flash_msg_type'] = "success";
            }
            header("Location: subjects.php");
            exit();
        } catch (\PDOException $e) {
            $msg = "Error assigning teacher: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = is_rtl() ? "يرجى تحديد المعلم والفصل والمادة." : "Please select a teacher, class, and subject.";
        $msg_type = "danger";
    }
}

// Handle Delete Allocation
if (isset($_GET['delete_allocation'])) {
    $alloc_id = intval($_GET['delete_allocation']);
    if ($alloc_id > 0) {
        $pdo->prepare("DELETE FROM teacher_allocations WHERE allocation_id = ?")->execute([$alloc_id]);
        $_SESSION['flash_msg'] = is_rtl() ? "تم إزالة التوزيع الأكاديمي." : "Allocation assignment removed.";
        $_SESSION['flash_msg_type'] = "warning";
        header("Location: subjects.php");
        exit();
    }
}

// Handle Delete Subject
if (isset($_GET['delete_subject'])) {
    $sub_id = intval($_GET['delete_subject']);
    if ($sub_id > 0) {
        $pdo->prepare("DELETE FROM teacher_allocations WHERE subject_id = ?")->execute([$sub_id]);
        $pdo->prepare("DELETE FROM subjects WHERE subject_id = ?")->execute([$sub_id]);
        $_SESSION['flash_msg'] = is_rtl() ? "تم حذف المادة الدراسية من الدليل." : "Subject removed from curriculum catalog.";
        $_SESSION['flash_msg_type'] = "warning";
        header("Location: subjects.php");
        exit();
    }
}

// Data queries
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$teachers = $pdo->query("SELECT user_id, full_name, email FROM users WHERE role = 'teacher' AND status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$allocations = $pdo->query("
    SELECT ta.*, u.full_name as teacher_name, c.class_name, s.subject_name, s.subject_code 
    FROM teacher_allocations ta
    JOIN users u ON ta.teacher_user_id = u.user_id
    JOIN classes c ON ta.class_id = c.class_id
    JOIN subjects s ON ta.subject_id = s.subject_id
    ORDER BY c.class_name ASC, s.subject_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_subjects = count($subjects);
$total_allocs   = count($allocations);
$total_teachers = count($teachers);
$covered_classes = count(array_unique(array_column($allocations, 'class_id')));
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('manage_subjects') ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => __('manage_subjects'),
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container-fluid py-4 px-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
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
                        <i class="fa-solid fa-book-bookmark me-1 text-emerald-300"></i> <?= number_format($total_subjects) ?> <?= is_rtl() ? 'مادة دراسية معتمدة' : 'Curriculum Subjects' ?>
                    </span>
                </div>
                <h2 class="font-heading fw-bold text-white mb-1">
                    <i class="fa-solid fa-book-open me-2"></i><?= __('manage_subjects') ?>
                </h2>
                <p class="text-white-50 mb-0 small"><?= __('manage_subjects_sub') ?></p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="classes.php" class="btn btn-light text-primary fw-bold shadow-xs rounded-lg px-3 py-2">
                    <i class="fa-solid fa-cubes-stacked me-1"></i> <?= __('manage_classes') ?>
                </a>
                <a href="index.php" class="btn btn-outline-light rounded-lg px-3 py-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> <?= is_rtl() ? 'مركز الإدارة' : 'Control Center' ?>
                </a>
            </div>
        </div>
    </div>

    <!-- 4 KPI Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي المواد الدراسية' : 'Catalog Subjects' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(168,85,247,0.12); color: #A855F7;">
                        <i class="fa-solid fa-book-open fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($total_subjects) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-purple fw-semibold"><i class="fa-solid fa-check-double me-1"></i> Certified</span>
                    <span class="text-muted-foreground">· Curriculum titles</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'التوزيعات التدريسية النشطة' : 'Active Allocations' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(16,185,129,0.12); color: #10B981;">
                        <i class="fa-solid fa-layer-group fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($total_allocs) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-success fw-semibold"><i class="fa-solid fa-circle-check me-1"></i> Assigned</span>
                    <span class="text-muted-foreground">· Class schedules</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'أعضاء هيئة التدريس' : 'Active Faculty' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(2,132,199,0.12); color: #0284C7;">
                        <i class="fa-solid fa-chalkboard-user fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($total_teachers) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold"><i class="fa-solid fa-user-check me-1"></i> Teachers</span>
                    <span class="text-muted-foreground">· Ready to teach</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الفصول المغطاة' : 'Covered Classes' ?></span>
                    <div class="bg-accent text-primary p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem;">
                        <i class="fa-solid fa-cubes-stacked fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($covered_classes) ?> / <?= count($classes) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-primary fw-semibold"><i class="fa-solid fa-chart-pie me-1"></i> <?= count($classes) > 0 ? round(($covered_classes / count($classes)) * 100) : 0 ?>%</span>
                    <span class="text-muted-foreground">· Classroom coverage</span>
                </div>
            </div>
        </div>
    </div>

    <!-- 2-Column Responsive Workspace -->
    <div class="row g-4 mb-4">
        <!-- Left Column: Forms -->
        <div class="col-lg-5">
            <!-- Add Subject Form -->
            <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
                <h5 class="fw-bold font-heading mb-2 text-foreground"><i class="fa-solid fa-plus text-primary me-2"></i><?= is_rtl() ? 'إضافة مادة دراسية' : 'Add Curriculum Subject' ?></h5>
                <p class="text-muted-foreground small mb-3"><?= is_rtl() ? 'تعريف مادة جديدة وكود المنهج المعتمد.' : 'Register new subject titles and course code identifiers.' ?></p>
                <form method="POST">
                    <input type="hidden" name="action" value="add_subject">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('subject_name') ?> *</label>
                        <input type="text" name="subject_name" class="form-control" placeholder="e.g. Mathematics, Science, Arabic" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('subject_code') ?> *</label>
                        <input type="text" name="subject_code" class="form-control font-monospace" placeholder="e.g. MATH-101, SCI-201" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-xs">
                        <i class="fa-solid fa-floppy-disk me-1"></i> <?= __('save') ?>
                    </button>
                </form>
            </div>

            <!-- Allocate Teacher Form -->
            <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
                <h5 class="fw-bold font-heading mb-2 text-foreground"><i class="fa-solid fa-user-check text-success me-2"></i><?= __('save_teacher_allocation') ?></h5>
                <p class="text-muted-foreground small mb-3"><?= is_rtl() ? 'إسناد معلم لتدريس مادة محددة في فصل محدد.' : 'Assign a teacher to a specific class and subject combination.' ?></p>
                <form method="POST">
                    <input type="hidden" name="action" value="assign_teacher">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('select_teacher') ?> *</label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">-- <?= __('select_teacher') ?> --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['user_id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('select_class') ?> *</label>
                        <select name="class_id" class="form-select" required>
                            <option value="">-- <?= __('select_class') ?> --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['class_id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('select_subject') ?> *</label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">-- <?= __('select_subject') ?> --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_name']) ?> (<?= $s['subject_code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-xs">
                        <i class="fa-solid fa-link me-1"></i> <?= __('save_teacher_allocation') ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Right Column: Tables -->
        <div class="col-lg-7">
            <!-- Subjects Table -->
            <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold font-heading mb-0 text-foreground"><i class="fa-solid fa-book text-primary me-2"></i><?= __('curriculum_subjects') ?> (<?= count($subjects) ?>)</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th><?= __('subject_name') ?></th>
                                <th><?= __('subject_code') ?></th>
                                <th class="text-center"><?= __('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($subjects)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted-foreground">
                                        <i class="fa-solid fa-book-open fs-3 d-block mb-2 text-muted"></i>
                                        <?= is_rtl() ? 'لا توجد مواد دراسية مسجلة.' : 'No subjects in curriculum catalog.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($subjects as $s): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary-subtle text-muted-foreground border">#<?= $s['subject_id'] ?></span></td>
                                        <td class="fw-bold text-foreground"><?= htmlspecialchars($s['subject_name']) ?></td>
                                        <td><code class="px-2 py-1 bg-secondary rounded border border-border"><?= htmlspecialchars($s['subject_code']) ?></code></td>
                                        <td class="text-center">
                                            <a href="subjects.php?delete_subject=<?= $s['subject_id'] ?>" class="btn btn-sm btn-outline-danger" title="<?= __('confirm_delete') ?>" onclick="return confirm('<?= __('confirm_delete') ?>');">
                                                <i class="fa-solid fa-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Active Teacher Allocations Table -->
            <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold font-heading mb-0 text-foreground"><i class="fa-solid fa-layer-group text-success me-2"></i><?= __('active_teacher_allocations') ?> (<?= count($allocations) ?>)</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?= is_rtl() ? 'المعلم' : 'Teacher' ?></th>
                                <th><?= is_rtl() ? 'الفصل' : 'Class' ?></th>
                                <th><?= is_rtl() ? 'المادة' : 'Subject' ?></th>
                                <th class="text-center"><?= __('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($allocations)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted-foreground">
                                        <i class="fa-solid fa-user-slash fs-3 d-block mb-2 text-muted"></i>
                                        <?= is_rtl() ? 'لا توجد توزيعات تدريسية مسجلة.' : 'No active teacher allocations.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($allocations as $a): ?>
                                    <tr>
                                        <td class="fw-bold text-foreground">
                                            <i class="fa-solid fa-chalkboard-user text-muted me-1"></i>
                                            <?= htmlspecialchars($a['teacher_name']) ?>
                                        </td>
                                        <td><span class="badge bg-info-subtle text-info border border-info px-2.5 py-1 rounded-full"><?= htmlspecialchars($a['class_name']) ?></span></td>
                                        <td><?= htmlspecialchars($a['subject_name']) ?> <small class="text-muted">(<?= $a['subject_code'] ?>)</small></td>
                                        <td class="text-center">
                                            <a href="subjects.php?delete_allocation=<?= $a['allocation_id'] ?>" class="btn btn-sm btn-outline-danger" title="Remove assignment" onclick="return confirm('<?= is_rtl() ? 'إلغاء هذا التوزيع؟' : 'Remove this assignment?' ?>');">
                                                <i class="fa-solid fa-trash"></i>
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

    <?= render_platform_footer() ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
