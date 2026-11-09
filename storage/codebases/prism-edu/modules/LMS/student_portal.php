<?php
require_once __DIR__ . '/../../core/init.php';

$licCheck = checkSystemLicense($pdo);
if (!$licCheck['valid']) {
    die("<h3 style='color:red;'>System Access Restricted: " . $licCheck['reason'] . "</h3>");
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    $_SESSION['user_id']   = 3;
    $_SESSION['role']      = 'student';
    $_SESSION['full_name'] = 'Demo Student';
}

$student_user_id = $_SESSION['user_id'];

$stmt_student = $pdo->prepare("
    SELECT s.student_id, s.admission_no, s.class_id, u.full_name, c.class_name
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE s.user_id = ?
");
$stmt_student->execute([$student_user_id]);
$student = $stmt_student->fetch();

$student_id = $student['student_id'] ?? 1;
$class_id   = $student['class_id'] ?? 1;

$msg = "";
$msg_type = "success";

// Handle File Upload & Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_homework') {
    $assignment_id   = intval($_POST['assignment_id']);
    $submission_text = trim($_POST['submission_text']);
    $file_path       = null;

    // Handle File Attachment
    if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../../uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_tmp  = $_FILES['assignment_file']['tmp_name'];
        $file_name = time() . '_' . preg_replace("/[^a-zA-Z0-9\._-]/", "_", basename($_FILES['assignment_file']['name']));
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($file_tmp, $target_file)) {
            $file_path = 'uploads/' . $file_name;
        } else {
            $msg = is_rtl() ? "فشل رفع الملف المرفق." : "Failed to upload attached document.";
            $msg_type = "danger";
        }
    }

    if ($msg_type !== "danger") {
        // Fetch assignment deadline configuration
        $asn_chk = $pdo->prepare("SELECT due_date, deadline_type, cut_off_date FROM assignments WHERE assignment_id = ?");
        $asn_chk->execute([$assignment_id]);
        $asn_meta = $asn_chk->fetch(PDO::FETCH_ASSOC);

        $is_late = 0;
        $delay_minutes = 0;
        $is_locked = false;

        if ($asn_meta) {
            $now_ts = time();
            $due_ts = strtotime($asn_meta['due_date'] . ' 23:59:59');
            $cutoff_ts = !empty($asn_meta['cut_off_date']) ? strtotime($asn_meta['cut_off_date'] . ' 23:59:59') : $due_ts;

            if ($asn_meta['deadline_type'] === 'hard' && $now_ts > $cutoff_ts) {
                $is_locked = true;
                $msg = is_rtl() ? "عفواً، تم إغلاق باب التسليم نهائياً لهذا الواجب لانتهاء الموعد الصارم المحدد." : "Submissions for this assignment are completely locked. The hard cutoff deadline has passed.";
                $msg_type = "danger";
            } elseif ($now_ts > $due_ts) {
                $is_late = 1;
                $delay_minutes = max(1, (int)round(($now_ts - $due_ts) / 60));
            }
        }

        if (!$is_locked) {
            $chk = $pdo->prepare("SELECT submission_id, file_path FROM assignment_submissions WHERE assignment_id = ? AND student_id = ?");
            $chk->execute([$assignment_id, $student_id]);
            $existing = $chk->fetch();

            if ($existing) {
                if (!$file_path) {
                    $file_path = $existing['file_path'];
                }
                $stmt_u = $pdo->prepare("
                    UPDATE assignment_submissions 
                    SET submission_text = ?, file_path = ?, submitted_at = NOW(), review_status = 'Pending', is_late = ?, delay_minutes = ? 
                    WHERE assignment_id = ? AND student_id = ?
                ");
                $stmt_u->execute([$submission_text, $file_path, $is_late, $delay_minutes, $assignment_id, $student_id]);
                $msg = $is_late 
                    ? (is_rtl() ? "تمت إعادة إرسال الواجب بنجاح (تسليم متأخر مسجل)!" : "Assignment resubmitted successfully (tagged as Late Submission)!")
                    : (is_rtl() ? "تمت إعادة إرسال الواجب بنجاح لمراجعة المعلم!" : "Assignment resubmitted successfully for teacher review!");
            } else {
                $stmt_i = $pdo->prepare("
                    INSERT INTO assignment_submissions (assignment_id, student_id, submission_text, file_path, submitted_at, status, review_status, is_late, delay_minutes) 
                    VALUES (?, ?, ?, ?, NOW(), 'Submitted', 'Pending', ?, ?)
                ");
                $stmt_i->execute([$assignment_id, $student_id, $submission_text, $file_path, $is_late, $delay_minutes]);
                $msg = $is_late
                    ? (is_rtl() ? "تم رفع وتسليم الواجب بنجاح (تم وسم التسليم بالمتأخر لانتهاء الموعد المحدد)!" : "Assignment submitted successfully (tagged as Late Submission)!")
                    : (is_rtl() ? "تم رفع وتسليم الواجب بنجاح!" : "Assignment uploaded and turned in successfully!");
            }
        }
    }
}

// Fetch Assignments & Detailed Grading Info
$filter_tab = $_GET['tab'] ?? 'all';

$stmt_assign = $pdo->prepare("
    SELECT a.*, sub.subject_name, u.full_name AS teacher_name,
           subm.submission_id, subm.submitted_at, subm.submission_text, subm.file_path AS student_file,
           subm.grade, subm.teacher_feedback, subm.review_status, subm.is_late, subm.delay_minutes
    FROM assignments a
    JOIN subjects sub ON a.subject_id = sub.subject_id
    LEFT JOIN users u ON a.teacher_id = u.user_id
    LEFT JOIN assignment_submissions subm ON a.assignment_id = subm.assignment_id AND subm.student_id = ?
    WHERE a.class_id = ?
    ORDER BY a.due_date DESC
");
$stmt_assign->execute([$student_id, $class_id]);
$all_assignments = $stmt_assign->fetchAll();

$total_asn = count($all_assignments);
$submitted_count = 0;
$accepted_count = 0;
$pending_count = 0;
$todo_count = 0;
$past_due_count = 0;

$today = date('Y-m-d');
$assignments = [];

foreach ($all_assignments as $a) {
    $has_subm = !empty($a['submission_id']);
    if ($has_subm) $submitted_count++;
    if ($a['review_status'] === 'Accepted') $accepted_count++;
    if ($a['review_status'] === 'Pending') $pending_count++;
    if (!$has_subm && $a['due_date'] >= $today) $todo_count++;
    if (!$has_subm && $a['due_date'] < $today) $past_due_count++;

    // Tab Filtering
    if ($filter_tab === 'todo' && ($has_subm || $a['due_date'] < $today)) continue;
    if ($filter_tab === 'submitted' && (!$has_subm || $a['review_status'] !== 'Pending')) continue;
    if ($filter_tab === 'graded' && $a['review_status'] !== 'Accepted') continue;
    if ($filter_tab === 'past_due' && ($has_subm || $a['due_date'] >= $today)) continue;

    $assignments[] = $a;
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'بوابة الطالب - الواجبات والتسليم' : 'Student Portal - Assignments & Review Desk' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'بوابة الطالب الأكاديمية' : 'Student Academic Hub',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="courses.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-primary"></i> ' . (is_rtl() ? 'المناهج' : 'Courses') . '</a>',
        '<a href="materials.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-folder-open me-1 text-info"></i> ' . (is_rtl() ? 'المستودع' : 'Materials') . '</a>',
        '<a href="discussions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-comments me-1 text-purple"></i> ' . (is_rtl() ? 'النقاشات' : 'Discussions') . '</a>'
    ]
]) ?>

<div class="container py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold mb-4 shadow-xs" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Welcome Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <div class="d-inline-flex align-items-center gap-2 mb-2">
                <span class="badge rounded-full font-medium px-3 py-1 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                    <i class="fa-solid fa-graduation-cap text-emerald-300 me-1"></i> Academic Portal: Live
                </span>
            </div>
            <h2 class="fw-bold font-heading mb-1 text-white"><?= is_rtl() ? 'مرحباً، ' : 'Welcome, ' ?><?= htmlspecialchars($student['full_name'] ?? 'Student') ?>! 👋</h2>
            <p class="mb-0 text-white-50 small">
                <strong><?= is_rtl() ? 'الصف الدراسي:' : 'Class:' ?></strong> <?= htmlspecialchars($student['class_name'] ?? 'Unassigned') ?> | 
                <strong><?= is_rtl() ? 'الرقم الأكاديمي:' : 'Admission No:' ?></strong> <?= htmlspecialchars($student['admission_no'] ?? 'N/A') ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="../../dashboard.php" class="btn btn-light text-primary fw-bold px-3 py-2 rounded-lg shadow-xs">
                <i class="fa-solid fa-house me-1"></i> <?= __('main_dashboard') ?>
            </a>
        </div>
    </div>

    <!-- 4-Column Responsive KPI Cards Grid -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'إجمالي التكليفات' : 'Total Assignments' ?></span>
                    <div class="bg-accent text-primary p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem;">
                        <i class="fa-solid fa-book-open fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $total_asn ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold"><i class="fa-solid fa-list-check me-1"></i> Coursework</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'تم التسليم' : 'Submitted Work' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(2,132,199,0.12); color: #0284C7;">
                        <i class="fa-solid fa-cloud-arrow-up fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $submitted_count ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-primary fw-semibold"><i class="fa-solid fa-arrow-up me-1"></i> Uploaded</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'تم التقييم والاعتماد' : 'Graded & Passed' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(16,185,129,0.12); color: #10B981;">
                        <i class="fa-solid fa-circle-check fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $accepted_count ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-success fw-semibold"><i class="fa-solid fa-star me-1"></i> Completed</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'قيد المراجعة' : 'Pending Evaluation' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(245,158,11,0.12); color: #F59E0B;">
                        <i class="fa-solid fa-hourglass-half fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-3 my-1"><?= $pending_count ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-warning fw-semibold"><i class="fa-solid fa-clock me-1"></i> In Review</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-border rounded-xl shadow-sm bg-card overflow-hidden">
        <div class="card-header bg-card py-3 fw-bold border-border d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-file-arrow-up text-primary me-1"></i>
                <span><?= is_rtl() ? 'الواجبات والتكليفات الدراسية' : 'Assignments & Document Submission Desk' ?></span>
            </div>
            <!-- Workflow Status Tabs -->
            <ul class="nav nav-pills gap-1">
                <li class="nav-item">
                    <a class="nav-link extra-small py-1 px-2.5 rounded-lg <?= $filter_tab === 'all' ? 'active' : 'bg-background text-foreground' ?>" href="student_portal.php?tab=all">
                        <?= is_rtl() ? 'الكل' : 'All' ?> (<?= $total_asn ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link extra-small py-1 px-2.5 rounded-lg <?= $filter_tab === 'todo' ? 'active' : 'bg-background text-foreground' ?>" href="student_portal.php?tab=todo">
                        <?= is_rtl() ? 'المطلوب حله' : 'To Do' ?> (<?= $todo_count ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link extra-small py-1 px-2.5 rounded-lg <?= $filter_tab === 'submitted' ? 'active' : 'bg-background text-foreground' ?>" href="student_portal.php?tab=submitted">
                        <?= is_rtl() ? 'قيد المراجعة' : 'In Review' ?> (<?= $pending_count ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link extra-small py-1 px-2.5 rounded-lg <?= $filter_tab === 'graded' ? 'active' : 'bg-background text-foreground' ?>" href="student_portal.php?tab=graded">
                        <?= is_rtl() ? 'تم التقييم' : 'Graded' ?> (<?= $accepted_count ?>)
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link extra-small py-1 px-2.5 rounded-lg <?= $filter_tab === 'past_due' ? 'active' : 'bg-background text-foreground' ?>" href="student_portal.php?tab=past_due">
                        <?= is_rtl() ? 'فات موعدها' : 'Past Due' ?> (<?= $past_due_count ?>)
                    </a>
                </li>
            </ul>
        </div>
        <div class="card-body p-0">
            <?php if (!empty($assignments)): ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($assignments as $asn): ?>
                        <?php 
                            $now_ts = time();
                            $due_ts = strtotime($asn['due_date'] . ' 23:59:59');
                            $cutoff_ts = !empty($asn['cut_off_date']) ? strtotime($asn['cut_off_date'] . ' 23:59:59') : $due_ts;
                            $is_hard_cutoff = ($asn['deadline_type'] === 'hard' && $now_ts > $cutoff_ts);
                        ?>
                        <div class="list-group-item p-4 border-border">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-accent text-primary px-3 py-1 font-medium"><?= htmlspecialchars($asn['subject_name']) ?></span>
                                    <?php if (!empty($asn['max_score'])): ?>
                                        <span class="badge bg-secondary text-muted-foreground rounded-pill extra-small">
                                            <?= is_rtl() ? 'الدرجة:' : 'Max:' ?> <?= (float)$asn['max_score'] ?> <?= is_rtl() ? 'درجة' : 'pts' ?>
                                        </span>
                                    <?php endif; ?>
                                    <?php if ($asn['deadline_type'] === 'hard'): ?>
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill extra-small">
                                            <i class="fa-solid fa-lock me-1"></i><?= is_rtl() ? 'موعد صارم' : 'Hard Cutoff' ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="<?= $now_ts > $due_ts ? 'text-destructive' : 'text-muted-foreground' ?> fw-bold small">
                                        <i class="fa-solid fa-clock me-1"></i><?= is_rtl() ? 'تاريخ التسليم:' : 'Due Date:' ?> <?= htmlspecialchars($asn['due_date']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <h5 class="fw-bold text-foreground mb-1 font-heading"><?= htmlspecialchars($asn['title']) ?></h5>
                            <p class="text-muted-foreground mb-3"><?= htmlspecialchars($asn['instructions']) ?></p>

                            <!-- Review Status Banner -->
                            <div class="mb-3">
                                <?php if ($asn['review_status'] === 'Accepted'): ?>
                                    <div class="p-3 bg-opacity-10 border border-success rounded-lg" style="background-color: #ECFDF5;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold text-success fs-6"><i class="fa-solid fa-circle-check me-2"></i><?= is_rtl() ? 'تم التقييم والاعتماد!' : 'Graded & Accepted!' ?></span>
                                            <span class="badge bg-success fs-6"><?= is_rtl() ? 'الدرجة:' : 'Score:' ?> <?= htmlspecialchars($asn['grade'] ?? 'A') ?></span>
                                        </div>
                                        <?php if (!empty($asn['is_late'])): ?>
                                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill extra-small mt-2">
                                                <i class="fa-solid fa-hourglass-end me-1"></i> <?= is_rtl() ? 'تسليم متأخر' : 'Late Submission' ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($asn['teacher_feedback'])): ?>
                                            <small class="text-foreground d-block mt-2"><strong><?= is_rtl() ? 'ملاحظات المعلم:' : 'Teacher Remarks:' ?></strong> <?= htmlspecialchars($asn['teacher_feedback']) ?></small>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($asn['review_status'] === 'Needs Revision'): ?>
                                    <div class="p-3 border border-danger rounded-lg" style="background-color: #FEE2E2;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold text-destructive fs-6"><i class="fa-solid fa-triangle-exclamation me-2"></i><?= is_rtl() ? 'مطلوب مراجعة وتعديل الواجب!' : 'Revision Required!' ?></span>
                                            <span class="badge bg-danger"><?= is_rtl() ? 'إعادة تسليم' : 'Redo Requested' ?></span>
                                        </div>
                                        <small class="text-foreground d-block mt-2"><strong><?= is_rtl() ? 'توجيهات المعلم:' : 'Teacher Feedback:' ?></strong> <?= htmlspecialchars($asn['teacher_feedback'] ?? 'Please correct and re-upload your document.') ?></small>
                                    </div>

                                <?php elseif (!empty($asn['submission_id'])): ?>
                                    <div class="p-3 border border-warning rounded-lg" style="background-color: #FEF3C7;">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold text-warning"><i class="fa-solid fa-hourglass-half me-2"></i><?= is_rtl() ? 'تم التسليم - بانتظار تقييم المعلم' : 'Submitted - Pending Teacher Evaluation' ?></span>
                                            <?php if (!empty($asn['is_late'])): ?>
                                                <span class="badge bg-danger text-white rounded-pill extra-small">
                                                    <i class="fa-solid fa-clock-rotate-left me-1"></i> <?= is_rtl() ? 'تسليم متأخر' : 'Late Submission' ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <small class="text-muted-foreground d-block mt-1"><?= is_rtl() ? 'تم الرفع في:' : 'Uploaded on:' ?> <?= htmlspecialchars($asn['submitted_at']) ?></small>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-secondary text-muted-foreground px-3 py-2"><i class="fa-solid fa-circle-exclamation me-1"></i> <?= is_rtl() ? 'لم يتم التسليم بعد' : 'Not Submitted Yet' ?></span>
                                <?php endif; ?>
                            </div>

                            <!-- Upload & Submission Form -->
                            <?php if ($is_hard_cutoff && empty($asn['submission_id'])): ?>
                                <div class="p-3 bg-secondary text-muted-foreground rounded-lg border border-border mt-3 text-center small">
                                    <i class="fa-solid fa-lock me-1 text-danger"></i>
                                    <?= is_rtl() ? 'تم إغلاق باب التسليم نهائياً لهذا الواجب لانتهاء الموعد النهائي الصارم.' : 'Submissions locked. The hard cutoff deadline has passed for this assignment.' ?>
                                </div>
                            <?php elseif (empty($asn['submission_id']) || $asn['review_status'] === 'Needs Revision' || $asn['review_status'] === 'Pending'): ?>
                                <form method="POST" enctype="multipart/form-data" class="bg-secondary p-3 rounded-lg border border-border mt-3">
                                    <input type="hidden" name="action" value="submit_homework">
                                    <input type="hidden" name="assignment_id" value="<?= $asn['assignment_id'] ?>">
                                    
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small text-uppercase text-muted-foreground"><?= is_rtl() ? 'ملاحظات / تعليق الطالب:' : 'Notes / Comments:' ?></label>
                                        <textarea name="submission_text" class="form-control" rows="2" placeholder="<?= is_rtl() ? 'اكتب ملاحظاتك لمعلم المادة...' : 'Write any notes for your teacher...' ?>"><?= htmlspecialchars($asn['submission_text'] ?? '') ?></textarea>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small text-uppercase text-muted-foreground"><?= is_rtl() ? 'إرفاق ملف الواجب (PDF, Word, صور, Zip):' : 'Attach Document (PDF, Word, Images, Zip):' ?></label>
                                        <input type="file" name="assignment_file" class="form-control">
                                        <?php if (!empty($asn['student_file'])): ?>
                                            <small class="text-muted-foreground d-block mt-1"><?= is_rtl() ? 'الملف المرفق حالياً:' : 'Current file:' ?> <a href="../../<?= htmlspecialchars($asn['student_file']) ?>" target="_blank" class="fw-bold text-primary"><i class="fa-solid fa-paperclip me-1"></i><?= is_rtl() ? 'عرض الملف' : 'View Uploaded File' ?></a></small>
                                        <?php endif; ?>
                                    </div>

                                    <button type="submit" class="btn btn-sm btn-primary fw-bold">
                                        <i class="fa-solid fa-cloud-arrow-up me-1"></i> <?= $asn['review_status'] === 'Needs Revision' ? (is_rtl() ? 'إعادة رفع التعديل' : 'Re-upload Revision') : (is_rtl() ? 'رفع وتسليم التكليف' : 'Upload & Submit Assignment') ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>
                <div class="p-5 text-center text-muted-foreground"><?= is_rtl() ? 'لا توجد واجبات متاحة حالياً.' : 'No assignments available at this time.' ?></div>
            <?php endif; ?>
        </div>
    </div>

    <?= render_platform_footer() ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>