<?php
require_once __DIR__ . '/../../core/init.php';
// Verify License
$licCheck = checkSystemLicense($pdo);
if (!$licCheck['valid']) {
    die("<h3 style='color:red;'>System Access Restricted: " . $licCheck['reason'] . "</h3>");
}

Auth::requireRole(['parent', 'admin', 'school_admin']);

$parent_user_id = $_SESSION['user_id'];

// Fetch Parent Record & Linked Children
$stmt_parent = $pdo->prepare("SELECT * FROM parents WHERE user_id = ?");
$stmt_parent->execute([$parent_user_id]);
$parent = $stmt_parent->fetch();
$parent_id = $parent['parent_id'] ?? 1;

$stmt_children = $pdo->prepare("
    SELECT s.student_id, s.admission_no, s.class_id, u.full_name, c.class_name
    FROM parent_student_map psm
    JOIN students s ON psm.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE psm.parent_id = ?
");
$stmt_children->execute([$parent_id]);
$children = $stmt_children->fetchAll();

if (empty($children)) {
    $children = $pdo->query("
        SELECT s.student_id, s.admission_no, s.class_id, u.full_name, c.class_name
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        LIMIT 5
    ")->fetchAll();
}

$selected_student_id = isset($_GET['child_id']) ? intval($_GET['child_id']) : ($children[0]['student_id'] ?? 0);

$active_child = null;
foreach ($children as $child) {
    if ($child['student_id'] == $selected_student_id) {
        $active_child = $child;
        break;
    }
}
if (!$active_child && !empty($children)) {
    $active_child = $children[0];
    $selected_student_id = $active_child['student_id'];
}

// Queries for Selected Child
$assignments_monitor = [];

if ($active_child) {
    // Homework & Submission Tracker
    $stmt_m = $pdo->prepare("
        SELECT a.*, a.file_path AS assignment_file, sub.subject_name, u.full_name AS teacher_name,
               subm.submission_id, subm.submitted_at, subm.submission_text, subm.file_path AS student_file,
               subm.status AS submission_status, subm.grade, subm.teacher_feedback, subm.review_status
        FROM assignments a
        JOIN subjects sub ON a.subject_id = sub.subject_id
        LEFT JOIN users u ON a.teacher_id = u.user_id
        LEFT JOIN assignment_submissions subm ON a.assignment_id = subm.assignment_id AND subm.student_id = ?
        WHERE a.class_id = ?
        ORDER BY a.due_date DESC
    ");
    $stmt_m->execute([$selected_student_id, $active_child['class_id'] ?? 1]);
    $assignments_monitor = $stmt_m->fetchAll();
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'بوابة أولياء الأمور - متابعة التكليفات والدرجات والملاحظات' : 'Parent Portal - Assignment & Progress Tracker' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .card-custom { border: none; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
        .parent-header { background: linear-gradient(135deg, #1e293b 0%, #0284c7 100%); color: white; }
        .teacher-feedback-card {
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .teacher-feedback-card:hover {
            box-shadow: 0 4px 10px rgba(0,0,0,0.04);
        }
    </style>
</head>
<body class="bg-light">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'بوابة أولياء الأمور' : 'Parent Portal',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => []
]) ?>

<div class="container py-4">

    <!-- Child Selector -->
    <div class="card card-custom p-3 mb-4 bg-white">
        <form method="GET" action="" class="row align-items-center g-3">
            <div class="col-md-3">
                <label class="fw-bold text-uppercase text-secondary small"><i class="fa-solid fa-child-reaching me-1 text-primary"></i> <?= is_rtl() ? 'اختر الطالب / الابن:' : 'Select Child:' ?></label>
            </div>
            <div class="col-md-7">
                <select name="child_id" class="form-select form-select-lg fw-bold text-primary" onchange="this.form.submit()">
                    <?php foreach ($children as $ch): ?>
                        <option value="<?= $ch['student_id'] ?>" <?= $ch['student_id'] == $selected_student_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ch['full_name']) ?> (<?= is_rtl() ? 'رقم القيد:' : 'Adm:' ?> <?= htmlspecialchars($ch['admission_no']) ?> — <?= htmlspecialchars($ch['class_name'] ?? (is_rtl() ? 'غير مسجل بفصل' : 'Class N/A')) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold"><i class="fa-solid fa-rotate me-1"></i> <?= is_rtl() ? 'عرض' : 'Switch' ?></button>
            </div>
        </form>
    </div>

    <?php if ($active_child): ?>
    <div class="p-4 mb-4 parent-header rounded-3 shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-1"><?= htmlspecialchars($active_child['full_name']) ?></h3>
            <p class="mb-0 opacity-75 small">
                <strong><?= is_rtl() ? 'الفصل الدراسي:' : 'Class:' ?></strong> <?= htmlspecialchars($active_child['class_name'] ?? (is_rtl() ? 'غير محدد' : 'Unassigned')) ?> | 
                <strong><?= is_rtl() ? 'الرقم الأكاديمي:' : 'Admission No:' ?></strong> <?= htmlspecialchars($active_child['admission_no']) ?>
            </p>
        </div>
        <div>
            <span class="badge bg-warning text-dark px-3 py-2 fs-6 shadow-xs"><i class="fa-solid fa-eye me-1"></i> <?= is_rtl() ? 'متابعة ولي الأمر نشطة' : 'Parent Oversight Active' ?></span>
        </div>
    </div>

    <!-- Parent Portal Quick Actions: Academic Reports & Timetable -->
    <div class="row g-3 mb-4">
        <div class="col-md-4 col-sm-6">
            <a href="../Grading/report_cards.php" class="card admin-action-card p-3 text-decoration-none">
                <div class="d-flex align-items-center">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-foreground"><?= is_rtl() ? 'النتائج والتقارير الأكاديمية' : 'Report Cards & Evaluations' ?></h6>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'معاينة بطاقات الدرجات والتحصيل الأكاديمي' : 'View term report cards & performance' ?></small>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-md-4 col-sm-6">
            <a href="timetable.php" class="card admin-action-card p-3 text-decoration-none">
                <div class="d-flex align-items-center">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-calendar-days"></i>
                    </div>
                    <div>
                        <h6 class="fw-bold mb-1 text-foreground"><?= is_rtl() ? 'جدول الحصص الأسبوعي' : 'Weekly Timetable' ?></h6>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'متابعة مواعيد الحصص والمواد الدراسية' : 'Check class schedules & subjects' ?></small>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <div class="tab-content">
        <!-- Homework & Completion Tracker -->
        <div class="tab-pane fade show active" id="p-assign">
            <div class="card card-custom">
                <div class="card-header bg-white py-3 fw-bold border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span class="text-dark"><i class="fa-solid fa-book-open-reader text-primary me-2"></i><?= is_rtl() ? 'واجبات وتكليفات الطالب vs ملاحظات وتوجيهات المعلم' : 'Teacher Assignments vs Student Submissions & Feedback' ?></span>
                    <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 px-3 py-1.5"><?= is_rtl() ? 'إشراف ثلاثي متكامل' : 'Triangular LMS Oversight' ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light text-muted small text-uppercase">
                                <tr>
                                    <th style="width: 16%;"><?= is_rtl() ? 'المادة والمعلم' : 'Subject & Teacher' ?></th>
                                    <th style="width: 24%;"><?= is_rtl() ? 'بيانات التكليف وتاريخ التسليم' : 'Assignment & Due Date' ?></th>
                                    <th style="width: 24%;"><?= is_rtl() ? 'حل وإرفاق الطالب' : 'Student Submission / Solution' ?></th>
                                    <th style="width: 31%;"><?= is_rtl() ? 'قرار وملاحظات وتوجيهات المعلم' : 'Teacher Decision & Comments' ?></th>
                                    <th style="width: 5%;" class="text-center"><?= is_rtl() ? 'معاينة' : 'View' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($assignments_monitor)): ?>
                                    <?php foreach ($assignments_monitor as $am): 
                                        $has_submitted = !empty($am['submission_id']);
                                        $rev_status = $am['review_status'] ?? 'Pending';
                                        $is_past_due = strtotime($am['due_date']) < strtotime(date('Y-m-d'));
                                    ?>
                                        <tr>
                                            <!-- Subject & Teacher -->
                                            <td>
                                                <div class="fw-bold text-primary mb-1"><?= htmlspecialchars($am['subject_name']) ?></div>
                                                <div class="text-muted small d-flex align-items-center gap-1" style="font-size: 0.78rem;">
                                                    <i class="fa-solid fa-chalkboard-user text-secondary"></i>
                                                    <span><?= htmlspecialchars($am['teacher_name'] ?? (is_rtl() ? 'معلم المادة' : 'Teacher')) ?></span>
                                                </div>
                                            </td>

                                            <!-- Assignment Details -->
                                            <td>
                                                <div class="fw-bold text-dark mb-1"><?= htmlspecialchars($am['title']) ?></div>
                                                <?php if (!empty($am['instructions'])): ?>
                                                    <div class="text-muted small mb-1" style="font-size: 0.78rem; line-height: 1.3;">
                                                        <?= nl2br(htmlspecialchars(mb_substr($am['instructions'], 0, 110))) . (mb_strlen($am['instructions']) > 110 ? '...' : '') ?>
                                                    </div>
                                                <?php endif; ?>
                                                
                                                <div class="d-flex align-items-center gap-2 flex-wrap mt-1">
                                                    <span class="badge <?= $is_past_due ? 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25' : 'bg-secondary bg-opacity-10 text-dark border' ?> small" style="font-size: 0.72rem;">
                                                        <i class="fa-regular fa-calendar me-1"></i><?= is_rtl() ? 'التسليم:' : 'Due:' ?> <?= htmlspecialchars($am['due_date']) ?>
                                                    </span>

                                                    <?php if (!empty($am['assignment_file'])): ?>
                                                        <a href="../../<?= htmlspecialchars($am['assignment_file']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.72rem;">
                                                            <i class="fa-solid fa-paperclip text-primary me-1"></i><?= is_rtl() ? 'ورقة التكليف' : 'Worksheet' ?>
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>

                                            <!-- Student Solution -->
                                            <td>
                                                <?php if ($has_submitted): ?>
                                                    <div class="d-flex flex-column gap-1">
                                                        <div class="d-flex align-items-center gap-1">
                                                            <span class="badge bg-success bg-opacity-10 text-success fw-bold" style="font-size: 0.72rem;">
                                                                <i class="fa-solid fa-circle-check me-1"></i><?= is_rtl() ? 'تم تسليم الحل' : 'Turned In' ?>
                                                            </span>
                                                            <span class="text-muted" style="font-size: 0.7rem;">
                                                                <?= !empty($am['submitted_at']) ? date('M d, H:i', strtotime($am['submitted_at'])) : '' ?>
                                                            </span>
                                                        </div>

                                                        <?php if (!empty($am['submission_text'])): ?>
                                                            <div class="p-2 bg-light rounded text-dark border" style="font-size: 0.75rem; white-space: pre-line; max-height: 80px; overflow-y: auto;">
                                                                <?= htmlspecialchars($am['submission_text']) ?>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (!empty($am['student_file'])): ?>
                                                            <div>
                                                                <a href="../../<?= htmlspecialchars($am['student_file']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0.5 px-2" style="font-size: 0.72rem;">
                                                                    <i class="fa-solid fa-file-arrow-down me-1"></i><?= is_rtl() ? 'تحميل حل الطالب' : 'Student Solution File' ?>
                                                                </a>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25 px-2.5 py-1.5" style="font-size: 0.75rem;">
                                                        <i class="fa-solid fa-circle-xmark me-1"></i><?= is_rtl() ? 'لم يتم التسليم بعد' : 'Not Turned In Yet' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Teacher Evaluation & Comments -->
                                            <td>
                                                <?php if ($rev_status === 'Needs Revision'): ?>
                                                    <div class="d-flex align-items-center gap-1.5 mb-1.5 flex-wrap">
                                                        <span class="badge bg-danger px-2.5 py-1.5 d-inline-flex align-items-center gap-1" style="font-size: 0.75rem;">
                                                            <i class="fa-solid fa-triangle-exclamation"></i> <?= is_rtl() ? 'مطلوب تعديل وإعادة تسليم (Redo)' : 'Revision Required (Redo)' ?>
                                                        </span>
                                                        <?php if (!empty($am['grade'])): ?>
                                                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25" style="font-size: 0.72rem;">
                                                                <?= htmlspecialchars($am['grade']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if (!empty($am['teacher_feedback'])): ?>
                                                        <div class="p-2.5 bg-danger bg-opacity-10 border border-danger-subtle rounded-3 shadow-xs teacher-feedback-card">
                                                            <div class="fw-bold text-danger d-flex align-items-center gap-1 small mb-1">
                                                                <i class="fa-solid fa-comment-dots"></i>
                                                                <span><?= is_rtl() ? 'توجيهات وملاحظات المعلم للتعديل:' : 'Teacher Revision & Redo Guidance:' ?></span>
                                                            </div>
                                                            <div class="text-dark small" style="white-space: pre-line; line-height: 1.4; font-size: 0.8rem;">
                                                                <?= htmlspecialchars($am['teacher_feedback']) ?>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-danger small"><i class="fa-solid fa-circle-exclamation me-1"></i><?= is_rtl() ? 'طلب المعلم مراجعة وتعديل الواجب وإعادة التسليم.' : 'Teacher requested student to review and redo the assignment.' ?></div>
                                                    <?php endif; ?>

                                                <?php elseif ($rev_status === 'Accepted'): ?>
                                                    <div class="d-flex align-items-center gap-1.5 mb-1.5 flex-wrap">
                                                        <span class="badge bg-success px-2.5 py-1.5 d-inline-flex align-items-center gap-1" style="font-size: 0.75rem;">
                                                            <i class="fa-solid fa-circle-check"></i> <?= is_rtl() ? 'تم التقييم والاعتماد' : 'Graded & Accepted' ?>
                                                        </span>
                                                        <?php if (!empty($am['grade'])): ?>
                                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 fw-bold" style="font-size: 0.75rem;">
                                                                <?= is_rtl() ? 'الدرجة: ' : 'Score: ' ?><?= htmlspecialchars($am['grade']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>

                                                    <?php if (!empty($am['teacher_feedback'])): ?>
                                                        <div class="p-2.5 bg-success bg-opacity-10 border border-success-subtle rounded-3 shadow-xs teacher-feedback-card">
                                                            <div class="fw-bold text-success d-flex align-items-center gap-1 small mb-1">
                                                                <i class="fa-solid fa-comment-dots"></i>
                                                                <span><?= is_rtl() ? 'ملاحظات وتقييم المعلم للطالب:' : 'Teacher Feedback & Remarks:' ?></span>
                                                            </div>
                                                            <div class="text-dark small" style="white-space: pre-line; line-height: 1.4; font-size: 0.8rem;">
                                                                <?= htmlspecialchars($am['teacher_feedback']) ?>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>

                                                <?php elseif ($has_submitted): ?>
                                                    <span class="badge bg-warning text-dark px-2.5 py-1.5 d-inline-flex align-items-center gap-1 mb-1" style="font-size: 0.75rem;">
                                                        <i class="fa-solid fa-hourglass-half"></i> <?= is_rtl() ? 'تم التسليم - قيد مراجعة المعلم' : 'Submitted - Pending Review' ?>
                                                    </span>
                                                    <div class="text-muted small mt-1" style="font-size: 0.72rem;">
                                                        <i class="fa-regular fa-clock me-1"></i><?= is_rtl() ? 'بانتظار تصحيح المعلم وتسجيل الملاحظات والدرجة.' : 'Awaiting teacher evaluation and feedback comments.' ?>
                                                    </div>

                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-muted px-2.5 py-1.5" style="font-size: 0.75rem;">
                                                        <i class="fa-solid fa-minus me-1"></i><?= is_rtl() ? 'بانتظار تسليم الطالب' : 'Awaiting Student Submission' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <!-- Detail View Button -->
                                            <td class="text-center">
                                                <button type="button" class="btn btn-outline-primary btn-sm rounded-circle p-0 d-inline-flex align-items-center justify-content-center" 
                                                        style="width: 32px; height: 32px;" 
                                                        title="<?= is_rtl() ? 'معاينة كامل تفاصيل التكليف والملاحظات' : 'View Full Details & Feedback' ?>"
                                                        onclick='openParentDetailModal(<?= json_encode([
                                                            "title" => $am["title"],
                                                            "subject_name" => $am["subject_name"],
                                                            "teacher_name" => $am["teacher_name"] ?? (is_rtl() ? "معلم المادة" : "Teacher"),
                                                            "due_date" => $am["due_date"],
                                                            "instructions" => $am["instructions"] ?? "",
                                                            "assignment_file" => $am["assignment_file"] ?? "",
                                                            "has_submitted" => $has_submitted,
                                                            "submitted_at" => !empty($am["submitted_at"]) ? date("M d, Y H:i", strtotime($am["submitted_at"])) : "",
                                                            "submission_text" => $am["submission_text"] ?? "",
                                                            "student_file" => $am["student_file"] ?? "",
                                                            "review_status" => $rev_status,
                                                            "grade" => $am["grade"] ?? "",
                                                            "teacher_feedback" => $am["teacher_feedback"] ?? ""
                                                        ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                                    <i class="fa-solid fa-eye" style="font-size: 0.8rem;"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted p-5">
                                            <i class="fa-solid fa-folder-open fs-2 mb-2 d-block text-secondary"></i>
                                            <?= is_rtl() ? 'لا توجد تكليفات أو واجبات مسجلة لهذا الفصل الدراسي حالياً.' : 'No assignments recorded for this class section yet.' ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
    <?php endif; ?>
</div>

<!-- Parent Assignment & Teacher Comments Inspection Modal -->
<div class="modal fade" id="parentAssignmentDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-3 px-4">
                <div class="d-flex align-items-center gap-2">
                    <i class="fa-solid fa-book-open-reader text-warning fs-5"></i>
                    <div>
                        <h5 class="modal-title fw-bold fs-6 mb-0" id="parentModalTitle">Assignment Title</h5>
                        <span class="badge bg-primary px-2.5 py-0.5" id="parentModalSubject">Subject</span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4 bg-light">
                <!-- Assignment Info -->
                <div class="card border-0 shadow-xs mb-3 rounded-3">
                    <div class="card-header bg-white py-2 px-3 fw-bold small text-dark d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-chalkboard-user text-primary me-1"></i> <span id="parentModalTeacher">Teacher</span></span>
                        <span class="text-danger small" id="parentModalDueDate"><i class="fa-regular fa-clock me-1"></i> Due Date</span>
                    </div>
                    <div class="card-body p-3">
                        <h6 class="fw-bold small text-muted text-uppercase mb-1"><?= is_rtl() ? 'التعليمات والإرشادات الأكاديمية:' : 'Assignment Instructions:' ?></h6>
                        <div id="parentModalInstructions" class="small text-dark mb-2" style="white-space: pre-line;"></div>
                        <div id="parentModalWorksheetContainer" style="display: none;">
                            <a id="parentModalWorksheetLink" href="#" target="_blank" class="btn btn-sm btn-outline-secondary fw-semibold">
                                <i class="fa-solid fa-paperclip text-primary me-1"></i> <?= is_rtl() ? 'عرض ورقة عمل المعلم' : 'Download Teacher Worksheet' ?>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Student Solution Details -->
                <div class="card border-0 shadow-xs mb-3 rounded-3">
                    <div class="card-header bg-white py-2 px-3 fw-bold small text-dark d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-file-signature text-success me-1"></i> <?= is_rtl() ? 'ما قام الطالب بتسليمه:' : 'Student Submitted Work:' ?></span>
                        <span id="parentModalSubmitTime" class="text-muted small"></span>
                    </div>
                    <div class="card-body p-3">
                        <div id="parentModalSolutionText" class="p-2.5 bg-light rounded text-dark small border mb-2" style="white-space: pre-line; max-height: 120px; overflow-y: auto;"></div>
                        <div id="parentModalStudentFileContainer" style="display: none;">
                            <a id="parentModalStudentFileLink" href="#" target="_blank" class="btn btn-sm btn-outline-primary fw-semibold">
                                <i class="fa-solid fa-file-arrow-down me-1"></i> <?= is_rtl() ? 'تحميل حل وملف الطالب' : 'Download Student Attached Solution' ?>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Teacher Evaluation & Revision Comments Box -->
                <div class="card border-0 shadow-xs rounded-3">
                    <div class="card-header bg-white py-2 px-3 fw-bold small text-dark d-flex justify-content-between align-items-center">
                        <span><i class="fa-solid fa-comment-dots text-warning me-1"></i> <?= is_rtl() ? 'قرار وملاحظات وتوجيهات المعلم:' : 'Teacher Feedback & Evaluation Remarks:' ?></span>
                        <span id="parentModalStatusBadge"></span>
                    </div>
                    <div class="card-body p-3">
                        <div id="parentModalFeedbackContainer">
                            <div id="parentModalFeedbackContent" class="p-3 rounded-3 small" style="white-space: pre-line; line-height: 1.5;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-white py-2 px-4">
                <button type="button" class="btn btn-secondary btn-sm px-4" data-bs-dismiss="modal"><?= is_rtl() ? 'إغلاق' : 'Close' ?></button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openParentDetailModal(data) {
    document.getElementById('parentModalTitle').innerText = data.title;
    document.getElementById('parentModalSubject').innerText = data.subject_name;
    document.getElementById('parentModalTeacher').innerText = data.teacher_name;
    document.getElementById('parentModalDueDate').innerHTML = '<i class="fa-regular fa-clock me-1"></i> ' + (<?= json_encode(is_rtl() ? 'التسليم: ' : 'Due: ') ?>) + data.due_date;
    document.getElementById('parentModalInstructions').innerText = data.instructions || (<?= json_encode(is_rtl() ? 'لا توجد تعليمات إضافية.' : 'No additional instructions provided.') ?>);

    // Worksheet attachment
    const wsContainer = document.getElementById('parentModalWorksheetContainer');
    const wsLink = document.getElementById('parentModalWorksheetLink');
    if (data.assignment_file) {
        wsLink.href = '../../' + data.assignment_file;
        wsContainer.style.display = 'block';
    } else {
        wsContainer.style.display = 'none';
    }

    // Student solution text & file
    const solText = document.getElementById('parentModalSolutionText');
    const submitTime = document.getElementById('parentModalSubmitTime');
    const stFileContainer = document.getElementById('parentModalStudentFileContainer');
    const stFileLink = document.getElementById('parentModalStudentFileLink');

    if (data.has_submitted) {
        submitTime.innerText = data.submitted_at ? (<?= json_encode(is_rtl() ? 'تم التسليم: ' : 'Turned In: ') ?> + data.submitted_at) : '';
        solText.innerText = data.submission_text || (<?= json_encode(is_rtl() ? 'تم التسليم بدون ملاحظات نصية إضافية.' : 'Submitted with no additional text comments.') ?>);
        if (data.student_file) {
            stFileLink.href = '../../' + data.student_file;
            stFileContainer.style.display = 'block';
        } else {
            stFileContainer.style.display = 'none';
        }
    } else {
        submitTime.innerText = <?= json_encode(is_rtl() ? 'لم يتم التسليم' : 'Not Turned In') ?>;
        solText.innerHTML = '<span class="text-danger fw-semibold"><i class="fa-solid fa-circle-xmark me-1"></i> ' + (<?= json_encode(is_rtl() ? 'لم يقم الطالب برفع حل الواجب بعد.' : 'Student has not submitted any solution yet.') ?>) + '</span>';
        stFileContainer.style.display = 'none';
    }

    // Teacher feedback & review status
    const statusBadge = document.getElementById('parentModalStatusBadge');
    const fbContent = document.getElementById('parentModalFeedbackContent');

    if (data.review_status === 'Needs Revision') {
        statusBadge.innerHTML = '<span class="badge bg-danger px-2.5 py-1"><i class="fa-solid fa-triangle-exclamation me-1"></i> ' + (<?= json_encode(is_rtl() ? 'مطلوب تعديل وإعادة تسليم' : 'Revision Required (Redo)') ?>) + '</span>';
        fbContent.className = 'p-3 rounded-3 small bg-danger bg-opacity-10 border border-danger-subtle text-dark';
        fbContent.innerHTML = '<strong class="text-danger d-block mb-1"><i class="fa-solid fa-rotate-left me-1"></i> ' + (<?= json_encode(is_rtl() ? 'توجيهات المعلم لإعادة التسليم والتعديل:' : 'Teacher Revision & Redo Guidance:') ?>) + '</strong>' + (data.teacher_feedback ? data.teacher_feedback : (<?= json_encode(is_rtl() ? 'طلب المعلم من الطالب مراجعة وتعديل الواجب وإعادة الإرفاق.' : 'Teacher requested student to review and redo the assignment.') ?>));
    } else if (data.review_status === 'Accepted') {
        statusBadge.innerHTML = '<span class="badge bg-success px-2.5 py-1"><i class="fa-solid fa-circle-check me-1"></i> ' + (<?= json_encode(is_rtl() ? 'معتمد' : 'Accepted') ?>) + ' ' + (data.grade ? '(' + data.grade + ')' : '') + '</span>';
        fbContent.className = 'p-3 rounded-3 small bg-success bg-opacity-10 border border-success-subtle text-dark';
        fbContent.innerHTML = '<strong class="text-success d-block mb-1"><i class="fa-solid fa-comment-dots me-1"></i> ' + (<?= json_encode(is_rtl() ? 'ملاحظات وتقييم المعلم:' : 'Teacher Evaluation Remarks:') ?>) + '</strong>' + (data.teacher_feedback ? data.teacher_feedback : (<?= json_encode(is_rtl() ? 'تم اعتماد التكليف بنجاح.' : 'Assignment evaluated and accepted.') ?>));
    } else if (data.has_submitted) {
        statusBadge.innerHTML = '<span class="badge bg-warning text-dark px-2.5 py-1"><i class="fa-solid fa-hourglass-half me-1"></i> ' + (<?= json_encode(is_rtl() ? 'قيد المراجعة' : 'Pending Review') ?>) + '</span>';
        fbContent.className = 'p-3 rounded-3 small bg-warning bg-opacity-10 border border-warning-subtle text-dark';
        fbContent.innerHTML = '<span class="text-muted"><i class="fa-regular fa-clock me-1"></i> ' + (<?= json_encode(is_rtl() ? 'التكليف بانتظار تصحيح المعلم وتسجيل الدرجات والملاحظات.' : 'Assignment is pending teacher evaluation and remarks.') ?>) + '</span>';
    } else {
        statusBadge.innerHTML = '<span class="badge bg-secondary px-2.5 py-1">' + (<?= json_encode(is_rtl() ? 'غير مسلّم' : 'Not Submitted') ?>) + '</span>';
        fbContent.className = 'p-3 rounded-3 small bg-light border text-muted';
        fbContent.innerHTML = '<i class="fa-solid fa-circle-info me-1"></i> ' + (<?= json_encode(is_rtl() ? 'في انتظار قيام الطالب برفع الحل.' : 'Awaiting student submission.') ?>);
    }

    const modal = new bootstrap.Modal(document.getElementById('parentAssignmentDetailModal'));
    modal.show();
}
</script>
</body>
</html>