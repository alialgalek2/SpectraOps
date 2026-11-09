<?php
/**
 * API Endpoint: Set User Theme Preference (Light / Dark Mode)
 * Persists theme preference across session, cookies, and database.
 */

require_once __DIR__ . '/../core/init.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$theme = $input['theme'] ?? $_POST['theme'] ?? '';

if (!in_array($theme, ['light', 'dark'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid theme mode. Expected "light" or "dark".']);
    exit();
}

// 1. Update Session
$_SESSION['theme_preference'] = $theme;

// 2. Set 1-Year Cookie for Instant Client-Side Zero-Flicker Pre-render
setcookie('theme_mode', $theme, [
    'expires'  => time() + (86400 * 365),
    'path'     => '/',
    'samesite' => 'Lax',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'
]);

// 3. Persist to Database if User is Logged In
$user_id = $_SESSION['user_id'] ?? null;
if ($user_id && isset($pdo)) {
    try {
        $stmt = $pdo->prepare("UPDATE users SET theme_preference = ? WHERE user_id = ?");
        $stmt->execute([$theme, $user_id]);
    } catch (Exception $e) {
        // Silently continue if database column or table is unavailable
    }
}

echo json_encode([
    'success' => true,
    'theme'   => $theme,
    'message' => 'Theme preference saved successfully'
]);
