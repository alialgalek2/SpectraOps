<?php
/**
 * Automated RFID / Biometric Attendance API Endpoint
 * Handles automated turnstile / card badge scans & parent notification triggers
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../core/init.php';

// Machine-to-machine Turnstile / Device Key Verification
$configuredSecret = getenv('RFID_DEVICE_SECRET') ?: (getenv('ATTENDANCE_API_KEY') ?: null);
$receivedSecret   = $_SERVER['HTTP_X_DEVICE_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

if (empty($configuredSecret) || empty($receivedSecret) || !hash_equals($configuredSecret, (string)$receivedSecret)) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error'   => 'Unauthorized access. Valid hardware device key (X-Device-Key or X-API-Key) required.'
    ]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$rfid_card_id = trim($input['rfid_card_id'] ?? '');
$method = $input['tracking_method'] ?? 'RFID';

if (empty($rfid_card_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing RFID / Barcode Card ID.']);
    exit();
}

try {
    // 1. Locate student by RFID card or Admission No
    $stmt = $pdo->prepare("
        SELECT s.student_id, s.class_id, s.admission_no, u.full_name, c.class_name,
               s.guardian_email, s.parent_id
        FROM students s
        JOIN users u ON s.user_id = u.user_id
        LEFT JOIN classes c ON s.class_id = c.class_id
        WHERE s.rfid_card_id = ? OR s.admission_no = ? OR s.student_id = ?
        LIMIT 1
    ");
    $stmt->execute([$rfid_card_id, $rfid_card_id, $rfid_card_id]);
    $student = $stmt->fetch();

    if (!$student) {
        echo json_encode(['success' => false, 'message' => "Unrecognized RFID/Badge: '{$rfid_card_id}'. Student not found."]);
        exit();
    }

    $today = date('Y-m-d');
    $time = date('H:i:s');
    $status = (date('H:i') > '08:15') ? 'Late' : 'Present'; // After 8:15 AM is late

    // 2. Record or update attendance
    $attStmt = $pdo->prepare("
        INSERT INTO student_attendance (student_id, class_id, date, status, check_in_time, tracking_method, recorded_by)
        VALUES (?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            check_in_time = VALUES(check_in_time),
            tracking_method = VALUES(tracking_method)
    ");
    $attStmt->execute([$student['student_id'], $student['class_id'], $today, $status, $time, $method]);

    // 3. Trigger Inter-Module Event
    EventDispatcher::dispatch('attendance.scanned', [
        'student_id' => $student['student_id'],
        'status'     => $status,
        'time'       => $time,
        'date'       => $today
    ]);

    echo json_encode([
        'success'      => true,
        'message'      => "Attendance recorded successfully for {$student['full_name']}.",
        'student_name' => $student['full_name'],
        'class_name'   => $student['class_name'] ?? 'Class',
        'status'       => $status,
        'time'         => $time,
        'date'         => $today
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
