<?php
/**
 * LMS Module - Learning Management & Classroom Hub
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Authentication guard: Any registered user (Admin, Teacher, Student, Parent)
Auth::requireLogin();

$user_role = Auth::role();
$user_id = Auth::id();
$full_name = Auth::fullName();

// Fetch summary metrics
$course_count = 0;
$material_count = 0;
$assignment_count = 0;
$announcement_count = 0;

try {
    $course_count = (int)$pdo->query("SELECT COUNT(*) FROM courses")->fetchColumn();
    $material_count = (int)$pdo->query("SELECT COUNT(*) FROM course_materials")->fetchColumn();
    $assignment_count = (int)$pdo->query("SELECT COUNT(*) FROM assignments")->fetchColumn();
    $announcement_count = (int)$pdo->query("SELECT COUNT(*) FROM announcements")->fetchColumn();
} catch (\Throwable $e) {
    // Graceful fallback if tables are being prepared
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'منصة التعلم الإلكتروني والفصول - LMS' : 'LMS & Learning Management Hub' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .admin-action-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-xl, 1rem);
            background: var(--card);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .admin-action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.08);
            color: inherit;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'إدارة التعلم والمقررات' : 'LMS & Learning Desk',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => [
        '<a href="courses.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-primary"></i> ' . (is_rtl() ? 'المناهج' : 'Courses') . '</a>',
        '<a href="materials.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-folder-open me-1 text-info"></i> ' . (is_rtl() ? 'المستودع' : 'Materials') . '</a>',
        '<a href="assignments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-signature me-1 text-success"></i> ' . (is_rtl() ? 'الواجبات' : 'Assignments') . '</a>',
        '<a href="discussions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-comments me-1 text-purple"></i> ' . (is_rtl() ? 'النقاشات' : 'Discussions') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-chalkboard-user text-primary me-2"></i><?= is_rtl() ? 'نظام إدارة التعلم والفصول الدراسية (LMS)' : 'Learning Management System & Classroom Hub' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'مقررات دراسية، مستودع موارد رقمية، تكليفات وواجبات، منتديات نقاش تفاعلية، ولوحات تحكم متخصصة.' : 'Course syllabi, digital resource repository, homework grading desk, interactive discussions, and multi-role portals.' ?>
            </p>
        </div>
        <div>
            <span class="badge bg-card border border-border text-foreground px-3 py-2 fs-6 shadow-sm rounded-xl">
                <i class="fa-solid fa-user-circle text-primary me-1"></i> <?= htmlspecialchars($full_name) ?> <span class="badge bg-primary-subtle text-primary ms-1"><?= ucfirst($user_role) ?></span>
            </span>
        </div>
    </div>

    <!-- Live Metrics Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $course_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'المقررات الأكاديمية' : 'Academic Courses' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-folder-open"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $material_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الموارد الرقمية' : 'Digital Assets' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-file-signature"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $assignment_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'التكليفات والواجبات' : 'Course Assignments' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-bullhorn"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $announcement_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'إعلانات وتعاميم' : 'Active Bulletins' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1: Curriculum, Courseware & Content -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-layer-group text-primary me-2"></i><?= is_rtl() ? 'المناهج والمقررات والمحتوى الرقمي' : 'Curriculum, Courseware & Digital Resources' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- Course Catalog & Syllabi -->
        <div class="col-md-4 col-sm-6">
            <a href="courses.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'دليل المقررات والمناهج' : 'Course Catalog & Syllabi' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'تخطيط الوحدات ونسب الإنجاز' : 'Curriculum Tree & Objectives' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'ربط المقررات بالفصول والسنوات، تفريع الوحدات والدروس، وتتبع نسب إنجاز الخطة الدراسية.' : 'Structure subjects into hierarchical units and lesson topics, track learning objectives and syllabus completion %.' ?></p>
            </a>
        </div>

        <!-- Digital Materials & Repository -->
        <div class="col-md-4 col-sm-6">
            <a href="materials.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'مستودع الموارد الرقمية' : 'Digital Resource Repository' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الملفات، الفيديوهات والروابط' : 'Multi-Format Content & Slides' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'مشاركة شرائح العرض، أوراق العمل، فيديوهات الشرح، مع جدولة مواعيد الإتاحة وأقفال الرؤية.' : 'Upload lecture slides, worksheets, video lessons, and external links with scheduled releases and visibility locks.' ?></p>
            </a>
        </div>

        <!-- Topic Discussion Boards -->
        <div class="col-md-4 col-sm-6">
            <a href="discussions.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-comments"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'منتديات النقاش التفاعلية' : 'Topic Discussion Boards' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'غرف النقاش الموجهة والأسئلة' : 'Peer Q&A & Moderated Forums' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'حوارات تفاعلية لكل موضوع دراسي، تثبيت الإجابات النموذجية، وإدارة مشاركات الطلاب.' : 'Engage students in peer debates per lesson topic with teacher moderation, pinned answers, and thread locks.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 2: Homework, Assessments & Grading -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-clipboard-check text-success me-2"></i><?= is_rtl() ? 'التكليفات والتقييم ورصد الدرجات' : 'Assignments, Grading & Class Streams' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- Assignment Review & Grading Desk -->
        <?php if (in_array($user_role, ['admin', 'school_admin', 'teacher'])): ?>
        <div class="col-md-4 col-sm-6">
            <a href="assignments.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-file-signature"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'منصة تصحيح الواجبات' : 'Assignment Grading Desk' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'معاينة الحلول والمزامنة مع درجات أعمال السنة' : 'Rubrics, Preview & CA Gradebook Sync' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'فحص الحلول المرفوعة في المتصفح، رصد الدرجات ومعايير التقييم، وتحديث درجات أعمال السنة مباشرة.' : 'In-line document preview, rubric scoring, late submission tags, and 1-click sync into Continuous Assessment gradebooks.' ?></p>
            </a>
        </div>
        <?php endif; ?>

        <!-- Announcements & Notices -->
        <div class="col-md-4 col-sm-6">
            <a href="announcements.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-bullhorn"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= __('notice_board') ?></h5>
                        <small class="text-muted-foreground"><?= __('notice_board_sub') ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'بث الإعلانات المدرسية وتنبيهات الفصول العاجلة مع دعم المرفقات الرسمية.' : 'Broadcast urgent notices, daily agenda circulars, and class stream updates with document attachments.' ?></p>
            </a>
        </div>

        <!-- Class Timetables -->
        <div class="col-md-4 col-sm-6">
            <a href="timetable.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-calendar-days"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= __('class_timetables') ?></h5>
                        <small class="text-muted-foreground"><?= __('class_timetables_sub') ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'الجداول الأسبوعية، توزيع الفصول والقاعات، وتوقيت الحصص الدراسية.' : 'Check weekly schedules, room allocations, lecture timeslots, and subject periods.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 3: Specialized Role Workspaces -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-user-gear text-purple me-2"></i><?= is_rtl() ? 'بوابات ومساحات العمل المخصصة' : 'Role-Based Workspaces & Portals' ?>
    </h5>
    <div class="row g-3">
        <!-- Daily Class Logs (For Teachers / Admins) -->
        <?php if ($user_role === 'admin' || $user_role === 'teacher'): ?>
        <div class="col-md-4 col-sm-6">
            <a href="teacher_daily_log.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-book-open-reader"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= __('daily_class_logs') ?></h5>
                        <small class="text-muted-foreground"><?= __('daily_logs_sub') ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تحضير ملخصات الدروس اليومية، وتوثيق موضوعات الشرح والواجبات المطلوبة.' : 'Prepare daily lesson notes, record attendance topics, and submit homework instructions.' ?></p>
            </a>
        </div>
        <?php endif; ?>

        <!-- Student Learning Portal -->
        <?php if ($user_role === 'admin' || $user_role === 'student'): ?>
        <div class="col-md-4 col-sm-6">
            <a href="student_portal.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-laptop-file"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= __('student_portal') ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'تسليم الواجبات والمواد التعليمية' : 'Submission Portal & Materials' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'بوابة تفاعلية للطلاب: تصفية الواجبات، تسليم الحلول الرقمية، وتصفح المناهج والملفات.' : 'Student workspace: filter homework by status, upload digital submissions, and access course resources.' ?></p>
            </a>
        </div>
        <?php endif; ?>

        <!-- Parent Portal -->
        <?php if ($user_role === 'admin' || $user_role === 'parent'): ?>
        <div class="col-md-4 col-sm-6">
            <a href="parent_portal.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-users-viewfinder"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= __('parent_portal') ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'متابعة الواجبات والأداء' : 'Homework Oversight Desk' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'متابعة واجبات الأبناء اليومية، مواعيد التسليم، حالة الحلول، والدرجات المحققة.' : 'Track children\'s daily homework logs, deadlines, submission statuses, and teacher remarks.' ?></p>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
