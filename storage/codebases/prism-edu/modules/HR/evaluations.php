<?php
/**
 * HR Module - Faculty & Staff Performance Evaluations & Appraisals
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin, School Admin, HR, or Principal
Auth::requireRole(['admin', 'school_admin', 'hr']);

$user_role = Auth::role();
$user_id = Auth::id();
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_msg_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// -------------------------------------------------------------
// 1. Handle Evaluation Submission
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_evaluation'])) {
    $emp_id = intval($_POST['employee_id'] ?? 0);
    $period = trim($_POST['evaluation_period'] ?? '2025/2026 - Term 1');
    $teaching = max(1, min(5, intval($_POST['teaching_quality'] ?? 5)));
    $punctuality = max(1, min(5, intval($_POST['punctuality'] ?? 5)));
    $management = max(1, min(5, intval($_POST['classroom_management'] ?? 5)));
    $teamwork = max(1, min(5, intval($_POST['teamwork'] ?? 5)));
    $recommendation = $_POST['recommendation'] ?? 'Retain';
    $comments = trim($_POST['comments'] ?? '');
    $eval_date = $_POST['evaluation_date'] ?? date('Y-m-d');

    // Score out of 100%: ((sum of 4 items) / 20) * 100
    $overall_score = round((($teaching + $punctuality + $management + $teamwork) / 20) * 100, 2);

    if ($emp_id > 0 && !empty($period)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO staff_evaluations (employee_id, evaluator_id, evaluation_period, teaching_quality, punctuality, classroom_management, teamwork, overall_score, recommendation, comments, evaluation_date)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$emp_id, $user_id, $period, $teaching, $punctuality, $management, $teamwork, $overall_score, $recommendation, $comments, $eval_date]);
            $_SESSION['flash_msg'] = is_rtl() ? "تم تسجيل تقييم الأداء بنجاح بدرجة $overall_score%!" : "Performance evaluation recorded successfully with score $overall_score%!";
            $_SESSION['flash_msg_type'] = "success";
        } catch (\PDOException $e) {
            $_SESSION['flash_msg'] = "Database Error: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = "danger";
        }
    } else {
        $_SESSION['flash_msg'] = is_rtl() ? "يرجى اختيار الموظف والفترة التقييمية." : "Please select an employee and appraisal period.";
        $_SESSION['flash_msg_type'] = "warning";
    }
    header("Location: evaluations.php");
    exit();
}

// -------------------------------------------------------------
// 2. Fetch Evaluations & Metrics
// -------------------------------------------------------------
$search_query   = trim($_GET['q'] ?? '');
$filter_recom   = trim($_GET['recom'] ?? '');
$filter_period  = trim($_GET['period'] ?? '');

$evaluations = [];
try {
    $sql = "
        SELECT se.*, 
               e.full_name as employee_name, e.employee_code, e.job_title, e.category,
               d.department_name,
               u.full_name as evaluator_name
        FROM staff_evaluations se
        JOIN employees e ON se.employee_id = e.employee_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        LEFT JOIN users u ON se.evaluator_id = u.user_id
        WHERE 1=1
    ";
    $params = [];

    if (!empty($search_query)) {
        $sql .= " AND (e.full_name LIKE ? OR e.employee_code LIKE ? OR se.comments LIKE ?)";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
        $params[] = "%$search_query%";
    }

    if (!empty($filter_recom)) {
        $sql .= " AND se.recommendation = ?";
        $params[] = $filter_recom;
    }

    if (!empty($filter_period)) {
        $sql .= " AND se.evaluation_period = ?";
        $params[] = $filter_period;
    }

    $sql .= " ORDER BY se.evaluation_date DESC, se.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Exception $e) {}

// Calculate KPI Summary
$totalEvals = count($evaluations);
$avgScore = 0.0;
$topPerformers = 0;
if ($totalEvals > 0) {
    $sumScore = 0;
    foreach ($evaluations as $ev) {
        $sumScore += floatval($ev['overall_score']);
        if (floatval($ev['overall_score']) >= 90.0) $topPerformers++;
    }
    $avgScore = round($sumScore / $totalEvals, 1);
}

// Fetch active employees for dropdown
$active_employees = $pdo->query("SELECT employee_id, full_name, category, job_title FROM employees WHERE status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Distinct periods for filter dropdown
$periods_list = $pdo->query("SELECT DISTINCT evaluation_period FROM staff_evaluations ORDER BY evaluation_period DESC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'تقييم أداء الكوادر والموظفين' : 'Staff Performance & KPI Appraisals' ?> - <?= __('portal_title') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'تقييم أداء الكوادر والـ KPIs' : 'Staff Appraisals & KPIs',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="employees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-users me-1 text-primary"></i> ' . (is_rtl() ? 'دليل الموظفين' : 'Staff') . '</a>',
        '<a href="departments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sitemap me-1 text-primary"></i> ' . (is_rtl() ? 'الأقسام' : 'Departments') . '</a>'
    ]
]) ?>

<div class="container-fluid px-4 py-4">

    <!-- Flash Alert -->
    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-xs mb-4" role="alert">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-info' ?> fs-5"></i>
            <div><?= htmlspecialchars($msg) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header Actions Bar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h4 class="fw-bold font-heading text-foreground mb-1">
                <i class="fa-solid fa-award text-primary me-2"></i><?= is_rtl() ? 'تقييم كفاءة الكوادر التعليمية والإدارية' : 'Faculty & Staff Performance Appraisals' ?>
            </h4>
            <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'قياس معايير الجودة، الانضباط الوظيفي، تقييم الحصص الدراسية، وتوصيات الترقي والمكافآت.' : 'Track evaluation rubrics, punctuality, pedagogical delivery, and promotion recommendations.' ?></p>
        </div>
        <div>
            <button type="button" class="btn btn-primary btn-sm rounded-lg px-3 py-2 fw-bold shadow-xs" data-bs-toggle="modal" data-bs-target="#newEvalModal">
                <i class="fa-solid fa-star me-1"></i> <?= is_rtl() ? 'تسجيل تقييم جديد' : 'New Performance Appraisal' ?>
            </button>
        </div>
    </div>

    <!-- Metrics Row -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'التقييمات المسجلة' : 'Total Appraisals' ?></span>
                    <div class="action-icon-box icon-box-primary" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-list-check"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-foreground"><?= $totalEvals ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'تقييم أداء رسمي' : 'Completed evaluations' ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'متوسط الدرجات العام' : 'Average KPI Score' ?></span>
                    <div class="action-icon-box icon-box-success" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-chart-line"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-success"><?= $avgScore ?>%</div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'معدل الكفاءة المؤسسية' : 'Institutional index' ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'المتميزون (امتياز)' : 'Distinguished (90%+)' ?></span>
                    <div class="action-icon-box icon-box-warning" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-trophy"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-warning"><?= $topPerformers ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'مرشحون للمكافآت والترقية' : 'Eligible for incentive' ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الفترة النشطة' : 'Active Cycle' ?></span>
                    <div class="action-icon-box icon-box-purple" style="width: 2.25rem; height: 2.25rem; font-size: 1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                </div>
                <div class="fs-5 fw-bold font-heading text-primary text-truncate">2025/2026</div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي الأول' : 'Term 1 Cycle' ?></div>
            </div>
        </div>
    </div>

    <!-- Filters & Search Bar -->
    <div class="card border border-border rounded-xl p-3 bg-card shadow-sm mb-4">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-transparent border-border text-muted-foreground"><i class="fa-solid fa-search"></i></span>
                    <input type="text" name="q" class="form-control form-control-sm bg-background text-foreground border-border rounded-end" placeholder="<?= is_rtl() ? 'بحث باسم الموظف أو الكود أو الملاحظات...' : 'Search by staff name, code, or notes...' ?>" value="<?= htmlspecialchars($search_query) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="recom" class="form-select form-select-sm bg-background text-foreground border-border rounded-lg">
                    <option value=""><?= is_rtl() ? '-- جميع التوصيات --' : '-- All Recommendations --' ?></option>
                    <option value="Retain" <?= $filter_recom === 'Retain' ? 'selected' : '' ?>><?= is_rtl() ? 'تجديد واستمرار (Retain)' : 'Retain' ?></option>
                    <option value="Salary Increment" <?= $filter_recom === 'Salary Increment' ? 'selected' : '' ?>><?= is_rtl() ? 'علاوة دورية (Increment)' : 'Salary Increment' ?></option>
                    <option value="Promotion" <?= $filter_recom === 'Promotion' ? 'selected' : '' ?>><?= is_rtl() ? 'ترقية وظيفية (Promotion)' : 'Promotion' ?></option>
                    <option value="Needs Training" <?= $filter_recom === 'Needs Training' ? 'selected' : '' ?>><?= is_rtl() ? 'خطة تدريب (Training)' : 'Needs Training' ?></option>
                    <option value="Warning" <?= $filter_recom === 'Warning' ? 'selected' : '' ?>><?= is_rtl() ? 'إنذار لفت نظر (Warning)' : 'Warning' ?></option>
                </select>
            </div>
            <div class="col-md-2">
                <select name="period" class="form-select form-select-sm bg-background text-foreground border-border rounded-lg">
                    <option value=""><?= is_rtl() ? '-- جميع الفترات --' : '-- All Periods --' ?></option>
                    <?php foreach ($periods_list as $pl): ?>
                        <option value="<?= htmlspecialchars($pl) ?>" <?= $filter_period === $pl ? 'selected' : '' ?>><?= htmlspecialchars($pl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm flex-grow-1 rounded-lg fw-semibold">
                    <i class="fa-solid fa-filter me-1"></i> <?= is_rtl() ? 'تصفية' : 'Filter' ?>
                </button>
                <?php if (!empty($search_query) || !empty($filter_recom) || !empty($filter_period)): ?>
                    <a href="evaluations.php" class="btn btn-outline-border btn-sm rounded-lg" title="<?= is_rtl() ? 'إلغاء التصفية' : 'Reset' ?>"><i class="fa-solid fa-rotate-left"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Evaluations Roster Table -->
    <div class="card border border-border bg-card shadow-sm rounded-xl overflow-hidden">
        <div class="card-header bg-card py-3 px-4 border-bottom border-border d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <h6 class="fw-bold font-heading text-foreground mb-0">
                    <i class="fa-solid fa-file-signature me-2 text-primary"></i><?= is_rtl() ? 'سجل تقييمات الأداء الوظيفي' : 'Completed Performance Appraisals' ?>
                </h6>
                <span class="badge bg-secondary text-foreground border border-border rounded-pill px-2.5 py-1 small"><?= count($evaluations) ?></span>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-secondary small text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3"><?= is_rtl() ? 'الموظف' : 'Employee' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'الفترة' : 'Period' ?></th>
                        <th class="py-3 text-center"><?= is_rtl() ? 'الجودة' : 'Quality' ?></th>
                        <th class="py-3 text-center"><?= is_rtl() ? 'الانضباط' : 'Punctuality' ?></th>
                        <th class="py-3 text-center"><?= is_rtl() ? 'الإدارة' : 'Management' ?></th>
                        <th class="py-3 text-center"><?= is_rtl() ? 'التعاون' : 'Teamwork' ?></th>
                        <th class="py-3 text-center"><?= is_rtl() ? 'النتيجة الإجمالية' : 'Overall Score' ?></th>
                        <th class="py-3"><?= is_rtl() ? 'التوصية' : 'Recommendation' ?></th>
                        <th class="px-4 py-3"><?= is_rtl() ? 'المُقيِّم والتاريخ' : 'Evaluator & Date' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($evaluations)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted-foreground">
                                <i class="fa-solid fa-clipboard-check fs-2 mb-2 d-block opacity-50"></i>
                                <?= is_rtl() ? 'لا توجد تقييمات مسجلة تطابق معايير البحث. انقر على "تسجيل تقييم جديد".' : 'No evaluations found matching search criteria. Click "New Performance Appraisal" to record.' ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($evaluations as $ev): 
                            $score = floatval($ev['overall_score']);
                            $badgeClass = $score >= 85 ? 'bg-success-subtle text-success border border-success-subtle' : ($score >= 70 ? 'bg-primary-subtle text-primary border border-primary-subtle' : ($score >= 50 ? 'bg-warning-subtle text-warning border border-warning-subtle' : 'bg-danger-subtle text-danger border border-danger-subtle'));
                        ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="d-flex align-items-center gap-2.5">
                                        <div class="avatar-badge flex-shrink-0 bg-primary-subtle text-primary border border-primary-subtle rounded-circle d-flex align-items-center justify-content-center fw-bold" style="width: 2.25rem; height: 2.25rem; font-size: 0.85rem;">
                                            <?= mb_substr($ev['employee_name'], 0, 1) ?>
                                        </div>
                                        <div>
                                            <div class="fw-bold text-foreground small"><?= htmlspecialchars($ev['employee_name']) ?></div>
                                            <span class="text-muted-foreground font-mono" style="font-size: 0.72rem;"><?= htmlspecialchars($ev['employee_code'] ?: '#' . $ev['employee_id']) ?> · <?= htmlspecialchars($ev['job_title'] ?: $ev['category']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <span class="badge bg-secondary text-foreground border border-border small"><?= htmlspecialchars($ev['evaluation_period']) ?></span>
                                </td>
                                <td class="py-3 text-center">
                                    <span class="fw-bold text-foreground"><?= $ev['teaching_quality'] ?>/5</span>
                                </td>
                                <td class="py-3 text-center">
                                    <span class="fw-bold text-foreground"><?= $ev['punctuality'] ?>/5</span>
                                </td>
                                <td class="py-3 text-center">
                                    <span class="fw-bold text-foreground"><?= $ev['classroom_management'] ?>/5</span>
                                </td>
                                <td class="py-3 text-center">
                                    <span class="fw-bold text-foreground"><?= $ev['teamwork'] ?>/5</span>
                                </td>
                                <td class="py-3 text-center">
                                    <span class="badge <?= $badgeClass ?> px-2.5 py-1 rounded-pill fw-bold">
                                        <?= $score ?>%
                                    </span>
                                </td>
                                <td class="py-3">
                                    <span class="badge bg-secondary text-foreground border border-border px-2.5 py-1 rounded-pill small">
                                        <?= htmlspecialchars($ev['recommendation']) ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="small fw-semibold text-foreground"><?= htmlspecialchars($ev['evaluator_name'] ?? 'HR Admin') ?></div>
                                    <span class="text-muted-foreground font-mono" style="font-size: 0.72rem;"><?= htmlspecialchars($ev['evaluation_date']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: New Evaluation Form -->
<div class="modal fade" id="newEvalModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-card border border-border shadow-lg rounded-xl">
            <form method="POST">
                <input type="hidden" name="submit_evaluation" value="1">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground font-heading">
                        <i class="fa-solid fa-star text-primary me-2"></i><?= is_rtl() ? 'نموذج تقييم أداء الكادر' : 'Staff Appraisal Evaluation Form' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'الموظف محل التقييم' : 'Select Employee' ?> <span class="text-danger">*</span></label>
                            <select name="employee_id" class="form-select bg-background text-foreground border-border rounded-lg" required>
                                <option value=""><?= is_rtl() ? '-- اختر الموظف --' : '-- Select Employee --' ?></option>
                                <?php foreach ($active_employees as $ae): ?>
                                    <option value="<?= $ae['employee_id'] ?>"><?= htmlspecialchars($ae['full_name']) ?> (<?= htmlspecialchars($ae['job_title'] ?: $ae['category']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'فترة التقييم' : 'Appraisal Period' ?> <span class="text-danger">*</span></label>
                            <input type="text" name="evaluation_period" class="form-control bg-background text-foreground border-border rounded-lg" value="2025/2026 - Term 1" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'تاريخ التقييم' : 'Evaluation Date' ?> <span class="text-danger">*</span></label>
                            <input type="date" name="evaluation_date" class="form-control bg-background text-foreground border-border rounded-lg" value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>

                    <h6 class="fw-bold text-foreground border-bottom border-border pb-2 mb-3">
                        <i class="fa-solid fa-sliders text-primary me-2"></i><?= is_rtl() ? 'معايير التقييم والـ KPIs (مقياس 1 إلى 5 نجوم)' : 'Evaluation Rubric & KPI Scores (1 to 5 Scale)' ?>
                    </h6>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'جودة التدريس والمهام الوظيفية' : '1. Quality of Delivery & Work (1-5)' ?></label>
                            <select name="teaching_quality" class="form-select bg-background text-foreground border-border rounded-lg score-input" onchange="recalculateScore()">
                                <option value="5">5 - Excellent / ممتاز</option>
                                <option value="4">4 - Very Good / جيد جداً</option>
                                <option value="3" selected>3 - Satisfactory / مقبول</option>
                                <option value="2">2 - Needs Improvement / يحتاج تطوير</option>
                                <option value="1">1 - Unsatisfactory / ضعيف</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'الانضباط والالتزام بالمواعيد' : '2. Punctuality & Attendance (1-5)' ?></label>
                            <select name="punctuality" class="form-select bg-background text-foreground border-border rounded-lg score-input" onchange="recalculateScore()">
                                <option value="5">5 - Excellent / ممتاز</option>
                                <option value="4">4 - Very Good / جيد جداً</option>
                                <option value="3" selected>3 - Satisfactory / مقبول</option>
                                <option value="2">2 - Needs Improvement / يحتاج تطوير</option>
                                <option value="1">1 - Unsatisfactory / ضعيف</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'إدارة الصفوف والمهام التنظيمية' : '3. Classroom & Task Management (1-5)' ?></label>
                            <select name="classroom_management" class="form-select bg-background text-foreground border-border rounded-lg score-input" onchange="recalculateScore()">
                                <option value="5">5 - Excellent / ممتاز</option>
                                <option value="4">4 - Very Good / جيد جداً</option>
                                <option value="3" selected>3 - Satisfactory / مقبول</option>
                                <option value="2">2 - Needs Improvement / يحتاج تطوير</option>
                                <option value="1">1 - Unsatisfactory / ضعيف</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'التعاون وروح الفريق والاحترافية' : '4. Teamwork & Professional Conduct (1-5)' ?></label>
                            <select name="teamwork" class="form-select bg-background text-foreground border-border rounded-lg score-input" onchange="recalculateScore()">
                                <option value="5">5 - Excellent / ممتاز</option>
                                <option value="4">4 - Very Good / جيد جداً</option>
                                <option value="3" selected>3 - Satisfactory / مقبول</option>
                                <option value="2">2 - Needs Improvement / يحتاج تطوير</option>
                                <option value="1">1 - Unsatisfactory / ضعيف</option>
                            </select>
                        </div>
                    </div>

                    <div class="p-3 bg-secondary rounded-lg border border-border mb-3 d-flex justify-content-between align-items-center">
                        <span class="fw-bold small text-foreground"><?= is_rtl() ? 'النتيجة الإجمالية المحسوبة تلقائياً:' : 'Calculated Overall KPI Percentage:' ?></span>
                        <span class="fs-5 fw-bold font-heading text-primary" id="previewScore">60.0%</span>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'التوصية الإدارية' : 'Administrative Recommendation' ?></label>
                            <select name="recommendation" class="form-select bg-background text-foreground border-border rounded-lg">
                                <option value="Retain"><?= is_rtl() ? 'تجديد واستمرار (Retain)' : 'Retain in Position' ?></option>
                                <option value="Salary Increment"><?= is_rtl() ? 'مكافأة / علاوة دورية (Salary Increment)' : 'Salary Increment / Bonus' ?></option>
                                <option value="Promotion"><?= is_rtl() ? 'ترقية وظيفية (Promotion)' : 'Promotion to Senior Role' ?></option>
                                <option value="Needs Training"><?= is_rtl() ? 'خطة تدريب وتطوير (Needs Training)' : 'Developmental Training Program' ?></option>
                                <option value="Probation Extension"><?= is_rtl() ? 'تمديد التجربة (Probation Extension)' : 'Extend Probation Period' ?></option>
                                <option value="Warning"><?= is_rtl() ? 'إنذار لفت نظر (Warning)' : 'Formal Warning / Review' ?></option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-foreground small"><?= is_rtl() ? 'ملاحظات وأهداف التطوير' : 'Evaluator Remarks & Targets' ?></label>
                            <textarea name="comments" rows="2" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="<?= is_rtl() ? 'ملاحظات المقيّم، نقاط القوة، وأهداف الفصل القادم...' : 'Evaluator notes, specific strengths, and goals...' ?>"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top border-border bg-secondary/30 py-2.5 px-4">
                    <button type="button" class="btn btn-outline-border btn-sm px-3 rounded-lg" data-bs-dismiss="modal"><?= __('cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold rounded-lg shadow-xs"><?= is_rtl() ? 'اعتماد التقييم' : 'Save Appraisal' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function recalculateScore() {
    let sum = 0;
    document.querySelectorAll('.score-input').forEach(sel => {
        sum += parseInt(sel.value) || 0;
    });
    let pct = ((sum / 20) * 100).toFixed(1);
    document.getElementById('previewScore').innerText = pct + '%';
}
</script>
</body>
</html>
