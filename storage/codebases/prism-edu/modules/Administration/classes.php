<?php
/**
 * Administration Module - Classes & Academic Sections Desk
 * Dedicated Standalone Page
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin or School Admin
Auth::requireRole(['admin', 'school_admin']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Add Class
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_class') {
    $class_name     = trim($_POST['class_name'] ?? '');
    $section_clause = trim($_POST['section_clause'] ?? '');

    if (!empty($class_name)) {
        try {
            $full_title = !empty($section_clause) ? "$class_name ($section_clause)" : $class_name;
            $stmt = $pdo->prepare("INSERT INTO classes (class_name) VALUES (?)");
            $stmt->execute([$full_title]);
            $_SESSION['flash_msg'] = is_rtl() ? "تم إنشاء الفصل الدراسي بنجاح!" : "Class '$full_title' created successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: classes.php");
            exit();
        } catch (\PDOException $e) {
            $msg = "Error adding class: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = is_rtl() ? "يرجى إدخال اسم الفصل الدراسي." : "Please enter the class name.";
        $msg_type = "danger";
    }
}

// Handle Delete Class (Only if no active students).  Deletion is deliberately
// POST-only so a shared link, prefetch, or accidental refresh cannot remove data.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_class') {
    $cid = intval($_POST['class_id'] ?? 0);
    if ($cid > 0) {
        $studentCheck = $pdo->prepare("SELECT COUNT(*) FROM students WHERE class_id = ? AND is_deleted = 0");
        $studentCheck->execute([$cid]);
        $hasStudents = (int) $studentCheck->fetchColumn();
        if ($hasStudents > 0) {
            $_SESSION['flash_msg'] = is_rtl() ? "لا يمكن حذف الفصل لوجود طلاب مسجلين به." : "Cannot delete class with active student enrollments.";
            $_SESSION['flash_msg_type'] = "danger";
        } else {
            $pdo->prepare("DELETE FROM teacher_allocations WHERE class_id = ?")->execute([$cid]);
            $pdo->prepare("DELETE FROM classes WHERE class_id = ?")->execute([$cid]);
            $_SESSION['flash_msg'] = is_rtl() ? "تم حذف الفصل الدراسي بنجاح." : "Class removed successfully.";
            $_SESSION['flash_msg_type'] = "warning";
        }
        header("Location: classes.php");
        exit();
    }
}

// Fetch classes with student counts
$classes = $pdo->query("
    SELECT c.*, COUNT(s.student_id) AS student_count 
    FROM classes c 
    LEFT JOIN students s ON c.class_id = s.class_id AND s.is_deleted = 0 
    GROUP BY c.class_id 
    ORDER BY c.class_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Metrics
$total_classes = count($classes);
$total_enrolled = (int)($pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0")->fetchColumn() ?: 0);
$total_allocs   = (int)($pdo->query("SELECT COUNT(*) FROM teacher_allocations")->fetchColumn() ?: 0);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('manage_classes') ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => __('manage_classes'),
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
                        <i class="fa-solid fa-chalkboard-user me-1 text-emerald-300"></i> <?= number_format($total_classes) ?> <?= is_rtl() ? 'فصل دراسي نشط' : 'Active Classes' ?>
                    </span>
                </div>
                <h2 class="font-heading fw-bold text-white mb-1">
                    <i class="fa-solid fa-cubes-stacked me-2"></i><?= __('manage_classes') ?>
                </h2>
                <p class="text-white-50 mb-0 small"><?= __('manage_classes_sub') ?></p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="subjects.php" class="btn btn-light text-primary fw-bold shadow-xs rounded-lg px-3 py-2">
                    <i class="fa-solid fa-book-open me-1"></i> <?= __('manage_subjects') ?>
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
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي الفصول' : 'Total Classes' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(2,132,199,0.12); color: #0284C7;">
                        <i class="fa-solid fa-cubes-stacked fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($total_classes) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold"><i class="fa-solid fa-building me-1"></i> Active</span>
                    <span class="text-muted-foreground">· Grade tiers</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الطلاب المسجلين بالفصول' : 'Enrolled Students' ?></span>
                    <div class="bg-accent text-primary p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem;">
                        <i class="fa-solid fa-user-graduate fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($total_enrolled) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-primary fw-semibold"><i class="fa-solid fa-users me-1"></i> Students</span>
                    <span class="text-muted-foreground">· Active admissions</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'التوزيعات التدريسية' : 'Faculty Allocations' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(16,185,129,0.12); color: #10B981;">
                        <i class="fa-solid fa-chalkboard-user fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= number_format($total_allocs) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <a href="subjects.php" class="text-success fw-semibold text-decoration-none"><i class="fa-solid fa-arrow-right me-1"></i> <?= is_rtl() ? 'عرض التوزيعات' : 'View Allocations' ?></a>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'التقويم الأكاديمي' : 'Academic Desk' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(168,85,247,0.12); color: #A855F7;">
                        <i class="fa-solid fa-calendar-days fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= is_rtl() ? 'مباشر' : 'Live' ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <a href="academic_calendar.php" class="text-purple fw-semibold text-decoration-none"><i class="fa-solid fa-calendar-check me-1"></i> Timetable & Terms</a>
                </div>
            </div>
        </div>
    </div>

    <!-- 2-Column Responsive Workspace -->
    <div class="row g-4 mb-4">
        <!-- Add Class Form -->
        <div class="col-lg-4">
            <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
                <h5 class="fw-bold font-heading mb-2 text-foreground"><i class="fa-solid fa-plus text-primary me-2"></i><?= is_rtl() ? 'إضافة فصل دراسي جديد' : 'Add New Class' ?></h5>
                <p class="text-muted-foreground small mb-3"><?= is_rtl() ? 'تسجيل صفوف دراسية وشعب جديدة للمنظومة.' : 'Register new classes, grades, or class sections.' ?></p>

                <form method="POST">
                    <input type="hidden" name="action" value="add_class">
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('class_name') ?> *</label>
                        <input type="text" name="class_name" class="form-control" placeholder="e.g. Grade 1 or Primary 4" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-foreground"><?= __('section_clause') ?></label>
                        <input type="text" name="section_clause" class="form-control" placeholder="e.g. Alpha / Section A / Boys">
                        <div class="form-text text-muted-foreground small"><?= is_rtl() ? 'اختياري: لتحديد الشعبة أو الفئة.' : 'Optional: Specify section identifier or room.' ?></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-xs">
                        <i class="fa-solid fa-floppy-disk me-1"></i> <?= __('save') ?>
                    </button>
                </form>
            </div>
        </div>

        <!-- Active Classes Table -->
        <div class="col-lg-8">
            <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold font-heading mb-0 text-foreground"><i class="fa-solid fa-list text-info me-2"></i><?= is_rtl() ? 'قائمة الفصول والشعب المسجلة' : 'Registered Classes & Sections' ?> (<?= count($classes) ?>)</h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'إدارة الصفوف وعدد الطلاب المقيدين بكل شعبة.' : 'All academic classes and current student enrollment numbers.' ?></small>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th><?= __('class_name') ?></th>
                                <th class="text-center"><?= is_rtl() ? 'عدد الطلاب' : 'Enrolled Students' ?></th>
                                <th class="text-center"><?= __('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($classes)): ?>
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted-foreground">
                                        <i class="fa-solid fa-chalkboard fs-3 d-block mb-2 text-muted"></i>
                                        <?= is_rtl() ? 'لا توجد فصول دراسية مسجلة حتى الآن.' : 'No classes registered yet.' ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($classes as $c): ?>
                                    <tr>
                                        <td><span class="badge bg-secondary-subtle text-muted-foreground border">#<?= $c['class_id'] ?></span></td>
                                        <td class="fw-bold text-foreground">
                                            <i class="fa-solid fa-chalkboard me-2 text-primary"></i>
                                            <?= htmlspecialchars($c['class_name']) ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $c['student_count'] > 0 ? 'bg-primary-subtle text-primary border border-primary' : 'bg-secondary-subtle text-muted-foreground border' ?> px-2.5 py-1 rounded-full font-medium">
                                                <i class="fa-solid fa-user-graduate me-1"></i> <?= number_format($c['student_count']) ?> <?= is_rtl() ? 'طالب' : 'students' ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'هل أنت متأكد من حذف هذا الفصل؟' : 'Delete this class record?' ?>');">
                                                <input type="hidden" name="action" value="delete_class">
                                                <input type="hidden" name="class_id" value="<?= (int) $c['class_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= __('confirm_delete') ?>">
                                                <i class="fa-solid fa-trash"></i>
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
    </div>

    <?= render_platform_footer() ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
