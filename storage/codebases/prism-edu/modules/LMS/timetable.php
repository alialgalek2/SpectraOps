<?php
/**
 * LMS Module - Timetable & Scheduling with Automated Collision & Conflict Resolver
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireLogin();

$user_role = Auth::role();
$user_id   = Auth::id();

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle Schedule Slot Addition with Automated Collision Check
if ($user_role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_schedule') {
    $class_id    = intval($_POST['class_id']);
    $subject_id  = intval($_POST['subject_id']);
    $teacher_id  = intval($_POST['teacher_id']);
    $day_of_week = trim($_POST['day_of_week']);
    $start_time  = trim($_POST['start_time']);
    $end_time    = trim($_POST['end_time']);
    $room_no     = trim($_POST['room_no']);

    if ($class_id > 0 && $subject_id > 0 && $teacher_id > 0 && !empty($start_time) && !empty($end_time)) {
        // -------------------------------------------------------------
        // AUTOMATED CONFLICT DETECTION ENGINE
        // -------------------------------------------------------------
        $conflicts = [];

        // 1. Check Teacher Double Booking
        $tCheck = $pdo->prepare("
            SELECT t.*, c.class_name, s.subject_name 
            FROM timetables t
            JOIN classes c ON t.class_id = c.class_id
            JOIN subjects s ON t.subject_id = s.subject_id
            WHERE t.teacher_id = ? AND t.day_of_week = ?
              AND (t.start_time < ? AND t.end_time > ?)
            LIMIT 1
        ");
        $tCheck->execute([$teacher_id, $day_of_week, $end_time, $start_time]);
        $teacherConflict = $tCheck->fetch();
        if ($teacherConflict) {
            $conflicts[] = "Teacher Collision: Teacher is already scheduled for <strong>{$teacherConflict['class_name']} ({$teacherConflict['subject_name']})</strong> from {$teacherConflict['start_time']} to {$teacherConflict['end_time']}.";
        }

        // 2. Check Room Collision (if room specified)
        if (!empty($room_no)) {
            $rCheck = $pdo->prepare("
                SELECT t.*, c.class_name, s.subject_name, u.full_name as teacher_name
                FROM timetables t
                JOIN classes c ON t.class_id = c.class_id
                JOIN subjects s ON t.subject_id = s.subject_id
                JOIN users u ON t.teacher_id = u.user_id
                WHERE LOWER(t.room_no) = LOWER(?) AND t.day_of_week = ?
                  AND (t.start_time < ? AND t.end_time > ?)
                LIMIT 1
            ");
            $rCheck->execute([$room_no, $day_of_week, $end_time, $start_time]);
            $roomConflict = $rCheck->fetch();
            if ($roomConflict) {
                $conflicts[] = "Room Collision: Room <strong>{$room_no}</strong> is already booked for <strong>{$roomConflict['class_name']} ({$roomConflict['teacher_name']})</strong> at this time.";
            }
        }

        // 3. Check Class Schedule Collision
        $cCheck = $pdo->prepare("
            SELECT t.*, s.subject_name, u.full_name as teacher_name
            FROM timetables t
            JOIN subjects s ON t.subject_id = s.subject_id
            JOIN users u ON t.teacher_id = u.user_id
            WHERE t.class_id = ? AND t.day_of_week = ?
              AND (t.start_time < ? AND t.end_time > ?)
            LIMIT 1
        ");
        $cCheck->execute([$class_id, $day_of_week, $end_time, $start_time]);
        $classConflict = $cCheck->fetch();
        if ($classConflict) {
            $conflicts[] = "Class Collision: This class already has <strong>{$classConflict['subject_name']}</strong> scheduled at this timeslot.";
        }

        if (!empty($conflicts)) {
            $_SESSION['flash_msg'] = "<strong>Schedule Conflict Detected!</strong><br>• " . implode("<br>• ", $conflicts);
            $_SESSION['flash_msg_type'] = "danger";
            header("Location: timetable.php");
            exit();
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO timetables (class_id, subject_id, teacher_id, day_of_week, start_time, end_time, room_no) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$class_id, $subject_id, $teacher_id, $day_of_week, $start_time, $end_time, $room_no]);
                $_SESSION['flash_msg'] = "Class schedule slot added without any teacher or room conflicts!";
                $_SESSION['flash_msg_type'] = "success";
                header("Location: timetable.php");
                exit();
            } catch (\PDOException $e) {
                $msg = "Error adding slot: " . $e->getMessage();
                $msg_type = "danger";
            }
        }
    } else {
        $msg = "Please fill out all mandatory schedule fields.";
        $msg_type = "danger";
    }
}

// Delete Slot / Allocation.  These are POST-only to prevent accidental or
// link-triggered deletions.
if ($user_role === 'admin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'delete_allocation') {
        $alloc_id = intval($_POST['allocation_id'] ?? 0);
        if ($alloc_id > 0) {
            $pdo->prepare("DELETE FROM timetable_allocations WHERE allocation_id = ?")->execute([$alloc_id]);
            $_SESSION['flash_msg'] = is_rtl() ? "تم حذف الحصة / النشاط من الجدول بنجاح." : "Schedule slot / activity removed successfully.";
            $_SESSION['flash_msg_type'] = "warning";
            header("Location: timetable.php");
            exit();
        }
    } elseif (($_POST['action'] ?? '') === 'delete_slot') {
        $slot_id = intval($_POST['slot_id'] ?? 0);
        if ($slot_id > 0) {
            $pdo->prepare("DELETE FROM timetables WHERE schedule_id = ?")->execute([$slot_id]);
            $pdo->prepare("DELETE FROM timetable_allocations WHERE allocation_id = ?")->execute([$slot_id]);
            $_SESSION['flash_msg'] = "Schedule slot removed.";
            $_SESSION['flash_msg_type'] = "warning";
            header("Location: timetable.php");
            exit();
        }
    }
}

// Filter variables
$filter_class   = intval($_GET['filter_class'] ?? 0);
$filter_teacher = intval($_GET['filter_teacher'] ?? 0);
$filter_day     = trim($_GET['filter_day'] ?? '');

$classes  = $pdo->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll();
$subjects = $pdo->query("SELECT * FROM subjects ORDER BY subject_name ASC")->fetchAll();
$teachers = $pdo->query("SELECT user_id, full_name FROM users WHERE role = 'teacher' AND status = 'Active' ORDER BY full_name ASC")->fetchAll();
$periods  = $pdo->query("SELECT * FROM timetable_periods ORDER BY sort_order ASC")->fetchAll();

$query = "
    SELECT a.*, 
           c.class_name, 
           u.full_name AS teacher_name, 
           p.period_name, 
           p.start_time AS p_start, 
           p.end_time AS p_end
    FROM timetable_allocations a 
    LEFT JOIN classes c ON a.class_id = c.class_id 
    LEFT JOIN users u ON a.teacher_id = u.user_id 
    LEFT JOIN timetable_periods p ON a.period_id = p.period_id
    WHERE 1=1
";
$params = [];

if ($filter_class > 0) {
    $query .= " AND a.class_id = ?";
    $params[] = $filter_class;
}
if ($filter_teacher > 0) {
    $query .= " AND a.teacher_id = ?";
    $params[] = $filter_teacher;
}
if (!empty($filter_day)) {
    $query .= " AND a.day_of_week = ?";
    $params[] = $filter_day;
}

$query .= " ORDER BY FIELD(a.day_of_week, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), COALESCE(a.start_time, p.start_time) ASC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$schedules = $stmt->fetchAll();

// Fallback if timetable_allocations is empty
if (empty($schedules) && empty($filter_class) && empty($filter_teacher) && empty($filter_day)) {
    $legacyStmt = $pdo->query("
        SELECT t.*, t.schedule_id AS allocation_id, t.room_no AS room_number, 
               c.class_name, sub.subject_name, sub.subject_code, 'class' AS allocation_type, u.full_name AS teacher_name 
        FROM timetables t 
        JOIN classes c ON t.class_id = c.class_id 
        JOIN subjects sub ON t.subject_id = sub.subject_id 
        JOIN users u ON t.teacher_id = u.user_id 
        ORDER BY FIELD(t.day_of_week, 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'), t.start_time ASC
    ");
    $schedules = $legacyStmt->fetchAll();
}
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('class_timetables') ?> &amp; <?= __('conflict_detector') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-banner { 
            background: linear-gradient(135deg, #00b894 0%, #0984e3 100%); 
            color: white; 
            border-radius: 16px; 
        }
        .card-custom { border: none; border-radius: 14px; box-shadow: 0 4px 12px rgba(0,0,0,0.06); }
        .slot-card {
            border-left: 4px solid #00b894;
            background: #fff;
            transition: all 0.2s;
        }
        .slot-card:hover {
            transform: translateX(4px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        }
    </style>
</head>
<body class="bg-light">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => __('class_timetables'),
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container-fluid px-4 py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Banner -->
    <div class="p-4 p-md-5 mb-4 hero-banner shadow-sm">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <h2 class="fw-bold mb-1"><i class="fa-solid fa-calendar-week me-2"></i><?= __('class_timetables') ?> &amp; Scheduling Engine</h2>
                <p class="mb-0 opacity-75">Automated collision detection prevents teacher double-booking and room overlap.</p>
            </div>
            <div class="d-flex gap-2">
                <span class="badge bg-white text-success fs-6 px-3 py-2 shadow-sm">
                    <i class="fa-solid fa-shield-halved me-1"></i> Conflict Detection Active
                </span>
            </div>
        </div>
    </div>

    <!-- Main Workspace -->
    <div class="row g-4">
        <!-- Left Column: Add Slot (Admin Only) -->
        <?php if ($user_role === 'admin'): ?>
        <div class="col-lg-4">
            <div class="card card-custom p-4 bg-white sticky-top" style="top: 20px;">
                <h5 class="fw-bold text-dark mb-3">
                    <i class="fa-solid fa-circle-plus me-2 text-success"></i>Schedule New Period
                </h5>
                <form method="POST">
                    <input type="hidden" name="action" value="add_schedule">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted"><?= __('select_class') ?> <span class="text-danger">*</span></label>
                        <select name="class_id" class="form-select fw-bold" required>
                            <option value="">-- <?= __('select_class') ?> --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['class_id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted"><?= __('select_subject') ?> <span class="text-danger">*</span></label>
                        <select name="subject_id" class="form-select fw-bold" required>
                            <option value="">-- <?= __('select_subject') ?> --</option>
                            <?php foreach ($subjects as $s): ?>
                                <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_name']) ?> (<?= $s['subject_code'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted"><?= __('select_teacher') ?> <span class="text-danger">*</span></label>
                        <select name="teacher_id" class="form-select fw-bold" required>
                            <option value="">-- <?= __('select_teacher') ?> --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['user_id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Day of Week <span class="text-danger">*</span></label>
                        <select name="day_of_week" class="form-select fw-bold" required>
                            <option value="Sunday">Sunday</option>
                            <option value="Monday" selected>Monday</option>
                            <option value="Tuesday">Tuesday</option>
                            <option value="Wednesday">Wednesday</option>
                            <option value="Thursday">Thursday</option>
                            <option value="Friday">Friday</option>
                            <option value="Saturday">Saturday</option>
                        </select>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" class="form-control fw-bold" value="08:00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-bold small text-muted">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" class="form-control fw-bold" value="09:00" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold small text-muted">Room / Hall Number</label>
                        <input type="text" name="room_no" class="form-control" placeholder="e.g., Room 101, Science Lab">
                    </div>

                    <button type="submit" class="btn btn-success fw-bold w-100 py-2">
                        <i class="fa-solid fa-shield-halved me-1"></i> Verify &amp; Add Period Slot
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Right Column: Master Timetable Grid & Filters -->
        <div class="<?= $user_role === 'admin' ? 'col-lg-8' : 'col-12' ?>">
            <!-- Filters -->
            <div class="card card-custom p-3 bg-white mb-4">
                <form method="GET" class="row g-2 align-items-center">
                    <div class="col-md-4 col-sm-6">
                        <select name="filter_class" class="form-select fw-bold" onchange="this.form.submit()">
                            <option value="0">-- All Classes --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['class_id'] ?>" <?= $filter_class == $c['class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-sm-6">
                        <select name="filter_teacher" class="form-select fw-bold" onchange="this.form.submit()">
                            <option value="0">-- All Teachers --</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['user_id'] ?>" <?= $filter_teacher == $t['user_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['full_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-sm-6">
                        <select name="filter_day" class="form-select fw-bold" onchange="this.form.submit()">
                            <option value="">-- All Days --</option>
                            <?php foreach (['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] as $d): ?>
                                <option value="<?= $d ?>" <?= $filter_day == $d ? 'selected' : '' ?>><?= $d ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1 col-sm-6">
                        <a href="timetable.php" class="btn btn-outline-secondary w-100" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </form>
            </div>

            <!-- Schedule List -->
            <div class="card card-custom p-4 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <h5 class="fw-bold mb-0 text-dark"><i class="fa-solid fa-list-ul me-2 text-primary"></i>Active Timetable Schedule (<?= count($schedules) ?> Periods)</h5>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($user_role === 'admin'): ?>
                            <button type="button" class="btn btn-outline-success btn-sm fw-bold" onclick="openSlotEditor('Monday', 1, <?= $filter_class ?: ($classes[0]['class_id'] ?? 1) ?>)">
                                <i class="fa-solid fa-plus me-1"></i> <?= is_rtl() ? 'إضافة نشاط / استراحة' : 'Add Activity / Break' ?>
                            </button>
                        <?php endif; ?>
                        <button type="button" class="btn btn-outline-primary btn-sm fw-bold" onclick="openPrintPreview()">
                            <i class="fa-solid fa-print me-1"></i> <?= is_rtl() ? 'معاينة وطباعة الجدول' : 'Print Timetable' ?>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Day</th>
                                <th>Time Range</th>
                                <th>Class</th>
                                <th>Subject / Activity</th>
                                <th>Teacher / Supervisor</th>
                                <th>Room / Venue</th>
                                <?php if ($user_role === 'admin'): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($schedules)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No schedule periods or activities found matching your filters.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($schedules as $s): 
                                    $startStr = !empty($s['start_time']) ? $s['start_time'] : (!empty($s['p_start']) ? $s['p_start'] : '08:00:00');
                                    $endStr   = !empty($s['end_time'])   ? $s['end_time']   : (!empty($s['p_end'])   ? $s['p_end']   : '08:45:00');
                                    $displayStart = date('h:i A', strtotime($startStr));
                                    $displayEnd   = date('h:i A', strtotime($endStr));
                                    $allocType    = $s['allocation_type'] ?? 'class';
                                    $roomVal      = $s['room_number'] ?? ($s['room_no'] ?? 'Standard Hall');
                                ?>
                                    <tr>
                                        <td><span class="badge bg-dark"><?= $s['day_of_week'] ?></span></td>
                                        <td class="fw-bold text-primary">
                                            <i class="fa-regular fa-clock me-1"></i> <?= $displayStart ?> - <?= $displayEnd ?>
                                        </td>
                                        <td><span class="badge bg-primary bg-opacity-10 text-primary border border-primary"><?= htmlspecialchars($s['class_name'] ?? ('Class #' . $s['class_id'])) ?></span></td>
                                        <td>
                                            <?php if ($allocType === 'activity'): ?>
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1"><i class="fa-solid fa-star me-1"></i>Activity</span>
                                                <strong class="text-dark"><?= htmlspecialchars($s['subject_name'] ?? 'Activity') ?></strong>
                                            <?php elseif ($allocType === 'break'): ?>
                                                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle me-1"><i class="fa-solid fa-mug-hot me-1"></i>Break</span>
                                                <strong class="text-dark"><?= htmlspecialchars($s['subject_name'] ?: 'Class Break') ?></strong>
                                            <?php else: ?>
                                                <strong class="text-dark"><?= htmlspecialchars($s['subject_name'] ?? 'Class Lecture') ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($s['teacher_name'])): ?>
                                                <i class="fa-solid fa-chalkboard-user me-1 text-success"></i> <?= htmlspecialchars($s['teacher_name']) ?>
                                            <?php else: ?>
                                                <span class="text-muted fst-italic">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border">
                                                <i class="fa-solid fa-location-dot me-1 text-secondary"></i> <?= htmlspecialchars($roomVal) ?>
                                            </span>
                                        </td>
                                        <?php if ($user_role === 'admin'): ?>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <button type="button" class="btn btn-outline-primary" onclick='openSlotEditor(<?= json_encode($s['day_of_week']) ?>, <?= intval($s['period_id'] ?? 1) ?>, <?= intval($s['class_id'] ?? 1) ?>, <?= htmlspecialchars(json_encode($s), ENT_QUOTES, 'UTF-8') ?>)' title="Adjust Time &amp; Edit Slot">
                                                        <i class="fa-solid fa-pen-to-square"></i>
                                                    </button>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Remove this slot from the timetable?');">
                                                        <input type="hidden" name="action" value="delete_allocation">
                                                        <input type="hidden" name="allocation_id" value="<?= intval($s['allocation_id'] ?? 0) ?>">
                                                        <button type="submit" class="btn btn-outline-danger" title="Delete Slot">
                                                        <i class="fa-solid fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        <?php endif; ?>
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

<!-- Intermediary Print Preview Modal -->
<div class="modal fade" id="timetablePrintModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content shadow-lg border-0 rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-2.5 px-3">
                <h6 class="modal-title fw-bold">
                    <i class="fa-solid fa-file-invoice me-2 text-primary"></i><?= is_rtl() ? 'معاينة الطباعة لجدول الحصص' : 'Timetable Print Preview' ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- Filter / Adjustment Bar -->
            <div class="modal-body p-3 bg-light">
                <div class="row g-2 align-items-center mb-3 no-print">
                    <div class="col-auto">
                        <label class="form-label small fw-bold mb-0 text-muted"><?= is_rtl() ? 'النوع:' : 'Mode:' ?></label>
                    </div>
                    <div class="col-auto">
                        <select id="modalPrintType" class="form-select form-select-sm fw-bold" onchange="onModalTypeChange()">
                            <option value="class"><?= is_rtl() ? 'جدول الفصل' : 'Class Timetable' ?></option>
                            <option value="teacher"><?= is_rtl() ? 'جدول المعلم' : 'Teacher Schedule' ?></option>
                        </select>
                    </div>
                    
                    <div class="col-auto" id="modalClassWrapper">
                        <select id="modalClassId" class="form-select form-select-sm fw-bold" onchange="refreshPrintPreview()">
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= $c['class_id'] ?>" <?= ($filter_class == $c['class_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-auto d-none" id="modalTeacherWrapper">
                        <select id="modalTeacherId" class="form-select form-select-sm fw-bold" onchange="refreshPrintPreview()">
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= $t['user_id'] ?>" <?= ($filter_teacher == $t['user_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($t['full_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-auto">
                        <span class="badge bg-white text-secondary border px-2.5 py-1.5 shadow-xs" style="font-size: 0.75rem;">
                            <i class="fa-solid fa-file-pdf me-1 text-danger"></i><?= is_rtl() ? 'صفحة أفقية واحدة (A4 Landscape)' : '1-Page A4 Landscape Fitted' ?>
                        </span>
                    </div>

                    <div class="col text-end">
                        <button type="button" class="btn btn-success btn-sm fw-bold px-3 shadow-xs" onclick="executeTargetedPrint()">
                            <i class="fa-solid fa-print me-1"></i> <?= is_rtl() ? 'طباعة / حفظ PDF' : 'Print / Save PDF' ?>
                        </button>
                    </div>
                </div>

                <!-- Isolated Iframe Matrix Viewport (Single-Page Landscape Replica) -->
                <div class="border rounded-3 bg-white shadow-xs" style="height: 560px; max-height: calc(85vh - 120px); overflow: hidden;">
                    <iframe id="printPreviewFrame" src="about:blank" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Slot Edit / Configuration Modal (Ad-hoc Activity, Custom Break & Time Adjustment Support) -->
<div class="modal fade" id="slotEditorModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form id="slotEditorForm" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-dark text-white py-2.5 px-3">
                <h6 class="modal-title fw-bold" id="slotModalTitle">
                    <i class="fa-solid fa-sliders me-2 text-primary"></i><?= is_rtl() ? 'تكوين الحصة / النشاط وتعديل التوقيت' : 'Configure Schedule Slot & Adjust Time' ?>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3 bg-light">
                <input type="hidden" name="allocation_id" id="slotAllocationId" value="0">

                <!-- Slot Type Selector -->
                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'نوع الحصة / الفترة' : 'Slot Type' ?></label>
                    <select name="allocation_type" id="slotTypeSelect" class="form-select form-select-sm fw-bold" onchange="toggleSlotFields()">
                        <option value="activity"><?= is_rtl() ? 'نشاط عام / طابور صباحي / رياضة / نوادي' : 'General Activity / Assembly / Sports / Clubs' ?></option>
                        <option value="class"><?= is_rtl() ? 'مادة دراسية منتظمة' : 'Regular Academic Subject' ?></option>
                        <option value="break"><?= is_rtl() ? 'استراحة فصلية مخصصة' : 'Custom Class Break' ?></option>
                    </select>
                </div>

                <!-- Class Selector -->
                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'الفصل الدراسي' : 'Target Class / Section' ?> <span class="text-danger">*</span></label>
                    <select name="class_id" id="slotClassSelect" class="form-select form-select-sm fw-bold" required>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= $c['class_id'] ?>" <?= ($filter_class == $c['class_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['class_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Day of Week & Period Reference -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'اليوم' : 'Day of Week' ?> <span class="text-danger">*</span></label>
                        <select name="day_of_week" id="slotDaySelect" class="form-select form-select-sm fw-bold" required>
                            <option value="Sunday"><?= is_rtl() ? 'الأحد' : 'Sunday' ?></option>
                            <option value="Monday" selected><?= is_rtl() ? 'الإثنين' : 'Monday' ?></option>
                            <option value="Tuesday"><?= is_rtl() ? 'الثلاثاء' : 'Tuesday' ?></option>
                            <option value="Wednesday"><?= is_rtl() ? 'الأربعاء' : 'Wednesday' ?></option>
                            <option value="Thursday"><?= is_rtl() ? 'الخميس' : 'Thursday' ?></option>
                            <option value="Friday"><?= is_rtl() ? 'الجمعة' : 'Friday' ?></option>
                            <option value="Saturday"><?= is_rtl() ? 'السبت' : 'Saturday' ?></option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'الحصة المرجعية' : 'Period Reference' ?></label>
                        <select name="period_id" id="slotPeriodSelect" class="form-select form-select-sm" onchange="onPeriodSelectChange(this)">
                            <?php foreach ($periods as $p): ?>
                                <option value="<?= $p['period_id'] ?>" data-start="<?= substr($p['start_time'], 0, 5) ?>" data-end="<?= substr($p['end_time'], 0, 5) ?>">
                                    <?= htmlspecialchars($p['period_name']) ?> (<?= substr($p['start_time'], 0, 5) ?> - <?= substr($p['end_time'], 0, 5) ?>)
                                </option>
                            <?php endforeach; ?>
                            <option value="0" data-start="08:00" data-end="08:45"><?= is_rtl() ? '-- توقيت مخصص خارج الحصص --' : '-- Custom Slot Time --' ?></option>
                        </select>
                    </div>
                </div>

                <!-- Adjustable Activity Time Bounds (Start & End Time) -->
                <div class="p-2.5 mb-3 bg-white border border-primary border-opacity-25 rounded-3 shadow-xs">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label small fw-bold text-primary mb-0">
                            <i class="fa-regular fa-clock me-1"></i><?= is_rtl() ? 'توقيت النشاط القابل للتعديل' : 'Adjustable Activity Time Range' ?> <span class="text-danger">*</span>
                        </label>
                        <span class="badge bg-primary bg-opacity-10 text-primary small" style="font-size: 0.7rem;"><?= is_rtl() ? 'مخصص' : 'Customizable' ?></span>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small text-muted mb-1" style="font-size: 0.75rem;"><?= is_rtl() ? 'وقت البدء' : 'Start Time' ?></label>
                            <input type="time" name="start_time" id="slotStartTime" class="form-control form-control-sm fw-bold" value="08:00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted mb-1" style="font-size: 0.75rem;"><?= is_rtl() ? 'وقت الانتهاء' : 'End Time' ?></label>
                            <input type="time" name="end_time" id="slotEndTime" class="form-control form-control-sm fw-bold" value="08:45" required>
                        </div>
                    </div>
                    <small class="text-muted d-block mt-1.5" style="font-size: 0.73rem;">
                        <i class="fa-solid fa-circle-info me-1 text-info"></i>
                        <?= is_rtl() ? 'يمكنك تعديل وقت البداية والنهاية بحرية لأي نشاط أو طابور أو استراحة.' : 'You can freely adjust start and end times to fit assemblies, study halls, clubs, or breaks.' ?>
                    </small>
                </div>

                <!-- Subject / Title -->
                <div class="mb-3">
                    <label class="form-label small fw-bold text-dark" id="slotSubjectLabel"><?= is_rtl() ? 'اسم النشاط / المادة' : 'Activity / Subject Title' ?> <span class="text-danger">*</span></label>
                    <input type="text" name="subject_name" id="slotSubjectName" class="form-control form-control-sm" placeholder="e.g., Morning Assembly, Football Coaching, Robotics Club, Recess" required>
                </div>

                <!-- Teacher Selector (Conditional) -->
                <div class="mb-3" id="slotTeacherField">
                    <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'المشرف / المعلم (اختياري)' : 'Supervisor / Teacher (Optional)' ?></label>
                    <select name="teacher_id" id="slotTeacherId" class="form-select form-select-sm">
                        <option value=""><?= is_rtl() ? '-- بدون معلم أو مشرف محدد --' : '-- No Specific Supervisor --' ?></option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['user_id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Room Field (Conditional) -->
                <div class="mb-3" id="slotRoomField">
                    <label class="form-label small fw-bold text-dark"><?= is_rtl() ? 'القاعة / المكان' : 'Room / Location' ?></label>
                    <input type="text" name="room_number" id="slotRoomNumber" class="form-control form-control-sm" placeholder="e.g., Main Courtyard, Sports Ground, Auditorium, Lab 2">
                </div>
            </div>
            <div class="modal-footer bg-white py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                <button type="submit" id="slotSubmitBtn" class="btn btn-primary btn-sm fw-bold px-3">
                    <i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'حفظ التغييرات' : 'Save Slot & Time' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let printModalInstance = null;
let slotModalInstance = null;

const periodTimeMap = {
    <?php foreach ($periods as $p): ?>
        "<?= $p['period_id'] ?>": {
            start: "<?= substr($p['start_time'], 0, 5) ?>",
            end: "<?= substr($p['end_time'], 0, 5) ?>"
        },
    <?php endforeach; ?>
};

function onPeriodSelectChange(selectElem) {
    const pId = selectElem.value;
    if (pId && periodTimeMap[pId]) {
        document.getElementById('slotStartTime').value = periodTimeMap[pId].start;
        document.getElementById('slotEndTime').value = periodTimeMap[pId].end;
    }
}

function openPrintPreview() {
    if (!printModalInstance) {
        printModalInstance = new bootstrap.Modal(document.getElementById('timetablePrintModal'));
    }
    printModalInstance.show();
    refreshPrintPreview();
}

function onModalTypeChange() {
    const type = document.getElementById('modalPrintType').value;
    const isClass = type === 'class';
    
    document.getElementById('modalClassWrapper').classList.toggle('d-none', !isClass);
    document.getElementById('modalTeacherWrapper').classList.toggle('d-none', isClass);
    
    refreshPrintPreview();
}

function refreshPrintPreview() {
    const type = document.getElementById('modalPrintType').value;
    const targetId = type === 'class'
        ? (document.getElementById('modalClassId').value || 1)
        : (document.getElementById('modalTeacherId').value || 1);

    const frame = document.getElementById('printPreviewFrame');
    // Standalone print matrix script inside the iframe with embedded=1
    frame.src = `../Administration/print_timetable.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(targetId)}&embedded=1`;
}

function executeTargetedPrint() {
    const frame = document.getElementById('printPreviewFrame');
    if (frame && frame.contentWindow) {
        frame.contentWindow.focus();
        frame.contentWindow.print(); // Prints ONLY the contents inside the iframe
    }
}

function toggleSlotFields() {
    const type = document.getElementById('slotTypeSelect').value;
    const teacherField = document.getElementById('slotTeacherField');
    const roomField = document.getElementById('slotRoomField');
    const subjectLabel = document.getElementById('slotSubjectLabel');

    if (type === 'break') {
        teacherField.classList.add('d-none');
        roomField.classList.add('d-none');
        if (subjectLabel) subjectLabel.innerText = "<?= is_rtl() ? 'اسم الاستراحة' : 'Break / Recess Label' ?>";
        if (!document.getElementById('slotSubjectName').value) {
            document.getElementById('slotSubjectName').value = "<?= is_rtl() ? 'استراحة / فسحة' : 'Class Recess / Break' ?>";
        }
    } else if (type === 'activity') {
        teacherField.classList.remove('d-none');
        roomField.classList.remove('d-none');
        if (subjectLabel) subjectLabel.innerText = "<?= is_rtl() ? 'اسم النشاط' : 'Activity Title / Name' ?>";
    } else {
        teacherField.classList.remove('d-none');
        roomField.classList.remove('d-none');
        if (subjectLabel) subjectLabel.innerText = "<?= is_rtl() ? 'اسم المادة الدراسية' : 'Subject Name' ?>";
    }
}

// Open Editor on Table Slot Click or trigger button
function openSlotEditor(day = 'Monday', periodId = 1, classId = 1, existingData = null) {
    if (!slotModalInstance) {
        slotModalInstance = new bootstrap.Modal(document.getElementById('slotEditorModal'));
    }
    
    const modalTitle = document.getElementById('slotModalTitle');
    
    if (existingData) {
        document.getElementById('slotAllocationId').value = existingData.allocation_id || 0;
        document.getElementById('slotTypeSelect').value = existingData.allocation_type || 'activity';
        document.getElementById('slotClassSelect').value = existingData.class_id || classId;
        document.getElementById('slotDaySelect').value = existingData.day_of_week || day;
        document.getElementById('slotPeriodSelect').value = (existingData.period_id !== undefined && existingData.period_id !== null) ? existingData.period_id : periodId;
        document.getElementById('slotSubjectName').value = existingData.subject_name || '';
        
        // Extract start time
        let st = existingData.start_time || existingData.p_start || (periodTimeMap[existingData.period_id]?.start || '08:00');
        if (st && st.length > 5) st = st.substring(0, 5);
        document.getElementById('slotStartTime').value = st || '08:00';
        
        // Extract end time
        let et = existingData.end_time || existingData.p_end || (periodTimeMap[existingData.period_id]?.end || '08:45');
        if (et && et.length > 5) et = et.substring(0, 5);
        document.getElementById('slotEndTime').value = et || '08:45';
        
        document.getElementById('slotTeacherId').value = existingData.teacher_id || '';
        document.getElementById('slotRoomNumber').value = existingData.room_number || existingData.room_no || '';
        
        modalTitle.innerHTML = `<i class="fa-solid fa-pen-to-square me-2 text-primary"></i><?= is_rtl() ? 'تعديل النشاط / الحصة وتوقيتها' : 'Edit Slot & Adjust Time' ?>`;
    } else {
        document.getElementById('slotEditorForm').reset();
        document.getElementById('slotAllocationId').value = 0;
        document.getElementById('slotTypeSelect').value = 'activity';
        document.getElementById('slotClassSelect').value = classId;
        document.getElementById('slotDaySelect').value = day;
        document.getElementById('slotPeriodSelect').value = periodId;
        
        const defaultStart = periodTimeMap[periodId]?.start || '08:00';
        const defaultEnd = periodTimeMap[periodId]?.end || '08:45';
        document.getElementById('slotStartTime').value = defaultStart;
        document.getElementById('slotEndTime').value = defaultEnd;
        
        modalTitle.innerHTML = `<i class="fa-solid fa-circle-plus me-2 text-primary"></i><?= is_rtl() ? 'إضافة نشاط / حصة جديدة' : 'Add Activity / Schedule Slot' ?>`;
    }

    toggleSlotFields();
    slotModalInstance.show();
}

// Save Handler for Slot Editor Form
document.getElementById('slotEditorForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('slotSubmitBtn');
    const origHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin me-1"></i> Saving...`;

    const formData = new FormData(this);

    fetch('save_schedule_slot.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            alert(data.message || 'Error saving slot.');
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    })
    .catch(err => {
        alert('Network error while saving slot.');
        btn.disabled = false;
        btn.innerHTML = origHtml;
    });
});
</script>
</body>
</html>
