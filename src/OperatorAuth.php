<?php
declare(strict_types=1);

namespace FleetHub;

/** Shared session and authorization guard for the operator console and its API. */
final class OperatorAuth {
    public static function startSession(): void {
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', '1');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            ini_set('session.cookie_secure', '1');
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function requireOperatorApi(bool $writeAccess = false): void {
        self::startSession();
        $role = (string)($_SESSION['fleet_user_role'] ?? '');
        if (empty($_SESSION['fleet_user_id']) || !in_array($role, ['admin', 'viewer'], true)) {
            self::deny(401, 'Operator authentication is required.');
        }
        if ($writeAccess && $role !== 'admin') {
            self::deny(403, 'Administrator access is required.');
        }
        if ($writeAccess && !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
            self::deny(403, 'CSRF validation failed. Refresh the console and try again.');
        }
    }

    private static function deny(int $status, string $message): never {
        http_response_code($status);
        echo json_encode(['status' => 'error', 'message' => $message]);
        exit;
    }
}
