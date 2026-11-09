<?php
/**
 * Teacher 40/60 Assessment & Exam Grading Grid
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';
require_once __DIR__ . '/../../licensing/license_helper.php';

Auth::requireRole(['teacher', 'admin']);

// Verify License
$licCheck = checkSystemLicense($pdo);
if (!$licCheck['valid']) {
    die("<h3 style='color:red;'>System Access Restricted: " . $licCheck['reason'] . "</h3>");
}

$message = "";
$msg_type = "info";

// Teacher / Class / Subject Selection
$user_id    = Auth::id();
$class_id   = intval($_GET['class_id'] ?? 1);
$subject_id = intval($_GET['subject_id'] ?? 1);
$term       = $_GET['term'] ?? 'Term 1';
$year       = $_GET['year'] ?? '2025/2026';

// Ensure subjects table has a record
$pdo->exec("INSERT INTO subjects (subject_id, subject_name, subject_code) VALUES (1, 'Mathematics', 'MATH101') ON DUPLICATE KEY UPDATE subject_name='Mathematics'");

// Handle Batch Grade Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    try {
        $pdo->beginTransaction();

        $ca_scores   = $_POST['ca_score'] ?? [];
        $exam_scores = $_POST['exam_score'] ?? [];

        foreach ($ca_scores as $student_id => $ca_val) {
            $ca_val   = ($ca_val === '') ? NULL : (float)$ca_val;
            $exam_val = isset($exam_scores[$student_id]) && $exam_scores[$student_id] !== '' ? (float)$exam_scores[$student_id] : NULL;

            // Compute total score and letter grade
            $total_score = null;
            $grade_letter = 'Pending';

            if ($ca_val !== NULL && $exam_val !== NULL) {
                $total_score = $ca_val + $exam_val;
                if ($total_score >= 90) $grade_letter = 'A+';
                elseif ($total_score >= 85) $grade_letter = 'A';
                elseif ($total_score >= 80) $grade_letter = 'B+';
                elseif ($total_score >= 75) $grade_letter = 'B';
                elseif ($total_score >= 70) $grade_letter = 'C+';
                elseif ($total_score >= 65) $grade_letter = 'C';
                elseif ($total_score >= 50) $grade_letter = 'D';
                else $grade_letter = 'F';
            } elseif ($ca_val !== NULL) {
                $total_score = $ca_val;
            } elseif ($exam_val !== NULL) {
                $total_score = $exam_val;
            }

            // Check if record exists
            $checkStmt = $pdo->prepare("SELECT grade_id FROM grades WHERE student_id = ? AND subject_id = ? AND term = ? AND academic_year = ?");
            $checkStmt->execute([$student_id, $subject_id, $term, $year]);
            $existing = $checkStmt->fetch();

            if ($existing) {
                $updStmt = $pdo->prepare("UPDATE grades SET ca_score = ?, exam_score = ?, total_score = ?, grade_letter = ? WHERE grade_id = ?");
                $updStmt->execute([$ca_val, $exam_val, $total_score, $grade_letter, $existing['grade_id']]);
            } else {
                $insStmt = $pdo->prepare("INSERT INTO grades (student_id, subject_id, class_id, ca_score, exam_score, total_score, grade_letter, term, academic_year) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insStmt->execute([$student_id, $subject_id, $class_id, $ca_val, $exam_val, $total_score, $grade_letter, $term, $year]);
            }
        }

        $pdo->commit();
        $message = is_rtl() ? "تم حفظ وتحديث درجات الطلاب بنجاح!" : "Student assessment marks updated successfully!";
        $msg_type = "success";
    } catch (\PDOException $e) {
        $pdo->rollBack();
        $message = "Database error: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// Fetch Classes and Subjects
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll();

// Fetch Students in Selected Class + Existing Grades
$query = "
    SELECT 
        s.student_id,
        s.admission_no,
        COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
        g.ca_score,
        g.exam_score,
        g.total_score,
        g.grade_letter
    FROM students s
    LEFT JOIN users u ON s.user_id = u.user_id
    LEFT JOIN grades g ON s.student_id = g.student_id 
        AND g.subject_id = ? 
        AND g.term = ?
    WHERE s.class_id = ? AND s.is_deleted = 0
    ORDER BY student_name ASC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$subject_id, $term, $class_id]);
$students = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('grades') ?> - <?= is_rtl() ? 'رصد وتحديث درجات الطلاب' : 'Teacher Grading Sheet (40/60 Assessment)' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .score-input { width: 90px; font-weight: bold; text-align: center; }
    </style>
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'رصد وتحديث درجات الطلاب' : 'Student Grade Entry & Evaluation',
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container-fluid px-4 py-4">
    <?php if ($message): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header Filter Box -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي:' : 'Class / Section:' ?></label>
                <select name="class_id" class="form-select fw-bold" onchange="this.form.submit()">
                    <?php foreach ($classes as $c): ?>
                        <option value="<?= $c['class_id'] ?>" <?= $c['class_id'] == $class_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['class_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'المادة الدراسية:' : 'Subject:' ?></label>
                <select name="subject_id" class="form-select fw-bold" onchange="this.form.submit()">
                    <?php foreach ($subjects as $sub): ?>
                        <option value="<?= $sub['subject_id'] ?>" <?= $sub['subject_id'] == $subject_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($sub['subject_name']) ?> (<?= htmlspecialchars($sub['subject_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الفصل / الترم:' : 'Term / Semester:' ?></label>
                <select name="term" class="form-select fw-bold" onchange="this.form.submit()">
                    <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>><?= is_rtl() ? 'الفصل الأول (Term 1)' : 'Term 1' ?></option>
                    <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>><?= is_rtl() ? 'الفصل الثاني (Term 2)' : 'Term 2' ?></option>
                    <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>><?= is_rtl() ? 'الفصل الثالث (Term 3)' : 'Term 3' ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary fw-bold w-100 py-2">
                    <i class="fa-solid fa-filter me-1"></i> <?= is_rtl() ? 'تصفية كشف الدرجات' : 'Filter Grading Sheet' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Student Grading Sheet -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h5 class="fw-bold mb-0 text-foreground">
                <i class="fa-solid fa-chart-simple text-purple me-2"></i><?= is_rtl() ? 'مصفوفة التقييم المستمر والاختبارات النهائية' : 'Continuous Assessment & Final Exam Matrix' ?> (<?= count($students) ?> <?= is_rtl() ? 'طلاب' : 'Students' ?>)
            </h5>
            <span class="badge bg-secondary text-foreground border border-border"><?= is_rtl() ? 'التوزيع: 40% أعمال سنة + 60% اختبار نهائي' : 'Weighting: 40% Coursework + 60% Final Exam' ?></span>
        </div>

        <form method="POST">
            <input type="hidden" name="save_grades" value="1">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%;"><?= is_rtl() ? 'رقم القيد' : 'Admission No' ?></th>
                            <th style="width: 30%;"><?= is_rtl() ? 'اسم الطالب' : 'Student Full Name' ?></th>
                            <th style="width: 15%;" class="text-center"><?= is_rtl() ? 'أعمال السنة (40)' : 'CA Mark (Max 40)' ?></th>
                            <th style="width: 15%;" class="text-center"><?= is_rtl() ? 'الامتحان (60)' : 'Exam Mark (Max 60)' ?></th>
                            <th style="width: 15%;" class="text-center"><?= is_rtl() ? 'المجموع (100)' : 'Total Score (100)' ?></th>
                            <th style="width: 10%;" class="text-center"><?= is_rtl() ? 'التقدير' : 'Letter Grade' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted-foreground py-4"><?= is_rtl() ? 'لا يوجد طلاب مسجلين في هذا الفصل حالياً.' : 'No students enrolled in this class.' ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $st): ?>
                                <?php 
                                $tot = $st['total_score'] !== null ? floatval($st['total_score']) : null;
                                ?>
                                <tr>
                                    <td><code>#<?= htmlspecialchars($st['admission_no'] ?: $st['student_id']) ?></code></td>
                                    <td class="fw-bold text-foreground"><?= htmlspecialchars($st['student_name']) ?></td>
                                    <td class="text-center">
                                        <input type="number" step="0.5" min="0" max="40" 
                                               class="form-control form-control-sm score-input mx-auto"
                                               name="ca_score[<?= $st['student_id'] ?>]" 
                                               value="<?= htmlspecialchars($st['ca_score'] ?? '') ?>"
                                               placeholder="0-40">
                                    </td>
                                    <td class="text-center">
                                        <input type="number" step="0.5" min="0" max="60" 
                                               class="form-control form-control-sm score-input mx-auto"
                                               name="exam_score[<?= $st['student_id'] ?>]" 
                                               value="<?= htmlspecialchars($st['exam_score'] ?? '') ?>"
                                               placeholder="0-60">
                                    </td>
                                    <td class="text-center fw-bold fs-6 text-primary">
                                        <?= $tot !== null ? $tot : '—' ?>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-<?= in_array($st['grade_letter'], ['A+', 'A', 'B+', 'B']) ? 'success' : ($st['grade_letter'] === 'F' ? 'danger' : 'warning text-dark') ?>">
                                            <?= $st['grade_letter'] ?? 'Pending' ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 text-end">
                <button type="submit" class="btn btn-success fw-bold px-4 py-2 shadow-sm">
                    <i class="fa-solid fa-floppy-disk me-2"></i> <?= is_rtl() ? 'حفظ واحتساب درجات جميع الطلاب' : 'Save & Calculate All Marks' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>