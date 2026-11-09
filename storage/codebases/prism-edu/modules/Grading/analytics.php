<?php
/**
 * Grading Module - Performance Computation, Rankings & Analytics Engine
 * Features: Statistical Computations (Mean, Std Dev, Min/Max), Cohort/Section Rankings (Dense vs Standard),
 * Academic Risk Identification & Honor Roll, and Multi-Term Longitudinal Trends.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher']);

$role = Auth::role();
$user_id = Auth::id();
$can_edit = in_array($role, ['admin', 'school_admin']);

$msg = '';
$msg_type = 'success';

// Filters & Context
$class_id   = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$term       = $_GET['term'] ?? 'Term 1';
$year       = $_GET['year'] ?? '2025/2026';

$classes = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name ASC")->fetchAll();
if ($class_id <= 0 && !empty($classes)) {
    $class_id = (int)$classes[0]['class_id'];
}

// Fetch Ranking Policy
$policy = $pdo->query("SELECT * FROM grade_weightage_policies ORDER BY policy_id ASC LIMIT 1")->fetch();
$ranking_method = $policy ? ($policy['ranking_method'] ?? 'standard') : 'standard';

// Handle Ranking Computation & Sync
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit && isset($_POST['action']) && $_POST['action'] === 'sync_rankings') {
    try {
        // Fetch All Students in Class and their aggregate total marks
        $agg_stmt = $pdo->prepare("
            SELECT s.student_id, 
                   COALESCE(SUM(g.total_score), 0) as total_aggregate,
                   COALESCE(AVG(g.total_score), 0) as average_mark,
                   COALESCE(AVG(g.gpa_points), 0) as avg_gpa
            FROM students s
            LEFT JOIN grades g ON s.student_id = g.student_id AND g.term = ? AND g.academic_year = ?
            WHERE s.class_id = ? AND s.is_deleted = 0
            GROUP BY s.student_id
            ORDER BY total_aggregate DESC
        ");
        $agg_stmt->execute([$term, $year, $class_id]);
        $cohort_scores = $agg_stmt->fetchAll();

        $upd_rank = $pdo->prepare("
            UPDATE grades SET rank_in_class = ? 
            WHERE student_id = ? AND term = ? AND academic_year = ?
        ");

        $prev_score = null;
        $curr_rank = 0;
        $dense_rank = 0;
        $i = 0;

        foreach ($cohort_scores as $cs) {
            $i++;
            $score = round((float)$cs['total_aggregate'], 2);

            if ($score !== $prev_score) {
                $curr_rank = $i;
                $dense_rank++;
                $prev_score = $score;
            }

            $assigned_rank = ($ranking_method === 'dense') ? $dense_rank : $curr_rank;
            $upd_rank->execute([$assigned_rank, $cs['student_id'], $term, $year]);
        }

        $msg = is_rtl() ? 'تمت إعادة حساب وتحديث الترتيب الأكاديمي للطلاب بنجاح!' : 'Class rank positions computed and synchronized successfully!';
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $msg_type = 'danger';
    }
}

// Fetch Full Class Performance Roster for Analytics
$perf_stmt = $pdo->prepare("
    SELECT s.student_id, s.admission_no, 
           COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
           c.class_name,
           COUNT(g.grade_id) as total_subjects,
           COALESCE(SUM(g.total_score), 0) as total_marks,
           COALESCE(AVG(g.total_score), 0) as average_percentage,
           COALESCE(AVG(g.gpa_points), 0) as cumulative_gpa,
           MAX(g.rank_in_class) as assigned_rank,
           SUM(CASE WHEN g.total_score < 50 THEN 1 ELSE 0 END) as failed_subjects
    FROM students s
    JOIN classes c ON s.class_id = c.class_id
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN grades g ON s.student_id = g.student_id AND g.term = ? AND g.academic_year = ?
    WHERE s.class_id = ? AND s.is_deleted = 0
    GROUP BY s.student_id
    ORDER BY total_marks DESC
");
$perf_stmt->execute([$term, $year, $class_id]);
$cohort_roster = $perf_stmt->fetchAll();

// Statistical Computations: Mean, Standard Deviation, Highest, Lowest
$scores_array = [];
$total_cohort = count($cohort_roster);
$honor_roll_count = 0;
$risk_count = 0;

foreach ($cohort_roster as $row) {
    $avg = (float)$row['average_percentage'];
    if ($row['total_subjects'] > 0) {
        $scores_array[] = $avg;
        if ($avg >= 90.0 || (float)$row['cumulative_gpa'] >= 3.8) {
            $honor_roll_count++;
        }
        if ($avg < 50.0 || (int)$row['failed_subjects'] > 0) {
            $risk_count++;
        }
    }
}

$evaluated_count = count($scores_array);
$mean_avg = $evaluated_count > 0 ? array_sum($scores_array) / $evaluated_count : 0;
$highest_mark = !empty($scores_array) ? max($scores_array) : 0;
$lowest_mark = !empty($scores_array) ? min($scores_array) : 0;

// Calculate Standard Deviation (sigma)
$variance = 0.0;
if ($evaluated_count > 1) {
    foreach ($scores_array as $sc) {
        $variance += pow($sc - $mean_avg, 2);
    }
    $std_dev = sqrt($variance / ($evaluated_count - 1));
} else {
    $std_dev = 0.0;
}

$pass_rate = $evaluated_count > 0 ? round((($evaluated_count - $risk_count) / $evaluated_count) * 100) : 0;
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'محرك الحوسبة والترتيب والتحليلات الأكاديمية - نظام الدرجات' : 'Performance Analytics & Rankings Engine' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'التحليلات والترتيب' : 'Performance Analytics',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="policies.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sliders me-1 text-primary"></i> ' . (is_rtl() ? 'السياسات' : 'Policies') . '</a>',
        '<a href="scheduling.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-calendar-check me-1 text-info"></i> ' . (is_rtl() ? 'الجدولة' : 'Scheduling') . '</a>',
        '<a href="matrix.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-table-cells me-1 text-success"></i> ' . (is_rtl() ? 'شبكة الرصد' : 'Mark Matrix') . '</a>',
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
                <i class="fa-solid fa-chart-pie text-purple me-2"></i><?= is_rtl() ? 'محرك الحوسبة التراكمية والترتيب والتحليلات' : 'Performance Analytics & Rankings Engine' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'احتساب المعدلات التراكمية، الانحراف المعياري، ترتيب الطلاب (تنافسي/متتالي)، وقوائم الشرف والإنذار الأكاديمي.' : 'Aggregated GPAs, statistical distributions, dense/competition ranking, honor roll, and academic risk detection.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($can_edit): ?>
                <form method="POST" action="" onsubmit="return confirm('<?= is_rtl() ? 'إعادة حساب ومزامنة مراكز الطلاب في قاعدة البيانات؟' : 'Recompute and synchronize all student rank positions?' ?>');">
                    <input type="hidden" name="action" value="sync_rankings">
                    <button type="submit" class="btn btn-primary rounded-xl shadow-sm px-3">
                        <i class="fa-solid fa-medal me-1"></i> <?= is_rtl() ? 'مزامنة الترتيب الصفي' : 'Compute & Sync Class Rank' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter Toolbar -->
    <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
        <form method="GET" action="" class="row g-2 align-items-center">
            <div class="col-12 col-md-5">
                <label class="form-label small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'الفصل الدراسي:' : 'Class Section:' ?></label>
                <select name="class_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                    <?php foreach ($classes as $cl): ?>
                        <option value="<?= $cl['class_id'] ?>" <?= $class_id == $cl['class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['class_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'الفصل:' : 'Term:' ?></label>
                <select name="term" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                    <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                    <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                    <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'العام الأكاديمي:' : 'Academic Year:' ?></label>
                <input type="text" name="year" class="form-control form-control-sm bg-background border-border text-foreground" value="<?= htmlspecialchars($year) ?>">
            </div>
            <div class="col-12 col-md-2 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm rounded-lg flex-fill">
                    <i class="fa-solid fa-filter me-1"></i><?= is_rtl() ? 'تطبيق' : 'Apply' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Statistical Executive Overview Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-calculator"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= round($mean_avg, 1) ?>%</div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'المتوسط الحسابي (Mean)' : 'Cohort Mean Average' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-chart-area"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground">&plusmn; <?= round($std_dev, 2) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الانحراف المعياري (&sigma;)' : 'Std Deviation (&sigma;)' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-award"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $honor_roll_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'لوحة الشرف (>= 90%)' : 'Honor Roll Cadets' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-danger me-3">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $risk_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'طلاب بحاجة لدعم أكاديمي' : 'At Academic Risk' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cohort Academic Standing Roster Table -->
    <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
        <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="fw-bold font-heading mb-0 text-foreground">
                <i class="fa-solid fa-ranking-star text-warning me-2"></i><?= is_rtl() ? 'ترتيب الطلاب والتحصيل التراكمي' : 'Student Cumulative Standing & Rank Roster' ?>
            </h5>
            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill font-monospace small">
                Algorithm: <?= ucfirst($ranking_method) ?> Competition Ranking
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width: 80px;" class="text-center"><?= is_rtl() ? 'الترتيب' : 'Rank' ?></th>
                        <th><?= is_rtl() ? 'رقم القيد' : 'Admission #' ?></th>
                        <th><?= is_rtl() ? 'اسم الطالب' : 'Student Full Name' ?></th>
                        <th class="text-center"><?= is_rtl() ? 'المواد المرصودة' : 'Subjects' ?></th>
                        <th class="text-center"><?= is_rtl() ? 'المجموع الكلي' : 'Total Marks' ?></th>
                        <th class="text-center"><?= is_rtl() ? 'المعدل العام (%)' : 'Overall %' ?></th>
                        <th class="text-center"><?= is_rtl() ? 'المعدل التراكمي GPA' : 'GPA' ?></th>
                        <th class="text-center"><?= is_rtl() ? 'الحالة الأكاديمية' : 'Standing Status' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cohort_roster)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted-foreground"><?= is_rtl() ? 'لا توجد درجات مرصودة لهذا الفصل حتى الآن.' : 'No student marks compiled for this class section yet.' ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($cohort_roster as $st): 
                            $avg_pct = round((float)$st['average_percentage'], 1);
                            $gpa_val = round((float)$st['cumulative_gpa'], 2);
                            $rank = $st['assigned_rank'] ?: '—';
                            $is_honor = ($avg_pct >= 90.0 || $gpa_val >= 3.8);
                            $is_risk = ($avg_pct < 50.0 || (int)$st['failed_subjects'] > 0);
                        ?>
                            <tr>
                                <td class="text-center">
                                    <?php if ($rank == 1): ?>
                                        <span class="badge bg-warning text-dark rounded-circle p-2 fs-6 shadow-xs"><i class="fa-solid fa-crown"></i> 1</span>
                                    <?php elseif ($rank == 2): ?>
                                        <span class="badge bg-secondary text-white rounded-circle p-2 fs-6 shadow-xs">2</span>
                                    <?php elseif ($rank == 3): ?>
                                        <span class="badge bg-info text-white rounded-circle p-2 fs-6 shadow-xs">3</span>
                                    <?php else: ?>
                                        <span class="fw-bold font-monospace text-foreground">#<?= $rank ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-monospace text-muted-foreground"><?= htmlspecialchars($st['admission_no']) ?></td>
                                <td class="fw-bold text-foreground">
                                    <?= htmlspecialchars($st['student_name']) ?>
                                    <?php if ($is_honor): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle ms-2 rounded-pill small">
                                            <i class="fa-solid fa-star me-1"></i>Honor Roll
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-monospace"><?= $st['total_subjects'] ?></td>
                                <td class="text-center fw-bold text-foreground font-monospace"><?= number_format($st['total_marks'], 1) ?></td>
                                <td class="text-center fw-bold fs-6 <?= $avg_pct < 50 ? 'text-danger' : 'text-primary' ?>">
                                    <?= $avg_pct ?>%
                                </td>
                                <td class="text-center font-monospace fw-bold">
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill">
                                        <?= number_format($gpa_val, 2) ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($is_risk): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill px-2.5 py-1">
                                            <i class="fa-solid fa-triangle-exclamation me-1"></i><?= is_rtl() ? 'إنذار أكاديمي' : 'Academic Alert' ?>
                                        </span>
                                    <?php elseif ($is_honor): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-2.5 py-1">
                                            <i class="fa-solid fa-medal me-1"></i><?= is_rtl() ? 'امتياز' : 'Distinction' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-2.5 py-1">
                                            <i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'أداء جيد' : 'Good Standing' ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
