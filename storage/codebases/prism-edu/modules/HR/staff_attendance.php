<?php
/**
 * HR Module - Staff & Faculty Daily Attendance Tracker & Time Log
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin, School Admin, HR, or Finance
Auth::requireRole(['admin', 'school_admin', 'hr', 'finance']);

$user_role = Auth::role();
$user_id = Auth::id();
$msg = $_SESSION['flash_msg'] ?? '';
$msg_type = $_SESSION['flash_msg_type'] ?? 'info';
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

$selected_date = $_GET['date'] ?? date('Y-m-d');
$selected_dept = !empty($_GET['dept_id']) ? intval($_GET['dept_id']) : null;

// -------------------------------------------------------------
// 1. Handle Bulk Attendance Save / Updates
// -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $att_date = $_POST['attendance_date'] ?? $selected_date;
    $entries = $_POST['attendance'] ?? [];

    if (!empty($entries) && is_array($entries)) {
        try {
            $pdo->beginTransaction();
            $upsertStmt = $pdo->prepare("
                INSERT INTO staff_attendance (employee_id, attendance_date, status, check_in_time, check_out_time, late_minutes, notes, marked_by)
                VALUES (:emp_id, :att_date, :status, :in_time, :out_time, :late_min, :notes, :marked_by)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    check_in_time = VALUES(check_in_time),
                    check_out_time = VALUES(check_out_time),
                    late_minutes = VALUES(late_minutes),
                    notes = VALUES(notes),
                    marked_by = VALUES(marked_by)
            ");

            $savedCount = 0;
            foreach ($entries as $empId => $data) {
                $status = $data['status'] ?? 'Present';
                $inTime = !empty($data['check_in_time']) ? $data['check_in_time'] : null;
                $outTime = !empty($data['check_out_time']) ? $data['check_out_time'] : null;
                $lateMin = intval($data['late_minutes'] ?? 0);
                $notes = trim($data['notes'] ?? '');

                // Auto calculate late minutes if check-in is after 08:00 AM
                if ($inTime && $status === 'Late' && $lateMin <= 0) {
                    $standardStart = strtotime('08:00:00');
                    $actualTime = strtotime($inTime);
                    if ($actualTime > $standardStart) {
                        $lateMin = round(($actualTime - $standardStart) / 60);
                    }
                }

                $upsertStmt->execute([
                    'emp_id'    => intval($empId),
                    'att_date'  => $att_date,
                    'status'    => $status,
                    'in_time'   => $inTime,
                    'out_time'  => $outTime,
                    'late_min'  => $lateMin,
                    'notes'     => $notes,
                    'marked_by' => $user_id
                ]);
                $savedCount++;
            }

            $pdo->commit();
            $_SESSION['flash_msg'] = is_rtl() 
                ? "تم حفظ سجل الحضور والدوام بنجاح لـ $savedCount موظفاً بتاريخ $att_date." 
                : "Attendance record saved successfully for $savedCount staff members on $att_date.";
            $_SESSION['flash_msg_type'] = "success";
        } catch (\Exception $e) {
            $pdo->rollBack();
            $_SESSION['flash_msg'] = "Database Error: " . $e->getMessage();
            $_SESSION['flash_msg_type'] = "danger";
        }
    }
    header("Location: staff_attendance.php?date=" . urlencode($att_date) . ($selected_dept ? "&dept_id=" . $selected_dept : ""));
    exit();
}

// -------------------------------------------------------------
// 2. Fetch Active Staff & Existing Attendance for Selected Date
// -------------------------------------------------------------
$departments = $pdo->query("SELECT department_id, department_name, department_code FROM departments ORDER BY department_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$sql = "
    SELECT e.employee_id, e.employee_code, e.full_name, e.category, e.job_title, e.department_id,
           d.department_name, d.department_code,
           sa.attendance_id, sa.status AS attendance_status, sa.check_in_time, sa.check_out_time, sa.late_minutes, sa.notes
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN staff_attendance sa ON (e.employee_id = sa.employee_id AND sa.attendance_date = :att_date)
    WHERE e.status != 'Terminated'
";
$params = ['att_date' => $selected_date];

if ($selected_dept) {
    $sql .= " AND e.department_id = :dept_id";
    $params['dept_id'] = $selected_dept;
}

$sql .= " ORDER BY e.full_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Metrics for the Day
$totalStaff = count($staffList);
$presentCount = 0;
$lateCount = 0;
$absentCount = 0;
$onLeaveCount = 0;

foreach ($staffList as $st) {
    $stStatus = $st['attendance_status'] ?? 'Present'; // default assumption if unmarked
    if ($st['attendance_status'] !== null) {
        if ($stStatus === 'Present') $presentCount++;
        elseif ($stStatus === 'Late') $lateCount++;
        elseif ($stStatus === 'Absent') $absentCount++;
        elseif ($stStatus === 'On Leave') $onLeaveCount++;
    }
}
$attendedTotal = $presentCount + $lateCount;
$attendanceRate = ($totalStaff > 0 && ($presentCount + $lateCount + $absentCount) > 0) 
    ? round(($attendedTotal / max(1, ($attendedTotal + $absentCount))) * 100, 1) 
    : 100.0;
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'سجل حضور ودوام الكوادر' : 'Staff Daily Attendance Tracker' ?> - <?= __('portal_title') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'سجل حضور ودوام الكوادر' : 'Faculty & Staff Attendance',
    'back_url' => 'index.php',
    'extra_nav_items' => [
        '<a href="departments.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sitemap me-1 text-primary"></i> ' . (is_rtl() ? 'الأقسام' : 'Departments') . '</a>',
        '<a href="leaves.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-person-walking-arrow-right me-1 text-primary"></i> ' . __('staff_leaves') . '</a>'
    ]
]) ?>

<div class="container-fluid px-4 py-4">

    <!-- Flash Alert -->
    <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-xs mb-4" role="alert">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-info' ?> fs-5"></i>
            <div><?= htmlspecialchars($msg) ?></div>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter & Date Controls Bar -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-3 mb-4">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4 col-sm-6">
                <label class="form-label fw-bold text-foreground small mb-1">
                    <i class="fa-solid fa-calendar-day text-primary me-1"></i><?= is_rtl() ? 'تاريخ الحضور' : 'Attendance Date' ?>
                </label>
                <input type="date" name="date" class="form-control form-control-sm fw-semibold" value="<?= htmlspecialchars($selected_date) ?>" onchange="this.form.submit()">
            </div>
            <div class="col-md-4 col-sm-6">
                <label class="form-label fw-bold text-foreground small mb-1">
                    <i class="fa-solid fa-sitemap text-primary me-1"></i><?= is_rtl() ? 'تصفية حسب القسم' : 'Filter by Department' ?>
                </label>
                <select name="dept_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value=""><?= is_rtl() ? 'جميع الأقسام والكوادر' : 'All Departments' ?></option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['department_id'] ?>" <?= ($selected_dept == $d['department_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($d['department_name']) ?> (<?= htmlspecialchars($d['department_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 col-12 d-flex justify-content-md-end gap-2">
                <button type="button" class="btn btn-outline-border btn-sm px-3" onclick="markAllStatus('Present')">
                    <i class="fa-solid fa-check-double text-success me-1"></i><?= is_rtl() ? 'تحديد الكل حاضر' : 'Mark All Present' ?>
                </button>
                <button type="submit" form="attendanceForm" class="btn btn-primary btn-sm px-4 fw-bold shadow-xs">
                    <i class="fa-solid fa-floppy-disk me-1"></i><?= is_rtl() ? 'حفظ السجل' : 'Save Sheet' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Attendance Stats Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'حضور تام' : 'Present' ?></span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill">On Time</span>
                </div>
                <div class="fs-4 fw-bold font-heading text-success"><?= $presentCount ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'موظف في الوقت المحدد' : 'Checked-in prompt' ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'متأخر' : 'Late Arrival' ?></span>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle rounded-pill">Tardy</span>
                </div>
                <div class="fs-4 fw-bold font-heading text-warning"><?= $lateCount ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'تأخر عن الدوام' : 'Late arrivals' ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'غياب' : 'Absent' ?></span>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle rounded-pill">Unexcused</span>
                </div>
                <div class="fs-4 fw-bold font-heading text-danger"><?= $absentCount ?></div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'غائب بدون عذر' : 'Absent staff' ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'نسبة الانضباط' : 'Punctuality Rate' ?></span>
                    <div class="action-icon-box icon-box-primary" style="width: 2rem; height: 2rem; font-size: 0.9rem; border-radius: var(--radius-md);">
                        <i class="fa-solid fa-percent"></i>
                    </div>
                </div>
                <div class="fs-4 fw-bold font-heading text-primary"><?= $attendanceRate ?>%</div>
                <div class="small text-muted-foreground"><?= is_rtl() ? 'إجمالي نسبة الحضور' : 'Daily compliance' ?></div>
            </div>
        </div>
    </div>

    <!-- Attendance Form Table -->
    <div class="card border border-border bg-card shadow-sm rounded-xl overflow-hidden">
        <div class="card-header bg-card py-3 px-4 border-bottom border-border d-flex justify-content-between align-items-center">
            <h6 class="fw-bold font-heading text-foreground mb-0">
                <i class="fa-solid fa-clipboard-user me-2 text-primary"></i>
                <?= is_rtl() ? 'كشف حضور وانصراف الكادر ليوم' : 'Staff Attendance Roll for' ?>: 
                <span class="text-primary font-mono"><?= htmlspecialchars($selected_date) ?></span>
            </h6>
            <span class="badge bg-secondary text-muted-foreground border border-border px-2.5 py-1">
                <?= count($staffList) ?> <?= is_rtl() ? 'موظف مدرج' : 'Staff Listed' ?>
            </span>
        </div>

        <form id="attendanceForm" method="POST">
            <input type="hidden" name="save_attendance" value="1">
            <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selected_date) ?>">

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-secondary small text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3" style="width: 28%;"><?= is_rtl() ? 'الموظف' : 'Staff Member' ?></th>
                            <th class="py-3" style="width: 16%;"><?= is_rtl() ? 'القسم والوظيفة' : 'Department & Role' ?></th>
                            <th class="py-3" style="width: 18%;"><?= is_rtl() ? 'حالة الدوام' : 'Attendance Status' ?></th>
                            <th class="py-3" style="width: 12%;"><?= is_rtl() ? 'وقت الحضور' : 'Clock In' ?></th>
                            <th class="py-3" style="width: 12%;"><?= is_rtl() ? 'وقت الانصراف' : 'Clock Out' ?></th>
                            <th class="py-3" style="width: 7%;"><?= is_rtl() ? 'تأخير (د)' : 'Late (m)' ?></th>
                            <th class="px-4 py-3" style="width: 15%;"><?= is_rtl() ? 'ملاحظات' : 'Remarks' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($staffList)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted-foreground">
                                    <i class="fa-solid fa-users-slash fs-2 mb-2 d-block opacity-50"></i>
                                    <?= is_rtl() ? 'لا يوجد موظفون متاحون لهذا الاختيار.' : 'No active staff members found for this criteria.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($staffList as $staff): 
                                $empId = $staff['employee_id'];
                                $curStatus = $staff['attendance_status'] ?? 'Present';
                                $curIn = $staff['check_in_time'] ?? ($curStatus === 'Present' ? '08:00' : '');
                                $curOut = $staff['check_out_time'] ?? ($curStatus === 'Present' ? '15:00' : '');
                                $curLate = $staff['late_minutes'] ?? 0;
                                $curNotes = $staff['notes'] ?? '';
                            ?>
                                <tr>
                                    <td class="px-4 py-3">
                                        <div class="d-flex align-items-center gap-2.5">
                                            <div class="avatar-badge flex-shrink-0" style="width: 2rem; height: 2rem; font-size: 0.8rem;">
                                                <?= mb_substr($staff['full_name'], 0, 1) ?>
                                            </div>
                                            <div>
                                                <div class="fw-bold text-foreground small"><?= htmlspecialchars($staff['full_name']) ?></div>
                                                <span class="text-muted-foreground font-mono" style="font-size: 0.72rem;"><?= htmlspecialchars($staff['employee_code'] ?: '#' . $staff['employee_id']) ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3">
                                        <div class="small fw-semibold text-foreground"><?= htmlspecialchars($staff['department_name'] ?: ($staff['category'] ?? 'Support')) ?></div>
                                        <div class="text-muted-foreground" style="font-size: 0.72rem;"><?= htmlspecialchars($staff['job_title'] ?: $staff['category']) ?></div>
                                    </td>
                                    <td class="py-3">
                                        <select name="attendance[<?= $empId ?>][status]" class="form-select form-select-sm fw-semibold status-select" onchange="handleStatusColor(this)">
                                            <option value="Present" <?= $curStatus === 'Present' ? 'selected' : '' ?> class="text-success"><?= is_rtl() ? 'حاضر' : 'Present' ?></option>
                                            <option value="Late" <?= $curStatus === 'Late' ? 'selected' : '' ?> class="text-warning"><?= is_rtl() ? 'متأخر' : 'Late' ?></option>
                                            <option value="Absent" <?= $curStatus === 'Absent' ? 'selected' : '' ?> class="text-danger"><?= is_rtl() ? 'غائب' : 'Absent' ?></option>
                                            <option value="Half-Day" <?= $curStatus === 'Half-Day' ? 'selected' : '' ?> class="text-info"><?= is_rtl() ? 'نصف دوام' : 'Half-Day' ?></option>
                                            <option value="On Leave" <?= $curStatus === 'On Leave' ? 'selected' : '' ?> class="text-secondary"><?= is_rtl() ? 'إجازة رسمية' : 'On Leave' ?></option>
                                            <option value="Excused" <?= $curStatus === 'Excused' ? 'selected' : '' ?> class="text-primary"><?= is_rtl() ? 'غياب مبرر' : 'Excused' ?></option>
                                        </select>
                                    </td>
                                    <td class="py-3">
                                        <input type="time" name="attendance[<?= $empId ?>][check_in_time]" value="<?= htmlspecialchars($curIn) ?>" class="form-control form-control-sm text-center font-mono">
                                    </td>
                                    <td class="py-3">
                                        <input type="time" name="attendance[<?= $empId ?>][check_out_time]" value="<?= htmlspecialchars($curOut) ?>" class="form-control form-control-sm text-center font-mono">
                                    </td>
                                    <td class="py-3">
                                        <input type="number" min="0" max="360" name="attendance[<?= $empId ?>][late_minutes]" value="<?= intval($curLate) ?>" class="form-control form-control-sm text-center font-mono">
                                    </td>
                                    <td class="px-4 py-3">
                                        <input type="text" name="attendance[<?= $empId ?>][notes]" value="<?= htmlspecialchars($curNotes) ?>" placeholder="<?= is_rtl() ? 'ملاحظات...' : 'Remarks...' ?>" class="form-control form-control-sm">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-card py-3 px-4 border-top border-border d-flex justify-content-between align-items-center">
                <span class="text-muted-foreground small">
                    <i class="fa-solid fa-clock me-1 text-primary"></i> <?= is_rtl() ? 'يتم حفظ السجلات مباشرة في قاعدة بيانات شؤون الموظفين.' : 'Records are synced immediately with HR payroll logs.' ?>
                </span>
                <button type="submit" class="btn btn-primary btn-sm px-4 fw-bold shadow-xs">
                    <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ ومزامنة الحضور' : 'Save & Sync Attendance' ?>
                </button>
            </div>
        </form>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function markAllStatus(status) {
    document.querySelectorAll('.status-select').forEach(sel => {
        sel.value = status;
        handleStatusColor(sel);
    });
}

function handleStatusColor(sel) {
    sel.classList.remove('text-success', 'text-warning', 'text-danger', 'text-info', 'text-secondary', 'text-primary');
    if (sel.value === 'Present') sel.classList.add('text-success');
    else if (sel.value === 'Late') sel.classList.add('text-warning');
    else if (sel.value === 'Absent') sel.classList.add('text-danger');
    else if (sel.value === 'Half-Day') sel.classList.add('text-info');
    else if (sel.value === 'On Leave') sel.classList.add('text-secondary');
    else if (sel.value === 'Excused') sel.classList.add('text-primary');
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.status-select').forEach(handleStatusColor);
});
</script>
</body>
</html>
