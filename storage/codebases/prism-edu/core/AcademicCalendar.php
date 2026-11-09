<?php
declare(strict_types=1);

/**
 * Academic Calendar Engine & Master Timeline Service
 * Centralizes temporal milestones, term boundaries, holiday tracking,
 * exam windows, and cross-module synchronization across EduMaster Core.
 */

class AcademicCalendar
{
    /**
     * Supported Event Categories
     */
    public const TYPE_TERM_START     = 'Term Start';
    public const TYPE_TERM_END       = 'Term End';
    public const TYPE_HOLIDAY        = 'Holiday';
    public const TYPE_CLOSURE        = 'School Closure';
    public const TYPE_EXAM_PERIOD    = 'Exam Period';
    public const TYPE_GENERAL_EVENT  = 'General Event';

    /**
     * Get default event type presentation attributes
     */
    public static function getEventTypeConfig(string $type): array
    {
        $configs = [
            self::TYPE_TERM_START => [
                'label'      => is_rtl() ? 'بداية الفصل الدراسي' : 'Term Start',
                'badge'      => 'badge bg-success',
                'color'      => '#10b981',
                'icon'       => 'fa-solid fa-door-open',
                'bg_opacity' => 'rgba(16, 185, 129, 0.12)'
            ],
            self::TYPE_TERM_END => [
                'label'      => is_rtl() ? 'نهاية الفصل الدراسي' : 'Term End',
                'badge'      => 'badge bg-danger',
                'color'      => '#ef4444',
                'icon'       => 'fa-solid fa-flag-checkered',
                'bg_opacity' => 'rgba(239, 68, 68, 0.12)'
            ],
            self::TYPE_HOLIDAY => [
                'label'      => is_rtl() ? 'عطلة رسمية' : 'Public Holiday',
                'badge'      => 'badge bg-warning text-dark',
                'color'      => '#f59e0b',
                'icon'       => 'fa-solid fa-umbrella-beach',
                'bg_opacity' => 'rgba(245, 158, 11, 0.12)'
            ],
            self::TYPE_CLOSURE => [
                'label'      => is_rtl() ? 'إغلاق المؤسسة' : 'School Closure',
                'badge'      => 'badge bg-danger text-white',
                'color'      => '#dc2626',
                'icon'       => 'fa-solid fa-ban',
                'bg_opacity' => 'rgba(220, 38, 38, 0.15)'
            ],
            self::TYPE_EXAM_PERIOD => [
                'label'      => is_rtl() ? 'فترة الامتحانات' : 'Exam Period',
                'badge'      => 'badge bg-purple text-white',
                'color'      => '#8b5cf6',
                'icon'       => 'fa-solid fa-file-pen',
                'bg_opacity' => 'rgba(139, 92, 246, 0.12)'
            ],
            self::TYPE_GENERAL_EVENT => [
                'label'      => is_rtl() ? 'فعالية مدرسية' : 'General Event',
                'badge'      => 'badge bg-primary text-white',
                'color'      => '#0ea5e9',
                'icon'       => 'fa-solid fa-calendar-check',
                'bg_opacity' => 'rgba(14, 165, 233, 0.12)'
            ],
        ];

        return $configs[$type] ?? [
            'label'      => $type,
            'badge'      => 'badge bg-secondary',
            'color'      => '#64748b',
            'icon'       => 'fa-solid fa-calendar',
            'bg_opacity' => 'rgba(100, 116, 139, 0.12)'
        ];
    }

    /**
     * Retrieve the active academic year
     */
    public static function getActiveAcademicYear(): string
    {
        global $global_branding, $pdo;
        if (!empty($global_branding['academic_year'])) {
            return $global_branding['academic_year'];
        }
        if ($pdo) {
            try {
                $stmt = $pdo->query("SELECT setting_value FROM school_settings WHERE setting_key IN ('academic_year', 'current_session') ORDER BY setting_id ASC LIMIT 1");
                $val = $stmt->fetchColumn();
                if (!empty($val)) {
                    return $val;
                }
            } catch (\Exception $e) {}
        }
        return '2025/2026';
    }

    /**
     * Check if a specific date is an instructional school day (not holiday or closure)
     */
    public static function isSchoolDay(string $date, ?string $academic_year = null): bool
    {
        $holiday = self::isHoliday($date, $academic_year);
        if ($holiday !== null) {
            return false;
        }

        // Optional weekend check (Friday & Saturday for RTL/Sudan regional mode, or Saturday & Sunday)
        $dayOfWeek = (int)date('w', strtotime($date)); // 0 = Sunday, 5 = Friday, 6 = Saturday
        if ($dayOfWeek === 5 || $dayOfWeek === 6) { // Friday & Saturday default weekend
            return false;
        }

        return true;
    }

    /**
     * Check if date falls within a registered Holiday or School Closure
     */
    public static function isHoliday(string $date, ?string $academic_year = null): ?array
    {
        global $pdo;
        if (!$pdo) return null;

        $year = $academic_year ?: self::getActiveAcademicYear();

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM academic_calendar 
                WHERE academic_year = ? 
                  AND (is_closed = 1 OR event_type IN ('Holiday', 'School Closure'))
                  AND ? BETWEEN start_date AND end_date
                ORDER BY is_closed DESC, start_date ASC 
                LIMIT 1
            ");
            $stmt->execute([$year, $date]);
            $res = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $res ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Check if date falls within an active Examination Period window
     */
    public static function isExamPeriod(string $date, ?string $academic_year = null): ?array
    {
        global $pdo;
        if (!$pdo) return null;

        $year = $academic_year ?: self::getActiveAcademicYear();

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM academic_calendar 
                WHERE academic_year = ? 
                  AND event_type = 'Exam Period'
                  AND ? BETWEEN start_date AND end_date
                LIMIT 1
            ");
            $stmt->execute([$year, $date]);
            $res = $stmt->fetch(\PDO::FETCH_ASSOC);
            return $res ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Identify the active term for a given date
     */
    public static function getActiveTerm(?string $date = null, ?string $academic_year = null): ?array
    {
        global $pdo;
        if (!$pdo) return null;

        $checkDate = $date ?: date('Y-m-d');
        $year = $academic_year ?: self::getActiveAcademicYear();

        try {
            // Check if there is an event covering this period
            $stmt = $pdo->prepare("
                SELECT * FROM academic_calendar 
                WHERE academic_year = ? 
                  AND ? BETWEEN start_date AND end_date
                  AND event_type IN ('Term Start', 'Term End', 'General Event')
                ORDER BY start_date ASC
                LIMIT 1
            ");
            $stmt->execute([$year, $checkDate]);
            $termEvent = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($termEvent && !empty($termEvent['term'])) {
                return $termEvent;
            }

            // Fallback: Return latest term start event before or on checkDate
            $stmt2 = $pdo->prepare("
                SELECT * FROM academic_calendar 
                WHERE academic_year = ? AND start_date <= ? AND event_type = 'Term Start'
                ORDER BY start_date DESC LIMIT 1
            ");
            $stmt2->execute([$year, $checkDate]);
            $res = $stmt2->fetch(\PDO::FETCH_ASSOC);
            return $res ?: ['term' => 'Term 1', 'academic_year' => $year];
        } catch (\Exception $e) {
            return ['term' => 'Term 1', 'academic_year' => $year];
        }
    }

    /**
     * Fetch upcoming milestones with real-time countdown calculations
     */
    public static function getUpcomingMilestones(int $limit = 5, ?string $academic_year = null, ?string $role = 'all'): array
    {
        global $pdo;
        if (!$pdo) return [];

        $today = date('Y-m-d');
        $year = $academic_year ?: self::getActiveAcademicYear();

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM academic_calendar 
                WHERE academic_year = ? 
                  AND (end_date >= ? OR start_date >= ?)
                  AND (target_audience = 'all' OR target_audience LIKE ? OR ? = 'admin' OR ? = 'school_admin')
                ORDER BY start_date ASC, end_date ASC
                LIMIT ?
            ");
            $stmt->bindValue(1, $year, \PDO::PARAM_STR);
            $stmt->bindValue(2, $today, \PDO::PARAM_STR);
            $stmt->bindValue(3, $today, \PDO::PARAM_STR);
            $stmt->bindValue(4, '%' . ($role ?: 'all') . '%', \PDO::PARAM_STR);
            $stmt->bindValue(5, $role ?: 'all', \PDO::PARAM_STR);
            $stmt->bindValue(6, $role ?: 'all', \PDO::PARAM_STR);
            $stmt->bindValue(7, $limit, \PDO::PARAM_INT);
            $stmt->execute();
            
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $todayTs = strtotime($today);
            $results = [];

            foreach ($events as $evt) {
                $startTs = strtotime($evt['start_date']);
                $endTs   = strtotime($evt['end_date']);

                $diffDays = (int)round(($startTs - $todayTs) / 86400);

                if ($today >= $evt['start_date'] && $today <= $evt['end_date']) {
                    $status_text = is_rtl() ? 'جارٍ اليوم' : 'Active Today';
                    $countdown_badge = 'badge bg-success';
                    $countdown_label = is_rtl() ? 'مباشر الآن' : 'Live Now';
                    $is_active_now = true;
                } elseif ($diffDays === 1) {
                    $status_text = is_rtl() ? 'يبدأ غداً' : 'Starts Tomorrow';
                    $countdown_badge = 'badge bg-warning text-dark';
                    $countdown_label = is_rtl() ? 'غداً' : 'Tomorrow';
                    $is_active_now = false;
                } elseif ($diffDays > 1) {
                    $status_text = is_rtl() ? "متبقٍ $diffDays يوم" : "$diffDays days remaining";
                    $countdown_badge = 'badge bg-primary text-white';
                    $countdown_label = is_rtl() ? "خلال $diffDays يوم" : "In $diffDays days";
                    $is_active_now = false;
                } else {
                    $status_text = is_rtl() ? 'حدث منتهي' : 'Concluding';
                    $countdown_badge = 'badge bg-secondary';
                    $countdown_label = is_rtl() ? 'قيد الإغلاق' : 'Closing';
                    $is_active_now = false;
                }

                $evt['config']          = self::getEventTypeConfig($evt['event_type']);
                $evt['days_remaining']  = $diffDays;
                $evt['status_text']     = $status_text;
                $evt['countdown_badge'] = $countdown_badge;
                $evt['countdown_label'] = $countdown_label;
                $evt['is_active_now']   = $is_active_now;

                $results[] = $evt;
            }

            return $results;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Fetch all calendar events structured for a given month
     */
    public static function getEventsForMonth(int $year, int $month, ?string $academic_year = null): array
    {
        global $pdo;
        if (!$pdo) return [];

        $monthPadded = str_pad((string)$month, 2, '0', STR_PAD_LEFT);
        $firstDay = "$year-$monthPadded-01";
        $lastDay  = date('Y-m-t', strtotime($firstDay));

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM academic_calendar 
                WHERE (start_date <= ? AND end_date >= ?)
                ORDER BY start_date ASC, is_closed DESC
            ");
            $stmt->execute([$lastDay, $firstDay]);
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Group by specific calendar dates (YYYY-MM-DD)
            $grouped = [];
            foreach ($events as $evt) {
                $evt['config'] = self::getEventTypeConfig($evt['event_type']);
                
                $cur = strtotime(max($evt['start_date'], $firstDay));
                $end = strtotime(min($evt['end_date'], $lastDay));

                while ($cur <= $end) {
                    $dStr = date('Y-m-d', $cur);
                    if (!isset($grouped[$dStr])) {
                        $grouped[$dStr] = [];
                    }
                    $grouped[$dStr][] = $evt;
                    $cur = strtotime('+1 day', $cur);
                }
            }

            return [
                'raw_events' => $events,
                'by_date'    => $grouped,
                'year'       => $year,
                'month'      => $month,
                'first_day'  => $firstDay,
                'last_day'   => $lastDay
            ];
        } catch (\Exception $e) {
            return ['raw_events' => [], 'by_date' => [], 'year' => $year, 'month' => $month];
        }
    }

    /**
     * Fetch events within an arbitrary range [start_date, end_date]
     */
    public static function getEventsForRange(string $start_date, string $end_date, ?string $academic_year = null): array
    {
        global $pdo;
        if (!$pdo) return [];

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM academic_calendar 
                WHERE (start_date <= ? AND end_date >= ?)
                ORDER BY start_date ASC
            ");
            $stmt->execute([$end_date, $start_date]);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['config'] = self::getEventTypeConfig($r['event_type']);
            }
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Retrieve all events with optional filters (for management table view)
     */
    public static function getAllEvents(?string $academic_year = null, ?string $event_type = null, ?string $term = null): array
    {
        global $pdo;
        if (!$pdo) return [];

        $conditions = [];
        $params = [];

        if (!empty($academic_year)) {
            $conditions[] = "academic_year = ?";
            $params[] = $academic_year;
        }
        if (!empty($event_type)) {
            $conditions[] = "event_type = ?";
            $params[] = $event_type;
        }
        if (!empty($term)) {
            $conditions[] = "term = ?";
            $params[] = $term;
        }

        $where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

        try {
            $stmt = $pdo->prepare("SELECT * FROM academic_calendar $where ORDER BY start_date DESC, event_id DESC");
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['config'] = self::getEventTypeConfig($r['event_type']);
            }
            return $rows;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Save (Insert or Update) an academic calendar event
     */
    public static function saveEvent(array $data, ?int $event_id = null): int
    {
        global $pdo;
        if (!$pdo) {
            throw new \RuntimeException("Database connection is not initialized.");
        }

        $title           = trim($data['title'] ?? '');
        $description     = trim($data['description'] ?? '');
        $event_type      = $data['event_type'] ?? self::TYPE_GENERAL_EVENT;
        $academic_year   = $data['academic_year'] ?? self::getActiveAcademicYear();
        $term            = $data['term'] ?? 'Term 1';
        $start_date      = $data['start_date'] ?? date('Y-m-d');
        $end_date        = !empty($data['end_date']) ? $data['end_date'] : $start_date;
        $is_closed       = !empty($data['is_closed']) ? 1 : 0;
        $color_code      = $data['color_code'] ?? self::getEventTypeConfig($event_type)['color'];
        $target_audience = $data['target_audience'] ?? 'all';
        $created_by      = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

        if (empty($title)) {
            throw new \InvalidArgumentException("Event title is required.");
        }
        if (strtotime($end_date) < strtotime($start_date)) {
            $end_date = $start_date;
        }

        if ($event_id && $event_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE academic_calendar 
                SET title = ?, description = ?, event_type = ?, academic_year = ?, term = ?, 
                    start_date = ?, end_date = ?, is_closed = ?, color_code = ?, target_audience = ?
                WHERE event_id = ?
            ");
            $stmt->execute([
                $title, $description, $event_type, $academic_year, $term,
                $start_date, $end_date, $is_closed, $color_code, $target_audience,
                $event_id
            ]);
            return $event_id;
        }

        $stmt = $pdo->prepare("
            INSERT INTO academic_calendar 
            (title, description, event_type, academic_year, term, start_date, end_date, is_closed, color_code, target_audience, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $title, $description, $event_type, $academic_year, $term,
            $start_date, $end_date, $is_closed, $color_code, $target_audience, $created_by
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Delete an academic calendar event
     */
    public static function deleteEvent(int $event_id): bool
    {
        global $pdo;
        if (!$pdo || $event_id <= 0) return false;

        try {
            $stmt = $pdo->prepare("DELETE FROM academic_calendar WHERE event_id = ?");
            return $stmt->execute([$event_id]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
