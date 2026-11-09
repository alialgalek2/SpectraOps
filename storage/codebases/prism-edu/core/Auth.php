<?php
/**
 * Core Authentication & Access Control Service (Umbrella Architecture)
 */

class Auth {
    // Long data-entry workflows (admission, grading, finance) need a usable
    // session window.  The browser can still log out explicitly at any time.
    const INACTIVITY_TIMEOUT = 1800; // 30 minutes in seconds

    public static function check(): bool {
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            return false;
        }

        // Check 5-minute inactivity timeout
        if (isset($_SESSION['last_activity'])) {
            $elapsed = time() - (int)$_SESSION['last_activity'];
            if ($elapsed > self::INACTIVITY_TIMEOUT) {
                self::handleInactivityLogout();
                return false;
            }
        }

        // User is active: refresh activity timestamp
        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * Terminate session on inactivity timeout and set flash reason
     */
    public static function handleInactivityLogout(): void {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_regenerate_id(true);
        }
        $_SESSION['timeout_logout'] = true;
    }

    public static function id(): ?int {
        return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    }

    public static function role(): string {
        return strtolower($_SESSION['role'] ?? '');
    }

    public static function fullName(): string {
        return $_SESSION['full_name'] ?? 'User';
    }

    public static function user(): ?array {
        if (!self::check()) return null;
        return [
            'user_id'   => self::id(),
            'username'  => $_SESSION['username'] ?? '',
            'role'      => self::role(),
            'full_name' => self::fullName(),
            'email'     => $_SESSION['email'] ?? '',
            'role_id'   => $_SESSION['role_id'] ?? 1
        ];
    }

    /**
     * @param array|string $roles
     * @return bool
     */
    public static function hasRole(array|string $roles): bool {
        if (!self::check()) return false;
        if (is_string($roles)) {
            $roles = [$roles];
        }
        $normalized = array_map('strtolower', $roles);
        return in_array(self::role(), $normalized);
    }

    public static function requireLogin(?string $redirect = null): void {
        if (!self::check()) {
            $isTimeout = !empty($_SESSION['timeout_logout']);
            unset($_SESSION['timeout_logout']);
            $timeoutQuery = $isTimeout ? '?reason=timeout' : '';
            $loginUrl = $redirect ?? (defined('BASE_URL') ? BASE_URL . '/login.php' . $timeoutQuery : '/school_system/login.php' . $timeoutQuery);

            // Handle AJAX / API callers gracefully
            $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));
            if ($isAjax) {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode([
                    'success'  => false,
                    'error'    => 'Session timed out due to inactivity',
                    'reason'   => 'timeout',
                    'redirect' => $loginUrl
                ]);
                exit();
            }

            header("Location: " . $loginUrl);
            exit();
        }
    }

    public static function requireAuth(?string $redirect = null): void {
        self::requireLogin($redirect);
    }

    /**
     * @param array|string $roles
     * @param string|null $redirect
     * @return void
     */
    public static function requireRole(array|string $roles, ?string $redirect = null): void {
        self::requireLogin();
        if (!self::hasRole($roles)) {
            $_SESSION['flash_msg'] = function_exists('is_rtl') && is_rtl()
                ? "تم رفض الوصول: ليس لديك الصلاحية الكافية لزيارة هذه الصفحة."
                : "Access Denied: You do not have sufficient permissions to access this page.";
            $_SESSION['flash_msg_type'] = "danger";

            $dashUrl = $redirect ?? (defined('BASE_URL') ? BASE_URL . '/dashboard.php' : '/school_system/dashboard.php');
            header("Location: " . $dashUrl);
            exit();
        }
    }

    public static function requireAdmin(?string $redirect = null): void {
        self::requireRole('admin', $redirect);
    }

    public static function login(array $user): void {
        // Prevent an attacker from reusing a session identifier set before login.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id']       = $user['user_id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['role']          = strtolower($user['role']);
        $_SESSION['role_id']       = $user['role_id'] ?? 1;
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['email']         = $user['email'] ?? '';
        $_SESSION['last_activity'] = time();

        if (!empty($user['theme_preference']) && in_array($user['theme_preference'], ['light', 'dark'])) {
            $_SESSION['theme_preference'] = $user['theme_preference'];
            setcookie('theme_mode', $user['theme_preference'], time() + (86400 * 365), '/', '', false, false);
        }
    }

    /**
     * Vendor Support Authentication
     * Validates a temporary vendor access token and sets up a support session.
     */
    public static function loginVendorSupport(string $token): bool {
        global $pdo;
        if (!$pdo) return false;
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM vendor_support_grants WHERE access_token = ? AND is_active = 1 AND expires_at > NOW()");
            $stmt->execute([$token]);
            $grant = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($grant) {
                $_SESSION['user_id']       = 0; // Vendor ID
                $_SESSION['username']      = 'vendor_' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $grant['vendor_name']));
                $_SESSION['role']          = 'admin'; // Grant admin-level access for support
                $_SESSION['role_id']       = 1;
                $_SESSION['full_name']     = $grant['vendor_name'] . ' (Support)';
                $_SESSION['email']         = 'support@vendor.local';
                $_SESSION['last_activity'] = time();
                $_SESSION['is_vendor']     = true;

                // Log audit action
                $auditStmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, entity, entity_id, description, ip_address, created_at) VALUES (0, 'login', 'vendor_support', ?, ?, ?, NOW())");
                $auditStmt->execute([
                    $grant['grant_id'],
                    "[VENDOR SUPPORT] " . $grant['vendor_name'] . " logged in via temporary access token.",
                    $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);

                return true;
            }
        } catch (\Throwable $e) {
            error_log("Vendor Login Error: " . $e->getMessage());
        }
        return false;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }
}
