<?php
declare(strict_types=1);

/**
 * Academic Calendar Management Desk (Administration Control Center)
 * Master timeline and temporal milestone governance for EduMaster Core.
 */

require_once __DIR__ . '/../../core/init.php';

// Ensure user is logged in
Auth::requireLogin();

// Non-admins get redirected to the LMS viewer
if (!Auth::hasRole('admin') && !Auth::hasRole('school_admin')) {
    header("Location: " . BASE_URL . "/modules/LMS/calendar.php");
    exit();
}

$active_year = $_GET['year'] ?? AcademicCalendar::getActiveAcademicYear();
$active_term = $_GET['term'] ?? '';
$active_type = $_GET['type'] ?? '';
$view_mode   = $_GET['view'] ?? 'calendar'; // 'calendar' or 'list'

// Month navigation for calendar grid
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$current_year  = isset($_GET['cal_year']) ? (int)$_GET['cal_year'] : (int)date('Y');

// Month bounds wrap-around
if ($current_month < 1) {
    $current_month = 12;
    $current_year--;
} elseif ($current_month > 12) {
    $current_month = 1;
    $current_year++;
}

$flash_msg = '';
$flash_type = 'success';

// Handle Event Actions (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'save_event') {
            $event_id = !empty($_POST['event_id']) ? (int)$_POST['event_id'] : null;
            $data = [
                'title'           => trim($_POST['title'] ?? ''),
                'description'     => trim($_POST['description'] ?? ''),
                'event_type'      => $_POST['event_type'] ?? AcademicCalendar::TYPE_GENERAL_EVENT,
                'academic_year'   => $_POST['academic_year'] ?? $active_year,
                'term'            => $_POST['term'] ?? 'Term 1',
                'start_date'      => $_POST['start_date'] ?? date('Y-m-d'),
                'end_date'        => !empty($_POST['end_date']) ? $_POST['end_date'] : $_POST['start_date'],
                'is_closed'       => isset($_POST['is_closed']) ? 1 : 0,
                'color_code'      => $_POST['color_code'] ?? '#0ea5e9',
                'target_audience' => $_POST['target_audience'] ?? 'all',
            ];

            AcademicCalendar::saveEvent($data, $event_id);
            $_SESSION['flash_msg'] = $event_id ? (is_rtl() ? 'تم تحديث الفعالية بنجاح!' : 'Event updated successfully!') : (is_rtl() ? 'تمت إضافة الفعالية بنجاح!' : 'Academic event added successfully!');
            $_SESSION['flash_type'] = 'success';
            header("Location: academic_calendar.php?year=" . urlencode($active_year) . "&month=" . $current_month . "&cal_year=" . $current_year . "&view=" . $view_mode);
            exit();
        }

        if ($action === 'delete_event') {
            $event_id = (int)($_POST['event_id'] ?? 0);
            if ($event_id > 0) {
                AcademicCalendar::deleteEvent($event_id);
                $_SESSION['flash_msg'] = is_rtl() ? 'تم حذف الفعالية من التقويم الأكاديمي.' : 'Academic event deleted successfully.';
                $_SESSION['flash_type'] = 'info';
            }
            header("Location: academic_calendar.php?year=" . urlencode($active_year) . "&month=" . $current_month . "&cal_year=" . $current_year . "&view=" . $view_mode);
            exit();
        }

        if ($action === 'quick_closure') {
            $title      = trim($_POST['closure_title'] ?? (is_rtl() ? 'إغلاق طارئ للمؤسسة' : 'Emergency School Closure'));
            $start_date = $_POST['closure_start_date'] ?? date('Y-m-d');
            $end_date   = $_POST['closure_end_date'] ?? $start_date;
            $reason     = trim($_POST['closure_reason'] ?? '');

            AcademicCalendar::saveEvent([
                'title'           => $title,
                'description'     => $reason,
                'event_type'      => AcademicCalendar::TYPE_CLOSURE,
                'academic_year'   => $active_year,
                'term'            => AcademicCalendar::getActiveTerm($start_date)['term'] ?? 'Term 1',
                'start_date'      => $start_date,
                'end_date'        => $end_date,
                'is_closed'       => 1,
                'color_code'      => '#dc2626',
                'target_audience' => 'all',
            ]);

            $_SESSION['flash_msg'] = is_rtl() ? 'تم تسجيل إغلاق المؤسسة وتحديث الجداول والأنشطة المرتبطة!' : 'School closure registered and all affected modules updated!';
            $_SESSION['flash_type'] = 'warning';
            header("Location: academic_calendar.php?year=" . urlencode($active_year) . "&month=" . $current_month . "&cal_year=" . $current_year . "&view=" . $view_mode);
            exit();
        }
    } catch (\Exception $e) {
        $flash_msg  = $e->getMessage();
        $flash_type = 'danger';
    }
}

// Retrieve flash message from session
if (isset($_SESSION['flash_msg'])) {
    $flash_msg  = $_SESSION['flash_msg'];
    $flash_type = $_SESSION['flash_type'] ?? 'success';
    unset($_SESSION['flash_msg'], $_SESSION['flash_type']);
}

// Fetch calendar data
$month_data      = AcademicCalendar::getEventsForMonth($current_year, $current_month, $active_year);
$all_events      = AcademicCalendar::getAllEvents($active_year, $active_type, $active_term);
$milestones      = AcademicCalendar::getUpcomingMilestones(5, $active_year);
$available_years = ['2024/2025', '2025/2026', '2026/2027', '2027/2028'];

// Calendar grid mathematics
$firstDayTimestamp = strtotime("{$current_year}-{$current_month}-01");
$daysInMonth       = (int)date('t', $firstDayTimestamp);
$startingDayOfWeek = (int)date('w', $firstDayTimestamp); // 0 = Sun, 6 = Sat
$monthName         = date('F Y', $firstDayTimestamp);
$monthNameAr       = [
    1 => 'يناير', 2 => 'فبراير', 3 => 'مارس', 4 => 'أبريل',
    5 => 'مايو', 6 => 'يونيو', 7 => 'يوليو', 8 => 'أغسطس',
    9 => 'سبتمبر', 10 => 'أكتوبر', 11 => 'نوفمبر', 12 => 'ديسمبر'
][$current_month] . " $current_year";

$displayMonthName = is_rtl() ? $monthNameAr : $monthName;

// Prev & Next Month URL calculations
$prevMonth = $current_month - 1;
$prevYear  = $current_year;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $current_month + 1;
$nextYear  = $current_year;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$prevMonthUrl = "?year=" . urlencode($active_year) . "&month={$prevMonth}&cal_year={$prevYear}&view={$view_mode}";
$nextMonthUrl = "?year=" . urlencode($active_year) . "&month={$nextMonth}&cal_year={$nextYear}&view={$view_mode}";
$todayMonthUrl = "?year=" . urlencode($active_year) . "&month=" . date('m') . "&cal_year=" . date('Y') . "&view={$view_mode}";
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'التقويم الأكاديمي والجدول الزمني الرئيسي' : 'Master Academic Calendar' ?> - <?= htmlspecialchars($global_branding['school_name']) ?></title>
    <?= get_bootstrap_css() ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --cal-border: rgba(226, 232, 240, 0.8);
            --cal-bg-hover: rgba(241, 245, 249, 0.6);
            --cal-today-border: var(--primary, #0d6efd);
        }
        [data-bs-theme="dark"] {
            --cal-border: rgba(51, 65, 85, 0.7);
            --cal-bg-hover: rgba(30, 41, 59, 0.6);
            --cal-today-border: #38bdf8;
        }
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            border-top: 1px solid var(--cal-border);
            border-left: 1px solid var(--cal-border);
        }
        .cal-header-cell {
            padding: 0.75rem 0.5rem;
            text-align: center;
            font-weight: 700;
            font-size: 0.8125rem;
            background: var(--bs-secondary-bg);
            border-right: 1px solid var(--cal-border);
            border-bottom: 1px solid var(--cal-border);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .cal-day-cell {
            min-height: 110px;
            padding: 0.5rem;
            border-right: 1px solid var(--cal-border);
            border-bottom: 1px solid var(--cal-border);
            background: var(--bs-body-bg);
            transition: background 0.15s ease-in-out;
            position: relative;
        }
        .cal-day-cell:hover {
            background: var(--cal-bg-hover);
        }
        .cal-day-cell.is-empty {
            background: var(--bs-tertiary-bg);
            opacity: 0.4;
        }
        .cal-day-cell.is-today {
            background: rgba(13, 110, 253, 0.04);
            box-shadow: inset 0 0 0 2px var(--cal-today-border);
        }
        .cal-day-cell.is-closed {
            background: rgba(220, 38, 38, 0.05);
        }
        .cal-day-number {
            font-size: 0.875rem;
            font-weight: 700;
            color: var(--bs-body-color);
            display: inline-flex;
            width: 24px;
            height: 24px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .cal-day-cell.is-today .cal-day-number {
            background: var(--primary, #0d6efd);
            color: #ffffff;
        }
        .cal-event-pill {
            display: block;
            font-size: 0.72rem;
            padding: 0.2rem 0.4rem;
            margin-top: 0.25rem;
            border-radius: 4px;
            text-decoration: none;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 600;
            transition: transform 0.1s ease;
            cursor: pointer;
        }
        .cal-event-pill:hover {
            transform: scale(1.02);
            filter: brightness(0.95);
        }
        .milestone-timeline-item {
            position: relative;
            padding-left: 1.5rem;
            padding-bottom: 1.25rem;
        }
        [dir="rtl"] .milestone-timeline-item {
            padding-left: 0;
            padding-right: 1.5rem;
        }
        .milestone-timeline-item::before {
            content: '';
            position: absolute;
            left: 0.4rem;
            top: 0.5rem;
            bottom: 0;
            width: 2px;
            background: var(--cal-border);
        }
        [dir="rtl"] .milestone-timeline-item::before {
            left: auto;
            right: 0.4rem;
        }
        .milestone-timeline-item:last-child::before {
            display: none;
        }
        .milestone-bullet {
            position: absolute;
            left: 0;
            top: 0.25rem;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: var(--primary, #0d6efd);
            border: 2px solid var(--bs-body-bg);
        }
        [dir="rtl"] .milestone-bullet {
            left: auto;
            right: 0;
        }
    </style>
</head>
<body>

    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'التقويم الأكاديمي' : 'Academic Calendar',
        'back_url' => 'index.php'
    ]) ?>

    <div class="container-fluid px-3 px-lg-4 py-3">
        
        <!-- Header Controls & Actions -->
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 fw-bold mb-1 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-calendar-days text-primary"></i>
                    <span><?= is_rtl() ? 'التقويم الأكاديمي والجدول الزمني الرئيسي' : 'Master Academic Calendar' ?></span>
                </h1>
                <p class="text-muted-foreground mb-0 small">
                    <?= is_rtl() 
                        ? 'إدارة فترات الفصول الدراسية، العطلات الرسمية، فترات الامتحانات والإغلاقات المؤسسية المعتمدة.' 
                        : 'Centralized governance for term dates, public holidays, exam assessment windows and school closures.' ?>
                </p>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex align-items-center flex-wrap gap-2">
                <button type="button" class="btn btn-primary fw-bold shadow-xs px-3" data-bs-toggle="modal" data-bs-target="#eventModal" onclick="openCreateModal()">
                    <i class="fa-solid fa-plus me-1"></i>
                    <span><?= is_rtl() ? 'إضافة حدث / موعد' : 'New Academic Event' ?></span>
                </button>
                <button type="button" class="btn btn-outline-danger fw-bold shadow-xs px-3" data-bs-toggle="modal" data-bs-target="#quickClosureModal">
                    <i class="fa-solid fa-ban me-1"></i>
                    <span><?= is_rtl() ? 'إعلان إغلاق للمؤسسة' : 'Declare Closure' ?></span>
                </button>
                <button type="button" class="btn btn-outline-success fw-bold shadow-xs px-3" onclick="openCalendarPrintPreview()">
                    <i class="fa-solid fa-print me-1"></i>
                    <span><?= is_rtl() ? 'طباعة التقويم' : 'Print Calendar' ?></span>
                </button>
                <div class="btn-group shadow-xs">
                    <a href="?year=<?= urlencode($active_year) ?>&month=<?= $current_month ?>&cal_year=<?= $current_year ?>&view=calendar" class="btn btn-outline-border <?= $view_mode === 'calendar' ? 'active' : '' ?>" title="Calendar Grid">
                        <i class="fa-solid fa-calendar-days"></i>
                    </a>
                    <a href="?year=<?= urlencode($active_year) ?>&month=<?= $current_month ?>&cal_year=<?= $current_year ?>&view=list" class="btn btn-outline-border <?= $view_mode === 'list' ? 'active' : '' ?>" title="List Timeline">
                        <i class="fa-solid fa-list-ul"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Flash Alerts -->
        <?php if (!empty($flash_msg)): ?>
            <div class="alert alert-<?= $flash_type ?> alert-dismissible fade show d-flex align-items-center gap-2 shadow-xs" role="alert">
                <i class="fa-solid <?= $flash_type === 'success' ? 'fa-circle-check' : ($flash_type === 'warning' ? 'fa-triangle-exclamation' : 'fa-circle-exclamation') ?>"></i>
                <div><?= htmlspecialchars($flash_msg) ?></div>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Active Academic Year & Quick Filter Strip -->
        <div class="card border-0 bg-secondary shadow-xs mb-4">
            <div class="card-body py-2 px-3">
                <form method="GET" class="row g-2 align-items-center">
                    <input type="hidden" name="view" value="<?= htmlspecialchars($view_mode) ?>">
                    <input type="hidden" name="month" value="<?= $current_month ?>">
                    <input type="hidden" name="cal_year" value="<?= $current_year ?>">

                    <div class="col-auto">
                        <span class="small fw-bold text-muted-foreground"><?= is_rtl() ? 'العام الأكاديمي:' : 'Academic Year:' ?></span>
                    </div>
                    <div class="col-auto">
                        <select name="year" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($available_years as $y): ?>
                                <option value="<?= $y ?>" <?= $active_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-auto ms-md-3">
                        <span class="small fw-bold text-muted-foreground"><?= is_rtl() ? 'الفصل الدراسي:' : 'Term:' ?></span>
                    </div>
                    <div class="col-auto">
                        <select name="term" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value=""><?= is_rtl() ? 'جميع الفصول' : 'All Terms' ?></option>
                            <option value="Term 1" <?= $active_term === 'Term 1' ? 'selected' : '' ?>><?= is_rtl() ? 'الفصل الدراسي الأول' : 'Term 1' ?></option>
                            <option value="Term 2" <?= $active_term === 'Term 2' ? 'selected' : '' ?>><?= is_rtl() ? 'الفصل الدراسي الثاني' : 'Term 2' ?></option>
                            <option value="Term 3" <?= $active_term === 'Term 3' ? 'selected' : '' ?>><?= is_rtl() ? 'الفصل الدراسي الثالث' : 'Term 3' ?></option>
                            <option value="Full Year" <?= $active_term === 'Full Year' ? 'selected' : '' ?>><?= is_rtl() ? 'العام كاملاً' : 'Full Year' ?></option>
                        </select>
                    </div>

                    <div class="col-auto ms-md-3">
                        <span class="small fw-bold text-muted-foreground"><?= is_rtl() ? 'نوع الفعالية:' : 'Event Type:' ?></span>
                    </div>
                    <div class="col-auto">
                        <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value=""><?= is_rtl() ? 'كافة الأنواع' : 'All Event Types' ?></option>
                            <option value="Term Start" <?= $active_type === 'Term Start' ? 'selected' : '' ?>><?= is_rtl() ? 'بداية فصل' : 'Term Start' ?></option>
                            <option value="Term End" <?= $active_type === 'Term End' ? 'selected' : '' ?>><?= is_rtl() ? 'نهاية فصل' : 'Term End' ?></option>
                            <option value="Holiday" <?= $active_type === 'Holiday' ? 'selected' : '' ?>><?= is_rtl() ? 'عطلة رسمية' : 'Holiday' ?></option>
                            <option value="School Closure" <?= $active_type === 'School Closure' ? 'selected' : '' ?>><?= is_rtl() ? 'إغلاق للمؤسسة' : 'School Closure' ?></option>
                            <option value="Exam Period" <?= $active_type === 'Exam Period' ? 'selected' : '' ?>><?= is_rtl() ? 'فترة امتحانات' : 'Exam Period' ?></option>
                            <option value="General Event" <?= $active_type === 'General Event' ? 'selected' : '' ?>><?= is_rtl() ? 'فعالية عامة' : 'General Event' ?></option>
                        </select>
                    </div>

                    <?php if (!empty($active_term) || !empty($active_type)): ?>
                        <div class="col-auto">
                            <a href="?year=<?= urlencode($active_year) ?>&month=<?= $current_month ?>&cal_year=<?= $current_year ?>&view=<?= $view_mode ?>" class="btn btn-sm btn-link text-decoration-none">
                                <i class="fa-solid fa-xmark me-1"></i><?= is_rtl() ? 'إعادة ضبط' : 'Reset Filters' ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- Left / Main Area: Calendar Grid or List View -->
            <div class="col-xl-9 col-lg-8">
                
                <?php if ($view_mode === 'calendar'): ?>
                    
                    <!-- Month Navigation Header -->
                    <div class="card border border-border shadow-xs overflow-hidden mb-4">
                        <div class="card-header bg-card border-bottom d-flex align-items-center justify-content-between py-3">
                            <h2 class="h5 fw-bold mb-0 text-foreground d-flex align-items-center gap-2">
                                <i class="fa-regular fa-calendar text-primary"></i>
                                <span><?= $displayMonthName ?></span>
                            </h2>
                            <div class="d-flex align-items-center gap-2">
                                <a href="<?= $prevMonthUrl ?>" class="btn btn-sm btn-outline-border rounded-lg px-2.5" title="Previous Month">
                                    <i class="fa-solid fa-chevron-<?= is_rtl() ? 'right' : 'left' ?>"></i>
                                </a>
                                <a href="<?= $todayMonthUrl ?>" class="btn btn-sm btn-outline-border rounded-lg px-3 fw-semibold">
                                    <?= is_rtl() ? 'اليوم' : 'Today' ?>
                                </a>
                                <a href="<?= $nextMonthUrl ?>" class="btn btn-sm btn-outline-border rounded-lg px-2.5" title="Next Month">
                                    <i class="fa-solid fa-chevron-<?= is_rtl() ? 'left' : 'right' ?>"></i>
                                </a>
                            </div>
                        </div>

                        <!-- Interactive 7-Column Grid -->
                        <div class="cal-grid">
                            <?php 
                            $weekDayNames = is_rtl() 
                                ? ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
                                : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                            foreach ($weekDayNames as $wName): ?>
                                <div class="cal-header-cell text-muted-foreground"><?= $wName ?></div>
                            <?php endforeach; ?>

                            <!-- Empty Leading Days -->
                            <?php for ($i = 0; $i < $startingDayOfWeek; $i++): ?>
                                <div class="cal-day-cell is-empty"></div>
                            <?php endfor; ?>

                            <!-- Month Days -->
                            <?php 
                            $todayDate = date('Y-m-d');
                            for ($day = 1; $day <= $daysInMonth; $day++): 
                                $dateStr = sprintf('%04d-%02d-%02d', $current_year, $current_month, $day);
                                $isToday = ($dateStr === $todayDate);
                                $dayEvents = $month_data['by_date'][$dateStr] ?? [];
                                $isClosedDay = false;
                                foreach ($dayEvents as $dev) {
                                    if (!empty($dev['is_closed'])) {
                                        $isClosedDay = true;
                                        break;
                                    }
                                }
                            ?>
                                <div class="cal-day-cell <?= $isToday ? 'is-today' : '' ?> <?= $isClosedDay ? 'is-closed' : '' ?>">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <span class="cal-day-number"><?= $day ?></span>
                                        <?php if ($isClosedDay): ?>
                                            <span class="badge bg-danger p-1" style="font-size: 0.6rem;" title="<?= is_rtl() ? 'المؤسسة مغلقة' : 'School Closed' ?>">
                                                <i class="fa-solid fa-lock"></i>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Render Day Events -->
                                    <div class="d-flex flex-column gap-1">
                                        <?php foreach ($dayEvents as $evt): 
                                            $evtColor = !empty($evt['color_code']) ? $evt['color_code'] : '#0ea5e9';
                                        ?>
                                            <div class="cal-event-pill" 
                                                 style="background-color: <?= htmlspecialchars($evt['config']['bg_opacity'] ?? 'rgba(14,165,233,0.12)') ?>; color: <?= htmlspecialchars($evtColor) ?>; border-inline-start: 3px solid <?= htmlspecialchars($evtColor) ?>;"
                                                 onclick='openEditModal(<?= json_encode($evt, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                 title="<?= htmlspecialchars($evt['title']) ?> (<?= htmlspecialchars($evt['event_type']) ?>)">
                                                <i class="<?= htmlspecialchars($evt['config']['icon']) ?> me-1" style="font-size: 0.7rem;"></i>
                                                <?= htmlspecialchars($evt['title']) ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endfor; ?>

                            <!-- Empty Trailing Days -->
                            <?php 
                            $totalCells = $startingDayOfWeek + $daysInMonth;
                            $trailingEmpty = (7 - ($totalCells % 7)) % 7;
                            for ($j = 0; $j < $trailingEmpty; $j++): ?>
                                <div class="cal-day-cell is-empty"></div>
                            <?php endfor; ?>
                        </div>
                    </div>

                <?php else: ?>

                    <!-- List / Detailed Timeline View -->
                    <div class="card border border-border shadow-xs overflow-hidden mb-4">
                        <div class="card-header bg-card border-bottom d-flex align-items-center justify-content-between py-3">
                            <h2 class="h5 fw-bold mb-0 text-foreground">
                                <i class="fa-solid fa-timeline text-primary me-2"></i>
                                <span><?= is_rtl() ? 'سجل الأحداث والمواعيد الأكاديمية' : 'Academic Milestones & Events Ledger' ?></span>
                            </h2>
                            <span class="badge bg-secondary text-foreground"><?= count($all_events) ?> <?= is_rtl() ? 'فعالية مسجلة' : 'Events Total' ?></span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th class="ps-3"><?= is_rtl() ? 'النوع' : 'Event Type' ?></th>
                                        <th><?= is_rtl() ? 'عنوان الفعالية' : 'Event Title' ?></th>
                                        <th><?= is_rtl() ? 'الفصل الدراسي' : 'Term' ?></th>
                                        <th><?= is_rtl() ? 'الفترة الزمنية' : 'Date Schedule' ?></th>
                                        <th><?= is_rtl() ? 'الإغلاق المؤسسي' : 'Operational Status' ?></th>
                                        <th><?= is_rtl() ? 'الجمهور المستهدف' : 'Audience' ?></th>
                                        <th class="text-end pe-3"><?= is_rtl() ? 'إجراءات' : 'Actions' ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_events)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-5 text-muted-foreground">
                                                <i class="fa-regular fa-calendar-xmark fs-2 mb-2 d-block"></i>
                                                <?= is_rtl() ? 'لا توجد فعاليات مسجلة لهذا العام الأكاديمي أو التصفية الحالية.' : 'No academic events found matching the active filter criteria.' ?>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($all_events as $evt): 
                                            $isMultiDay = ($evt['start_date'] !== $evt['end_date']);
                                        ?>
                                            <tr>
                                                <td class="ps-3">
                                                    <span class="<?= htmlspecialchars($evt['config']['badge']) ?>">
                                                        <i class="<?= htmlspecialchars($evt['config']['icon']) ?> me-1"></i>
                                                        <?= htmlspecialchars($evt['config']['label']) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="fw-bold text-foreground"><?= htmlspecialchars($evt['title']) ?></div>
                                                    <?php if (!empty($evt['description'])): ?>
                                                        <div class="text-muted-foreground small" style="max-width: 320px;"><?= htmlspecialchars($evt['description']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($evt['term'] ?: 'Term 1') ?></span>
                                                </td>
                                                <td>
                                                    <div class="small fw-semibold">
                                                        <i class="fa-regular fa-calendar text-primary me-1"></i>
                                                        <?= htmlspecialchars($evt['start_date']) ?>
                                                        <?php if ($isMultiDay): ?>
                                                            <span class="text-muted-foreground"> <?= is_rtl() ? 'إلى' : 'to' ?> </span>
                                                            <?= htmlspecialchars($evt['end_date']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php if (!empty($evt['is_closed'])): ?>
                                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                                                            <i class="fa-solid fa-lock me-1"></i><?= is_rtl() ? 'إغلاق كامل للمؤسسة' : 'School Closed' ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                                            <i class="fa-solid fa-chalkboard-user me-1"></i><?= is_rtl() ? 'يوم دراسي معتاد' : 'Open / Instructional' ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary text-capitalize"><?= htmlspecialchars($evt['target_audience']) ?></span>
                                                </td>
                                                <td class="text-end pe-3">
                                                    <div class="btn-group btn-group-sm">
                                                        <button type="button" class="btn btn-outline-border" onclick='openEditModal(<?= json_encode($evt, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit">
                                                            <i class="fa-solid fa-pen-to-square text-primary"></i>
                                                        </button>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('<?= is_rtl() ? 'هل أنت متأكد من حذف هذه الفعالية من التقويم الأكاديمي؟' : 'Are you sure you want to delete this event from the academic calendar?' ?>');">
                                                            <input type="hidden" name="action" value="delete_event">
                                                            <input type="hidden" name="event_id" value="<?= $evt['event_id'] ?>">
                                                            <button type="submit" class="btn btn-outline-border text-danger" title="Delete">
                                                                <i class="fa-solid fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php endif; ?>

            </div>

            <!-- Right Sidebar: Milestones & Quick Intelligence -->
            <div class="col-xl-3 col-lg-4">
                
                <!-- Upcoming Milestones Widget -->
                <div class="card border border-border shadow-xs mb-4">
                    <div class="card-header bg-card border-bottom py-3">
                        <h3 class="h6 fw-bold mb-0 text-foreground d-flex align-items-center justify-content-between">
                            <span><i class="fa-solid fa-hourglass-half text-primary me-2"></i><?= is_rtl() ? 'المواعيد والمهام القادمة' : 'Upcoming Milestones' ?></span>
                            <span class="badge bg-primary text-white"><?= count($milestones) ?></span>
                        </h3>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($milestones)): ?>
                            <div class="text-center py-4 text-muted-foreground small">
                                <i class="fa-regular fa-calendar-check fs-3 mb-2 d-block text-success"></i>
                                <?= is_rtl() ? 'لا توجد مواعيد قريبة قادمة مسجلة.' : 'No upcoming milestones recorded.' ?>
                            </div>
                        <?php else: ?>
                            <div class="milestone-timeline">
                                <?php foreach ($milestones as $m): ?>
                                    <div class="milestone-timeline-item">
                                        <div class="milestone-bullet" style="background-color: <?= htmlspecialchars($m['color_code'] ?: '#0d6efd') ?>;"></div>
                                        <div class="d-flex align-items-center justify-content-between mb-1">
                                            <span class="<?= htmlspecialchars($m['countdown_badge']) ?>" style="font-size: 0.68rem;">
                                                <?= htmlspecialchars($m['countdown_label']) ?>
                                            </span>
                                            <span class="text-muted-foreground small" style="font-size: 0.7rem;"><?= htmlspecialchars($m['start_date']) ?></span>
                                        </div>
                                        <div class="fw-bold text-foreground small line-height-sm mb-1"><?= htmlspecialchars($m['title']) ?></div>
                                        <div class="text-muted-foreground" style="font-size: 0.72rem;">
                                            <span class="<?= htmlspecialchars($m['config']['badge']) ?> me-1" style="font-size: 0.65rem;"><?= htmlspecialchars($m['config']['label']) ?></span>
                                            <span><?= htmlspecialchars($m['term']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Event Category Legend Card -->
                <div class="card border border-border shadow-xs mb-4">
                    <div class="card-header bg-card border-bottom py-3">
                        <h4 class="h6 fw-bold mb-0 text-foreground">
                            <i class="fa-solid fa-palette text-primary me-2"></i>
                            <span><?= is_rtl() ? 'دليل تصنيف الأحداث' : 'Event Categories Legend' ?></span>
                        </h4>
                    </div>
                    <div class="card-body p-3">
                        <div class="d-flex flex-column gap-2 small">
                            <div class="d-flex align-items-center justify-content-between p-2 rounded-lg bg-secondary">
                                <span class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-door-open text-success"></i>
                                    <span><?= is_rtl() ? 'بداية الفصل' : 'Term Start' ?></span>
                                </span>
                                <span class="badge bg-success"><?= is_rtl() ? 'افتتاح' : 'Start' ?></span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between p-2 rounded-lg bg-secondary">
                                <span class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-flag-checkered text-danger"></i>
                                    <span><?= is_rtl() ? 'نهاية الفصل' : 'Term End' ?></span>
                                </span>
                                <span class="badge bg-danger"><?= is_rtl() ? 'ختام' : 'End' ?></span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between p-2 rounded-lg bg-secondary">
                                <span class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-umbrella-beach text-warning"></i>
                                    <span><?= is_rtl() ? 'عطلة رسمية' : 'Public Holiday' ?></span>
                                </span>
                                <span class="badge bg-warning text-dark"><?= is_rtl() ? 'عطلة' : 'Holiday' ?></span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between p-2 rounded-lg bg-secondary">
                                <span class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-ban text-danger"></i>
                                    <span><?= is_rtl() ? 'إغلاق المؤسسة' : 'School Closure' ?></span>
                                </span>
                                <span class="badge bg-danger text-white"><?= is_rtl() ? 'مغلق' : 'Closed' ?></span>
                            </div>
                            <div class="d-flex align-items-center justify-content-between p-2 rounded-lg bg-secondary">
                                <span class="d-flex align-items-center gap-2">
                                    <i class="fa-solid fa-file-pen" style="color: #8b5cf6;"></i>
                                    <span><?= is_rtl() ? 'فترة الامتحانات' : 'Exam Period' ?></span>
                                </span>
                                <span class="badge" style="background-color: #8b5cf6; color: white;"><?= is_rtl() ? 'امتحانات' : 'Exams' ?></span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- Create / Edit Event Modal -->
    <div class="modal fade" id="eventModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="save_event">
                    <input type="hidden" name="event_id" id="modalEventId" value="">
                    
                    <div class="modal-header">
                        <h5 class="modal-title fw-bold" id="modalEventHeading">
                            <i class="fa-solid fa-calendar-plus text-primary me-2"></i>
                            <span><?= is_rtl() ? 'إضافة حدث أكاديمي جديد' : 'New Academic Event' ?></span>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'عنوان الفعالية / الموعد' : 'Event Title' ?> <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="modalTitle" class="form-control" required placeholder="e.g. Mid-Term Assessment Block">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'نوع الفعالية' : 'Event Type' ?> <span class="text-danger">*</span></label>
                                <select name="event_type" id="modalEventType" class="form-select" required onchange="handleEventTypeChange(this.value)">
                                    <option value="General Event"><?= is_rtl() ? 'فعالية مدرسية عامة' : 'General Event' ?></option>
                                    <option value="Term Start"><?= is_rtl() ? 'بداية فصل دراسي' : 'Term Start' ?></option>
                                    <option value="Term End"><?= is_rtl() ? 'نهاية فصل دراسي' : 'Term End' ?></option>
                                    <option value="Holiday"><?= is_rtl() ? 'عطلة رسمية' : 'Holiday' ?></option>
                                    <option value="School Closure"><?= is_rtl() ? 'إغلاق المؤسسة' : 'School Closure' ?></option>
                                    <option value="Exam Period"><?= is_rtl() ? 'فترة الامتحانات' : 'Exam Period' ?></option>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'العام الأكاديمي' : 'Academic Year' ?></label>
                                <select name="academic_year" id="modalAcademicYear" class="form-select">
                                    <?php foreach ($available_years as $y): ?>
                                        <option value="<?= $y ?>" <?= $active_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'الفصل الدراسي' : 'Term' ?></label>
                                <select name="term" id="modalTerm" class="form-select">
                                    <option value="Term 1"><?= is_rtl() ? 'الفصل الدراسي الأول' : 'Term 1' ?></option>
                                    <option value="Term 2"><?= is_rtl() ? 'الفصل الدراسي الثاني' : 'Term 2' ?></option>
                                    <option value="Term 3"><?= is_rtl() ? 'الفصل الدراسي الثالث' : 'Term 3' ?></option>
                                    <option value="Full Year"><?= is_rtl() ? 'العام كاملاً' : 'Full Year' ?></option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'الجمهور المستهدف' : 'Target Audience' ?></label>
                                <select name="target_audience" id="modalTargetAudience" class="form-select">
                                    <option value="all"><?= is_rtl() ? 'الجميع (الكل)' : 'All (Public)' ?></option>
                                    <option value="teachers"><?= is_rtl() ? 'المعلمون فقط' : 'Teachers Only' ?></option>
                                    <option value="students"><?= is_rtl() ? 'الطلاب وأولياء الأمور' : 'Students & Parents' ?></option>
                                    <option value="admin"><?= is_rtl() ? 'الإدارة المدرسية' : 'School Leadership' ?></option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'تاريخ البدء' : 'Start Date' ?> <span class="text-danger">*</span></label>
                                <input type="date" name="start_date" id="modalStartDate" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'تاريخ الانتهاء' : 'End Date' ?> <span class="text-danger">*</span></label>
                                <input type="date" name="end_date" id="modalEndDate" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>

                            <div class="col-md-8">
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="is_closed" id="modalIsClosed" value="1">
                                    <label class="form-check-label fw-bold" for="modalIsClosed">
                                        <?= is_rtl() ? 'إغلاق المؤسسة وتعطيل الحضور المدرسي في هذا اليوم' : 'School Closure (No instructional classes or attendance)' ?>
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small"><?= is_rtl() ? 'لون التمييز' : 'Badge Color Tag' ?></label>
                                <input type="color" name="color_code" id="modalColorCode" class="form-control form-control-color w-100" value="#0ea5e9">
                            </div>

                            <div class="col-12">
                                <label class="form-label fw-bold"><?= is_rtl() ? 'التفاصيل والوصف' : 'Description / Instructions' ?></label>
                                <textarea name="description" id="modalDescription" rows="3" class="form-control" placeholder="<?= is_rtl() ? 'أي ملاحظات أو توجيهات خاصة بالفعالية...' : 'Optional details or instructions for this academic event...' ?>"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer bg-secondary">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold px-4"><?= is_rtl() ? 'حفظ وتثبيت' : 'Save Event' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Emergency Closure Modal -->
    <div class="modal fade" id="quickClosureModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="quick_closure">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title fw-bold">
                            <i class="fa-solid fa-ban me-2"></i>
                            <span><?= is_rtl() ? 'إعلان إغلاق طارئ / عطلة استثنائية' : 'Declare School Closure' ?></span>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted-foreground small mb-3">
                            <?= is_rtl() 
                                ? 'سيتم تسجيل هذا اليوم كإغلاق رسمي، مما يعطل تلقائياً تسجيل الغياب ويلغي الدروس المجدولة في هذا التاريخ.' 
                                : 'Declaring a closure will mark the selected dates as non-instructional, synchronizing with attendance and timetable logs.' ?>
                        </p>
                        <div class="mb-3">
                            <label class="form-label fw-bold"><?= is_rtl() ? 'سبب الإغلاق' : 'Closure Title / Reason' ?> <span class="text-danger">*</span></label>
                            <input type="text" name="closure_title" class="form-control" required placeholder="e.g. Unscheduled Weather Closure / National Holiday">
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-bold small"><?= is_rtl() ? 'من تاريخ' : 'From Date' ?></label>
                                <input type="date" name="closure_start_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-bold small"><?= is_rtl() ? 'إلى تاريخ' : 'To Date' ?></label>
                                <input type="date" name="closure_end_date" class="form-control" required value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold small"><?= is_rtl() ? 'ملاحظات إضافية' : 'Additional Notes' ?></label>
                            <textarea name="closure_reason" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer bg-secondary">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-danger fw-bold px-4"><?= is_rtl() ? 'تأكيد الإغلاق' : 'Confirm Closure' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Academic Calendar Print Preview Modal (In-Page Intermediary Controller Layer) -->
    <div class="modal fade" id="calendarPrintModal" tabindex="-1" aria-labelledby="calendarPrintModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen-lg-down modal-xl modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                <div class="modal-header bg-dark text-white py-2.5 px-3">
                    <h5 class="modal-title fw-bold fs-6 d-flex align-items-center" id="calendarPrintModalLabel">
                        <i class="fa-solid fa-calendar-check me-2 text-warning"></i>
                        <span><?= is_rtl() ? 'معاينة طباعة التقويم الأكاديمي وتخصيص المخرجات' : 'Academic Calendar Print Preview & Format Configuration' ?></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <!-- Filter / Adjustment Bar -->
                <div class="modal-body p-3 bg-light">
                    <div class="row g-2 align-items-center mb-3 no-print">
                        <div class="col-auto">
                            <label class="form-label small fw-bold mb-0 text-muted"><?= is_rtl() ? 'التنسيق:' : 'Format:' ?></label>
                        </div>
                        <div class="col-auto">
                            <select id="modalCalFormat" class="form-select form-select-sm fw-bold" onchange="onCalendarFormatChange()">
                                <option value="month" selected><?= is_rtl() ? 'شبكة شهرية (7 أيام)' : 'Monthly Matrix Grid (7-Day)' ?></option>
                                <option value="year"><?= is_rtl() ? 'الخطة السنوية والجدول الزمني' : 'Full Year Milestone Ledger' ?></option>
                            </select>
                        </div>

                        <div class="col-auto">
                            <label class="form-label small fw-bold mb-0 text-muted"><?= is_rtl() ? 'العام:' : 'Year:' ?></label>
                        </div>
                        <div class="col-auto">
                            <select id="modalCalYear" class="form-select form-select-sm fw-bold" onchange="refreshCalendarPrintPreview()">
                                <?php foreach ($available_years as $y): ?>
                                    <option value="<?= $y ?>" <?= $active_year === $y ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-auto" id="modalCalMonthWrapper">
                            <label class="form-label small fw-bold mb-0 text-muted"><?= is_rtl() ? 'الشهر:' : 'Month:' ?></label>
                        </div>
                        <div class="col-auto" id="modalCalMonthSelectWrapper">
                            <select id="modalCalMonth" class="form-select form-select-sm fw-bold" onchange="refreshCalendarPrintPreview()">
                                <?php 
                                for ($m = 1; $m <= 12; $m++): 
                                    $mNameStr = is_rtl() ? ($monthNameAr[$m] ?? "شهر $m") : date('F', mktime(0,0,0,$m,1));
                                ?>
                                    <option value="<?= $m ?>" <?= $current_month === $m ? 'selected' : '' ?>><?= $m ?> - <?= $mNameStr ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="col-auto">
                            <span class="badge bg-white text-secondary border px-2.5 py-1.5 shadow-xs" style="font-size: 0.75rem;">
                                <i class="fa-solid fa-file-pdf me-1 text-danger"></i><?= is_rtl() ? 'صفحة أفقية واحدة (A4 Landscape)' : '1-Page A4 Landscape Fitted' ?>
                            </span>
                        </div>

                        <div class="col text-end">
                            <button type="button" class="btn btn-success btn-sm fw-bold px-3 shadow-xs" onclick="executeTargetedCalendarPrint()">
                                <i class="fa-solid fa-print me-1"></i> <?= is_rtl() ? 'طباعة / حفظ PDF' : 'Print / Save PDF' ?>
                            </button>
                        </div>
                    </div>

                    <!-- Isolated Iframe Matrix Viewport (Single-Page Landscape Replica) -->
                    <div class="border rounded-3 bg-white shadow-xs" style="height: 560px; max-height: calc(85vh - 120px); overflow: hidden;">
                        <iframe id="calendarPrintPreviewFrame" src="about:blank" style="width: 100%; height: 100%; border: none;"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?= render_platform_footer() ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let calendarPrintModalInstance = null;

        function openCalendarPrintPreview() {
            if (!calendarPrintModalInstance) {
                calendarPrintModalInstance = new bootstrap.Modal(document.getElementById('calendarPrintModal'));
            }
            calendarPrintModalInstance.show();
            refreshCalendarPrintPreview();
        }

        function onCalendarFormatChange() {
            const format = document.getElementById('modalCalFormat').value;
            const isMonth = (format === 'month');
            
            document.getElementById('modalCalMonthWrapper').classList.toggle('d-none', !isMonth);
            document.getElementById('modalCalMonthSelectWrapper').classList.toggle('d-none', !isMonth);
            
            refreshCalendarPrintPreview();
        }

        function refreshCalendarPrintPreview() {
            const format = document.getElementById('modalCalFormat').value;
            const year = document.getElementById('modalCalYear').value;
            const month = document.getElementById('modalCalMonth').value;
            const calYear = '<?= $current_year ?>';

            const frame = document.getElementById('calendarPrintPreviewFrame');
            frame.src = `print_academic_calendar.php?year=${encodeURIComponent(year)}&month=${encodeURIComponent(month)}&cal_year=${encodeURIComponent(calYear)}&format=${encodeURIComponent(format)}&embedded=1`;
        }

        function executeTargetedCalendarPrint() {
            const frame = document.getElementById('calendarPrintPreviewFrame');
            if (frame && frame.contentWindow) {
                frame.contentWindow.focus();
                frame.contentWindow.print(); // Targeted print execution exclusively on the iframe container
            }
        }

        function openCreateModal() {
            document.getElementById('modalEventId').value = '';
            document.getElementById('modalEventHeading').innerHTML = '<i class="fa-solid fa-calendar-plus text-primary me-2"></i> <span><?= is_rtl() ? "إضافة حدث أكاديمي جديد" : "New Academic Event" ?></span>';
            document.getElementById('modalTitle').value = '';
            document.getElementById('modalDescription').value = '';
            document.getElementById('modalEventType').value = 'General Event';
            document.getElementById('modalTerm').value = 'Term 1';
            document.getElementById('modalStartDate').value = '<?= date('Y-m-d') ?>';
            document.getElementById('modalEndDate').value = '<?= date('Y-m-d') ?>';
            document.getElementById('modalIsClosed').checked = false;
            document.getElementById('modalColorCode').value = '#0ea5e9';
            document.getElementById('modalTargetAudience').value = 'all';
        }

        function openEditModal(evt) {
            document.getElementById('modalEventId').value = evt.event_id;
            document.getElementById('modalEventHeading').innerHTML = '<i class="fa-solid fa-pen-to-square text-primary me-2"></i> <span><?= is_rtl() ? "تعديل الحدث الأكاديمي" : "Edit Academic Event" ?></span>';
            document.getElementById('modalTitle').value = evt.title || '';
            document.getElementById('modalDescription').value = evt.description || '';
            document.getElementById('modalEventType').value = evt.event_type || 'General Event';
            document.getElementById('modalAcademicYear').value = evt.academic_year || '<?= $active_year ?>';
            document.getElementById('modalTerm').value = evt.term || 'Term 1';
            document.getElementById('modalStartDate').value = evt.start_date || '';
            document.getElementById('modalEndDate').value = evt.end_date || evt.start_date || '';
            document.getElementById('modalIsClosed').checked = (evt.is_closed == 1);
            document.getElementById('modalColorCode').value = evt.color_code || '#0ea5e9';
            document.getElementById('modalTargetAudience').value = evt.target_audience || 'all';

            var modal = new bootstrap.Modal(document.getElementById('eventModal'));
            modal.show();
        }

        function handleEventTypeChange(type) {
            var colorPicker = document.getElementById('modalColorCode');
            var isClosedSwitch = document.getElementById('modalIsClosed');

            if (type === 'Holiday' || type === 'School Closure') {
                isClosedSwitch.checked = true;
                colorPicker.value = (type === 'School Closure') ? '#dc2626' : '#f59e0b';
            } else if (type === 'Term Start') {
                isClosedSwitch.checked = false;
                colorPicker.value = '#10b981';
            } else if (type === 'Term End') {
                isClosedSwitch.checked = false;
                colorPicker.value = '#ef4444';
            } else if (type === 'Exam Period') {
                isClosedSwitch.checked = false;
                colorPicker.value = '#8b5cf6';
            } else {
                isClosedSwitch.checked = false;
                colorPicker.value = '#0ea5e9';
            }
        }
    </script>
</body>
</html>
