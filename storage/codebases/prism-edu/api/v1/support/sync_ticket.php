<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once dirname(__DIR__, 3) . '/config/Database.php';

use App\Config\Database;

$rawInput = file_get_contents('php://input');
$data = !empty($rawInput) ? json_decode($rawInput, true) : [];

if (!is_array($data) || empty($data['ticket_number']) || empty($data['status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing ticket_number or status parameter.']);
    exit;
}

$ticketNumber = trim((string)$data['ticket_number']);
$status = strtolower(trim((string)$data['status']));

$allowedStatuses = ['open', 'in_progress', 'resolved', 'closed'];
if (!in_array($status, $allowedStatuses, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Invalid status: {$status}. Allowed: " . implode(', ', $allowedStatuses)]);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        UPDATE support_tickets 
        SET status = :status, updated_at = NOW() 
        WHERE ticket_number = :ticket_number
    ");
    $stmt->execute([
        ':status'        => $status,
        ':ticket_number' => $ticketNumber
    ]);

    $matched = $stmt->rowCount();

    echo json_encode([
        'success'       => true,
        'ticket_number' => $ticketNumber,
        'new_status'    => $status,
        'rows_updated'  => $matched,
        'timestamp'     => date('Y-m-d H:i:s')
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database update error: ' . $e->getMessage()]);
}
