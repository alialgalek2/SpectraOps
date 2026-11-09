<?php
/**
 * modules/Administration/print_timetable.php
 * Dedicated 2D Timetable Print Matrix Engine
 * Orientation & Axis: Time Slots on Y-Axis (Left Column), Days on X-Axis (Header Columns)
 * Cell Architecture: Subject Name (Bold), Time Range Pill, Secondary Metadata (Teacher/Class/Room)
 * Intermittent Interval Banners: Break/Lunch rows span across all day columns.
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'student';

$type      = 'class';
$target_id = 0;
$title     = '';
$sub_title = '';

// Fetch all defined periods ordered by timeline
try {
    $periods = $pdo->query("SELECT * FROM timetable_periods ORDER BY sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $periods = [];
}

if (empty($periods)) {
    $periods = [
        ['period_id' => 1, 'period_name' => 'Period 1', 'start_time' => '08:00:00', 'end_time' => '08:45:00', 'is_break' => 0],
        ['period_id' => 2, 'period_name' => 'Period 2', 'start_time' => '08:45:00', 'end_time' => '09:30:00', 'is_break' => 0],
        ['period_id' => 3, 'period_name' => 'Morning Break', 'start_time' => '09:30:00', 'end_time' => '09:50:00', 'is_break' => 1],
        ['period_id' => 4, 'period_name' => 'Period 3', 'start_time' => '09:50:00', 'end_time' => '10:35:00', 'is_break' => 0],
        ['period_id' => 5, 'period_name' => 'Period 4', 'start_time' => '10:35:00', 'end_time' => '11:20:00', 'is_break' => 0],
        ['period_id' => 6, 'period_name' => 'Lunch Break', 'start_time' => '11:20:00', 'end_time' => '12:00:00', 'is_break' => 1],
        ['period_id' => 7, 'period_name' => 'Period 5', 'start_time' => '12:00:00', 'end_time' => '12:45:00', 'is_break' => 0],
        ['period_id' => 8, 'period_name' => 'Period 6', 'start_time' => '12:45:00', 'end_time' => '13:30:00', 'is_break' => 0]
    ];
}

// Configurable school week days
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
$children = [];
$selected_child_id = 0;

// Resolve Target Entity based on Session Role
if ($role === 'teacher') {
    $type = 'teacher';
    $target_id = $user_id;
    $title = is_rtl() ? "الجدول الأسبوعي للمعلم" : "Weekly Teaching Schedule";
    $sub_title = (is_rtl() ? "المعلم: " : "Instructor: ") . htmlspecialchars($_SESSION['full_name'] ?? '');
} elseif ($role === 'student') {
    $type = 'class';
    try {
        $stmt = $pdo->prepare("SELECT class_id FROM students WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $target_id = (int)$stmt->fetchColumn();

        $clsStmt = $pdo->prepare("SELECT class_name FROM classes WHERE class_id = ?");
        $clsStmt->execute([$target_id]);
        $cName = $clsStmt->fetchColumn() ?: (is_rtl() ? 'الفصل الدراسي المخصص' : 'Assigned Section');
        $title = is_rtl() ? "جدول الحصص الأسبوعي" : "Weekly Class Timetable";
        $sub_title = (is_rtl() ? "الفصل: " : "Class: ") . htmlspecialchars($cName);
    } catch (Exception $e) {
        $title = "Weekly Class Timetable";
        $sub_title = "Assigned Section";
    }
} elseif ($role === 'parent') {
    $type = 'class';
    try {
        $stmt = $pdo->prepare("SELECT s.student_id, s.class_id, u.full_name, c.class_name 
                               FROM parent_student_map psm
                               JOIN students s ON psm.student_id = s.student_id
                               JOIN users u ON s.user_id = u.user_id
                               JOIN classes c ON s.class_id = c.class_id
                               WHERE psm.parent_user_id = ? OR psm.parent_id = ?");
        $stmt->execute([$user_id, $user_id]);
        $children = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $selected_child_id = intval($_GET['child_id'] ?? ($children[0]['student_id'] ?? 0));
        $current_child = null;
        foreach ($children as $child) {
            if ($child['student_id'] === $selected_child_id) {
                $current_child = $child;
                break;
            }
        }

        if ($current_child) {
            $target_id = (int)$current_child['class_id'];
            $title = is_rtl() ? "جدول الحصص الأسبوعي للطالب" : "Weekly Class Timetable";
            $sub_title = (is_rtl() ? "الطالب: " : "Student: ") . htmlspecialchars($current_child['full_name']) . " (" . htmlspecialchars($current_child['class_name']) . ")";
        } else {
            $target_id = 1;
            $title = "Weekly Class Timetable";
            $sub_title = "Parent Portal Overview";
        }
    } catch (Exception $e) {
        $title = "Weekly Class Timetable";
        $sub_title = "Parent Portal Overview";
    }
} else {
    $type = $_GET['type'] ?? 'class';
    $target_id = intval($_GET['id'] ?? 1);
    
    if ($type === 'teacher') {
        try {
            $uStmt = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $uStmt->execute([$target_id]);
            $tName = $uStmt->fetchColumn() ?: "Instructor #$target_id";
            $title = is_rtl() ? "الجدول الأسبوعي للمعلم" : "Teacher Schedule";
            $sub_title = (is_rtl() ? "المعلم: " : "Instructor: ") . htmlspecialchars($tName);
        } catch (Exception $e) {
            $title = "Teacher Schedule";
            $sub_title = "Master View";
        }
    } else {
        try {
            $cStmt = $pdo->prepare("SELECT class_name FROM classes WHERE class_id = ?");
            $cStmt->execute([$target_id]);
            $cName = $cStmt->fetchColumn() ?: "Class #$target_id";
            $title = is_rtl() ? "جدول الفصل الدراسي" : "Class Timetable";
            $sub_title = (is_rtl() ? "الفصل: " : "Class: ") . htmlspecialchars($cName);
        } catch (Exception $e) {
            $title = "Class Timetable";
            $sub_title = "Academic Year Master View";
        }
    }
}

// Fetch schedule allocations
$allocations = [];
try {
    if ($type === 'teacher') {
        $allocStmt = $pdo->prepare("SELECT a.*, c.class_name, a.room_number as room_no, a.subject_name 
                                    FROM timetable_allocations a 
                                    LEFT JOIN classes c ON a.class_id = c.class_id 
                                    WHERE a.teacher_id = ?");
        $allocStmt->execute([$target_id]);
        $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allocations)) {
            $stmt2 = $pdo->prepare("SELECT t.*, s.subject_name, c.class_name, t.room_no as room_number 
                                    FROM timetables t 
                                    LEFT JOIN subjects s ON t.subject_id = s.subject_id 
                                    LEFT JOIN classes c ON t.class_id = c.class_id 
                                    WHERE t.teacher_id = ?");
            $stmt2->execute([$target_id]);
            $allocations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    } else {
        $allocStmt = $pdo->prepare("SELECT a.*, u.full_name as teacher_name, a.room_number as room_no, a.subject_name 
                                    FROM timetable_allocations a 
                                    LEFT JOIN users u ON a.teacher_id = u.user_id 
                                    WHERE a.class_id = ?");
        $allocStmt->execute([$target_id]);
        $allocations = $allocStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($allocations)) {
            $stmt2 = $pdo->prepare("SELECT t.*, s.subject_name, u.full_name as teacher_name, t.room_no as room_number 
                                    FROM timetables t 
                                    LEFT JOIN subjects s ON t.subject_id = s.subject_id 
                                    LEFT JOIN users u ON t.teacher_id = u.user_id 
                                    WHERE t.class_id = ?");
            $stmt2->execute([$target_id]);
            $allocations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Exception $e) {}

// Pivot allocations into: [Period_ID][Day]
$grid = [];
foreach ($allocations as $row) {
    $periodKey = $row['period_id'] ?? null;
    $dayKey = $row['day_of_week'] ?? null;

    // If period_id is missing from old timetables, match by start_time
    if (empty($periodKey) && !empty($row['start_time'])) {
        foreach ($periods as $p) {
            if (substr($p['start_time'], 0, 5) === substr($row['start_time'], 0, 5)) {
                $periodKey = $p['period_id'];
                break;
            }
        }
    }

    if ($periodKey && $dayKey) {
        $grid[$periodKey][$dayKey] = $row;
    }
}

// School institution info
$school_name = 'Algale Academy';
try {
    $sn = $pdo->query("SELECT setting_value FROM school_settings WHERE setting_key = 'school_name'")->fetchColumn();
    if (!empty($sn)) $school_name = $sn;
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> - <?= htmlspecialchars($sub_title) ?> - <?= htmlspecialchars($school_name) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
            color: #1e293b;
        }

        .timetable-board {
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .day-header {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.85rem;
            padding: 10px 4px;
            border-radius: 8px;
            text-align: center;
        }

        .time-badge {
            background-color: #f1f5f9;
            color: #334155;
            font-weight: 700;
            font-size: 0.8rem;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 6px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            min-height: 64px;
        }

        .time-badge .period-title {
            font-size: 0.82rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 2px;
            line-height: 1.15;
        }

        .time-badge .period-hours {
            font-size: 0.72rem;
            color: #64748b;
            font-weight: 600;
            line-height: 1.1;
        }

        .slot-card {
            background-color: #ffffff;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            padding: 6px 8px;
            min-height: 64px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            transition: all 0.2s ease;
        }

        .slot-card:hover {
            border-color: #3b82f6;
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.12);
        }

        .subject-text {
            font-weight: 700;
            font-size: 0.86rem;
            color: #0f172a;
            line-height: 1.2;
            margin-bottom: 3px;
        }

        .time-pill {
            display: inline-block;
            font-size: 0.68rem;
            font-weight: 600;
            background-color: #eff6ff;
            color: #2563eb;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            padding: 1px 6px;
            margin-bottom: 3px;
            white-space: nowrap;
        }

        .meta-text {
            font-size: 0.72rem;
            color: #64748b;
            line-height: 1.15;
        }

        .room-tag {
            font-size: 0.68rem;
            font-weight: 600;
            color: #059669;
            background: #ecfdf5;
            padding: 1px 5px;
            border-radius: 4px;
            margin-top: 2px;
            display: inline-block;
        }

        .slot-card.is-activity {
            background-color: #f5f3ff;
            border-color: #ddd6fe;
        }
        .slot-card.is-activity .subject-text {
            color: #6d28d9;
        }
        .slot-card.is-activity .time-pill {
            background-color: #ede9fe;
            color: #7c3aed;
            border-color: #c4b5fd;
        }
        .activity-badge {
            display: inline-block;
            font-size: 0.64rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #7c3aed;
            background: #ede9fe;
            padding: 1px 5px;
            border-radius: 4px;
            margin-bottom: 2px;
        }

        .slot-card.is-break {
            background-color: #fefce8;
            border: 1px dashed #ca8a04;
        }
        .slot-card.is-break .subject-text {
            color: #854d0e;
            font-size: 0.82rem;
        }
        .slot-card.is-break .time-pill {
            background-color: #fef9c3;
            color: #a16207;
            border-color: #fde047;
        }

        .interval-row {
            background-color: #fef3c7 !important;
            border: 1px dashed #d97706 !important;
            color: #92400e;
            font-weight: 700;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.06em;
            border-radius: 8px;
            padding: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Embedded Iframe Preview Mode */
        body.is-embedded {
            padding: 6px !important;
            background-color: #f8fafc !important;
            height: 100vh !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
            display: flex !important;
            flex-direction: column !important;
        }
        body.is-embedded .timetable-board {
            height: 100% !important;
            max-height: 100% !important;
            display: flex !important;
            flex-direction: column !important;
            justify-content: space-between !important;
            padding: 8px 12px !important;
            border-radius: 12px !important;
            box-sizing: border-box !important;
            overflow: hidden !important;
        }
        body.is-embedded .board-header {
            margin-bottom: 4px !important;
            padding-bottom: 4px !important;
        }
        body.is-embedded table.timetable-table {
            flex: 1 1 auto !important;
            height: 100% !important;
            table-layout: fixed !important;
            border-collapse: separate !important;
            border-spacing: 2px !important;
            margin: 0 !important;
        }
        body.is-embedded .slot-card,
        body.is-embedded .time-badge {
            min-height: 0 !important;
            height: 100% !important;
            padding: 3px 4px !important;
        }
        body.is-embedded .day-header { padding: 4px 2px; font-size: 0.78rem; }
        body.is-embedded .subject-text { font-size: 0.8rem; margin-bottom: 1px; line-height: 1.15; }
        body.is-embedded .time-pill { font-size: 0.66rem; padding: 0 4px; margin-bottom: 1px; line-height: 1.2; }
        body.is-embedded .meta-text { font-size: 0.7rem; line-height: 1.1; }
        body.is-embedded .room-tag { font-size: 0.66rem; padding: 0 3px; margin-top: 1px; }
        body.is-embedded .activity-badge { font-size: 0.62rem; padding: 0 3px; margin-bottom: 1px; }
        body.is-embedded .interval-row { padding: 4px 6px; font-size: 0.76rem; }
        body.is-embedded .board-footer {
            margin-top: 4px !important;
            padding-top: 4px !important;
            font-size: 0.72rem !important;
        }

        /* Strict Single-Page Landscape Print Architecture */
        @media print {
            @page {
                size: landscape;
                margin: 3mm 4mm 3mm 4mm;
            }
            html, body {
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                overflow: hidden !important;
                font-size: 8.5pt !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print {
                display: none !important;
            }
            .timetable-board {
                width: 100% !important;
                height: 100vh !important;
                max-height: 100vh !important;
                margin: 0 !important;
                padding: 4px 6px !important;
                border: 1.5px solid #1e293b !important;
                border-radius: 4px !important;
                box-shadow: none !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: space-between !important;
                box-sizing: border-box !important;
                page-break-inside: avoid !important;
                page-break-after: avoid !important;
                overflow: hidden !important;
            }
            .board-header {
                margin-bottom: 2px !important;
                padding-bottom: 2px !important;
                border-bottom: 1px solid #cbd5e1 !important;
            }
            .board-header .school-name {
                font-size: 9.5pt !important;
                font-weight: 800 !important;
                line-height: 1.1 !important;
            }
            .board-header .school-sub {
                font-size: 6.5pt !important;
                line-height: 1 !important;
            }
            .board-header .main-title {
                font-size: 10.5pt !important;
                font-weight: 800 !important;
                margin: 0 !important;
                line-height: 1.1 !important;
                text-transform: uppercase !important;
            }
            .board-header .sub-title {
                font-size: 7.5pt !important;
                font-weight: 600 !important;
                line-height: 1.1 !important;
            }
            .board-header .print-meta {
                font-size: 6.5pt !important;
                line-height: 1 !important;
            }
            table.timetable-table {
                width: 100% !important;
                height: 100% !important;
                flex: 1 1 auto !important;
                table-layout: fixed !important;
                border-collapse: separate !important;
                border-spacing: 2px !important;
                margin: 0 !important;
            }
            table.timetable-table thead th {
                padding: 1px 2px !important;
                height: 20px !important;
            }
            .day-header {
                padding: 2px 4px !important;
                font-size: 7.5pt !important;
                font-weight: 700 !important;
                border-radius: 3px !important;
                background-color: #0f172a !important;
                color: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            table.timetable-table tbody tr {
                page-break-inside: avoid !important;
            }
            table.timetable-table tbody td {
                padding: 1px !important;
                vertical-align: middle !important;
            }
            .time-badge {
                min-height: 0 !important;
                height: 100% !important;
                padding: 2px 3px !important;
                border-radius: 3px !important;
                border: 1px solid #94a3b8 !important;
                background-color: #f8fafc !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .time-badge .period-title {
                font-size: 7.5pt !important;
                font-weight: 800 !important;
                margin: 0 !important;
                line-height: 1.1 !important;
            }
            .time-badge .period-hours {
                font-size: 6.5pt !important;
                font-weight: 600 !important;
                color: #475569 !important;
                margin: 0 !important;
                line-height: 1.1 !important;
            }
            .slot-card {
                min-height: 0 !important;
                height: 100% !important;
                padding: 2px 3px !important;
                border-radius: 3px !important;
                border: 1px solid #94a3b8 !important;
                box-shadow: none !important;
                background-color: #fff !important;
                display: flex !important;
                flex-direction: column !important;
                justify-content: center !important;
                align-items: center !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .subject-text {
                font-size: 8pt !important;
                font-weight: 700 !important;
                line-height: 1.15 !important;
                margin-bottom: 1px !important;
            }
            .time-pill {
                font-size: 6.5pt !important;
                font-weight: 600 !important;
                padding: 0 4px !important;
                margin-bottom: 1px !important;
                border: 1px solid #94a3b8 !important;
                background-color: #f8fafc !important;
                color: #000 !important;
                border-radius: 8px !important;
                line-height: 1.15 !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .meta-text {
                font-size: 7pt !important;
                line-height: 1.05 !important;
                color: #334155 !important;
            }
            .room-tag {
                font-size: 6.5pt !important;
                font-weight: 600 !important;
                padding: 0 3px !important;
                margin-top: 1px !important;
                border: 1px solid #cbd5e1 !important;
                background-color: #f8fafc !important;
                color: #000 !important;
                border-radius: 3px !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .activity-badge {
                font-size: 6pt !important;
                font-weight: 700 !important;
                padding: 0 3px !important;
                margin-bottom: 1px !important;
                border-radius: 3px !important;
                background-color: #ede9fe !important;
                color: #7c3aed !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .interval-row {
                padding: 3px 6px !important;
                font-size: 7.5pt !important;
                font-weight: 700 !important;
                border-radius: 3px !important;
                border: 1px solid #94a3b8 !important;
                background-color: #f1f5f9 !important;
                color: #0f172a !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .board-footer {
                margin-top: 2px !important;
                padding-top: 2px !important;
                border-top: 1px solid #cbd5e1 !important;
                font-size: 7pt !important;
                line-height: 1 !important;
                display: flex !important;
                justify-content: space-between !important;
                align-items: center !important;
            }
        }
    </style>
</head>
<?php
$is_embedded = isset($_GET['embedded']);
?>
<body class="<?= $is_embedded ? 'is-embedded' : 'p-3 p-md-4' ?>">

    <?php if (!$is_embedded): ?>
        <!-- Action Toolbar (Hidden during print or when embedded in preview iframe) -->
        <div class="no-print mb-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
            <a href="../../dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="fa-solid fa-arrow-<?= is_rtl() ? 'right' : 'left' ?> me-1"></i> <?= is_rtl() ? 'العودة للوحة التحكم' : 'Dashboard' ?>
            </a>

            <div class="d-flex align-items-center gap-2">
                <?php if ($role === 'parent' && count($children) > 1): ?>
                    <select class="form-select form-select-sm" onchange="location.href='?child_id=' + this.value;" style="min-width: 220px;">
                        <?php foreach ($children as $c): ?>
                            <option value="<?= $c['student_id'] ?>" <?= $c['student_id'] === $selected_child_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['class_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button onclick="window.print()" class="btn btn-primary btn-sm fw-bold shadow-xs">
                    <i class="fa-solid fa-print me-1"></i> <?= is_rtl() ? 'طباعة الجدول' : 'Print Timetable' ?>
                </button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Main Print Board Container -->
    <div class="timetable-board">
        
        <!-- Header Section -->
        <div class="board-header">
            <div class="d-flex align-items-center justify-content-between pb-1 mb-1">
                <div class="text-start">
                    <div class="school-name fw-bold fs-6 text-dark"><?= htmlspecialchars($school_name) ?></div>
                    <div class="school-sub text-muted small" style="font-size: 0.72rem;"><?= is_rtl() ? 'نظام الإدارة الأكاديمية الذكي' : 'Academic Management System' ?></div>
                </div>
                <div class="text-center">
                    <h4 class="main-title fw-bold tracking-tight mb-0 text-uppercase text-dark" style="font-size: 1.15rem;"><?= $title ?></h4>
                    <div class="sub-title text-primary fw-semibold small" style="font-size: 0.8rem;"><?= $sub_title ?></div>
                </div>
                <div class="text-end print-meta">
                    <div class="text-muted small" style="font-size: 0.72rem;"><?= is_rtl() ? 'تاريخ الطباعة:' : 'Print Date:' ?> <?= date('Y-m-d') ?></div>
                    <div class="badge bg-secondary text-muted-foreground border mt-0.5" style="font-size: 0.65rem;"><?= is_rtl() ? 'الجدول المعتمد' : 'Official Approved' ?></div>
                </div>
            </div>
        </div>

        <!-- Weekly Matrix Grid: Time Slots (Y-Axis) x Days of Week (X-Axis) -->
        <table class="table table-borderless align-middle mb-0 w-100 timetable-table">
            <thead>
                <tr>
                    <th style="width: 14%;" class="p-1 text-center text-secondary small fw-bold text-uppercase">
                        <?= is_rtl() ? 'التوقيت / الحصة' : 'Time Slot' ?>
                    </th>
                    <?php foreach ($days as $day): ?>
                        <th class="p-1 text-center">
                            <div class="day-header">
                                <?= is_rtl() ? ([
                                    'Sunday' => 'الأحد',
                                    'Monday' => 'الإثنين',
                                    'Tuesday' => 'الثلاثاء',
                                    'Wednesday' => 'الأربعاء',
                                    'Thursday' => 'الخميس',
                                    'Friday' => 'الجمعة',
                                    'Saturday' => 'السبت'
                                ][$day] ?? $day) : $day ?>
                            </div>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($periods as $p): ?>
                    <tr>
                        <!-- Time Column on Y-Axis (Left Column) -->
                        <td class="p-1">
                            <div class="time-badge">
                                <div class="period-title"><?= htmlspecialchars($p['period_name']) ?></div>
                                <div class="period-hours">
                                    <?= date('h:i A', strtotime($p['start_time'])) ?> - <?= date('h:i A', strtotime($p['end_time'])) ?>
                                </div>
                            </div>
                        </td>

                        <?php if ($p['is_break']): ?>
                            <!-- Full-Width Intermittent Interval Banner (Break / Lunch) -->
                            <td colspan="<?= count($days) ?>" class="p-1">
                                <div class="interval-row">
                                    <i class="fa-solid fa-mug-hot me-2"></i>
                                    <span><?= htmlspecialchars($p['period_name']) ?></span>
                                    <span class="ms-2 opacity-75 small">(<?= date('h:i A', strtotime($p['start_time'])) ?> - <?= date('h:i A', strtotime($p['end_time'])) ?>)</span>
                                </div>
                            </td>
                        <?php else: ?>
                            <!-- Regular Scheduled Subject Slots Across Days -->
                            <?php foreach ($days as $day): ?>
                                <td class="p-1">
                                    <?php if (isset($grid[$p['period_id']][$day])): 
                                        $slot = $grid[$p['period_id']][$day];
                                        $slotStart = !empty($slot['start_time']) ? date('h:i A', strtotime($slot['start_time'])) : date('h:i A', strtotime($p['start_time']));
                                        $slotEnd   = !empty($slot['end_time']) ? date('h:i A', strtotime($slot['end_time'])) : date('h:i A', strtotime($p['end_time']));
                                        $roomNo    = $slot['room_number'] ?? ($slot['room_no'] ?? '');
                                        $allocType = $slot['allocation_type'] ?? 'class';
                                    ?>
                                        <?php if ($allocType === 'break'): ?>
                                            <!-- Custom Class Break Slot -->
                                            <div class="slot-card is-break">
                                                <div class="subject-text">
                                                    <i class="fa-solid fa-mug-hot me-1 text-warning"></i><?= htmlspecialchars($slot['subject_name'] ?? 'Class Break') ?>
                                                </div>
                                                <div class="time-pill">
                                                    <i class="fa-regular fa-clock me-1"></i><?= $slotStart ?> - <?= $slotEnd ?>
                                                </div>
                                                <div class="meta-text text-muted small"><?= is_rtl() ? 'استراحة فصلية' : 'Class Recess' ?></div>
                                            </div>
                                        <?php elseif ($allocType === 'activity'): ?>
                                            <!-- Ad-hoc Class Activity (Assembly, Sports, Clubs) -->
                                            <div class="slot-card is-activity">
                                                <span class="activity-badge">
                                                    <i class="fa-solid fa-star me-1"></i><?= is_rtl() ? 'نشاط' : 'Activity' ?>
                                                </span>
                                                <div class="subject-text"><?= htmlspecialchars($slot['subject_name'] ?? 'Activity') ?></div>
                                                <div class="time-pill">
                                                    <i class="fa-regular fa-clock me-1"></i><?= $slotStart ?> - <?= $slotEnd ?>
                                                </div>
                                                <?php 
                                                    $metaName = $type === 'class' 
                                                         ? htmlspecialchars($slot['teacher_name'] ?? '') 
                                                         : htmlspecialchars($slot['class_name'] ?? '');
                                                    if (!empty($metaName)): 
                                                ?>
                                                    <div class="meta-text"><?= $metaName ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($roomNo)): ?>
                                                    <div class="room-tag">
                                                        <i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars($roomNo) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <!-- Regular Academic Subject Slot -->
                                            <div class="slot-card">
                                                <div class="subject-text"><?= htmlspecialchars($slot['subject_name'] ?? 'Lecture') ?></div>
                                                <div class="time-pill">
                                                    <i class="fa-regular fa-clock me-1"></i><?= $slotStart ?> - <?= $slotEnd ?>
                                                </div>
                                                <div class="meta-text">
                                                    <?= $type === 'class' 
                                                        ? htmlspecialchars($slot['teacher_name'] ?? '') 
                                                        : htmlspecialchars($slot['class_name'] ?? '') ?>
                                                </div>
                                                <?php if (!empty($roomNo)): ?>
                                                    <div class="room-tag">
                                                        <i class="fa-solid fa-location-dot me-1"></i><?= is_rtl() ? 'قاعة ' : 'Room ' ?><?= htmlspecialchars($roomNo) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="slot-card" style="opacity: 0.35; background-color: #f8fafc;">
                                            <span class="text-muted small">-</span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Signature and Approval Footer for Physical Distribution -->
        <div class="board-footer mt-2 pt-2 border-top d-flex justify-content-between align-items-center text-muted small">
            <div>
                <span class="fw-semibold"><?= is_rtl() ? 'إعداد قسم الجداول المدرسية' : 'Timetable Coordination Office' ?></span>
            </div>
            <div>
                <span><?= is_rtl() ? 'اعتماد مدير المدرسة / الوكيل الأكاديمي:' : 'Principal / Academic Director Approval:' ?> ___________________________</span>
            </div>
        </div>

    </div>

</body>
</html>
