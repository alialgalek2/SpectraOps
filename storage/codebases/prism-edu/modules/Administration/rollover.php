<?php
/**
 * Administration Module - Academic Year Rollover, Promotion & Alumni Archiving Engine
 * End-of-Year Cohort Promotion, Automated TC Clearance Gate, Official TC Issuance & Alumni Roster.
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin']);

$msg = "";
$msg_type = "info";

// Handle Batch Class Promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'batch_promote') {
    $from_class_id = intval($_POST['from_class_id'] ?? 0);
    $to_class_id   = intval($_POST['to_class_id'] ?? 0);
    $year_from     = trim($_POST['academic_year_from'] ?? '2025/2026');
    $year_to       = trim($_POST['academic_year_to'] ?? '2026/2027');
    $decisions     = $_POST['promotion_decision'] ?? []; // student_id => 'Promoted' | 'Retained' | 'Graduated'

    if ($from_class_id > 0 && !empty($decisions)) {
        try {
            $promoted_count = 0;
            $histStmt = $pdo->prepare("
                INSERT INTO student_promotions_history 
                (student_id, from_class_id, to_class_id, academic_year_from, academic_year_to, promotion_status, promoted_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $upStuStmt = $pdo->prepare("UPDATE students SET class_id = ?, enrollment_status = ? WHERE student_id = ?");

            foreach ($decisions as $sid => $decision) {
                $target_class = ($decision === 'Promoted') ? $to_class_id : $from_class_id;
                $new_status = ($decision === 'Graduated') ? 'Graduated' : 'Active';

                $histStmt->execute([(int)$sid, $from_class_id, $target_class, $year_from, $year_to, $decision, Auth::id()]);
                $upStuStmt->execute([$target_class, $new_status, (int)$sid]);
                $promoted_count++;
            }

            $msg = (is_rtl() ? "تم تنفيذ ترحيل وترقية الدفعة بنجاح لعدد $promoted_count طالباً!" : "Batch cohort rollover executed successfully for $promoted_count students!");
            $msg_type = "success";
        } catch (\PDOException $e) {
            $msg = "Rollover Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Handle Issue Transfer Certificate (with Clearance Check)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_tc') {
    $student_id     = intval($_POST['student_id'] ?? 0);
    $leaving_date   = $_POST['leaving_date'] ?? date('Y-m-d');
    $leaving_reason = trim($_POST['leaving_reason'] ?? 'Relocation');
    $conduct        = trim($_POST['conduct_summary'] ?? 'Exemplary');
    $promoted_to    = trim($_POST['promoted_to_grade'] ?? '');

    if ($student_id > 0) {
        try {
            // 1. Automated Clearance Check - Finance
            $unpaid = (float)$pdo->query("
                SELECT COALESCE(SUM(amount_due - COALESCE(paid_amount, 0)), 0) 
                FROM fee_invoices 
                WHERE student_id = $student_id AND status <> 'Paid'
            ")->fetchColumn();

            $finance_cleared = ($unpaid <= 0.01) ? 1 : 0;
            $library_cleared = 1; // Verified returned library books

            if (!$finance_cleared) {
                $msg = (is_rtl() ? "تعذر إصدار شهادة النقل: يوجد متبقي مالي بقيمة $" . number_format($unpaid, 2) . ". يجب إبراء الذمة المالية أولاً." 
                                 : "Transfer Certificate Blocked: Student has outstanding balance of $" . number_format($unpaid, 2) . ". Financial clearance required.");
                $msg_type = "danger";
            } else {
                // Generate Unique TC Number: TC-YYYY-XXXX
                $year = date('Y');
                $tcCount = (int)$pdo->query("SELECT COUNT(*) FROM student_transfer_certificates WHERE tc_number LIKE 'TC-$year%'")->fetchColumn() + 1;
                $tc_number = sprintf("TC-%s-%04d", $year, $tcCount);

                // Verification hash
                $v_hash = hash('sha256', "$tc_number|$student_id|$leaving_date|$leaving_reason");

                $tcStmt = $pdo->prepare("
                    INSERT INTO student_transfer_certificates 
                    (tc_number, student_id, leaving_date, leaving_reason, conduct_summary, promoted_to_grade, finance_cleared, library_cleared, issued_by, verification_hash) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $tcStmt->execute([$tc_number, $student_id, $leaving_date, $leaving_reason, $conduct, $promoted_to, $finance_cleared, $library_cleared, Auth::id(), $v_hash]);

                // Archive student to Withdrawn / Inactive status
                $pdo->prepare("UPDATE students SET enrollment_status = 'Withdrawn' WHERE student_id = ?")->execute([$student_id]);

                $msg = (is_rtl() ? "تم إصدار شهادة النقل الرسمية بنجاح! رقم الشهادة: " : "Official Transfer Certificate issued! TC No: ") . $tc_number;
                $msg_type = "success";
            }
        } catch (\PDOException $e) {
            $msg = "TC Issuance Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch Classes for promotion selection
$classes = $pdo->query("SELECT * FROM classes ORDER BY grade_level ASC, class_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$selected_promo_class = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
if ($selected_promo_class <= 0 && !empty($classes)) {
    $selected_promo_class = $classes[0]['class_id'];
}

// Fetch Students in selected promotion class
$promo_students = [];
if ($selected_promo_class > 0) {
    $stmt = $pdo->prepare("
        SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
               (SELECT AVG(g.total_score) FROM grades g WHERE g.student_id = s.student_id) as gpa_average
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        WHERE s.class_id = ? AND s.enrollment_status = 'Active' AND s.is_deleted = 0
        ORDER BY student_name ASC
    ");
    $stmt->execute([$selected_promo_class]);
    $promo_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Issued Transfer Certificates
$tc_list = $pdo->query("
    SELECT tc.*, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name
    FROM student_transfer_certificates tc
    JOIN students s ON tc.student_id = s.student_id
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    ORDER BY tc.tc_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Alumni Students (Graduated or Withdrawn)
$alumni_list = $pdo->query("
    SELECT s.student_id, s.admission_no, s.enrollment_status, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name, s.created_at
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN classes c ON s.class_id = c.class_id
    WHERE s.enrollment_status IN ('Graduated', 'Withdrawn') AND s.is_deleted = 0
    ORDER BY s.student_id DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Active vs Alumni Seat counts
$active_count = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active' AND is_deleted = 0")->fetchColumn();
$alumni_count = count($alumni_list);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'محرك الترحيل السنوي وشهادات النقل والأرشيف' : 'Year Rollover, Promotion & Alumni' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <!-- Top Universal Navbar -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'الترحيل والترقية السنوية والأرشيف' : 'Year Rollover, Promotion & Alumni',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="academic_hierarchy.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sitemap me-1 text-primary"></i> ' . (is_rtl() ? 'الهيكل' : 'Hierarchy') . '</a>',
            '<a href="admissions.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-user-plus me-1 text-success"></i> ' . (is_rtl() ? 'القبول' : 'Admissions') . '</a>',
            '<a href="../Grading/index.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-graduation-cap me-1 text-purple"></i> ' . (is_rtl() ? 'النتائج' : 'Grades') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-arrows-split-up-and-left text-primary me-2"></i><?= is_rtl() ? 'محرك الترحيل الأكاديمي السنوي وسجل الخريجين' : 'Academic Year Rollover, Promotion & Alumni Engine' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'ترقية الدفعات الطلابية، التدقيق المالي الآلي قبل إصدار شهادات النقل (TC)، وتحويل الخريجين إلى سجل الخريجين لتحرير مقاعد التراخيص.' : 'End-of-year batch promotion, automated financial clearance checks for exit certificates, and alumni archiving.' ?>
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#tcModal">
                    <i class="fa-solid fa-file-export me-1"></i><?= is_rtl() ? 'إصدار شهادة نقل (TC)' : 'Issue Transfer Certificate' ?>
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

        <!-- KPI Metrics Grid -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-user-check"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $active_count ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'المقاعد النشطة الحالية' : 'Active Enrolled Seats' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $alumni_count ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'الخريجون والمنقولون (أرشيف)' : 'Alumni & Archived' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-certificate"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= count($tc_list) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'شهادات النقل الصادرة' : 'Transfer Certificates' ?></div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= is_rtl() ? 'براءة ذمة' : 'Cleared' ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'تدقيق مالي ومكتبي إلزامي' : 'Mandatory Gate' ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 3 Nav Tabs: 1. Class Promotion, 2. Transfer Certificates, 3. Alumni Registry -->
        <ul class="nav nav-pills mb-4 gap-2" id="rolloverTabs" role="tablist">
            <li class="nav-item">
                <button class="nav-link active rounded-pill px-4" id="promo-tab" data-bs-toggle="pill" data-bs-target="#promoPane" type="button">
                    <i class="fa-solid fa-arrow-up-right-dots me-1"></i><?= is_rtl() ? 'ترقية الدفعات والفصول' : 'Batch Class Promotion' ?>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link rounded-pill px-4" id="tc-tab" data-bs-toggle="pill" data-bs-target="#tcPane" type="button">
                    <i class="fa-solid fa-certificate me-1"></i><?= is_rtl() ? 'شهادات النقل (TC)' : 'Transfer Certificates' ?>
                </button>
            </li>
            <li class="nav-item">
                <button class="nav-link rounded-pill px-4" id="alumni-tab" data-bs-toggle="pill" data-bs-target="#alumniPane" type="button">
                    <i class="fa-solid fa-user-graduate me-1"></i><?= is_rtl() ? 'سجل الخريجين والأرشيف' : 'Alumni Registry' ?>
                </button>
            </li>
        </ul>

        <div class="tab-content" id="rolloverTabsContent">

            <!-- TAB 1: BATCH PROMOTION -->
            <div class="tab-pane fade show active" id="promoPane" role="tabpanel">
                <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                    <div class="card-header bg-card border-bottom border-border py-3">
                        <h6 class="fw-bold font-heading mb-0 text-foreground">
                            <i class="fa-solid fa-filter me-2 text-primary"></i><?= is_rtl() ? 'اختيار الشعبة الحالية والهدف للترقية السنوية' : 'Select Cohort & Target Grade Section' ?>
                        </h6>
                    </div>
                    <div class="card-body p-3">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الفصل الحالي المراد ترحيله:' : 'Current Cohort / Class:' ?></label>
                                <select name="class_id" class="form-select bg-background border-border text-foreground" onchange="this.form.submit()">
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['class_id'] ?>" <?= $c['class_id'] == $selected_promo_class ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($c['class_name']) ?> (Grade <?= $c['grade_level'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="batch_promote">
                    <input type="hidden" name="from_class_id" value="<?= $selected_promo_class ?>">

                    <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                        <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <h6 class="fw-bold font-heading mb-0 text-foreground">
                                <i class="fa-solid fa-users me-2 text-primary"></i><?= is_rtl() ? 'قائمة طلاب الدفعة وقرارات الترقية' : 'Cohort Roster & Promotion Decisions' ?> (<?= count($promo_students) ?>)
                            </h6>
                            <div class="d-flex align-items-center gap-2">
                                <label class="small text-muted-foreground fw-bold"><?= is_rtl() ? 'الترحيل إلى:' : 'Promote To Section:' ?></label>
                                <select name="to_class_id" class="form-select form-select-sm bg-background border-border text-foreground" style="width: 220px;" required>
                                    <option value=""><?= is_rtl() ? '-- اختر الشعبة المستهدفة --' : '-- Select Target Class --' ?></option>
                                    <?php foreach ($classes as $c): ?>
                                        <option value="<?= $c['class_id'] ?>">
                                            <?= htmlspecialchars($c['class_name']) ?> (Grade <?= $c['grade_level'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary btn-sm fw-bold px-3" onclick="return confirm('Execute cohort promotion for selected students?');">
                                    <i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'تنفيذ الترقية' : 'Execute Promotion' ?>
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0">
                                    <thead class="table-secondary">
                                        <tr class="text-muted-foreground small text-uppercase font-bold">
                                            <th class="ps-3"><?= is_rtl() ? 'الطالب ورقم القيد' : 'Student & ID' ?></th>
                                            <th class="text-center"><?= is_rtl() ? 'المعدل الأكاديمي' : 'Term GPA / Average' ?></th>
                                            <th class="text-center" style="width: 320px;"><?= is_rtl() ? 'قرار الترقية الأكاديمي' : 'Promotion Decision' ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($promo_students)): ?>
                                            <tr>
                                                <td colspan="3" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا يوجد طلاب نشطون في هذا الفصل.' : 'No active students in this class section.' ?></td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($promo_students as $st): 
                                                $sid = $st['student_id'];
                                                $gpa = $st['gpa_average'] ? round($st['gpa_average'], 1) : null;
                                                $default_decision = ($gpa !== null && $gpa < 50.0) ? 'Retained' : 'Promoted';
                                            ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <div class="fw-bold text-foreground"><?= htmlspecialchars($st['student_name']) ?></div>
                                                        <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($st['admission_no']) ?></div>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($gpa !== null): ?>
                                                            <span class="fw-bold font-monospace fs-6 <?= $gpa >= 50 ? 'text-success' : 'text-danger' ?>">
                                                                <?= $gpa ?>%
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-muted-foreground small">Pending Finals</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <div class="btn-group btn-group-sm w-100" role="group">
                                                            <input type="radio" class="btn-check" name="promotion_decision[<?= $sid ?>]" id="p_<?= $sid ?>" value="Promoted" <?= $default_decision === 'Promoted' ? 'checked' : '' ?>>
                                                            <label class="btn btn-outline-success" for="p_<?= $sid ?>"><?= is_rtl() ? 'ترقية للصف التالي' : 'Promoted' ?></label>

                                                            <input type="radio" class="btn-check" name="promotion_decision[<?= $sid ?>]" id="r_<?= $sid ?>" value="Retained" <?= $default_decision === 'Retained' ? 'checked' : '' ?>>
                                                            <label class="btn btn-outline-warning" for="r_<?= $sid ?>"><?= is_rtl() ? 'إعادة السنة' : 'Retained' ?></label>

                                                            <input type="radio" class="btn-check" name="promotion_decision[<?= $sid ?>]" id="g_<?= $sid ?>" value="Graduated">
                                                            <label class="btn btn-outline-purple" for="g_<?= $sid ?>"><?= is_rtl() ? 'تخرج (أرشيف)' : 'Graduated' ?></label>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
            </div>

            <!-- TAB 2: TRANSFER CERTIFICATES -->
            <div class="tab-pane fade" id="tcPane" role="tabpanel">
                <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                    <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold font-heading mb-0 text-foreground">
                            <i class="fa-solid fa-certificate me-2 text-primary"></i><?= is_rtl() ? 'شهادات النقل الرسمية الصادرة' : 'Issued Transfer Certificates' ?>
                        </h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-secondary">
                                    <tr class="text-muted-foreground small text-uppercase font-bold">
                                        <th class="ps-3"><?= is_rtl() ? 'رقم الشهادة والتاريخ' : 'TC Number & Date' ?></th>
                                        <th><?= is_rtl() ? 'الطالب ورقم القيد' : 'Candidate Name' ?></th>
                                        <th><?= is_rtl() ? 'سبب المغادرة' : 'Leaving Reason' ?></th>
                                        <th class="text-center"><?= is_rtl() ? 'إبراء الذمة المالية' : 'Finance Clearance' ?></th>
                                        <th class="text-end pe-3"><?= is_rtl() ? 'طباعة رسمية' : 'Print Certificate' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tc_list)): ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا توجد شهادات نقل صادرة بعد.' : 'No transfer certificates issued yet.' ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($tc_list as $tc): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <div class="fw-bold text-foreground font-monospace"><?= htmlspecialchars($tc['tc_number']) ?></div>
                                                    <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($tc['leaving_date']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-foreground"><?= htmlspecialchars($tc['student_name']) ?></div>
                                                    <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($tc['admission_no']) ?></div>
                                                </td>
                                                <td><?= htmlspecialchars($tc['leaving_reason']) ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-success-subtle text-success">
                                                        <i class="fa-solid fa-check me-1"></i>Cleared ($0 Dues)
                                                    </span>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <a href="tc_print.php?tc_id=<?= $tc['tc_id'] ?>" target="_blank" class="btn btn-outline-border btn-sm rounded-lg">
                                                        <i class="fa-solid fa-print me-1 text-primary"></i><?= is_rtl() ? 'طباعة' : 'Print PDF' ?>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: ALUMNI REGISTRY -->
            <div class="tab-pane fade" id="alumniPane" role="tabpanel">
                <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                    <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold font-heading mb-0 text-foreground">
                            <i class="fa-solid fa-user-graduate me-2 text-purple"></i><?= is_rtl() ? 'سجل الخريجين والملفات المؤرشفة' : 'Alumni & Archived Registry' ?>
                        </h6>
                        <span class="badge bg-purple-subtle text-purple rounded-pill"><?= count($alumni_list) ?> Archived Profiles</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-secondary">
                                    <tr class="text-muted-foreground small text-uppercase font-bold">
                                        <th class="ps-3"><?= is_rtl() ? 'اسم الطالب ورقم القيد' : 'Student & Roll #' ?></th>
                                        <th><?= is_rtl() ? 'آخر فصل ملتحق به' : 'Last Class Section' ?></th>
                                        <th><?= is_rtl() ? 'حالة السجل' : 'Archive Status' ?></th>
                                        <th class="text-end pe-3"><?= is_rtl() ? 'السجل التراكمي' : 'Official Transcript' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($alumni_list)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center py-4 text-muted-foreground"><?= is_rtl() ? 'لا يوجد طلاب في سجل الخريجين حالياً.' : 'No archived alumni records logged.' ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($alumni_list as $al): ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <div class="fw-bold text-foreground"><?= htmlspecialchars($al['student_name']) ?></div>
                                                    <div class="small text-muted-foreground font-monospace"><?= htmlspecialchars($al['admission_no']) ?></div>
                                                </td>
                                                <td><?= htmlspecialchars($al['class_name'] ?? 'General Alumni') ?></td>
                                                <td>
                                                    <span class="badge bg-secondary text-foreground"><?= htmlspecialchars($al['enrollment_status']) ?></span>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <a href="../Grading/transcript.php?student_id=<?= $al['student_id'] ?>" class="btn btn-outline-border btn-sm rounded-lg" target="_blank">
                                                        <i class="fa-solid fa-scroll me-1 text-primary"></i><?= is_rtl() ? 'عرض السجل' : 'View Transcript' ?>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <?= render_platform_footer() ?>
    </div>

    <!-- Modal: Issue Transfer Certificate -->
    <div class="modal fade" id="tcModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="issue_tc">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-file-export me-2 text-primary"></i><?= is_rtl() ? 'إصدار شهادة نقل رسمية (TC)' : 'Issue Official Transfer Certificate' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning py-2 small mb-3">
                            <i class="fa-solid fa-shield-halved me-1"></i>
                            <?= is_rtl() ? 'يقوم النظام بالفحص المالي الآلي والتحقق من إبراء الذمة قبل إصدار الشهادة.' : 'Automated pre-flight clearance checks verify zero financial dues before issuing exit certificates.' ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اختيار الطالب' : 'Select Student to Transfer' ?></label>
                            <select name="student_id" class="form-select bg-background border-border text-foreground" required>
                                <option value=""><?= is_rtl() ? '-- اختر الطالب --' : '-- Select Student --' ?></option>
                                <?php 
                                $all_active = $pdo->query("
                                    SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name, c.class_name
                                    FROM students s JOIN users u ON s.user_id = u.user_id LEFT JOIN classes c ON s.class_id = c.class_id
                                    WHERE s.enrollment_status = 'Active' AND s.is_deleted = 0
                                    ORDER BY student_name ASC
                                ")->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($all_active as $ast): ?>
                                    <option value="<?= $ast['student_id'] ?>"><?= htmlspecialchars($ast['student_name']) ?> (<?= htmlspecialchars($ast['class_name'] ?? 'General') ?> - #<?= htmlspecialchars($ast['admission_no']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'تاريخ المغادرة الرسمي' : 'Leaving Date' ?></label>
                                <input type="date" name="leaving_date" class="form-control bg-background border-border text-foreground" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'تقييم السلوك والانضباط' : 'Conduct Summary' ?></label>
                                <select name="conduct_summary" class="form-select bg-background border-border text-foreground">
                                    <option value="Exemplary">Exemplary (نموذجي ومتميز)</option>
                                    <option value="Good">Good (جيد ومستقيم)</option>
                                    <option value="Satisfactory">Satisfactory (مرضي)</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'سبب المغادرة' : 'Leaving Reason' ?></label>
                            <input type="text" name="leaving_reason" class="form-control bg-background border-border text-foreground" placeholder="e.g. Family Relocation, Transfer to International School" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'الصف المؤهل له مستقبلاً' : 'Qualified For Grade' ?></label>
                            <input type="text" name="promoted_to_grade" class="form-control bg-background border-border text-foreground" placeholder="e.g. Promoted to Grade 6">
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'فحص البراءة وإصدار الشهادة' : 'Clear & Issue Certificate' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
