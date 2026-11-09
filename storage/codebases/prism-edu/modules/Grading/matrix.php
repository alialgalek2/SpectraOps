<?php
/**
 * Grading Module - Mark Entry Matrix & Moderation Workflow
 * Features: 2D Multi-Component Roster, Real-Time Range Validation, Special Condition Codes (AB, EX, ML, DQ),
 * and Multi-Tier Review & Locking (Draft -> Department Review -> Locked).
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher']);

$role = Auth::role();
$user_id = Auth::id();
$is_admin = in_array($role, ['admin', 'school_admin']);

$msg = '';
$msg_type = 'success';

// Filters & Context
$class_id   = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$subject_id = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
$series_id  = isset($_GET['series_id']) ? (int)$_GET['series_id'] : 0;
$term       = $_GET['term'] ?? 'Term 1';
$year       = $_GET['year'] ?? '2025/2026';

// Fetch Dropdown Options
$classes = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name ASC")->fetchAll();
$subjects = $pdo->query("SELECT subject_id, subject_name, subject_code FROM subjects ORDER BY subject_name ASC")->fetchAll();
$series_list = $pdo->query("SELECT series_id, title, term, academic_year, status FROM exam_series ORDER BY start_date DESC")->fetchAll();

if ($class_id <= 0 && !empty($classes)) $class_id = (int)$classes[0]['class_id'];
if ($subject_id <= 0 && !empty($subjects)) $subject_id = (int)$subjects[0]['subject_id'];
if ($series_id <= 0 && !empty($series_list)) $series_id = (int)$series_list[0]['series_id'];

// Fetch Active Weightage Policy & Grading Scale Tiers
$policy = $pdo->query("SELECT * FROM grade_weightage_policies ORDER BY policy_id ASC LIMIT 1")->fetch();
$ca_weight = $policy ? (float)$policy['ca_weight'] : 20.0;
$mid_weight = $policy ? (float)$policy['midterm_weight'] : 30.0;
$fin_weight = $policy ? (float)$policy['final_weight'] : 50.0;

$scale = $pdo->query("SELECT * FROM grading_scales WHERE is_default = 1 LIMIT 1")->fetch();
$scale_id = $scale ? (int)$scale['scale_id'] : 1;
$tiers = $pdo->prepare("SELECT * FROM grading_scale_tiers WHERE scale_id = ? ORDER BY min_percentage DESC");
$tiers->execute([$scale_id]);
$scale_tiers = $tiers->fetchAll();

function compute_letter_and_gpa($percentage, $scale_tiers) {
    foreach ($scale_tiers as $t) {
        if ($percentage >= (float)$t['min_percentage'] && $percentage <= (float)$t['max_percentage']) {
            return [$t['grade_letter'], (float)$t['gpa_point']];
        }
    }
    return ['F', 0.00];
}

// Check Current Matrix Moderation Status
$mod_check = $pdo->prepare("
    SELECT moderation_status FROM grades 
    WHERE class_id = ? AND subject_id = ? AND term = ? AND academic_year = ?
    LIMIT 1
");
$mod_check->execute([$class_id, $subject_id, $term, $year]);
$current_moderation_status = $mod_check->fetchColumn() ?: 'draft';
$is_locked = ($current_moderation_status === 'locked');

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_marks') {
            if ($is_locked && !$is_admin) {
                throw new RuntimeException(is_rtl() ? 'هذه الدرجات مقفلة رسمياً ومحمية من التعديل.' : 'This mark sheet is locked and frozen against further modifications.');
            }

            $pdo->beginTransaction();

            $ca_scores     = $_POST['ca_score'] ?? [];
            $paper1_scores = $_POST['paper1_score'] ?? [];
            $paper2_scores = $_POST['paper2_score'] ?? [];
            $conditions    = $_POST['special_condition'] ?? [];
            $comments      = $_POST['teacher_comment'] ?? [];

            foreach ($ca_scores as $student_id => $ca_val) {
                $student_id = (int)$student_id;
                $ca   = ($ca_val === '') ? null : floatval($ca_val);
                $p1   = isset($paper1_scores[$student_id]) && $paper1_scores[$student_id] !== '' ? floatval($paper1_scores[$student_id]) : null;
                $p2   = isset($paper2_scores[$student_id]) && $paper2_scores[$student_id] !== '' ? floatval($paper2_scores[$student_id]) : null;
                $cond = $conditions[$student_id] ?? 'NONE';
                $comm = trim($comments[$student_id] ?? '');

                // Validation bounds
                if ($ca !== null && ($ca < 0 || $ca > $ca_weight)) {
                    throw new InvalidArgumentException(is_rtl() ? "درجة أعمال السنة تجاوزت الحد الأقصى ($ca_weight)." : "CA score out of range (Max: $ca_weight).");
                }
                if ($p1 !== null && ($p1 < 0 || $p1 > $mid_weight)) {
                    throw new InvalidArgumentException(is_rtl() ? "درجة ورقة 1 تجاوزت الحد الأقصى ($mid_weight)." : "Paper 1 score out of range (Max: $mid_weight).");
                }
                if ($p2 !== null && ($p2 < 0 || $p2 > $fin_weight)) {
                    throw new InvalidArgumentException(is_rtl() ? "درجة ورقة 2 تجاوزت الحد الأقصى ($fin_weight)." : "Paper 2 score out of range (Max: $fin_weight).");
                }

                // Calculate Totals & Grades
                $total_score = null;
                $exam_score = null;
                $grade_letter = 'Pending';
                $gpa_pt = null;

                if ($cond !== 'NONE') {
                    // Special conditions: AB, EX, ML, DQ
                    $grade_letter = $cond;
                    $total_score = 0.00;
                    $gpa_pt = 0.00;
                } else {
                    $exam_score = ($p1 !== null ? $p1 : 0) + ($p2 !== null ? $p2 : 0);
                    if ($ca !== null || $exam_score !== null) {
                        $total_score = ($ca !== null ? $ca : 0) + ($exam_score !== null ? $exam_score : 0);
                        list($grade_letter, $gpa_pt) = compute_letter_and_gpa($total_score, $scale_tiers);
                    }
                }

                // Verification Hash (SHA-256) for certificate authenticity
                $hash_input = "$student_id|$subject_id|$term|$year|$total_score|$grade_letter|" . ($global_branding['school_name'] ?? 'EduMaster');
                $verif_hash = hash('sha256', $hash_input);

                // Upsert Grade Record
                $chk = $pdo->prepare("SELECT grade_id FROM grades WHERE student_id = ? AND subject_id = ? AND term = ? AND academic_year = ?");
                $chk->execute([$student_id, $subject_id, $term, $year]);
                $ex_gid = $chk->fetchColumn();

                if ($ex_gid) {
                    $upd = $pdo->prepare("
                        UPDATE grades 
                        SET ca_score = ?, paper1_score = ?, paper2_score = ?, exam_score = ?, total_score = ?,
                            grade_letter = ?, gpa_points = ?, special_condition = ?, teacher_comment = ?, verification_hash = ?, series_id = ?
                        WHERE grade_id = ?
                    ");
                    $upd->execute([$ca, $p1, $p2, $exam_score, $total_score, $grade_letter, $gpa_pt, $cond, $comm, $verif_hash, $series_id, $ex_gid]);
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO grades (student_id, subject_id, class_id, ca_score, paper1_score, paper2_score, exam_score, total_score, grade_letter, gpa_points, special_condition, teacher_comment, verification_hash, term, academic_year, series_id, moderation_status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$student_id, $subject_id, $class_id, $ca, $p1, $p2, $exam_score, $total_score, $grade_letter, $gpa_pt, $cond, $comm, $verif_hash, $term, $year, $series_id, $current_moderation_status]);
                }
            }

            $pdo->commit();
            $msg = is_rtl() ? 'تم حفظ وتدقيق درجات الطلاب بنجاح!' : 'Mark matrix validated and saved successfully!';
        } elseif ($action === 'advance_moderation') {
            $new_stage = $_POST['new_stage'] ?? 'draft'; // draft, department_review, locked
            $note = trim($_POST['moderation_note'] ?? '');

            if ($new_stage === 'locked' && !$is_admin) {
                throw new RuntimeException(is_rtl() ? 'عفواً، قفل واعتماد الدرجات النهائي مقتصر على إدارة الامتحانات فقط.' : 'Final grade approval and locking is restricted to administrators and exam officers.');
            }

            $upd = $pdo->prepare("
                UPDATE grades 
                SET moderation_status = ? 
                WHERE class_id = ? AND subject_id = ? AND term = ? AND academic_year = ?
            ");
            $upd->execute([$new_stage, $class_id, $subject_id, $term, $year]);

            $log = $pdo->prepare("
                INSERT INTO exam_moderation_logs (series_id, class_id, subject_id, stage, moderated_by, action_note)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $log->execute([$series_id, $class_id, $subject_id, $new_stage, $user_id, $note]);

            $current_moderation_status = $new_stage;
            $is_locked = ($current_moderation_status === 'locked');
            $msg = is_rtl() ? "تم تحديث مرحلة المراجعة إلى: " . ucfirst($new_stage) : "Moderation stage advanced to: " . ucfirst($new_stage);
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = $e->getMessage();
        $msg_type = 'danger';
    }
}

// Fetch Class Students and Current Marks
$students_stmt = $pdo->prepare("
    SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
           g.grade_id, g.ca_score, g.paper1_score, g.paper2_score, g.exam_score, g.total_score, g.grade_letter, g.gpa_points,
           g.special_condition, g.teacher_comment, g.moderation_status
    FROM students s
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN grades g ON s.student_id = g.student_id AND g.subject_id = ? AND g.term = ? AND g.academic_year = ?
    WHERE s.class_id = ? AND s.is_deleted = 0
    ORDER BY s.admission_no ASC
");
$students_stmt->execute([$subject_id, $term, $year, $class_id]);
$students_marks = $students_stmt->fetchAll();

// Matrix Statistics
$total_enrolled = count($students_marks);
$graded_count = 0;
$pass_count = 0;
$sum_marks = 0;

foreach ($students_marks as $sm) {
    if ($sm['total_score'] !== null) {
        $graded_count++;
        $sum_marks += (float)$sm['total_score'];
        if ((float)$sm['total_score'] >= 50.0) $pass_count++;
    }
}

$class_avg = $graded_count > 0 ? round($sum_marks / $graded_count, 1) : 0;
$pass_rate = $graded_count > 0 ? round(($pass_count / $graded_count) * 100) : 0;
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'شبكة رصد الدرجات والمراجعة والاعتماد - نظام الدرجات' : 'Mark Entry Matrix & Moderation Workflow' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .mark-input {
            width: 80px;
            text-align: center;
            font-weight: 700;
        }
        .mark-input:focus {
            box-shadow: 0 0 0 3px rgba(67, 56, 202, 0.25);
        }
        .is-out-of-range {
            background-color: #fee2e2 !important;
            border-color: #ef4444 !important;
            color: #b91c1c !important;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'شبكة رصد الدرجات' : 'Mark Entry Matrix',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="policies.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sliders me-1 text-primary"></i> ' . (is_rtl() ? 'السياسات' : 'Policies') . '</a>',
        '<a href="scheduling.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-calendar-check me-1 text-info"></i> ' . (is_rtl() ? 'الجدولة' : 'Scheduling') . '</a>',
        '<a href="analytics.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-chart-line me-1 text-purple"></i> ' . (is_rtl() ? 'الإحصائيات' : 'Analytics') . '</a>',
        '<a href="report_cards.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-id-card-clip me-1 text-warning"></i> ' . (is_rtl() ? 'الشهادات' : 'Report Cards') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Flash Alert -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show border-0 shadow-sm rounded-xl mb-4" role="alert">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Moderation Stage Banner -->
    <div class="p-3 mb-4 bg-card border border-border rounded-xl shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div class="d-flex align-items-center gap-3">
            <div class="action-icon-box <?= $is_locked ? 'icon-box-danger' : ($current_moderation_status === 'department_review' ? 'icon-box-warning' : 'icon-box-primary') ?>">
                <i class="fa-solid <?= $is_locked ? 'fa-lock' : ($current_moderation_status === 'department_review' ? 'fa-user-check' : 'fa-pen-to-square') ?>"></i>
            </div>
            <div>
                <h5 class="fw-bold font-heading mb-0 text-foreground">
                    <?= is_rtl() ? 'مرحلة اعتماد الشيت:' : 'Workflow Moderation Stage:' ?>
                    <span class="badge bg-<?= $is_locked ? 'danger' : ($current_moderation_status === 'department_review' ? 'warning text-dark' : 'primary') ?> ms-2 rounded-pill px-3 py-1">
                        <?= strtoupper(str_replace('_', ' ', $current_moderation_status)) ?>
                    </span>
                </h5>
                <div class="small text-muted-foreground">
                    <?php if ($is_locked): ?>
                        <?= is_rtl() ? 'تم إغلاق الدرجات واعتمادها نهائياً. التعديل مقصور على الإدارة العليا.' : 'This matrix has been permanently locked & approved. Editing is restricted.' ?>
                    <?php elseif ($current_moderation_status === 'department_review'): ?>
                        <?= is_rtl() ? 'الدرجات قيد مراجعة رئيس القسم الأكاديمي (HOD) للتدقيق الإحصائي والمنحنيات.' : 'Under Department Head (HOD) review for curve analysis and score distribution checks.' ?>
                    <?php else: ?>
                        <?= is_rtl() ? 'المسودة الأولية: يستطيع معلم المادة إدخال وتعديل درجات الطلاب بحرية.' : 'Draft Stage: Subject teacher enters and modifies preliminary assessment marks.' ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Multi-Tier Review Controls -->
        <div class="d-flex align-items-center gap-2">
            <?php if (!$is_locked): ?>
                <?php if ($current_moderation_status === 'draft'): ?>
                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'إرسال الشيت لمراجعة رئيس القسم؟' : 'Submit marks for Department Review?' ?>');">
                        <input type="hidden" name="action" value="advance_moderation">
                        <input type="hidden" name="new_stage" value="department_review">
                        <button type="submit" class="btn btn-warning btn-sm rounded-xl px-3 font-semibold text-dark">
                            <i class="fa-solid fa-paper-plane me-1"></i> <?= is_rtl() ? 'إرسال لمراجعة القسم (HOD)' : 'Submit to Dept Review' ?>
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($is_admin || $current_moderation_status === 'department_review'): ?>
                    <button type="button" class="btn btn-danger btn-sm rounded-xl px-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#lockMatrixModal">
                        <i class="fa-solid fa-lock me-1"></i> <?= is_rtl() ? 'الاعتماد النهائي والقفل' : 'Approve & Lock Matrix' ?>
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($is_admin): ?>
                    <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'إعادة فتح الدرجات للتعديل؟' : 'Unlock matrix for revisions?' ?>');">
                        <input type="hidden" name="action" value="advance_moderation">
                        <input type="hidden" name="new_stage" value="draft">
                        <button type="submit" class="btn btn-outline-warning btn-sm rounded-xl px-3">
                            <i class="fa-solid fa-lock-open me-1"></i> <?= is_rtl() ? 'إعادة الفتح (صلاحية إدارية)' : 'Admin Unlock' ?>
                        </button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter & Scope Selector Toolbar -->
    <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-6 col-md-3">
                <label class="form-label small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'الفصل الدراسي:' : 'Class Section:' ?></label>
                <select name="class_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?= $cl['class_id'] ?>" <?= $class_id == $cl['class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'المادة الدراسية:' : 'Subject:' ?></label>
                <select name="subject_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                    <?php foreach ($subjects as $sb): ?>
                        <option value="<?= $sb['subject_id'] ?>" <?= $subject_id == $sb['subject_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sb['subject_name']) ?> (<?= htmlspecialchars($sb['subject_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'سلسلة الامتحان:' : 'Exam Series:' ?></label>
                <select name="series_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                    <?php foreach ($series_list as $sl): ?>
                        <option value="<?= $sl['series_id'] ?>" <?= $series_id == $sl['series_id'] ? 'selected' : '' ?>><?= htmlspecialchars($sl['title']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-lg flex-fill">
                    <i class="fa-solid fa-arrows-rotate me-1"></i><?= is_rtl() ? 'تحديث الشيت' : 'Refresh Matrix' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Live Roster Performance Metrics -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $total_enrolled ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الطلاب المقيدون' : 'Enrolled Students' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-chart-column"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $class_avg ?>%</div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'متوسط درجات الفصل' : 'Class Mean Average' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $pass_rate ?>%</div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'نسبة النجاح (>= 50%)' : 'Passing Rate (>= 50%)' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-pen-ruler"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $graded_count ?> / <?= $total_enrolled ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الدرجات المرصودة' : 'Completed Marks' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2D Mark Entry Table Form -->
    <form method="POST" action="" id="matrixForm">
        <input type="hidden" name="action" value="save_marks">

        <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
            <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="fw-bold font-heading mb-0 text-foreground">
                    <i class="fa-solid fa-table-cells text-success me-2"></i><?= is_rtl() ? 'جدول رصد الدرجات التفصيلي' : 'Component-Level Mark Roster Grid' ?>
                </h5>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill font-monospace small">
                        CA: <?= $ca_weight ?>% | Paper 1: <?= $mid_weight ?>% | Paper 2: <?= $fin_weight ?>%
                    </span>
                    <?php if (!$is_locked || $is_admin): ?>
                        <button type="submit" class="btn btn-success btn-sm rounded-xl px-4 shadow-sm">
                            <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ الشيت' : 'Save All Marks' ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th><?= is_rtl() ? 'رقم القيد' : 'Roll / Adm #' ?></th>
                            <th><?= is_rtl() ? 'اسم الطالب' : 'Student Name' ?></th>
                            <th class="text-center" style="width: 110px;">
                                <?= is_rtl() ? 'أعمال السنة' : 'CA / Coursework' ?><br>
                                <span class="small text-muted-foreground fw-normal">(Max: <?= $ca_weight ?>)</span>
                            </th>
                            <th class="text-center" style="width: 110px;">
                                <?= is_rtl() ? 'ورقة 1 (نصفي)' : 'Paper 1 (Mid)' ?><br>
                                <span class="small text-muted-foreground fw-normal">(Max: <?= $mid_weight ?>)</span>
                            </th>
                            <th class="text-center" style="width: 110px;">
                                <?= is_rtl() ? 'ورقة 2 (نهائي)' : 'Paper 2 (Final)' ?><br>
                                <span class="small text-muted-foreground fw-normal">(Max: <?= $fin_weight ?>)</span>
                            </th>
                            <th class="text-center" style="width: 100px;"><?= is_rtl() ? 'المجموع (100)' : 'Total (100)' ?></th>
                            <th class="text-center" style="width: 90px;"><?= is_rtl() ? 'التقدير' : 'Grade' ?></th>
                            <th class="text-center" style="width: 90px;"><?= is_rtl() ? 'الـ GPA' : 'GPA' ?></th>
                            <th class="text-center" style="width: 140px;"><?= is_rtl() ? 'حالة خاصة' : 'Condition' ?></th>
                            <th><?= is_rtl() ? 'ملاحظة المعلم' : 'Pedagogical Remark' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students_marks)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted-foreground">
                                    <i class="fa-solid fa-user-xmark fs-2 mb-2 d-block"></i>
                                    <?= is_rtl() ? 'لا يوجد طلاب مقيدون في هذا الفصل الدراسي حالياً.' : 'No active students enrolled in this class section.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $idx = 1; foreach ($students_marks as $row): 
                                $sid = $row['student_id'];
                                $tot = $row['total_score'];
                                $g_let = $row['grade_letter'] ?? '—';
                                $gpa = $row['gpa_points'];
                                $cond = $row['special_condition'] ?? 'NONE';
                            ?>
                                <tr>
                                    <td class="text-muted-foreground font-monospace"><?= $idx++ ?></td>
                                    <td class="font-monospace text-foreground"><?= htmlspecialchars($row['admission_no']) ?></td>
                                    <td class="fw-bold text-foreground"><?= htmlspecialchars($row['student_name']) ?></td>

                                    <!-- CA Score Input -->
                                    <td class="text-center">
                                        <input type="number" step="0.5" min="0" max="<?= $ca_weight ?>" 
                                               name="ca_score[<?= $sid ?>]" 
                                               class="form-control form-control-sm mark-input mx-auto bg-background border-border text-foreground" 
                                               value="<?= $row['ca_score'] !== null ? $row['ca_score'] : '' ?>" 
                                               data-max="<?= $ca_weight ?>" 
                                               oninput="validateRange(this)" 
                                               <?= ($is_locked && !$is_admin) ? 'disabled' : '' ?>>
                                    </td>

                                    <!-- Paper 1 Score Input -->
                                    <td class="text-center">
                                        <input type="number" step="0.5" min="0" max="<?= $mid_weight ?>" 
                                               name="paper1_score[<?= $sid ?>]" 
                                               class="form-control form-control-sm mark-input mx-auto bg-background border-border text-foreground" 
                                               value="<?= $row['paper1_score'] !== null ? $row['paper1_score'] : '' ?>" 
                                               data-max="<?= $mid_weight ?>" 
                                               oninput="validateRange(this)" 
                                               <?= ($is_locked && !$is_admin) ? 'disabled' : '' ?>>
                                    </td>

                                    <!-- Paper 2 Score Input -->
                                    <td class="text-center">
                                        <input type="number" step="0.5" min="0" max="<?= $fin_weight ?>" 
                                               name="paper2_score[<?= $sid ?>]" 
                                               class="form-control form-control-sm mark-input mx-auto bg-background border-border text-foreground" 
                                               value="<?= $row['paper2_score'] !== null ? $row['paper2_score'] : '' ?>" 
                                               data-max="<?= $fin_weight ?>" 
                                               oninput="validateRange(this)" 
                                               <?= ($is_locked && !$is_admin) ? 'disabled' : '' ?>>
                                    </td>

                                    <!-- Computed Total -->
                                    <td class="text-center fw-bold fs-6 <?= ($tot !== null && $tot < 50) ? 'text-danger' : 'text-foreground' ?>">
                                        <?= $tot !== null ? number_format($tot, 1) : '—' ?>
                                    </td>

                                    <!-- Letter Grade -->
                                    <td class="text-center">
                                        <?php if ($cond !== 'NONE'): ?>
                                            <span class="badge bg-danger rounded-pill px-2"><?= $cond ?></span>
                                        <?php elseif ($g_let !== 'Pending' && $g_let !== '—'): ?>
                                            <span class="badge bg-primary rounded-pill px-2.5"><?= htmlspecialchars($g_let) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted-foreground small">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- GPA Quality Points -->
                                    <td class="text-center font-monospace small">
                                        <?= $gpa !== null ? number_format($gpa, 2) : '—' ?>
                                    </td>

                                    <!-- Special Condition Code -->
                                    <td class="text-center">
                                        <select name="special_condition[<?= $sid ?>]" class="form-select form-select-sm bg-background border-border text-foreground font-bold" <?= ($is_locked && !$is_admin) ? 'disabled' : '' ?>>
                                            <option value="NONE" <?= $cond === 'NONE' ? 'selected' : '' ?>>Normal</option>
                                            <option value="AB" <?= $cond === 'AB' ? 'selected' : '' ?>>Absent (AB)</option>
                                            <option value="EX" <?= $cond === 'EX' ? 'selected' : '' ?>>Exempt (EX)</option>
                                            <option value="ML" <?= $cond === 'ML' ? 'selected' : '' ?>>Medical (ML)</option>
                                            <option value="DQ" <?= $cond === 'DQ' ? 'selected' : '' ?>>Disqualified (DQ)</option>
                                        </select>
                                    </td>

                                    <!-- Remark -->
                                    <td>
                                        <input type="text" name="teacher_comment[<?= $sid ?>]" class="form-control form-control-sm bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'ملاحظة تربوية...' : 'Teacher comment...' ?>" value="<?= htmlspecialchars($row['teacher_comment'] ?? '') ?>" <?= ($is_locked && !$is_admin) ? 'disabled' : '' ?>>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$is_locked || $is_admin): ?>
                <div class="card-footer bg-card border-top border-border py-3 text-end">
                    <button type="submit" class="btn btn-success rounded-xl px-5 shadow-sm">
                        <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ وتحديث شبكة الدرجات' : 'Save & Validate Mark Sheet' ?>
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Modal: Final Approval & Mark Sheet Lock -->
<div class="modal fade" id="lockMatrixModal" tabindex="-1" aria-labelledby="lockMatrixModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <div class="modal-header border-border pb-3">
                <h5 class="modal-title font-heading fw-bold text-danger" id="lockMatrixModalLabel">
                    <i class="fa-solid fa-lock text-danger me-2"></i><?= is_rtl() ? 'الاعتماد النهائي وقفل شيت الدرجات' : 'Final Grade Approval & Matrix Lock' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="advance_moderation">
                <input type="hidden" name="new_stage" value="locked">
                <div class="modal-body">
                    <p class="text-foreground">
                        <?= is_rtl() 
                            ? 'سيؤدي هذا الإجراء إلى تجميد وقفل شبكة درجات المادة نهائياً ومنع المعلمين من إجراء أي تعديلات إضافية. هل تم التأكد من اكتمال الرصد وتدقيق كافة التقديرات؟' 
                            : 'This action will permanently lock and freeze the mark matrix against further edits by instructors. Have all scores and exemptions been verified?' ?>
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'مذكرة الاعتماد / قرار الكنترول' : 'Approval Minutes / Moderator Note' ?></label>
                        <textarea name="moderation_note" rows="3" class="form-control bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'مثال: تم تدقيق وتثبيت نتائج الاختبار النهائي واعتمادها للشهادات...' : 'E.g., All papers verified and approved for official transcripts...' ?>" required></textarea>
                    </div>
                </div>
                <div class="modal-footer border-border">
                    <button type="button" class="btn btn-outline-border rounded-xl px-3" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-danger rounded-xl px-4">
                        <i class="fa-solid fa-lock me-1"></i> <?= is_rtl() ? 'تأكيد القفل والاعتماد' : 'Confirm & Lock Permanently' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function validateRange(input) {
    const max = parseFloat(input.getAttribute('data-max'));
    const val = parseFloat(input.value);
    if (!isNaN(val) && (val < 0 || val > max)) {
        input.classList.add('is-out-of-range');
    } else {
        input.classList.remove('is-out-of-range');
    }
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
