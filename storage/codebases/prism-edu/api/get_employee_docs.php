<?php
/**
 * API Endpoint: Fetch archived employee documents
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../core/init.php';

// Access Control: Admin or HR Only
if (!Auth::check() || (!Auth::hasRole('admin') && !Auth::hasRole('school_admin') && !Auth::hasRole('hr'))) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Administrator or HR privileges required.', 'docs' => []]);
    exit();
}

$employee_id = intval($_GET['employee_id'] ?? 0);

if ($employee_id <= 0) {
    echo json_encode(['docs' => []]);
    exit();
}

try {
    $stmt = $pdo->prepare("
        SELECT document_id, employee_id, document_title, document_type, file_path, file_size, expiry_date, uploaded_at
        FROM employee_documents
        WHERE employee_id = ?
        ORDER BY uploaded_at DESC
    ");
    $stmt->execute([$employee_id]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['docs' => $docs]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'docs' => []]);
}
