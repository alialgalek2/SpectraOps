<?php
/**
 * PRISM EDU - Production Security & Session Bootstrap
 * Enforces strict cookie parameters, defense-in-depth HTTP headers, and CSRF token protection.
 */

// 1. Strict Session Security Flags
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') 
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $domain = preg_replace('/:\d+$/', '', $host);
    // Browsers reject a Domain attribute for localhost and IP addresses.  Leave
    // it host-only in those environments so sign-in works consistently.
    if ($domain === 'localhost' || filter_var($domain, FILTER_VALIDATE_IP) || !str_contains($domain, '.')) {
        $domain = '';
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => $domain,
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    @session_start();
}

// 2. Global Security HTTP Headers
if (!headers_sent()) {
    header("X-Frame-Options: SAMEORIGIN");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.gstatic.com; img-src 'self' data: https:; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com; connect-src 'self';");
}

// 3. CSRF Protection Helpers
if (!function_exists('generateCsrfToken')) {
    function generateCsrfToken(): string {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token): bool {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!defined('ENFORCE_STRICT_CSRF')) {
    define('ENFORCE_STRICT_CSRF', true);
}

/**
 * Compatibility layer for legacy server-rendered forms.
 *
 * The application has a central CSRF gate, but many older screens predate it.
 * Add a token to every HTML POST form that has not supplied one itself.  This
 * keeps the gate fail-closed without leaving ordinary staff workflows broken.
 */
if (php_sapi_name() !== 'cli' && !defined('PRISM_CSRF_FORM_BUFFER')) {
    define('PRISM_CSRF_FORM_BUFFER', true);
    ob_start(static function (string $body): string {
        if (!str_contains(strtolower($body), '<form') || !str_contains(strtolower($body), '<html')) {
            return $body;
        }

        $token = htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8');
        return preg_replace_callback(
            '#<form\b(?P<attributes>[^>]*)>(?P<content>.*?)</form>#is',
            static function (array $match) use ($token): string {
                $attributes = $match['attributes'];
                $content = $match['content'];
                $isPost = preg_match('#\bmethod\s*=\s*["\']?post\b#i', $attributes) === 1;

                if (!$isPost || preg_match('#\bname\s*=\s*["\']csrf_token["\']?#i', $content) === 1) {
                    return $match[0];
                }

                return '<form' . $attributes . '><input type="hidden" name="csrf_token" value="' . $token . '">' . $content . '</form>';
            },
            $body
        ) ?? $body;
    });
}

// Intercept state-changing requests (Fail-Closed: Missing or invalid token is always 403)
if (php_sapi_name() !== 'cli' && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PUT', 'DELETE'])) {
    // Machine-to-machine calls authenticating via hardware/device keys are exempt from browser session CSRF
    $isDeviceCall = !empty($_SERVER['HTTP_X_DEVICE_KEY'])
        || !empty($_SERVER['HTTP_X_API_KEY'])
        || !empty($_SERVER['HTTP_X_SYNC_KEY']);

    if (!$isDeviceCall) {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        if (empty($token) || !verifyCsrfToken($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            die(json_encode(['status' => 403, 'error' => 'Invalid or missing CSRF token']));
        }
    }
}
