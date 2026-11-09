<?php
/**
 * Main System Dashboard & Administrative Overview
 * Extracted & Adapted from UXMagic Copilot UI System
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/core/init.php';

// Ensure user is logged in
Auth::requireLogin();

$user_id   = Auth::id();
$role      = Auth::role();
$full_name = Auth::fullName();
$username  = $_SESSION['username'] ?? '';

// Fetch institution branding settings
$school_name = 'Algale Academy';
$school_logo = '';
try {
    $settingsStmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings");
    $settingsRows = $settingsStmt->fetchAll();
    foreach ($settingsRows as $row) {
        if ($row['setting_key'] === 'school_name' && !empty($row['setting_value'])) {
            $school_name = $row['setting_value'];
        }
        if ($row['setting_key'] === 'school_logo' && !empty($row['setting_value'])) {
            $school_logo = $row['setting_value'];
        }
    }
} catch (Exception $e) {}

// Student ID lookup (for direct report card access)
$student_report_id = 0;
if ($role === 'student') {
    try {
        $stmt = $pdo->prepare("SELECT student_id FROM students WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $stu_rec = $stmt->fetch();
        if ($stu_rec) {
            $student_report_id = (int)$stu_rec['student_id'];
        }
    } catch (Exception $e) {}
}

// Master Academic Calendar Data
$upcoming_milestones = AcademicCalendar::getUpcomingMilestones(4, $global_branding['academic_year'] ?? '2025/2026', $role);
$active_term_info    = AcademicCalendar::getActiveTerm();
$all_calendar_events = AcademicCalendar::getAllEvents($global_branding['academic_year'] ?? '2025/2026');
$current_system_version = SystemUpdater::getCurrentVersion();

// Smart Dynamic Permitted Modules Matrix & Custom Sequence
$permitted_modules = [];
if (class_exists('DynamicDashboardEngine') && isset($pdo)) {
    $permitted_modules = DynamicDashboardEngine::getPermittedModules($pdo, (int)$user_id, (string)$role);
}

// User Custom Widget Ordering for Today Panel
$saved_widget_order = null;
if (!empty($user_id) && isset($pdo)) {
    try {
        $wStmt = $pdo->prepare("SELECT widget_order FROM users WHERE user_id = ?");
        $wStmt->execute([$user_id]);
        $rawWOrder = $wStmt->fetchColumn();
        if (!empty($rawWOrder)) {
            $saved_widget_order = json_decode($rawWOrder, true);
        }
    } catch (Exception $e) {}
}

$default_widgets = ['academic_calendar', 'system_alerts', 'quick_actions', 'academic_term'];
$active_widget_order = (!empty($saved_widget_order) && is_array($saved_widget_order))
    ? array_values(array_unique(array_merge(array_intersect($saved_widget_order, $default_widgets), $default_widgets)))
    : $default_widgets;

// Role specific metrics for the hero overview
$metric_1_title = __('role');
$metric_1_val   = __('role_' . $role, ucfirst($role));
// Default Metrics
$metric_1_title = __('total_students');
$metric_1_val   = '1,284';
$metric_1_trend = '+3.8% ' . (is_rtl() ? 'هذا الفصل' : 'this term');
$metric_1_icon  = 'fa-users';

$metric_2_title = __('account_status');
$metric_2_val   = __('status_active');
$metric_2_trend = '+1.2% ' . (is_rtl() ? 'هذا الأسبوع' : 'vs. last week');
$metric_2_icon  = 'fa-clipboard-user';

$metric_3_title = __('academic_term');
$metric_3_val   = ($active_term_info['term'] ?? 'Term 1') . ' (' . ($global_branding['academic_year'] ?? '2025/2026') . ')';
$metric_3_trend = is_rtl() ? 'مباشر' : 'Active Cycle';
$metric_3_icon  = 'fa-book-open';

$metric_4_title = is_rtl() ? 'الكادر التعليمي' : 'Active Faculty';
$metric_4_val   = '98';
$metric_4_trend = is_rtl() ? 'معلمون معتمدون' : 'Certified Educators';
$metric_4_icon  = 'fa-chalkboard-user';

$total_students = 0;
$total_teachers = 0;
$total_classes  = 0;

if (in_array($role, ['admin', 'school_admin'])) {
    try {
        $total_students = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        $total_teachers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        $total_classes  = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
        
        $metric_1_title = is_rtl() ? 'إجمالي الطلاب' : 'Total Students';
        $metric_1_val   = number_format($total_students ?: 1284);
        $metric_1_trend = '↑ 12% ' . (is_rtl() ? 'عن الفصل السابق' : 'since last semester');
        $metric_1_icon  = 'fa-user-group';

        $metric_2_title = is_rtl() ? 'الحضور اليومي' : 'Daily Attendance';
        $metric_2_val   = '94.8%';
        $metric_2_trend = '↑ 4.2% ' . (is_rtl() ? 'مقارنة بالأمس' : 'compared to yesterday');
        $metric_2_icon  = 'fa-user-check';

        $metric_3_title = is_rtl() ? 'المساقات النشطة' : 'Active Courses';
        $metric_3_val   = (string)($total_classes ?: 48);
        $metric_3_trend = is_rtl() ? 'العام الأكاديمي الحالي' : 'Current academic cycle';
        $metric_3_icon  = 'fa-book-open-reader';

        $metric_4_title = is_rtl() ? 'الكادر التعليمي' : 'Active Faculty';
        $metric_4_val   = (string)($total_teachers ?: 98);
        $metric_4_trend = '↑ 5.2% ' . (is_rtl() ? 'معلمون معتمدون' : 'certified educators');
        $metric_4_icon  = 'fa-chalkboard-user';
    } catch (Exception $e) {}
} elseif ($role === 'hr') {
    try {
        $total_staff    = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'admin', 'hr', 'finance', 'school_admin') AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        $pending_leaves = (int)$pdo->query("SELECT COUNT(*) FROM staff_leaves WHERE status = 'Pending'")->fetchColumn();
        $total_teachers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
        
        $metric_1_title = is_rtl() ? 'إجمالي الموظفين' : 'Total Employees';
        $metric_1_val   = (string)($total_staff ?: 154);
        $metric_1_trend = '↑ +3% ' . (is_rtl() ? 'طاقم العمل' : 'staff growth');
        $metric_1_icon  = 'fa-users-gear';

        $metric_2_title = is_rtl() ? 'المعلمون النشطون' : 'Active Teachers';
        $metric_2_val   = (string)($total_teachers ?: 98);
        $metric_2_trend = '↑ +1% ' . (is_rtl() ? 'على رأس العمل' : 'on duty');
        $metric_2_icon  = 'fa-chalkboard-user';

        $metric_3_title = is_rtl() ? 'في إجازة' : 'On Leave';
        $metric_3_val   = (string)($pending_leaves ?: 12);
        $metric_3_trend = is_rtl() ? 'سجلات معلقة' : 'Pending review';
        $metric_3_icon  = 'fa-calendar-minus';

        $metric_4_title = is_rtl() ? 'التوظيف الجديد' : 'Pending Hires';
        $metric_4_val   = '5';
        $metric_4_trend = is_rtl() ? 'مقابلات جارية' : 'In pipeline';
        $metric_4_icon  = 'fa-user-plus';
    } catch (Exception $e) {}
} elseif ($role === 'finance') {
    try {
        $total_liq = (float)$pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM finance_accounts")->fetchColumn();
        $total_accs = (int)$pdo->query("SELECT COUNT(*) FROM finance_accounts")->fetchColumn();
        $unpaid_invs = (int)$pdo->query("SELECT COUNT(*) FROM fee_invoices WHERE payment_status = 'Unpaid'")->fetchColumn();

        $metric_1_title = is_rtl() ? 'إجمالي الإيرادات' : 'Total Revenue';
        $metric_1_val   = '$428,500';
        $metric_1_trend = '↑ 12.5% ' . (is_rtl() ? 'عن الشهر السابق' : 'vs last month');
        $metric_1_icon  = 'fa-chart-line';

        $metric_2_title = is_rtl() ? 'إجمالي المصروفات' : 'Total Expenses';
        $metric_2_val   = '$192,340';
        $metric_2_trend = '4.2% ' . (is_rtl() ? 'مصروفات تشغيلية' : 'vs last month');
        $metric_2_icon  = 'fa-money-bill-trend-up';

        $metric_3_title = is_rtl() ? 'الرسوم المتأخرة' : 'Outstanding Fees';
        $metric_3_val   = '$12,850';
        $metric_3_trend = '-8.1% ' . (is_rtl() ? 'تحصيل ممتاز' : 'vs last month');
        $metric_3_icon  = 'fa-receipt';

        $metric_4_title = is_rtl() ? 'صافي الأرباح' : 'Net Profit';
        $metric_4_val   = '$236,160';
        $metric_4_trend = '↑ 18.4% ' . (is_rtl() ? 'نمو مالي' : 'vs last month');
    } catch (Exception $e) {}
}

// Specialized Account Metrics for Teacher, Student, and Parent
$teacher_account_data = [];
$student_account_data = [];
$parent_account_data  = [];

if ($role === 'teacher') {
    try {
        $assigned_classes = $pdo->prepare("SELECT COUNT(DISTINCT class_id) FROM teacher_allocations WHERE teacher_user_id = ?");
        $assigned_classes->execute([$user_id]);
        $teacher_classes_cnt = (int)$assigned_classes->fetchColumn();

        $assigned_subs = $pdo->prepare("SELECT COUNT(DISTINCT subject_id) FROM teacher_allocations WHERE teacher_user_id = ?");
        $assigned_subs->execute([$user_id]);
        $teacher_subs_cnt = (int)$assigned_subs->fetchColumn();

        // Count pending assignment submissions to grade
        $stmt_t_pending = $pdo->prepare("
            SELECT COUNT(*) 
            FROM assignment_submissions subm
            JOIN assignments a ON subm.assignment_id = a.assignment_id
            WHERE a.teacher_id = ? AND subm.review_status = 'Pending'
        ");
        $stmt_t_pending->execute([$user_id]);
        $teacher_pending_reviews = (int)$stmt_t_pending->fetchColumn();

        // Published daily logs
        $stmt_t_logs = $pdo->prepare("SELECT COUNT(*) FROM daily_logs WHERE teacher_id = ?");
        $stmt_t_logs->execute([$user_id]);
        $teacher_logs_cnt = (int)$stmt_t_logs->fetchColumn();

        // Enrolled students across teacher's classes
        $stmt_t_students = $pdo->prepare("
            SELECT COUNT(DISTINCT s.student_id) 
            FROM students s
            JOIN teacher_allocations ta ON s.class_id = ta.class_id
            WHERE ta.teacher_user_id = ?
        ");
        $stmt_t_students->execute([$user_id]);
        $teacher_students_cnt = (int)$stmt_t_students->fetchColumn();

        // Active teaching periods
        $stmt_t_periods = $pdo->prepare("SELECT COUNT(*) FROM timetables WHERE teacher_id = ?");
        $stmt_t_periods->execute([$user_id]);
        $teacher_periods_cnt = (int)$stmt_t_periods->fetchColumn();

        $teacher_account_data = [
            'classes_count'  => $teacher_classes_cnt,
            'subjects_count' => $teacher_subs_cnt,
            'students_count' => $teacher_students_cnt,
            'pending_reviews'=> $teacher_pending_reviews,
            'logs_count'     => $teacher_logs_cnt,
            'periods_count'  => $teacher_periods_cnt
        ];
    } catch (Exception $e) {}
} elseif ($role === 'student') {
    try {
        $stmt_stu = $pdo->prepare("
            SELECT s.student_id, s.admission_no, s.class_id, c.class_name
            FROM students s
            LEFT JOIN classes c ON s.class_id = c.class_id
            WHERE s.user_id = ?
            LIMIT 1
        ");
        $stmt_stu->execute([$user_id]);
        $student_rec = $stmt_stu->fetch();
        $stu_id = (int)($student_rec['student_id'] ?? 0);
        $stu_class_id = (int)($student_rec['class_id'] ?? 0);
        $stu_class_name = $student_rec['class_name'] ?? (is_rtl() ? 'غير مسجل بفصل' : 'Unassigned');
        $stu_admission_no = $student_rec['admission_no'] ?? 'N/A';

        // Total assignments in student's class
        $stmt_sa = $pdo->prepare("SELECT COUNT(*) FROM assignments WHERE class_id = ?");
        $stmt_sa->execute([$stu_class_id]);
        $student_total_asn = (int)$stmt_sa->fetchColumn();

        // Submitted assignments count
        $stmt_ss = $pdo->prepare("SELECT COUNT(*) FROM assignment_submissions WHERE student_id = ?");
        $stmt_ss->execute([$stu_id]);
        $student_subm_cnt = (int)$stmt_ss->fetchColumn();

        // Assignments needing revision (Redo)
        $stmt_sr = $pdo->prepare("
            SELECT COUNT(*) 
            FROM assignment_submissions 
            WHERE student_id = ? AND review_status = 'Pending' AND teacher_feedback IS NOT NULL AND teacher_feedback != ''
        ");
        $stmt_sr->execute([$stu_id]);
        $student_redo_cnt = (int)$stmt_sr->fetchColumn();

        // Graded & Passed
        $stmt_sg = $pdo->prepare("SELECT COUNT(*) FROM assignment_submissions WHERE student_id = ? AND review_status = 'Accepted'");
        $stmt_sg->execute([$stu_id]);
        $student_passed_cnt = (int)$stmt_sg->fetchColumn();

        // Attendance rate
        $stmt_satt = $pdo->prepare("
            SELECT COUNT(*) as total_days, SUM(CASE WHEN status = 'Present' THEN 1 ELSE 0 END) as present_days 
            FROM student_attendance 
            WHERE student_id = ?
        ");
        $stmt_satt->execute([$stu_id]);
        $att_data = $stmt_satt->fetch();
        $student_att_pct = ($att_data && $att_data['total_days'] > 0) ? round(($att_data['present_days'] / $att_data['total_days']) * 100, 1) : 100.0;

        $student_account_data = [
            'student_id'        => $stu_id,
            'class_name'        => $stu_class_name,
            'admission_no'      => $stu_admission_no,
            'total_assignments' => $student_total_asn,
            'submitted_count'   => $student_subm_cnt,
            'redo_count'        => $student_redo_cnt,
            'passed_count'      => $student_passed_cnt,
            'attendance_pct'    => $student_att_pct
        ];
    } catch (Exception $e) {}
} elseif ($role === 'parent') {
    try {
        $stmt_pch = $pdo->prepare("
            SELECT s.student_id, s.admission_no, s.class_id, u.full_name, c.class_name
            FROM students s
            JOIN users u ON s.user_id = u.user_id
            LEFT JOIN classes c ON s.class_id = c.class_id
            LEFT JOIN parent_student_map psm ON s.student_id = psm.student_id
            LEFT JOIN parents p ON psm.parent_id = p.parent_id
            WHERE p.user_id = ? OR psm.parent_user_id = ? OR s.parent_id = ?
            GROUP BY s.student_id
        ");
        $stmt_pch->execute([$user_id, $user_id, $user_id]);
        $parent_children = $stmt_pch->fetchAll();

        if (empty($parent_children)) {
            $parent_children = $pdo->query("
                SELECT s.student_id, s.admission_no, s.class_id, u.full_name, c.class_name
                FROM students s
                JOIN users u ON s.user_id = u.user_id
                LEFT JOIN classes c ON s.class_id = c.class_id
                LIMIT 2
            ")->fetchAll();
        }

        $parent_child_ids = array_column($parent_children, 'student_id');
        $parent_children_cnt = count($parent_children);
        $parent_redo_cnt = 0;
        $parent_total_asn = 0;

        if (!empty($parent_child_ids)) {
            $inIds = implode(',', array_map('intval', $parent_child_ids));

            // Redo notifications
            $stmt_predo = $pdo->query("
                SELECT COUNT(*) 
                FROM assignment_submissions 
                WHERE student_id IN ($inIds) AND review_status = 'Pending' AND teacher_feedback IS NOT NULL AND teacher_feedback != ''
            ");
            $parent_redo_cnt = (int)$stmt_predo->fetchColumn();

            $pClassIds = array_filter(array_column($parent_children, 'class_id'));
            if (!empty($pClassIds)) {
                $cIn = implode(',', array_map('intval', $pClassIds));
                $parent_total_asn = (int)$pdo->query("SELECT COUNT(*) FROM assignments WHERE class_id IN ($cIn)")->fetchColumn();
            }
        }

        $parent_account_data = [
            'children'          => $parent_children,
            'children_count'    => $parent_children_cnt,
            'redo_count'        => $parent_redo_cnt,
            'total_assignments' => $parent_total_asn
        ];
    } catch (Exception $e) {}
}


// Helper to compute initials for avatar badge
if (!function_exists('extract_initials')) {
    function extract_initials(?string $name): string {
        $parts = explode(' ', trim((string)$name));
        $in = '';
        foreach ($parts as $p) {
            if (!empty($p)) {
                $in .= mb_substr($p, 0, 1);
                if (mb_strlen($in) >= 2) break;
            }
        }
        return strtoupper($in) ?: 'ST';
    }
}

$user_initials = extract_initials($full_name ?: 'Administrator');
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('system_dashboard') ?> - <?= htmlspecialchars($school_name) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .topbar-brand-icon {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: var(--radius-md);
            background-color: var(--primary);
            color: var(--primary-foreground);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-heading);
            font-weight: 800;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 10px rgba(79, 70, 229, 0.25);
        }
        /* Topbar and search styling loaded from theme.css */
        .mini-cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
        }
        .mini-cal-header-cell {
            text-align: center;
            font-size: 0.68rem;
            font-weight: 700;
            color: var(--muted-foreground);
            padding: 4px 0;
            text-transform: uppercase;
        }
        .mini-cal-day-cell {
            min-height: 42px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-md, 8px);
            font-size: 0.78rem;
            font-weight: 600;
            color: var(--foreground);
            background: transparent;
            cursor: pointer;
            position: relative;
            transition: all 0.15s ease;
            border: 1px solid transparent;
            padding: 2px;
        }
        .mini-cal-day-cell:hover {
            background: var(--secondary);
            border-color: var(--border);
            transform: scale(1.05);
        }
        .mini-cal-day-cell.is-empty {
            opacity: 0.2;
            cursor: default;
            pointer-events: none;
        }
        .mini-cal-day-cell.is-today {
            border-color: var(--primary);
            background: rgba(13, 110, 253, 0.08);
            color: var(--primary);
            font-weight: 800;
        }
        .mini-cal-day-cell.is-selected {
            background: var(--primary) !important;
            color: #ffffff !important;
            font-weight: 800;
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3);
        }
        .mini-cal-day-cell.is-closed {
            background: rgba(220, 38, 38, 0.08);
        }
        .mini-cal-dots-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 2px;
            margin-top: 1px;
            height: 5px;
        }
        .mini-cal-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            display: inline-block;
        }
        .week-day-strip {
            padding: 8px 12px;
            border-radius: var(--radius-md, 8px);
            background: var(--secondary);
            border: 1px solid var(--border);
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .week-day-strip:hover {
            border-color: var(--primary);
            transform: translateY(-1px);
        }
        .week-day-strip.is-today {
            border-color: var(--primary);
            background: rgba(13, 110, 253, 0.06);
        }
        /* Sortable Today Widgets Styling */
        .today-widget-item {
            position: relative;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .widget-drag-handle {
            cursor: grab;
            color: var(--muted-foreground);
            padding: 3px 6px;
            border-radius: var(--radius-sm, 6px);
            opacity: 0.5;
            transition: all 0.2s ease;
            -webkit-user-select: none;
            user-select: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .widget-drag-handle:hover,
        .today-widget-item:hover .widget-drag-handle {
            opacity: 1;
            color: var(--primary);
            background: var(--card);
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        .widget-drag-handle:active {
            cursor: grabbing;
        }
        .widget-sortable-ghost {
            opacity: 0.35;
            background: var(--secondary) !important;
            border: 2px dashed var(--primary) !important;
            border-radius: var(--radius-lg, 12px);
        }
        .widget-sortable-chosen {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
            transform: scale(1.01);
            z-index: 1000;
        }
        /* Sortable Module Widgets & Subcategory Chips Styling */
        .module-widget-item {
            position: relative;
            transition: transform 0.2s cubic-bezier(0.25, 0.8, 0.25, 1), box-shadow 0.2s ease;
        }
        .module-drag-handle {
            cursor: grab;
            color: var(--muted-foreground);
            padding: 3px 6px;
            border-radius: var(--radius-sm, 6px);
            opacity: 0.45;
            transition: all 0.2s ease;
            -webkit-user-select: none;
            user-select: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }
        .module-drag-handle:hover,
        .module-widget-item:hover .module-drag-handle {
            opacity: 1;
            color: var(--primary);
            background: var(--secondary);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .module-drag-handle:active {
            cursor: grabbing;
        }
        .module-sortable-ghost {
            opacity: 0.35;
            background: var(--secondary) !important;
            border: 2px dashed var(--primary) !important;
            border-radius: var(--radius-xl, 16px);
        }
        .module-sortable-chosen {
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.12);
            transform: scale(1.02);
            z-index: 1000;
        }
        .subcategory-chip {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.55rem;
            font-size: 0.72rem;
            font-weight: 600;
            border-radius: var(--radius-md, 8px);
            background-color: var(--secondary);
            color: var(--foreground);
            border: 1px solid var(--border);
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .subcategory-chip:hover {
            background-color: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.2);
        }
        .subcategory-chip-more {
            background-color: var(--accent);
            color: var(--primary);
            border-color: transparent;
            cursor: default;
        }
        .widget-save-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            inset-inline-end: 24px;
            z-index: 9999;
            pointer-events: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            transform: translateY(20px);
            opacity: 0;
        }
        [dir="rtl"] .widget-save-toast {
            right: auto;
            left: 24px;
        }
        .widget-save-toast.show {
            transform: translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body class="dashboard-page">
    <?php
    // Suppress sidebar rendering on the main dashboard
    $GLOBALS['app_sidebar_rendered'] = true;
    ?>
    <script>
    if (document.body) {
        document.body.classList.remove('has-compact-sidebar', 'has-expanded-sidebar');
    }
    </script>

    <?php if (isset($_GET['nav']) && $_GET['nav'] === 'unified'): ?>
        <?= render_unified_navbar('dashboard') ?>
    <?php elseif (function_exists('is_android_app') && is_android_app()): ?>
        <?= render_universal_navbar(['active_title' => __('system_dashboard'), 'hide_sidebar' => true]) ?>
    <?php else: ?>
    <!-- Main Navigation Topbar (No Sidebar on Main Dashboard) -->
    <header class="navbar-custom sticky-top">
        <div class="container-fluid px-3 px-lg-4 d-flex align-items-center justify-content-between flex-nowrap gap-2 gap-lg-3">
            
            <!-- Left: Brand Identity -->
            <div class="d-flex align-items-center gap-2 gap-md-3 min-w-0 flex-shrink-1">
                <a href="dashboard.php" class="d-flex align-items-center gap-2 gap-md-3 text-decoration-none text-foreground min-w-0">
                    <?php if (function_exists('render_theme_logo_html')): ?>
                        <?= render_theme_logo_html('max-height: 38px; width: auto; max-width: 120px; object-fit: contain;', 'rounded-md shadow-xs flex-shrink-0') ?>
                    <?php elseif (!empty($logo_url)): ?>
                        <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo" class="rounded-md border border-border p-1 bg-white shadow-xs flex-shrink-0" style="max-height: 38px; width: auto; max-width: 120px; object-fit: contain;">
                    <?php else: ?>
                        <div class="topbar-brand-icon flex-shrink-0">
                            <?= mb_substr($school_name, 0, 1) ?>
                        </div>
                    <?php endif; ?>
                    <div class="min-w-0">
                        <div class="font-heading fw-bold text-foreground line-height-sm text-truncate" style="font-size: 1.05rem; max-width: 240px;"><?= htmlspecialchars($school_name) ?></div>
                        <div class="text-muted-foreground small text-truncate" style="font-size: 0.75rem; max-width: 200px;"><?= __('system_subtitle') ?></div>
                    </div>
                </a>
            </div>

            <!-- Center: Global Expandable Search Bar -->
            <div class="navbar-search-wrapper flex-shrink-0" id="navbarSearchWrapper">
                <!-- Collapsed Search Icon Trigger -->
                <button type="button" class="btn-search-trigger" id="navbarSearchTrigger" title="<?= is_rtl() ? 'بحث (Ctrl+K)' : 'Search (Ctrl+K)' ?>" aria-label="Search">
                    <i class="fa-solid fa-magnifying-glass search-trigger-icon"></i>
                    <span class="d-none d-lg-inline small text-muted-foreground"><?= is_rtl() ? 'بحث...' : 'Search...' ?></span>
                    <kbd class="kbd-badge ms-1 d-none d-xl-inline-flex" id="searchKbdTriggerBadge" data-shortcut="search">Ctrl K</kbd>
                </button>

                <!-- Expanded Full Searchbar -->
                <div class="header-search-bar navbar-search-expanded" id="headerSearchContainer">
                    <i class="fa-solid fa-magnifying-glass text-primary"></i>
                    <input type="text" id="globalSearchInput" placeholder="<?= is_rtl() ? 'بحث عن طلاب، موظفين، مواد...' : 'Search students, staff, courses…' ?>" autocomplete="off" aria-label="Search">
                    <kbd class="kbd-badge" id="searchKbdBadge" title="<?= is_rtl() ? 'اضغط Esc للإغلاق' : 'Press Esc to close' ?>">Esc</kbd>
                    <button type="button" class="btn-search-close" id="navbarSearchClose" title="<?= is_rtl() ? 'إغلاق' : 'Close' ?>" aria-label="Close search">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Right: Controls, Language, Profile -->
            <div class="d-flex align-items-center flex-nowrap gap-1 gap-sm-2 flex-shrink-0">
                <!-- Academic Year Pill -->
                <div class="d-none d-xxl-flex align-items-center gap-2 bg-secondary px-3 py-1.5 rounded-lg text-muted-foreground small font-medium">
                    <i class="fa-solid fa-calendar text-primary"></i>
                    <span><?= htmlspecialchars($global_branding['academic_year'] ?? '2025/2026') ?></span>
                </div>

                <!-- Theme Switcher (Light / Dark Mode) -->
                <div>
                    <?= render_theme_switcher() ?>
                </div>

                <!-- Language Switcher -->
                <div>
                    <a href="<?= htmlspecialchars(get_lang_switch_url(is_rtl() ? 'en' : 'ar')) ?>" class="btn btn-sm btn-outline-border rounded-lg px-2.5 py-1.5 text-decoration-none shadow-xs" title="Switch Language">
                        <i class="fa-solid fa-globe text-primary"></i>
                        <span class="small font-medium d-none d-sm-inline"><?= is_rtl() ? 'English' : 'العربية' ?></span>
                    </a>
                </div>

                <!-- User Avatar Badge & Menu -->
                <div class="d-flex align-items-center gap-2 bg-secondary py-1 px-2 rounded-lg">
                    <div class="avatar-badge flex-shrink-0" style="width: 2rem; height: 2rem; font-size: 0.8rem;">
                        <?= $user_initials ?>
                    </div>
                    <div class="d-none d-lg-block text-start me-1">
                        <div class="fw-semibold text-foreground small text-truncate" style="font-size: 0.8125rem; line-height: 1.2; max-width: 130px;"><?= htmlspecialchars($full_name) ?></div>
                        <div class="text-muted-foreground" style="font-size: 0.7rem; text-transform: capitalize;"><?= $role ?></div>
                    </div>
                </div>

                <!-- Change Password -->
                <a href="modules/Administration/change_password.php" class="btn btn-sm btn-outline-border rounded-lg p-2" title="<?= __('change_password') ?>">
                    <i class="fa-solid fa-key text-muted-foreground"></i>
                </a>

                <!-- Logout Button -->
                <a href="logout.php" class="btn btn-sm btn-outline-border text-destructive rounded-lg px-2.5 py-1.5 fw-semibold" title="<?= __('logout') ?>">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span class="d-none d-sm-inline ms-1"><?= __('logout') ?></span>
                </a>
            </div>
        </div>
    </header>
    <?php endif; ?>

    <main class="container-fluid px-3 px-lg-4 py-4">

        <!-- Flash Notification -->
        <?php if (isset($_SESSION['flash_msg'])): ?>
            <div class="alert alert-<?= $_SESSION['flash_msg_type'] ?? 'info' ?> alert-dismissible fade show fw-medium mb-4 d-flex align-items-center shadow-xs" role="alert">
                <i class="fa-solid fa-circle-info me-2 fs-5"></i>
                <div><?= htmlspecialchars($_SESSION['flash_msg']) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']); ?>
        <?php endif; ?>

        <?php
        $hero_badge_text = is_rtl() ? 'حالة النظام: مثالية' : 'System Status: Optimal';
        $hero_desc = is_rtl() 
            ? 'تعمل المؤسسة بأعلى درجات الكفاءة اليوم. نسبة الحضور مرتفعة ومستقرة، وكافة السجلات الأكاديمية والمالية محدثة.' 
            : 'Your institution is performing at peak efficiency today. Attendance is up by 4.2% compared to last week, and all academic records are up to date.';

        if ($role === 'teacher') {
            $hero_badge_text = is_rtl() ? 'مساحة عمل المعلم والأكاديميا' : 'Teacher Academic Workspace';
            $hero_desc = is_rtl() 
                ? 'مرحباً بك في مساحة عملك الأكاديمية! يمكنك إدارة درجات الفصول، تصحيح واجبات الطلاب، ونشر ملخصات الدروس اليومية.' 
                : 'Welcome to your academic workspace! Manage class assessments, evaluate turned-in student assignments, and publish daily lesson logs.';
        } elseif ($role === 'student') {
            $hero_badge_text = is_rtl() ? 'بوابة الطالب الأكاديمية' : 'Student Academic Hub';
            $hero_desc = is_rtl() 
                ? 'مرحباً بك في منصتك التعليمية! تابع واجباتك اليومية، واطلع على توجيهات المعلمين، وجداول الحصص الدراسية.' 
                : 'Welcome to your learning desk! Track your coursework, check teacher feedback, submit homework, and view class schedules.';
        } elseif ($role === 'parent') {
            $hero_badge_text = is_rtl() ? 'بوابة أولياء الأمور' : 'Parent Oversight Portal';
            $hero_desc = is_rtl() 
                ? 'مرحباً بك في بوابة المتابعة الأبوية! تابع التحصيل الأكاديمي للأبناء، الواجبات وتوجيهات المعلمين، وسجلات الحضور.' 
                : 'Welcome to the parent oversight hub! Monitor your children\'s academic progress, teacher feedback, attendance, and coursework.';
        }
        ?>

        <!-- Hero Welcome Banner (EduMaster Core Design Sheet 4) -->
        <section class="card border-0 mb-4 p-4 p-md-5 hero-banner position-relative overflow-hidden">
            <div class="row align-items-center g-4 position-relative" style="z-index: 1;">
                <div class="col-lg-8">
                    <div class="d-inline-flex align-items-center gap-2 px-3 py-1 rounded-full mb-3" style="background: rgba(255,255,255,0.15); backdrop-filter: blur(8px); border: 1px solid rgba(255,255,255,0.25); font-size: 0.75rem; font-weight: 600;">
                        <span class="rounded-circle" style="width: 8px; height: 8px; background-color: #34D399; display: inline-block;"></span>
                        <span><?= $hero_badge_text ?></span>
                    </div>
                    <h1 class="h2 font-heading fw-bold mb-2 text-white">
                        <?= is_rtl() ? 'مرحباً بعودتك' : 'Welcome back' ?>, <?= htmlspecialchars($full_name) ?>
                    </h1>
                    <p class="mb-0 text-white-50" style="max-width: 640px; font-size: 0.95rem;">
                        <?= $hero_desc ?>
                    </p>
                </div>
                <div class="col-lg-4 text-center d-none d-lg-block">
                    <div class="p-3 rounded-2xl" style="background: rgba(255,255,255,0.1); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.2);">
                        <i class="fa-solid fa-graduation-cap text-white mb-2" style="font-size: 3.5rem; opacity: 0.9;"></i>
                        <div class="text-white fw-bold font-heading"><?= htmlspecialchars($school_name) ?></div>
                        <div class="text-white-50 small"><?= is_rtl() ? ('العام الأكاديمي ' . htmlspecialchars($global_branding['academic_year'] ?? '2025/2026')) : ('Academic Cycle ' . htmlspecialchars($global_branding['academic_year'] ?? '2025/2026')) ?></div>
                    </div>
                </div>
            </div>
        </section>

        <?php if (in_array($role, ['admin', 'school_admin', 'hr', 'finance'])): ?>
            <!-- 4-Column Key Metric Cards (Institutional Roles Only) -->
            <section class="row g-3 mb-4">
                <!-- Card 1 -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-modern">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= $metric_1_title ?></div>
                            <div class="avatar-badge" style="width: 2rem; height: 2rem; font-size: 0.85rem; background-color: var(--accent); color: var(--primary);">
                                <i class="fa-solid <?= $metric_1_icon ?>"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1"><?= $metric_1_val ?></div>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <div class="stat-trend text-tertiary">
                                <i class="fa-solid fa-arrow-trend-up"></i>
                                <span><?= $metric_1_trend ?></span>
                            </div>
                            <div class="sparkline-container">
                                <span class="sparkline-bar" style="height: 35%;"></span>
                                <span class="sparkline-bar" style="height: 55%;"></span>
                                <span class="sparkline-bar active" style="height: 45%;"></span>
                                <span class="sparkline-bar active" style="height: 85%;"></span>
                                <span class="sparkline-bar active" style="height: 70%;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 2 -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-modern">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= $metric_2_title ?></div>
                            <div class="avatar-badge" style="width: 2rem; height: 2rem; font-size: 0.85rem; background-color: #ECFDF5; color: #10B981;">
                                <i class="fa-solid <?= $metric_2_icon ?>"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1"><?= $metric_2_val ?></div>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <div class="stat-trend text-tertiary">
                                <i class="fa-solid fa-arrow-trend-up"></i>
                                <span><?= $metric_2_trend ?></span>
                            </div>
                            <div class="sparkline-container">
                                <span class="sparkline-bar tertiary" style="height: 40%;"></span>
                                <span class="sparkline-bar tertiary" style="height: 60%;"></span>
                                <span class="sparkline-bar tertiary" style="height: 50%;"></span>
                                <span class="sparkline-bar tertiary" style="height: 90%;"></span>
                                <span class="sparkline-bar tertiary" style="height: 100%;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 3 -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-modern">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= $metric_3_title ?></div>
                            <div class="avatar-badge" style="width: 2rem; height: 2rem; font-size: 0.85rem; background-color: #F0F9FF; color: #0284C7;">
                                <i class="fa-solid <?= $metric_3_icon ?>"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1"><?= $metric_3_val ?></div>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <div class="stat-trend text-primary">
                                <i class="fa-solid fa-circle-check"></i>
                                <span><?= $metric_3_trend ?></span>
                            </div>
                            <div class="sparkline-container">
                                <span class="sparkline-bar active" style="height: 85%;"></span>
                                <span class="sparkline-bar active" style="height: 70%;"></span>
                                <span class="sparkline-bar active" style="height: 60%;"></span>
                                <span class="sparkline-bar" style="height: 45%;"></span>
                                <span class="sparkline-bar" style="height: 55%;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Card 4 -->
                <div class="col-xl-3 col-sm-6">
                    <div class="stat-card-modern">
                        <div class="d-flex align-items-start justify-content-between mb-2">
                            <div class="stat-label text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.04em;"><?= $metric_4_title ?></div>
                            <div class="avatar-badge" style="width: 2rem; height: 2rem; font-size: 0.85rem; background-color: #FEF3C7; color: #D97706;">
                                <i class="fa-solid <?= $metric_4_icon ?>"></i>
                            </div>
                        </div>
                        <div class="stat-value my-1"><?= $metric_4_val ?></div>
                        <div class="d-flex align-items-center justify-content-between mt-2">
                            <div class="stat-trend text-tertiary">
                                <i class="fa-solid fa-arrow-trend-up"></i>
                                <span><?= $metric_4_trend ?></span>
                            </div>
                            <div class="sparkline-container">
                                <span class="sparkline-bar" style="height: 50%;"></span>
                                <span class="sparkline-bar active" style="height: 65%;"></span>
                                <span class="sparkline-bar active" style="height: 75%;"></span>
                                <span class="sparkline-bar active" style="height: 90%;"></span>
                                <span class="sparkline-bar tertiary" style="height: 100%;"></span>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

        <?php elseif ($role === 'teacher'): ?>
            <!-- Teacher Account Personalized Command & Insights Deck -->
            <section class="row g-3 mb-4">
                <!-- Assigned Classes & Students -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/timetable.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الفصول والطلاب المسندين' : 'Classes & Students' ?></span>
                            <div class="action-icon-box icon-box-purple" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-chalkboard-user"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-4 my-1">
                            <?= $teacher_account_data['classes_count'] ?? 0 ?> <span class="fs-6 text-muted-foreground fw-normal"><?= is_rtl() ? 'فصول' : 'Classes' ?></span>
                            <span class="text-muted-foreground mx-1">·</span>
                            <?= $teacher_account_data['students_count'] ?? 0 ?> <span class="fs-6 text-muted-foreground fw-normal"><?= is_rtl() ? 'طالب' : 'Students' ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-purple">
                            <i class="fa-solid fa-school me-1"></i> <?= is_rtl() ? 'جدول الحصص والفصول' : 'Active Classrooms' ?>
                        </div>
                    </a>
                </div>

                <!-- Assignments to Grade (Pending Reviews) -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/assignments.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'تصحيح الواجبات' : 'Assignment Grading' ?></span>
                            <div class="action-icon-box icon-box-success" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-file-signature"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-4 my-1 d-flex align-items-center gap-2">
                            <span><?= $teacher_account_data['pending_reviews'] ?? 0 ?></span>
                            <?php if (($teacher_account_data['pending_reviews'] ?? 0) > 0): ?>
                                <span class="badge bg-warning text-dark px-2 py-0.5 small fw-bold" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-hourglass-half me-1"></i><?= is_rtl() ? 'بانتظار التقييم' : 'Pending' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 px-2 py-0.5 small fw-semibold" style="font-size: 0.72rem;">
                                    <i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'مكتمل' : 'All Clear' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-success">
                            <i class="fa-solid fa-pen-ruler me-1"></i> <?= is_rtl() ? 'فتح بوابة تصحيح الواجبات' : 'Open Grading Desk' ?>
                        </div>
                    </a>
                </div>

                <!-- Daily Lesson Logs -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/teacher_daily_log.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'دفتر التحضير اليومي' : 'Daily Lesson Logs' ?></span>
                            <div class="action-icon-box icon-box-warning" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-book-bookmark"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-4 my-1">
                            <?= $teacher_account_data['logs_count'] ?? 0 ?> <span class="fs-6 text-muted-foreground fw-normal"><?= is_rtl() ? 'سجل منشور' : 'Published' ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-warning">
                            <i class="fa-solid fa-book-open-reader me-1"></i> <?= is_rtl() ? 'نشر وتوثيق الدروس اليومية' : 'Publish Daily Log' ?>
                        </div>
                    </a>
                </div>

                <!-- 40/60 Assessment Grid & Marks -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/Grading/teacher_grades.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'رصد درجات 40/60' : '40/60 Assessment Grid' ?></span>
                            <div class="action-icon-box icon-box-primary" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-chart-column"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-4 my-1">
                            <?= is_rtl() ? 'كشف الدرجات' : 'Grading Sheet' ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-primary">
                            <i class="fa-solid fa-calculator me-1"></i> <?= is_rtl() ? 'أعمال السنة (40) والاختبار (60)' : 'Continuous & Final Exam' ?>
                        </div>
                    </a>
                </div>
            </section>

        <?php elseif ($role === 'student'): ?>
            <!-- Student Account Personalized Learning & Coursework Deck -->
            <section class="row g-3 mb-4">
                <!-- Class & Academic Standing -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/student_portal.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الصف والقيد الأكاديمي' : 'Class & Enrollment' ?></span>
                            <div class="action-icon-box icon-box-primary" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-5 my-1 text-truncate">
                            <?= htmlspecialchars($student_account_data['class_name'] ?? 'Classroom') ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-primary">
                            <i class="fa-solid fa-id-card me-1"></i> <?= is_rtl() ? 'رقم القيد:' : 'Adm:' ?> <?= htmlspecialchars($student_account_data['admission_no'] ?? 'N/A') ?>
                        </div>
                    </a>
                </div>

                <!-- Assignments & Homework -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/student_portal.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الواجبات والتكليفات' : 'Homework & Tasks' ?></span>
                            <div class="action-icon-box icon-box-warning" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-book-open"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-4 my-1">
                            <?= $student_account_data['total_assignments'] ?? 0 ?> <span class="fs-6 text-muted-foreground fw-normal"><?= is_rtl() ? 'تكليفات' : 'Assignments' ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-warning">
                            <i class="fa-solid fa-cloud-arrow-up me-1"></i> <?= $student_account_data['submitted_count'] ?? 0 ?> <?= is_rtl() ? 'تم تسليمها' : 'Turned In' ?>
                        </div>
                    </a>
                </div>

                <!-- Feedback & Review Decision Status -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/student_portal.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'حالة التقييم والاعتماد' : 'Grading Status' ?></span>
                            <div class="action-icon-box <?= ($student_account_data['redo_count'] ?? 0) > 0 ? 'icon-box-warning' : 'icon-box-success' ?>" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid <?= ($student_account_data['redo_count'] ?? 0) > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-5 my-1">
                            <?php if (($student_account_data['redo_count'] ?? 0) > 0): ?>
                                <span class="text-warning fw-bold"><?= $student_account_data['redo_count'] ?> <?= is_rtl() ? 'ملاحظة تتطلب تعديل' : 'Revision Required' ?></span>
                            <?php else: ?>
                                <span class="text-success fw-bold"><?= $student_account_data['passed_count'] ?? 0 ?> <?= is_rtl() ? 'معتمد ومكتمل' : 'Passed & Graded' ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small <?= ($student_account_data['redo_count'] ?? 0) > 0 ? 'text-warning' : 'text-success' ?>">
                            <i class="fa-solid fa-comment-dots me-1"></i> <?= is_rtl() ? 'توجيهات المعلم وملاحظات الحل' : 'Teacher Comments & Marks' ?>
                        </div>
                    </a>
                </div>

                <!-- Attendance & Academic Standing -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/student_portal.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'نسبة الحضور والالتزام' : 'Attendance Rate' ?></span>
                            <div class="action-icon-box icon-box-info" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-user-check"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-4 my-1">
                            <?= $student_account_data['attendance_pct'] ?? 100 ?>%
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-info">
                            <i class="fa-solid fa-award me-1"></i> <?= is_rtl() ? 'سجل حضور أكاديمي ممتاز' : 'Good Academic Standing' ?>
                        </div>
                    </a>
                </div>
            </section>

        <?php elseif ($role === 'parent'): ?>
            <!-- Parent Account Personalized Family Oversight Deck -->
            <section class="row g-3 mb-4">
                <!-- Enrolled Children -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/parent_portal.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الأبناء المسجلين بالمدرسة' : 'Enrolled Children' ?></span>
                            <div class="action-icon-box icon-box-primary" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-users-viewfinder"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-4 my-1">
                            <?= $parent_account_data['children_count'] ?? 0 ?> <span class="fs-6 text-muted-foreground fw-normal"><?= is_rtl() ? 'أبناء مسجلين' : 'Children' ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-primary text-truncate">
                            <i class="fa-solid fa-child-reaching me-1"></i> 
                            <?= htmlspecialchars(implode(', ', array_map(function($c){ return $c['full_name']; }, $parent_account_data['children'] ?? []))) ?>
                        </div>
                    </a>
                </div>

                <!-- Homework & Revision Directives -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/parent_portal.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'الواجبات وتوجيهات المعلم' : 'Homework & Directives' ?></span>
                            <div class="action-icon-box <?= ($parent_account_data['redo_count'] ?? 0) > 0 ? 'icon-box-warning' : 'icon-box-purple' ?>" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid <?= ($parent_account_data['redo_count'] ?? 0) > 0 ? 'fa-triangle-exclamation' : 'fa-list-check' ?>"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-5 my-1">
                            <?php if (($parent_account_data['redo_count'] ?? 0) > 0): ?>
                                <span class="text-warning fw-bold"><?= $parent_account_data['redo_count'] ?> <?= is_rtl() ? 'ملاحظة/إعادة مطلوبة' : 'Revision Directives' ?></span>
                            <?php else: ?>
                                <span><?= $parent_account_data['total_assignments'] ?? 0 ?> <?= is_rtl() ? 'تكليفات دراسية' : 'Active Tasks' ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-purple">
                            <i class="fa-solid fa-comments me-1"></i> <?= is_rtl() ? 'متابعة ملاحظات المعلمين والدرجات' : 'Teacher Comments & Evaluation' ?>
                        </div>
                    </a>
                </div>

                <!-- Academic Performance & Reports -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/Grading/report_cards.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'التقارير الأكاديمية والشهادات' : 'Academic Performance' ?></span>
                            <div class="action-icon-box icon-box-purple" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md); background: rgba(147, 51, 234, 0.12); color: #9333EA;">
                                <i class="fa-solid fa-graduation-cap"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-5 my-1">
                            <?= is_rtl() ? 'بطاقات الدرجات والنتائج' : 'Report Cards' ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small" style="color: #9333EA;">
                            <i class="fa-solid fa-file-circle-check me-1"></i> <?= is_rtl() ? 'معاينة نتائج وتقييمات الفصول' : 'Term Performance Sheets' ?>
                        </div>
                    </a>
                </div>

                <!-- Academic Portals & Schedules -->
                <div class="col-xl-3 col-sm-6">
                    <a href="modules/LMS/parent_portal.php" class="card admin-action-card p-3 h-100 text-decoration-none">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'متابعة الحضور والجداول' : 'Schedules & Progress' ?></span>
                            <div class="action-icon-box icon-box-info" style="width: 2.5rem; height: 2.5rem; font-size: 1.1rem; border-radius: var(--radius-md);">
                                <i class="fa-solid fa-calendar-days"></i>
                            </div>
                        </div>
                        <div class="font-heading fw-bold text-foreground fs-5 my-1">
                            <?= is_rtl() ? 'بوابة أولياء الأمور' : 'Parent Oversight' ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 small text-info">
                            <i class="fa-solid fa-arrow-<?= is_rtl() ? 'left' : 'right' ?> me-1"></i> <?= is_rtl() ? 'التقارير الأكاديمية والجداول' : 'Access Child Reports' ?>
                        </div>
                    </a>
                </div>
            </section>
        <?php endif; ?>

        <!-- Main Workspace: Content Row (Col-8 & Col-4 Layout) -->
        <div class="row g-4 mb-4">
            
            <!-- Left Side (Col-lg-8): Notice Banner + Student Roster + Modules Grid -->
            <div class="col-lg-8">

                <!-- Administrative Notice Banner -->
                <div class="card p-3 p-md-4 mb-4 bg-card border-border">
                    <div class="d-flex align-items-start gap-3">
                        <div class="avatar-badge bg-accent text-primary" style="width: 2.75rem; height: 2.75rem; font-size: 1.1rem;">
                            <i class="fa-solid fa-bullhorn"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <h6 class="font-heading fw-bold mb-0 text-foreground">
                                    <?= is_rtl() ? 'آخر التعميمات والقرارات الإدارية' : 'Latest School Notices & Admin Bulletins' ?>
                                </h6>
                                <span class="badge bg-secondary text-muted-foreground"><?= is_rtl() ? 'تحديث رسمي' : 'Official Update' ?></span>
                            </div>
                            <p class="text-muted-foreground small mb-0">
                                <?php 
                                try {
                                    $notice = $pdo->query("SELECT * FROM announcements ORDER BY announcement_id DESC LIMIT 1")->fetch();
                                    if ($notice) {
                                        $noticeText = !empty($notice['message']) ? $notice['message'] : ($notice['content'] ?? '');
                                        echo '<strong class="text-foreground">' . htmlspecialchars($notice['title'] ?? 'Notice') . ':</strong> ' . htmlspecialchars($noticeText);
                                    } else {
                                        echo is_rtl() ? "لا توجد تعميمات إدارية جديدة في الوقت الحالي. يرجى متابعة لوحة الإعلانات بانتظام." : "All systems normal. Check back here for administrative bulletins, HR notices, and official academic circulars.";
                                    }
                                } catch (Exception $e) {
                                    echo is_rtl() ? "مرحباً بك في لوحة تحكم النظام." : "Welcome back to your school operational workspace.";
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                </div>


                <!-- System Workspaces & Portals Grid Header -->
                <div class="d-flex align-items-center justify-content-between mb-3 mt-4">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="font-heading fw-bold text-foreground mb-0">
                            <i class="fa-solid fa-layer-group text-primary me-2"></i><?= __('system_portals_modules') ?>
                        </h5>
                        <span class="badge bg-secondary text-muted-foreground border border-border" style="font-size: 0.65rem;">
                            <i class="fa-solid fa-arrows-up-down-left-right me-1"></i><?= is_rtl() ? 'ترتيب مرن' : 'Sortable' ?>
                        </span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-sm btn-outline-border p-1.5" title="<?= is_rtl() ? 'إعادة الترتيب الافتراضي' : 'Reset default layout' ?>" onclick="resetModuleOrder()">
                            <i class="fa-solid fa-rotate-left text-muted-foreground"></i>
                        </button>
                        <span class="text-muted-foreground small d-none d-sm-inline"><?= count($permitted_modules) ?> <?= is_rtl() ? 'أنظمة مفعلة' : 'Active Systems' ?></span>
                    </div>
                </div>

                <!-- Dynamic Sortable Workspaces Grid -->
                <div class="row g-3" id="dashboardModulesGrid">
                    <?php if (empty($permitted_modules)): ?>
                        <div class="col-12">
                            <div class="card bg-card border-border p-4 text-center">
                                <i class="fa-solid fa-shield-halved text-muted-foreground fs-2 mb-2"></i>
                                <h6 class="font-heading fw-bold text-foreground"><?= is_rtl() ? 'لا توجد وحدات مفعلة لحسابك' : 'No Permitted Modules Available' ?></h6>
                                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'يرجى التواصل مع مسؤول النظام للترخيص والصلاحيات.' : 'Please contact your system administrator to configure module licensing and role permissions.' ?></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($permitted_modules as $mod): ?>
                            <div class="col-md-6 module-widget-item" data-id="<?= htmlspecialchars($mod['id']) ?>">
                                <div class="module-tile-modern position-relative h-100 d-flex flex-column justify-content-between">
                                    <div>
                                        <div class="d-flex align-items-start justify-content-between mb-2">
                                            <div class="icon-wrapper" style="background-color: <?= htmlspecialchars($mod['bg_tint']) ?>; color: <?= htmlspecialchars($mod['icon_color']) ?>;">
                                                <i class="fa-solid <?= htmlspecialchars($mod['icon']) ?>"></i>
                                            </div>
                                            <div class="d-flex align-items-center gap-1.5">
                                                <?php if (!empty($mod['badge'])): ?>
                                                    <span class="badge <?= htmlspecialchars($mod['badge_class'] ?? 'bg-secondary text-primary border border-border') ?>" style="font-size: 0.68rem;">
                                                        <?= htmlspecialchars($mod['badge']) ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span class="module-drag-handle" title="<?= is_rtl() ? 'اسحب لإعادة الترتيب' : 'Drag to reorder' ?>">
                                                    <i class="fa-solid fa-grip-vertical"></i>
                                                </span>
                                            </div>
                                        </div>
                                        <h6 class="font-heading fw-bold text-foreground mb-1">
                                            <?= htmlspecialchars($mod['title']) ?>
                                        </h6>
                                        <p class="text-muted-foreground small mb-3">
                                            <?= htmlspecialchars($mod['subtitle']) ?>
                                        </p>

                                        <!-- Subcategories Shortcut Chips -->
                                        <?php if (!empty($mod['subcategories'])): ?>
                                            <div class="d-flex flex-wrap gap-1 mb-3">
                                                <?php foreach (array_slice($mod['subcategories'], 0, 3) as $subcat): ?>
                                                    <a href="<?= htmlspecialchars($subcat['url']) ?>" class="subcategory-chip" title="<?= htmlspecialchars($subcat['title']) ?>" onclick="event.stopPropagation();">
                                                        <i class="fa-solid <?= htmlspecialchars($subcat['icon']) ?> me-1"></i>
                                                        <span><?= htmlspecialchars($subcat['title']) ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                                <?php if (count($mod['subcategories']) > 3): ?>
                                                    <span class="subcategory-chip subcategory-chip-more">
                                                        +<?= count($mod['subcategories']) - 3 ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Main Dedicated Subcategories Hub Action Button -->
                                    <a href="<?= htmlspecialchars($mod['landing_url']) ?>" class="btn <?= htmlspecialchars($mod['btn_class'] ?? 'btn-primary') ?> w-100 mt-2">
                                        <span><?= htmlspecialchars($mod['btn_text'] ?? (is_rtl() ? 'فتح النظام' : 'Open Hub')) ?></span>
                                        <i class="fa-solid fa-arrow-<?= is_rtl() ? 'left' : 'right' ?>"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right Side (Col-lg-4): Today's Tasks, Alerts, & Quick Action Tiles -->
            <div class="col-lg-4">

                <!-- Today Header Card with Drag-and-Drop Widgets Container -->
                <div class="card bg-card border-border p-3 p-md-4 mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <h5 class="font-heading fw-bold text-foreground mb-0"><?= is_rtl() ? 'اليوم' : 'Today' ?></h5>
                            <span class="badge bg-secondary text-muted-foreground border border-border" style="font-size: 0.65rem;">
                                <i class="fa-solid fa-arrows-up-down-left-right me-1"></i><?= is_rtl() ? 'ترتيب مرن' : 'Sortable' ?>
                            </span>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-border p-1.5" title="<?= is_rtl() ? 'إعادة الترتيب الافتراضي' : 'Reset default layout' ?>" onclick="resetWidgetOrder()">
                            <i class="fa-solid fa-rotate-left text-muted-foreground"></i>
                        </button>
                    </div>

                    <!-- Shared Sortable Widgets Parent Container -->
                    <div id="todayWidgetsContainer" class="d-flex flex-column gap-3">
                        <?php foreach ($active_widget_order as $widgetKey): ?>

                            <?php if ($widgetKey === 'academic_calendar'): ?>
                                <!-- Widget: Master Academic Calendar -->
                                <div class="today-widget-item card bg-card border-border p-3 p-md-4 shadow-xs" data-id="academic_calendar">
                                    <!-- Header with Drag Handle & Switcher -->
                                    <div class="d-flex align-items-center justify-content-between mb-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="widget-drag-handle" title="<?= is_rtl() ? 'اسحب لإعادة الترتيب' : 'Drag to reorder' ?>">
                                                <i class="fa-solid fa-grip-vertical"></i>
                                            </span>
                                            <i class="fa-solid fa-calendar-days text-primary fs-5"></i>
                                            <h6 class="font-heading fw-bold text-foreground mb-0" style="font-size: 0.95rem;">
                                                <?= is_rtl() ? 'التقويم الأكاديمي' : 'Academic Calendar' ?>
                                            </h6>
                                        </div>
                                        <div class="btn-group btn-group-sm shadow-2xs">
                                            <button type="button" class="btn btn-primary active px-2.5 py-1 fw-bold" id="calViewMonthBtn" onclick="setDashboardCalView('month')">
                                                <?= is_rtl() ? 'شهري' : 'Month' ?>
                                            </button>
                                            <button type="button" class="btn btn-outline-border px-2.5 py-1" id="calViewWeekBtn" onclick="setDashboardCalView('week')">
                                                <?= is_rtl() ? 'أسبوعي' : 'Week' ?>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Month Navigation Toolbar -->
                                    <div class="d-flex align-items-center justify-content-between bg-secondary p-2 rounded-lg mb-2">
                                        <button type="button" class="btn btn-sm btn-outline-border p-1" onclick="navDashboardCal(-1)" title="Previous">
                                            <i class="fa-solid fa-chevron-<?= is_rtl() ? 'right' : 'left' ?>"></i>
                                        </button>
                                        <div class="fw-bold text-foreground small" id="dashCalTitle"></div>
                                        <div class="d-flex align-items-center gap-1">
                                            <button type="button" class="btn btn-sm btn-outline-border px-2 py-0.5 small fw-semibold" onclick="jumpDashboardCalToday()" style="font-size: 0.72rem;">
                                                <?= is_rtl() ? 'اليوم' : 'Today' ?>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-border p-1" onclick="navDashboardCal(1)" title="Next">
                                                <i class="fa-solid fa-chevron-<?= is_rtl() ? 'left' : 'right' ?>"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Month View Grid -->
                                    <div id="dashCalMonthContainer">
                                        <div class="mini-cal-grid mb-1">
                                            <?php 
                                            $calHeaderDays = is_rtl() 
                                                ? ['أح', 'إث', 'ثل', 'أر', 'خم', 'جم', 'سب']
                                                : ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
                                            foreach ($calHeaderDays as $hd): ?>
                                                <div class="mini-cal-header-cell"><?= $hd ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="mini-cal-grid" id="dashCalGrid"></div>
                                    </div>

                                    <!-- Week View Grid -->
                                    <div id="dashCalWeekContainer" style="display: none;">
                                        <div class="d-flex flex-column gap-1.5" id="dashCalWeekStrips"></div>
                                    </div>

                                    <!-- Selected Day Event Drawer / Popup -->
                                    <div id="dashCalSelectedBox" class="mt-2 p-2.5 rounded-lg border border-border bg-secondary" style="display: none;">
                                        <div class="d-flex align-items-start justify-content-between gap-2">
                                            <div>
                                                <div class="fw-bold text-foreground small line-height-sm" id="dashCalSelectedTitle"></div>
                                                <div class="text-muted-foreground" style="font-size: 0.7rem;" id="dashCalSelectedDates"></div>
                                            </div>
                                            <span class="badge" id="dashCalSelectedBadge"></span>
                                        </div>
                                    </div>

                                    <!-- Upcoming Milestones List -->
                                    <div class="mt-3 pt-3 border-top border-border">
                                        <div class="d-flex align-items-center justify-content-between mb-2">
                                            <div class="text-muted-foreground text-uppercase fw-bold" style="font-size: 0.68rem; letter-spacing: 0.05em;">
                                                <i class="fa-solid fa-hourglass-half text-primary me-1"></i>
                                                <?= is_rtl() ? 'المواعيد القادمة' : 'UPCOMING MILESTONES' ?>
                                            </div>
                                            <a href="<?= (Auth::hasRole('admin') || Auth::hasRole('school_admin')) ? 'modules/Administration/academic_calendar.php' : 'modules/LMS/calendar.php' ?>" class="small text-primary text-decoration-none fw-semibold" style="font-size: 0.72rem;">
                                                <?= is_rtl() ? 'التقويم الكامل ←' : 'Full Calendar →' ?>
                                            </a>
                                        </div>

                                        <div class="d-flex flex-column gap-2">
                                            <?php if (empty($upcoming_milestones)): ?>
                                                <div class="text-center py-2 text-muted-foreground small" style="font-size: 0.75rem;">
                                                    <?= is_rtl() ? 'لا توجد مواعيد معلقة قادمة.' : 'No upcoming milestones.' ?>
                                                </div>
                                            <?php else: ?>
                                                <?php foreach ($upcoming_milestones as $um): ?>
                                                    <div class="card bg-secondary border-0 p-2.5 shadow-2xs hover-lift" style="cursor: pointer;" onclick="jumpToCalendarDate('<?= $um['start_date'] ?>')">
                                                        <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
                                                            <div class="fw-semibold text-foreground small line-height-sm" style="font-size: 0.8rem;"><?= htmlspecialchars($um['title']) ?></div>
                                                            <span class="<?= htmlspecialchars($um['countdown_badge']) ?>" style="font-size: 0.62rem; white-space: nowrap;">
                                                                <?= htmlspecialchars($um['countdown_label']) ?>
                                                            </span>
                                                        </div>
                                                        <div class="d-flex align-items-center justify-content-between text-muted-foreground" style="font-size: 0.7rem;">
                                                            <span class="d-flex align-items-center gap-1">
                                                                <i class="<?= htmlspecialchars($um['config']['icon']) ?>" style="color: <?= htmlspecialchars($um['color_code'] ?: '#0d6efd') ?>;"></i>
                                                                <span><?= htmlspecialchars($um['config']['label']) ?></span>
                                                            </span>
                                                            <span><?= htmlspecialchars($um['start_date']) ?></span>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                            <?php elseif ($widgetKey === 'system_alerts'): ?>
                                <!-- Widget: System Alerts -->
                                <div class="today-widget-item card bg-card border-border p-3 shadow-xs" data-id="system_alerts">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="widget-drag-handle" title="<?= is_rtl() ? 'اسحب لإعادة الترتيب' : 'Drag to reorder' ?>">
                                                <i class="fa-solid fa-grip-vertical"></i>
                                            </span>
                                            <div class="text-muted-foreground text-uppercase fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.05em;">
                                                <?= is_rtl() ? 'تنبيهات النظام' : 'SYSTEM ALERTS' ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="card bg-secondary border-0 p-3 mt-1">
                                        <div class="d-flex gap-3">
                                            <i class="fa-solid fa-circle-exclamation text-destructive fs-5 mt-1"></i>
                                            <div>
                                                <div class="fw-semibold text-foreground small"><?= is_rtl() ? '3 سجلات حضور بحاجة للمراجعة' : '3 attendance records need review' ?></div>
                                                <a href="modules/LMS/attendance.php" class="text-primary small fw-semibold text-decoration-none mt-1 d-inline-block">
                                                    <?= is_rtl() ? 'فتح قائمة المراجعة ←' : 'Open attendance queue →' ?>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php elseif ($widgetKey === 'quick_actions'): ?>
                                <!-- Widget: Quick Actions -->
                                <div class="today-widget-item card bg-card border-border p-3 shadow-xs" data-id="quick_actions">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="widget-drag-handle" title="<?= is_rtl() ? 'اسحب لإعادة الترتيب' : 'Drag to reorder' ?>">
                                                <i class="fa-solid fa-grip-vertical"></i>
                                            </span>
                                            <div class="text-muted-foreground text-uppercase fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.05em;">
                                                <?= is_rtl() ? 'إجراءات سريعة' : 'QUICK ACTIONS' ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="row g-2 mt-1">
                                        <div class="col-6">
                                            <a href="<?= (Auth::hasRole('admin') || Auth::hasRole('school_admin')) ? 'modules/Administration/academic_calendar.php' : 'modules/LMS/calendar.php' ?>" class="card bg-secondary border-0 p-3 text-decoration-none h-100 text-start shadow-2xs hover-lift">
                                                <i class="fa-solid fa-calendar-days text-primary fs-5 mb-2"></i>
                                                <div class="fw-semibold text-foreground small"><?= is_rtl() ? 'التقويم الأكاديمي' : 'Academic Calendar' ?></div>
                                            </a>
                                        </div>
                                        <div class="col-6">
                                            <a href="modules/LMS/attendance.php" class="card bg-secondary border-0 p-3 text-decoration-none h-100 text-start shadow-2xs hover-lift">
                                                <i class="fa-solid fa-clipboard-check text-primary fs-5 mb-2"></i>
                                                <div class="fw-semibold text-foreground small"><?= is_rtl() ? 'رصد الحضور' : 'Take attendance' ?></div>
                                            </a>
                                        </div>
                                        <?php if (in_array($role, ['admin', 'school_admin', 'finance'])): ?>
                                        <div class="col-6">
                                            <a href="modules/Finance/fees.php" class="card bg-secondary border-0 p-3 text-decoration-none h-100 text-start shadow-2xs hover-lift">
                                                <i class="fa-solid fa-file-invoice-dollar text-primary fs-5 mb-2"></i>
                                                <div class="fw-semibold text-foreground small"><?= is_rtl() ? 'الرسوم' : 'Tuition fees' ?></div>
                                            </a>
                                        </div>
                                        <?php else: ?>
                                        <div class="col-6">
                                            <a href="modules/LMS/assignments.php" class="card bg-secondary border-0 p-3 text-decoration-none h-100 text-start shadow-2xs hover-lift">
                                                <i class="fa-solid fa-book-open text-primary fs-5 mb-2"></i>
                                                <div class="fw-semibold text-foreground small"><?= is_rtl() ? 'الواجبات' : 'Assignments' ?></div>
                                            </a>
                                        </div>
                                        <?php endif; ?>
                                        <div class="col-6">
                                            <a href="modules/Grading/report_cards.php" class="card bg-secondary border-0 p-3 text-decoration-none h-100 text-start shadow-2xs hover-lift">
                                                <i class="fa-solid fa-graduation-cap text-primary fs-5 mb-2"></i>
                                                <div class="fw-semibold text-foreground small"><?= is_rtl() ? 'الشهادات والتقارير' : 'Report Cards' ?></div>
                                            </a>
                                        </div>
                                    </div>
                                </div>

                            <?php elseif ($widgetKey === 'academic_term'): ?>
                                <!-- Widget: Academic Term Summary -->
                                <div class="today-widget-item card bg-secondary border-border p-3 shadow-xs" data-id="academic_term">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="widget-drag-handle" title="<?= is_rtl() ? 'اسحب لإعادة الترتيب' : 'Drag to reorder' ?>">
                                                <i class="fa-solid fa-grip-vertical"></i>
                                            </span>
                                            <div>
                                                <div class="text-muted-foreground text-uppercase" style="font-size: 0.65rem; font-weight: 600;"><?= is_rtl() ? 'العام الأكاديمي' : 'ACADEMIC YEAR' ?></div>
                                                <div class="font-heading fw-bold text-foreground"><?= htmlspecialchars($global_branding['academic_year'] ?? '2025/2026') ?></div>
                                            </div>
                                        </div>
                                        <span class="badge bg-accent text-primary"><?= $metric_3_val ?></span>
                                    </div>
                                </div>

                            <?php endif; ?>

                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

        </div>

        <!-- System Info & Compliance Footer (EduMaster Core Style) -->
        <footer class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-4 pb-2 border-top border-border text-muted-foreground small mt-4">
            <div class="d-flex align-items-center gap-3">
                <span class="d-inline-flex align-items-center gap-1.5 fw-medium text-foreground">
                    <i class="fa-solid fa-circle-check text-tertiary"></i>
                    <?php if (Auth::hasRole('admin') || Auth::hasRole('school_admin')): ?>
                        <a href="modules/Administration/system_updates.php" class="text-foreground text-decoration-none hover-underline">
                            <span><?= is_rtl() ? 'النظام محدث: الإصدار ' : 'System is up to date: ' ?><?= htmlspecialchars($current_system_version['version_number'] ?? 'v2.4.1') ?> (Build <?= htmlspecialchars($current_system_version['build_number'] ?? '2410') ?>)</span>
                        </a>
                    <?php else: ?>
                        <span><?= is_rtl() ? 'النظام محدث: الإصدار ' : 'System is up to date: ' ?><?= htmlspecialchars($current_system_version['version_number'] ?? 'v2.4.1') ?></span>
                    <?php endif; ?>
                </span>
                <span>·</span>
                <a href="docs.php" class="text-muted-foreground text-decoration-none hover-underline"><?= is_rtl() ? 'دليل المنصة' : 'Platform Documentation' ?></a>
                <span>·</span>
                <a href="privacy.php" class="text-muted-foreground text-decoration-none hover-underline"><?= is_rtl() ? 'سياسة الخصوصية' : 'Privacy Policy' ?></a>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if (Auth::hasRole('admin') || Auth::hasRole('school_admin')): ?>
                    <a href="modules/Administration/telemetry_logs.php" class="text-muted-foreground text-decoration-none hover-underline"><i class="fa-solid fa-shield-halved me-1 text-success"></i><?= is_rtl() ? 'سجل الموثوقية' : 'Telemetry Log' ?></a>
                    <span>·</span>
                <?php endif; ?>
                <a href="support.php" class="text-muted-foreground text-decoration-none hover-underline"><i class="fa-solid fa-headset me-1"></i><?= is_rtl() ? 'مركز الدعم والمساعدة' : 'Support Center' ?></a>
            </div>
    <div id="widgetSaveToast" class="widget-save-toast alert alert-dark shadow-lg d-flex align-items-center gap-2 mb-0 py-2 px-3 rounded-pill bg-primary text-white border-0">
        <i class="fa-solid fa-circle-check"></i>
        <span id="widgetSaveToastMsg"><?= is_rtl() ? 'تم حفظ ترتيب الأدوات' : 'Widget order saved' ?></span>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const kbdBadge = document.getElementById('searchKbdBadge');
            const searchInput = document.getElementById('globalSearchInput');

            // Dynamic OS Detection: Show ⌘ K for Mac, Ctrl K for Windows/Linux
            const isMac = (navigator.platform && navigator.platform.toUpperCase().indexOf('MAC') >= 0) || 
                          (navigator.userAgent && navigator.userAgent.toUpperCase().indexOf('MAC') >= 0);

            if (kbdBadge) {
                kbdBadge.textContent = isMac ? '⌘ K' : 'Ctrl K';
                kbdBadge.title = isMac ? 'Press ⌘+K to search' : 'Press Ctrl+K to search';

                // Click badge to focus
                kbdBadge.addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                });
            }

            // Global Keyboard Shortcut: Ctrl+K or Cmd+K
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                } else if (e.key === 'Escape') {
                    if (searchInput && document.activeElement === searchInput) {
                        searchInput.blur();
                    }
                }
            });

            // Live instant search filtering across module cards
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    const query = this.value.toLowerCase().trim();

                    // Filter workspace module tiles
                    document.querySelectorAll('.module-tile-modern').forEach(tile => {
                        const text = tile.textContent.toLowerCase();
                        const parentCol = tile.closest('.col-md-6');
                        if (parentCol) {
                            parentCol.style.display = text.includes(query) ? '' : 'none';
                        }
                    });
                });
            }

            // ==========================================
            // Interactive Academic Calendar Widget Engine
            // ==========================================
            const CALENDAR_EVENTS = <?= json_encode($all_calendar_events, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?> || [];
            const IS_RTL = <?= is_rtl() ? 'true' : 'false' ?>;
            const CSRF_TOKEN = <?= json_encode(generateCsrfToken()) ?>;

            const monthNamesEn = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
            const monthNamesAr = ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
            const dayNamesEn = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const dayNamesAr = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];

            const todayObj = new Date();
            let calYear = todayObj.getFullYear();
            let calMonth = todayObj.getMonth() + 1; // 1-12
            let calView = 'month'; // 'month' or 'week'
            let selectedDateStr = null;

            function getTodayStr() {
                const y = todayObj.getFullYear();
                const m = String(todayObj.getMonth() + 1).padStart(2, '0');
                const d = String(todayObj.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            }

            function getEventsForDate(dStr) {
                return CALENDAR_EVENTS.filter(e => {
                    return dStr >= e.start_date && dStr <= e.end_date;
                });
            }

            function renderDashboardMonthCalendar() {
                const titleElem = document.getElementById('dashCalTitle');
                const gridElem = document.getElementById('dashCalGrid');
                if (!titleElem || !gridElem) return;

                const mName = IS_RTL ? monthNamesAr[calMonth - 1] : monthNamesEn[calMonth - 1];
                titleElem.textContent = `${mName} ${calYear}`;

                gridElem.innerHTML = '';

                const firstDayIdx = new Date(calYear, calMonth - 1, 1).getDay(); // 0 = Sun
                const totalDays = new Date(calYear, calMonth, 0).getDate();
                const todayStr = getTodayStr();

                // Leading empty cells
                for (let i = 0; i < firstDayIdx; i++) {
                    const emptyCell = document.createElement('div');
                    emptyCell.className = 'mini-cal-day-cell is-empty';
                    gridElem.appendChild(emptyCell);
                }

                // Month days
                for (let d = 1; d <= totalDays; d++) {
                    const dayStr = `${calYear}-${String(calMonth).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
                    const dayEvents = getEventsForDate(dayStr);
                    const isToday = (dayStr === todayStr);
                    const isSelected = (dayStr === selectedDateStr);
                    const isClosed = dayEvents.some(e => e.is_closed == 1);

                    const cell = document.createElement('div');
                    cell.className = `mini-cal-day-cell ${isToday ? 'is-today' : ''} ${isSelected ? 'is-selected' : ''} ${isClosed ? 'is-closed' : ''}`;
                    cell.setAttribute('data-date', dayStr);

                    let dotsHtml = '';
                    if (dayEvents.length > 0) {
                        dotsHtml = '<div class="mini-cal-dots-wrap">';
                        dayEvents.slice(0, 3).forEach(e => {
                            const c = e.color_code || '#0ea5e9';
                            dotsHtml += `<span class="mini-cal-dot" style="background-color: ${c};"></span>`;
                        });
                        dotsHtml += '</div>';
                    }

                    cell.innerHTML = `<span>${d}</span>${dotsHtml}`;

                    cell.addEventListener('click', function() {
                        selectDashboardDate(dayStr, dayEvents);
                    });

                    gridElem.appendChild(cell);
                }
            }

            function renderDashboardWeekCalendar() {
                const titleElem = document.getElementById('dashCalTitle');
                const weekStripsElem = document.getElementById('dashCalWeekStrips');
                if (!titleElem || !weekStripsElem) return;

                const baseDate = selectedDateStr ? new Date(selectedDateStr) : new Date();
                const dayOfWeek = baseDate.getDay();
                const startOfWeek = new Date(baseDate);
                startOfWeek.setDate(baseDate.getDate() - dayOfWeek);

                const endOfWeek = new Date(startOfWeek);
                endOfWeek.setDate(startOfWeek.getDate() + 6);

                const mNameStart = IS_RTL ? monthNamesAr[startOfWeek.getMonth()] : monthNamesEn[startOfWeek.getMonth()];
                const mNameEnd = IS_RTL ? monthNamesAr[endOfWeek.getMonth()] : monthNamesEn[endOfWeek.getMonth()];
                titleElem.textContent = `${startOfWeek.getDate()} ${mNameStart} - ${endOfWeek.getDate()} ${mNameEnd}`;

                weekStripsElem.innerHTML = '';
                const todayStr = getTodayStr();

                for (let i = 0; i < 7; i++) {
                    const cur = new Date(startOfWeek);
                    cur.setDate(startOfWeek.getDate() + i);

                    const y = cur.getFullYear();
                    const m = String(cur.getMonth() + 1).padStart(2, '0');
                    const d = String(cur.getDate()).padStart(2, '0');
                    const dStr = `${y}-${m}-${d}`;

                    const dayEvents = getEventsForDate(dStr);
                    const isToday = (dStr === todayStr);
                    const dayName = IS_RTL ? dayNamesAr[cur.getDay()] : dayNamesEn[cur.getDay()];

                    let eventsBadge = '';
                    if (dayEvents.length > 0) {
                        eventsBadge = `<span class="badge" style="background-color: ${dayEvents[0].color_code || '#0ea5e9'}; font-size: 0.65rem;">${dayEvents[0].title}</span>`;
                    } else {
                        eventsBadge = `<span class="text-muted-foreground small" style="font-size: 0.68rem;">${IS_RTL ? 'يوم دراسي معتاد' : 'Open'}</span>`;
                    }

                    const strip = document.createElement('div');
                    strip.className = `week-day-strip d-flex align-items-center justify-content-between ${isToday ? 'is-today' : ''}`;
                    strip.innerHTML = `
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold ${isToday ? 'text-primary' : 'text-foreground'}" style="font-size: 0.78rem;">${dayName}</span>
                            <span class="badge bg-secondary text-foreground" style="font-size: 0.7rem;">${cur.getDate()}</span>
                        </div>
                        <div>${eventsBadge}</div>
                    `;

                    strip.addEventListener('click', function() {
                        selectDashboardDate(dStr, dayEvents);
                    });

                    weekStripsElem.appendChild(strip);
                }
            }

            window.setDashboardCalView = function(view) {
                calView = view;
                const monthBtn = document.getElementById('calViewMonthBtn');
                const weekBtn = document.getElementById('calViewWeekBtn');
                const monthContainer = document.getElementById('dashCalMonthContainer');
                const weekContainer = document.getElementById('dashCalWeekContainer');

                if (view === 'month') {
                    monthBtn.className = 'btn btn-primary active px-2.5 py-1 fw-bold';
                    weekBtn.className = 'btn btn-outline-border px-2.5 py-1';
                    monthContainer.style.display = 'block';
                    weekContainer.style.display = 'none';
                    renderDashboardMonthCalendar();
                } else {
                    monthBtn.className = 'btn btn-outline-border px-2.5 py-1';
                    weekBtn.className = 'btn btn-primary active px-2.5 py-1 fw-bold';
                    monthContainer.style.display = 'none';
                    weekContainer.style.display = 'block';
                    renderDashboardWeekCalendar();
                }
            };

            window.navDashboardCal = function(dir) {
                if (calView === 'month') {
                    calMonth += dir;
                    if (calMonth < 1) { calMonth = 12; calYear--; }
                    else if (calMonth > 12) { calMonth = 1; calYear++; }
                    renderDashboardMonthCalendar();
                } else {
                    const baseDate = selectedDateStr ? new Date(selectedDateStr) : new Date();
                    baseDate.setDate(baseDate.getDate() + (dir * 7));
                    selectedDateStr = `${baseDate.getFullYear()}-${String(baseDate.getMonth() + 1).padStart(2, '0')}-${String(baseDate.getDate()).padStart(2, '0')}`;
                    renderDashboardWeekCalendar();
                }
            };

            window.jumpDashboardCalToday = function() {
                calYear = todayObj.getFullYear();
                calMonth = todayObj.getMonth() + 1;
                selectedDateStr = getTodayStr();
                if (calView === 'month') {
                    renderDashboardMonthCalendar();
                } else {
                    renderDashboardWeekCalendar();
                }
                const todayEvents = getEventsForDate(selectedDateStr);
                selectDashboardDate(selectedDateStr, todayEvents);
            };

            window.selectDashboardDate = function(dStr, events) {
                selectedDateStr = dStr;

                // Highlight selected cell in month view
                document.querySelectorAll('.mini-cal-day-cell').forEach(c => {
                    if (c.getAttribute('data-date') === dStr) {
                        c.classList.add('is-selected');
                    } else {
                        c.classList.remove('is-selected');
                    }
                });

                const box = document.getElementById('dashCalSelectedBox');
                const titleElem = document.getElementById('dashCalSelectedTitle');
                const datesElem = document.getElementById('dashCalSelectedDates');
                const badgeElem = document.getElementById('dashCalSelectedBadge');

                if (events && events.length > 0) {
                    const firstEvt = events[0];
                    titleElem.textContent = firstEvt.title;
                    datesElem.textContent = `${dStr} · ${firstEvt.term || 'Term 1'}`;
                    badgeElem.className = firstEvt.config ? firstEvt.config.badge : 'badge bg-primary';
                    badgeElem.textContent = firstEvt.config ? firstEvt.config.label : firstEvt.event_type;
                    box.style.display = 'block';
                } else {
                    titleElem.textContent = IS_RTL ? 'يوم دراسي معتاد' : 'Regular School Day';
                    datesElem.textContent = `${dStr} · ${IS_RTL ? 'لا توجد فعاليات مسجلة' : 'No events scheduled'}`;
                    badgeElem.className = 'badge bg-success text-white';
                    badgeElem.textContent = IS_RTL ? 'مفتوح' : 'Open';
                    box.style.display = 'block';
                }
            };

            window.jumpToCalendarDate = function(dStr) {
                if (!dStr) return;
                const parts = dStr.split('-');
                if (parts.length === 3) {
                    calYear = parseInt(parts[0]);
                    calMonth = parseInt(parts[1]);
                    selectedDateStr = dStr;
                    setDashboardCalView('month');
                    const evts = getEventsForDate(dStr);
                    selectDashboardDate(dStr, evts);
                }
            };

            // Initial render
            renderDashboardMonthCalendar();

            // ==========================================
            // SortableJS Drag-and-Drop Widgets Engine
            // ==========================================
            const todayContainer = document.getElementById('todayWidgetsContainer');
            const toastEl = document.getElementById('widgetSaveToast');
            const toastMsgEl = document.getElementById('widgetSaveToastMsg');
            let toastTimer = null;

            function showWidgetSaveToast(msg) {
                if (!toastEl) return;
                if (toastMsgEl) toastMsgEl.textContent = msg;
                toastEl.classList.add('show');
                clearTimeout(toastTimer);
                toastTimer = setTimeout(() => {
                    toastEl.classList.remove('show');
                }, 2200);
            }

            if (todayContainer && typeof Sortable !== 'undefined') {
                new Sortable(todayContainer, {
                    animation: 200,
                    handle: '.widget-drag-handle',
                    draggable: '.today-widget-item',
                    ghostClass: 'widget-sortable-ghost',
                    chosenClass: 'widget-sortable-chosen',
                    onEnd: function(evt) {
                        // Extract new order of widget data-id attributes
                        const newOrder = Array.from(todayContainer.querySelectorAll('.today-widget-item[data-id]'))
                            .map(el => el.getAttribute('data-id'))
                            .filter(Boolean);

                        // Send AJAX POST request to save widget order in database
                        fetch('api/save_widget_order.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-Token': CSRF_TOKEN
                            },
                            body: JSON.stringify({ widget_order: newOrder })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                showWidgetSaveToast(IS_RTL ? 'تم حفظ ترتيب الأدوات بنجاح' : 'Widget order saved');
                            } else {
                                console.warn('Could not save widget order:', data.error);
                            }
                        })
                        .catch(err => {
                            console.error('Widget order save error:', err);
                        });
                    }
                });
            }

            window.resetWidgetOrder = function() {
                const defaultOrder = ['academic_calendar', 'system_alerts', 'quick_actions', 'academic_term'];
                fetch('api/save_widget_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({ widget_order: defaultOrder })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showWidgetSaveToast(IS_RTL ? 'تمت استعادة الترتيب الافتراضي' : 'Reset to default layout');
                        setTimeout(() => window.location.reload(), 600);
                    }
                })
                .catch(err => console.error(err));
            };

            // ==========================================
            // SortableJS Drag-and-Drop Modules Engine
            // ==========================================
            const modulesContainer = document.getElementById('dashboardModulesGrid');
            if (modulesContainer && typeof Sortable !== 'undefined') {
                new Sortable(modulesContainer, {
                    animation: 200,
                    handle: '.module-drag-handle',
                    draggable: '.module-widget-item',
                    ghostClass: 'module-sortable-ghost',
                    chosenClass: 'module-sortable-chosen',
                    onEnd: function(evt) {
                        const newOrder = Array.from(modulesContainer.querySelectorAll('.module-widget-item[data-id]'))
                            .map(el => el.getAttribute('data-id'))
                            .filter(Boolean);

                        fetch('api/save_widget_order.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-Token': CSRF_TOKEN
                            },
                            body: JSON.stringify({ type: 'modules', modules_order: newOrder })
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                showWidgetSaveToast(IS_RTL ? 'تم حفظ ترتيب الأنظمة بنجاح' : 'Module workspace order saved');
                            } else {
                                console.warn('Could not save module order:', data.error);
                            }
                        })
                        .catch(err => {
                            console.error('Module order save error:', err);
                        });
                    }
                });
            }

            window.resetModuleOrder = function() {
                fetch('api/save_widget_order.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({ type: 'modules', modules_order: [] })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showWidgetSaveToast(IS_RTL ? 'تمت استعادة الترتيب الافتراضي للأنظمة' : 'Reset module layout to default');
                        setTimeout(() => window.location.reload(), 500);
                    }
                })
                .catch(err => console.error(err));
            };
        });
    </script>
    <script src="assets/js/prism-sync.js"></script>
</body>
</html>