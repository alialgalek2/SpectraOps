<?php
/**
 * modules/LMS/save_schedule_slot.php
 * Backend Handler for Scheduling Slots & Ad-hoc Activities/Breaks
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allocation_id   = intval($_POST['allocation_id'] ?? 0);
    $day_of_week     = trim($_POST['day_of_week'] ?? 'Monday');
    $period_id       = intval($_POST['period_id'] ?? 0);
    $class_id        = intval($_POST['class_id'] ?? 0);
    $allocation_type = $_POST['allocation_type'] ?? 'class'; // 'class', 'activity', 'break'
    if (!in_array($allocation_type, ['class', 'activity', 'break'])) {
        $allocation_type = 'class';
    }
    $subject_name    = trim($_POST['subject_name'] ?? '');
    $teacher_id      = !empty($_POST['teacher_id']) ? intval($_POST['teacher_id']) : null;
    $room_number     = trim($_POST['room_number'] ?? '');

    if (empty($subject_name) && $allocation_type === 'break') {
        $subject_name = 'Class Recess / Break';
    } elseif (empty($subject_name)) {
        echo json_encode(['status' => 'error', 'message' => 'Subject title or activity label is required.']);
        exit;
    }

    if ($class_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Class ID is required.']);
        exit;
    }

    // Fetch period times if available as default baseline
    $start_time = null;
    $end_time = null;
    if ($period_id > 0) {
        try {
            $pStmt = $pdo->prepare("SELECT start_time, end_time FROM timetable_periods WHERE period_id = ?");
            $pStmt->execute([$period_id]);
            $pRow = $pStmt->fetch();
            if ($pRow) {
                $start_time = $pRow['start_time'];
                $end_time = $pRow['end_time'];
            }
        } catch (Exception $e) {}
    }

    // Override with custom user-adjusted start and end times if provided
    $custom_start = !empty($_POST['start_time']) ? trim($_POST['start_time']) : null;
    $custom_end   = !empty($_POST['end_time'])   ? trim($_POST['end_time'])   : null;

    if (!empty($custom_start)) {
        if (strlen($custom_start) === 5) $custom_start .= ':00';
        $start_time = $custom_start;
    }
    if (!empty($custom_end)) {
        if (strlen($custom_end) === 5) $custom_end .= ':00';
        $end_time = $custom_end;
    }

    $db_period_id = ($period_id > 0) ? $period_id : null;

    // Conflict validation (only check if it is a standard academic class with assigned teacher)
    if ($allocation_type === 'class' && $teacher_id && $db_period_id) {
        try {
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM timetable_allocations 
                WHERE day_of_week = ? AND period_id = ? AND teacher_id = ? AND allocation_id != ?
            ");
            $checkStmt->execute([$day_of_week, $db_period_id, $teacher_id, $allocation_id]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['status' => 'error', 'message' => 'Teacher is already assigned to another class during this period.']);
                exit;
            }
        } catch (Exception $e) {}
    }

    try {
        if ($allocation_id > 0) {
            // Update existing slot
            $stmt = $pdo->prepare("
                UPDATE timetable_allocations SET 
                    day_of_week = ?, 
                    period_id = ?, 
                    class_id = ?, 
                    subject_name = ?, 
                    allocation_type = ?, 
                    teacher_id = ?, 
                    room_number = ?,
                    start_time = COALESCE(?, start_time),
                    end_time = COALESCE(?, end_time)
                WHERE allocation_id = ?
            ");
            $stmt->execute([$day_of_week, $db_period_id, $class_id, $subject_name, $allocation_type, $teacher_id, $room_number, $start_time, $end_time, $allocation_id]);
        } else {
            // Insert new slot
            $stmt = $pdo->prepare("
                INSERT INTO timetable_allocations 
                (day_of_week, period_id, class_id, subject_name, allocation_type, teacher_id, room_number, start_time, end_time) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$day_of_week, $db_period_id, $class_id, $subject_name, $allocation_type, $teacher_id, $room_number, $start_time, $end_time]);
            $allocation_id = (int)$pdo->lastInsertId();
        }

        echo json_encode([
            'status' => 'success', 
            'allocation_id' => $allocation_id,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'message' => 'Schedule slot and customized time saved successfully.'
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
