<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap/security.php';
require_once __DIR__ . '/../../config/Database.php';

use App\Config\Database;

header('Content-Type: application/json');

// Offline sync is a machine endpoint, not a browser endpoint.  Do not accept
// an arbitrary header as proof of identity: validate the configured key.
$configuredKey = getenv('SYNC_API_KEY') ?: getenv('ATTENDANCE_API_KEY');
$providedKey = $_SERVER['HTTP_X_SYNC_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
if (empty($configuredKey) || empty($providedKey) || !hash_equals($configuredKey, (string) $providedKey)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'A valid sync device key is required.']);
    exit();
}

$pdo = Database::getConnection();
$rawInput = file_get_contents('php://input');
$input = !empty($rawInput) ? json_decode($rawInput, true) : [];

if (!is_array($input)) {
    $input = [];
}

$lastSyncedAt = $input['last_synced_at'] ?? '1970-01-01 00:00:00';
$mutations = $input['mutations'] ?? [];
$allowedStatuses = ['Present', 'Absent', 'Late', 'Excused'];
$serverTime = date('Y-m-d H:i:s');
try {
    $dbNow = $pdo->query("SELECT NOW()")->fetchColumn();
    if ($dbNow) {
        $serverTime = (string)$dbNow;
    }
} catch (\Throwable $e) {
    // Fallback to PHP date
}

try {
    $pdo->beginTransaction();

    // 1. Ingest Inbound Offline Mutations from Client
    if (!empty($mutations) && is_array($mutations)) {
        $studentClassStmt = $pdo->prepare('SELECT 1 FROM students WHERE student_id = ? AND class_id = ? LIMIT 1');
        $insertStmt = $pdo->prepare("
            INSERT INTO attendance (student_id, class_id, status, attendance_date, created_at, updated_at)
            VALUES (:student_id, :class_id, :status, :attendance_date, NOW(), NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()
        ");

        foreach ($mutations as $action) {
            if (isset($action['entity']) && $action['entity'] === 'attendance' && isset($action['payload'])) {
                $payload = $action['payload'];
                $studentId = filter_var($payload['student_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $classId = filter_var($payload['class_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $status = $payload['status'] ?? 'Present';
                $attendanceDate = $payload['date'] ?? date('Y-m-d');
                $dateIsValid = is_string($attendanceDate)
                    && preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)
                    && strtotime($attendanceDate) !== false;
                if (!$studentId || !$classId || !in_array($status, $allowedStatuses, true) || !$dateIsValid) {
                    throw new InvalidArgumentException('Invalid attendance mutation.');
                }
                $studentClassStmt->execute([$studentId, $classId]);
                if (!$studentClassStmt->fetchColumn()) {
                    throw new InvalidArgumentException('Student does not belong to the supplied class.');
                }
                $insertStmt->execute([
                    ':student_id'       => $studentId,
                    ':class_id'         => $classId,
                    ':status'           => $status,
                    ':attendance_date'  => $attendanceDate
                ]);
            }
        }
    }

    // 2. Fetch Outbound Changes (Deltas) Since Last Sync
    $deltaStmt = $pdo->prepare("
        SELECT id, 'attendance' AS entity, student_id, class_id, status, attendance_date, updated_at 
        FROM attendance 
        WHERE updated_at > :last_sync
        ORDER BY updated_at ASC 
        LIMIT 250
    ");
    $deltaStmt->execute([':last_sync' => $lastSyncedAt]);
    $updates = $deltaStmt->fetchAll(PDO::FETCH_ASSOC);

    $pdo->commit();

    echo json_encode([
        'status'      => 'success',
        'server_time' => $serverTime,
        'updates'     => $updates
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (\InvalidArgumentException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Sync operation failed',
        'debug'   => defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : null
    ]);
}
