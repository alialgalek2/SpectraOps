<?php
/**
 * Grading Module - Exams, Grading & Official Reports Central Hub
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Authentication Guard: Any registered user role
Auth::requireRole(['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance']);

$user_role = Auth::role();
$user_id = Auth::id();
$full_name = Auth::fullName();
$is_staff = in_array($user_role, ['admin', 'school_admin', 'teacher', 'finance']);

// Pull live summary metrics
$series_count = 0;
$session_count = 0;
$grade_count = 0;
$student_count = 0;

try {
    $series_count = (int)$pdo->query("SELECT COUNT(*) FROM exam_series")->fetchColumn();
    $session_count = (int)$pdo->query("SELECT COUNT(*) FROM exam_timetables")->fetchColumn();
    $grade_count = (int)$pdo->query("SELECT COUNT(*) FROM grades WHERE total_score IS NOT NULL")->fetchColumn();
    $student_count = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0")->fetchColumn();
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'مركز الامتحانات ورصد الدرجات والتقارير' : 'Exams, Grading & Reports Hub' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .grading-action-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-xl, 1rem);
            background: var(--card);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .grading-action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.08);
            color: inherit;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'مركز الامتحانات والدرجات' : 'Exams & Grading Hub',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => [
        '<a href="policies.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sliders me-1 text-primary"></i> ' . (is_rtl() ? 'السياسات' : 'Policies') . '</a>',
        '<a href="scheduling.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-calendar-check me-1 text-info"></i> ' . (is_rtl() ? 'الجدولة' : 'Scheduling') . '</a>',
        '<a href="matrix.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-table-cells me-1 text-success"></i> ' . (is_rtl() ? 'شبكة الرصد' : 'Matrix') . '</a>',
        '<a href="analytics.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-chart-line me-1 text-purple"></i> ' . (is_rtl() ? 'التحليلات' : 'Analytics') . '</a>',
        '<a href="report_cards.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-id-card-clip me-1 text-warning"></i> ' . (is_rtl() ? 'الشهادات' : 'Report Cards') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-graduation-cap text-danger me-2"></i><?= is_rtl() ? 'نظام الامتحانات ورصد الدرجات والتقارير الرسمية' : 'Examinations, Grading & Official Reports Hub' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'إدارة مواسم الامتحانات، أوزان التقييم، جداول القاعات والمراقبة، شبكة رصد الدرجات، التحليلات الإحصائية والشهادات المؤمنة.' : 'Exam series management, weightage policies, hall invigilation, 2D mark entry matrix, statistical rankings, and secured report cards.' ?>
            </p>
        </div>
        <div>
            <span class="badge bg-card border border-border text-foreground px-3 py-2 fs-6 shadow-sm rounded-xl">
                <i class="fa-solid fa-user-circle text-primary me-1"></i> <?= htmlspecialchars($full_name) ?> <span class="badge bg-danger-subtle text-danger ms-1"><?= ucfirst($user_role) ?></span>
            </span>
        </div>
    </div>

    <!-- Live Metrics Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-calendar-days"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $series_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'سلاسل الامتحانات' : 'Exam Series Events' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-door-open"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $session_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الجلسات والقاعات' : 'Scheduled Sessions' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-square-poll-horizontal"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $grade_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الدرجات المعتمدة' : 'Compiled Marks' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-id-card-clip"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $student_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الشهادات المؤهلة' : 'Eligible Candidates' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1: Examination Setup & Timetabling -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-sliders text-primary me-2"></i><?= is_rtl() ? '1. إعداد الامتحانات، معادلات الأوزان والجدولة' : '1. Examination Setup, Weightage & Hall Scheduling' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- Policies & Exam Series -->
        <div class="col-md-6 col-lg-6">
            <a href="policies.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'تهيئة سلاسل الاختبارات والأوزان والسلالم' : 'Exam Series, Weightage & Policy Setup' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'معادلة النتيجة وسلالم GPA والتقديرات' : 'Weightage Formulas, Scales & Boundaries' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'ربط مواسم الامتحانات بالعام والفصل، ضبط معادلة الأوزان (أعمال سنة 20% + نصفي 30% + نهائي 50%)، وتخصيص سلالم التقدير والـ GPA.' : 'Configure exam series windows, define component weightage formulas, customize letter grade schemas (A* through F), and GPA 4.0/5.0 quality points.' ?></p>
            </a>
        </div>

        <!-- Timetabling, Seating & Invigilation -->
        <div class="col-md-6 col-lg-6">
            <a href="scheduling.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'جدولة الامتحانات وتوزيع المقاعد والمراقبة' : 'Timetabling, Seating & Hall Invigilation' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'توزيع القاعات، بطاقات الجلوس وسجلات التوقيع' : 'Hall Allocation, Desk Slips & Registers' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'توزيع جلسات الاختبارات على القاعات والمختبرات، تخصيص أرقام الجلوس عشوائياً أو حسب القيد، تعيين المراقبين مع حظر تعارض المادة، وطباعة بطاقات الجلوس.' : 'Map exam sessions to halls, distribute seating rosters (roll/alphabetical/anti-cheating random), assign invigilators with conflict checks, and print desk tags.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 2: Mark Entry & Moderation Workflow -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-table-cells text-success me-2"></i><?= is_rtl() ? '2. شبكة رصد الدرجات ودورة الاعتماد والمراجعة' : '2. Mark Entry Matrix & Multi-Tier Moderation' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- 2D Mark Entry Matrix -->
        <div class="col-md-4 col-sm-6">
            <a href="matrix.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-table-cells"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'شبكة رصد الدرجات المتقدمة' : '2D Mark Entry Matrix Grid' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'رصد المكونات، التحقق والتدقيق' : 'Component Scores & Range Validation' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'رصد فوري لدرجات أوراق الامتحان وأعمال السنة، فحص القيم المسموحة لحظياً، ودعم الحالات الخاصة (غياب AB، عذر ML، حرمان DQ).' : 'Enter raw marks by component (CA, Paper 1, Paper 2), real-time range bounds validation, and special condition codes (AB, EX, ML, DQ).' ?></p>
            </a>
        </div>

        <!-- 3-Tier Moderation & Mark Locking -->
        <div class="col-md-4 col-sm-6">
            <a href="matrix.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-danger me-3">
                        <i class="fa-solid fa-lock"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'دورة المراجعة والقفل الإداري' : '3-Tier Review & Mark Locking' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'مسودة -> مراجعة القسم -> اعتماد نهائي' : 'Draft -> Dept Review -> Locked' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'نظام الحوكمة ثلاثي المراحل: إدخال المسودة من المعلم، تدقيق المنحنيات من رئيس القسم، وقفل الشيت نهائياً بقرار الكنترول المدرسي.' : 'Multi-tier governance: preliminary draft entry, HOD statistical curve reviews, and permanent administrative lock against edits.' ?></p>
            </a>
        </div>

        <!-- Continuous Assessment / LMS Assignment Desk Link -->
        <div class="col-md-4 col-sm-6">
            <a href="../LMS/assignments.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-file-signature"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'مزامنة واجبات LMS مع درجات CA' : 'LMS Homework & CA Sync Desk' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'مزامنة درجات التكليفات بنقرة واحدة' : '1-Click Coursework Sync' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'استيراد ومزامنة درجات التكليفات المنزلية والواجبات المصححة في LMS مباشرة داخل وعاء أعمال السنة (CA).' : 'Directly aggregate turns-ins and continuous assessment assignments from LMS into the examination gradebook.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 3: Performance Computation, Rankings & Official Reports -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-id-card-clip text-warning me-2"></i><?= is_rtl() ? '3. الحوسبة التراكمية، التحليلات، والشهادات المؤمنة' : '3. Analytics, Rankings & Secured Report Cards' ?>
    </h5>
    <div class="row g-3">
        <!-- Analytics & Class Rankings Engine -->
        <div class="col-md-4 col-sm-6">
            <a href="analytics.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-chart-pie"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'محرك الحوسبة والترتيب الصفي' : 'Rankings & Performance Analytics' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الترتيب التنافسي، لوحة الشرف والإنذارات' : 'Dense Ranking, Mean, Std Dev & Honor Roll' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'حساب المعدلات التراكمية، الانحراف المعياري، تصنيف الطلاب حسب الترتيب الصفي، واكتشاف حالات الإنذار الأكاديمي ولوحة الشرف.' : 'Compute GPA distributions, cohort rankings (standard vs. dense), statistical standard deviation, honor rolls, and at-risk students.' ?></p>
            </a>
        </div>

        <!-- Official Report Cards & Financial Guard -->
        <div class="col-md-4 col-sm-6">
            <a href="report_cards.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-id-card-clip"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'الشهادات وبطاقات الدرجات المؤمنة' : 'Official Report Cards & Security' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'حجب مالي، رمز QR وبصمة مشفرة' : 'Financial Guard, QR Verification & Hash' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'توليد كشوف الدرجات الرسمية، حجب الشهادات تلقائياً في حال وجود متأخرات مالية، وتضمين رمز QR وبصمة SHA-256 للتحقق.' : 'Generate official term grade cards, enforce financial clearance locks for unpaid tuition, and embed QR verification codes.' ?></p>
            </a>
        </div>

        <!-- Cumulative Transcripts -->
        <div class="col-md-4 col-sm-6">
            <a href="transcript.php" class="card grading-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-scroll"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'السجل الأكاديمي التراكمي الموحد' : 'Unified Academic Transcripts' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'سجل الدرجات المتعدد الفصول للطباعة A4' : 'Multi-Term Longitudinal Ledger' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'استخراج السجل الأكاديمي التاريخي الشامل لجميع الفصول والسنوات السابقة مع المعدل التراكمي وتنسيق الطباعة المعتمد.' : 'Longitudinal multi-term academic ledgers tracking progress over years with cumulative GPA calculation.' ?></p>
            </a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
