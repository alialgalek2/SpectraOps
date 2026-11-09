<?php
require_once __DIR__ . '/../core/init.php';

$notice = $pdo->query('SELECT * FROM announcements ORDER BY announcement_id DESC LIMIT 1')->fetch();
if ($notice) {
    $noticeText = !empty($notice['message']) ? $notice['message'] : ($notice['content'] ?? '');
    echo "RENDERED BANNER OUTPUT:\n";
    echo htmlspecialchars($notice['title'] ?? 'Notice') . ': ' . htmlspecialchars($noticeText) . "\n";
}
