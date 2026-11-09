<?php
/**
 * LMS Module - Attendance Tracking & Automated RFID/Biometric Roll-Call
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Access Control: Admin or Teacher
Auth::requireRole(['admin', 'teacher']);

$user_role = Auth::role();
$user_id   = Auth::id();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Selected Class & Date Filter
$class_id = intval($_GET['class_id'] ?? 0);
$date     = $_GET['date'] ?? date('Y-m-d');

// Academic Calendar Cross-Reference
$holiday_event = AcademicCalendar::isHoliday($date);
$is_school_day = AcademicCalendar::isSchoolDay($date);

// Fetch all classes
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();
if ($class_id <= 0 && !empty($classes)) {
    $class_id = $classes[0]['class_id'];
}

// Handle Attendance Submission (Roll-call)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_attendance') {
    $statuses = $_POST['status'] ?? [];
    $remarks  = $_POST['remarks'] ?? [];
    $notify   = isset($_POST['notify_parents']) ? 1 : 0;

    $alertCount = 0;
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("
            INSERT INTO student_attendance (student_id, class_id, date, status, check_in_time, tracking_method, remarks, notified_parent, recorded_by)
            VALUES (?, ?, ?, ?, CURRENT_TIME(), 'Manual', ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                remarks = VALUES(remarks),
                notified_parent = VALUES(notified_parent),
                recorded_by = VALUES(recorded_by)
        ");

        // Message dispatcher for absent/late alerts
        $msgStmt = $pdo->prepare("
            INSERT INTO messages (sender_id, receiver_id, student_id, subject, message, inquiry_type)
            SELECT ?, COALESCE(psm.parent_user_id, s.parent_id, u.user_id), s.student_id, ?, ?, 'Attendance'
            FROM students s
            LEFT JOIN parent_student_map psm ON s.student_id = psm.student_id
            LEFT JOIN users u ON (s.guardian_email IS NOT NULL AND s.guardian_email != '' AND s.guardian_email = u.email)
            WHERE s.student_id = ? AND (psm.parent_user_id IS NOT NULL OR s.parent_id IS NOT NULL OR u.user_id IS NOT NULL)
            LIMIT 1
        ");

        foreach ($statuses as $student_id => $status) {
            $student_id = intval($student_id);
            $remark = trim($remarks[$student_id] ?? '');
            $notified = 0;

            if ($notify && in_array($status, ['Absent', 'Late'])) {
                $notified = 1;
                $subj = "URGENT: Attendance Alert for {$date}";
                $body = "Dear Parent, your child was marked as {$status} today ({$date}). Please contact the school office if you have any questions.";
                $msgStmt->execute([$user_id, $subj, $body, $student_id]);
                $alertCount++;
            }

            $stmt->execute([$student_id, $class_id, $date, $status, $remark, $notified, $user_id]);
        }

        $pdo->commit();
        $_SESSION['flash_msg'] = "Attendance recorded successfully!" . ($alertCount > 0 ? " ($alertCount Parent alerts dispatched)" : "");
        $_SESSION['flash_msg_type'] = "success";
        header("Location: attendance.php?class_id={$class_id}&date={$date}");
        exit();
    } catch (\PDOException $e) {
        $pdo->rollBack();
        $msg = "Error saving attendance: " . $e->getMessage();
        $msg_type = "danger";
    }
}

// Fetch Students in this class
$studentsStmt = $pdo->prepare("
    SELECT s.student_id, s.admission_no, s.rfid_card_id, s.student_photo, u.full_name,
           att.status, att.check_in_time, att.remarks, att.tracking_method, att.notified_parent
    FROM students s
    JOIN users u ON s.user_id = u.user_id
    LEFT JOIN student_attendance att ON s.student_id = att.student_id AND att.date = ?
    WHERE s.class_id = ?
    ORDER BY u.full_name ASC
");
$studentsStmt->execute([$date, $class_id]);
$students = $studentsStmt->fetchAll();

// Summary Stats
$presentCount = 0;
$absentCount  = 0;
$lateCount    = 0;
$excusedCount = 0;
foreach ($students as $st) {
    $stat = $st['status'] ?? 'Present';
    if ($stat === 'Present') $presentCount++;
    elseif ($stat === 'Absent') $absentCount++;
    elseif ($stat === 'Late') $lateCount++;
    elseif ($stat === 'Excused') $excusedCount++;
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('attendance_tracking') ?> - School Management</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-btn {
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .status-btn.active {
            transform: scale(1.05);
            box-shadow: 0 4px 10px rgba(0,0,0,0.15);
        }
        .rfid-box {
            background: #1e293b;
            color: #38bdf8;
            border: 1px solid var(--border);
            font-family: monospace;
            border-radius: var(--radius-lg, 12px);
        }
    </style>
</head>
<body class="bg-background">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => __('attendance_tracking'),
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container-fluid px-4 py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 mb-2">
                    <span class="badge rounded-full font-medium px-3 py-1 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                        <i class="fa-solid fa-clipboard-check text-warning me-1"></i> <?= is_rtl() ? 'رصد الحضور والغياب: نشط' : 'Attendance & Roll-Call: Active' ?>
                    </span>
                </div>
                <h2 class="fw-bold font-heading mb-1 text-white"><i class="fa-solid fa-clipboard-check me-2"></i><?= __('attendance_tracking') ?></h2>
                <p class="mb-0 text-white-50 small"><?= __('attendance_tracking_sub') ?></p>
            </div>
            <div class="d-flex gap-2">
                <button class="btn btn-white btn-sm fw-bold text-dark shadow-sm" data-bs-toggle="modal" data-bs-target="#rfidModal">
                    <i class="fa-solid fa-id-card-clip me-1 text-primary"></i> <?= is_rtl() ? 'ماسح RFID / الباركود المباشر' : 'Live RFID / Barcode Scanner' ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Academic Calendar Status Banner -->
    <?php if ($holiday_event): ?>
        <div class="alert alert-warning border border-warning-subtle shadow-xs d-flex align-items-center gap-3 mb-4 rounded-xl p-3" role="alert">
            <div class="action-icon-box icon-box-warning" style="width: 2.5rem; height: 2.5rem; min-width: 2.5rem; min-height: 2.5rem;">
                <i class="fa-solid fa-umbrella-beach fs-5"></i>
            </div>
            <div>
                <div class="fw-bold text-foreground">
                    <?= is_rtl() ? 'تنبيه التقويم الأكاديمي: هذا التاريخ مسجل كعطلة أو إغلاق مدرسي!' : 'Academic Calendar Alert: Selected date is registered as a School Holiday / Closure!' ?>
                </div>
                <div class="small text-muted-foreground">
                    <strong><?= htmlspecialchars($holiday_event['title']) ?></strong> (<?= htmlspecialchars($holiday_event['event_type']) ?>) - <?= is_rtl() ? 'العمليات الدراسية معطلة في هذا اليوم وفقاً للجدول الزمني المعتمد.' : 'Instructional activities are non-operational on this scheduled date.' ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Filters & Stats Bar -->
    <div class="row g-3 mb-4">
        <!-- Class & Date Filter Card -->
        <div class="col-lg-5 col-12">
            <div class="card border border-border bg-card shadow-sm rounded-xl p-3 h-100">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-sm-5">
                        <label class="form-label small fw-bold text-muted-foreground mb-1"><?= __('select_class') ?>:</label>
                        <select name="class_id" class="form-select form-select-sm fw-bold border-border" onchange="this.form.submit()">
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['class_id'] ?>" <?= $c['class_id'] == $class_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-5">
                        <label class="form-label small fw-bold text-muted-foreground mb-1"><?= is_rtl() ? 'التاريخ' : 'Date' ?>:</label>
                        <input type="date" name="date" class="form-control form-control-sm fw-bold border-border" value="<?= htmlspecialchars($date) ?>" onchange="this.form.submit()">
                    </div>
                    <div class="col-sm-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold w-100 mt-sm-4"><?= __('filter') ?></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 4 Unified Summary Metric Cards -->
        <div class="col-lg-7 col-12">
            <div class="row g-2 h-100">
                <!-- Present -->
                <div class="col-6 col-sm-3">
                    <div class="stat-card-modern h-100 p-3">
                        <div class="d-flex align-items-start justify-content-between mb-1">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                <?= __('present') ?>
                            </div>
                            <div class="action-icon-box icon-box-success" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem;">
                                <i class="fa-solid fa-circle-check"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1 fs-3 text-success"><?= $presentCount ?></div>
                        <div class="stat-trend text-success small">
                            <i class="fa-solid fa-user-check"></i>
                            <span><?= is_rtl() ? 'حاضر' : 'Attended' ?></span>
                        </div>
                    </div>
                </div>

                <!-- Absent -->
                <div class="col-6 col-sm-3">
                    <div class="stat-card-modern h-100 p-3">
                        <div class="d-flex align-items-start justify-content-between mb-1">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                <?= __('absent') ?>
                            </div>
                            <div class="action-icon-box icon-box-destructive" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem;">
                                <i class="fa-solid fa-circle-xmark"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1 fs-3 text-danger"><?= $absentCount ?></div>
                        <div class="stat-trend text-danger small">
                            <i class="fa-solid fa-user-xmark"></i>
                            <span><?= is_rtl() ? 'غياب' : 'Absentee' ?></span>
                        </div>
                    </div>
                </div>

                <!-- Late -->
                <div class="col-6 col-sm-3">
                    <div class="stat-card-modern h-100 p-3">
                        <div class="d-flex align-items-start justify-content-between mb-1">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                <?= __('late') ?>
                            </div>
                            <div class="action-icon-box icon-box-warning" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem;">
                                <i class="fa-solid fa-clock"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1 fs-3 text-warning"><?= $lateCount ?></div>
                        <div class="stat-trend text-warning small">
                            <i class="fa-solid fa-hourglass-half"></i>
                            <span><?= is_rtl() ? 'متأخر' : 'Tardy' ?></span>
                        </div>
                    </div>
                </div>

                <!-- Excused -->
                <div class="col-6 col-sm-3">
                    <div class="stat-card-modern h-100 p-3">
                        <div class="d-flex align-items-start justify-content-between mb-1">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.03em;">
                                <?= __('excused') ?>
                            </div>
                            <div class="action-icon-box icon-box-info" style="width: 2rem; height: 2rem; min-width: 2rem; min-height: 2rem; font-size: 0.875rem;">
                                <i class="fa-solid fa-envelope-open-text"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1 fs-3 text-info"><?= $excusedCount ?></div>
                        <div class="stat-trend text-info small">
                            <i class="fa-solid fa-file-medical"></i>
                            <span><?= is_rtl() ? 'معذور' : 'Excused' ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Attendance Sheet Form -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
        <form method="POST">
            <input type="hidden" name="action" value="save_attendance">
            
            <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                <h5 class="fw-bold text-foreground mb-0">
                    <i class="fa-solid fa-list-check me-2 text-primary"></i>Daily Attendance Sheet (<?= count($students) ?> Students)
                </h5>
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" name="notify_parents" id="notifyParents" value="1" checked>
                    <label class="form-check-label fw-bold text-danger small" for="notifyParents">
                        <i class="fa-solid fa-bell me-1"></i> Send Instant Parent Alert for Absent/Late Students
                    </label>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Student</th>
                            <th>RFID / Card</th>
                            <th>Status Selector</th>
                            <th>Check-in Time</th>
                            <th>Method</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted-foreground py-4">No students enrolled in this class.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $st): ?>
                                <?php $current_status = $st['status'] ?? 'Present'; ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="action-icon-box icon-box-primary me-2" style="width: 2.25rem; height: 2.25rem; min-width: 2.25rem; min-height: 2.25rem; font-size: 0.95rem; border-radius: var(--radius-md);">
                                                <i class="fa-solid fa-user-graduate"></i>
                                            </div>
                                            <div>
                                                <a href="../Administration/student_profile.php?id=<?= $st['student_id'] ?>" class="fw-bold text-foreground text-decoration-none">
                                                    <?= htmlspecialchars($st['full_name']) ?>
                                                </a>
                                                <small class="d-block text-muted-foreground">ID: <?= htmlspecialchars($st['admission_no'] ?: 'STU-' . $st['student_id']) ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <code><?= htmlspecialchars($st['rfid_card_id'] ?: 'N/A') ?></code>
                                    </td>
                                    <td>
                                        <div class="btn-group" role="group">
                                            <input type="radio" class="btn-check" name="status[<?= $st['student_id'] ?>]" id="pres_<?= $st['student_id'] ?>" value="Present" <?= $current_status === 'Present' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-success btn-sm fw-bold" for="pres_<?= $st['student_id'] ?>">Present</label>

                                            <input type="radio" class="btn-check" name="status[<?= $st['student_id'] ?>]" id="abs_<?= $st['student_id'] ?>" value="Absent" <?= $current_status === 'Absent' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-danger btn-sm fw-bold" for="abs_<?= $st['student_id'] ?>">Absent</label>

                                            <input type="radio" class="btn-check" name="status[<?= $st['student_id'] ?>]" id="late_<?= $st['student_id'] ?>" value="Late" <?= $current_status === 'Late' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-warning btn-sm fw-bold" for="late_<?= $st['student_id'] ?>">Late</label>

                                            <input type="radio" class="btn-check" name="status[<?= $st['student_id'] ?>]" id="exc_<?= $st['student_id'] ?>" value="Excused" <?= $current_status === 'Excused' ? 'checked' : '' ?>>
                                            <label class="btn btn-outline-secondary btn-sm fw-bold" for="exc_<?= $st['student_id'] ?>">Excused</label>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary-subtle text-foreground border border-border">
                                            <i class="fa-regular fa-clock me-1"></i> <?= $st['check_in_time'] ?: '—' ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle"><?= $st['tracking_method'] ?: 'Manual' ?></span>
                                    </td>
                                    <td>
                                        <input type="text" name="remarks[<?= $st['student_id'] ?>]" class="form-control form-control-sm border-border" placeholder="Optional notes" value="<?= htmlspecialchars($st['remarks'] ?? '') ?>">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-end mt-4">
                <button type="submit" class="btn btn-success fw-bold px-4 py-2 shadow-sm rounded-lg">
                    <i class="fa-solid fa-floppy-disk me-2"></i> Save &amp; Finalize Attendance
                </button>
            </div>
        </form>
    </div>
</div>

<!-- RFID / Barcode Scanner Modal -->
<div class="modal fade" id="rfidModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content border border-border shadow-lg rounded-xl">
            <div class="modal-header bg-card border-bottom border-border">
                <h5 class="modal-title fw-bold text-foreground"><i class="fa-solid fa-qrcode me-2 text-primary"></i>Automated RFID / Barcode Reader</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="p-3 rfid-box mb-3">
                    <i class="fa-solid fa-wifi fa-3x mb-2 text-warning"></i>
                    <p class="mb-0 small">Ready for scan. Tap RFID card or scan barcode...</p>
                </div>
                <input type="text" id="rfidScanInput" class="form-control form-control-lg text-center fw-bold border-border" placeholder="Scan or type Card ID & Press Enter" autofocus>
                <div id="rfidScanResult" class="mt-3"></div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('rfidScanInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        const cardId = this.value.trim();
        if (!cardId) return;

        const resultDiv = document.getElementById('rfidScanResult');
        resultDiv.innerHTML = '<div class="spinner-border text-primary spinner-border-sm"></div> Verifying badge...';

        fetch('../../api/rfid_attendance.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ rfid_card_id: cardId })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                resultDiv.innerHTML = `
                    <div class="alert alert-success fw-bold">
                        <i class="fa-solid fa-circle-check me-2"></i> ${data.message}<br>
                        <strong>Student:</strong> ${data.student_name} (${data.class_name})<br>
                        <strong>Time:</strong> ${data.time} | Status: <span class="badge bg-success">${data.status}</span>
                    </div>
                `;
                document.getElementById('rfidScanInput').value = '';
            } else {
                resultDiv.innerHTML = `<div class="alert alert-danger fw-bold"><i class="fa-solid fa-circle-xmark me-2"></i> ${data.message}</div>`;
            }
        })
        .catch(err => {
            resultDiv.innerHTML = `<div class="alert alert-danger">Error contacting API: ${err}</div>`;
        });
    }
});
</script>
</body>
</html>
