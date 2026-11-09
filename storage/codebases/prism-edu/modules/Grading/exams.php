<?php
/**
 * Grading Module - Examination Scores Ledger & Entry
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher', 'student', 'parent']);

$role = Auth::role();
$user_id = Auth::id();
$msg = "";
$msg_type = "success";

// Handle Teacher posting exam results
if (in_array($role, ['teacher', 'admin']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_result') {
    $student_id     = intval($_POST['student_id']);
    $subject_id     = intval($_POST['subject_id']);
    $exam_name      = trim($_POST['exam_name']);
    $marks_obtained = floatval($_POST['marks_obtained']);
    $total_marks    = floatval($_POST['total_marks']);

    // Simple grading scale calculation
    $percentage = ($total_marks > 0) ? ($marks_obtained / $total_marks) * 100 : 0;
    $grade = 'F';
    if ($percentage >= 90) $grade = 'A+';
    elseif ($percentage >= 80) $grade = 'A';
    elseif ($percentage >= 70) $grade = 'B';
    elseif ($percentage >= 60) $grade = 'C';
    elseif ($percentage >= 50) $grade = 'D';

    if ($student_id > 0 && $subject_id > 0 && !empty($exam_name)) {
        $stmt = $pdo->prepare("INSERT INTO exam_results (student_id, subject_id, exam_name, marks_obtained, total_marks, grade) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$student_id, $subject_id, $exam_name, $marks_obtained, $total_marks, $grade]);
        $msg = "Exam results recorded successfully!";
    } else {
        $msg = "Please fill in all mandatory fields.";
        $msg_type = "danger";
    }
}

// Fetch data depending on role
$students = $pdo->query("SELECT s.student_id, u.full_name, c.class_name FROM students s JOIN users u ON s.user_id = u.user_id JOIN classes c ON s.class_id = c.class_id ORDER BY u.full_name ASC")->fetchAll();
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll();

// Academic Calendar Exam Window Sync
$active_exam_period = AcademicCalendar::isExamPeriod(date('Y-m-d'));
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('exams_and_grading') ?> - <?= is_rtl() ? 'رصد درجات الاختبارات' : 'Exam Scores' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'مركز إدارة الامتحانات والتقييمات' : 'Examination & Assessment Hub',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="teacher_grades.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-chart-simple me-1 text-primary"></i> ' . __('grades') . '</a>'
    ]
]) ?>

<div class="container py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <h2 class="fw-bold font-heading mb-1 text-white"><i class="fa-solid fa-square-poll-horizontal me-2"></i><?= is_rtl() ? 'سجل نتائج وتقييمات الاختبارات' : 'Examinations & Academic Records' ?></h2>
        <p class="mb-0 text-white-50 small"><?= is_rtl() ? 'تسجيل نتائج الاختبارات، حساب التقديرات والنسب المئوية، ومتابعة التحصيل الدراسي للطلاب.' : 'Record test scores, compute letter grades, and view student performance reports.' ?></p>
    </div>

    <?php if ($active_exam_period): ?>
        <div class="alert alert-primary border-0 shadow-xs d-flex align-items-center justify-content-between p-3 mb-4 rounded-xl" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-file-signature fs-4 text-primary"></i>
                <div>
                    <div class="fw-bold"><?= is_rtl() ? 'فترة امتحانات نشطة حالياً وفقاً للتقويم الأكاديمي!' : 'Official Examination Window Active in Academic Calendar!' ?></div>
                    <div class="small text-muted-foreground"><?= htmlspecialchars($active_exam_period['title']) ?> (<?= htmlspecialchars($active_exam_period['start_date']) ?> <?= is_rtl() ? 'إلى' : 'to' ?> <?= htmlspecialchars($active_exam_period['end_date']) ?>)</div>
                </div>
            </div>
            <span class="badge bg-primary text-white px-3 py-2 rounded-pill"><?= is_rtl() ? 'تقييم رسمي' : 'Official Assessment' ?></span>
        </div>
    <?php endif; ?>

    <?php if ($role === 'teacher' || $role === 'admin'): ?>
    <!-- Teacher / Admin Result Entry Form -->
    <div class="card border border-border bg-card shadow-sm rounded-xl mb-4">
        <div class="card-header bg-card border-bottom border-border py-3 fw-bold text-foreground">
            <i class="fa-solid fa-pen-to-square text-primary me-2"></i><?= is_rtl() ? 'تسجيل درجة اختبار لطالب' : 'Record Student Exam Mark' ?>
        </div>
        <div class="card-body p-4">
            <form method="POST" action="" class="row g-3">
                <input type="hidden" name="action" value="save_result">
                <div class="col-md-4">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الطالب:' : 'Student:' ?></label>
                    <select name="student_id" class="form-select" required>
                        <?php foreach ($students as $stu): ?>
                            <option value="<?= $stu['student_id'] ?>"><?= htmlspecialchars($stu['full_name']) ?> (<?= htmlspecialchars($stu['class_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'المادة:' : 'Subject:' ?></label>
                    <select name="subject_id" class="form-select" required>
                        <?php foreach ($subjects as $sub): ?>
                            <option value="<?= $sub['subject_id'] ?>"><?= htmlspecialchars($sub['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'عنوان الاختبار:' : 'Exam Title:' ?></label>
                    <input type="text" name="exam_name" class="form-control" placeholder="<?= is_rtl() ? 'مثال: اختبار منتصف الفصل 2026' : 'E.g., Midterm Exam 2026' ?>" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الدرجة' : 'Obtained' ?></label>
                    <input type="number" step="0.01" name="marks_obtained" class="form-control" placeholder="85" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الكاملة' : 'Total' ?></label>
                    <input type="number" step="0.01" name="total_marks" class="form-control" value="100" required>
                </div>
                <div class="col-12 mt-4">
                    <button type="submit" class="btn btn-primary fw-bold px-4 py-2"><i class="fa-solid fa-floppy-disk me-2"></i> <?= is_rtl() ? 'حفظ رصد الدرجة' : 'Save Grade Record' ?></button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Results Ledger Table -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
        <h5 class="fw-bold font-heading text-foreground mb-3"><i class="fa-solid fa-list-check text-success me-2"></i><?= is_rtl() ? 'سجل الدرجات المرصودة' : 'Recorded Examination Grades' ?></h5>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th><?= is_rtl() ? 'اسم الطالب' : 'Student Name' ?></th>
                        <th><?= is_rtl() ? 'عنوان الاختبار' : 'Exam Title' ?></th>
                        <th><?= is_rtl() ? 'المادة' : 'Subject' ?></th>
                        <th><?= is_rtl() ? 'الدرجة' : 'Score' ?></th>
                        <th><?= is_rtl() ? 'التقدير' : 'Grade' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $results_query = "
                        SELECT er.*, s_user.full_name AS student_name, sub.subject_name 
                        FROM exam_results er
                        JOIN students st ON er.student_id = st.student_id
                        JOIN users s_user ON st.user_id = s_user.user_id
                        JOIN subjects sub ON er.subject_id = sub.subject_id
                        ORDER BY er.created_at DESC LIMIT 20
                    ";
                    $recorded_results = $pdo->query($results_query)->fetchAll();
                    foreach ($recorded_results as $res):
                    ?>
                        <tr>
                            <td class="fw-bold text-foreground"><?= htmlspecialchars($res['student_name']) ?></td>
                            <td><?= htmlspecialchars($res['exam_name']) ?></td>
                            <td><span class="badge bg-secondary text-foreground border border-border"><?= htmlspecialchars($res['subject_name']) ?></span></td>
                            <td class="fw-bold text-primary"><?= $res['marks_obtained'] ?> / <?= $res['total_marks'] ?></td>
                            <td>
                                <span class="badge bg-<?= in_array($res['grade'], ['A+', 'A', 'B']) ? 'success' : 'warning text-dark' ?>">
                                    <?= htmlspecialchars($res['grade']) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recorded_results)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted-foreground py-4"><?= is_rtl() ? 'لا توجد نتائج اختبارات مرصودة حالياً.' : 'No examination grades recorded yet.' ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>