<?php
/** Serve employee documents only after the same HR authorization used by the archive. */
require_once __DIR__ . '/../core/init.php';

if (!Auth::hasRole(['admin', 'school_admin', 'hr', 'finance'])) {
    http_response_code(403);
    exit('Forbidden');
}

$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$documentId) {
    http_response_code(400);
    exit('Invalid document request');
}

$stmt = $pdo->prepare('SELECT file_path, document_title FROM employee_documents WHERE document_id = ?');
$stmt->execute([$documentId]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$document || !str_starts_with($document['file_path'], 'uploads/hr_documents/')) {
    http_response_code(404);
    exit('Document not found');
}

$path = realpath(BASE_PATH . '/' . $document['file_path']);
$root = realpath(BASE_PATH . '/uploads/hr_documents');
if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Document not found');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
$name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $document['document_title'] ?: basename($path));
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
