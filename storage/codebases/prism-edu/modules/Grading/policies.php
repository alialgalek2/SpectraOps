<?php
/**
 * Grading Module - Examination Setup, Weightage & Policy Configuration
 * Features: Exam Series Hierarchy, Component Weightage Formulas, Grading Scales & Boundaries
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher']);

$role = Auth::role();
$user_id = Auth::id();
$can_edit = in_array($role, ['admin', 'school_admin']);

$msg = '';
$msg_type = 'success';

// Handle Action Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_series') {
            $title = trim($_POST['title'] ?? '');
            $series_code = trim($_POST['series_code'] ?? '');
            $academic_year = trim($_POST['academic_year'] ?? '2025/2026');
            $term = trim($_POST['term'] ?? 'Term 1');
            $start_date = $_POST['start_date'] ?? date('Y-m-d');
            $end_date = $_POST['end_date'] ?? date('Y-m-d', strtotime('+10 days'));
            $weight_percent = floatval($_POST['weight_percent'] ?? 50.00);
            $status = $_POST['status'] ?? 'Active';

            if (empty($title) || empty($series_code)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى إدخال مسمى سلسلة الامتحانات والرمز التعريفي.' : 'Please enter exam series title and code.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO exam_series (title, series_code, academic_year, term, start_date, end_date, weight_percent, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $series_code, $academic_year, $term, $start_date, $end_date, $weight_percent, $status]);

            $msg = is_rtl() ? 'تم إنشاء سلسلة الامتحانات بنجاح.' : 'Exam series created successfully.';
        } elseif ($action === 'update_policy') {
            $policy_id = (int)($_POST['policy_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $ca_weight = floatval($_POST['ca_weight'] ?? 20);
            $midterm_weight = floatval($_POST['midterm_weight'] ?? 30);
            $final_weight = floatval($_POST['final_weight'] ?? 50);
            $ranking_method = $_POST['ranking_method'] ?? 'standard';
            $show_rank = isset($_POST['show_rank_on_report']) ? 1 : 0;
            $clearance_threshold = floatval($_POST['clearance_threshold'] ?? 0.00);

            // Validate total weight sums to 100%
            $total_weight = $ca_weight + $midterm_weight + $final_weight;
            if (abs($total_weight - 100.0) > 0.01) {
                throw new InvalidArgumentException(is_rtl() ? "مجموع الأوزان النسبية يجب أن يساوي 100% (المجموع الحالي: $total_weight%)." : "Total component weights must equal 100% (Current sum: $total_weight%).");
            }

            if ($policy_id > 0) {
                $stmt = $pdo->prepare("
                    UPDATE grade_weightage_policies 
                    SET title = ?, ca_weight = ?, midterm_weight = ?, final_weight = ?, ranking_method = ?, show_rank_on_report = ?, clearance_threshold = ?
                    WHERE policy_id = ?
                ");
                $stmt->execute([$title, $ca_weight, $midterm_weight, $final_weight, $ranking_method, $show_rank, $clearance_threshold, $policy_id]);
                $msg = is_rtl() ? 'تم تحديث سياسة معادلة الأوزان النسبية بنجاح.' : 'Weightage and ranking policy updated successfully.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO grade_weightage_policies (title, academic_year, term, ca_weight, midterm_weight, final_weight, ranking_method, show_rank_on_report, clearance_threshold)
                    VALUES (?, '2025/2026', 'Term 1', ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$title, $ca_weight, $midterm_weight, $final_weight, $ranking_method, $show_rank, $clearance_threshold]);
                $msg = is_rtl() ? 'تم حفظ سياسة حساب الدرجات الجديدة.' : 'New weightage policy created successfully.';
            }
        } elseif ($action === 'add_scale_tier') {
            $scale_id = (int)($_POST['scale_id'] ?? 1);
            $grade_letter = trim($_POST['grade_letter'] ?? '');
            $min_pct = floatval($_POST['min_percentage'] ?? 0);
            $max_pct = floatval($_POST['max_percentage'] ?? 100);
            $gpa_pt = floatval($_POST['gpa_point'] ?? 0);
            $band = trim($_POST['performance_band'] ?? 'Pass');
            $remark = trim($_POST['remark'] ?? '');

            if (empty($grade_letter) || $min_pct > $max_pct) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى مراجعة بيانات الشريحة والنسب المئوية.' : 'Please verify tier letter and percentage boundary ranges.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO grading_scale_tiers (scale_id, grade_letter, min_percentage, max_percentage, gpa_point, performance_band, remark)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$scale_id, $grade_letter, $min_pct, $max_pct, $gpa_pt, $band, $remark]);
            $msg = is_rtl() ? 'تمت إضافة شريحة التقدير بنجاح.' : 'Grading tier added successfully.';
        } elseif ($action === 'delete_scale_tier') {
            $tier_id = (int)($_POST['tier_id'] ?? 0);
            if ($tier_id > 0) {
                $pdo->prepare("DELETE FROM grading_scale_tiers WHERE tier_id = ?")->execute([$tier_id]);
                $msg = is_rtl() ? 'تم حذف شريحة التقدير.' : 'Grading tier deleted.';
            }
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $msg_type = 'danger';
    }
}

// Fetch Data
$series_list = $pdo->query("SELECT * FROM exam_series ORDER BY start_date DESC")->fetchAll();
$policies = $pdo->query("SELECT * FROM grade_weightage_policies ORDER BY policy_id ASC")->fetchAll();
$current_policy = !empty($policies) ? $policies[0] : [
    'policy_id' => 0,
    'title' => 'Standard Secondary Weightage Formula',
    'ca_weight' => 20,
    'midterm_weight' => 30,
    'final_weight' => 50,
    'ranking_method' => 'standard',
    'show_rank_on_report' => 1,
    'clearance_threshold' => 0.00
];

$scales = $pdo->query("SELECT * FROM grading_scales ORDER BY is_default DESC, scale_id ASC")->fetchAll();
$selected_scale_id = isset($_GET['scale_id']) ? (int)$_GET['scale_id'] : ($scales[0]['scale_id'] ?? 1);

$tiers_stmt = $pdo->prepare("SELECT * FROM grading_scale_tiers WHERE scale_id = ? ORDER BY min_percentage DESC");
$tiers_stmt->execute([$selected_scale_id]);
$tiers = $tiers_stmt->fetchAll();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'إعدادات الامتحانات ومعادلات الأوزان والسلالم - نظام الدرجات' : 'Exam Setup, Weightage & Grading Policies' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .tier-row {
            transition: background 0.15s ease;
        }
        .tier-row:hover {
            background-color: var(--muted);
        }
        .weight-pill {
            font-weight: 700;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'إعدادات وسياسات الامتحانات' : 'Exam Setup & Policies',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="scheduling.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-calendar-check me-1 text-info"></i> ' . (is_rtl() ? 'الجدولة والمراقبة' : 'Timetables & Halls') . '</a>',
        '<a href="matrix.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-table-cells me-1 text-success"></i> ' . (is_rtl() ? 'شبكة الرصد' : 'Mark Matrix') . '</a>',
        '<a href="analytics.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-chart-line me-1 text-purple"></i> ' . (is_rtl() ? 'الإحصائيات والترتيب' : 'Analytics') . '</a>',
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

    <!-- Header Actions Bar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-sliders text-primary me-2"></i><?= is_rtl() ? 'تهيئة سلاسل الامتحانات، أوزان التقييم وسلالم الدرجات' : 'Examination Setup, Weightage & Policy Engine' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'إدارة مواعيد السلاسل الامتحانية، صياغة معادلات احتساب الدرجة النهائية (أعمال سنة / نصفي / نهائي)، وتخصيص سلالم التقدير والـ GPA.' : 'Configure term exam events, define weighted grade composition formulas, and customize multi-curriculum letter/GPA scales.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary rounded-xl shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#newSeriesModal">
                    <i class="fa-solid fa-plus-circle me-1"></i> <?= is_rtl() ? 'إنشاء سلسلة امتحانات' : 'New Exam Series' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overview Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($series_list) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'سلاسل الامتحانات' : 'Exam Series Events' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-percent"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($policies) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'معادلات الأوزان' : 'Weightage Policies' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($scales) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'سلالم الدرجات المعتمدة' : 'Active Grading Scales' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($tiers) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'شرائح التقديرات (النشط)' : 'Scale Tiers (Active)' ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <!-- Section 1: Examination Series & Term Hierarchy -->
        <div class="col-12 col-xl-7">
            <div class="card bg-card border border-border rounded-xl shadow-sm h-100">
                <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                    <h5 class="fw-bold font-heading mb-0 text-foreground">
                        <i class="fa-solid fa-calendar-check text-primary me-2"></i><?= is_rtl() ? 'سلاسل ومواسم الاختبارات الرسمية' : 'Official Examination Series & Windows' ?>
                    </h5>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill">
                        <?= count($series_list) ?> <?= is_rtl() ? 'سلسلة مسجلة' : 'Series' ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?= is_rtl() ? 'السلسلة / الاختبار' : 'Exam Series Title' ?></th>
                                <th><?= is_rtl() ? 'العام / الفصل' : 'Session & Term' ?></th>
                                <th><?= is_rtl() ? 'الفترة الزمنية' : 'Date Window' ?></th>
                                <th><?= is_rtl() ? 'الوزن' : 'Weight' ?></th>
                                <th><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($series_list)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد سلاسل امتحانات مضافة حتى الآن.' : 'No examination series defined yet.' ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($series_list as $s): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-bold text-foreground"><?= htmlspecialchars($s['title']) ?></div>
                                            <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($s['series_code']) ?></div>
                                        </td>
                                        <td>
                                            <div class="small text-foreground fw-medium"><?= htmlspecialchars($s['academic_year']) ?></div>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"><?= htmlspecialchars($s['term']) ?></span>
                                        </td>
                                        <td>
                                            <div class="small text-foreground"><?= date('M d', strtotime($s['start_date'])) ?> - <?= date('M d, Y', strtotime($s['end_date'])) ?></div>
                                            <div class="small text-muted-foreground"><?= round((strtotime($s['end_date']) - strtotime($s['start_date'])) / 86400) ?> <?= is_rtl() ? 'يوم' : 'days' ?></div>
                                        </td>
                                        <td>
                                            <span class="badge bg-info-subtle text-info border border-info-subtle rounded-pill">
                                                <?= number_format($s['weight_percent'], 1) ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $st_class = 'secondary';
                                            if ($s['status'] === 'Active') $st_class = 'success';
                                            elseif ($s['status'] === 'Upcoming') $st_class = 'primary';
                                            elseif ($s['status'] === 'Under Review') $st_class = 'warning';
                                            ?>
                                            <span class="badge bg-<?= $st_class ?>-subtle text-<?= $st_class ?> border border-<?= $st_class ?>-subtle rounded-pill">
                                                <?= htmlspecialchars($s['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Section 2: Component Weightage Formula Engine -->
        <div class="col-12 col-xl-5">
            <div class="card bg-card border border-border rounded-xl shadow-sm h-100">
                <div class="card-header bg-card border-bottom border-border py-3">
                    <h5 class="fw-bold font-heading mb-0 text-foreground">
                        <i class="fa-solid fa-calculator text-success me-2"></i><?= is_rtl() ? 'معادلة احتساب الدرجة النهائية والأوزان' : 'Component Weightage Formula Engine' ?>
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_policy">
                        <input type="hidden" name="policy_id" value="<?= $current_policy['policy_id'] ?? 0 ?>">

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'مسمى سياسة الاحتساب' : 'Weightage Policy Title' ?></label>
                            <input type="text" name="title" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_policy['title'] ?? 'Standard Secondary Weightage Formula') ?>" required>
                        </div>

                        <!-- Live Visual Formula Banner -->
                        <div class="p-3 mb-3 bg-secondary-subtle border border-border rounded-xl">
                            <div class="small fw-bold text-muted-foreground mb-2"><?= is_rtl() ? 'الصيغة الرياضية المطبقة للدرجة النهائية:' : 'Active Applied Final Score Formula:' ?></div>
                            <div class="d-flex align-items-center flex-wrap gap-2 text-foreground font-monospace small">
                                <span class="badge bg-primary text-white p-2 rounded-lg">Final 100%</span>
                                <span>=</span>
                                <span class="badge bg-info text-white p-2 rounded-lg">(CA <?= $current_policy['ca_weight'] ?>%)</span>
                                <span>+</span>
                                <span class="badge bg-warning text-dark p-2 rounded-lg">(Mid-Term <?= $current_policy['midterm_weight'] ?>%)</span>
                                <span>+</span>
                                <span class="badge bg-success text-white p-2 rounded-lg">(Final Paper <?= $current_policy['final_weight'] ?>%)</span>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'أعمال السنة (CA)' : 'Continuous (CA)' ?></label>
                                <div class="input-group input-group-sm">
                                    <input type="number" step="0.5" min="0" max="100" name="ca_weight" class="form-control bg-background border-border text-foreground" value="<?= $current_policy['ca_weight'] ?>" required>
                                    <span class="input-group-text bg-background border-border">%</span>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اختبار نصفي' : 'Mid-Term Exam' ?></label>
                                <div class="input-group input-group-sm">
                                    <input type="number" step="0.5" min="0" max="100" name="midterm_weight" class="form-control bg-background border-border text-foreground" value="<?= $current_policy['midterm_weight'] ?>" required>
                                    <span class="input-group-text bg-background border-border">%</span>
                                </div>
                            </div>
                            <div class="col-4">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الامتحان النهائي' : 'Final Exam' ?></label>
                                <div class="input-group input-group-sm">
                                    <input type="number" step="0.5" min="0" max="100" name="final_weight" class="form-control bg-background border-border text-foreground" value="<?= $current_policy['final_weight'] ?>" required>
                                    <span class="input-group-text bg-background border-border">%</span>
                                </div>
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'خوارزمية الترتيب الأكاديمي' : 'Ranking Algorithm' ?></label>
                                <select name="ranking_method" class="form-select form-select-sm bg-background border-border text-foreground">
                                    <option value="standard" <?= ($current_policy['ranking_method'] ?? '') === 'standard' ? 'selected' : '' ?>><?= is_rtl() ? 'ترتيب تنافسي قياسي (1, 2, 2, 4)' : 'Standard Competition (1, 2, 2, 4)' ?></option>
                                    <option value="dense" <?= ($current_policy['ranking_method'] ?? '') === 'dense' ? 'selected' : '' ?>><?= is_rtl() ? 'ترتيب متتالي مصمت (1, 2, 2, 3)' : 'Dense Ranking (1, 2, 2, 3)' ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'حاجز الحجب المالي للشهادات' : 'Financial Clearance Threshold' ?></label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-background border-border">$</span>
                                    <input type="number" step="1.0" min="0" name="clearance_threshold" class="form-control bg-background border-border text-foreground" value="<?= $current_policy['clearance_threshold'] ?? 0.00 ?>">
                                </div>
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="showRankSwitch" name="show_rank_on_report" value="1" <?= !empty($current_policy['show_rank_on_report']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-medium text-foreground small" for="showRankSwitch">
                                <?= is_rtl() ? 'إظهار الترتيب والمركز الصفي على الشهادات الرسمية' : 'Display Class Rank Position on Official Report Cards' ?>
                            </label>
                        </div>

                        <?php if ($can_edit): ?>
                            <div class="text-end">
                                <button type="submit" class="btn btn-success btn-sm rounded-xl px-3">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ إعدادات المعادلة' : 'Save Formula Policy' ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 3: Grading Scales & Boundaries -->
    <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
        <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <h5 class="fw-bold font-heading mb-0 text-foreground">
                    <i class="fa-solid fa-stairs text-purple me-2"></i><?= is_rtl() ? 'سلالم التقديرات، الرموز الحرفية ونقاط الـ GPA' : 'Grading Scales, Letter Boundaries & GPA Points' ?>
                </h5>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'تحويل النسب المئوية إلى تقديرات حرفية، نقاط جودة تراكمية، ونطاقات أداء وصفية.' : 'Conversion schema mapping raw percentages into letter marks, GPA quality points, and descriptive bands.' ?></div>
            </div>
            <div class="d-flex align-items-center gap-2">
                <form method="GET" action="" class="d-inline-flex align-items-center gap-2">
                    <label class="small text-muted-foreground fw-bold text-nowrap"><?= is_rtl() ? 'السلم النشط:' : 'Active Scale:' ?></label>
                    <select name="scale_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <?php foreach ($scales as $sc): ?>
                            <option value="<?= $sc['scale_id'] ?>" <?= $selected_scale_id == $sc['scale_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($sc['name']) ?> (<?= strtoupper($sc['scale_type']) ?>) <?= !empty($sc['is_default']) ? '⭐' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($can_edit): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm rounded-xl px-2" data-bs-toggle="modal" data-bs-target="#newTierModal">
                        <i class="fa-solid fa-plus-circle me-1"></i><?= is_rtl() ? 'إضافة شريحة' : 'Add Tier' ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= is_rtl() ? 'التقدير الحرفي' : 'Grade Letter' ?></th>
                        <th><?= is_rtl() ? 'الحد الأدنى (%)' : 'Min %' ?></th>
                        <th><?= is_rtl() ? 'الحد الأعلى (%)' : 'Max %' ?></th>
                        <th><?= is_rtl() ? 'نقاط الـ GPA (من 4.0)' : 'GPA Quality Points' ?></th>
                        <th><?= is_rtl() ? 'المستوى الوصفي' : 'Performance Band' ?></th>
                        <th><?= is_rtl() ? 'الملاحظة الإرشادية' : 'Descriptive Remark' ?></th>
                        <?php if ($can_edit): ?><th><?= is_rtl() ? 'إجراءات' : 'Actions' ?></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tiers)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد شرائح مضافة لهذا السلم.' : 'No tiers configured for this scale.' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tiers as $tr): ?>
                            <tr class="tier-row">
                                <td>
                                    <span class="badge bg-primary px-2.5 py-1.5 fs-6 rounded-lg font-heading">
                                        <?= htmlspecialchars($tr['grade_letter']) ?>
                                    </span>
                                </td>
                                <td class="fw-bold text-foreground"><?= number_format($tr['min_percentage'], 2) ?>%</td>
                                <td class="fw-bold text-foreground"><?= number_format($tr['max_percentage'], 2) ?>%</td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill font-monospace">
                                        <?= number_format($tr['gpa_point'], 2) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $band_color = 'secondary';
                                    if ($tr['performance_band'] === 'Distinction') $band_color = 'success';
                                    elseif ($tr['performance_band'] === 'Merit') $band_color = 'info';
                                    elseif ($tr['performance_band'] === 'Pass') $band_color = 'warning';
                                    elseif ($tr['performance_band'] === 'Fail') $band_color = 'danger';
                                    ?>
                                    <span class="badge bg-<?= $band_color ?>-subtle text-<?= $band_color ?> border border-<?= $band_color ?>-subtle rounded-pill">
                                        <?= htmlspecialchars($tr['performance_band']) ?>
                                    </span>
                                </td>
                                <td class="small text-muted-foreground"><?= htmlspecialchars($tr['remark'] ?? '—') ?></td>
                                <?php if ($can_edit): ?>
                                    <td>
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'حذف هذه الشريحة؟' : 'Delete this tier?' ?>');">
                                            <input type="hidden" name="action" value="delete_scale_tier">
                                            <input type="hidden" name="tier_id" value="<?= $tr['tier_id'] ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill py-0 px-2" title="Delete Tier">
                                                <i class="fa-solid fa-trash-can fa-xs"></i>
                                            </button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: New Exam Series -->
<?php if ($can_edit): ?>
<div class="modal fade" id="newSeriesModal" tabindex="-1" aria-labelledby="newSeriesModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <div class="modal-header border-border pb-3">
                <h5 class="modal-title font-heading fw-bold text-foreground" id="newSeriesModalLabel">
                    <i class="fa-solid fa-calendar-plus text-primary me-2"></i><?= is_rtl() ? 'إنشاء سلسلة امتحانات وتقييمات جديدة' : 'Create New Examination Series Event' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_series">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'مسمى سلسلة الاختبارات *' : 'Series Title *' ?></label>
                            <input type="text" name="title" class="form-control bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'مثال: امتحانات نهاية الفصل الدراسي الأول 2025' : 'E.g., Term 1 Final Examinations 2025' ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'رمز السلسلة التعريفي *' : 'Unique Series Code *' ?></label>
                            <input type="text" name="series_code" class="form-control bg-background border-border text-foreground font-monospace" placeholder="EXAM-2025-T1-FINAL" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'العام الأكاديمي' : 'Academic Year' ?></label>
                            <input type="text" name="academic_year" class="form-control bg-background border-border text-foreground" value="2025/2026" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي' : 'Academic Term' ?></label>
                            <select name="term" class="form-select bg-background border-border text-foreground">
                                <option value="Term 1">Term 1</option>
                                <option value="Term 2">Term 2</option>
                                <option value="Term 3">Term 3</option>
                                <option value="Mid-Term">Mid-Term</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'تاريخ البداية' : 'Start Date' ?></label>
                            <input type="date" name="start_date" class="form-control bg-background border-border text-foreground" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'تاريخ النهاية' : 'End Date' ?></label>
                            <input type="date" name="end_date" class="form-control bg-background border-border text-foreground" value="<?= date('Y-m-d', strtotime('+10 days')) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الوزن النسبي (%)' : 'Weight Percentage (%)' ?></label>
                            <input type="number" step="0.5" name="weight_percent" class="form-control bg-background border-border text-foreground" value="50.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'حالة السلسلة' : 'Status' ?></label>
                            <select name="status" class="form-select bg-background border-border text-foreground">
                                <option value="Upcoming">Upcoming</option>
                                <option value="Active" selected>Active</option>
                                <option value="Under Review">Under Review</option>
                                <option value="Completed">Completed</option>
                                <option value="Published">Published</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-border">
                    <button type="button" class="btn btn-outline-border rounded-xl px-3" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-xl px-4">
                        <i class="fa-solid fa-check me-1"></i> <?= is_rtl() ? 'إنشاء السلسلة' : 'Create Series' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: New Scale Tier -->
<div class="modal fade" id="newTierModal" tabindex="-1" aria-labelledby="newTierModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <div class="modal-header border-border pb-3">
                <h5 class="modal-title font-heading fw-bold text-foreground" id="newTierModalLabel">
                    <i class="fa-solid fa-plus-circle text-primary me-2"></i><?= is_rtl() ? 'إضافة شريحة تقدير' : 'Add Scale Tier' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_scale_tier">
                <input type="hidden" name="scale_id" value="<?= $selected_scale_id ?>">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الرمز الحرفي *' : 'Grade Letter *' ?></label>
                            <input type="text" name="grade_letter" class="form-control bg-background border-border text-foreground font-heading" placeholder="A, B+, C..." required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'نقاط الـ GPA (0 - 4.0)' : 'GPA Point' ?></label>
                            <input type="number" step="0.01" min="0" max="5.0" name="gpa_point" class="form-control bg-background border-border text-foreground" value="3.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الحد الأدنى (%)' : 'Min Percentage (%)' ?></label>
                            <input type="number" step="0.01" min="0" max="100" name="min_percentage" class="form-control bg-background border-border text-foreground" value="70.00" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الحد الأعلى (%)' : 'Max Percentage (%)' ?></label>
                            <input type="number" step="0.01" min="0" max="100" name="max_percentage" class="form-control bg-background border-border text-foreground" value="79.99" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'المستوى الوصفي' : 'Performance Band' ?></label>
                            <select name="performance_band" class="form-select bg-background border-border text-foreground">
                                <option value="Distinction">Distinction</option>
                                <option value="Merit">Merit</option>
                                <option value="Pass" selected>Pass</option>
                                <option value="Fail">Fail</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الملاحظة الإرشادية' : 'Descriptive Remark' ?></label>
                            <input type="text" name="remark" class="form-control bg-background border-border text-foreground" placeholder="Above Average, Satisfactory...">
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-border">
                    <button type="button" class="btn btn-outline-border rounded-xl px-3" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-xl px-4">
                        <i class="fa-solid fa-check me-1"></i> <?= is_rtl() ? 'إضافة الشريحة' : 'Save Tier' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
