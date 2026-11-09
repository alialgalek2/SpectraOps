<?php
/**
 * modules/Administration/print_academic_calendar.php
 * Dedicated Standalone & Embedded Printable Academic Calendar Matrix Engine
 * 
 * Features:
 * 1. Dual Print Architectures:
 *    - Monthly Matrix Grid (7-Day Column Landscape View with Date Numbering & Colored Milestone Badges)
 *    - Full Academic Year Milestone Ledger (Comprehensive Term & Event Schedule Table)
 * 2. 1-Page Landscape & Strict @media print rules with Zero-Bleed Height Constraints.
 * 3. Embedded Iframe Optimization mode (embedded=1) for seamless in-page Pre-Print Intermediary Modals.
 * 4. Institutional Certification Block (Principal & Academic Board Sign-offs, School Stamp Area).
 * 5. Full Bilingual Support (English & Arabic RTL/LTR).
 */

require_once __DIR__ . '/../../core/init.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

$active_year = $_GET['year'] ?? AcademicCalendar::getActiveAcademicYear();
$format      = $_GET['format'] ?? 'month'; // 'month' or 'year'
$active_type = $_GET['type'] ?? '';
$active_term = $_GET['term'] ?? '';
$is_embedded = !empty($_GET['embedded']);

// Month & Year selection for Monthly Grid
$current_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$current_year  = isset($_GET['cal_year']) ? (int)$_GET['cal_year'] : (int)date('Y');

if ($current_month < 1) {
    $current_month = 12;
    $current_year--;
} elseif ($current_month > 12) {
    $current_month = 1;
    $current_year++;
}

// Fetch calendar data
$month_data = AcademicCalendar::getEventsForMonth($current_year, $current_month, $active_year);
$all_events = AcademicCalendar::getAllEvents($active_year, $active_type, $active_term);

// Filter all_events if user role is student or parent (exclude admin-only items if restricted)
$user_role = $_SESSION['role'] ?? 'admin';
if ($user_role !== 'admin' && $user_role !== 'school_admin') {
    $all_events = array_filter($all_events, function($e) use ($user_role) {
        $aud = $e['target_audience'] ?? 'all';
        return $aud === 'all' || stripos($aud, $user_role) !== false;
    });
}

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

// School Branding
$school_name = $global_branding['school_name'] ?? 'EduMaster International Academy';
$school_logo = $global_branding['logo_url'] ?? '';
try {
    $sn = $pdo->query("SELECT setting_value FROM school_settings WHERE setting_key = 'school_name'")->fetchColumn();
    if (!empty($sn)) $school_name = $sn;
} catch (Exception $e) {}

$doc_title = ($format === 'year')
    ? (is_rtl() ? "الخطة الزمنية والتقويم الأكاديمي السنوي" : "Annual Academic Calendar & Milestone Ledger")
    : (is_rtl() ? "التقويم الأكاديمي الشهري - $displayMonthName" : "Monthly Academic Calendar - $displayMonthName");
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($doc_title) ?> - <?= htmlspecialchars($school_name) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --cal-border: #cbd5e1;
            --cal-dark: #0f172a;
            --cal-primary: #0284c7;
            --cal-bg-light: #f8fafc;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f1f5f9;
            color: #0f172a;
            margin: 0;
            padding: 12px;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        .calendar-board {
            background: #ffffff;
            border: 2px solid #cbd5e1;
            border-radius: 12px;
            padding: 14px 18px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            max-width: 1400px;
            margin: 0 auto;
            min-height: calc(100vh - 24px);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-sizing: border-box;
        }

        /* Header Architecture */
        .board-header {
            border-bottom: 2px solid #0f172a;
            padding-bottom: 8px;
            margin-bottom: 10px;
        }
        .school-name {
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.02em;
            line-height: 1.15;
        }
        .main-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: #0369a1;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .sub-title {
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
        }

        /* 7-Column Monthly Matrix */
        .cal-grid-matrix {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            border-top: 1.5px solid var(--cal-border);
            border-inline-start: 1.5px solid var(--cal-border);
            border-radius: 6px;
            overflow: hidden;
            flex-grow: 1;
        }
        .day-col-header {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.78rem;
            padding: 6px 2px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border-inline-end: 1.5px solid var(--cal-border);
            border-bottom: 1.5px solid var(--cal-border);
        }
        .cal-day-cell {
            min-height: 78px;
            padding: 4px 6px;
            border-inline-end: 1.5px solid var(--cal-border);
            border-bottom: 1.5px solid var(--cal-border);
            background-color: #ffffff;
            display: flex;
            flex-direction: column;
            position: relative;
            box-sizing: border-box;
        }
        .cal-day-cell.is-empty {
            background-color: #f8fafc;
            opacity: 0.5;
        }
        .cal-day-cell.is-closed {
            background-color: #fef2f2;
        }
        .cal-day-cell.is-today {
            background-color: #f0f9ff;
            box-shadow: inset 0 0 0 1.5px #0284c7;
        }
        .cal-cell-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 3px;
        }
        .cal-day-num {
            font-size: 0.82rem;
            font-weight: 800;
            color: #1e293b;
            display: inline-flex;
            width: 20px;
            height: 20px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
        }
        .cal-day-cell.is-today .cal-day-num {
            background-color: #0284c7;
            color: #ffffff;
        }
        .closed-stamp {
            font-size: 0.62rem;
            font-weight: 700;
            color: #dc2626;
            background: #fee2e2;
            padding: 1px 4px;
            border-radius: 3px;
            text-transform: uppercase;
        }

        .event-badge-card {
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 4px;
            margin-bottom: 2px;
            border-radius: 3px;
            line-height: 1.15;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            display: flex;
            align-items: center;
            gap: 3px;
            border-inline-start: 3px solid transparent;
        }

        /* Yearly Ledger Table Architecture */
        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.8rem;
            margin-bottom: 6px;
        }
        .ledger-table th {
            background-color: #0f172a;
            color: #ffffff;
            font-weight: 700;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 6px 8px;
            border: 1px solid #334155;
            text-align: inherit;
        }
        .ledger-table td {
            padding: 5px 8px;
            border: 1px solid #cbd5e1;
            vertical-align: middle;
        }
        .ledger-table tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        /* Footer Certification Block */
        .board-footer {
            border-top: 1.5px solid #cbd5e1;
            padding-top: 6px;
            margin-top: 8px;
            font-size: 0.72rem;
            color: #64748b;
        }
        .signature-box {
            border: 1px dashed #94a3b8;
            border-radius: 6px;
            padding: 6px 12px;
            text-align: center;
            background: #f8fafc;
            min-width: 150px;
        }
        .signature-title {
            font-weight: 700;
            font-size: 0.72rem;
            color: #334155;
            margin-bottom: 18px;
        }
        .signature-line {
            border-top: 1px solid #64748b;
            font-size: 0.65rem;
            color: #64748b;
            padding-top: 2px;
        }

        /* Quick Action Bar (Top of standalone page) */
        .quick-action-bar {
            max-width: 1400px;
            margin: 0 auto 10px auto;
        }

        /* Embedded Iframe Adjustments */
        body.is-embedded {
            padding: 4px !important;
            background: transparent !important;
        }
        body.is-embedded .quick-action-bar {
            display: none !important;
        }
        body.is-embedded .calendar-board {
            border: 1px solid #e2e8f0;
            padding: 8px 10px;
            min-height: auto;
            border-radius: 6px;
            box-shadow: none;
        }
        body.is-embedded .cal-day-cell {
            min-height: 58px;
            padding: 2px 4px;
        }
        body.is-embedded .event-badge-card {
            font-size: 0.64rem;
            padding: 1px 3px;
            margin-bottom: 1px;
        }
        body.is-embedded .board-footer {
            margin-top: 4px;
            padding-top: 4px;
        }

        /* Strict Single-Page Landscape Print Architecture */
        @media print {
            @page {
                size: landscape;
                margin: 4mm 4mm 4mm 4mm;
            }
            html, body {
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                overflow: hidden !important;
                font-size: 8pt !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .no-print {
                display: none !important;
            }
            .calendar-board {
                width: 100% !important;
                height: 100vh !important;
                max-height: 100vh !important;
                margin: 0 !important;
                padding: 4px 8px !important;
                border: 1.5px solid #0f172a !important;
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
                margin-bottom: 4px !important;
                padding-bottom: 3px !important;
                border-bottom: 1.5px solid #0f172a !important;
            }
            .school-name {
                font-size: 10pt !important;
                line-height: 1.1 !important;
            }
            .main-title {
                font-size: 10.5pt !important;
                line-height: 1.1 !important;
            }
            .sub-title {
                font-size: 8pt !important;
                line-height: 1.1 !important;
            }
            .cal-day-cell {
                min-height: 48px !important;
                padding: 2px 3px !important;
            }
            .event-badge-card {
                font-size: 6.5pt !important;
                padding: 1px 2px !important;
                margin-bottom: 1px !important;
                line-height: 1.1 !important;
            }
            .day-col-header {
                padding: 3px 2px !important;
                font-size: 7.5pt !important;
            }
            .ledger-table th, .ledger-table td {
                padding: 3px 5px !important;
                font-size: 7.5pt !important;
            }
            .board-footer {
                margin-top: 3px !important;
                padding-top: 3px !important;
                font-size: 6.8pt !important;
            }
            .signature-box {
                padding: 3px 8px !important;
                min-width: 120px !important;
            }
            .signature-title {
                margin-bottom: 10px !important;
                font-size: 6.8pt !important;
            }
        }
    </style>
</head>
<body class="<?= $is_embedded ? 'is-embedded' : '' ?>">

    <!-- Interactive Top Controls (Non-Print Standalone View) -->
    <div class="quick-action-bar no-print d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="academic_calendar.php" class="btn btn-sm btn-outline-secondary fw-semibold">
                <i class="fa-solid fa-arrow-left me-1"></i> <?= is_rtl() ? 'العودة لإدارة التقويم' : 'Back to Calendar Desk' ?>
            </a>
            <span class="badge bg-dark text-white px-2.5 py-1.5">
                <i class="fa-solid fa-calendar me-1 text-info"></i> <?= htmlspecialchars($active_year) ?>
            </span>
            <span class="badge bg-secondary text-dark border px-2.5 py-1.5">
                <?= $format === 'year' ? (is_rtl() ? 'الخطة السنوية' : 'Annual Ledger') : (is_rtl() ? 'الشبكة الشهرية' : 'Monthly Matrix') ?>
            </span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="btn-group btn-group-sm">
                <a href="?year=<?= urlencode($active_year) ?>&month=<?= $current_month ?>&cal_year=<?= $current_year ?>&format=month" class="btn btn-outline-primary <?= $format === 'month' ? 'active' : '' ?>">
                    <i class="fa-solid fa-calendar-days me-1"></i> <?= is_rtl() ? 'شهري' : 'Monthly Grid' ?>
                </a>
                <a href="?year=<?= urlencode($active_year) ?>&format=year" class="btn btn-outline-primary <?= $format === 'year' ? 'active' : '' ?>">
                    <i class="fa-solid fa-list-check me-1"></i> <?= is_rtl() ? 'سنوي كامل' : 'Annual Ledger' ?>
                </a>
            </div>
            <button type="button" class="btn btn-sm btn-primary fw-bold px-3 shadow-xs" onclick="window.print()">
                <i class="fa-solid fa-print me-1"></i> <?= is_rtl() ? 'طباعة الوثيقة / PDF' : 'Print Document / PDF' ?>
            </button>
        </div>
    </div>

    <!-- Official Printable Matrix / Ledger Board -->
    <div class="calendar-board">
        
        <!-- Board Header -->
        <div class="board-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="d-flex align-items-center gap-2">
                <div class="p-1.5 bg-dark text-white rounded-2 d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                    <i class="fa-solid fa-graduation-cap fs-5 text-warning"></i>
                </div>
                <div>
                    <div class="school-name"><?= htmlspecialchars($school_name) ?></div>
                    <div class="sub-title">
                        <i class="fa-solid fa-building-columns text-secondary me-1"></i>
                        <?= is_rtl() ? 'إدارة الشؤون الأكاديمية والامتحانات' : 'Academic Affairs & Temporal Governance Division' ?>
                    </div>
                </div>
            </div>

            <div class="text-md-end">
                <h1 class="main-title"><?= htmlspecialchars($doc_title) ?></h1>
                <div class="sub-title">
                    <span class="me-2"><i class="fa-regular fa-calendar-check me-1 text-primary"></i><?= is_rtl() ? 'العام الدراسي:' : 'Academic Session:' ?> <strong><?= htmlspecialchars($active_year) ?></strong></span>
                    <span><i class="fa-regular fa-clock me-1 text-muted"></i><?= is_rtl() ? 'تاريخ الإصدار:' : 'Issued:' ?> <?= date('Y-m-d') ?></span>
                </div>
            </div>
        </div>

        <!-- MAIN CONTENT: FORMAT 1 = MONTHLY MATRIX GRID -->
        <?php if ($format === 'month'): ?>
            <div class="cal-grid-matrix">
                <?php 
                $weekDays = is_rtl() 
                    ? ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت']
                    : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                foreach ($weekDays as $w): ?>
                    <div class="day-col-header"><?= $w ?></div>
                <?php endforeach; ?>

                <!-- Leading Empty Cells -->
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
                        <div class="cal-cell-header">
                            <span class="cal-day-num"><?= $day ?></span>
                            <?php if ($isClosedDay): ?>
                                <span class="closed-stamp" title="<?= is_rtl() ? 'إغلاق المؤسسة' : 'School Closed' ?>">
                                    <i class="fa-solid fa-ban me-0.5"></i><?= is_rtl() ? 'إغلاق' : 'Closed' ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Milestone Event Tags -->
                        <div class="d-flex flex-column">
                            <?php foreach ($dayEvents as $evt): 
                                $cfg = $evt['config'] ?? AcademicCalendar::getEventTypeConfig($evt['event_type'] ?? '');
                                $evtColor = !empty($evt['color_code']) ? $evt['color_code'] : ($cfg['color'] ?? '#0ea5e9');
                                $bgOpacity = $cfg['bg_opacity'] ?? 'rgba(14,165,233,0.12)';
                            ?>
                                <div class="event-badge-card" 
                                     style="background-color: <?= htmlspecialchars($bgOpacity) ?>; color: <?= htmlspecialchars($evtColor) ?>; border-inline-start-color: <?= htmlspecialchars($evtColor) ?>;"
                                     title="<?= htmlspecialchars($evt['title']) ?>">
                                    <i class="<?= htmlspecialchars($cfg['icon'] ?? 'fa-solid fa-calendar') ?>" style="font-size: 0.62rem;"></i>
                                    <span><?= htmlspecialchars($evt['title']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endfor; ?>

                <!-- Trailing Empty Cells -->
                <?php 
                $totalCells = $startingDayOfWeek + $daysInMonth;
                $trailingEmpty = (7 - ($totalCells % 7)) % 7;
                for ($j = 0; $j < $trailingEmpty; $j++): ?>
                    <div class="cal-day-cell is-empty"></div>
                <?php endfor; ?>
            </div>

        <!-- MAIN CONTENT: FORMAT 2 = FULL YEAR MILESTONE LEDGER -->
        <?php else: ?>
            <div class="table-responsive flex-grow-1" style="overflow-y: auto;">
                <table class="ledger-table">
                    <thead>
                        <tr>
                            <th style="width: 14%;"><?= is_rtl() ? 'التاريخ / الفترة' : 'Date Range' ?></th>
                            <th style="width: 28%;"><?= is_rtl() ? 'الحدث الأكاديمي / الموعد' : 'Academic Event & Milestone' ?></th>
                            <th style="width: 14%;"><?= is_rtl() ? 'النوع' : 'Category' ?></th>
                            <th style="width: 10%;"><?= is_rtl() ? 'الفصل' : 'Term' ?></th>
                            <th style="width: 12%;"><?= is_rtl() ? 'الجمهور المستهدف' : 'Audience' ?></th>
                            <th style="width: 10%;"><?= is_rtl() ? 'حالة الدوام' : 'Instruction' ?></th>
                            <th style="width: 12%;"><?= is_rtl() ? 'ملاحظات' : 'Details' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($all_events)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">
                                    <i class="fa-solid fa-circle-info me-1"></i>
                                    <?= is_rtl() ? 'لا توجد فعاليات أو مواعيد مسجلة في هذا العام الأكاديمي.' : 'No academic events or milestones registered for this session.' ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($all_events as $ev): 
                                $cfg = $ev['config'] ?? AcademicCalendar::getEventTypeConfig($ev['event_type'] ?? '');
                                $isClosed = !empty($ev['is_closed']);
                                $dateDisp = $ev['start_date'];
                                if (!empty($ev['end_date']) && $ev['end_date'] !== $ev['start_date']) {
                                    $dateDisp .= ' <br><span class="text-muted" style="font-size: 0.7rem;">' . (is_rtl() ? 'إلى ' : 'to ') . $ev['end_date'] . '</span>';
                                }
                            ?>
                                <tr>
                                    <td class="fw-bold text-dark"><?= $dateDisp ?></td>
                                    <td>
                                        <div class="fw-bold text-dark"><?= htmlspecialchars($ev['title']) ?></div>
                                        <?php if (!empty($ev['description'])): ?>
                                            <div class="text-muted" style="font-size: 0.72rem;"><?= htmlspecialchars($ev['description']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="<?= htmlspecialchars($cfg['badge'] ?? 'badge bg-secondary') ?>" style="font-size: 0.7rem;">
                                            <i class="<?= htmlspecialchars($cfg['icon'] ?? 'fa-solid fa-tag') ?> me-1"></i>
                                            <?= htmlspecialchars($cfg['label'] ?? $ev['event_type']) ?>
                                        </span>
                                    </td>
                                    <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($ev['term'] ?? 'Term 1') ?></span></td>
                                    <td>
                                        <span class="text-capitalize text-muted" style="font-size: 0.75rem;">
                                            <?= htmlspecialchars($ev['target_audience'] ?? 'all') ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($isClosed): ?>
                                            <span class="badge bg-danger text-white"><i class="fa-solid fa-lock me-1"></i><?= is_rtl() ? 'مغلق' : 'Closed' ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><?= is_rtl() ? 'دوام اعتيادي' : 'Instructional' ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted" style="font-size: 0.72rem;">
                                        <?= !empty($ev['description']) ? htmlspecialchars(mb_substr($ev['description'], 0, 45)) : '-' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Footer Certification & Sign-off Block -->
        <div class="board-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <!-- Legend Indicators -->
            <div class="d-flex align-items-center flex-wrap gap-2">
                <span class="fw-bold text-dark me-1"><i class="fa-solid fa-shapes me-1 text-primary"></i><?= is_rtl() ? 'الدليل:' : 'Legend:' ?></span>
                <span class="badge bg-success" style="font-size: 0.65rem;"><?= is_rtl() ? 'افتتاح فصل' : 'Term Start' ?></span>
                <span class="badge bg-danger" style="font-size: 0.65rem;"><?= is_rtl() ? 'ختام فصل' : 'Term End' ?></span>
                <span class="badge bg-warning text-dark" style="font-size: 0.65rem;"><?= is_rtl() ? 'عطلة رسمية' : 'Holiday' ?></span>
                <span class="badge bg-danger text-white" style="font-size: 0.65rem;"><?= is_rtl() ? 'إغلاق المؤسسة' : 'Closure' ?></span>
                <span class="badge text-white" style="background-color: #8b5cf6; font-size: 0.65rem;"><?= is_rtl() ? 'امتحانات' : 'Exams' ?></span>
                <span class="badge bg-primary text-white" style="font-size: 0.65rem;"><?= is_rtl() ? 'فعالية عامة' : 'Event' ?></span>
            </div>

            <!-- Certification Signatures -->
            <div class="d-flex align-items-center gap-3">
                <div class="signature-box">
                    <div class="signature-title"><?= is_rtl() ? 'اعتماد مدير الشؤون الأكاديمية' : 'Academic Director Sign-off' ?></div>
                    <div class="signature-line"><?= is_rtl() ? 'التوقيع والختم الرسمي' : 'Signature & Official Stamp' ?></div>
                </div>
                <div class="signature-box">
                    <div class="signature-title"><?= is_rtl() ? 'اعتماد المدير العام للمؤسسة' : 'School Principal Approval' ?></div>
                    <div class="signature-line"><?= is_rtl() ? 'التاريخ والاعتماد المالي/الإداري' : 'Date & Validation Stamp' ?></div>
                </div>
            </div>
        </div>

    </div>

</body>
</html>
