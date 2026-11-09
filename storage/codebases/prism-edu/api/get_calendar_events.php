<?php
/**
 * api/get_calendar_events.php
 * Unified Dual-Feed Calendar Endpoint (Academic Events + Class Timetable Allocations)
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../core/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$user_id   = (int)$_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'student';
$feed_type = $_GET['feed_type'] ?? 'events';

$start_range = $_GET['start'] ?? date('Y-m-d', strtotime('-1 month'));
$end_range   = $_GET['end']   ?? date('Y-m-d', strtotime('+2 months'));
$events = [];

// 1. Academic Events Feed
if ($feed_type === 'events' || $feed_type === 'all') {
    try {
        $acadStmt = $pdo->prepare("SELECT title, description, event_type, start_date, end_date, color_code, is_closed 
                                   FROM academic_calendar 
                                   WHERE start_date <= ? AND end_date >= ?");
        $acadStmt->execute([$end_range, $start_range]);
        $academic_records = $acadStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($academic_records as $ar) {
            $color = !empty($ar['color_code']) ? $ar['color_code'] : '#6c757d';
            if ($ar['event_type'] === 'Holiday') $color = '#dc3545';
            elseif ($ar['event_type'] === 'Exam Period') $color = '#fd7e14';
            elseif ($ar['event_type'] === 'Term Start' || $ar['event_type'] === 'Term End') $color = '#10b981';

            // FullCalendar all-day end dates are exclusive; add 1 day if multi-day or end_date provided
            $endDate = $ar['end_date'];
            if (!empty($endDate) && $endDate >= $ar['start_date']) {
                $endDate = date('Y-m-d', strtotime($endDate . ' +1 day'));
            } else {
                $endDate = $ar['start_date'];
            }

            $events[] = [
                'id'              => 'acad_' . uniqid(),
                'title'           => '[School] ' . $ar['title'],
                'start'           => $ar['start_date'],
                'end'             => $endDate,
                'allDay'          => true,
                'backgroundColor' => $color,
                'borderColor'     => $color,
                'extendedProps'   => [
                    'category'    => 'academic',
                    'type'        => $ar['event_type'],
                    'description' => $ar['description'] ?? '',
                    'is_closed'   => (int)($ar['is_closed'] ?? 0)
                ]
            ];
        }
    } catch (Exception $e) {}
}

// 2. Timetable Allocations Feed
if ($feed_type === 'timetable' || $feed_type === 'all') {
    $allocations = [];

    try {
        if ($role === 'teacher') {
            // Check timetable_allocations first
            $stmt = $pdo->prepare("SELECT a.*, p.start_time as p_start, p.end_time as p_end, c.class_name 
                                   FROM timetable_allocations a
                                   LEFT JOIN timetable_periods p ON a.period_id = p.period_id
                                   LEFT JOIN classes c ON a.class_id = c.class_id
                                   WHERE a.teacher_id = ?");
            $stmt->execute([$user_id]);
            $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback to timetables table if allocations is empty
            if (empty($allocations)) {
                $stmt2 = $pdo->prepare("SELECT t.*, t.schedule_id as allocation_id, t.room_no as room_number, s.subject_name, c.class_name 
                                        FROM timetables t 
                                        LEFT JOIN subjects s ON t.subject_id = s.subject_id 
                                        LEFT JOIN classes c ON t.class_id = c.class_id 
                                        WHERE t.teacher_id = ?");
                $stmt2->execute([$user_id]);
                $allocations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

        } elseif ($role === 'student') {
            $stmt = $pdo->prepare("SELECT a.*, p.start_time as p_start, p.end_time as p_end, u.full_name as teacher_name 
                                   FROM timetable_allocations a
                                   LEFT JOIN timetable_periods p ON a.period_id = p.period_id
                                   JOIN students s ON a.class_id = s.class_id
                                   LEFT JOIN users u ON a.teacher_id = u.user_id
                                   WHERE s.user_id = ?");
            $stmt->execute([$user_id]);
            $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback to timetables table if empty
            if (empty($allocations)) {
                $stmt2 = $pdo->prepare("SELECT t.*, t.schedule_id as allocation_id, t.room_no as room_number, s.subject_name, u.full_name as teacher_name 
                                        FROM timetables t 
                                        JOIN students st ON t.class_id = st.class_id 
                                        LEFT JOIN subjects s ON t.subject_id = s.subject_id 
                                        LEFT JOIN users u ON t.teacher_id = u.user_id 
                                        WHERE st.user_id = ?");
                $stmt2->execute([$user_id]);
                $allocations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

        } elseif ($role === 'parent') {
            $child_id = intval($_GET['child_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT a.*, p.start_time as p_start, p.end_time as p_end, c.class_name, u.full_name as teacher_name 
                                   FROM timetable_allocations a
                                   LEFT JOIN timetable_periods p ON a.period_id = p.period_id
                                   JOIN students s ON a.class_id = s.class_id
                                   JOIN parent_student_map psm ON s.student_id = psm.student_id
                                   LEFT JOIN classes c ON a.class_id = c.class_id
                                   LEFT JOIN users u ON a.teacher_id = u.user_id
                                   WHERE (psm.parent_user_id = ? OR psm.parent_id = ?) " . ($child_id ? "AND s.student_id = ?" : ""));
            $params = $child_id ? [$user_id, $user_id, $child_id] : [$user_id, $user_id];
            $stmt->execute($params);
            $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($allocations)) {
                $stmt2 = $pdo->prepare("SELECT t.*, t.schedule_id as allocation_id, t.room_no as room_number, s.subject_name, c.class_name, u.full_name as teacher_name 
                                        FROM timetables t 
                                        JOIN students st ON t.class_id = st.class_id 
                                        JOIN parent_student_map psm ON st.student_id = psm.student_id 
                                        LEFT JOIN subjects s ON t.subject_id = s.subject_id 
                                        LEFT JOIN classes c ON t.class_id = c.class_id 
                                        LEFT JOIN users u ON t.teacher_id = u.user_id 
                                        WHERE (psm.parent_user_id = ? OR psm.parent_id = ?) " . ($child_id ? "AND st.student_id = ?" : ""));
                $stmt2->execute($params);
                $allocations = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            }

        } else {
            // Admin / School Admin / Staff Overview
            $target_class_id = intval($_GET['class_id'] ?? 0);
            $target_teacher_id = intval($_GET['teacher_id'] ?? 0);

            if ($target_class_id > 0) {
                $stmt = $pdo->prepare("SELECT a.*, p.start_time as p_start, p.end_time as p_end, c.class_name, u.full_name as teacher_name 
                                       FROM timetable_allocations a
                                       LEFT JOIN timetable_periods p ON a.period_id = p.period_id
                                       LEFT JOIN classes c ON a.class_id = c.class_id
                                       LEFT JOIN users u ON a.teacher_id = u.user_id
                                       WHERE a.class_id = ?");
                $stmt->execute([$target_class_id]);
                $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($target_teacher_id > 0) {
                $stmt = $pdo->prepare("SELECT a.*, p.start_time as p_start, p.end_time as p_end, c.class_name 
                                       FROM timetable_allocations a
                                       LEFT JOIN timetable_periods p ON a.period_id = p.period_id
                                       LEFT JOIN classes c ON a.class_id = c.class_id
                                       WHERE a.teacher_id = ?");
                $stmt->execute([$target_teacher_id]);
                $allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $allocations = $pdo->query("SELECT a.*, p.start_time as p_start, p.end_time as p_end, c.class_name, u.full_name as teacher_name 
                                            FROM timetable_allocations a
                                            LEFT JOIN timetable_periods p ON a.period_id = p.period_id
                                            LEFT JOIN classes c ON a.class_id = c.class_id
                                            LEFT JOIN users u ON a.teacher_id = u.user_id
                                            LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } catch (Exception $e) {}

    $dayMap = [
        'Sunday'    => 0,
        'Monday'    => 1,
        'Tuesday'   => 2,
        'Wednesday' => 3,
        'Thursday'  => 4,
        'Friday'    => 5,
        'Saturday'  => 6
    ];

    foreach ($allocations as $alloc) {
        $dayNum = $dayMap[$alloc['day_of_week']] ?? null;
        if ($dayNum === null) continue;

        $subjectName = !empty($alloc['subject_name']) ? $alloc['subject_name'] : 'Lecture';
        $className   = !empty($alloc['class_name']) ? $alloc['class_name'] : ($alloc['teacher_name'] ?? ('Room ' . ($alloc['room_number'] ?? $alloc['room_no'] ?? '101')));
        $label = $subjectName . ' (' . $className . ')';

        $startTime = !empty($alloc['start_time']) ? $alloc['start_time'] : ($alloc['p_start'] ?? '08:00:00');
        $endTime   = !empty($alloc['end_time']) ? $alloc['end_time'] : ($alloc['p_end'] ?? '08:45:00');
        $roomNum   = $alloc['room_number'] ?? ($alloc['room_no'] ?? 'Room 101');

        $events[] = [
            'id'              => 'alloc_' . ($alloc['allocation_id'] ?? ($alloc['schedule_id'] ?? uniqid())),
            'title'           => $label,
            'startTime'       => substr($startTime, 0, 5),
            'endTime'         => substr($endTime, 0, 5),
            'daysOfWeek'      => [$dayNum],
            'startRecur'      => $start_range,
            'endRecur'        => $end_range,
            'backgroundColor' => '#0284c7',
            'borderColor'     => '#0369a1',
            'extendedProps'   => [
                'category'    => 'timetable',
                'room'        => $roomNum,
                'class'       => $alloc['class_name'] ?? '',
                'teacher'     => $alloc['teacher_name'] ?? ''
            ]
        ];
    }
}

echo json_encode($events);
