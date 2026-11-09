<?php
/**
 * Grading Module - Exam Timetabling, Seating & Hall Invigilation Scheduling
 * Features: Exam Seating & Hall Allocation, Faculty Invigilation Roster (Conflict Prevention), Printable Desk Slips & Hall Registers
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin', 'teacher']);

$role = Auth::role();
$user_id = Auth::id();
$can_edit = in_array($role, ['admin', 'school_admin']);

$msg = '';
$msg_type = 'success';

// Handle Action Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_timetable_slot') {
            $series_id  = (int)($_POST['series_id'] ?? 0);
            $subject_id = (int)($_POST['subject_id'] ?? 0);
            $class_id   = (int)($_POST['class_id'] ?? 0);
            $hall_id    = (int)($_POST['hall_id'] ?? 0);
            $exam_date  = $_POST['exam_date'] ?? date('Y-m-d');
            $start_time = $_POST['start_time'] ?? '09:00';
            $end_time   = $_POST['end_time'] ?? '11:00';
            $invigilator_id = !empty($_POST['invigilator_id']) ? (int)$_POST['invigilator_id'] : null;
            $relief_id  = !empty($_POST['relief_invigilator_id']) ? (int)$_POST['relief_invigilator_id'] : null;

            if ($series_id <= 0 || $subject_id <= 0 || $class_id <= 0 || $hall_id <= 0) {
                throw new InvalidArgumentException(is_rtl() ? 'يرجى استيفاء جميع الحقول الإلزامية (السلسلة، المادة، الفصل، والقاعة).' : 'Please fill all mandatory fields (Series, Subject, Class, Hall).');
            }

            // Conflict Check Rule: Ensure no teacher invigilates their own subject
            if ($invigilator_id) {
                $teach_check = $pdo->prepare("
                    SELECT COUNT(*) FROM courses 
                    WHERE teacher_id = ? AND subject_id = ? AND class_id = ?
                ");
                $teach_check->execute([$invigilator_id, $subject_id, $class_id]);
                if ($teach_check->fetchColumn() > 0) {
                    throw new InvalidArgumentException(is_rtl() 
                        ? 'تنبيه تعارض الحوكمة: لا يجوز تكليف معلم بمراقبة قاعة امتحان مادته الدراسية لنفس الفصل!' 
                        : 'Governance Conflict: An instructor cannot invigilate an exam hall for their own subject in this class!');
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO exam_timetables (series_id, subject_id, class_id, hall_id, exam_date, start_time, end_time, invigilator_id, relief_invigilator_id, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Scheduled')
            ");
            $stmt->execute([$series_id, $subject_id, $class_id, $hall_id, $exam_date, $start_time, $end_time, $invigilator_id, $relief_id]);
            $new_tt_id = $pdo->lastInsertId();

            // Auto-Generate Seating Allocation
            $alloc_method = $_POST['seating_method'] ?? 'roll_number'; // alphabetical, roll_number, random
            $students_stmt = $pdo->prepare("
                SELECT s.student_id, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name
                FROM students s
                LEFT JOIN users u ON s.user_id = u.user_id
                WHERE s.class_id = ? AND s.is_deleted = 0
            ");
            $students_stmt->execute([$class_id]);
            $stu_list = $students_stmt->fetchAll();

            if ($alloc_method === 'alphabetical') {
                usort($stu_list, fn($a, $b) => strcmp($a['student_name'], $b['student_name']));
            } elseif ($alloc_method === 'random') {
                shuffle($stu_list);
            } else {
                usort($stu_list, fn($a, $b) => strcmp($a['admission_no'] ?? '', $b['admission_no'] ?? ''));
            }

            $desk = 1;
            $ins_seat = $pdo->prepare("INSERT INTO exam_seating_allocations (timetable_id, student_id, hall_id, desk_number, attendance_status) VALUES (?, ?, ?, ?, 'Present')");
            foreach ($stu_list as $st) {
                $ins_seat->execute([$new_tt_id, $st['student_id'], $hall_id, $desk]);
                $desk++;
            }

            $msg = is_rtl() ? 'تمت جدولة جلسة الامتحان وتوليد توزيع المقاعد تلقائياً!' : 'Exam session scheduled and candidate seating generated successfully!';
        } elseif ($action === 'create_hall') {
            $hall_name = trim($_POST['hall_name'] ?? '');
            $building  = trim($_POST['building'] ?? '');
            $capacity  = (int)($_POST['capacity'] ?? 40);
            $hall_type = $_POST['hall_type'] ?? 'hall';

            if (!empty($hall_name)) {
                $stmt = $pdo->prepare("INSERT INTO exam_halls (hall_name, building, capacity, hall_type) VALUES (?, ?, ?, ?)");
                $stmt->execute([$hall_name, $building, $capacity, $hall_type]);
                $msg = is_rtl() ? 'تم تسجيل القاعة الامتحانية بنجاح.' : 'Examination hall registered successfully.';
            }
        } elseif ($action === 'update_attendance') {
            $allocation_id = (int)($_POST['allocation_id'] ?? 0);
            $status = $_POST['attendance_status'] ?? 'Present';
            if ($allocation_id > 0) {
                $pdo->prepare("UPDATE exam_seating_allocations SET attendance_status = ? WHERE allocation_id = ?")->execute([$status, $allocation_id]);
                $msg = is_rtl() ? 'تم تحديث حالة حضور الطالب.' : 'Candidate attendance status updated.';
            }
        }
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        $msg_type = 'danger';
    }
}

// Fetch Filter and Lookup Data
$selected_timetable_id = isset($_GET['timetable_id']) ? (int)$_GET['timetable_id'] : 0;
$view_mode = $_GET['view'] ?? 'matrix'; // matrix, desk_slips, hall_register

$series_list = $pdo->query("SELECT * FROM exam_series ORDER BY start_date DESC")->fetchAll();
$halls = $pdo->query("SELECT * FROM exam_halls ORDER BY hall_name ASC")->fetchAll();
$classes = $pdo->query("SELECT class_id, class_name FROM classes ORDER BY class_name ASC")->fetchAll();
$subjects = $pdo->query("SELECT subject_id, subject_name, subject_code FROM subjects ORDER BY subject_name ASC")->fetchAll();
$teachers = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY full_name ASC")->fetchAll();

// Fetch Timetables
$timetables = $pdo->query("
    SELECT tt.*, es.title as series_title, s.subject_name, s.subject_code, c.class_name, h.hall_name, h.capacity,
           u1.full_name as invigilator_name, u2.full_name as relief_name,
           (SELECT COUNT(*) FROM exam_seating_allocations WHERE timetable_id = tt.timetable_id) as allocated_candidates
    FROM exam_timetables tt
    JOIN exam_series es ON tt.series_id = es.series_id
    JOIN subjects s ON tt.subject_id = s.subject_id
    JOIN classes c ON tt.class_id = c.class_id
    JOIN exam_halls h ON tt.hall_id = h.hall_id
    LEFT JOIN users u1 ON tt.invigilator_id = u1.user_id
    LEFT JOIN users u2 ON tt.relief_invigilator_id = u2.user_id
    ORDER BY tt.exam_date ASC, tt.start_time ASC
")->fetchAll();

// If no timetable selected, default to first
if ($selected_timetable_id <= 0 && !empty($timetables)) {
    $selected_timetable_id = (int)$timetables[0]['timetable_id'];
}

// Fetch Selected Timetable Session Details & Seating Allocations
$current_session = null;
$seating_roster = [];

if ($selected_timetable_id > 0) {
    foreach ($timetables as $t) {
        if ($t['timetable_id'] == $selected_timetable_id) {
            $current_session = $t;
            break;
        }
    }

    $seat_stmt = $pdo->prepare("
        SELECT esa.*, s.admission_no, COALESCE(u.full_name, CONCAT(s.first_name, ' ', s.last_name)) as student_name,
               u.email as student_email
        FROM exam_seating_allocations esa
        JOIN students s ON esa.student_id = s.student_id
        LEFT JOIN users u ON s.user_id = u.user_id
        WHERE esa.timetable_id = ?
        ORDER BY esa.desk_number ASC
    ");
    $seat_stmt->execute([$selected_timetable_id]);
    $seating_roster = $seat_stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'جدولة الامتحانات وتوزيع القاعات والمراقبة - نظام الدرجات' : 'Exam Timetabling, Seating & Hall Invigilation' ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .desk-card {
            border: 1px dashed var(--border);
            border-radius: var(--radius-lg, 0.75rem);
            background: var(--card);
            transition: all 0.2s ease;
        }
        .desk-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
        }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; color: black !important; }
            .print-page { page-break-after: always; }
            .desk-slip-item {
                border: 2px solid #000 !important;
                page-break-inside: avoid;
                margin-bottom: 20px;
                padding: 15px;
            }
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<div class="no-print">
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'الجدولة والمراقبة' : 'Exam Scheduling & Halls',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="policies.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sliders me-1 text-primary"></i> ' . (is_rtl() ? 'السياسات' : 'Policies') . '</a>',
        '<a href="matrix.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-table-cells me-1 text-success"></i> ' . (is_rtl() ? 'شبكة الرصد' : 'Mark Matrix') . '</a>',
        '<a href="analytics.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-chart-line me-1 text-purple"></i> ' . (is_rtl() ? 'الترتيب والإحصائيات' : 'Analytics') . '</a>',
        '<a href="report_cards.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-id-card-clip me-1 text-warning"></i> ' . (is_rtl() ? 'الشهادات' : 'Report Cards') . '</a>'
    ]
]) ?>
</div>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Flash Alert -->
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show border-0 shadow-sm rounded-xl mb-4 no-print" role="alert">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-2"></i>
            <?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Header Actions Bar -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 no-print">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-calendar-check text-info me-2"></i><?= is_rtl() ? 'جدولة الامتحانات، توزيع المقاعد وقوائم المراقبة' : 'Exam Timetabling, Seating & Invigilation' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'توزيع جلسات الاختبارات على القاعات، توزيع أرقام جلوس الطلاب، تخصيص المراقبين مع تدقيق منع التعارض، وتوليد كشوف الحضور وبطاقات الجلوس.' : 'Allocate exam halls, generate candidate seating plans, assign invigilators with conflict guards, and export desk tags & registers.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($can_edit): ?>
                <button type="button" class="btn btn-outline-border rounded-xl px-3" data-bs-toggle="modal" data-bs-target="#newHallModal">
                    <i class="fa-solid fa-door-open me-1 text-info"></i> <?= is_rtl() ? 'إضافة قاعة' : 'Add Hall' ?>
                </button>
                <button type="button" class="btn btn-primary rounded-xl shadow-sm px-3" data-bs-toggle="modal" data-bs-target="#newTimetableModal">
                    <i class="fa-solid fa-plus-circle me-1"></i> <?= is_rtl() ? 'جدولة جلسة امتحان' : 'Schedule Session' ?>
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Overview Metrics Cards -->
    <div class="row g-3 mb-4 no-print">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($timetables) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الجلسات المجدولة' : 'Scheduled Sessions' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-building-columns"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($halls) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'القاعات والمختبرات' : 'Exam Halls & Labs' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-success me-3">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($teachers) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'هيئة المراقبة المتاحة' : 'Invigilator Pool' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-chair"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= count($seating_roster) ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'مقاعد الجلسة الحالية' : 'Active Session Desks' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Session Selector & View Mode Switcher -->
    <div class="card bg-card border border-border rounded-xl p-3 mb-4 shadow-sm no-print">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-6">
                <form method="GET" action="" class="d-flex align-items-center gap-2 m-0">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
                    <label class="small text-muted-foreground fw-bold text-nowrap"><?= is_rtl() ? 'اختر جلسة الاختبار:' : 'Select Exam Session:' ?></label>
                    <select name="timetable_id" class="form-select form-select-sm bg-background border-border text-foreground" onchange="this.form.submit()">
                        <?php foreach ($timetables as $tt): ?>
                            <option value="<?= $tt['timetable_id'] ?>" <?= $selected_timetable_id == $tt['timetable_id'] ? 'selected' : '' ?>>
                                <?= date('M d', strtotime($tt['exam_date'])) ?> | <?= htmlspecialchars($tt['subject_name']) ?> (<?= htmlspecialchars($tt['class_name']) ?>) - <?= htmlspecialchars($tt['hall_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>
            <div class="col-12 col-md-6 d-flex justify-content-md-end align-items-center gap-2">
                <a href="?timetable_id=<?= $selected_timetable_id ?>&view=matrix" class="btn btn-sm <?= $view_mode === 'matrix' ? 'btn-primary' : 'btn-outline-border' ?> rounded-lg">
                    <i class="fa-solid fa-table-cells me-1"></i><?= is_rtl() ? 'مصفوفة المقاعد' : 'Seating Grid' ?>
                </a>
                <a href="?timetable_id=<?= $selected_timetable_id ?>&view=desk_slips" class="btn btn-sm <?= $view_mode === 'desk_slips' ? 'btn-primary' : 'btn-outline-border' ?> rounded-lg">
                    <i class="fa-solid fa-address-card me-1"></i><?= is_rtl() ? 'بطاقات الجلوس' : 'Candidate Desk Slips' ?>
                </a>
                <a href="?timetable_id=<?= $selected_timetable_id ?>&view=hall_register" class="btn btn-sm <?= $view_mode === 'hall_register' ? 'btn-primary' : 'btn-outline-border' ?> rounded-lg">
                    <i class="fa-solid fa-clipboard-user me-1"></i><?= is_rtl() ? 'سجل الحضور والتوقيعات' : 'Invigilator Register' ?>
                </a>
                <button type="button" class="btn btn-outline-border btn-sm rounded-lg" onclick="window.print()">
                    <i class="fa-solid fa-print me-1"></i><?= is_rtl() ? 'طباعة' : 'Print' ?>
                </button>
            </div>
        </div>
    </div>

    <?php if ($current_session): ?>
        <!-- Session Meta Summary Banner -->
        <div class="p-3 mb-4 bg-card border border-border rounded-xl shadow-sm d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="action-icon-box icon-box-primary" style="width: 44px; height: 44px; font-size: 1.2rem;">
                    <i class="fa-solid fa-file-pen"></i>
                </div>
                <div>
                    <h5 class="fw-bold font-heading mb-0 text-foreground">
                        <?= htmlspecialchars($current_session['subject_name']) ?> (<?= htmlspecialchars($current_session['subject_code']) ?>)
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-2 rounded-pill"><?= htmlspecialchars($current_session['class_name']) ?></span>
                    </h5>
                    <div class="small text-muted-foreground">
                        <i class="fa-regular fa-calendar me-1"></i><?= date('l, F d, Y', strtotime($current_session['exam_date'])) ?> |
                        <i class="fa-regular fa-clock ms-2 me-1"></i><?= date('h:i A', strtotime($current_session['start_time'])) ?> - <?= date('h:i A', strtotime($current_session['end_time'])) ?> |
                        <i class="fa-solid fa-door-open ms-2 me-1 text-info"></i><?= htmlspecialchars($current_session['hall_name']) ?>
                    </div>
                </div>
            </div>

            <!-- Invigilation Faculty Assignment (With Governance Conflict Guard) -->
            <div class="d-flex align-items-center gap-2">
                <div class="text-end">
                    <div class="small text-muted-foreground fw-bold"><?= is_rtl() ? 'رئيس المراقبة:' : 'Chief Invigilator:' ?></div>
                    <div class="fw-bold text-foreground">
                        <i class="fa-solid fa-user-tie text-success me-1"></i>
                        <?= htmlspecialchars($current_session['invigilator_name'] ?? 'Not Assigned') ?>
                    </div>
                    <?php if (!empty($current_session['relief_name'])): ?>
                        <div class="small text-muted-foreground">
                            <?= is_rtl() ? 'مراقب احتياط:' : 'Relief:' ?> <?= htmlspecialchars($current_session['relief_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($view_mode === 'matrix'): ?>
            <!-- VIEW 1: SEATING PLAN GRID -->
            <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold font-heading mb-0 text-foreground">
                        <i class="fa-solid fa-chair text-primary me-2"></i><?= is_rtl() ? 'خريطة توزيع المقاعد بالقاعة' : 'Hall Seating Allocation Matrix' ?>
                    </h6>
                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle rounded-pill">
                        <?= count($seating_roster) ?> / <?= $current_session['capacity'] ?> <?= is_rtl() ? 'مقعد محجوز' : 'Seats Allocated' ?>
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php foreach ($seating_roster as $seat): ?>
                            <div class="col-6 col-sm-4 col-md-3 col-xl-2">
                                <div class="desk-card p-3 text-center h-100 position-relative">
                                    <div class="badge bg-primary text-white rounded-pill px-2 py-1 mb-2 font-monospace">
                                        Desk #<?= $seat['desk_number'] ?>
                                    </div>
                                    <div class="fw-bold text-foreground small text-truncate" title="<?= htmlspecialchars($seat['student_name']) ?>">
                                        <?= htmlspecialchars($seat['student_name']) ?>
                                    </div>
                                    <div class="small text-muted-foreground font-monospace mb-2">
                                        #<?= htmlspecialchars($seat['admission_no']) ?>
                                    </div>

                                    <!-- Attendance Toggle -->
                                    <?php if ($can_edit): ?>
                                        <form method="POST" action="" class="no-print">
                                            <input type="hidden" name="action" value="update_attendance">
                                            <input type="hidden" name="allocation_id" value="<?= $seat['allocation_id'] ?>">
                                            <select name="attendance_status" class="form-select form-select-sm text-center font-bold" onchange="this.form.submit()">
                                                <option value="Present" <?= $seat['attendance_status'] === 'Present' ? 'selected' : '' ?>>Present</option>
                                                <option value="Absent" <?= $seat['attendance_status'] === 'Absent' ? 'selected' : '' ?>>Absent</option>
                                                <option value="Medical Leave" <?= $seat['attendance_status'] === 'Medical Leave' ? 'selected' : '' ?>>Medical</option>
                                                <option value="Disqualified" <?= $seat['attendance_status'] === 'Disqualified' ? 'selected' : '' ?>>DQ</option>
                                            </select>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge bg-<?= $seat['attendance_status'] === 'Present' ? 'success' : 'danger' ?>-subtle text-<?= $seat['attendance_status'] === 'Present' ? 'success' : 'danger' ?> rounded-pill">
                                            <?= $seat['attendance_status'] ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($view_mode === 'desk_slips'): ?>
            <!-- VIEW 2: CANDIDATE DESK TAGS / SLIPS (PRINTABLE) -->
            <div class="row g-3">
                <?php foreach ($seating_roster as $seat): ?>
                    <div class="col-12 col-md-6 col-lg-4 desk-slip-item">
                        <div class="card p-3 border border-2 border-dark rounded-xl bg-white text-dark shadow-sm">
                            <div class="d-flex justify-content-between align-items-start border-bottom pb-2 mb-2">
                                <div>
                                    <div class="fw-bold font-heading fs-5"><?= htmlspecialchars($global_branding['school_name'] ?? 'School System') ?></div>
                                    <small class="text-secondary"><?= is_rtl() ? 'بطاقة جلوس الطالب الرسمية' : 'Candidate Exam Desk Slip' ?></small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-dark text-white fs-6 px-3 py-1 font-monospace">DESK: <?= $seat['desk_number'] ?></span>
                                </div>
                            </div>
                            <div class="row g-2 align-items-center mb-2">
                                <div class="col-8">
                                    <div class="fw-bold fs-6"><?= htmlspecialchars($seat['student_name']) ?></div>
                                    <div class="small text-secondary font-monospace">Roll No: <?= htmlspecialchars($seat['admission_no']) ?></div>
                                    <div class="small fw-semibold"><?= htmlspecialchars($current_session['class_name']) ?></div>
                                </div>
                                <div class="col-4 text-center">
                                    <div class="border border-secondary rounded p-2 small text-muted font-monospace" style="height: 60px; display: flex; align-items: center; justify-content: center;">
                                        [ PHOTO ]
                                    </div>
                                </div>
                            </div>
                            <div class="p-2 bg-light rounded border text-secondary small">
                                <div><strong>Subject:</strong> <?= htmlspecialchars($current_session['subject_name']) ?> (<?= htmlspecialchars($current_session['subject_code']) ?>)</div>
                                <div><strong>Date & Time:</strong> <?= date('M d, Y', strtotime($current_session['exam_date'])) ?> | <?= date('h:i A', strtotime($current_session['start_time'])) ?></div>
                                <div><strong>Hall:</strong> <?= htmlspecialchars($current_session['hall_name']) ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($view_mode === 'hall_register'): ?>
            <!-- VIEW 3: INVIGILATOR ATTENDANCE REGISTER -->
            <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                    <h6 class="fw-bold font-heading mb-0 text-foreground">
                        <i class="fa-solid fa-clipboard-list text-primary me-2"></i><?= is_rtl() ? 'سجل حضور وتسليم كراسات الإجابة بالقاعة' : 'Hall Invigilation Register & Candidate Signatures' ?>
                    </h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 70px;">Desk #</th>
                                <th>Roll / Admission No</th>
                                <th>Candidate Full Name</th>
                                <th>Class</th>
                                <th style="width: 140px;">Status</th>
                                <th style="width: 180px;">Candidate Signature</th>
                                <th style="width: 180px;">Booklet / Script No</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($seating_roster as $seat): ?>
                                <tr>
                                    <td class="fw-bold font-monospace text-center"><?= $seat['desk_number'] ?></td>
                                    <td class="font-monospace"><?= htmlspecialchars($seat['admission_no']) ?></td>
                                    <td class="fw-bold"><?= htmlspecialchars($seat['student_name']) ?></td>
                                    <td><?= htmlspecialchars($current_session['class_name']) ?></td>
                                    <td>
                                        <span class="badge bg-<?= $seat['attendance_status'] === 'Present' ? 'success' : 'danger' ?>-subtle text-<?= $seat['attendance_status'] === 'Present' ? 'success' : 'danger' ?> rounded-pill">
                                            <?= $seat['attendance_status'] ?>
                                        </span>
                                    </td>
                                    <td class="border-bottom border-dark" style="height: 45px;"></td>
                                    <td class="border-bottom border-dark"></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-4 border-top border-border bg-card d-flex justify-content-between align-items-center flex-wrap gap-4 mt-3">
                    <div>
                        <div class="small fw-bold text-muted-foreground"><?= is_rtl() ? 'توقيع رئيس القاعة:' : 'Chief Invigilator Signature:' ?></div>
                        <div class="mt-4 border-bottom border-dark" style="width: 200px;"></div>
                        <div class="small text-muted-foreground mt-1"><?= htmlspecialchars($current_session['invigilator_name'] ?? 'Invigilator') ?></div>
                    </div>
                    <div>
                        <div class="small fw-bold text-muted-foreground"><?= is_rtl() ? 'توقيع مراقب الدعم والتسليم:' : 'Relief Supervisor Signature:' ?></div>
                        <div class="mt-4 border-bottom border-dark" style="width: 200px;"></div>
                        <div class="small text-muted-foreground mt-1"><?= htmlspecialchars($current_session['relief_name'] ?? 'Supervisor') ?></div>
                    </div>
                    <div>
                        <div class="small fw-bold text-muted-foreground"><?= is_rtl() ? 'اعتماد الكنترول الأكاديمي:' : 'Examination Officer Seal:' ?></div>
                        <div class="mt-4 border-bottom border-dark" style="width: 200px;"></div>
                        <div class="small text-muted-foreground mt-1">Academic Control Center</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <div class="card bg-card border border-border rounded-xl p-5 text-center shadow-sm">
            <i class="fa-solid fa-calendar-xmark fs-1 text-muted-foreground mb-3"></i>
            <h5 class="fw-bold text-foreground"><?= is_rtl() ? 'لم يتم جدولة أي جلسات اختبار بعد' : 'No Examination Sessions Scheduled Yet' ?></h5>
            <p class="text-muted-foreground small mb-3"><?= is_rtl() ? 'انقر على "جدولة جلسة امتحان" للبدء في توزيع المقاعد وقاعات المراقبة.' : 'Click "Schedule Session" to allocate exam halls and distribute candidate seating.' ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: Schedule New Exam Session -->
<?php if ($can_edit): ?>
<div class="modal fade no-print" id="newTimetableModal" tabindex="-1" aria-labelledby="newTimetableModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <div class="modal-header border-border pb-3">
                <h5 class="modal-title font-heading fw-bold text-foreground" id="newTimetableModalLabel">
                    <i class="fa-solid fa-calendar-plus text-primary me-2"></i><?= is_rtl() ? 'جدولة جلسة امتحان وتوزيع القاعة' : 'Schedule Examination Session & Hall' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_timetable_slot">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'سلسلة الامتحان *' : 'Exam Series *' ?></label>
                            <select name="series_id" class="form-select bg-background border-border text-foreground" required>
                                <?php foreach ($series_list as $sl): ?>
                                    <option value="<?= $sl['series_id'] ?>"><?= htmlspecialchars($sl['title']) ?> (<?= htmlspecialchars($sl['term']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'المادة الدراسية *' : 'Subject *' ?></label>
                            <select name="subject_id" class="form-select bg-background border-border text-foreground" required>
                                <?php foreach ($subjects as $sb): ?>
                                    <option value="<?= $sb['subject_id'] ?>"><?= htmlspecialchars($sb['subject_name']) ?> (<?= htmlspecialchars($sb['subject_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي المستهدف *' : 'Target Class Section *' ?></label>
                            <select name="class_id" class="form-select bg-background border-border text-foreground" required>
                                <?php foreach ($classes as $cl): ?>
                                    <option value="<?= $cl['class_id'] ?>"><?= htmlspecialchars($cl['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'القاعة الامتحانية *' : 'Examination Hall *' ?></label>
                            <select name="hall_id" class="form-select bg-background border-border text-foreground" required>
                                <?php foreach ($halls as $hl): ?>
                                    <option value="<?= $hl['hall_id'] ?>"><?= htmlspecialchars($hl['hall_name']) ?> (Capacity: <?= $hl['capacity'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'تاريخ الامتحان *' : 'Exam Date *' ?></label>
                            <input type="date" name="exam_date" class="form-control bg-background border-border text-foreground" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'وقت البدء' : 'Start Time' ?></label>
                            <input type="time" name="start_time" class="form-control bg-background border-border text-foreground" value="09:00" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'وقت النهاية' : 'End Time' ?></label>
                            <input type="time" name="end_time" class="form-control bg-background border-border text-foreground" value="11:00" required>
                        </div>

                        <!-- Invigilator Assignment with Governance Conflict Protection -->
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'رئيس القاعة (المراقب الأول)' : 'Chief Invigilator' ?></label>
                            <select name="invigilator_id" class="form-select bg-background border-border text-foreground">
                                <option value=""><?= is_rtl() ? 'بدون تعيين حالياً' : 'None / Assign Later' ?></option>
                                <?php foreach ($teachers as $tc): ?>
                                    <option value="<?= $tc['user_id'] ?>"><?= htmlspecialchars($tc['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text small text-muted-foreground"><?= is_rtl() ? 'يطبق النظام تدقيق الحوكمة لمنع المعلم من مراقبة مادته الخاصة.' : 'Conflict check automatically ensures teacher cannot invigilate their own subject.' ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'مراقب احتياطي / مشرف القاعة' : 'Relief Supervisor' ?></label>
                            <select name="relief_invigilator_id" class="form-select bg-background border-border text-foreground">
                                <option value=""><?= is_rtl() ? 'اختياري' : 'Optional' ?></option>
                                <?php foreach ($teachers as $tc): ?>
                                    <option value="<?= $tc['user_id'] ?>"><?= htmlspecialchars($tc['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'خوارزمية توزيع أرقام الجلوس والمقاعد' : 'Candidate Seating Distribution Strategy' ?></label>
                            <select name="seating_method" class="form-select bg-background border-border text-foreground">
                                <option value="roll_number" selected><?= is_rtl() ? 'حسب رقم القيد الأكاديمي (Roll / Admission Number)' : 'Sequential by Roll / Admission Number' ?></option>
                                <option value="alphabetical"><?= is_rtl() ? 'أبجدياً حسب اسم الطالب (Alphabetical Order)' : 'Alphabetical Order by Student Full Name' ?></option>
                                <option value="random"><?= is_rtl() ? 'توزيع عشوائي لمنع التجاور (Random Anti-Cheating Distribution)' : 'Randomized Anti-Cheating Distribution' ?></option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-border">
                    <button type="button" class="btn btn-outline-border rounded-xl px-3" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-xl px-4">
                        <i class="fa-solid fa-calendar-check me-1"></i> <?= is_rtl() ? 'تأكيد الجدولة وتوزيع المقاعد' : 'Schedule & Allocate Seats' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: New Hall -->
<div class="modal fade no-print" id="newHallModal" tabindex="-1" aria-labelledby="newHallModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-card border border-border rounded-xl shadow-lg">
            <div class="modal-header border-border pb-3">
                <h5 class="modal-title font-heading fw-bold text-foreground" id="newHallModalLabel">
                    <i class="fa-solid fa-door-open text-primary me-2"></i><?= is_rtl() ? 'تسجيل قاعة امتحانية جديدة' : 'Register New Exam Hall' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="action" value="create_hall">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'اسم القاعة *' : 'Hall Name *' ?></label>
                            <input type="text" name="hall_name" class="form-control bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'مثال: القاعة الكبرى B' : 'E.g., Grand Hall B' ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'المبنى / الموقع' : 'Building / Wing' ?></label>
                            <input type="text" name="building" class="form-control bg-background border-border text-foreground" placeholder="Main Building 2nd Floor">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الطاقة الاستيعابية (المقاعد)' : 'Seat Capacity' ?></label>
                            <input type="number" name="capacity" class="form-control bg-background border-border text-foreground" value="40" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'نوع القاعة' : 'Hall Type' ?></label>
                            <select name="hall_type" class="form-select bg-background border-border text-foreground">
                                <option value="hall">Main Examination Hall</option>
                                <option value="lab">Computer / Science Lab</option>
                                <option value="classroom">Regular Classroom</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-border">
                    <button type="button" class="btn btn-outline-border rounded-xl px-3" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                    <button type="submit" class="btn btn-primary rounded-xl px-4">
                        <i class="fa-solid fa-check me-1"></i> <?= is_rtl() ? 'حفظ القاعة' : 'Save Hall' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
