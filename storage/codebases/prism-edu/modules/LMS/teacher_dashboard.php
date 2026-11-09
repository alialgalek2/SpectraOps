<?php
/**
 * LMS Module - Teacher Workspace & Classroom Hub
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Verify License
$licCheck = checkSystemLicense($pdo);
if (!$licCheck['valid']) {
    die("<h3 style='color:red;'>System Access Restricted: " . $licCheck['reason'] . "</h3>");
}

Auth::requireRole(['teacher', 'admin', 'school_admin']);

$teacher_id = $_SESSION['user_id'] ?? Auth::id();

// Fetch Summary Stats
$class_count   = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
$student_count = (int)$pdo->query("SELECT COUNT(*) FROM students")->fetchColumn();

$stmt_logs = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE teacher_id = ?");
$stmt_logs->execute([$teacher_id]);
$logs_count = (int)$stmt_logs->fetchColumn();

// Fetch Pending Assignment Submissions Count
$stmt_pending_asn = $pdo->prepare("
    SELECT COUNT(*) 
    FROM assignment_submissions subm
    JOIN assignments a ON subm.assignment_id = a.assignment_id
    WHERE (a.teacher_id = ? OR ? = 1) AND subm.review_status = 'Pending'
");
$stmt_pending_asn->execute([$teacher_id, (Auth::hasRole('admin') || Auth::hasRole('school_admin')) ? 1 : 0]);
$pending_asn_count = (int)$stmt_pending_asn->fetchColumn();

// Fetch Recent Logs Activity
$stmt_recent = $pdo->prepare("
    SELECT dl.*, c.class_name, s.subject_name 
    FROM daily_logs dl
    JOIN classes c ON dl.class_id = c.class_id
    JOIN subjects s ON dl.subject_id = s.subject_id
    WHERE dl.teacher_id = ?
    ORDER BY dl.created_at DESC LIMIT 3
");
$stmt_recent->execute([$teacher_id]);
$recent_activity = $stmt_recent->fetchAll();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'لوحة تحكم المعلم - نظام إدارة التعلم' : 'Teacher Workspace Dashboard - LMS Hub' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'لوحة المعلم والأكاديميا' : 'Teacher LMS Hub',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="assignments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-signature me-1 text-primary"></i> ' . (is_rtl() ? 'تصحيح الواجبات' : 'Grade Assignments') . ($pending_asn_count > 0 ? ' <span class="badge bg-warning text-dark ms-1">' . $pending_asn_count . '</span>' : '') . '</a>',
        '<a href="teacher_daily_log.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-book-journal-whills me-1 text-primary"></i> ' . (is_rtl() ? 'التحضير اليومي' : 'Daily Logs') . '</a>'
    ]
]) ?>

<div class="container py-4">
    <!-- Welcome Header -->
    <div class="p-4 mb-4 bg-white rounded-3 shadow-sm border-start border-4 border-primary">
        <h2 class="fw-bold mb-1"><?= is_rtl() ? 'مرحباً بك في مساحة عمل المعلم' : 'Welcome back, Academic Team' ?></h2>
        <p class="text-muted mb-0"><?= is_rtl() ? 'إدارة درجات الطلاب، إعداد الدروس والواجبات اليومية، ومتابعة الفصول الدراسية.' : 'Manage class grades, post daily lesson coverage, and evaluate student homework submissions.' ?></p>
    </div>

    <!-- Quick Stats Cards (Standard Clean Design) -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الفصول والشعب' : 'Assigned Classes' ?></span>
                    <div class="action-icon-box icon-box-primary" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-chalkboard-user"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $class_count ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-primary fw-semibold"><i class="fa-solid fa-school me-1"></i> Classrooms</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي الطلاب' : 'Total Enrolled Students' ?></span>
                    <div class="action-icon-box icon-box-success" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $student_count ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-success fw-semibold"><i class="fa-solid fa-user-graduate me-1"></i> Active Students</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'التحضيرات المنشورة' : 'Published Daily Logs' ?></span>
                    <div class="action-icon-box icon-box-info" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-book-open-reader"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $logs_count ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold"><i class="fa-solid fa-file-lines me-1"></i> Daily Journal</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Navigation Modules (Unified Action Cards) -->
    <div class="row g-4 mb-4">
        <!-- Assignment Review & Grading Desk -->
        <div class="col-lg-6 col-xl-3 col-md-6">
            <a href="assignments.php" class="card admin-action-card p-4 h-100 d-flex flex-column justify-content-between text-decoration-none">
                <div>
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="action-icon-box icon-box-success me-3">
                            <i class="fa-solid fa-file-signature"></i>
                        </div>
                        <?php if ($pending_asn_count > 0): ?>
                            <span class="badge bg-warning text-dark px-2.5 py-1.5 fw-bold">
                                <i class="fa-solid fa-hourglass-half me-1"></i><?= $pending_asn_count ?> <?= is_rtl() ? 'بانتظار التصحيح' : 'Pending' ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <h5 class="fw-bold mb-2 text-foreground"><?= is_rtl() ? 'تصحيح وتقييم الواجبات' : 'Assignment Grading' ?></h5>
                    <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'مراجعة حلول وملفات الطلاب المرفوعة، رصد الدرجات، كتابة الملاحظات التوجيهية، وإدارة التكليفات.' : 'Review student homework submissions, grade files, assign marks & feedback, and create assignments.' ?></p>
                </div>
                <div class="mt-3 pt-3 border-top border-border d-flex align-items-center justify-content-between text-success fw-semibold small">
                    <span><?= is_rtl() ? 'فتح بوابة تصحيح الواجبات' : 'Open Grading Desk' ?></span>
                    <i class="fa-solid fa-arrow-<?= is_rtl() ? 'left' : 'right' ?>"></i>
                </div>
            </a>
        </div>

        <!-- 40/60 Assessment Grid -->
        <div class="col-lg-6 col-xl-3 col-md-6">
            <a href="../Grading/teacher_grades.php" class="card admin-action-card p-4 h-100 d-flex flex-column justify-content-between text-decoration-none">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-primary me-3">
                            <i class="fa-solid fa-chart-column"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2 text-foreground"><?= is_rtl() ? 'رصد الدرجات (40/60)' : '40/60 Assessment Grid' ?></h5>
                    <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'إدخال أعمال السنة (40) والاختبار النهائي (60) مع الحساب التلقائي للمجموع والتقديرات.' : 'Batch entry interface for Continuous Assessment (40%) and Final Exam (60%) scores with auto totals.' ?></p>
                </div>
                <div class="mt-3 pt-3 border-top border-border d-flex align-items-center justify-content-between text-primary fw-semibold small">
                    <span><?= is_rtl() ? 'فتح كشف الدرجات' : 'Open Grading Sheet' ?></span>
                    <i class="fa-solid fa-arrow-<?= is_rtl() ? 'left' : 'right' ?>"></i>
                </div>
            </a>
        </div>

        <!-- Daily Lesson & Homework Log -->
        <div class="col-lg-6 col-xl-3 col-md-6">
            <a href="teacher_daily_log.php" class="card admin-action-card p-4 h-100 d-flex flex-column justify-content-between text-decoration-none">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-warning me-3">
                            <i class="fa-solid fa-book-bookmark"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2 text-foreground"><?= is_rtl() ? 'دفتر التحضير والواجبات' : 'Daily Lesson Feed' ?></h5>
                    <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'نشر ملخصات الدروس، الموضوعات المشروحة، والواجبات المنزلية مع تواريخ التسليم.' : 'Publish covered daily topics, lesson summaries, homework assignments, and target due dates.' ?></p>
                </div>
                <div class="mt-3 pt-3 border-top border-border d-flex align-items-center justify-content-between text-warning fw-semibold small">
                    <span><?= is_rtl() ? 'إدارة السجلات اليومية' : 'Manage Daily Logs' ?></span>
                    <i class="fa-solid fa-arrow-<?= is_rtl() ? 'left' : 'right' ?>"></i>
                </div>
            </a>
        </div>

        <!-- Class Timetables & Attendance -->
        <div class="col-lg-6 col-xl-3 col-md-6">
            <a href="attendance.php" class="card admin-action-card p-4 h-100 d-flex flex-column justify-content-between text-decoration-none">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-info me-3">
                            <i class="fa-solid fa-clipboard-user"></i>
                        </div>
                    </div>
                    <h5 class="fw-bold mb-2 text-foreground"><?= is_rtl() ? 'الحضور والجدول الدراسي' : 'Attendance & Schedules' ?></h5>
                    <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تسجيل ومتابعة حضور وغياب الطلاب اليومي، والاطلاع على جدول الحصص الأسبوعي.' : 'Record and monitor student roll-call, verify daily absence records, and check room timetables.' ?></p>
                </div>
                <div class="mt-3 pt-3 border-top border-border d-flex align-items-center justify-content-between text-info fw-semibold small">
                    <span><?= is_rtl() ? 'رصد الحضور اليومي' : 'Take Attendance' ?></span>
                    <i class="fa-solid fa-arrow-<?= is_rtl() ? 'left' : 'right' ?>"></i>
                </div>
            </a>
        </div>
    </div>

    <!-- Recent Published Activity -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 fw-bold">
            <i class="fa-solid fa-clock-rotate-left text-primary me-2"></i><?= is_rtl() ? 'آخر السجلات الأكاديمية والدروس المنشورة' : 'Your Recent Lesson Logs' ?>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($recent_activity)): ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($recent_activity as $act): ?>
                        <div class="list-group-item p-3">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <span class="badge bg-secondary"><?= htmlspecialchars($act['class_name']) ?> (<?= htmlspecialchars($act['subject_name']) ?>)</span>
                                <small class="text-muted"><?= $act['log_date'] ?></small>
                            </div>
                            <h6 class="mb-1 fw-bold"><?= htmlspecialchars($act['lesson_topic']) ?></h6>
                            <p class="mb-0 text-muted small"><?= htmlspecialchars(substr($act['summary_content'], 0, 120)) ?>...</p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="p-4 text-center text-muted">
                    <p class="mb-0"><?= is_rtl() ? 'لا توجد سجلات منشورة حتى الآن. استخدم خيار دفتر التحضير أعلاه لنشر أول درس.' : 'No activity logged yet. Use the Daily Lesson Feed above to post your first log.' ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
