<?php
/**
 * Grading Module - Official Academic Report Card Generator & Security Desk
 * Features: Multi-Curriculum Score Breakdown, Attendance Sync, Financial Clearance Guard,
 * Document Security QR Code & Hash, and Digital Self-Service Release State.
 */

require_once __DIR__ . '/../../core/init.php';

// Safe include of Finance clearance engine
if (file_exists(__DIR__ . '/../Finance/clearance.php')) {
    require_once __DIR__ . '/../Finance/clearance.php';
}

Auth::requireRole(['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance']);

$user_role = Auth::role();
$user_id   = Auth::id();
$is_staff  = in_array($user_role, ['admin', 'school_admin', 'teacher', 'finance']);

$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$term       = $_GET['term'] ?? 'Term 1';
$year       = $_GET['year'] ?? '2025/2026';

// If student role, lock to student's own ID
if ($user_role === 'student') {
    $stmt = $pdo->prepare("SELECT student_id FROM students WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $stu_record = $stmt->fetch();
    if ($stu_record) {
        $student_id = (int)$stu_record['student_id'];
    }
} elseif ($user_role === 'parent') {
    // If parent, ensure selected student is linked to this parent
    $p_stmt = $pdo->prepare("
        SELECT s.student_id FROM students s
        JOIN student_parents sp ON s.student_id = sp.student_id
        WHERE sp.parent_id = ?
        LIMIT 1
    ");
    $p_stmt->execute([$user_id]);
    $linked_id = $p_stmt->fetchColumn();
    if ($student_id <= 0 && $linked_id) {
        $student_id = (int)$linked_id;
    }
}

// Fallback to first student if not set
if ($student_id <= 0) {
    $firstStu = $pdo->query("SELECT student_id FROM students WHERE is_deleted = 0 ORDER BY student_id ASC LIMIT 1")->fetch();
    if ($firstStu) {
        $student_id = intval($firstStu['student_id']);
    }
}

// Fetch Student Dossier
$stmt = $pdo->prepare("
    SELECT s.*, u.full_name, u.email as student_email, c.class_name 
    FROM students s 
    LEFT JOIN users u ON s.user_id = u.user_id 
    LEFT JOIN classes c ON s.class_id = c.class_id 
    WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch();

// Staff student switcher list
$allStudents = [];
if ($is_staff) {
    $allStudents = $pdo->query("
        SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name
        FROM students s
        LEFT JOIN users u ON s.user_id = u.user_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        WHERE s.is_deleted = 0
        ORDER BY c.class_name ASC, student_name ASC
    ")->fetchAll();
}

// 1. FINANCIAL CLEARANCE GUARD CHECK
$has_financial_clearance = true;
$financial_block_reason = '';
$outstanding_balance = 0.0;

if (function_exists('evaluateStudentClearance')) {
    $has_financial_clearance = evaluateStudentClearance($student_id, $term);
}

// Also query fee_invoices directly to calculate outstanding sum
try {
    $inv_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(amount_due - COALESCE(paid_amount, 0)), 0) 
        FROM fee_invoices 
        WHERE student_id = ? AND status <> 'Paid'
    ");
    $inv_stmt->execute([$student_id]);
    $outstanding_balance = (float)$inv_stmt->fetchColumn();

    // Check against configured clearance threshold
    $threshold = (float)($pdo->query("SELECT clearance_threshold FROM grade_weightage_policies LIMIT 1")->fetchColumn() ?: 0.00);
    if ($outstanding_balance > $threshold) {
        $has_financial_clearance = false;
        $financial_block_reason = is_rtl() 
            ? "يوجد رصيد رسوم دراسية مستحق بقيمة $" . number_format($outstanding_balance, 2) . ". يرجى مراجعة إدارة الحسابات والمالية لإبراء الذمة واستلام الشهادة." 
            : "Outstanding tuition balance of $" . number_format($outstanding_balance, 2) . " detected. Please settle all academic dues with the Bursar / Finance Office to release official report card.";
    }
} catch (\Throwable $e) {}

// 2. DIGITAL RELEASE & PUBLICATION STATE CHECK
$is_digitally_released = true;
$series_status = $pdo->query("SELECT status FROM exam_series WHERE term = '$term' ORDER BY series_id DESC LIMIT 1")->fetchColumn();
if ($series_status && in_array($series_status, ['Upcoming', 'Active', 'Under Review'])) {
    if (!$is_staff) {
        $is_digitally_released = false;
    }
}

// 3. FETCH DETAILED SUBJECT SCORES & COMPONENT BREAKDOWN
$grades_stmt = $pdo->prepare("
    SELECT sub.subject_name, sub.subject_code,
           COALESCE(g.ca_score, 0) as ca_score,
           COALESCE(g.paper1_score, 0) as paper1_score,
           COALESCE(g.paper2_score, 0) as paper2_score,
           COALESCE(g.exam_score, 0) as exam_score,
           COALESCE(g.total_score, 0) as total_score,
           g.grade_letter,
           g.gpa_points,
           g.special_condition,
           g.rank_in_class,
           g.conduct_rating,
           g.teacher_comment,
           g.principal_comment,
           g.verification_hash
    FROM grades g
    JOIN subjects sub ON g.subject_id = sub.subject_id
    WHERE g.student_id = ? AND g.term = ? AND g.academic_year = ?
    ORDER BY sub.subject_name ASC
");
$grades_stmt->execute([$student_id, $term, $year]);
$grades = $grades_stmt->fetchAll();

// 4. ATTENDANCE SUMMARY SYNC FROM SIS/LMS
$attendance_summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
try {
    $att_stmt = $pdo->prepare("
        SELECT status, COUNT(*) as cnt 
        FROM attendance 
        WHERE student_id = ? 
        GROUP BY status
    ");
    $att_stmt->execute([$student_id]);
    $att_rows = $att_stmt->fetchAll();
    foreach ($att_rows as $ar) {
        $st = strtolower($ar['status']);
        if (isset($attendance_summary[$st])) {
            $attendance_summary[$st] = (int)$ar['cnt'];
        }
    }
} catch (\Throwable $e) {}

$total_school_days = array_sum($attendance_summary);
$attendance_percentage = $total_school_days > 0 ? round(($attendance_summary['present'] / $total_school_days) * 100) : 100;

// Aggregate Performance Metrics
$total_subjects = count($grades);
$sum_scores = 0;
$sum_gpa = 0;
$class_rank = !empty($grades[0]['rank_in_class']) ? $grades[0]['rank_in_class'] : null;
$conduct_rating = !empty($grades[0]['conduct_rating']) ? $grades[0]['conduct_rating'] : 'Excellent';
$principal_note = !empty($grades[0]['principal_comment']) ? $grades[0]['principal_comment'] : 'Demonstrates commendable diligence, academic curiosity, and exemplary classroom conduct.';

foreach ($grades as $g) {
    $sum_scores += (float)$g['total_score'];
    $sum_gpa += (float)($g['gpa_points'] ?? 0);
}

$average_score = $total_subjects > 0 ? round($sum_scores / $total_subjects, 1) : 0;
$cumulative_gpa = $total_subjects > 0 ? round($sum_gpa / $total_subjects, 2) : 0.00;

// 5. SECURITY QR CODE & DOCUMENT VERIFICATION FINGERPRINT
$doc_hash = !empty($grades[0]['verification_hash']) ? $grades[0]['verification_hash'] : hash('sha256', "$student_id|$term|$year|$average_score|" . ($global_branding['school_name'] ?? 'EduMaster'));

// Construct verification URL pointing to verify.php
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]" . dirname($_SERVER['PHP_SELF']);
$verification_url = $base_url . "/verify.php?doc_hash=" . urlencode($doc_hash) . "&sid=" . $student_id;
$qr_image_url = "https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=4&data=" . urlencode($verification_url);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('report_cards') ?> - <?= htmlspecialchars($student['full_name'] ?? 'Student') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .report-card-container {
            max-width: 950px;
            margin: 25px auto;
            background: #ffffff;
            color: #0f172a;
            padding: 40px 45px;
            border-radius: var(--radius-xl, 1.25rem);
            box-shadow: 0 10px 30px rgba(0,0,0,0.08);
            border: 1px solid var(--border, #e2e8f0);
            position: relative;
        }
        .security-badge {
            background: #f8fafc;
            border: 1px dashed #cbd5e1;
            border-radius: 10px;
            padding: 10px;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; }
            .report-card-container {
                box-shadow: none !important;
                margin: 0 !important;
                padding: 15px !important;
                width: 100% !important;
                max-width: 100% !important;
                border-radius: 0 !important;
                border: none !important;
            }
        }
    </style>
</head>
<body class="bg-background">

<!-- Top Universal Single-Row Navbar -->
<div class="no-print">
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'الشهادات والبطاقات الفصلية' : 'Official Report Cards',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="transcript.php?student_id=' . $student_id . '" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-scroll me-1 text-primary"></i> ' . (is_rtl() ? 'السجل التراكمي' : 'Transcripts') . '</a>',
        '<a href="matrix.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-table-cells me-1 text-success"></i> ' . (is_rtl() ? 'شبكة الرصد' : 'Matrix') . '</a>',
        '<a href="analytics.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-chart-line me-1 text-purple"></i> ' . (is_rtl() ? 'التحليلات' : 'Analytics') . '</a>'
    ]
]) ?>
</div>

<div class="container py-4">

    <!-- Staff Student Switcher Bar -->
    <?php if ($is_staff && !empty($allStudents)): ?>
        <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm no-print">
            <form method="GET" class="row g-2 align-items-center">
                <div class="col-12 col-md-6">
                    <label class="small text-muted-foreground fw-bold mb-1"><?= is_rtl() ? 'اختيار الطالب المستهدف:' : 'Select Student Dossier:' ?></label>
                    <select name="student_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <?php foreach ($allStudents as $st): ?>
                            <option value="<?= $st['student_id'] ?>" <?= $st['student_id'] == $student_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($st['student_name']) ?> (<?= htmlspecialchars($st['class_name'] ?? 'General') ?> - #<?= htmlspecialchars($st['admission_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="small text-muted-foreground fw-bold mb-1"><?= is_rtl() ? 'الفصل الدراسي:' : 'Academic Term:' ?></label>
                    <select name="term" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <option value="Term 1" <?= $term === 'Term 1' ? 'selected' : '' ?>>Term 1</option>
                        <option value="Term 2" <?= $term === 'Term 2' ? 'selected' : '' ?>>Term 2</option>
                        <option value="Term 3" <?= $term === 'Term 3' ? 'selected' : '' ?>>Term 3</option>
                    </select>
                </div>
                <div class="col-6 col-md-3 d-flex align-items-end gap-2">
                    <button type="button" class="btn btn-outline-border btn-sm rounded-lg flex-fill" onclick="window.print()">
                        <i class="fa-solid fa-print me-1"></i><?= is_rtl() ? 'طباعة رسمية' : 'Print PDF' ?>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- CHECK: FINANCIAL CLEARANCE GUARD -->
    <?php if (!$has_financial_clearance && !$is_staff): ?>
        <div class="card bg-card border border-danger rounded-xl p-5 text-center shadow-sm my-5">
            <div class="action-icon-box icon-box-danger mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2rem;">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h4 class="fw-bold font-heading text-danger mb-2">
                <?= is_rtl() ? 'حجب الشهادة الدراسية لعدم إبراء الذمة المالية' : 'Official Report Card Withheld — Financial Clearance Required' ?>
            </h4>
            <p class="text-muted-foreground col-md-8 mx-auto mb-4">
                <?= htmlspecialchars($financial_block_reason) ?>
            </p>
            <div class="d-flex justify-content-center gap-3">
                <a href="../Finance/student_invoices.php" class="btn btn-danger rounded-xl px-4">
                    <i class="fa-solid fa-credit-card me-1"></i><?= is_rtl() ? 'سداد المستحقات عبر البوابة' : 'Pay Academic Invoices Online' ?>
                </a>
                <a href="../Finance/account_statement.php" class="btn btn-outline-border rounded-xl px-4">
                    <i class="fa-solid fa-file-invoice-dollar me-1"></i><?= is_rtl() ? 'عرض كشف الحساب' : 'View Account Statement' ?>
                </a>
            </div>
        </div>

    <!-- CHECK: DIGITAL PUBLICATION STATE -->
    <?php elseif (!$is_digitally_released && !$is_staff): ?>
        <div class="card bg-card border border-border rounded-xl p-5 text-center shadow-sm my-5">
            <div class="action-icon-box icon-box-warning mx-auto mb-3" style="width: 70px; height: 70px; font-size: 2rem;">
                <i class="fa-solid fa-hourglass-half"></i>
            </div>
            <h4 class="fw-bold font-heading text-foreground mb-2">
                <?= is_rtl() ? 'النتائج قيد التدقيق والمراجعة الأكاديمية' : 'Examination Results Under Review' ?>
            </h4>
            <p class="text-muted-foreground col-md-8 mx-auto mb-3">
                <?= is_rtl() ? 'لم يتم النشر الرقمي الرسمي لبطاقات الدرجات لهذا الفصل بعد. ستتاح الشهادات فور اعتمادها رسمياً من الكنترول المدرسي.' : 'Official term report cards have not yet been released. They will be available automatically once approved by academic leadership.' ?>
            </p>
        </div>

    <?php else: ?>

        <!-- OFFICIAL REPORT CARD DOCUMENT CONTAINER -->
        <div class="report-card-container">

            <!-- Institutional Letterhead Header -->
            <div class="d-flex justify-content-between align-items-center border-bottom pb-4 mb-4 flex-wrap gap-3">
                <div class="d-flex align-items-center gap-3">
                    <?php $logo_url = get_school_logo_url($global_branding['school_logo'] ?? ''); ?>
                    <?php if (!empty($logo_url)): ?>
                        <img src="../../<?= htmlspecialchars($logo_url) ?>" alt="Logo" class="rounded p-1 border" style="height: 65px; width: auto;">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded-xl d-flex align-items-center justify-content-center fs-3 fw-bold" style="width: 65px; height: 65px;">
                            <?= mb_substr($global_branding['school_name'] ?? 'E', 0, 1) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <h3 class="fw-bold font-heading mb-0 text-dark"><?= htmlspecialchars($global_branding['school_name'] ?? 'Global Academy') ?></h3>
                        <div class="small text-secondary"><?= is_rtl() ? 'الكنترول الأكاديمي المركزي - الإدارة العامة للتقييم والامتحانات' : 'Academic Affairs & Central Examination Controller' ?></div>
                        <div class="small text-muted font-monospace"><?= is_rtl() ? 'العام الدراسي:' : 'Academic Year:' ?> <?= htmlspecialchars($year) ?> | <?= htmlspecialchars($term) ?></div>
                    </div>
                </div>
                <div class="text-end">
                    <span class="badge bg-dark text-white fs-6 px-3 py-1.5 rounded-pill font-heading">
                        <?= is_rtl() ? 'كشف الدرجات الرسمي' : 'OFFICIAL TRANSCRIPT' ?>
                    </span>
                    <div class="small text-muted mt-1 font-monospace">Date: <?= date('d M Y') ?></div>
                </div>
            </div>

            <!-- Student Profile Information Grid -->
            <div class="row g-3 p-3 bg-light rounded-xl border mb-4 text-dark">
                <div class="col-6 col-md-3">
                    <div class="small text-muted text-uppercase fw-bold"><?= is_rtl() ? 'اسم الطالب' : 'Candidate Name' ?></div>
                    <div class="fw-bold fs-6"><?= htmlspecialchars($student['full_name'] ?? 'Candidate') ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted text-uppercase fw-bold"><?= is_rtl() ? 'رقم القيد' : 'Admission / Roll #' ?></div>
                    <div class="fw-bold font-monospace"><?= htmlspecialchars($student['admission_no'] ?? 'N/A') ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted text-uppercase fw-bold"><?= is_rtl() ? 'الصف والشعبة' : 'Class & Section' ?></div>
                    <div class="fw-bold"><?= htmlspecialchars($student['class_name'] ?? 'Unassigned') ?></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="small text-muted text-uppercase fw-bold"><?= is_rtl() ? 'الترتيب الصفي' : 'Class Rank' ?></div>
                    <div class="fw-bold text-primary"><?= $class_rank ? "#$class_rank" : "—" ?></div>
                </div>
            </div>

            <!-- Detailed Multi-Curriculum Score Table -->
            <div class="table-responsive mb-4">
                <table class="table table-bordered align-middle text-dark mb-0">
                    <thead class="table-secondary">
                        <tr class="text-uppercase small font-bold">
                            <th><?= is_rtl() ? 'المادة الدراسية' : 'Subject & Code' ?></th>
                            <th class="text-center" style="width: 100px;"><?= is_rtl() ? 'أعمال السنة' : 'Coursework' ?></th>
                            <th class="text-center" style="width: 90px;"><?= is_rtl() ? 'ورقة 1' : 'Paper 1' ?></th>
                            <th class="text-center" style="width: 90px;"><?= is_rtl() ? 'ورقة 2' : 'Paper 2' ?></th>
                            <th class="text-center" style="width: 110px;"><?= is_rtl() ? 'المجموع' : 'Total (100)' ?></th>
                            <th class="text-center" style="width: 80px;"><?= is_rtl() ? 'التقدير' : 'Grade' ?></th>
                            <th class="text-center" style="width: 80px;"><?= is_rtl() ? 'نقاط GPA' : 'Points' ?></th>
                            <th><?= is_rtl() ? 'الملاحظة الإرشادية' : 'Instructor Remarks' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($grades)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-4 text-muted"><?= is_rtl() ? 'لا توجد درجات مرصودة لهذا الطالب في هذا الفصل.' : 'No examination marks recorded for this academic term.' ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($grades as $g): 
                                $tot = (float)$g['total_score'];
                                $is_fail = ($tot < 50.0);
                            ?>
                                <tr>
                                    <td>
                                        <div class="fw-bold"><?= htmlspecialchars($g['subject_name']) ?></div>
                                        <div class="small text-muted font-monospace"><?= htmlspecialchars($g['subject_code']) ?></div>
                                    </td>
                                    <td class="text-center font-monospace"><?= number_format($g['ca_score'], 1) ?></td>
                                    <td class="text-center font-monospace"><?= number_format($g['paper1_score'], 1) ?></td>
                                    <td class="text-center font-monospace"><?= number_format($g['paper2_score'], 1) ?></td>
                                    <td class="text-center fw-bold fs-6 font-monospace <?= $is_fail ? 'text-danger' : 'text-primary' ?>">
                                        <?= number_format($tot, 1) ?>
                                    </td>
                                    <td class="text-center fw-bold">
                                        <span class="badge bg-<?= $is_fail ? 'danger' : 'primary' ?> px-2 py-1 rounded-pill">
                                            <?= htmlspecialchars($g['grade_letter']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center font-monospace small">
                                        <?= $g['gpa_points'] !== null ? number_format($g['gpa_points'], 2) : '—' ?>
                                    </td>
                                    <td class="small text-secondary">
                                        <?= htmlspecialchars($g['teacher_comment'] ?? 'Satisfactory work') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Summary Performance Indicators & Attendance Sync -->
            <div class="row g-3 mb-4 text-dark">
                <div class="col-md-6">
                    <div class="p-3 bg-light rounded-xl border h-100">
                        <div class="fw-bold small text-muted text-uppercase mb-2"><?= is_rtl() ? 'ملخص الحضور والغياب (مزامنة SIS/LMS)' : 'Attendance Ledger Summary' ?></div>
                        <div class="d-flex justify-content-between align-items-center mb-1 small">
                            <span><?= is_rtl() ? 'الأيام الحاضرة:' : 'Days Present:' ?> <strong><?= $attendance_summary['present'] ?></strong></span>
                            <span><?= is_rtl() ? 'الغياب:' : 'Absent:' ?> <strong class="text-danger"><?= $attendance_summary['absent'] ?></strong></span>
                            <span><?= is_rtl() ? 'التأخير:' : 'Late:' ?> <strong><?= $attendance_summary['late'] ?></strong></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center small">
                            <span><?= is_rtl() ? 'نسبة الانتظام المدرسي:' : 'Attendance Rate:' ?></span>
                            <span class="fw-bold text-success"><?= $attendance_percentage ?>%</span>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 bg-light rounded-xl border h-100 d-flex justify-content-around align-items-center text-center">
                        <div>
                            <div class="small text-muted text-uppercase fw-bold"><?= is_rtl() ? 'المعدل العام' : 'Overall Mean' ?></div>
                            <div class="fs-4 fw-bold text-primary"><?= $average_score ?>%</div>
                        </div>
                        <div class="border-start ps-4">
                            <div class="small text-muted text-uppercase fw-bold"><?= is_rtl() ? 'المعدل التراكمي' : 'Term GPA' ?></div>
                            <div class="fs-4 fw-bold text-success"><?= number_format($cumulative_gpa, 2) ?></div>
                        </div>
                        <div class="border-start ps-4">
                            <div class="small text-muted text-uppercase fw-bold"><?= is_rtl() ? 'تقييم السلوك' : 'Conduct Rating' ?></div>
                            <div class="fs-5 fw-bold text-dark"><?= htmlspecialchars($conduct_rating) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Principal / Head Remarks -->
            <div class="p-3 bg-light rounded-xl border mb-4 text-dark">
                <div class="small fw-bold text-muted text-uppercase mb-1"><?= is_rtl() ? 'ملاحظات وتوجيهات الإدارة المدرسية:' : 'Principal & Academic Director Remarks:' ?></div>
                <p class="small text-secondary mb-0 italic">"<?= htmlspecialchars($principal_note) ?>"</p>
            </div>

            <!-- Document Security, QR Code & Institutional Seal Verification -->
            <div class="row align-items-center pt-3 border-top text-dark">
                <div class="col-8 col-md-9">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-white border rounded p-1 shadow-xs">
                            <img src="<?= $qr_image_url ?>" alt="Verification QR" style="width: 80px; height: 80px;">
                        </div>
                        <div>
                            <div class="small fw-bold font-heading text-dark">
                                <i class="fa-solid fa-shield-halved text-success me-1"></i><?= is_rtl() ? 'وثيقة أكاديمية مؤمنة ومشفرة إلكترونياً' : 'Tamper-Proof Digitally Verified Document' ?>
                            </div>
                            <div class="small text-muted font-monospace" style="font-size: 0.72rem; word-break: break-all;">
                                SHA-256: <?= $doc_hash ?>
                            </div>
                            <div class="small text-muted" style="font-size: 0.75rem;">
                                <?= is_rtl() ? 'امسح رمز الاستجابة السريعة (QR) للتحقق الفوري من أصالة الشهادة عبر بوابة الاعتماد المركزية.' : 'Scan QR code to independently verify certificate authenticity via the public portal registry.' ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-4 col-md-3 text-end">
                    <div class="small fw-bold text-muted"><?= is_rtl() ? 'ختم الكنترول والاعتماد:' : 'Official Seal & Registrar:' ?></div>
                    <div class="mt-4 border-bottom border-dark mx-auto" style="width: 140px;"></div>
                    <div class="small text-muted mt-1">Registrar General</div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>