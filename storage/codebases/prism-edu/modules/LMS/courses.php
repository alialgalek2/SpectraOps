<?php
/**
 * modules/LMS/courses.php
 * LMS Module - Course Catalog, Syllabi & Curriculum Mapping Hub
 * (Core Umbrella Architecture)
 * 
 * Features:
 * 1. Academic Course Binding: Courses bound to academic year, term, class/grade, and subject.
 * 2. Enrolled Students roster count & authorized teacher mapping.
 * 3. Hierarchical Curriculum Tree: Term -> Unit/Module -> Topic/Lesson Plan.
 * 4. Learning Objectives, estimated periods, and dynamic syllabus completion tracking.
 * 5. Full Bilingual RTL/LTR Support & Unified System Theme.
 */

require_once __DIR__ . '/../../core/init.php';
require_once __DIR__ . '/../../licensing/license_helper.php';

$licCheck = checkSystemLicense($pdo);
if (!$licCheck['valid']) {
    die("<h3 style='color:red;'>System Access Restricted: " . $licCheck['reason'] . "</h3>");
}

Auth::requireLogin();

$user_id   = Auth::id();
$user_role = Auth::role();
$can_edit  = in_array($user_role, ['admin', 'school_admin', 'teacher']);

$msg = '';
$msg_type = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    try {
        // 1. Create Course
        if ($action === 'create_course') {
            $class_id      = (int)($_POST['class_id'] ?? 0);
            $subject_id    = (int)($_POST['subject_id'] ?? 0);
            $teacher_id    = ($user_role === 'teacher') ? $user_id : (int)($_POST['teacher_id'] ?? $user_id);
            $academic_year = trim($_POST['academic_year'] ?? '2025/2026');
            $term          = trim($_POST['term'] ?? 'Term 1');
            $course_code   = trim($_POST['course_code'] ?? '');
            $course_name   = trim($_POST['course_name'] ?? '');
            $overview      = trim($_POST['syllabus_overview'] ?? '');

            if ($class_id <= 0 || $subject_id <= 0 || empty($course_name)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى ملء جميع الحقول المطلوبة (الفصل، المادة، اسم المقرر).' : 'Please fill all required fields (Class, Subject, Course Name).');
            }

            $stmt = $pdo->prepare("
                INSERT INTO courses (class_id, subject_id, teacher_id, academic_year, term, course_code, course_name, syllabus_overview, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active')
            ");
            $stmt->execute([$class_id, $subject_id, $teacher_id, $academic_year, $term, $course_code, $course_name, $overview]);
            $new_course_id = $pdo->lastInsertId();

            $_SESSION['flash_msg'] = is_rtl() ? 'تم إنشاء المقرر الدراسي وربطه بالخطة الأكاديمية بنجاح!' : 'Course catalog record created successfully!';
            $_SESSION['flash_type'] = 'success';
            header("Location: courses.php?course_id=" . $new_course_id);
            exit();
        }

        // 2. Add Unit
        if ($action === 'add_unit') {
            $course_id    = (int)($_POST['course_id'] ?? 0);
            $unit_number  = (int)($_POST['unit_number'] ?? 1);
            $title        = trim($_POST['title'] ?? '');
            $description  = trim($_POST['description'] ?? '');
            $periods      = (int)($_POST['estimated_periods'] ?? 4);

            if ($course_id <= 0 || empty($title)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى إدخال عنوان الوحدة التعليمية.' : 'Please enter the unit title.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO curriculum_units (course_id, unit_number, title, description, estimated_periods, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$course_id, $unit_number, $title, $description, $periods, $unit_number]);

            $_SESSION['flash_msg'] = is_rtl() ? 'تمت إضافة الوحدة الدراسية بنجاح!' : 'Curriculum unit added successfully!';
            $_SESSION['flash_type'] = 'success';
            header("Location: courses.php?course_id=" . $course_id);
            exit();
        }

        // 3. Add Topic
        if ($action === 'add_topic') {
            $unit_id      = (int)($_POST['unit_id'] ?? 0);
            $course_id    = (int)($_POST['course_id'] ?? 0);
            $topic_number = (int)($_POST['topic_number'] ?? 1);
            $title        = trim($_POST['title'] ?? '');
            $objectives   = trim($_POST['learning_objectives'] ?? '');
            $periods      = (int)($_POST['estimated_periods'] ?? 2);

            if ($unit_id <= 0 || empty($title)) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى إدخال عنوان الموضوع/الدرس.' : 'Please enter the topic title.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO curriculum_topics (unit_id, topic_number, title, learning_objectives, estimated_periods, completion_status, sort_order)
                VALUES (?, ?, ?, ?, ?, 'Not Started', ?)
            ");
            $stmt->execute([$unit_id, $topic_number, $title, $objectives, $periods, $topic_number]);

            $_SESSION['flash_msg'] = is_rtl() ? 'تمت إضافة خطة الدرس/الموضوع للمنهج!' : 'Curriculum topic/lesson added successfully!';
            $_SESSION['flash_type'] = 'success';
            header("Location: courses.php?course_id=" . $course_id);
            exit();
        }

        // 4. Update Topic Status
        if ($action === 'update_topic_status') {
            $topic_id  = (int)($_POST['topic_id'] ?? 0);
            $course_id = (int)($_POST['course_id'] ?? 0);
            $status    = $_POST['status'] ?? 'Not Started';

            if (!in_array($status, ['Not Started', 'In Progress', 'Completed'])) {
                $status = 'Not Started';
            }

            $completed_at = ($status === 'Completed') ? date('Y-m-d H:i:s') : null;

            $stmt = $pdo->prepare("UPDATE curriculum_topics SET completion_status = ?, completed_at = ? WHERE topic_id = ?");
            $stmt->execute([$status, $completed_at, $topic_id]);

            $_SESSION['flash_msg'] = is_rtl() ? 'تم تحديث حالة إنجاز الدرس!' : 'Topic completion status updated!';
            $_SESSION['flash_type'] = 'success';
            header("Location: courses.php?course_id=" . $course_id);
            exit();
        }

    } catch (Exception $e) {
        $msg = $e->getMessage();
        $msg_type = 'danger';
    }
}

if (!empty($_SESSION['flash_msg'])) {
    $msg = $_SESSION['flash_msg'];
    $msg_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Filters & Course Selection
$filter_year = $_GET['year'] ?? '2025/2026';
$filter_term = $_GET['term'] ?? 'Term 1';
$filter_class = (int)($_GET['class_id'] ?? 0);

// Fetch All Accessible Courses
$courses_sql = "
    SELECT c.*, cl.class_name, cl.grade_level, s.subject_name, s.subject_code, u.full_name AS teacher_name,
           (SELECT COUNT(*) FROM students st WHERE st.class_id = c.class_id) AS enrolled_students_count,
           (SELECT COUNT(*) FROM curriculum_units cu WHERE cu.course_id = c.course_id) AS total_units_count
    FROM courses c
    JOIN classes cl ON c.class_id = cl.class_id
    JOIN subjects s ON c.subject_id = s.subject_id
    LEFT JOIN users u ON c.teacher_id = u.user_id
    WHERE 1=1
";
$courses_params = [];

if ($user_role === 'teacher') {
    $courses_sql .= " AND (c.teacher_id = ? OR c.class_id IN (SELECT class_id FROM teacher_allocations WHERE teacher_user_id = ?))";
    $courses_params[] = $user_id;
    $courses_params[] = $user_id;
} elseif ($user_role === 'student') {
    $courses_sql .= " AND c.class_id IN (SELECT class_id FROM students WHERE user_id = ?)";
    $courses_params[] = $user_id;
}

if ($filter_class > 0) {
    $courses_sql .= " AND c.class_id = ?";
    $courses_params[] = $filter_class;
}

$courses_sql .= " ORDER BY c.created_at DESC";
$courses_stmt = $pdo->prepare($courses_sql);
$courses_stmt->execute($courses_params);
$courses = $courses_stmt->fetchAll();

// Selected Course
$selected_course_id = (int)($_GET['course_id'] ?? ($courses[0]['course_id'] ?? 0));
$current_course = null;

if ($selected_course_id > 0) {
    foreach ($courses as $crs) {
        if ($crs['course_id'] == $selected_course_id) {
            $current_course = $crs;
            break;
        }
    }
    if (!$current_course) {
        $single_stmt = $pdo->prepare("
            SELECT c.*, cl.class_name, cl.grade_level, s.subject_name, s.subject_code, u.full_name AS teacher_name,
                   (SELECT COUNT(*) FROM students st WHERE st.class_id = c.class_id) AS enrolled_students_count
            FROM courses c
            JOIN classes cl ON c.class_id = cl.class_id
            JOIN subjects s ON c.subject_id = s.subject_id
            LEFT JOIN users u ON c.teacher_id = u.user_id
            WHERE c.course_id = ?
        ");
        $single_stmt->execute([$selected_course_id]);
        $current_course = $single_stmt->fetch();
    }
}

// Fetch Units & Topics for Selected Course
$units = [];
$total_topics = 0;
$completed_topics = 0;
$in_progress_topics = 0;
$total_estimated_periods = 0;

if ($current_course) {
    $units_stmt = $pdo->prepare("SELECT * FROM curriculum_units WHERE course_id = ? ORDER BY sort_order ASC, unit_number ASC");
    $units_stmt->execute([$current_course['course_id']]);
    $units = $units_stmt->fetchAll();

    foreach ($units as &$u) {
        $top_stmt = $pdo->prepare("SELECT * FROM curriculum_topics WHERE unit_id = ? ORDER BY sort_order ASC, topic_number ASC");
        $top_stmt->execute([$u['unit_id']]);
        $u['topics'] = $top_stmt->fetchAll();

        foreach ($u['topics'] as $t) {
            $total_topics++;
            $total_estimated_periods += (int)$t['estimated_periods'];
            if ($t['completion_status'] === 'Completed') $completed_topics++;
            if ($t['completion_status'] === 'In Progress') $in_progress_topics++;
        }
    }
    unset($u);
}

$completion_percent = ($total_topics > 0) ? round(($completed_topics / $total_topics) * 100) : 0;

// Supporting dropdown data
$classes_list = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name ASC")->fetchAll();
$subjects_list = $pdo->query("SELECT subject_id, subject_name, subject_code FROM subjects ORDER BY subject_name ASC")->fetchAll();
$teachers_list = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'دليل المقررات والمناهج الدراسية - LMS' : 'Course Catalog & Curriculum Mapping - LMS' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .topic-item {
            border-left: 3px solid var(--border);
            transition: all 0.2s ease;
        }
        [dir="rtl"] .topic-item {
            border-left: none;
            border-right: 3px solid var(--border);
        }
        .topic-item.status-Completed {
            border-color: #10b981 !important;
        }
        .topic-item.status-InProgress {
            border-color: #f59e0b !important;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'المناهج والمقررات' : 'Course Catalog & Syllabi',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="materials.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-folder-open me-1 text-primary"></i> ' . (is_rtl() ? 'المستودع الرقمي' : 'Digital Materials') . '</a>',
        '<a href="assignments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-signature me-1 text-success"></i> ' . (is_rtl() ? 'الواجبات' : 'Assignments') . '</a>',
        '<a href="discussions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-comments me-1 text-purple"></i> ' . (is_rtl() ? 'النقاشات' : 'Discussions') . '</a>'
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
                <i class="fa-solid fa-graduation-cap text-primary me-2"></i><?= is_rtl() ? 'دليل المقررات وتخطيط المناهج الأكاديمية' : 'Course Catalog & Curriculum Mapping' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'هيكلة المناهج الدراسية، الوحدات والدروس، أهداف التعلم ونسب إنجاز الخطة التعليمية.' : 'Academic course bindings, unit/topic breakdown, learning objectives, and syllabus completion tracking.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-primary rounded-xl shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#newCourseModal">
                    <i class="fa-solid fa-plus-circle me-1"></i> <?= is_rtl() ? 'إضافة مقرر جديد' : 'New Academic Course' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overview Metrics Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-book-bookmark"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($courses) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'المقررات المفعلة' : 'Active Courses' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-layer-group"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($units) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الوحدات التعليمية' : 'Curriculum Units' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $completed_topics ?> / <?= $total_topics ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الدروس المنجزة' : 'Topics Completed' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $completion_percent ?>%</div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'نسبة إنجاز المنهج' : 'Syllabus Progress' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Workspace Layout -->
    <div class="row g-4">
        <!-- Sidebar: Course Navigator -->
        <div class="col-lg-3 col-md-4">
            <div class="card border border-border rounded-xl bg-card shadow-sm mb-4">
                <div class="card-header bg-card border-bottom border-border py-3">
                    <h6 class="fw-bold mb-0 text-foreground">
                        <i class="fa-solid fa-list-ul text-primary me-2"></i><?= is_rtl() ? 'قائمة المقررات الدراسية' : 'Active Course Offerings' ?>
                    </h6>
                </div>
                <div class="list-group list-group-flush rounded-bottom-xl">
                    <?php if (empty($courses)): ?>
                        <div class="p-3 text-center text-muted-foreground small">
                            <?= is_rtl() ? 'لا توجد مقررات مسجلة حالياً.' : 'No active courses registered.' ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($courses as $c_item): ?>
                            <?php $is_act = ($current_course && $current_course['course_id'] == $c_item['course_id']); ?>
                            <a href="courses.php?course_id=<?= $c_item['course_id'] ?>" 
                               class="list-group-item list-group-item-action d-flex justify-content-between align-items-start py-3 <?= $is_act ? 'active bg-primary-subtle text-primary border-primary-subtle' : 'text-foreground' ?>">
                                <div class="me-auto">
                                    <div class="fw-bold small"><?= htmlspecialchars($c_item['course_name']) ?></div>
                                    <div class="text-muted-foreground extra-small">
                                        <i class="fa-solid fa-chalkboard me-1"></i><?= htmlspecialchars($c_item['class_name']) ?> • <?= htmlspecialchars($c_item['term']) ?>
                                    </div>
                                </div>
                                <span class="badge bg-secondary text-muted-foreground rounded-pill extra-small">
                                    <?= (int)$c_item['total_units_count'] ?> <?= is_rtl() ? 'وحدة' : 'units' ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Main Workspace: Curriculum Tree & Course Details -->
        <div class="col-lg-9 col-md-8">
            <?php if (!$current_course): ?>
                <div class="card border border-border rounded-xl bg-card shadow-sm p-5 text-center">
                    <div class="action-icon-box icon-box-primary mx-auto mb-3" style="width: 60px; height: 60px; font-size: 1.5rem;">
                        <i class="fa-solid fa-book-open"></i>
                    </div>
                    <h5 class="fw-bold text-foreground mb-1"><?= is_rtl() ? 'اختر مقرراً دراسياً للاطلاع على خطة المنهج' : 'Select a course to view curriculum breakdown' ?></h5>
                    <p class="text-muted-foreground small mb-3"><?= is_rtl() ? 'يمكنك استعراض شجرة المنهج، أهداف التعلم، وإدارة محتوى الدروس.' : 'Browse unit breakdown, lesson plans, learning objectives, and resources.' ?></p>
                </div>
            <?php else: ?>
                <!-- Course Profile Banner -->
                <div class="card border border-border rounded-xl bg-card shadow-sm mb-4 p-4">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                        <div>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1">
                                    <i class="fa-solid fa-hashtag me-1"></i><?= htmlspecialchars($current_course['course_code'] ?? 'CRS') ?>
                                </span>
                                <span class="badge bg-secondary text-muted-foreground rounded-pill px-3 py-1">
                                    <?= htmlspecialchars($current_course['academic_year']) ?> • <?= htmlspecialchars($current_course['term']) ?>
                                </span>
                            </div>
                            <h4 class="fw-bold text-foreground mb-1"><?= htmlspecialchars($current_course['course_name']) ?></h4>
                            <p class="text-muted-foreground small mb-2"><?= htmlspecialchars($current_course['syllabus_overview'] ?? 'No syllabus overview provided.') ?></p>
                            <div class="d-flex align-items-center gap-3 text-muted-foreground small flex-wrap">
                                <span><i class="fa-solid fa-user-tie text-primary me-1"></i> <?= is_rtl() ? 'المعلم:' : 'Teacher:' ?> <strong><?= htmlspecialchars($current_course['teacher_name'] ?? 'Assigned Faculty') ?></strong></span>
                                <span><i class="fa-solid fa-users text-info me-1"></i> <?= is_rtl() ? 'الطلاب المقيدون:' : 'Enrolled Students:' ?> <strong><?= (int)$current_course['enrolled_students_count'] ?></strong></span>
                                <span><i class="fa-solid fa-clock text-warning me-1"></i> <?= is_rtl() ? 'إجمالي الحصص التقديرية:' : 'Total Periods:' ?> <strong><?= $total_estimated_periods ?></strong></span>
                            </div>
                        </div>
                        <div class="text-end">
                            <?php if ($can_edit): ?>
                                <button type="button" class="btn btn-outline-border btn-sm rounded-xl" data-bs-toggle="modal" data-bs-target="#addUnitModal">
                                    <i class="fa-solid fa-folder-plus text-primary me-1"></i> <?= is_rtl() ? 'إضافة وحدة جديدة' : 'Add Unit / Module' ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Progress Bar -->
                    <div class="mt-3 pt-3 border-top border-border">
                        <div class="d-flex justify-content-between align-items-center mb-1 small">
                            <span class="text-muted-foreground"><?= is_rtl() ? 'تقدم إنجاز مفردات المنهج' : 'Curriculum Syllabus Completion' ?></span>
                            <span class="fw-bold text-foreground"><?= $completion_percent ?>% (<?= $completed_topics ?> / <?= $total_topics ?> <?= is_rtl() ? 'درس' : 'topics' ?>)</span>
                        </div>
                        <div class="progress" style="height: 8px; border-radius: 6px;">
                            <div class="progress-bar bg-success" role="progressbar" style="width: <?= $completion_percent ?>%"></div>
                        </div>
                    </div>
                </div>

                <!-- Units & Topics Accordion -->
                <h5 class="fw-bold text-foreground mb-3 d-flex align-items-center justify-content-between">
                    <span><i class="fa-solid fa-sitemap text-primary me-2"></i><?= is_rtl() ? 'الهيكل الشجري للمنهج (الوحدات والدروس)' : 'Curriculum Structure (Units & Lesson Plans)' ?></span>
                </h5>

                <?php if (empty($units)): ?>
                    <div class="card border border-border rounded-xl bg-card shadow-sm p-4 text-center">
                        <p class="text-muted-foreground mb-2"><?= is_rtl() ? 'لم يتم إضافة وحدات دراسية لهذا المقرر بعد.' : 'No curriculum units created for this course yet.' ?></p>
                        <?php if ($can_edit): ?>
                            <button type="button" class="btn btn-primary btn-sm rounded-xl mx-auto" data-bs-toggle="modal" data-bs-target="#addUnitModal">
                                <i class="fa-solid fa-plus me-1"></i> <?= is_rtl() ? 'إضافة أول وحدة تعليمية' : 'Create First Unit' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="accordion" id="curriculumAccordion">
                        <?php foreach ($units as $u_idx => $unit): ?>
                            <div class="accordion-item border border-border rounded-xl bg-card shadow-sm mb-3 overflow-hidden">
                                <h2 class="accordion-header" id="headingUnit<?= $unit['unit_id'] ?>">
                                    <button class="accordion-button <?= $u_idx > 0 ? 'collapsed' : '' ?> bg-card text-foreground py-3" type="button" data-bs-toggle="collapse" data-bs-target="#collapseUnit<?= $unit['unit_id'] ?>">
                                        <div class="d-flex align-items-center justify-content-between w-100 me-3">
                                            <div>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill me-2">
                                                    <?= is_rtl() ? 'الوحدة' : 'Unit' ?> <?= (int)$unit['unit_number'] ?>
                                                </span>
                                                <strong class="text-foreground"><?= htmlspecialchars($unit['title']) ?></strong>
                                            </div>
                                            <div class="text-muted-foreground small">
                                                <i class="fa-solid fa-stopwatch me-1"></i><?= (int)$unit['estimated_periods'] ?> <?= is_rtl() ? 'حصص' : 'periods' ?>
                                                <span class="badge bg-secondary text-muted-foreground rounded-pill ms-2">
                                                    <?= count($unit['topics']) ?> <?= is_rtl() ? 'درس' : 'topics' ?>
                                                </span>
                                            </div>
                                        </div>
                                    </button>
                                </h2>
                                <div id="collapseUnit<?= $unit['unit_id'] ?>" class="accordion-collapse collapse <?= $u_idx === 0 ? 'show' : '' ?>" data-bs-parent="#curriculumAccordion">
                                    <div class="accordion-body bg-background pt-2 pb-3">
                                        <?php if (!empty($unit['description'])): ?>
                                            <p class="small text-muted-foreground mb-3"><?= htmlspecialchars($unit['description']) ?></p>
                                        <?php endif; ?>

                                        <!-- Topics List -->
                                        <div class="d-flex flex-column gap-2 mb-3">
                                            <?php if (empty($unit['topics'])): ?>
                                                <div class="p-3 bg-card border border-border rounded-lg text-center text-muted-foreground small">
                                                    <?= is_rtl() ? 'لا توجد دروس أو موضوعات مضافة لهذه الوحدة.' : 'No lesson topics mapped in this unit.' ?>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($unit['topics'] as $topic): ?>
                                                    <?php 
                                                        $st = $topic['completion_status'];
                                                        $badge_class = 'bg-secondary text-muted-foreground';
                                                        if ($st === 'Completed') $badge_class = 'bg-success-subtle text-success border border-success-subtle';
                                                        if ($st === 'In Progress') $badge_class = 'bg-warning-subtle text-warning border border-warning-subtle';
                                                    ?>
                                                    <div class="topic-item status-<?= str_replace(' ', '', $st) ?> bg-card p-3 rounded-lg border border-border d-flex justify-content-between align-items-start flex-wrap gap-2">
                                                        <div style="max-width: 75%;">
                                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                                <span class="fw-bold text-foreground small"><?= is_rtl() ? 'درس' : 'Lesson' ?> <?= (int)$unit['unit_number'] ?>.<?= (int)$topic['topic_number'] ?>: <?= htmlspecialchars($topic['title']) ?></span>
                                                                <span class="badge <?= $badge_class ?> rounded-pill extra-small"><?= $st ?></span>
                                                            </div>
                                                            <?php if (!empty($topic['learning_objectives'])): ?>
                                                                <p class="extra-small text-muted-foreground mb-1">
                                                                    <strong><i class="fa-solid fa-bullseye text-danger me-1"></i><?= is_rtl() ? 'أهداف التعلم:' : 'Objectives:' ?></strong> 
                                                                    <?= htmlspecialchars($topic['learning_objectives']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <div class="extra-small text-muted-foreground">
                                                                <i class="fa-regular fa-clock me-1"></i> <?= (int)$topic['estimated_periods'] ?> <?= is_rtl() ? 'حصص مقررة' : 'allocated periods' ?>
                                                                <?php if ($topic['completed_at']): ?>
                                                                    • <i class="fa-solid fa-calendar-check text-success me-1"></i> <?= date('Y-m-d', strtotime($topic['completed_at'])) ?>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>

                                                        <!-- Teacher Topic Controls -->
                                                        <?php if ($can_edit): ?>
                                                            <div class="d-flex align-items-center gap-1">
                                                                <form method="POST" action="" class="d-inline">
                                                                    <input type="hidden" name="action" value="update_topic_status">
                                                                    <input type="hidden" name="course_id" value="<?= $current_course['course_id'] ?>">
                                                                    <input type="hidden" name="topic_id" value="<?= $topic['topic_id'] ?>">
                                                                    <select name="status" class="form-select form-select-sm bg-background border-border rounded-lg extra-small py-1" onchange="this.form.submit()">
                                                                        <option value="Not Started" <?= $st === 'Not Started' ? 'selected' : '' ?>><?= is_rtl() ? 'لم يبدأ' : 'Not Started' ?></option>
                                                                        <option value="In Progress" <?= $st === 'In Progress' ? 'selected' : '' ?>><?= is_rtl() ? 'قيد الشرح' : 'In Progress' ?></option>
                                                                        <option value="Completed" <?= $st === 'Completed' ? 'selected' : '' ?>><?= is_rtl() ? 'مكتمل' : 'Completed' ?></option>
                                                                    </select>
                                                                </form>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($can_edit): ?>
                                            <button type="button" class="btn btn-outline-border btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#addTopicModal" onclick="document.getElementById('modal_unit_id').value = '<?= $unit['unit_id'] ?>'">
                                                <i class="fa-solid fa-plus-circle text-primary me-1"></i> <?= is_rtl() ? 'إضافة درس لهذه الوحدة' : 'Add Topic to this Unit' ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal: New Course -->
<?php if ($can_edit): ?>
<div class="modal fade" id="newCourseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_course">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground">
                        <i class="fa-solid fa-graduation-cap text-primary me-2"></i><?= is_rtl() ? 'إنشاء مقرر دراسي جديد' : 'New Academic Course' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم المقرر / المادة:' : 'Course Name:' ?></label>
                        <input type="text" name="course_name" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="E.g., Grade 8 - Mathematics" required>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي:' : 'Class Section:' ?></label>
                            <select name="class_id" class="form-select bg-background text-foreground border-border rounded-lg" required>
                                <?php foreach ($classes_list as $cls): ?>
                                    <option value="<?= $cls['class_id'] ?>"><?= htmlspecialchars($cls['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'المادة الأكاديمية:' : 'Subject:' ?></label>
                            <select name="subject_id" class="form-select bg-background text-foreground border-border rounded-lg" required>
                                <?php foreach ($subjects_list as $sb): ?>
                                    <option value="<?= $sb['subject_id'] ?>"><?= htmlspecialchars($sb['subject_name']) ?> (<?= htmlspecialchars($sb['subject_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'العام الدراسي:' : 'Academic Year:' ?></label>
                            <input type="text" name="academic_year" class="form-control bg-background text-foreground border-border rounded-lg" value="2025/2026" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الفصل / الترم:' : 'Term:' ?></label>
                            <select name="term" class="form-select bg-background text-foreground border-border rounded-lg">
                                <option value="Term 1">Term 1</option>
                                <option value="Term 2">Term 2</option>
                                <option value="Term 3">Term 3</option>
                            </select>
                        </div>
                    </div>
                    <?php if ($user_role === 'admin' || $user_role === 'school_admin'): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'المعلم المسؤول:' : 'Assigned Teacher:' ?></label>
                            <select name="teacher_id" class="form-select bg-background text-foreground border-border rounded-lg">
                                <?php foreach ($teachers_list as $tc): ?>
                                    <option value="<?= $tc['user_id'] ?>"><?= htmlspecialchars($tc['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'رمز المقرر:' : 'Course Code:' ?></label>
                        <input type="text" name="course_code" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="e.g. MATH-8-T1">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'نظرة عامة على الخطة الدراسية:' : 'Syllabus Overview:' ?></label>
                        <textarea name="syllabus_overview" rows="3" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="Short description of core learning modules..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-border py-2">
                    <button type="button" class="btn btn-secondary rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-lg px-3"><?= is_rtl() ? 'حفظ المقرر' : 'Save Course' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add Unit -->
<div class="modal fade" id="addUnitModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_unit">
                <input type="hidden" name="course_id" value="<?= $current_course ? $current_course['course_id'] : 0 ?>">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground">
                        <i class="fa-solid fa-folder-plus text-primary me-2"></i><?= is_rtl() ? 'إضافة وحدة دراسية جديدة' : 'Add Curriculum Unit' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'رقم الوحدة:' : 'Unit No:' ?></label>
                            <input type="number" name="unit_number" class="form-control bg-background text-foreground border-border rounded-lg" value="<?= count($units) + 1 ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'عنوان الوحدة:' : 'Unit Title:' ?></label>
                            <input type="text" name="title" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="e.g. Unit 3: Quadratic Functions" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الحصص التقديرية:' : 'Estimated Periods:' ?></label>
                        <input type="number" name="estimated_periods" class="form-control bg-background text-foreground border-border rounded-lg" value="6" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'وصف الوحدة:' : 'Unit Description:' ?></label>
                        <textarea name="description" rows="2" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="Summary of competencies covered..."></textarea>
                    </div>
                </div>
                <div class="modal-footer border-top border-border py-2">
                    <button type="button" class="btn btn-secondary rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-lg px-3"><?= is_rtl() ? 'إضافة الوحدة' : 'Add Unit' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add Topic -->
<div class="modal fade" id="addTopicModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <form method="POST" action="">
                <input type="hidden" name="action" value="add_topic">
                <input type="hidden" name="course_id" value="<?= $current_course ? $current_course['course_id'] : 0 ?>">
                <input type="hidden" name="unit_id" id="modal_unit_id" value="0">
                <div class="modal-header border-bottom border-border py-3">
                    <h5 class="modal-title fw-bold text-foreground">
                        <i class="fa-solid fa-file-circle-plus text-primary me-2"></i><?= is_rtl() ? 'إضافة درس / موضوع جديد' : 'Add Lesson / Topic' ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-2 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'رقم الدرس:' : 'Topic No:' ?></label>
                            <input type="number" name="topic_number" class="form-control bg-background text-foreground border-border rounded-lg" value="1" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'عنوان الدرس:' : 'Topic Title:' ?></label>
                            <input type="text" name="title" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="e.g. Lesson 3.1: Factoring Polynomials" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'أهداف التعلم المتوقعة:' : 'Learning Objectives:' ?></label>
                        <textarea name="learning_objectives" rows="2" class="form-control bg-background text-foreground border-border rounded-lg" placeholder="Describe the competencies students will demonstrate..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الحصص المقررة (Periods):' : 'Estimated Periods:' ?></label>
                        <input type="number" name="estimated_periods" class="form-control bg-background text-foreground border-border rounded-lg" value="2" required>
                    </div>
                </div>
                <div class="modal-footer border-top border-border py-2">
                    <button type="button" class="btn btn-secondary rounded-lg" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-lg px-3"><?= is_rtl() ? 'إضافة الدرس' : 'Save Topic' ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
