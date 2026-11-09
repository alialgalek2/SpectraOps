<?php
/**
 * Administration Module - Daily Student Attendance Desk
 * Daily roll call, 1-Click Mark All Present, threshold alerts for exam eligibility (< 75%).
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher']);

$msg = "";
$msg_type = "info";

$selected_class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$attendance_date   = $_GET['date'] ?? date('Y-m-d');

// Fallback to first class
if ($selected_class_id <= 0) {
    $first_class = $pdo->query("SELECT class_id FROM classes ORDER BY grade_level ASC, class_name ASC LIMIT 1")->fetchColumn();
    $selected_class_id = $first_class ? intval($first_class) : 0;
}

// Handle Attendance Save Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    $class_id = intval($_POST['class_id'] ?? 0);
    $att_date = $_POST['date'] ?? date('Y-m-d');
    $statuses = $_POST['attendance'] ?? []; // student_id => status
    $remarks  = $_POST['remarks'] ?? [];

    if ($class_id > 0 && !empty($statuses)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO student_attendance 
                (student_id, class_id, date, status, remarks, recorded_by) 
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                status = VALUES(status), remarks = VALUES(remarks), recorded_by = VALUES(recorded_by)
            ");

            $saved_count = 0;
            foreach ($statuses as $sid => $st) {
                $rem = trim($remarks[$sid] ?? '');
                $stmt->execute([(int)$sid, $class_id, $att_date, $st, $rem, Auth::id()]);
                $saved_count++;
            }

            $msg = (is_rtl() ? "تم حفظ كشف الحضور والغياب بنجاح لعدد $saved_count طالباً!" : "Daily attendance recorded successfully for $saved_count students!");
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Error saving attendance: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch Classes for Dropdown
$classes = $pdo->query("SELECT class_id, class_name, grade_level FROM classes ORDER BY grade_level ASC, class_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Students in Selected Class
$students_stmt = $pdo->prepare("
    SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
           sa.status as today_status, sa.remarks as today_remarks
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN student_attendance sa ON s.student_id = sa.student_id AND sa.date = ?
    WHERE s.class_id = ? AND s.is_deleted = 0
    ORDER BY student_name ASC
");
$students_stmt->execute([$attendance_date, $selected_class_id]);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Historical Attendance Stats per Student (Exam Eligibility < 75%)
$eligibility_map = [];
try {
    $stats_stmt = $pdo->prepare("
        SELECT student_id,
               COUNT(*) as total_days,
               SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days,
               SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) as late_days,
               SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) as unexcused_days,
               SUM(CASE WHEN status = 'Excused' THEN 1 ELSE 0 END) as excused_days
        FROM student_attendance
        WHERE class_id = ?
        GROUP BY student_id
    ");
    $stats_stmt->execute([$selected_class_id]);
    $stats_rows = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($stats_rows as $sr) {
        $tot = (int)$sr['total_days'];
        $pres = (int)$sr['present_days'];
        $pct = $tot > 0 ? round(($pres / $tot) * 100, 1) : 100.0;
        $eligibility_map[$sr['student_id']] = [
            'total'      => $tot,
            'rate'       => $pct,
            'is_at_risk' => ($pct < 75.0 && $tot >= 5) // At risk of exam disqualification
        ];
    }
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'كشف الحضور اليومي وأهلية الامتحانات' : 'Daily Student Attendance Desk' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .att-radio:checked + label.btn-present { background-color: #10b981 !important; color: #fff !important; border-color: #10b981 !important; }
        .att-radio:checked + label.btn-late { background-color: #f59e0b !important; color: #fff !important; border-color: #f59e0b !important; }
        .att-radio:checked + label.btn-excused { background-color: #0284c7 !important; color: #fff !important; border-color: #0284c7 !important; }
        .att-radio:checked + label.btn-absent { background-color: #ef4444 !important; color: #fff !important; border-color: #ef4444 !important; }
    </style>
</head>
<body class="bg-background text-foreground">

    <!-- Top Universal Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'كشف الحضور والغياب المدرسي' : 'Daily Student Attendance Desk',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="behavior.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-shield-heart me-1 text-warning"></i> ' . (is_rtl() ? 'السلوك والإنضباط' : 'Discipline') . '</a>',
            '<a href="student_dossier.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-id-card me-1 text-info"></i> ' . (is_rtl() ? 'الملف الشامل' : 'Dossier') . '</a>',
            '<a href="../Grading/index.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-purple"></i> ' . (is_rtl() ? 'الامتحانات' : 'Exams') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-calendar-check text-success me-2"></i><?= is_rtl() ? 'مكتب رصد الحضور اليومي وضوابط الأهلية' : 'Daily Student Attendance & Eligibility Desk' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'رصد الحضور اليومي، التنبيه التلقائي عند تجاوز الغياب للحد المسموح (< 75%) المؤدي للحرمان من الامتحانات.' : 'Real-time class roll call with automated threshold warnings for students risking exam disqualification due to attendance.' ?>
                </p>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-success btn-sm rounded-lg" onclick="markAllPresent()">
                    <i class="fa-solid fa-check-double me-1"></i><?= is_rtl() ? 'تحديد الكل حاضر' : 'Mark All Present' ?>
                </button>
            </div>
        </div>

        <?php if (!empty($msg)): ?>
            <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-info me-2 fs-5"></i>
                <div><?= htmlspecialchars($msg) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Class & Date Selection Form -->
        <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي / الشعبة' : 'Class Section' ?></label>
                    <select name="class_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['class_id'] ?>" <?= $c['class_id'] == $selected_class_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['class_name']) ?> (Grade <?= $c['grade_level'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'تاريخ الكشف' : 'Attendance Date' ?></label>
                    <input type="date" name="date" class="form-control form-control-sm bg-background border-border text-foreground" value="<?= htmlspecialchars($attendance_date) ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary btn-sm w-100 rounded-lg">
                        <i class="fa-solid fa-arrows-rotate me-1"></i><?= is_rtl() ? 'تحميل الكشف' : 'Load Roster' ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Attendance Entry Form -->
        <form method="POST">
            <input type="hidden" name="action" value="save_attendance">
            <input type="hidden" name="class_id" value="<?= $selected_class_id ?>">
            <input type="hidden" name="date" value="<?= htmlspecialchars($attendance_date) ?>">

            <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold font-heading mb-0 text-foreground">
                        <i class="fa-solid fa-users me-2 text-primary"></i><?= is_rtl() ? 'سجل طلاب الشعبة' : 'Class Attendance Register' ?> (<?= count($students) ?> Students)
                    </h6>
                    <button type="submit" class="btn btn-primary btn-sm rounded-lg px-4 fw-bold">
                        <i class="fa-solid fa-floppy-disk me-1"></i><?= is_rtl() ? 'حفظ الحضور والغياب' : 'Save Attendance' ?>
                    </button>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-secondary">
                                <tr class="text-muted-foreground small text-uppercase font-bold">
                                    <th class="ps-3" style="width: 280px;"><?= is_rtl() ? 'الطالب ورقم القيد' : 'Student & Admission #' ?></th>
                                    <th class="text-center" style="width: 140px;"><?= is_rtl() ? 'معدل الحضور' : 'Term Rate' ?></th>
                                    <th class="text-center" style="width: 320px;"><?= is_rtl() ? 'حالة الحضور اليوم' : 'Today Status' ?></th>
                                    <th class="pe-3"><?= is_rtl() ? 'ملاحظات / عذر الغياب' : 'Remarks / Reason' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($students)): ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا يوجد طلاب مسجلون في هذا الفصل.' : 'No students found in this class section.' ?></td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($students as $st): 
                                        $sid = $st['student_id'];
                                        $curr = $st['today_status'] ?: 'Present';
                                        $rate_info = $eligibility_map[$sid] ?? ['rate' => 100, 'is_at_risk' => false];
                                    ?>
                                        <tr class="<?= $rate_info['is_at_risk'] ? 'bg-danger-subtle bg-opacity-25' : '' ?>">
                                            <td class="ps-3">
                                                <div class="fw-bold text-foreground"><?= htmlspecialchars($st['student_name']) ?></div>
                                                <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($st['admission_no']) ?></div>
                                                <?php if ($rate_info['is_at_risk']): ?>
                                                    <span class="badge bg-danger text-white mt-1" style="font-size: 0.68rem;">
                                                        <i class="fa-solid fa-triangle-exclamation me-1"></i>Exam Eligibility Risk (&lt; 75%)
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="fw-bold font-monospace fs-6 <?= $rate_info['is_at_risk'] ? 'text-danger' : 'text-success' ?>">
                                                    <?= $rate_info['rate'] ?>%
                                                </span>
                                                <div class="small text-muted-foreground" style="font-size: 0.72rem;"><?= is_rtl() ? 'نسبة الانتظام' : 'Term Attendance' ?></div>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm w-100" role="group">
                                                    <input type="radio" class="btn-check att-radio" name="attendance[<?= $sid ?>]" id="pres_<?= $sid ?>" value="Present" <?= $curr === 'Present' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-border btn-present" for="pres_<?= $sid ?>"><?= is_rtl() ? 'حاضر' : 'Present' ?></label>

                                                    <input type="radio" class="btn-check att-radio" name="attendance[<?= $sid ?>]" id="late_<?= $sid ?>" value="Late" <?= $curr === 'Late' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-border btn-late" for="late_<?= $sid ?>"><?= is_rtl() ? 'متأخر' : 'Late' ?></label>

                                                    <input type="radio" class="btn-check att-radio" name="attendance[<?= $sid ?>]" id="exc_<?= $sid ?>" value="Excused" <?= $curr === 'Excused' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-border btn-excused" for="exc_<?= $sid ?>"><?= is_rtl() ? 'مأذون' : 'Excused' ?></label>

                                                    <input type="radio" class="btn-check att-radio" name="attendance[<?= $sid ?>]" id="abs_<?= $sid ?>" value="Absent" <?= $curr === 'Absent' ? 'checked' : '' ?>>
                                                    <label class="btn btn-outline-border btn-absent" for="abs_<?= $sid ?>"><?= is_rtl() ? 'غائب' : 'Absent' ?></label>
                                                </div>
                                            </td>
                                            <td class="pe-3">
                                                <input type="text" name="remarks[<?= $sid ?>]" class="form-control form-control-sm bg-background border-border text-foreground" value="<?= htmlspecialchars($st['today_remarks'] ?? '') ?>" placeholder="Notes...">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-card border-top border-border py-3 text-end">
                    <button type="submit" class="btn btn-primary fw-bold px-4">
                        <i class="fa-solid fa-floppy-disk me-1"></i><?= is_rtl() ? 'حفظ كشف الحضور' : 'Save Attendance Register' ?>
                    </button>
                </div>
            </div>
        </form>

        <?= render_platform_footer() ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function markAllPresent() {
            document.querySelectorAll('input[type="radio"][value="Present"]').forEach(function(rb) {
                rb.checked = true;
            });
        }
    </script>
</body>
</html>
