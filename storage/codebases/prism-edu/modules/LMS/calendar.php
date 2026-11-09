<?php
declare(strict_types=1);

/**
 * Academic Calendar Portal (LMS & Academic Timeline Viewer)
 * Interactive schedule, milestones countdown and holiday timeline for teachers, students & parents.
 */

require_once __DIR__ . '/../../core/init.php';

// Ensure user is logged in
Auth::requireLogin();

$user_id   = Auth::id();
$user_role = Auth::role();

$active_year = $_GET['year'] ?? AcademicCalendar::getActiveAcademicYear();
$active_type = $_GET['type'] ?? '';
$active_term = $_GET['term'] ?? '';

// Month navigation for calendar grid
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$current_year  = isset($_GET['cal_year']) ? (int)$_GET['cal_year'] : (int)date('Y');

if ($current_month < 1) {
    $current_month = 12;
    $current_year--;
} elseif ($current_month > 12) {
    $current_month = 1;
    $current_year++;
}

// Fetch calendar data filtered for the active user's audience
$month_data = AcademicCalendar::getEventsForMonth($current_year, $current_month, $active_year);
$milestones = AcademicCalendar::getUpcomingMilestones(6, $active_year, $user_role);

// Calendar grid mathematics
$firstDayTimestamp = strtotime("{$current_year}-{$current_month}-01");
$daysInMonth       = (int)date('t', $firstDayTimestamp);
$startingDayOfWeek = (int)date('w', $firstDayTimestamp);
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

$available_years = ['2024/2025', '2025/2026', '2026/2027', '2027/2028'];

$prevMonthUrl  = "?year=" . urlencode($active_year) . "&month={$prevMonth}&cal_year={$prevYear}";
$nextMonthUrl  = "?year=" . urlencode($active_year) . "&month={$nextMonth}&cal_year={$nextYear}";
$todayMonthUrl = "?year=" . urlencode($active_year) . "&month=" . date('m') . "&cal_year=" . date('Y');
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'التقويم المدرسي والجدول الأكاديمي' : 'School Academic Calendar' ?> - <?= htmlspecialchars($global_branding['school_name']) ?></title>
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
        }
        .cal-day-cell {
            min-height: 105px;
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
            opacity: 0.35;
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
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.1s ease;
        }
        .cal-event-pill:hover {
            transform: scale(1.02);
            filter: brightness(0.92);
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
        'back_url' => BASE_URL . '/dashboard.php'
    ]) ?>

    <div class="container-fluid px-3 px-lg-4 py-3">
        
        <!-- Header Controls & View Banner -->
        <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 fw-bold mb-1 d-flex align-items-center gap-2">
                    <i class="fa-solid fa-calendar-days text-primary"></i>
                    <span><?= is_rtl() ? 'التقويم المدرسي والجدول الأكاديمي' : 'School Academic Calendar' ?></span>
                </h1>
                <p class="text-muted-foreground mb-0 small">
                    <?= is_rtl() 
                        ? 'متابعة مواعيد الفصول، العطلات المعتمدة، فترات الاختبارات والفعاليات المدرسية للعام ' . htmlspecialchars($active_year)
                        : 'Explore term boundaries, official holidays, assessment blocks, and upcoming milestones for ' . htmlspecialchars($active_year) ?>
                </p>
            </div>

            <div class="d-flex align-items-center gap-2">
                <?php if (Auth::hasRole('admin') || Auth::hasRole('school_admin')): ?>
                    <a href="<?= BASE_URL ?>/modules/Administration/academic_calendar.php" class="btn btn-outline-primary fw-bold shadow-xs px-3">
                        <i class="fa-solid fa-gear me-1"></i>
                        <span><?= is_rtl() ? 'إدارة التقويم' : 'Manage Calendar' ?></span>
                    </a>
                <?php endif; ?>
                <button type="button" class="btn btn-outline-success fw-bold shadow-xs px-3" onclick="openCalendarPrintPreview()">
                    <i class="fa-solid fa-print me-1"></i>
                    <span><?= is_rtl() ? 'طباعة التقويم' : 'Print Calendar' ?></span>
                </button>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- Main Column: Calendar Grid -->
            <div class="col-xl-9 col-lg-8">
                
                <div class="card border border-border shadow-xs overflow-hidden mb-4">
                    <div class="card-header bg-card border-bottom d-flex align-items-center justify-content-between py-3">
                        <h2 class="h5 fw-bold mb-0 text-foreground d-flex align-items-center gap-2">
                            <i class="fa-regular fa-calendar-days text-primary"></i>
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

                    <!-- 7-Day Week Calendar Grid -->
                    <div class="cal-grid">
                        <?php 
                        $weekDayNames = is_rtl() 
                            ? ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
                            : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                        foreach ($weekDayNames as $wName): ?>
                            <div class="cal-header-cell text-muted-foreground"><?= $wName ?></div>
                        <?php endforeach; ?>

                        <!-- Empty Leading Cells -->
                        <?php for ($i = 0; $i < $startingDayOfWeek; $i++): ?>
                            <div class="cal-day-cell is-empty"></div>
                        <?php endfor; ?>

                        <!-- Month Days Cells -->
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

                                <!-- Day Events -->
                                <div class="d-flex flex-column gap-1">
                                    <?php foreach ($dayEvents as $evt): 
                                        $evtColor = !empty($evt['color_code']) ? $evt['color_code'] : '#0ea5e9';
                                    ?>
                                        <div class="cal-event-pill" 
                                             style="background-color: <?= htmlspecialchars($evt['config']['bg_opacity'] ?? 'rgba(14,165,233,0.12)') ?>; color: <?= htmlspecialchars($evtColor) ?>; border-inline-start: 3px solid <?= htmlspecialchars($evtColor) ?>;"
                                             onclick='showEventDetail(<?= json_encode($evt, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                             title="<?= htmlspecialchars($evt['title']) ?>">
                                            <i class="<?= htmlspecialchars($evt['config']['icon']) ?> me-1" style="font-size: 0.68rem;"></i>
                                            <?= htmlspecialchars($evt['title']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endfor; ?>

                        <!-- Empty Trailing Cells -->
                        <?php 
                        $totalCells = $startingDayOfWeek + $daysInMonth;
                        $trailingEmpty = (7 - ($totalCells % 7)) % 7;
                        for ($j = 0; $j < $trailingEmpty; $j++): ?>
                            <div class="cal-day-cell is-empty"></div>
                        <?php endfor; ?>
                    </div>
                </div>

            </div>

            <!-- Sidebar Column: Milestones & Legend -->
            <div class="col-xl-3 col-lg-4">
                
                <!-- Upcoming Milestones Countdown Widget -->
                <div class="card border border-border shadow-xs mb-4">
                    <div class="card-header bg-card border-bottom py-3">
                        <h3 class="h6 fw-bold mb-0 text-foreground d-flex align-items-center justify-content-between">
                            <span><i class="fa-solid fa-hourglass-half text-primary me-2"></i><?= is_rtl() ? 'العد التنازلي للمواعيد القادمة' : 'Upcoming Milestones' ?></span>
                            <span class="badge bg-primary text-white"><?= count($milestones) ?></span>
                        </h3>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($milestones)): ?>
                            <div class="text-center py-4 text-muted-foreground small">
                                <i class="fa-regular fa-calendar-check fs-3 mb-2 d-block text-success"></i>
                                <?= is_rtl() ? 'لا توجد فعاليات قادمة قريبة.' : 'No upcoming events registered.' ?>
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

                <!-- Event Legend Card -->
                <div class="card border border-border shadow-xs mb-4">
                    <div class="card-header bg-card border-bottom py-3">
                        <h4 class="h6 fw-bold mb-0 text-foreground">
                            <i class="fa-solid fa-shapes text-primary me-2"></i>
                            <span><?= is_rtl() ? 'دليل الألوان والرموز' : 'Category Reference' ?></span>
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

    <!-- Event Inspection Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="detailTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 d-flex align-items-center gap-2">
                        <span id="detailBadge" class="badge"></span>
                        <span id="detailTerm" class="badge bg-secondary"></span>
                        <span id="detailClosed" class="badge bg-danger" style="display: none;"><i class="fa-solid fa-lock me-1"></i><?= is_rtl() ? 'المؤسسة مغلقة' : 'School Closed' ?></span>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted-foreground small"><?= is_rtl() ? 'الفترة الزمنية' : 'Date Schedule' ?></label>
                        <div class="fw-semibold text-foreground" id="detailDates"></div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-bold text-muted-foreground small"><?= is_rtl() ? 'التفاصيل والوصف' : 'Event Details' ?></label>
                        <div class="p-3 bg-secondary rounded-lg" id="detailDescription" style="white-space: pre-line;"></div>
                    </div>
                </div>
                <div class="modal-footer bg-secondary">
                    <button type="button" class="btn btn-outline-border px-4" data-bs-dismiss="modal"><?= is_rtl() ? 'إغلاق' : 'Close' ?></button>
                </div>
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
                        <span><?= is_rtl() ? 'معاينة طباعة التقويم المدرسي وتخصيص المخرجات' : 'School Academic Calendar Print Preview & Configuration' ?></span>
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

        function showEventDetail(evt) {
            document.getElementById('detailTitle').textContent = evt.title;
            
            var badge = document.getElementById('detailBadge');
            badge.className = evt.config ? evt.config.badge : 'badge bg-primary';
            badge.textContent = evt.config ? evt.config.label : evt.event_type;

            document.getElementById('detailTerm').textContent = evt.term || 'Term 1';

            var closedBadge = document.getElementById('detailClosed');
            if (evt.is_closed == 1) {
                closedBadge.style.display = 'inline-block';
            } else {
                closedBadge.style.display = 'none';
            }

            var datesText = evt.start_date;
            if (evt.end_date && evt.end_date !== evt.start_date) {
                datesText += ' <?= is_rtl() ? "إلى" : "to" ?> ' + evt.end_date;
            }
            document.getElementById('detailDates').textContent = datesText;

            var descElem = document.getElementById('detailDescription');
            descElem.textContent = evt.description ? evt.description : '<?= is_rtl() ? "لا توجد تفاصيل إضافية مسجلة لهذه الفعالية." : "No additional description recorded." ?>';

            var modal = new bootstrap.Modal(document.getElementById('detailModal'));
            modal.show();
        }
    </script>
</body>
</html>
