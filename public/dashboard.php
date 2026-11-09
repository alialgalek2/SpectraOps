<?php
declare(strict_types=1);

// --- 1. CONFIGURATION & CORE INCLUDES ---
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
require_once dirname(__DIR__) . '/src/Services/VendPulseLicenseService.php';
if (file_exists(dirname(__DIR__) . '/services/GeminiHotfixAgent.php')) {
    require_once dirname(__DIR__) . '/services/GeminiHotfixAgent.php';
} elseif (file_exists(dirname(__DIR__) . '/src/Services/GeminiHotfixAgent.php')) {
    require_once dirname(__DIR__) . '/src/Services/GeminiHotfixAgent.php';
}

use FleetHub\Database;
use FleetHub\FleetService;
use FleetHub\RateLimiter;
use App\Services\LicenseManager;
use App\Services\VendPulseLicenseService;
use App\Services\GeminiHotfixAgent;
use App\Services\OrderProvisioningService;

// Harden session settings before session_start() (H2)
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = Database::getConnection();
$service = new FleetService($pdo);

// Handle Logout (GET or POST)
if (isset($_GET['logout']) || (isset($_POST['action']) && $_POST['action'] === 'logout')) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $cleanPath = strtok($_SERVER['REQUEST_URI'] ?? 'dashboard.php', '?');
    header('Location: ' . $cleanPath);
    exit;
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$limiter = new RateLimiter($pdo);

// Handle Login (C1: Strict password verification only; H3: Login rate limiting)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['login_pass'])) {
    $submittedCsrf = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $loginError = "Security token mismatch or expired session. Please refresh and retry.";
    } elseif ($limiter->tooManyAttempts('login_fail:' . $clientIp, 5, 900)) {
        $loginError = "Too many failed login attempts. Security lockout active for 15 minutes.";
    } else {
        $email = trim((string)($_POST['login_email'] ?? ''));
        $pass = (string)($_POST['login_pass'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM central_users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($pass, (string)$user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['fleet_user_id'] = (int)$user['id'];
            $_SESSION['fleet_user_name'] = $user['name'];
            $_SESSION['fleet_user_role'] = $user['role'];
            $_SESSION['fleet_admin_logged'] = true;
            $limiter->clear('login_fail:' . $clientIp);
            
            // Clean redirect without ?logout=1 or stale parameters
            $cleanPath = strtok($_SERVER['REQUEST_URI'] ?? 'dashboard.php', '?');
            header('Location: ' . $cleanPath);
            exit;
        } else {
            $limiter->hit('login_fail:' . $clientIp, 900);
            $loginError = "Invalid operator credentials. Access denied.";
        }
    }
}

if (empty($_SESSION['fleet_user_id']) && empty($_SESSION['fleet_admin_logged'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'ai_diagnose' || ($_POST['action'] ?? '') === 'approve_ai_patch')) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required. Your operator session may have expired. Please refresh and log in.']);
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>SpectraOps — Operator Console Authentication</title>
        <link rel="icon" type="image/svg+xml" href="spectraops-icon.svg">
        <link rel="alternate icon" type="image/png" href="spectraops-icon.png">
        <link rel="shortcut icon" href="favicon.ico">
        <link rel="apple-touch-icon" href="apple-touch-icon.png">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <script>
            (function() {
                var t = localStorage.getItem('spectraops_theme') || 'dark';
                document.documentElement.setAttribute('data-theme', t);
            })();
        </script>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
        <script src="spectra-popup.js?v=<?= filemtime(__DIR__ . '/spectra-popup.js') ?>"></script>
        <style>
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                background-color: var(--ink);
                background-image: 
                    radial-gradient(circle at 50% 12%, rgba(79, 168, 224, 0.12) 0%, rgba(10, 14, 20, 0) 65%),
                    linear-gradient(rgba(31, 39, 51, 0.28) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(31, 39, 51, 0.28) 1px, transparent 1px);
                background-size: 100% 100%, 32px 32px, 32px 32px;
                padding: 24px;
            }
            .login-card {
                width: 100%;
                max-width: 410px;
                background: var(--panel);
                border: 1px solid var(--line);
                border-top: 2px solid var(--accent);
                border-radius: var(--radius-lg);
                padding: 36px 32px 28px;
                box-shadow: 0 24px 48px rgba(0, 0, 0, 0.7), 0 0 25px rgba(79, 168, 224, 0.08);
                position: relative;
            }
            .login-card-header {
                text-align: center;
                margin-bottom: 26px;
            }
            .login-brand {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                margin-bottom: 10px;
            }
            .login-brand-name {
                font-weight: 700;
                font-size: 20px;
                letter-spacing: 0.4px;
                color: var(--text);
            }
            .login-brand-name span { color: var(--accent); }
            .login-desc {
                font-size: 11.5px;
                color: var(--text-dim);
                margin-bottom: 12px;
                letter-spacing: 0.2px;
            }
            .login-status-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                padding: 3px 9px;
                background: rgba(61, 214, 140, 0.08);
                border: 1px solid var(--ok-dim);
                border-radius: 999px;
                font-family: 'JetBrains Mono', monospace;
                font-size: 10px;
                font-weight: 600;
                color: var(--ok-text);
                letter-spacing: 0.5px;
            }
            .input-wrapper {
                position: relative;
                display: flex;
                align-items: center;
            }
            .input-icon {
                position: absolute;
                left: 12px;
                width: 15px;
                height: 15px;
                color: var(--text-faint);
                pointer-events: none;
                transition: color 0.15s;
            }
            .input-wrapper .form-control {
                padding-left: 36px;
                padding-right: 36px;
                height: 38px;
                font-size: 12.5px;
            }
            .input-wrapper .form-control:focus ~ .input-icon {
                color: var(--accent);
            }
            .pwd-toggle-btn {
                position: absolute;
                right: 8px;
                background: none;
                border: none;
                color: var(--text-faint);
                cursor: pointer;
                padding: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: var(--radius);
                transition: color 0.15s;
            }
            .pwd-toggle-btn:hover {
                color: var(--text);
            }
            .pwd-toggle-btn svg {
                width: 15px;
                height: 15px;
            }
            .login-submit-btn {
                width: 100%;
                justify-content: center;
                padding: 10px 16px;
                font-size: 13px;
                font-weight: 600;
                letter-spacing: 0.2px;
                margin-top: 8px;
                gap: 8px;
            }
            .login-card-footer {
                margin-top: 26px;
                padding-top: 18px;
                border-top: 1px solid var(--line-soft);
                display: flex;
                justify-content: space-between;
                align-items: center;
                font-size: 10.5px;
                color: var(--text-faint);
                font-family: 'JetBrains Mono', monospace;
            }
            .security-pill {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                color: var(--text-dim);
            }
        </style>
    </head>
    <body class="login-body">
        <div class="login-theme-switch-wrap">
            <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="Switch Light/Dark mode" aria-label="Switch Theme">
                <span class="theme-toggle-track">
                    <span class="theme-toggle-icon sun-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    </span>
                    <span class="theme-toggle-icon moon-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
                    </span>
                    <span class="theme-toggle-thumb"></span>
                </span>
                <span class="theme-toggle-label">Dark</span>
            </button>
        </div>
        <div class="login-card">
            <div class="login-card-header">
                <div class="login-brand">
                    <svg width="30" height="30" viewBox="0 0 54 54">
                        <polygon points="6,46 22,10 31,10 15,46" fill="#4FA8E0"/>
                        <rect x="23" y="28" width="4.2" height="18" rx="1" fill="#2D5A78"/>
                        <rect x="31" y="20" width="4.2" height="26" rx="1" fill="#4FA8E0"/>
                        <rect x="39" y="12" width="4.2" height="34" rx="1" fill="#7FC4EE"/>
                    </svg>
                    <div class="login-brand-name">Spectra<span>Ops</span></div>
                </div>
                <div class="login-desc">Central Telemetry &amp; Fleet Operations Hub</div>
                <div>
                    <span class="login-status-badge">
                        <span class="dot dot-ok"></span>OPERATOR GATEWAY &bull; LIVE
                    </span>
                </div>
            </div>

            <?php if (!empty($loginError)): ?>
                <div class="alert-strip danger" style="border-radius: var(--radius); margin-bottom: 18px; padding: 10px 12px; font-size: 12px;">
                    <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span><?= htmlspecialchars($loginError) ?></span>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        if (window.SpectraPopup) {
                            window.SpectraPopup.show({
                                title: 'Authentication Alert',
                                message: <?= json_encode($loginError) ?>,
                                type: 'error',
                                confirmText: 'Dismiss'
                            });
                        }
                    });
                </script>
            <?php endif; ?>

            <form method="POST" action="<?= htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? 'dashboard.php', '?')) ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                
                <div class="form-group">
                    <label style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px;">Operator Email</label>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" name="login_email" class="form-control" placeholder="admin@domain.com" required autofocus autocomplete="username">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 22px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                        <label style="font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0;">Access Key / Password</label>
                    </div>
                    <div class="input-wrapper">
                        <svg class="input-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        <input type="password" id="loginPassInput" name="login_pass" class="form-control" placeholder="Enter operator password" required autocomplete="current-password">
                        <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility()" title="Toggle password view" aria-label="Toggle password visibility">
                            <svg id="pwdEyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary login-submit-btn">
                    <span>Authenticate &amp; Access Console</span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="width:14px; height:14px;"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                </button>
            </form>

            <div class="login-card-footer">
                <div class="security-pill">
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span>bcrypt-60 / SHA-256</span>
                </div>
                <span>RATE LIMITED</span>
            </div>
        </div>

        <script>
        function togglePasswordVisibility() {
            var input = document.getElementById('loginPassInput');
            var icon = document.getElementById('pwdEyeIcon');
            if (input.type === 'password') {
                input.type = 'text';
                icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                input.type = 'password';
                icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        }

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            try { localStorage.setItem('spectraops_theme', theme); } catch(e) {}
            document.querySelectorAll('.theme-toggle-label').forEach(function(el) {
                el.textContent = theme === 'light' ? 'Light' : 'Dark';
            });
            document.querySelectorAll('.theme-toggle-btn').forEach(function(btn) {
                btn.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
                btn.setAttribute('title', 'Switch to ' + (theme === 'light' ? 'Dark' : 'Light') + ' mode');
            });
        }

        function toggleTheme() {
            var current = document.documentElement.getAttribute('data-theme') || 'dark';
            applyTheme(current === 'light' ? 'dark' : 'light');
        }

        document.addEventListener('DOMContentLoaded', function() {
            var saved = 'dark';
            try { saved = localStorage.getItem('spectraops_theme') || 'dark'; } catch(e) {}
            applyTheme(saved);
        });
        </script>
    </body>
    </html>
    <?php
    exit;
}

$isAdmin = ($_SESSION['fleet_user_role'] ?? '') === 'admin';
$feedbackMsg = null;
$feedbackType = 'info';

if (!empty($_SESSION['flash_feedback'])) {
    $feedbackMsg = (string)($_SESSION['flash_feedback']['msg'] ?? '');
    $feedbackType = (string)($_SESSION['flash_feedback']['type'] ?? 'info');
    unset($_SESSION['flash_feedback']);
}

// --- 2. DATABASE ACTIONS ---

// Enforce CSRF protection on every state-changing POST request (H1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $submittedToken = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $isAuthedOperator = !empty($_SESSION['fleet_user_id']) || !empty($_SESSION['fleet_admin_logged']);
    $isAiAction = in_array($_POST['action'] ?? '', ['ai_diagnose', 'approve_ai_patch'], true);

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedToken)) {
        if ($isAiAction && $isAuthedOperator) {
            // Allow authenticated operator session for AI diagnostic & hotfix actions
        } else {
            if ($isAiAction) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Security validation failed: CSRF token mismatch.']);
                exit;
            }
            http_response_code(403);
            die("Security validation failed: CSRF token mismatch. Please refresh and try again.");
        }
    }
}

// Viewers may inspect the fleet, but all operational changes require an admin.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !$isAdmin) {
    if (($_POST['action'] ?? '') === 'ai_diagnose' || ($_POST['action'] ?? '') === 'approve_ai_patch') {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Administrator access is required for AI diagnostics and hotfix publishing.']);
        exit;
    }
    http_response_code(403);
    die('Administrator access is required for this action.');
}

// 1. AJAX Trigger: Run AI Diagnosis
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_diagnose') {
    header('Content-Type: application/json');
    $errorId = (int)($_POST['error_id'] ?? 0);
    $apiKey = getenv('GEMINI_API_KEY') ?: ($_ENV['GEMINI_API_KEY'] ?? '');
    if (empty($apiKey) || $apiKey === 'YOUR_API_KEY') {
        echo json_encode(['success' => false, 'error' => 'GEMINI_API_KEY is not configured or empty. Please set a valid API key in .env.']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT e.*, p.id AS product_id, p.product_slug, p.latest_version
        FROM central_error_logs e
        JOIN central_deployments d ON e.deployment_id = d.id
        JOIN central_products p ON d.product_id = p.id
        WHERE e.id = ?
    ");
    $stmt->execute([$errorId]);
    $err = $stmt->fetch();

    if (!$err) {
        echo json_encode(['success' => false, 'error' => 'Error log record not found.']);
        exit;
    }

    try {
        if (file_exists(__DIR__ . '/services/GeminiHotfixAgent.php')) {
            require_once __DIR__ . '/services/GeminiHotfixAgent.php';
        } elseif (file_exists(dirname(__DIR__) . '/services/GeminiHotfixAgent.php')) {
            require_once dirname(__DIR__) . '/services/GeminiHotfixAgent.php';
        } else {
            require_once dirname(__DIR__) . '/src/Services/GeminiHotfixAgent.php';
        }

        $codebasesRoot = is_dir(__DIR__ . '/storage/codebases') ? __DIR__ . '/storage/codebases' : dirname(__DIR__) . '/storage/codebases';
        $stagingDir = dirname(__DIR__) . '/storage/hotfixes/' . $err['product_slug'] . '/staging';
        if (!is_dir($stagingDir)) {
            @mkdir($stagingDir, 0755, true);
        }
        $updatesOutputDir = $stagingDir;

        $model = getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash';
        $agent = new GeminiHotfixAgent(
            $apiKey,
            $codebasesRoot,
            $updatesOutputDir,
            $model
        );

        $result = $agent->generateFix(
            $err['product_slug'],
            $err['error_message'],
            $err['file_path'] ?? '',
            $err['stack_trace'] ?? '',
            $err['latest_version']
        );

        $_SESSION['pending_ai_patch_' . $errorId] = array_merge($result, [
            'product_id'       => (int)$err['product_id'],
            'product_slug'     => $err['product_slug'],
            'error_id'         => $errorId,
            'staging_filename' => $result['package_filename'] ?? ''
        ]);

        echo json_encode([
            'success'          => true,
            'error_id'         => $errorId,
            'product_id'       => (int)$err['product_id'],
            'product_slug'     => $err['product_slug'],
            'root_cause'       => $result['root_cause'] ?? '',
            'fix_summary'      => $result['fix_summary'] ?? '',
            'new_version'      => $result['new_version'] ?? '',
            'fixed_file_path'  => $result['fixed_file_path'] ?? '',
            'staging_filename' => $result['package_filename'] ?? '',
            'package_filename' => $result['package_filename'] ?? '',
            'package_checksum' => $result['package_checksum'] ?? '',
            'original_code'    => $result['original_code'] ?? '',   // <--- Passed to client
            'corrected_code'   => $result['corrected_code'] ?? '',  // <--- Passed to client
            'csrf_token'       => (string)($_SESSION['csrf_token'] ?? '')
        ]);
        exit;
    } catch (\Throwable $ex) {
        echo json_encode(['success' => false, 'error' => $ex->getMessage()]);
        exit;
    }
}

// 2. Action: Approve & Distribute Generated Patch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_ai_patch') {
    $errorId = (int)($_POST['error_id'] ?? 0);
    $patchData = $_SESSION['pending_ai_patch_' . $errorId] ?? null;

    if ($patchData) {
        try {
            $safePkgName = basename((string)($patchData['package_filename'] ?? ''));
            $prodSlug = (string)($patchData['product_slug'] ?? '');

            // Locate staging artifact across candidate storage paths
            $candidateStagingPaths = [
                dirname(__DIR__) . "/storage/hotfixes/{$prodSlug}/staging/{$safePkgName}",
                dirname(__DIR__) . "/storage/hotfixes/staging/{$safePkgName}",
                dirname(__DIR__) . "/public/downloads/updates/{$safePkgName}",
            ];

            $stagingPath = null;
            foreach ($candidateStagingPaths as $candidate) {
                if (file_exists($candidate) && is_file($candidate)) {
                    $stagingPath = $candidate;
                    break;
                }
            }

            $updatesDir = dirname(__DIR__) . '/public/downloads/updates';
            if (!is_dir($updatesDir)) {
                @mkdir($updatesDir, 0755, true);
            }
            $targetPath = $updatesDir . '/' . $safePkgName;

            // Atomically promote artifact from staging into public downloads directory
            if ($stagingPath && realpath($stagingPath) !== realpath($targetPath)) {
                if (!@rename($stagingPath, $targetPath)) {
                    if (@copy($stagingPath, $targetPath)) {
                        @unlink($stagingPath);
                    }
                }
            }

            $actualChecksum = file_exists($targetPath) ? hash_file('sha256', $targetPath) : ($patchData['package_checksum'] ?? '');

            $service->publishRelease(
                (int)$patchData['product_id'],
                (string)$patchData['new_version'],
                "AI Automated Hotfix: " . (string)($patchData['fix_summary'] ?? ''),
                $safePkgName,
                (string)$actualChecksum,
                true,
                $errorId > 0 ? $errorId : null
            );

            unset($_SESSION['pending_ai_patch_' . $errorId]);
            $_SESSION['flash_feedback'] = [
                'msg' => "AI Patch <strong>v" . htmlspecialchars((string)$patchData['new_version']) . "</strong> approved and published as an OTA release!",
                'type' => 'success'
            ];
            $redirectId = $patchData['product_id'] ?? 1;
            header("Location: product_view.php?id={$redirectId}&status=patch_published");
            exit;
        } catch (\Throwable $e) {
            error_log("[Dashboard] AI Patch approval failed: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to approve AI patch: " . htmlspecialchars($e->getMessage()),
                'type' => 'danger'
            ];
            header("Location: dashboard.php?page=errors&status=patch_failed");
            exit;
        }
    } else {
        $_SESSION['flash_feedback'] = [
            'msg' => "No pending AI patch found for error ID #{$errorId}. Please re-diagnose or check release history.",
            'type' => 'warning'
        ];
        header("Location: dashboard.php?page=errors&status=no_pending_patch");
        exit;
    }
}

// --- SPECTRAOPS UNIFIED DELETION SUITE ---

// 1. Delete Fleet Deployment
if (isset($_GET['delete_deployment'])) {
    $depId = (int)$_GET['delete_deployment'];
    $del = $pdo->prepare("DELETE FROM central_deployments WHERE id = ?");
    $del->execute([$depId]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?status=deployment_deleted');
    exit;
}

// 2. Delete Error Log (Individual or Purge Resolved)
if (isset($_GET['delete_error'])) {
    $errId = (int)$_GET['delete_error'];
    $del = $pdo->prepare("DELETE FROM central_error_logs WHERE id = ?");
    $del->execute([$errId]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=errors&status=error_deleted');
    exit;
}

if (isset($_GET['purge_resolved_errors'])) {
    $prodFilter = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    if ($prodFilter > 0) {
        $stmt = $pdo->prepare("DELETE FROM central_error_logs WHERE (status = 'resolved' OR resolved = 1) AND deployment_id IN (SELECT id FROM central_deployments WHERE product_id = ?)");
        $stmt->execute([$prodFilter]);
    } else {
        $pdo->exec("DELETE FROM central_error_logs WHERE status = 'resolved' OR resolved = 1");
    }
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=errors&status=resolved_errors_purged');
    exit;
}

// 3. Delete Support Ticket
if (isset($_GET['delete_ticket'])) {
    $ticketId = (int)$_GET['delete_ticket'];
    $del = $pdo->prepare("DELETE FROM central_support_tickets WHERE id = ?");
    $del->execute([$ticketId]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=tickets&status=ticket_deleted');
    exit;
}

// 4. Delete Published Release & Remove Physical ZIP File
if (isset($_GET['delete_release'])) {
    $relId = (int)$_GET['delete_release'];
    
    // Fetch file path before deleting record
    $stmt = $pdo->prepare("SELECT package_filename FROM central_releases WHERE id = ?");
    $stmt->execute([$relId]);
    $filename = $stmt->fetchColumn();

    if ($filename) {
        $possiblePaths = [
            __DIR__ . '/downloads/updates/' . $filename,
            dirname(__DIR__) . '/public/downloads/updates/' . $filename,
            __DIR__ . '/public/downloads/updates/' . $filename,
        ];
        foreach ($possiblePaths as $filePath) {
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
    }

    $del = $pdo->prepare("DELETE FROM central_releases WHERE id = ?");
    $del->execute([$relId]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=releases&status=release_deleted');
    exit;
}

// 5. Delete Product, Codebase Directory & All Associated Data
if (isset($_GET['delete_product'])) {
    $prodId = (int)$_GET['delete_product'];
    
    $stmt = $pdo->prepare("SELECT product_slug FROM central_products WHERE id = ?");
    $stmt->execute([$prodId]);
    $slug = $stmt->fetchColumn();

    // Clean up physical reference codebase directory on disk
    if ($slug) {
        $possibleDirs = [
            dirname(__DIR__) . '/storage/codebases/' . $slug,
            __DIR__ . '/storage/codebases/' . $slug,
        ];
        foreach ($possibleDirs as $codebasePath) {
            if (is_dir($codebasePath)) {
                $files = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($codebasePath, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($files as $fileinfo) {
                    $todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
                    @$todo($fileinfo->getRealPath());
                }
                @rmdir($codebasePath);
            }
        }
    }

    $del = $pdo->prepare("DELETE FROM central_products WHERE id = ?");
    $del->execute([$prodId]);
    header('Location: dashboard.php?page=products&status=product_deleted');
    exit;
}

// 6. Delete License
if (isset($_GET['delete_license'])) {
    $licId = (int)$_GET['delete_license'];
    $del = $pdo->prepare("DELETE FROM central_licenses WHERE id = ?");
    $del->execute([$licId]);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=licenses&status=license_deleted');
    exit;
}

// 6b. Delete VendPulse Offline License
if (isset($_GET['delete_vp_license'])) {
    $licId = (int)$_GET['delete_vp_license'];
    $vpService = new VendPulseLicenseService($pdo);
    $vpService->deleteLicense($licId);
    $_SESSION['flash_feedback'] = ['msg' => 'VendPulse offline license removed successfully.', 'type' => 'success'];
    header('Location: ?page=licenses&view=vendpulse_generator');
    exit;
}

// 6c. Download VendPulse License File (.vpl)
if (isset($_GET['download_vp_license'])) {
    $licId = (int)$_GET['download_vp_license'];
    $vpService = new VendPulseLicenseService($pdo);
    $lic = $vpService->getLicenseById($licId);
    if ($lic) {
        $filename = "vendpulse_license_{$lic['license_id']}.vpl";
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen((string)$lic['license_bundle']));
        echo $lic['license_bundle'];
        exit;
    }
}

// 6d. Download VendPulse Public Key
if (isset($_GET['download_vp_pubkey'])) {
    $vpService = new VendPulseLicenseService($pdo);
    $pub = $vpService->getPublicKey();
    header('Content-Type: application/x-pem-file');
    header('Content-Disposition: attachment; filename="spectraops_vendpulse_public.pem"');
    header('Content-Length: ' . strlen($pub));
    echo $pub;
    exit;
}

// 7. Delete Admin User
if (isset($_GET['delete_admin_user'])) {
    $userId = (int)$_GET['delete_admin_user'];
    $currentAdminId = (int)($_SESSION['fleet_user_id'] ?? $_SESSION['fleet_admin_id'] ?? 0);

    // Prevent self-deletion
    if ($userId !== $currentAdminId) {
        $userTable = 'central_users';
        try {
            $pdo->query("SELECT 1 FROM central_users LIMIT 1");
        } catch (\Throwable $e) {
            $userTable = 'central_admin_users';
        }
        $del = $pdo->prepare("DELETE FROM {$userTable} WHERE id = ?");
        $del->execute([$userId]);
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=users&status=user_deleted');
        exit;
    } else {
        $alertMessage = "Action Denied: You cannot delete your currently active session account.";
        $alertType = 'error';
        $feedbackMsg = $alertMessage;
        $feedbackType = 'danger';
    }
}

// --- ACTION: Upload and Sync Master Project Codebase ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync_codebase') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $baseVersion = trim($_POST['base_version'] ?? '1.0.0');

    if ($productId > 0 && isset($_FILES['codebase_zip']) && $_FILES['codebase_zip']['error'] === UPLOAD_ERR_OK) {
        // 1. Fetch product slug
        $stmtSlug = $pdo->prepare("SELECT product_slug, product_name FROM central_products WHERE id = ?");
        $stmtSlug->execute([$productId]);
        $prod = $stmtSlug->fetch();

        if ($prod) {
            $slug = $prod['product_slug'];
            $storageRoot = is_dir(__DIR__ . '/storage/codebases') ? __DIR__ . '/storage/codebases' : dirname(__DIR__) . '/storage/codebases';
            $targetDir = $storageRoot . '/' . $slug;

            // Ensure destination directory exists and is clean
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $zipPath = $_FILES['codebase_zip']['tmp_name'];
            $zip = new ZipArchive();

            if ($zip->open($zipPath) === true) {
                // Security: Prevent Zip-Slip directory traversal
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (str_contains($entryName, '..') || str_starts_with($entryName, '/')) {
                        $alertMessage = "Security alert: Potentially malicious archive paths detected.";
                        $alertType = 'error';
                        $feedbackMsg = $alertMessage;
                        $feedbackType = 'danger';
                        $zip->close();
                        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?status=error_invalid_zip');
                        exit;
                    }
                }

                // Extract all files into the designated codebase storage
                $zip->extractTo($targetDir);
                $zip->close();

                // Update product's latest base version if provided
                if (!empty($baseVersion)) {
                    $upd = $pdo->prepare("UPDATE central_products SET latest_version = ? WHERE id = ?");
                    $upd->execute([$baseVersion, $productId]);
                }

                $alertMessage = "Master codebase for <strong>" . htmlspecialchars($prod['product_name']) . "</strong> successfully synced to storage!";
                $alertType = 'success';
                $feedbackMsg = $alertMessage;
                $feedbackType = 'success';
            } else {
                $alertMessage = "Failed to extract ZIP archive.";
                $alertType = 'error';
                $feedbackMsg = $alertMessage;
                $feedbackType = 'danger';
            }
        }
    } else {
        $alertMessage = "Please select a valid codebase ZIP file.";
        $alertType = 'error';
        $feedbackMsg = $alertMessage;
        $feedbackType = 'danger';
    }
}

// Action: Pair New Deployment via Prompted Client Key (Reverse Registration)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pair_client') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $clientName = trim((string)($_POST['client_name'] ?? ''));
    $domain = trim((string)($_POST['deployment_domain'] ?? ''));
    $apiKey = trim((string)($_POST['api_key'] ?? ''));

    if (!FleetService::isValidPublicDomain($domain)) {
        $feedbackMsg = "Invalid deployment domain '{$domain}'. Must be a valid public hostname (raw IPs, private networks, and localhost are rejected).";
        $feedbackType = 'danger';
    } elseif (!empty($clientName) && !empty($domain) && !empty($apiKey) && $productId > 0) {
        try {
            $paired = $service->pairDeploymentWithKey($productId, $clientName, $domain, $apiKey);
            $feedbackMsg = "Client deployment successfully paired! Monitoring <strong>" . htmlspecialchars($clientName) . "</strong> (Key Preview: <code>" . htmlspecialchars($paired['token_preview']) . "</code>, Paired at: " . htmlspecialchars($paired['paired_at']) . ")";
            $feedbackType = 'success';
        } catch (\Throwable $e) {
            error_log("[Dashboard] Pair error: " . $e->getMessage());
            $feedbackMsg = "Error pairing client: The deployment could not be paired. Please verify your credentials and network connectivity.";
            $feedbackType = 'danger';
        }
    } else {
        $feedbackMsg = "All fields (Product, Client Name, Domain, Client API Key) are required to complete pairing.";
        $feedbackType = 'danger';
    }
}

// Action: Dynamic License Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'issue_license') {
    $productId   = (int)($_POST['product_id'] ?? 0);
    $clientName  = trim((string)($_POST['client_name'] ?? ''));
    $clientEmail = trim((string)($_POST['client_email'] ?? ''));
    $licenseType = (string)($_POST['license_type'] ?? 'perpetual');
    $expiresAt   = !empty($_POST['expires_at']) ? $_POST['expires_at'] . ' 23:59:59' : null;

    if ($productId > 0 && $clientName !== '' && $clientEmail !== '') {
        try {
            // If the selected product is VendPulse, guide to VendPulse Offline Generator
            $stmtProd = $pdo->prepare("SELECT product_slug, product_code FROM central_products WHERE id = ?");
            $stmtProd->execute([$productId]);
            $prodInfo = $stmtProd->fetch();
            if ($prodInfo && ($prodInfo['product_code'] === 'VP' || ($prodInfo['product_slug'] ?? '') === 'vendpulse')) {
                header('Location: ?page=licenses&view=vendpulse_generator' . ($clientName !== '' ? '&vp_client=' . urlencode($clientName) : ''));
                exit;
            }

            require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
            $mgr = new LicenseManager($pdo);
            $issued = $mgr->issueLicense($productId, $clientName, $clientEmail, $licenseType, $expiresAt);

            $feedbackMsg = "Issued <strong>" . htmlspecialchars($issued['product_name']) . "</strong> License for <strong>" . htmlspecialchars($issued['client_name']) . "</strong>: <code style='font-family:JetBrains Mono,monospace; color:var(--accent); font-weight:600; padding:2px 6px; background:var(--panel-2); border:1px solid var(--line); border-radius:4px;'>" . htmlspecialchars($issued['license_key']) . "</code>";
            $feedbackType = 'success';
        } catch (\Throwable $e) {
            error_log("[Dashboard] Issue license error: " . $e->getMessage());
            $feedbackMsg = "Failed to issue license: " . htmlspecialchars($e->getMessage());
            $feedbackType = 'danger';
        }
    } else {
        $feedbackMsg = "Product, client name, and email are required to issue a license.";
        $feedbackType = 'danger';
    }
}

// Action: Suspend License & Bound Deployment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'suspend_license') {
    $licenseId = (int)($_POST['license_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($licenseId > 0) {
        try {
            require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
            $mgr = new LicenseManager($pdo);
            $lic = $mgr->getLicenseById($licenseId);
            if ($lic) {
                $mgr->suspendLicense($licenseId, $reason);
                $domainNote = !empty($lic['deployment_domain']) ? " (Bound deployment <strong>" . htmlspecialchars($lic['deployment_domain']) . "</strong> deactivated)" : "";
                $_SESSION['flash_feedback'] = [
                    'msg' => "License <code>" . htmlspecialchars($lic['license_key']) . "</code> has been <strong>suspended</strong>.{$domainNote}",
                    'type' => 'warning'
                ];
            }
        } catch (\Throwable $e) {
            error_log("[Dashboard] Suspend license error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to suspend license: " . htmlspecialchars($e->getMessage()),
                'type' => 'danger'
            ];
        }
    }
    $retPage = $_POST['page'] ?? 'licenses';
    header('Location: ?page=' . urlencode((string)$retPage));
    exit;
}

// Action: Reactivate License & Bound Deployment (un-suspend)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reactivate_license') {
    $licenseId = (int)($_POST['license_id'] ?? 0);

    if ($licenseId > 0) {
        try {
            require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
            $mgr = new LicenseManager($pdo);
            $lic = $mgr->getLicenseById($licenseId);
            if ($lic) {
                $mgr->reactivateLicense($licenseId);
                $domainNote = !empty($lic['deployment_domain']) ? " (Bound deployment <strong>" . htmlspecialchars($lic['deployment_domain']) . "</strong> restored)" : "";
                $_SESSION['flash_feedback'] = [
                    'msg' => "License <code>" . htmlspecialchars($lic['license_key']) . "</code> reactivated successfully.{$domainNote}",
                    'type' => 'success'
                ];
            }
        } catch (\Throwable $e) {
            error_log("[Dashboard] Reactivate license error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to reactivate license: " . htmlspecialchars($e->getMessage()),
                'type' => 'danger'
            ];
        }
    }
    $retPage = $_POST['page'] ?? 'licenses';
    header('Location: ?page=' . urlencode((string)$retPage));
    exit;
}

// Action: Revoke License & Bound Deployment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke_license') {
    $licenseId = (int)($_POST['license_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));

    if ($licenseId > 0) {
        try {
            require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
            $mgr = new LicenseManager($pdo);
            $lic = $mgr->getLicenseById($licenseId);
            if ($lic) {
                $mgr->revokeLicense($licenseId, $reason);
                $domainNote = !empty($lic['deployment_domain']) ? " (Bound deployment <strong>" . htmlspecialchars($lic['deployment_domain']) . "</strong> disconnected)" : "";
                $_SESSION['flash_feedback'] = [
                    'msg' => "License <code>" . htmlspecialchars($lic['license_key']) . "</code> has been <strong>permanently revoked</strong>.{$domainNote}",
                    'type' => 'danger'
                ];
            }
        } catch (\Throwable $e) {
            error_log("[Dashboard] Revoke license error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to revoke license: " . htmlspecialchars($e->getMessage()),
                'type' => 'danger'
            ];
        }
    }
    $retPage = $_POST['page'] ?? 'licenses';
    header('Location: ?page=' . urlencode((string)$retPage));
    exit;
}

// Action: Renew License & Deployment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'renew_license') {
    $licenseId = (int)($_POST['license_id'] ?? 0);
    $renewalType = trim((string)($_POST['license_type'] ?? ''));
    $expiresAt = !empty($_POST['expires_at']) ? trim((string)$_POST['expires_at']) . ' 23:59:59' : null;
    $reason = trim((string)($_POST['reason'] ?? 'Renewed via operator console'));

    if ($licenseId > 0) {
        try {
            require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
            $mgr = new LicenseManager($pdo);
            $updated = $mgr->renewLicense($licenseId, $expiresAt, $renewalType ?: null, $reason);
            $expNote = !empty($updated['expires_at']) ? " until <strong>" . htmlspecialchars(date('Y-m-d', strtotime($updated['expires_at']))) . "</strong>" : " as <strong>Perpetual</strong>";
            $domainNote = !empty($updated['deployment_domain']) ? " (Deployment <strong>" . htmlspecialchars($updated['deployment_domain']) . "</strong> re-activated)" : "";
            $_SESSION['flash_feedback'] = [
                'msg' => "License <code>" . htmlspecialchars($updated['license_key'] ?? '') . "</code> renewed successfully{$expNote}.{$domainNote}",
                'type' => 'success'
            ];
        } catch (\Throwable $e) {
            error_log("[Dashboard] Renew license error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to renew license: " . htmlspecialchars($e->getMessage()),
                'type' => 'danger'
            ];
        }
    }
    $retPage = $_POST['page'] ?? 'licenses';
    header('Location: ?page=' . urlencode((string)$retPage));
    exit;
}

// Action: Generate Offline Cryptographic VendPulse License
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_vp_license') {
    $clientName = trim((string)($_POST['client_name'] ?? ''));
    $licenseId = trim((string)($_POST['license_id'] ?? ''));
    $machineHwid = trim((string)($_POST['machine_hwid'] ?? ''));
    $tier = trim((string)($_POST['tier'] ?? 'SUPERMARKET'));
    $usageMode = trim((string)($_POST['usage_mode'] ?? 'RETAIL_SHOP'));
    $maxCounters = max(1, (int)($_POST['max_counters'] ?? 1));
    $isPerpetual = !empty($_POST['is_perpetual']) || ($_POST['expiry_mode'] ?? '') === 'perpetual';
    $expiresAt = null;

    if (!$isPerpetual && !empty($_POST['expires_at'])) {
        $expiresAt = trim((string)$_POST['expires_at']) . ' 23:59:59';
    }

    $allowedModules = $_POST['allowed_modules'] ?? null;
    if (!is_array($allowedModules)) {
        $allowedModules = null;
    }

    if ($clientName !== '') {
        try {
            $vpService = new VendPulseLicenseService($pdo);
            $issued = $vpService->issueLicense([
                'client_name' => $clientName,
                'license_id' => $licenseId !== '' ? $licenseId : null,
                'machine_hwid' => $machineHwid !== '' ? $machineHwid : null,
                'tier' => $tier,
                'usage_mode' => $usageMode,
                'max_counters' => $maxCounters,
                'expires_at' => $expiresAt,
                'allowed_modules' => $allowedModules,
            ]);

            $_SESSION['issued_vp_license'] = $issued;
            $_SESSION['flash_feedback'] = [
                'msg' => "VendPulse offline license <code>{$issued['license_id']}</code> generated and cryptographically signed successfully for {$issued['client_name']}.",
                'type' => 'success'
            ];
        } catch (\Throwable $e) {
            error_log("[Dashboard] Generate VendPulse license error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to generate VendPulse license: " . $e->getMessage(),
                'type' => 'danger'
            ];
        }
    } else {
        $_SESSION['flash_feedback'] = [
            'msg' => "Client / Company name is required to generate a license.",
            'type' => 'danger'
        ];
    }

    header('Location: ?page=licenses&view=vendpulse_generator');
    exit;
}

// Action: Revoke VendPulse Offline License
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke_vp_license') {
    $licId = (int)($_POST['license_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($licId > 0) {
        try {
            $vpService = new VendPulseLicenseService($pdo);
            $vpService->revokeLicense($licId, $reason);
            $_SESSION['flash_feedback'] = [
                'msg' => "VendPulse license #{$licId} marked as revoked.",
                'type' => 'warning'
            ];
        } catch (\Throwable $e) {
            error_log("[Dashboard] Revoke VP license error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to revoke license: " . $e->getMessage(),
                'type' => 'danger'
            ];
        }
    }
    header('Location: ?page=licenses&view=vendpulse_generator');
    exit;
}

// Action: Reactivate VendPulse Offline License
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reactivate_vp_license') {
    $licId = (int)($_POST['license_id'] ?? 0);
    if ($licId > 0) {
        try {
            $vpService = new VendPulseLicenseService($pdo);
            $vpService->reactivateLicense($licId);
            $_SESSION['flash_feedback'] = [
                'msg' => "VendPulse license #{$licId} reactivated successfully.",
                'type' => 'success'
            ];
        } catch (\Throwable $e) {
            error_log("[Dashboard] Reactivate VP license error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Failed to reactivate license: " . $e->getMessage(),
                'type' => 'danger'
            ];
        }
    }
    header('Location: ?page=licenses&view=vendpulse_generator');
    exit;
}

// Action: Live AJAX Offline License Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_vp_license_ajax') {
    header('Content-Type: application/json; charset=utf-8');
    $bundleInput = trim((string)($_POST['bundle_input'] ?? ''));
    $hwidInput = trim((string)($_POST['hwid_input'] ?? ''));
    try {
        $vpService = new VendPulseLicenseService($pdo);
        $res = $vpService->verifyLicenseBundle($bundleInput, $hwidInput !== '' ? $hwidInput : null);
        echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        echo json_encode(['valid' => false, 'status' => 'error', 'error' => $e->getMessage()]);
    }
    exit;
}

// Action: Publish Release Package (.zip) & Register Version in central_releases (C2)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_release') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $version = trim((string)($_POST['version'] ?? ''));
    $changelog = trim((string)($_POST['changelog'] ?? ''));
    $isActive = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;

    if ($productId > 0 && $version !== '' && isset($_FILES['package_file']) && $_FILES['package_file']['error'] === UPLOAD_ERR_OK) {
        $origName = basename($_FILES['package_file']['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $tmpFile = $_FILES['package_file']['tmp_name'];

        // Validate genuine MIME type via finfo (C2)
        $mime = '';
        if (class_exists(\finfo::class)) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($tmpFile);
        } elseif (function_exists('mime_content_type')) {
            $mime = (string)mime_content_type($tmpFile);
        }

        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
        $isValidZip = in_array($mime, $allowedMimes, true) && $ext === 'zip';

        // Additional integrity check via ZipArchive
        if ($isValidZip && class_exists('ZipArchive')) {
            $za = new \ZipArchive();
            if ($za->open($tmpFile) === true) {
                $za->close();
            } else {
                $isValidZip = false;
            }
        }

        if (!$isValidZip) {
            $feedbackMsg = "Invalid package format. Only verified .zip archives are accepted (detected MIME: " . htmlspecialchars($mime ?: 'unknown') . ").";
            $feedbackType = 'danger';
        } else {
            $targetDir = dirname(__DIR__) . '/public/downloads/updates';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $cleanFilename = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
            $destPath = $targetDir . '/' . $cleanFilename;

            if (move_uploaded_file($tmpFile, $destPath)) {
                $checksum = hash_file('sha256', $destPath);
                try {
                    $rel = $service->publishRelease($productId, $version, $changelog, $cleanFilename, $checksum, $isActive);
                    $feedbackMsg = "Release <strong>v" . htmlspecialchars($version) . "</strong> published successfully! Stored at <code>/public/downloads/updates/" . htmlspecialchars($cleanFilename) . "</code> (SHA-256: <code>" . htmlspecialchars($checksum) . "</code>).";
                    $feedbackType = 'success';
                } catch (\Throwable $e) {
                    error_log("[Dashboard] Publish release error: " . $e->getMessage());
                    $feedbackMsg = "Database error registering release. Please check logs for details.";
                    $feedbackType = 'danger';
                }
            } else {
                $feedbackMsg = "Failed to copy uploaded .zip package into /downloads/updates/";
                $feedbackType = 'danger';
            }
        }
    } else {
        $uploadErr = $_FILES['package_file']['error'] ?? 'No file chosen';
        $feedbackMsg = "Please specify a product, version, and select a valid .zip archive file (Upload error: {$uploadErr}).";
        $feedbackType = 'danger';
    }
}

// Action: Register New Client Deployment (Hub Token)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_deploy') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $clientName = trim((string)($_POST['client_name'] ?? ''));
    $domain = trim((string)($_POST['deployment_domain'] ?? ''));
    $env = trim((string)($_POST['environment'] ?? 'production'));

    if (!FleetService::isValidPublicDomain($domain)) {
        $feedbackMsg = "Invalid deployment domain '{$domain}'. Must be a valid public hostname (raw IPs, private networks, and localhost are rejected).";
        $feedbackType = 'danger';
    } elseif (!empty($clientName) && !empty($domain) && $productId > 0) {
        try {
            $newDep = $service->registerDeployment($productId, $clientName, $domain, $env);
            $feedbackMsg = "Deployment registered! Generated Ingestion Token: <code>" . htmlspecialchars($newDep['token']) . "</code>";
            $feedbackType = 'success';
        } catch (\Throwable $e) {
            error_log("[Dashboard] Register deploy error: " . $e->getMessage());
            $feedbackMsg = "Failed to register deployment. Please verify domain name and try again.";
            $feedbackType = 'danger';
        }
    }
}

// --- 1. ACTION: Publish Hotfix / Patch ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_patch') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $version   = trim($_POST['version'] ?? '');
    $changelog = trim($_POST['changelog'] ?? '');
    
    if ($productId > 0 && !empty($version) && isset($_FILES['patch_zip']) && $_FILES['patch_zip']['error'] === UPLOAD_ERR_OK) {
        $tmpPath = $_FILES['patch_zip']['tmp_name'];
        $mime = class_exists(\finfo::class)
            ? (string)(new \finfo(FILEINFO_MIME_TYPE))->file($tmpPath)
            : (function_exists('mime_content_type') ? (string)mime_content_type($tmpPath) : '');
        $isValidZip = in_array($mime, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'], true);
        if ($isValidZip && class_exists(\ZipArchive::class)) {
            $archive = new \ZipArchive();
            $isValidZip = $archive->open($tmpPath) === true;
            if ($isValidZip) {
                $archive->close();
            }
        }
        if (!$isValidZip) {
            $feedbackMsg = 'Invalid hotfix package. Upload a valid ZIP archive.';
            $feedbackType = 'danger';
        } else {
        $uploadDir = is_dir(__DIR__ . '/downloads/updates') 
            ? __DIR__ . '/downloads/updates' 
            : (is_dir(dirname(__DIR__) . '/public/downloads/updates') ? dirname(__DIR__) . '/public/downloads/updates' : __DIR__ . '/downloads/updates');
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $safeName = 'patch_' . $productId . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $version) . '_' . time() . '.zip';
        $destPath = $uploadDir . '/' . $safeName;

        if (move_uploaded_file($tmpPath, $destPath)) {
            $checksum = hash_file('sha256', $destPath);

            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $service->publishRelease($productId, $version, $changelog, $safeName, $checksum, true);
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO central_releases (product_id, version, changelog, package_filename, package_checksum, is_active, released_at)
                    VALUES (?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE 
                        changelog = VALUES(changelog),
                        package_filename = VALUES(package_filename),
                        package_checksum = VALUES(package_checksum),
                        is_active = 1,
                        released_at = UTC_TIMESTAMP()
                ");
                $ins->execute([$productId, $version, $changelog, $safeName, $checksum]);
                $pdo->prepare("UPDATE central_products SET latest_version = ? WHERE id = ?")->execute([$version, $productId]);
            }
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?status=patch_published');
            exit;
        }
        }
    }
}

// --- 2. ACTION: Quick Ping / Heartbeat Resync ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_deploy') {
    $dId = (int)($_POST['deployment_id'] ?? 0);
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $now = ($driver === 'sqlite') ? "datetime('now')" : "UTC_TIMESTAMP()";
    $upd = $pdo->prepare("UPDATE central_deployments SET last_heartbeat_at = {$now}, status = 'healthy' WHERE id = ?");
    $upd->execute([$dId]);
    $retUrl = strtok($_SERVER['REQUEST_URI'] ?? 'dashboard.php', '?');
    $q = [];
    if (!empty($_POST['page'])) $q['page'] = $_POST['page'];
    if (!empty($_POST['product_id'])) $q['product_id'] = (int)$_POST['product_id'];
    if (!empty($_POST['deployment_id'])) $q['deployment_id'] = (int)$_POST['deployment_id'];
    if (!empty($q)) $retUrl .= '?' . http_build_query($q);
    header('Location: ' . $retUrl);
    exit;
}

// Action: Register New Software Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_product') {
    $slug = trim((string)($_POST['product_slug'] ?? ''));
    $code = strtoupper(trim((string)($_POST['product_code'] ?? '')));
    $name = trim((string)($_POST['product_name'] ?? ''));
    $desc = trim((string)($_POST['description'] ?? ''));
    $repo = trim((string)($_POST['repository_url'] ?? ''));

    if (empty($code)) {
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $slug), 0, 6));
        if ($code === '') $code = 'APP';
    }

    if (!empty($slug) && !empty($name)) {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $timeFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
        $ins = $pdo->prepare("INSERT INTO central_products (product_slug, product_code, product_name, description, repository_url, created_at) VALUES (?, ?, ?, ?, ?, {$timeFunc})");
        $ins->execute([$slug, $code, $name, $desc, $repo]);
        $feedbackMsg = "Software product '{$name}' (Code: {$code}) registered successfully!";
        $feedbackType = 'success';
    }
}

// Action: Clear/Delete or Resolve Error Log (Converted from GET to POST for CSRF safety) (H1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['delete_error', 'resolve_error', 'dismiss_error'], true)) {
    $errId = (int)($_POST['error_id'] ?? 0);
    $act = $_POST['action'];
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $nowExpr = ($driver === 'sqlite') ? "datetime('now')" : "UTC_TIMESTAMP()";

    if ($act === 'run_error_lifecycle') {
        $res = $service->lifecycleErrorLogs(30, 60);
        $_SESSION['flash_feedback'] = [
            'msg' => "Retention sweep complete: <strong>{$res['archived_count']}</strong> incident(s) archived (&gt;30d) and <strong>{$res['deleted_count']}</strong> incident(s) permanently purged (&gt;60d).",
            'type' => 'info'
        ];
        header('Location: ?page=errors');
        exit;
    }

    if ($errId > 0) {
        if ($act === 'resolve_error') {
            $stmt = $pdo->prepare("UPDATE central_error_logs SET status = 'resolved', resolved = 1, resolved_at = {$nowExpr} WHERE id = ?");
            $stmt->execute([$errId]);
            $_SESSION['flash_feedback'] = [
                'msg' => "Error log incident #{$errId} marked as <strong>Resolved</strong>.",
                'type' => 'success'
            ];
        } else {
            $mode = $_POST['mode'] ?? 'delete';
            if ($mode === 'archive') {
                $stmt = $pdo->prepare("UPDATE central_error_logs SET status = 'archived', resolved = 1, resolved_at = COALESCE(resolved_at, {$nowExpr}) WHERE id = ?");
                $stmt->execute([$errId]);
                $_SESSION['flash_feedback'] = [
                    'msg' => "Error log incident #{$errId} dismissed and archived.",
                    'type' => 'success'
                ];
            } else {
                $del = $pdo->prepare("DELETE FROM central_error_logs WHERE id = ?");
                $del->execute([$errId]);
                $_SESSION['flash_feedback'] = [
                    'msg' => "Error log incident #{$errId} permanently deleted.",
                    'type' => 'success'
                ];
            }
        }
    }
    $retUrl = !empty($_POST['return_url']) ? $_POST['return_url'] : '?page=errors';
    header('Location: ' . $retUrl);
    exit;
}

// Action: Update Support Ticket Status (Converted from GET to POST for CSRF safety) (H1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_ticket') {
    $ticketId = (int)($_POST['ticket_id'] ?? 0);
    $newStatus = strtolower(trim((string)($_POST['status'] ?? 'resolved')));
    if ($ticketId > 0) {
        $res = $service->updateTicketStatus($ticketId, $newStatus);
        if ($res['success']) {
            $statusLabel = strtoupper($res['status']);
            $syncNote = !empty($res['client_synced'])
                ? " (Synchronized with client <strong>" . htmlspecialchars($res['client_name']) . "</strong> via {$res['sync_method']})"
                : "";
            $_SESSION['flash_feedback'] = [
                'msg' => "Ticket <code>#" . htmlspecialchars($res['ticket_reference']) . "</code> status marked as <strong>{$statusLabel}</strong>{$syncNote}.",
                'type' => ($newStatus === 'resolved' ? 'success' : 'info')
            ];
        } else {
            $_SESSION['flash_feedback'] = [
                'msg' => "Error updating ticket: " . htmlspecialchars($res['error'] ?? 'Unknown error'),
                'type' => 'danger'
            ];
        }
    }
    $retPage = $_POST['page'] ?? 'tickets';
    $retUrl = '?page=' . urlencode((string)$retPage);
    if (!empty($_POST['product_id'])) {
        $retUrl .= '&product_id=' . (int)$_POST['product_id'];
    }
    if (!empty($_POST['deployment_id'])) {
        $retUrl .= '&deployment_id=' . (int)$_POST['deployment_id'] . '#deployment-feed';
    }
    header('Location: ' . $retUrl);
    exit;
}

// Action: Delete / Remove Monitored Deployment (Converted from GET to POST for CSRF safety) (H1)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_deployment') {
    $depId = (int)($_POST['deployment_id'] ?? 0);
    if ($depId > 0) {
        try {
            $fetchStmt = $pdo->prepare("SELECT client_name, deployment_domain FROM central_deployments WHERE id = ?");
            $fetchStmt->execute([$depId]);
            $toDelete = $fetchStmt->fetch();

            if ($toDelete) {
                $deleted = $service->deleteDeployment($depId);

                if ($deleted) {
                    $_SESSION['flash_feedback'] = [
                        'msg' => "Deployment <strong>" . htmlspecialchars($toDelete['client_name']) . "</strong> (" . htmlspecialchars($toDelete['deployment_domain']) . ") has been permanently removed.",
                        'type' => 'success'
                    ];
                } else {
                    $_SESSION['flash_feedback'] = [
                        'msg' => "Deployment record was not modified. It may have already been removed.",
                        'type' => 'warning'
                    ];
                }
            } else {
                $_SESSION['flash_feedback'] = [
                    'msg' => "Deployment #{$depId} not found or has already been removed.",
                    'type' => 'warning'
                ];
            }
        } catch (\Throwable $e) {
            error_log("[Dashboard] Delete deployment error: " . $e->getMessage());
            $_SESSION['flash_feedback'] = [
                'msg' => "Error deleting deployment: " . htmlspecialchars($e->getMessage()),
                'type' => 'danger'
            ];
        }
    } else {
        $_SESSION['flash_feedback'] = [
            'msg' => "Invalid deployment ID specified.",
            'type' => 'danger'
        ];
    }
    $retPage = $_POST['page'] ?? 'overview';
    header('Location: ?page=' . urlencode((string)$retPage));
    exit;
}

if (!empty($_SESSION['flash_feedback'])) {
    $feedbackMsg = $_SESSION['flash_feedback']['msg'];
    $feedbackType = $_SESSION['flash_feedback']['type'];
    unset($_SESSION['flash_feedback']);
} elseif (isset($_GET['status']) && $_GET['status'] === 'patch_published') {
    $feedbackMsg = "Hotfix patch published and distributed to fleet.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'patch_approved') {
    $feedbackMsg = "AI patch approved and published as an OTA release.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'deployment_deleted') {
    $feedbackMsg = "Deployment and its heartbeat history have been permanently deleted.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'error_deleted') {
    $feedbackMsg = "Error log trace has been permanently deleted.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'resolved_errors_purged') {
    $feedbackMsg = "All resolved error logs have been purged from the database.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'ticket_deleted') {
    $feedbackMsg = "Support ticket has been permanently deleted.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'release_deleted') {
    $feedbackMsg = "Release record and physical package file have been permanently removed.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'product_deleted') {
    $feedbackMsg = "Product, source codebase directory, and all associated fleet data have been permanently deleted.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'license_deleted') {
    $feedbackMsg = "License key permanently removed from registry.";
    $feedbackType = 'success';
} elseif (isset($_GET['status']) && $_GET['status'] === 'user_deleted') {
    $feedbackMsg = "Administrator user account permanently deleted.";
    $feedbackType = 'success';
}

// Action: Register New User
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_user') {
    $name = trim((string)($_POST['user_name'] ?? ''));
    $email = trim((string)($_POST['user_email'] ?? ''));
    $role = ($_POST['user_role'] ?? 'viewer') === 'admin' ? 'admin' : 'viewer';
    $password = (string)($_POST['user_password'] ?? '');

    if (!empty($name) && !empty($email) && !empty($password)) {
        $check = $pdo->prepare("SELECT id FROM central_users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $feedbackMsg = "A user account with email '{$email}' already exists.";
            $feedbackType = 'danger';
        } elseif (strlen($password) < 8) {
            $feedbackMsg = "Password must be at least 8 characters long.";
            $feedbackType = 'danger';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $ins = $pdo->prepare("INSERT INTO central_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)");
            $ins->execute([$name, $email, $hash, $role]);
            $feedbackMsg = "User account '{$email}' created successfully!";
            $feedbackType = 'success';
        }
    }
}

// Action: Update Existing User Profile & Role
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $name = trim((string)($_POST['user_name'] ?? ''));
    $email = trim((string)($_POST['user_email'] ?? ''));
    $role = ($_POST['user_role'] ?? 'viewer') === 'admin' ? 'admin' : 'viewer';

    if ($userId > 0 && !empty($name) && !empty($email)) {
        $check = $pdo->prepare("SELECT id FROM central_users WHERE email = ? AND id != ?");
        $check->execute([$email, $userId]);
        if ($check->fetch()) {
            $feedbackMsg = "Email '{$email}' is already registered to another operator.";
            $feedbackType = 'danger';
        } else {
            // Prevent revoking admin from the last remaining admin
            $targetUser = $pdo->query("SELECT role FROM central_users WHERE id = {$userId}")->fetch();
            if ($targetUser && $targetUser['role'] === 'admin' && $role !== 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM central_users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $feedbackMsg = "Action blocked: Cannot revoke administrator role from the only remaining admin.";
                    $feedbackType = 'danger';
                } else {
                    $upd = $pdo->prepare("UPDATE central_users SET name = ?, email = ?, role = ? WHERE id = ?");
                    $upd->execute([$name, $email, $role, $userId]);
                    $feedbackMsg = "Operator account '{$email}' updated successfully.";
                    $feedbackType = 'success';
                }
            } else {
                $upd = $pdo->prepare("UPDATE central_users SET name = ?, email = ?, role = ? WHERE id = ?");
                $upd->execute([$name, $email, $role, $userId]);
                $feedbackMsg = "Operator account '{$email}' updated successfully.";
                $feedbackType = 'success';
            }
        }
    }
}

// Action: Reset Operator Password
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_user_password') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $newPass = (string)($_POST['new_password'] ?? '');

    if ($userId > 0 && strlen($newPass) >= 8) {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $upd = $pdo->prepare("UPDATE central_users SET password_hash = ? WHERE id = ?");
        $upd->execute([$hash, $userId]);
        $feedbackMsg = "Password updated successfully.";
        $feedbackType = 'success';
    } else {
        $feedbackMsg = "Password must be at least 8 characters long.";
        $feedbackType = 'danger';
    }
}

// Action: Delete Operator Account
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $currentUserId = (int)($_SESSION['fleet_user_id'] ?? 0);

    if ($userId === $currentUserId) {
        $feedbackMsg = "Security policy violation: You cannot delete your own active administrator account.";
        $feedbackType = 'danger';
    } elseif ($userId > 0) {
        $targetUser = $pdo->query("SELECT role, email FROM central_users WHERE id = {$userId}")->fetch();
        if ($targetUser) {
            if ($targetUser['role'] === 'admin') {
                $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM central_users WHERE role = 'admin'")->fetchColumn();
                if ($adminCount <= 1) {
                    $feedbackMsg = "Cannot delete the only remaining administrator account.";
                    $feedbackType = 'danger';
                } else {
                    $del = $pdo->prepare("DELETE FROM central_users WHERE id = ?");
                    $del->execute([$userId]);
                    $feedbackMsg = "Operator account '{$targetUser['email']}' deleted permanently.";
                    $feedbackType = 'success';
                }
            } else {
                $del = $pdo->prepare("DELETE FROM central_users WHERE id = ?");
                $del->execute([$userId]);
                $feedbackMsg = "Operator account '{$targetUser['email']}' deleted permanently.";
                $feedbackType = 'success';
            }
        }
    }
}

// Action: Sweep Stale Nodes & Trigger Autonomous AI Self-Healing Engine
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sweep_nodes') {
    try {
        $report = $service->runFleetMonitorCheck();
        $unreachable = (int)($report['unreachable_marked'] ?? 0);
        $pruned = (int)($report['heartbeats_pruned'] ?? $report['pruned_heartbeats'] ?? 0);

        // Trigger the Autonomous AI Hotfix & Self-Healing Dispatch Engine for any pending fleet error incidents
        $healingDaemon = dirname(__DIR__) . '/bin/autonomous-healing-daemon.php';
        $phpBin = PHP_BINARY ?: 'php';
        $dispatchedHealing = 0;

        if (file_exists($healingDaemon)) {
            // Count pending unresolved unhandled errors eligible for remediation
            $checkStmt = $pdo->query("
                SELECT COUNT(*) FROM central_error_logs
                WHERE (resolved = 0 OR resolved IS NULL)
                  AND (status IN ('open', 'analyzing') OR status IS NULL)
                  AND (manual_intervention_required = 0 OR manual_intervention_required IS NULL)
                  AND error_level IN ('FATAL', 'ERROR', 'CRITICAL')
            ");
            $dispatchedHealing = (int)($checkStmt ? $checkStmt->fetchColumn() : 0);

            if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
                pclose(popen("start /B \"\" \"{$phpBin}\" \"{$healingDaemon}\" --sweep-all > NUL 2>&1", "r"));
            } else {
                exec("\"{$phpBin}\" \"{$healingDaemon}\" --sweep-all > /dev/null 2>&1 &");
            }
        }

        $healingNote = ($dispatchedHealing > 0)
            ? " Autonomous self-healing triggered ({$dispatchedHealing} incident workers active)."
            : " Autonomous AI self-healing daemon verified active.";

        $feedbackMsg = "Sweep complete: {$unreachable} nodes marked unreachable, {$pruned} old heartbeats pruned.{$healingNote}";
        $feedbackType = 'success';
    } catch (\Throwable $e) {
        error_log("[Sweep Nodes Error] " . $e->getMessage());
        $feedbackMsg = "An internal error occurred during node sweep. Check server logs.";
        $feedbackType = 'danger';
    }
}

// --- 3. DATA AGGREGATION & ANALYTICS ---
$activePage = $_GET['page'] ?? 'overview';
$licenseView = $_GET['view'] ?? '';
if ($activePage === 'vendpulse_generator' || $activePage === 'vendpulse_license') {
    $activePage = 'licenses';
    $licenseView = 'vendpulse_generator';
}
$isVendPulseView = ($activePage === 'licenses' && $licenseView === 'vendpulse_generator');

$filterStatus = $_GET['status'] ?? 'all';
$filterProduct = (int)($_GET['product_id'] ?? 0);
$searchQuery = trim((string)($_GET['search'] ?? ''));

// Check if a specific system is selected
$selectedProductId = isset($_GET['product_id']) && (int)$_GET['product_id'] > 0 ? (int)$_GET['product_id'] : null;
$selectedProduct = null;

if ($selectedProductId) {
    $stmtProd = $pdo->prepare("SELECT * FROM central_products WHERE id = ?");
    $stmtProd->execute([$selectedProductId]);
    $selectedProduct = $stmtProd->fetch();
}

// Products list
$products = $pdo->query("SELECT * FROM central_products ORDER BY product_name ASC")->fetchAll();

// Published Releases
$releases = $service->getReleases();

// Scoped Deployment Query for deployment detail view & embedded overview feed (M1, M4)
$scopedDeploymentId = isset($_GET['deployment_id']) ? (int)$_GET['deployment_id'] : (int)($_GET['id'] ?? 0);
$scopedDeployment = null;
$scopedTickets = [];
$scopedErrors = [];

// If on overview with a product filter and no specific deployment requested, auto-resolve to product's first deployment
if ($scopedDeploymentId === 0 && $selectedProductId > 0) {
    $stmtFindDep = $pdo->prepare("SELECT id FROM central_deployments WHERE product_id = ? ORDER BY id ASC LIMIT 1");
    $stmtFindDep->execute([$selectedProductId]);
    $foundDepId = $stmtFindDep->fetchColumn();
    if ($foundDepId) {
        $scopedDeploymentId = (int)$foundDepId;
    }
}

if ($scopedDeploymentId > 0 && ($activePage === 'deployment' || in_array($activePage, ['overview', 'deployments', 'fleet', 'metrics']))) {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $daysRemainingExpr = ($driver === 'sqlite')
        ? "CAST(julianday(l.expires_at) - julianday('now') AS INTEGER)"
        : "TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), l.expires_at)";

    // 2. Fetch deployment metadata
    $stmtDeploy = $pdo->prepare("
        SELECT d.*, 
               p.product_name, p.product_slug, p.product_code,
               l.id AS license_id,
               l.license_key,
               l.client_name AS license_client_name,
               l.client_email AS license_client_email,
               l.license_type,
               l.status AS license_status,
               l.issued_at,
               l.expires_at,
               {$daysRemainingExpr} AS days_remaining
        FROM central_deployments d
        JOIN central_products p ON d.product_id = p.id
        LEFT JOIN central_licenses l ON d.license_id = l.id
        WHERE d.id = ?
    ");
    $stmtDeploy->execute([$scopedDeploymentId]);
    $scopedDeployment = $stmtDeploy->fetch();

    if ($scopedDeployment) {
        // License quick action handlers directly on this view:
        if (isset($_GET['set_license_status']) && !empty($scopedDeployment['license_id'])) {
            $newStatus = in_array($_GET['set_license_status'], ['active', 'suspended', 'revoked']) ? $_GET['set_license_status'] : 'active';
            
            // Update license state
            $updLic = $pdo->prepare("UPDATE central_licenses SET status = ? WHERE id = ?");
            $updLic->execute([$newStatus, $scopedDeployment['license_id']]);

            // Force deployment warning if suspended or revoked
            $depStatus = ($newStatus === 'active') ? 'healthy' : 'warning';
            $updDep = $pdo->prepare("UPDATE central_deployments SET status = ? WHERE id = ?");
            $updDep->execute([$depStatus, $scopedDeploymentId]);

            $redir = "dashboard.php?page=" . urlencode($activePage);
            if ($filterProduct > 0 || !empty($scopedDeployment['product_id'])) {
                $redir .= "&product_id=" . ($filterProduct ?: (int)$scopedDeployment['product_id']);
            }
            $redir .= "&deployment_id={$scopedDeploymentId}&status=license_updated#deployment-feed";
            header("Location: " . $redir);
            exit;
        }

        // 3. Fetch Support Tickets for this deployment
        $stmtTickets = $pdo->prepare("
            SELECT * FROM central_support_tickets 
            WHERE deployment_id = ? 
            ORDER BY created_at DESC
        ");
        $stmtTickets->execute([$scopedDeploymentId]);
        $scopedTickets = $stmtTickets->fetchAll();

        // 4. FIX: Fetch Telemetry Error Logs strictly for THIS specific deployment
        $stmtErrors = $pdo->prepare("
            SELECT * FROM central_error_logs 
            WHERE deployment_id = ? 
            ORDER BY occurred_at DESC
            LIMIT 100
        ");
        $stmtErrors->execute([$scopedDeploymentId]);
        $scopedErrors = $stmtErrors->fetchAll();
    }
}

// Deployments with denormalized telemetry metrics and explicit column whitelist (M2, M5)
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$diffExpr = ($driver === 'sqlite') 
    ? "CAST((julianday('now') - julianday(d.last_heartbeat_at)) * 1440 AS INTEGER)" 
    : "TIMESTAMPDIFF(MINUTE, d.last_heartbeat_at, UTC_TIMESTAMP())";

$sqlDeployments = "
    SELECT d.id, d.product_id, d.client_name, d.deployment_domain, d.environment,
           d.token_preview, d.current_version, d.php_version, d.mysql_version, d.os_info,
           d.server_ip, d.status, d.status_reason, d.last_heartbeat_at,
           d.memory_usage_mb, d.db_latency_ms, d.storage_free_gb, d.active_users_24h, d.load_avg,
           d.last_heartbeat_at AS heartbeat_recorded_at,
           {$diffExpr} AS minutes_since_hb_db,
           d.paired_at, d.is_active, d.created_at, d.updated_at,
           p.product_name, p.product_slug, p.product_code, p.latest_version AS product_latest_version,
           (SELECT COUNT(*) FROM central_error_logs e WHERE e.deployment_id = d.id AND e.resolved = 0) AS error_count
    FROM central_deployments d
    JOIN central_products p ON d.product_id = p.id
    WHERE 1=1
";

$params = [];
if ($filterProduct > 0) {
    $sqlDeployments .= " AND d.product_id = :filter_product";
    $params[':filter_product'] = $filterProduct;
}
if ($searchQuery !== '') {
    $sqlDeployments .= " AND (d.client_name LIKE :search OR d.deployment_domain LIKE :search OR d.server_ip LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

$sqlDeployments .= " ORDER BY 
    CASE d.status WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 WHEN 'unreachable' THEN 3 ELSE 4 END ASC,
    d.last_heartbeat_at DESC, d.created_at DESC
";

$stmtDep = $pdo->prepare($sqlDeployments);
$stmtDep->execute($params);
$deployments = $stmtDep->fetchAll();

// Recent Critical & Fatal Errors with Retention Lifecycle & OTA Reconciliation
if ($activePage === 'errors' || $activePage === 'overview') {
    try {
        $service->reconcilePatchPendingErrors();
        $service->lifecycleErrorLogs(30, 60);
    } catch (\Throwable $e) {
        error_log("[Dashboard] Auto error lifecycle error: " . $e->getMessage());
    }
}

$filterErrorStatus = strtolower(trim((string)($_GET['error_status'] ?? 'all')));
$errWhere = "WHERE 1=1";
$errParams = [];

if ($selectedProductId) {
    $errWhere .= " AND p.id = ?";
    $errParams[] = $selectedProductId;
}

if ($filterErrorStatus !== 'all' && in_array($filterErrorStatus, ['open', 'patch_pending', 'resolved', 'archived'], true)) {
    $errWhere .= " AND e.status = ?";
    $errParams[] = $filterErrorStatus;
}

$stmtErr = $pdo->prepare("
    SELECT e.*, d.product_id, d.client_name, d.deployment_domain, p.product_name
    FROM central_error_logs e
    JOIN central_deployments d ON e.deployment_id = d.id
    JOIN central_products p ON d.product_id = p.id
    {$errWhere}
    ORDER BY e.last_seen_at DESC
    LIMIT 100
");
$stmtErr->execute($errParams);
$recentErrors = $stmtErr->fetchAll();

// Support Tickets Query with Status Filtering
$filterTicketStatus = strtolower(trim((string)($_GET['ticket_status'] ?? 'all')));
$ticketStatusClause = ($activePage === 'overview') ? "WHERE t.status IN ('open', 'in_progress')" : "";
if ($activePage === 'tickets' && $filterTicketStatus !== 'all') {
    if (in_array($filterTicketStatus, ['open', 'resolved', 'closed'], true)) {
        $ticketStatusClause = "WHERE t.status = " . $pdo->quote($filterTicketStatus);
    }
}

$openTickets = [];
try {
    if ($selectedProductId) {
        $stmtTickets = $pdo->prepare("
            SELECT t.*, d.client_name, d.deployment_domain, p.product_name
            FROM central_support_tickets t
            JOIN central_deployments d ON t.deployment_id = d.id
            JOIN central_products p ON d.product_id = p.id
            WHERE p.id = ? AND t.status IN ('open', 'in_progress')
            ORDER BY 
                CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END, 
                t.created_at DESC
        ");
        $stmtTickets->execute([$selectedProductId]);
        $openTickets = $stmtTickets->fetchAll();
    } else {
        $openTickets = $pdo->query("
            SELECT t.*, d.client_name, d.deployment_domain, p.product_name
            FROM central_support_tickets t
            JOIN central_deployments d ON t.deployment_id = d.id
            JOIN central_products p ON d.product_id = p.id
            {$ticketStatusClause}
            ORDER BY 
                CASE t.status WHEN 'open' THEN 1 WHEN 'in_progress' THEN 2 WHEN 'resolved' THEN 3 ELSE 4 END ASC,
                CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END, 
                t.created_at DESC
        ")->fetchAll();
    }
} catch (\Throwable $e) {}

// Collapsed single GROUP BY query for ticket statistics (M4)
$openTicketsCount = 0;
$resolvedTicketsCount = 0;
$closedTicketsCount = 0;
try {
    $ticketCounts = $pdo->query("SELECT status, COUNT(*) as cnt FROM central_support_tickets GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
    $openTicketsCount = (int)($ticketCounts['open'] ?? 0) + (int)($ticketCounts['in_progress'] ?? 0);
    $resolvedTicketsCount = (int)($ticketCounts['resolved'] ?? 0);
    $closedTicketsCount = (int)($ticketCounts['closed'] ?? 0);
} catch (\Throwable $e) {}

// Users List
$usersList = [];
if ($isAdmin) {
    $usersList = $pdo->query("SELECT id, name, email, role, created_at FROM central_users ORDER BY name ASC")->fetchAll();
}

// Licenses List
$licensesList = [];
try {
    require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
    $licMgr = new LicenseManager($pdo);
    $licensesList = $licMgr->listLicenses($filterProduct > 0 ? $filterProduct : null);
} catch (\Throwable $e) {
    error_log("[Dashboard] License query error: " . $e->getMessage());
}

// VendPulse Offline Cryptographic Licenses List & Stats
$vpLicensesList = [];
$vpStats = ['total' => 0, 'active' => 0, 'revoked' => 0, 'expired' => 0, 'perpetual' => 0, 'tiers' => []];
$vpService = new VendPulseLicenseService($pdo);
try {
    $vpSearch = trim((string)($_GET['vp_search'] ?? ''));
    $vpTier = trim((string)($_GET['vp_tier'] ?? ''));
    $vpMode = trim((string)($_GET['vp_mode'] ?? ''));
    $vpStatus = trim((string)($_GET['vp_status'] ?? ''));
    $vpLicensesList = $vpService->listLicenses(
        $vpSearch !== '' ? $vpSearch : null,
        $vpTier !== '' ? $vpTier : null,
        $vpStatus !== '' ? $vpStatus : null,
        $vpMode !== '' ? $vpMode : null
    );
    $vpStats = $vpService->getStatistics();
} catch (\Throwable $e) {
    error_log("[Dashboard] VendPulse license query error: " . $e->getMessage());
}

$vpPublicKey = '';
try {
    $vpPublicKey = $vpService->getPublicKey();
} catch (\Throwable $e) {
    error_log("[Dashboard] VendPulse public key load error: " . $e->getMessage());
}

$freshVpLicense = $_SESSION['issued_vp_license'] ?? null;
unset($_SESSION['issued_vp_license']);

// Store Orders Ingress & Self-Service Distribution Stats
$ordersCount = 0;
$ordersList = [];
$orderStats = ['total' => 0, 'completed' => 0, 'downloads' => 0, 'cloud' => 0, 'cloud_deployed' => 0, 'cloud_pending' => 0, 'supermarket' => 0, 'retail' => 0, 'restaurant' => 0, 'offline' => 0];
$dashOrderService = null;
$orderSearch = '';
$orderStatus = '';
$orderMode = '';
$orderFilter = '';

try {
    require_once dirname(__DIR__) . '/src/Services/OrderProvisioningService.php';
    $dashOrderService = new OrderProvisioningService($pdo);
    $ordersCount = (int)$pdo->query("SELECT COUNT(*) FROM central_orders")->fetchColumn();

    if ($activePage === 'orders') {
        $orderSearch = trim((string)($_GET['order_search'] ?? ''));
        $orderStatus = trim((string)($_GET['status'] ?? $_GET['order_status'] ?? ''));
        $orderMode = trim((string)($_GET['mode'] ?? $_GET['order_mode'] ?? ''));
        $orderFilter = trim((string)($_GET['filter'] ?? $_GET['type'] ?? ''));

        $orderStats = $dashOrderService->getOrderStatistics();
        $ordersList = $dashOrderService->listOrders(
            $orderSearch !== '' ? $orderSearch : null,
            $orderStatus !== '' ? $orderStatus : null,
            $orderMode !== '' ? $orderMode : null,
            $orderFilter !== '' ? $orderFilter : null,
            150
        );
    }
} catch (\Throwable $e) {
    error_log("[Dashboard] Orders query error: " . $e->getMessage());
}

// --- 4. ADVANCED METRICS ANALYTICS COMPUTATION ---
$totalDeployments = count($deployments);
$healthyCount = 0;
$warningCount = 0;
$criticalCount = 0;
$unreachableCount = 0;
$pairedCount = 0;
$outdatedCount = 0;

$totalActiveUsers = 0;
$totalStorageFree = 0.0;
$totalMemUsage = 0.0;
$totalDbLatency = 0.0;
$nodesWithHeartbeat = 0;

$phpVersions = [];
$osBreakdown = [];
$latencyList = [];
$memoryList = [];

$now = time();
foreach ($deployments as &$d) {
    if ($d['last_heartbeat_at'] === null) {
        $d['minutes_since_heartbeat'] = 999999;
    } else {
        if (isset($d['minutes_since_hb_db']) && $d['minutes_since_hb_db'] !== null) {
            $d['minutes_since_heartbeat'] = max(0, (int)$d['minutes_since_hb_db']);
        } else {
            $hbTime = strtotime($d['last_heartbeat_at'] . ' UTC');
            $d['minutes_since_heartbeat'] = max(0, (int)floor(($now - $hbTime) / 60));
        }
    }

    // Computed real-time status with comprehensive severity assignment
    if ($d['last_heartbeat_at'] === null || $d['minutes_since_heartbeat'] > 120) {
        $d['computed_status'] = 'unreachable';
        $unreachableCount++;
    } elseif ($d['status'] === 'critical' || (isset($d['db_latency_ms']) && (float)$d['db_latency_ms'] > 1500) || (isset($d['storage_free_gb']) && (float)$d['storage_free_gb'] > 0 && (float)$d['storage_free_gb'] < 1.0) || (int)($d['error_count'] ?? 0) >= 4) {
        $d['computed_status'] = 'critical';
        $criticalCount++;
    } elseif ((int)($d['error_count'] ?? 0) > 0 || (isset($d['db_latency_ms']) && (float)$d['db_latency_ms'] > 500) || (isset($d['storage_free_gb']) && (float)$d['storage_free_gb'] > 0 && (float)$d['storage_free_gb'] < 3.0) || $d['status'] === 'warning') {
        $d['computed_status'] = 'warning';
        $warningCount++;
    } else {
        $d['computed_status'] = 'healthy';
        $healthyCount++;
    }

    if (!empty($d['paired_at'])) {
        $pairedCount++;
    }

    if (!empty($d['product_latest_version']) && version_compare((string)$d['current_version'], (string)$d['product_latest_version'], '<')) {
        $outdatedCount++;
        $d['is_outdated'] = true;
    } else {
        $d['is_outdated'] = false;
    }

    // Telemetry aggregations
    if (!empty($d['memory_usage_mb']) || !empty($d['db_latency_ms'])) {
        $nodesWithHeartbeat++;
        $totalActiveUsers += (int)($d['active_users_24h'] ?? 0);
        $totalStorageFree += (float)($d['storage_free_gb'] ?? 0);
        $totalMemUsage += (float)($d['memory_usage_mb'] ?? 0);
        $totalDbLatency += (float)($d['db_latency_ms'] ?? 0);

        $latencyList[] = [
            'name' => $d['client_name'],
            'domain' => $d['deployment_domain'],
            'latency' => (float)$d['db_latency_ms'],
            'status' => $d['computed_status']
        ];

        $memoryList[] = [
            'name' => $d['client_name'],
            'domain' => $d['deployment_domain'],
            'memory' => (float)$d['memory_usage_mb'],
            'status' => $d['computed_status']
        ];
    }

    if (!empty($d['php_version'])) {
        $ver = substr($d['php_version'], 0, 3);
        $phpVersions[$ver] = ($phpVersions[$ver] ?? 0) + 1;
    }
    if (!empty($d['os_info'])) {
        $osLower = strtolower($d['os_info']);
        $osTag = str_contains($osLower, 'ubuntu') ? 'Ubuntu'
            : (str_contains($osLower, 'debian') ? 'Debian'
            : (str_contains($osLower, 'windows') ? 'Windows'
            : (str_contains($osLower, 'alpine') ? 'Alpine' : 'Linux')));
        $osBreakdown[$osTag] = ($osBreakdown[$osTag] ?? 0) + 1;
    }
}
unset($d);

$avgLatency = $nodesWithHeartbeat > 0 ? round($totalDbLatency / $nodesWithHeartbeat, 1) : 0;
$avgMemory = $nodesWithHeartbeat > 0 ? round($totalMemUsage / $nodesWithHeartbeat, 1) : 0;
$avgStorage = $nodesWithHeartbeat > 0 ? round($totalStorageFree / $nodesWithHeartbeat, 1) : 0;
$versionAdoptionPct = $totalDeployments > 0 ? round((($totalDeployments - $outdatedCount) / $totalDeployments) * 100) : 100;
$fleetHealthPct = $totalDeployments > 0 ? round(($healthyCount / $totalDeployments) * 100) : 100;

usort($latencyList, fn($a, $b) => $b['latency'] <=> $a['latency']);
$maxLatencyBar = !empty($latencyList) ? max(60.0, max(array_column($latencyList, 'latency'))) : 60.0;
$maxMemoryBar = !empty($memoryList) ? max(256.0, max(array_column($memoryList, 'memory'))) : 256.0;

// Filter deployments for table view if status filter active
$filteredDeployments = $deployments;
if ($filterStatus !== 'all') {
    $filteredDeployments = array_filter($deployments, function($d) use ($filterStatus) {
        if ($filterStatus === 'outdated') {
            return !empty($d['is_outdated']);
        }
        return ($d['computed_status'] ?? '') === $filterStatus;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SpectraOps — Fleet</title>
<link rel="icon" type="image/svg+xml" href="spectraops-icon.svg">
<link rel="alternate icon" type="image/png" href="spectraops-icon.png">
<link rel="shortcut icon" href="favicon.ico">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>
(function() {
  var t = localStorage.getItem('spectraops_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
<script src="spectra-popup.js?v=<?= filemtime(__DIR__ . '/spectra-popup.js') ?>"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/diff2html/bundles/css/diff2html.min.css" />

<!-- Dark and Light mode Diff2Html theme matching SpectraOps -->
<style>
    .d2h-wrapper { background: var(--ink) !important; color: var(--text) !important; font-family: 'JetBrains Mono', monospace !important; font-size: 0.78rem; border-radius: var(--radius); border: 1px solid var(--line); }
    .d2h-file-header { background: var(--panel-2) !important; border-bottom: 1px solid var(--line) !important; padding: 6px 14px; }
    .d2h-file-name { color: var(--accent) !important; font-weight: 600; }
    .d2h-code-side-linenumber { background: var(--panel-2) !important; color: var(--text-faint) !important; border-color: var(--line) !important; font-size: 0.72rem; }
    .d2h-del { background-color: rgba(229, 72, 77, 0.16) !important; border-color: rgba(229, 72, 77, 0.3) !important; color: var(--crit-text) !important; }
    .d2h-ins { background-color: rgba(61, 214, 140, 0.16) !important; border-color: rgba(61, 214, 140, 0.3) !important; color: var(--ok-text) !important; }
    .d2h-code-line del { background-color: rgba(229, 72, 77, 0.38) !important; border-radius: 2px; }
    .d2h-code-line ins { background-color: rgba(61, 214, 140, 0.38) !important; border-radius: 2px; }
    .d2h-code-side-emptyplaceholder { background: var(--ink) !important; border-color: var(--line) !important; }
    .d2h-code-line-ctn { color: var(--text) !important; font-family: 'JetBrains Mono', monospace !important; }
    .diff-container { max-height: 400px; overflow-y: auto; overflow-x: auto; border-radius: var(--radius); border: 1px solid var(--line); background: var(--ink); }
</style>
</head>
<body>

<div class="shell">

  <!-- ================= RAIL ================= -->
  <nav class="rail">
    <a href="?page=overview" class="brand">
      <svg width="22" height="22" viewBox="0 0 54 54">
        <polygon points="6,46 22,10 31,10 15,46" fill="#4FA8E0"/>
        <rect x="23" y="28" width="4.2" height="18" rx="1" fill="#2D5A78"/>
        <rect x="31" y="20" width="4.2" height="26" rx="1" fill="#4FA8E0"/>
        <rect x="39" y="12" width="4.2" height="34" rx="1" fill="#7FC4EE"/>
      </svg>
      <div class="brand-name">Spectra<span>Ops</span></div>
    </a>

    <div class="nav-group-label">Monitor</div>
    <a href="?page=overview" class="nav-item <?= in_array($activePage, ['overview', 'deployments', 'fleet', 'metrics']) ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg>
        Fleet
      </div>
      <?php if ($criticalCount > 0): ?>
        <span class="nav-count crit"><?= $criticalCount ?></span>
      <?php else: ?>
        <span class="nav-count"><?= $totalDeployments ?></span>
      <?php endif; ?>
    </a>
    <a href="?page=errors" class="nav-item <?= $activePage === 'errors' ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01M10.3 3.9 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        Errors
      </div>
      <span class="nav-count <?= count($recentErrors) > 0 ? 'crit' : '' ?>"><?= count($recentErrors) ?></span>
    </a>
    <a href="?page=tickets" class="nav-item <?= $activePage === 'tickets' ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v12H7l-3 3V4Z"/></svg>
        Tickets
      </div>
      <span class="nav-count <?= $openTicketsCount > 0 ? 'warn' : '' ?>"><?= $openTicketsCount ?></span>
    </a>

    <div class="nav-group-label">Licensing & Provisioning</div>
    <a href="?page=licenses" class="nav-item <?= $activePage === 'licenses' ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
        Licenses
      </div>
      <span class="nav-count"><?= count($licensesList) + (int)($vpStats['total'] ?? 0) ?></span>
    </a>
    <a href="?page=orders" class="nav-item <?= $activePage === 'orders' ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        Store Orders
      </div>
      <span class="nav-count"><?= $ordersCount ?></span>
    </a>

    <div class="nav-group-label">Catalog & Releases</div>
    <a href="?page=releases" class="nav-item <?= in_array($activePage, ['releases', 'updates']) ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 17v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"/></svg>
        Releases
      </div>
      <span class="nav-count"><?= count($releases) ?></span>
    </a>
    <a href="?page=products" class="nav-item <?= $activePage === 'products' ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Products
      </div>
      <span class="nav-count"><?= count($products) ?></span>
    </a>

    <?php if ($isAdmin): ?>
    <div class="nav-group-label">Admin</div>
    <a href="?page=users" class="nav-item <?= $activePage === 'users' ? 'active' : '' ?>">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.2"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>
        Users
      </div>
      <span class="nav-count"><?= count($usersList) ?></span>
    </a>
    <?php endif; ?>

    <div class="rail-footer">
      <div class="rail-footer-user">
        <div class="avatar"><?= strtoupper(substr($_SESSION['fleet_user_name'] ?? 'AM', 0, 2)) ?></div>
        <div class="rail-user">
          <strong><?= htmlspecialchars($_SESSION['fleet_user_name'] ?? 'Ali M.') ?></strong>
          <?= htmlspecialchars(ucfirst($_SESSION['fleet_user_role'] ?? 'Admin')) ?>
        </div>
      </div>
      <a href="?logout=1" class="icon-btn danger" title="Lock / Logout">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      </a>
    </div>
  </nav>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <!-- Topbar -->
    <div class="topbar">
      <div class="topbar-title">
        <?php if ($activePage === 'errors'): ?>
          <h1>Errors</h1>
          <p>Real-time application exceptions and deduplicated error stream.</p>
        <?php elseif ($activePage === 'tickets'): ?>
          <h1>Tickets</h1>
          <p>Client support tickets, diagnostic inquiries, and synchronization.</p>
        <?php elseif ($activePage === 'releases'): ?>
          <h1>Releases</h1>
          <p>Cryptographically signed update packages and version rollouts.</p>
        <?php elseif ($activePage === 'products'): ?>
          <h1>Products</h1>
          <p>Catalog of software products managed across the fleet.</p>
        <?php elseif ($activePage === 'licenses'): ?>
          <?php if (!empty($isVendPulseView)): ?>
            <div style="display:flex; align-items:center; gap:8px;">
              <a href="?page=licenses" style="color:var(--text-dim); text-decoration:none; display:flex; align-items:center; gap:4px; font-size:13.5px; font-weight:500;">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"></polyline></svg>
                Licenses
              </a>
              <span style="color:var(--text-faint);">/</span>
              <h1 style="margin:0; font-size:1.55rem; display:inline-flex; align-items:center; gap:8px;">
                <span>VendPulse Generator</span>
                <span class="status-pill ok" style="font-size:11px; vertical-align:middle;"><span class="dot dot-ok"></span>Offline Cryptographic</span>
              </h1>
            </div>
            <p>Provision tamper-proof RSA-2048 offline licenses with System Usage Mode binding for air-gapped POS deployments.</p>
          <?php else: ?>
            <h1>Licenses</h1>
            <p>Dynamic product codes, multi-tier licenses, and deployment bindings.</p>
          <?php endif; ?>
        <?php elseif ($activePage === 'orders'): ?>
          <div style="display:flex; align-items:center; gap:8px;">
            <h1 style="margin:0; font-size:1.55rem; display:inline-flex; align-items:center; gap:8px;">
              <span>Store Orders &amp; Deployments</span>
              <span class="status-pill ok" style="font-size:11px; vertical-align:middle; background:rgba(14,165,233,0.1); border-color:rgba(14,165,233,0.3); color:#38bdf8;">
                <span class="dot" style="background:#0ea5e9;"></span>Cloud &amp; POS Fleet
              </span>
            </h1>
          </div>
          <p>Real-time e-commerce ingress, license fulfillment &amp; live cloud instance telemetry.</p>
        <?php elseif ($activePage === 'users'): ?>
          <h1>Users</h1>
          <p>Console administrator and operator accounts.</p>
        <?php else: ?>
          <h1>Fleet</h1>
          <p>Every client deployment reporting to this hub, live.</p>
        <?php endif; ?>
      </div>
      <div class="topbar-actions">
        <!-- Light / Dark Mode Toggle Switch -->
        <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="Switch Light/Dark mode" aria-label="Switch Theme">
          <span class="theme-toggle-track">
            <span class="theme-toggle-icon sun-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </span>
            <span class="theme-toggle-icon moon-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </span>
            <span class="theme-toggle-thumb"></span>
          </span>
          <span class="theme-toggle-label">Dark</span>
        </button>

        <form method="POST" style="display:inline;">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
          <input type="hidden" name="action" value="sweep_nodes">
          <input type="hidden" name="page" value="<?= htmlspecialchars($activePage) ?>">
          <button type="submit" class="btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
            Sweep stale nodes
          </button>
        </form>
        <button type="button" class="btn btn-warn" onclick="openPatchModal()">
          <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
          Publish Hotfix
        </button>
        <button type="button" class="btn btn-outline" style="border-color: #0dc0e7; color: #bef6fd;" onclick="document.getElementById('codebaseModal') ? document.getElementById('codebaseModal').classList.add('active') : openModal('codebaseModal')">
          📦 Sync Codebase
        </button>
        <button class="btn" onclick="openModal('pairModal')">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
          Pair client
        </button>
        <?php if ($activePage === 'releases'): ?>
          <button class="btn btn-primary" onclick="openModal('releaseModal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
            Publish New Release / Hotfix
          </button>
        <?php elseif ($activePage === 'products'): ?>
          <button class="btn btn-primary" onclick="openModal('regProductModal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
            New product
          </button>
        <?php elseif ($activePage === 'licenses'): ?>
          <?php if (!empty($isVendPulseView)): ?>
            <a href="?page=licenses" class="btn btn-outline" style="text-decoration:none;">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
              Back to Licenses
            </a>
            <button type="button" class="btn btn-outline" style="border-color: #0dc0e7; color: #bef6fd;" onclick="openModal('vpPubKeyModal')">
              🔑 Export Public Key
            </button>
            <button type="button" class="btn btn-outline" style="border-color: #3dd68c; color: #8fefc0;" onclick="openModal('vpVerifyModal')">
              🛡️ Verify License
            </button>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('vpGeneratorCard')?.scrollIntoView({behavior:'smooth'}); document.getElementById('vpClientName')?.focus();">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
              Generate License
            </button>
          <?php else: ?>
            <button class="btn btn-primary" onclick="openModal('licenseModal')">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
              Issue license
            </button>
          <?php endif; ?>
        <?php elseif ($activePage === 'orders'): ?>
          <a href="portal/download.php" target="_blank" class="btn btn-outline" style="text-decoration:none;">
            🌐 Test Portal View
          </a>
          <button type="button" class="btn btn-outline" style="border-color:#0ea5e9; color:#38bdf8;" onclick="openModal('pairModal')">
            🔗 Pair Cloud Node
          </button>
          <button type="button" class="btn btn-primary" onclick="openModal('orderWebhookModal')">
            ⚡ Webhook Ingress Docs
          </button>
        <?php elseif ($activePage === 'users'): ?>
          <button class="btn btn-primary" onclick="openModal('regUserModal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
            New user
          </button>
        <?php else: ?>
          <button class="btn btn-primary" onclick="openModal('regModal')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
            Register deployment
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Status strip -->
    <div class="status-strip">
      <a href="?page=<?= htmlspecialchars($activePage) ?>&status=all<?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="strip-item <?= $filterStatus === 'all' ? 'active' : '' ?>">
        <div class="strip-label"><span class="dot dot-accent"></span>All nodes</div>
        <div class="strip-value"><?= $totalDeployments ?></div>
      </a>
      <a href="?page=<?= htmlspecialchars($activePage) ?>&status=healthy<?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="strip-item <?= $filterStatus === 'healthy' ? 'active' : '' ?>">
        <div class="strip-label"><span class="dot dot-ok"></span>Healthy</div>
        <div class="strip-value ok"><?= $healthyCount ?></div>
      </a>
      <a href="?page=<?= htmlspecialchars($activePage) ?>&status=warning<?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="strip-item <?= $filterStatus === 'warning' ? 'active' : '' ?>">
        <div class="strip-label"><span class="dot dot-warn"></span>Warning</div>
        <div class="strip-value warn"><?= $warningCount ?></div>
      </a>
      <a href="?page=<?= htmlspecialchars($activePage) ?>&status=critical<?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="strip-item <?= $filterStatus === 'critical' ? 'active' : '' ?>">
        <div class="strip-label"><span class="dot dot-crit"></span>Critical</div>
        <div class="strip-value crit"><?= $criticalCount ?></div>
      </a>
      <a href="?page=<?= htmlspecialchars($activePage) ?>&status=unreachable<?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="strip-item <?= $filterStatus === 'unreachable' ? 'active' : '' ?>">
        <div class="strip-label"><span class="dot dot-dead"></span>Unreachable</div>
        <div class="strip-value dead"><?= $unreachableCount ?></div>
      </a>
      <a href="?page=<?= htmlspecialchars($activePage) ?>&status=outdated<?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="strip-item <?= $filterStatus === 'outdated' ? 'active' : '' ?>">
        <div class="strip-label">Outdated builds</div>
        <div class="strip-value"><?= $outdatedCount ?></div>
      </a>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
      <form method="GET" class="search-box">
        <input type="hidden" name="page" value="<?= htmlspecialchars($activePage) ?>">
        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
        <?php if ($filterProduct > 0): ?>
          <input type="hidden" name="product_id" value="<?= $filterProduct ?>">
        <?php endif; ?>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        <input type="text" name="search" placeholder="Search client, domain, or IP" value="<?= htmlspecialchars($searchQuery) ?>" />
      </form>
      
      <div class="chip-row">
        <a href="?page=<?= htmlspecialchars($activePage) ?>&status=<?= htmlspecialchars($filterStatus) ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="chip <?= $filterProduct === 0 ? 'active' : '' ?>">All products</a>
        <?php foreach ($products as $p): ?>
          <a href="?page=<?= htmlspecialchars($activePage) ?>&status=<?= htmlspecialchars($filterStatus) ?>&product_id=<?= (int)$p['id'] ?><?= $searchQuery !== '' ? '&search=' . urlencode($searchQuery) : '' ?>" class="chip <?= $filterProduct === (int)$p['id'] ? 'active' : '' ?>">
            <?= htmlspecialchars($p['product_name']) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="filter-spacer"></div>
      
      <?php if ($activePage === 'errors'): ?>
        <a href="?purge_resolved_errors=1<?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?>" 
           class="btn btn-sm btn-outline" 
           style="color: #10b981; border-color: rgba(16, 185, 129, 0.4); text-decoration: none; margin-right: 12px; display: inline-flex; align-items: center; gap: 4px;"
           onclick="return confirm('Purge all resolved errors from the database?');">
            🧹 Purge Resolved
        </a>
        <div class="result-count"><?= count($recentErrors) ?> incidents</div>
      <?php elseif ($activePage === 'tickets'): ?>
        <div class="result-count"><?= count($openTickets) ?> tickets</div>
      <?php elseif ($activePage === 'releases'): ?>
        <div class="result-count"><?= count($releases) ?> releases</div>
      <?php elseif ($activePage === 'orders'): ?>
        <div class="result-count"><?= count($ordersList) ?> orders</div>
      <?php else: ?>
        <div class="result-count"><?= count($filteredDeployments) ?> deployments</div>
      <?php endif; ?>
    </div>

    <?php if ($feedbackMsg): ?>
      <div class="alert-strip <?= htmlspecialchars($feedbackType) ?>">
        <span><?= strip_tags($feedbackMsg, '<strong><b><code><span><em>') ?></span>
      </div>
      <script>
        document.addEventListener('DOMContentLoaded', function() {
            if (window.SpectraPopup) {
                window.SpectraPopup.show({
                    title: <?= json_encode(
                        in_array($feedbackType, ['crit', 'danger', 'error']) ? 'Action Failed' :
                        ($feedbackType === 'success' ? 'Operation Completed' :
                        (in_array($feedbackType, ['warn', 'warning']) ? 'Attention Required' : 'System Notification'))
                    ) ?>,
                    message: <?= json_encode($feedbackMsg) ?>,
                    type: <?= json_encode(
                        in_array($feedbackType, ['crit', 'danger', 'error']) ? 'error' :
                        (in_array($feedbackType, ['warn', 'warning']) ? 'warning' :
                        ($feedbackType === 'success' ? 'success' : 'info'))
                    ) ?>,
                    html: true,
                    confirmText: 'Dismiss'
                });
            }
        });
      </script>
    <?php endif; ?>

<?php if ($selectedProduct): ?>
    <!-- Context Banner for Selected System -->
    <div class="system-context-banner">
        <div>
            <div class="sub-label">
                <span class="dot dot-accent"></span> Filtered Command View &bull; Active System
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                <h2><?= htmlspecialchars($selectedProduct['product_name']) ?></h2>
                <span class="product-badge" style="background: var(--accent-dim); color: #BFE0F7; border-color: var(--accent); font-weight: 600;">
                    Prefix: <?= htmlspecialchars($selectedProduct['product_code']) ?>
                </span>
                <?php if (!empty($selectedProduct['latest_version'])): ?>
                    <span class="version-tag" style="background: var(--panel-2); border: 1px solid var(--line); padding: 2px 7px; border-radius: 4px; font-size: 11px;">
                        Latest: v<?= htmlspecialchars($selectedProduct['latest_version']) ?>
                    </span>
                <?php endif; ?>
                <span style="font-size: 12px; color: var(--text-dim);">
                    &bull; <?= count($filteredDeployments) ?> node<?= count($filteredDeployments) === 1 ? '' : 's' ?>
                </span>
                <?php if (count($openTickets) > 0): ?>
                    <span class="nav-count warn"><?= count($openTickets) ?> open ticket<?= count($openTickets) === 1 ? '' : 's' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="system-context-actions">
            <button type="button" class="btn btn-primary" onclick="openPatchModal(<?= $selectedProduct['id'] ?>)">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                Publish Patch (<?= htmlspecialchars($selectedProduct['product_code']) ?>)
            </button>
            <a href="dashboard.php" class="btn btn-outline" title="Clear filter and view all fleet nodes">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                View All Systems
            </a>
        </div>
    </div>

    <!-- System-Specific Active Support Tickets Panel -->
    <div class="panel-card" style="margin: 0 24px 20px;">
        <div class="panel-header">
            <h4 class="panel-title">Active Tickets for <?= htmlspecialchars($selectedProduct['product_name']) ?> (<?= count($openTickets) ?>)</h4>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Ref</th>
                    <th>Client / Domain</th>
                    <th>Requester</th>
                    <th>Priority</th>
                    <th>Subject &amp; Issue Details</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($openTickets)): ?>
                    <tr><td colspan="6" style="text-align: center; color: var(--text-muted); padding: 20px;">No open support tickets for this system.</td></tr>
                <?php else: ?>
                    <?php foreach ($openTickets as $t): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($t['ticket_reference']) ?></code></td>
                            <td>
                                <strong><?= htmlspecialchars($t['client_name']) ?></strong><br>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($t['deployment_domain']) ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($t['requester_name']) ?><br>
                                <small style="color: var(--cyan, #0dc0e7);"><?= htmlspecialchars($t['requester_email']) ?></small>
                            </td>
                            <td>
                                <span class="badge badge-<?= in_array($t['priority'], ['urgent', 'high']) ? 'unreachable' : 'warning' ?>">
                                    <?= htmlspecialchars($t['priority']) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($t['subject']) ?></strong>
                                <p style="margin: 4px 0 0; font-size: 0.8rem; color: #cbd5e1;"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
                                <?php if (!empty($t['system_snapshot'])): ?>
                                    <details>
                                        <summary style="cursor:pointer; color:#0dc0e7; font-size:11px;">Diagnostic Snapshot</summary>
                                        <pre style="background:var(--ink, #091830); border:1px solid var(--line, rgba(13,192,231,0.2)); padding:8px; border-radius:4px; font-size:11px; max-height:140px; overflow:auto;"><?= htmlspecialchars(json_encode(json_decode($t['system_snapshot']), JSON_PRETTY_PRINT)) ?></pre>
                                    </details>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;">
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                    <input type="hidden" name="action" value="resolve_ticket">
                                    <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                    <input type="hidden" name="status" value="resolved">
                                    <input type="hidden" name="page" value="tickets">
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                                        Resolve
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

    <!-- Table View -->
    <div class="table-wrap">
      <?php if (in_array($activePage, ['overview', 'deployments', 'fleet', 'metrics'])): ?>
        <table>
          <thead>
            <tr>
              <th style="width:26%">Client</th>
              <th>Product</th>
              <th>Status</th>
              <th>Version</th>
              <th class="num">DB latency</th>
              <th class="num">Disk free</th>
              <th class="num">Users 24h</th>
              <th>Errors</th>
              <th>Last heartbeat</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($filteredDeployments)): ?>
              <tr>
                <td colspan="10" style="text-align: center; color: var(--text-faint); padding: 40px 16px;">
                  No client deployments matching current filters.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($filteredDeployments as $row): 
                $jsonDep = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
                $status = $row['computed_status'] ?? 'healthy';
                $isCrit = ($status === 'critical');
                
                $latVal = $row['db_latency_ms'] !== null ? (float)$row['db_latency_ms'] : null;
                $latClass = '';
                $latText = '—';
                if ($latVal !== null) {
                  $latText = round($latVal) . ' ms';
                  if ($latVal > 1500) $latClass = 'crit';
                  elseif ($latVal > 500) $latClass = 'hot';
                }

                $diskVal = $row['storage_free_gb'] !== null ? (float)$row['storage_free_gb'] : null;
                $diskClass = '';
                $diskText = '—';
                if ($diskVal !== null) {
                  $diskText = round($diskVal, 1) . ' GB';
                  if ($diskVal < 1.0) $diskClass = 'crit';
                  elseif ($diskVal < 3.0) $diskClass = 'hot';
                }

                $usersVal = $row['active_users_24h'] !== null ? (int)$row['active_users_24h'] : null;
                $usersText = $usersVal !== null ? number_format($usersVal) : '—';

                $errCount = (int)($row['error_count'] ?? 0);

                $hbText = 'Never';
                $hbClass = 'hb-dead';
                if ($row['last_heartbeat_at']) {
                  $mins = (int)($row['minutes_since_heartbeat'] ?? 0);
                  if ($mins < 2) {
                    $hbText = 'Just now';
                    $hbClass = 'hb-fresh';
                  } elseif ($mins < 60) {
                    $hbText = $mins . 'm ago';
                    $hbClass = 'hb-fresh';
                  } elseif ($mins < 1440) {
                    $hbText = floor($mins / 60) . 'h ' . ($mins % 60) . 'm ago';
                    $hbClass = 'hb-stale';
                  } else {
                    $hbText = floor($mins / 1440) . 'd ago';
                    $hbClass = 'hb-dead';
                  }
                }
              ?>
                <tr class="<?= $isCrit ? 'flash-crit' : '' ?>" style="<?= (!empty($scopedDeploymentId) && $scopedDeploymentId === (int)$row['id']) ? 'background: rgba(13, 192, 231, 0.08); outline: 1px solid rgba(13, 192, 231, 0.35);' : '' ?>">
                  <td>
                    <div class="client-cell">
                      <a href="?page=overview&product_id=<?= (int)$row['product_id'] ?>&deployment_id=<?= (int)$row['id'] ?>#deployment-feed" class="client-name" style="text-decoration:none; color:inherit;" title="Inspect Deployment Node Feed">
                        <?= htmlspecialchars($row['client_name']) ?> &rarr;
                        <?php if (!empty($scopedDeploymentId) && $scopedDeploymentId === (int)$row['id']): ?>
                          <span class="badge badge-medium" style="font-size:10px; margin-left:6px; font-weight:700;">Active Feed</span>
                        <?php endif; ?>
                      </a>
                      <span class="client-domain"><?= htmlspecialchars($row['deployment_domain']) ?></span>
                    </div>
                  </td>
                  <td>
                    <a href="product_view.php?id=<?= (int)$row['product_id'] ?>" style="text-decoration:none;">
                      <span class="product-badge"><?= htmlspecialchars($row['product_name']) ?></span>
                    </a>
                  </td>
                  <td>
                    <span class="status-pill <?= $status === 'unreachable' ? 'dead' : $status ?>">
                      <span class="dot dot-<?= $status === 'unreachable' ? 'dead' : $status ?>"></span>
                      <?= $status ?>
                    </span>
                  </td>
                  <td>
                    <span class="version-tag <?= !empty($row['is_outdated']) ? 'outdated' : '' ?>">v<?= htmlspecialchars($row['current_version']) ?></span>
                  </td>
                  <td class="num"><span class="metric-num <?= $latClass ?>"><?= $latText ?></span></td>
                  <td class="num"><span class="metric-num <?= $diskClass ?>"><?= $diskText ?></span></td>
                  <td class="num"><span class="metric-num"><?= $usersText ?></span></td>
                  <td>
                    <span class="err-badge <?= $errCount === 0 ? 'zero' : '' ?>"><?= $errCount ?></span>
                  </td>
                  <td><span class="hb-cell <?= $hbClass ?>"><?= $hbText ?></span></td>
                  <td>
                    <div class="row-actions">
                      <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="sync_deploy">
                        <input type="hidden" name="deployment_id" value="<?= (int)$row['id'] ?>">
                        <input type="hidden" name="page" value="<?= htmlspecialchars($activePage) ?>">
                        <input type="hidden" name="product_id" value="<?= (int)$row['product_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline" title="Reset unreachable state">Ping Sync</button>
                      </form>
                      <button class="icon-btn" onclick='openSystemModal(<?= $jsonDep ?>)' aria-label="View diagnostics" title="View diagnostics">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                      </button>
                      <a href="?page=overview&product_id=<?= (int)$row['product_id'] ?>&deployment_id=<?= (int)$row['id'] ?>#deployment-feed" class="icon-btn <?= (!empty($scopedDeploymentId) && $scopedDeploymentId === (int)$row['id']) ? 'active' : '' ?>" aria-label="View Deployment Node & Feed" title="View Deployment Error & Ticket Feed">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                      </a>
                      <a href="https://<?= htmlspecialchars($row['deployment_domain']) ?>" target="_blank" class="icon-btn" aria-label="Open site" title="Open site">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/></svg>
                      </a>
                      <a href="?delete_deployment=<?= $row['id'] ?>" 
                         class="btn btn-sm" 
                         style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; text-decoration: none;"
                         onclick="return confirm('Permanently remove this deployment and purge its heartbeat logs?');">
                          Delete
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <?php if (!empty($scopedDeployment) && in_array($activePage, ['overview', 'deployments', 'fleet', 'metrics'])): 
          $deployment = $scopedDeployment;
          $clientTickets = $scopedTickets;
          $clientErrors = $scopedErrors;
          $deploymentId = (int)$deployment['id'];
          $licStatus = strtolower($deployment['license_status'] ?? 'unknown');
        ?>
          <!-- EMBEDDED DEPLOYMENT NODE FEED DIRECTLY IN FLEET OVERVIEW -->
          <div id="deployment-feed" style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--line); scroll-margin-top: 70px;">
              
              <!-- Top Meta Bar for the Client Deployment -->
              <div class="system-context-banner" style="margin: 0 0 16px 0;">
                  <div>
                      <div class="sub-label">
                          <span class="dot dot-accent"></span>
                          Client Deployment Node &bull; Live Telemetry Feed
                      </div>
                      <h2>
                          <?= htmlspecialchars($deployment['client_name']) ?>
                          <small style="font-size: 13px; color: var(--text-dim); font-weight: normal; margin-left: 8px;" class="mono">(<?= htmlspecialchars($deployment['deployment_domain']) ?>)</small>
                      </h2>
                      <div style="margin-top: 6px; font-size: 12px; color: var(--text-dim); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                          <span>Product: <span class="product-badge"><?= htmlspecialchars($deployment['product_name']) ?></span></span>
                          <span style="color: var(--line);">|</span>
                          <span>Running: <span class="version-tag <?= !empty($deployment['is_outdated']) ? 'outdated' : '' ?>">v<?= htmlspecialchars($deployment['current_version']) ?></span></span>
                          <span style="color: var(--line);">|</span>
                          <span>Status: <span class="status-pill <?= $deployment['status'] === 'unreachable' ? 'dead' : $deployment['status'] ?>"><span class="dot"></span><?= htmlspecialchars($deployment['status']) ?></span></span>
                          <?php if (!empty($deployment['php_version'])): ?>
                              <span style="color: var(--line);">|</span>
                              <span class="mono">PHP <?= htmlspecialchars($deployment['php_version']) ?></span>
                          <?php endif; ?>
                          <?php if (!empty($deployment['license_key'])): ?>
                              <span style="color: var(--line);">|</span>
                              <span>License: <code class="mono" style="color: var(--accent);"><?= htmlspecialchars($deployment['license_key']) ?></code></span>
                          <?php endif; ?>
                      </div>
                  </div>
                  <div class="system-context-actions">
                      <button type="button" class="btn btn-primary" onclick="openPatchModal(<?= (int)$deployment['product_id'] ?>)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                          Dispatch Hotfix
                      </button>
                      <button type="button" class="btn" onclick="openSystemModal(<?= htmlspecialchars(json_encode($deployment), ENT_QUOTES, 'UTF-8') ?>)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                          Diagnostics
                      </button>
                      <a href="https://<?= htmlspecialchars($deployment['deployment_domain']) ?>" target="_blank" class="btn" style="text-decoration:none;">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/></svg>
                          Open Site
                      </a>
                      <a href="?page=<?= htmlspecialchars($activePage) ?><?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?>" class="btn" title="Close Scoped Feed">&times; Close Feed</a>
                  </div>
              </div>

              <!-- DEDICATED LICENSE STATUS & CONTROLS CARD -->
              <div class="panel-card" style="margin: 0 0 18px 0;">
                  <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
                      <div>
                          <div style="font-size: 11px; text-transform: uppercase; color: var(--text-faint); font-weight: 600; letter-spacing: 0.5px; margin-bottom: 6px;">
                              Cryptographic License Authorization
                          </div>
                          
                          <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap;">
                              <code class="mono" style="font-size: 14px; font-weight: 600; color: var(--accent); background: var(--panel-2); padding: 5px 12px; border-radius: var(--radius); border: 1px solid var(--line);">
                                  <?= htmlspecialchars($deployment['license_key'] ?? 'NO_LICENSE_BOUND') ?>
                              </code>
                              
                              <?php 
                                  $licStatus = strtolower($deployment['license_status'] ?? 'unknown');
                                  $pillClass = ($licStatus === 'active') ? 'ok' : (($licStatus === 'suspended') ? 'warn' : 'crit');
                              ?>
                              <span class="status-pill <?= $pillClass ?>">
                                  <span class="dot"></span>
                                  <?= htmlspecialchars($licStatus) ?>
                              </span>
                          </div>

                          <div style="display: flex; gap: 20px; font-size: 12px; color: var(--text-dim); flex-wrap: wrap;">
                              <div>
                                  <span style="color: var(--text-faint);">Plan:</span> 
                                  <strong style="color: var(--text);"><?= ucfirst(htmlspecialchars($deployment['license_type'] ?? 'Standard')) ?></strong>
                              </div>
                              <div>
                                  <span style="color: var(--text-faint);">Holder:</span> 
                                  <span style="color: var(--text);"><?= htmlspecialchars($deployment['license_client_name'] ?? $deployment['client_name']) ?></span>
                              </div>
                              <div>
                                  <span style="color: var(--text-faint);">Contact:</span> 
                                  <a href="mailto:<?= htmlspecialchars($deployment['license_client_email'] ?? '') ?>" style="color: var(--accent); text-decoration: none;">
                                      <?= htmlspecialchars($deployment['license_client_email'] ?? 'N/A') ?>
                                  </a>
                              </div>
                              <div>
                                  <span style="color: var(--text-faint);">Validity:</span> 
                                  <?php if (empty($deployment['expires_at'])): ?>
                                      <strong style="color: var(--ok);">Perpetual (No Expiration)</strong>
                                  <?php elseif (($deployment['days_remaining'] ?? 0) > 0): ?>
                                      <span>Expires <span class="mono"><?= htmlspecialchars(substr($deployment['expires_at'], 0, 10)) ?></span> (<strong style="color:var(--text);"><?= $deployment['days_remaining'] ?> days left</strong>)</span>
                                  <?php else: ?>
                                      <strong style="color: var(--crit);">Expired on <span class="mono"><?= htmlspecialchars(substr($deployment['expires_at'], 0, 10)) ?></span></strong>
                                  <?php endif; ?>
                              </div>
                          </div>
                      </div>

                      <!-- Right: Real-Time License Action Buttons -->
                      <?php if (!empty($deployment['license_id'])): ?>
                          <div style="display: flex; gap: 8px; align-items: center;">
                              <?php if ($licStatus === 'active'): ?>
                                  <a href="?page=<?= htmlspecialchars($activePage) ?><?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?>&deployment_id=<?= $deploymentId ?>&set_license_status=suspended" 
                                     class="btn btn-sm btn-warn" 
                                     onclick="return confirm('Suspend this license? Prism EDU will enforce lockout on the next lease check.');">
                                      ⏸ Suspend License
                                  </a>
                              <?php else: ?>
                                  <a href="?page=<?= htmlspecialchars($activePage) ?><?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?>&deployment_id=<?= $deploymentId ?>&set_license_status=active" 
                                     class="btn btn-sm btn-success" 
                                     onclick="return confirm('Reactivate this license? The client will immediately regain full access.');">
                                      ▶ Reactivate License
                                  </a>
                              <?php endif; ?>

                              <a href="?page=<?= htmlspecialchars($activePage) ?><?= $filterProduct > 0 ? '&product_id=' . $filterProduct : '' ?>&deployment_id=<?= $deploymentId ?>&set_license_status=revoked" 
                                 class="btn btn-sm btn-danger" 
                                 onclick="return confirm('Revoke this license permanently?');">
                                  ✕ Revoke
                              </a>
                          </div>
                      <?php endif; ?>
                  </div>
              </div>

              <!-- 2-Column Split: Tickets (Left) & Telemetry Error Logs (Right) -->
              <div class="split-grid" style="margin: 0 0 16px 0;">
                  
                  <!-- LEFT PANEL: IT Support Tickets -->
                  <div class="panel">
                      <div class="panel-header">
                          <div class="panel-title">Client Support Tickets (<?= count($clientTickets) ?>)</div>
                          <span style="font-size: 11px; color: var(--text-faint);">Human-submitted issues</span>
                      </div>
                      <div class="table-scroll">
                          <table>
                              <thead>
                                  <tr>
                                      <th>Ref</th>
                                      <th>Priority</th>
                                      <th>Subject &amp; Message</th>
                                      <th>Status</th>
                                      <th class="num">Action</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($clientTickets)): ?>
                                      <tr><td colspan="5" style="text-align: center; color: var(--text-faint); padding: 36px 16px;">No support tickets logged by this deployment.</td></tr>
                                  <?php else: ?>
                                      <?php foreach ($clientTickets as $t): ?>
                                          <tr>
                                              <td style="white-space: nowrap;">
                                                  <code class="mono" style="font-size:11.5px; color:var(--accent);"><?= htmlspecialchars($t['ticket_reference']) ?></code><br>
                                                  <small class="mono" style="color: var(--text-faint); font-size:11px;"><?= htmlspecialchars(substr($t['created_at'], 0, 10)) ?></small>
                                              </td>
                                              <td>
                                                  <?php
                                                      $prio = strtolower($t['priority'] ?? 'medium');
                                                      $prioClass = ($prio === 'urgent' || $prio === 'high') ? 'crit' : (($prio === 'medium') ? 'warn' : 'ok');
                                                  ?>
                                                  <span class="status-pill <?= $prioClass ?>">
                                                      <span class="dot"></span>
                                                      <?= htmlspecialchars($t['priority'] ?? 'normal') ?>
                                                  </span>
                                              </td>
                                              <td>
                                                  <strong style="color: var(--text); font-size: 13px;"><?= htmlspecialchars($t['subject']) ?></strong>
                                                  <p style="margin: 4px 0 0 0; color: var(--text-dim); font-size: 12px; line-height: 1.4;"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
                                              </td>
                                              <td>
                                                  <span class="status-pill <?= ($t['status'] === 'resolved') ? 'ok' : 'warn' ?>">
                                                      <span class="dot"></span>
                                                      <?= htmlspecialchars($t['status']) ?>
                                                  </span>
                                              </td>
                                              <td class="num" style="white-space: nowrap;">
                                                  <?php if ($t['status'] !== 'resolved'): ?>
                                                      <form method="POST" style="display:inline;">
                                                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                          <input type="hidden" name="action" value="resolve_ticket">
                                                          <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                                                          <input type="hidden" name="status" value="resolved">
                                                          <input type="hidden" name="page" value="<?= htmlspecialchars($activePage) ?>">
                                                          <input type="hidden" name="product_id" value="<?= (int)$deployment['product_id'] ?>">
                                                          <input type="hidden" name="deployment_id" value="<?= (int)$deployment['id'] ?>">
                                                          <button type="submit" class="btn btn-sm btn-success">
                                                              Resolve
                                                          </button>
                                                      </form>
                                                  <?php else: ?>
                                                      <span class="status-pill ok">Closed</span>
                                                  <?php endif; ?>
                                              </td>
                                          </tr>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>

                  <!-- RIGHT PANEL: Telemetry Error Logs for THIS Specific Deployment -->
                  <div class="panel">
                      <div class="panel-header">
                          <div class="panel-title">Telemetry Error Stream (<?= count($clientErrors) ?>)</div>
                          <span style="font-size: 11px; color: var(--text-faint);">Runtime traces &amp; exceptions</span>
                      </div>
                      <div class="table-scroll">
                          <table>
                              <thead>
                                  <tr>
                                      <th>Occurred</th>
                                      <th>Error Details &amp; Location</th>
                                      <th>Resolution</th>
                                      <th class="num">Action</th>
                                  </tr>
                              </thead>
                              <tbody>
                                  <?php if (empty($clientErrors)): ?>
                                      <tr><td colspan="4" style="text-align: center; color: var(--text-faint); padding: 36px 16px;">Zero errors recorded. This deployment is running clean.</td></tr>
                                  <?php else: ?>
                                      <?php foreach ($clientErrors as $err): ?>
                                          <tr>
                                              <td style="white-space: nowrap;">
                                                  <span class="mono" style="color: var(--text-faint); font-size: 11.5px;"><?= htmlspecialchars($err['occurred_at'] ?? $err['last_seen_at'] ?? 'Recent') ?></span><br>
                                                  <span class="status-pill warn" style="margin-top: 3px; font-size: 10px;">
                                                      <?= htmlspecialchars($err['error_level']) ?>
                                                  </span>
                                              </td>
                                              <td>
                                                  <div style="font-weight: 500; color: var(--crit); font-size: 12.5px;"><?= htmlspecialchars($err['error_message']) ?></div>
                                                  <small class="mono" style="color: var(--text-faint); font-size: 11px;">
                                                      <?= htmlspecialchars(basename($err['file_path'] ?? '')) ?>:<?= (int)($err['line_number'] ?? 0) ?>
                                                  </small>
                                                  <?php if (!empty($err['stack_trace'])): ?>
                                                      <details style="margin-top: 4px;">
                                                          <summary style="cursor:pointer; color:var(--accent); font-size:11px;">View Stack Trace</summary>
                                                          <pre class="mono" style="background:var(--ink); border:1px solid var(--line); padding:8px; border-radius:var(--radius); font-size:11px; max-height:140px; overflow:auto; color:var(--text-dim); margin-top:4px;"><?= htmlspecialchars($err['stack_trace']) ?></pre>
                                                      </details>
                                                  <?php endif; ?>
                                              </td>
                                              <td style="white-space: nowrap;">
                                                  <?php if (($err['status'] ?? 'open') === 'resolved'): ?>
                                                      <span class="status-pill ok">✓ Resolved <?= !empty($err['resolved_by_version']) ? '(v' . htmlspecialchars($err['resolved_by_version']) . ')' : '' ?></span>
                                                  <?php elseif (($err['status'] ?? 'open') === 'patch_pending'): ?>
                                                      <span class="status-pill warn">⏳ Patching</span>
                                                  <?php else: ?>
                                                      <span class="status-pill crit">● Open</span>
                                                  <?php endif; ?>
                                              </td>
                                              <td class="num" style="white-space: nowrap;">
                                                  <button type="button" class="btn btn-sm btn-ai" onclick="triggerAiFix(<?= (int)$err['id'] ?>)">
                                                      ✨ Fix
                                                  </button>
                                              </td>
                                          </tr>
                                      <?php endforeach; ?>
                                  <?php endif; ?>
                              </tbody>
                          </table>
                      </div>
                  </div>

              </div>
          </div>
        <?php endif; ?>

      <?php elseif ($activePage === 'deployment'): ?>
        <?php if (!$scopedDeployment): ?>
          <div style="padding: 40px; text-align: center; color: var(--text-dim);">
            <h3 style="color: var(--text); font-size: 16px;">Deployment Node Not Found</h3>
            <p style="margin-top: 8px; font-size: 13px; color: var(--text-dim);">The requested deployment could not be loaded or the ID is invalid.</p>
            <a href="dashboard.php" class="btn" style="margin-top: 16px;">&larr; Return to Fleet Overview</a>
          </div>
        <?php else: 
          $deployment = $scopedDeployment;
          $clientTickets = $scopedTickets;
          $clientErrors = $scopedErrors;
          $deploymentId = (int)$deployment['id'];
          $licStatus = strtolower($deployment['license_status'] ?? 'unknown');
        ?>
          <!-- Top Meta Bar for the Client Deployment -->
          <div class="system-context-banner" style="margin: 0 0 16px 0;">
              <div>
                  <div class="sub-label">
                      <span class="dot dot-accent"></span>
                      Client Deployment Node &bull; Dedicated View
                  </div>
                  <h2>
                      <?= htmlspecialchars($deployment['client_name']) ?>
                      <small style="font-size: 13px; color: var(--text-dim); font-weight: normal; margin-left: 8px;" class="mono">(<?= htmlspecialchars($deployment['deployment_domain']) ?>)</small>
                  </h2>
                  <div style="margin-top: 6px; font-size: 12px; color: var(--text-dim); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                      <span>Product: <span class="product-badge"><?= htmlspecialchars($deployment['product_name']) ?></span></span>
                      <span style="color: var(--line);">|</span>
                      <span>Running: <span class="version-tag <?= !empty($deployment['is_outdated']) ? 'outdated' : '' ?>">v<?= htmlspecialchars($deployment['current_version']) ?></span></span>
                      <span style="color: var(--line);">|</span>
                      <span>Status: <span class="status-pill <?= $deployment['status'] === 'unreachable' ? 'dead' : $deployment['status'] ?>"><span class="dot"></span><?= htmlspecialchars($deployment['status']) ?></span></span>
                      <?php if (!empty($deployment['php_version'])): ?>
                          <span style="color: var(--line);">|</span>
                          <span class="mono">PHP <?= htmlspecialchars($deployment['php_version']) ?></span>
                      <?php endif; ?>
                      <?php if (!empty($deployment['license_key'])): ?>
                          <span style="color: var(--line);">|</span>
                          <span>License: <code class="mono" style="color: var(--accent);"><?= htmlspecialchars($deployment['license_key']) ?></code></span>
                      <?php endif; ?>
                  </div>
              </div>
              <div class="system-context-actions">
                  <button type="button" class="btn btn-primary" onclick="openPatchModal(<?= (int)$deployment['product_id'] ?>)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                      Dispatch Hotfix
                  </button>
                  <button type="button" class="btn" onclick="openSystemModal(<?= htmlspecialchars(json_encode($deployment), ENT_QUOTES, 'UTF-8') ?>)">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
                      Diagnostics
                  </button>
                  <a href="https://<?= htmlspecialchars($deployment['deployment_domain']) ?>" target="_blank" class="btn" style="text-decoration:none;">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/></svg>
                      Open Site
                  </a>
                  <a href="dashboard.php?page=overview<?= !empty($deployment['product_id']) ? '&product_id=' . (int)$deployment['product_id'] : '' ?>" class="btn">&larr; Back to Fleet</a>
              </div>
          </div>

          <!-- DEDICATED LICENSE STATUS & CONTROLS CARD -->
          <div class="panel-card" style="margin: 0 0 18px 0;">
              <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
                  <div>
                      <div style="font-size: 11px; text-transform: uppercase; color: var(--text-faint); font-weight: 600; letter-spacing: 0.5px; margin-bottom: 6px;">
                          Cryptographic License Authorization
                      </div>
                      
                      <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap;">
                          <code class="mono" style="font-size: 14px; font-weight: 600; color: var(--accent); background: var(--panel-2); padding: 5px 12px; border-radius: var(--radius); border: 1px solid var(--line);">
                              <?= htmlspecialchars($deployment['license_key'] ?? 'NO_LICENSE_BOUND') ?>
                          </code>
                          
                          <?php 
                              $licStatus = strtolower($deployment['license_status'] ?? 'unknown');
                              $pillClass = ($licStatus === 'active') ? 'ok' : (($licStatus === 'suspended') ? 'warn' : 'crit');
                          ?>
                          <span class="status-pill <?= $pillClass ?>">
                              <span class="dot"></span>
                              <?= htmlspecialchars($licStatus) ?>
                          </span>
                      </div>

                      <div style="display: flex; gap: 20px; font-size: 12px; color: var(--text-dim); flex-wrap: wrap;">
                          <div>
                              <span style="color: var(--text-faint);">Plan:</span> 
                              <strong style="color: var(--text);"><?= ucfirst(htmlspecialchars($deployment['license_type'] ?? 'Standard')) ?></strong>
                          </div>
                          <div>
                              <span style="color: var(--text-faint);">Holder:</span> 
                              <span style="color: var(--text);"><?= htmlspecialchars($deployment['license_client_name'] ?? $deployment['client_name']) ?></span>
                          </div>
                          <div>
                              <span style="color: var(--text-faint);">Contact:</span> 
                              <a href="mailto:<?= htmlspecialchars($deployment['license_client_email'] ?? '') ?>" style="color: var(--accent); text-decoration: none;">
                                  <?= htmlspecialchars($deployment['license_client_email'] ?? 'N/A') ?>
                              </a>
                          </div>
                          <div>
                              <span style="color: var(--text-faint);">Validity:</span> 
                              <?php if (empty($deployment['expires_at'])): ?>
                                  <strong style="color: var(--ok);">Perpetual (No Expiration)</strong>
                              <?php elseif (($deployment['days_remaining'] ?? 0) > 0): ?>
                                  <span>Expires <span class="mono"><?= htmlspecialchars(substr($deployment['expires_at'], 0, 10)) ?></span> (<strong style="color:var(--text);"><?= $deployment['days_remaining'] ?> days left</strong>)</span>
                              <?php else: ?>
                                  <strong style="color: var(--crit);">Expired on <span class="mono"><?= htmlspecialchars(substr($deployment['expires_at'], 0, 10)) ?></span></strong>
                              <?php endif; ?>
                          </div>
                      </div>
                  </div>

                  <!-- Right: Real-Time License Action Buttons -->
                  <?php if (!empty($deployment['license_id'])): ?>
                      <div style="display: flex; gap: 8px; align-items: center;">
                          <?php if ($licStatus === 'active'): ?>
                              <a href="?page=deployment&id=<?= $deploymentId ?>&set_license_status=suspended" 
                                 class="btn btn-sm btn-warn" 
                                 onclick="return confirm('Suspend this license? Prism EDU will enforce lockout on the next lease check.');">
                                  ⏸ Suspend License
                              </a>
                          <?php else: ?>
                              <a href="?page=deployment&id=<?= $deploymentId ?>&set_license_status=active" 
                                 class="btn btn-sm btn-success" 
                                 onclick="return confirm('Reactivate this license? The client will immediately regain full access.');">
                                  ▶ Reactivate License
                              </a>
                          <?php endif; ?>

                          <a href="?page=deployment&id=<?= $deploymentId ?>&set_license_status=revoked" 
                             class="btn btn-sm btn-danger" 
                             onclick="return confirm('Revoke this license permanently?');">
                              ✕ Revoke
                          </a>
                      </div>
                  <?php endif; ?>

              </div>
          </div>

          <!-- 2-Column Split: Tickets (Left) & Telemetry Error Logs (Right) -->
          <div class="split-grid" style="margin: 0 0 16px 0;">
              
              <!-- LEFT PANEL: IT Support Tickets -->
              <div class="panel">
                  <div class="panel-header">
                      <div class="panel-title">Client Support Tickets (<?= count($clientTickets) ?>)</div>
                      <span style="font-size: 11px; color: var(--text-faint);">Human-submitted issues</span>
                  </div>
                  <div class="table-scroll">
                      <table>
                          <thead>
                              <tr>
                                  <th>Ref</th>
                                  <th>Priority</th>
                                  <th>Subject &amp; Message</th>
                                  <th>Status</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php if (empty($clientTickets)): ?>
                                  <tr><td colspan="4" style="text-align: center; color: var(--text-faint); padding: 36px 16px;">No support tickets logged by this deployment.</td></tr>
                              <?php else: ?>
                                  <?php foreach ($clientTickets as $t): ?>
                                      <tr>
                                          <td style="white-space: nowrap;">
                                              <code class="mono" style="font-size:11.5px; color:var(--accent);"><?= htmlspecialchars($t['ticket_reference']) ?></code><br>
                                              <small class="mono" style="color: var(--text-faint); font-size:11px;"><?= htmlspecialchars(substr($t['created_at'], 0, 10)) ?></small>
                                          </td>
                                          <td>
                                              <?php
                                                  $prio = strtolower($t['priority'] ?? 'medium');
                                                  $prioClass = ($prio === 'high' || $prio === 'critical') ? 'crit' : (($prio === 'urgent' || $prio === 'medium') ? 'warn' : 'info');
                                              ?>
                                              <span class="status-pill <?= $prioClass ?>"><?= htmlspecialchars($t['priority'] ?? 'normal') ?></span>
                                          </td>
                                          <td>
                                              <strong style="color: var(--text); font-size: 13px;"><?= htmlspecialchars($t['subject']) ?></strong>
                                              <p style="margin: 4px 0 0 0; color: var(--text-dim); font-size: 12px; line-height: 1.4;"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
                                          </td>
                                          <td>
                                              <span class="status-pill <?= ($t['status'] === 'resolved') ? 'ok' : 'warn' ?>">
                                                  <span class="dot"></span>
                                                  <?= htmlspecialchars($t['status']) ?>
                                              </span>
                                          </td>
                                      </tr>
                                  <?php endforeach; ?>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>

              <!-- RIGHT PANEL: Telemetry Error Logs for THIS Specific Deployment -->
              <div class="panel">
                  <div class="panel-header">
                      <div class="panel-title">Telemetry Error Stream (<?= count($clientErrors) ?>)</div>
                      <span style="font-size: 11px; color: var(--text-faint);">Runtime traces &amp; exceptions</span>
                  </div>
                  <div class="table-scroll">
                      <table>
                          <thead>
                              <tr>
                                  <th>Occurred</th>
                                  <th>Error Details &amp; Location</th>
                                  <th>Resolution</th>
                                  <th class="num">Action</th>
                              </tr>
                          </thead>
                          <tbody>
                              <?php if (empty($clientErrors)): ?>
                                  <tr><td colspan="4" style="text-align: center; color: var(--text-faint); padding: 36px 16px;">Zero errors recorded. This deployment is running clean.</td></tr>
                              <?php else: ?>
                                  <?php foreach ($clientErrors as $err): ?>
                                      <tr>
                                          <td style="white-space: nowrap;">
                                              <small class="mono" style="color: var(--text-faint); font-size:11px;"><?= htmlspecialchars($err['occurred_at'] ?? $err['last_seen_at'] ?? '') ?></small><br>
                                              <span class="status-pill warn" style="margin-top: 3px; font-size: 10px;"><?= htmlspecialchars($err['error_level']) ?></span>
                                          </td>
                                          <td>
                                              <div style="font-weight: 500; color: var(--crit); font-size: 12.5px;"><?= htmlspecialchars($err['error_message']) ?></div>
                                              <small class="mono" style="color: var(--text-dim); font-size: 11px;">
                                                  <?= htmlspecialchars(basename($err['file_path'] ?? '')) ?>:<?= (int)($err['line_number'] ?? 0) ?>
                                              </small>
                                              <?php if (!empty($err['stack_trace'])): ?>
                                                  <details style="margin-top: 4px;">
                                                      <summary style="cursor:pointer; color:var(--accent); font-size:11px;">View Stack Trace</summary>
                                                      <pre class="mono" style="background:var(--ink); border:1px solid var(--line); padding:8px; border-radius:var(--radius); font-size:11px; max-height:140px; overflow:auto; color:var(--text-dim); margin-top:4px;"><?= htmlspecialchars($err['stack_trace']) ?></pre>
                                                  </details>
                                              <?php endif; ?>
                                          </td>
                                          <td style="white-space: nowrap;">
                                              <?php if (($err['status'] ?? 'open') === 'resolved'): ?>
                                                  <span class="status-pill ok">✓ Resolved <?= !empty($err['resolved_by_version']) ? '(v' . htmlspecialchars($err['resolved_by_version']) . ')' : '' ?></span>
                                              <?php elseif (($err['status'] ?? 'open') === 'patch_pending'): ?>
                                                  <span class="status-pill warn">⏳ Patching</span>
                                              <?php else: ?>
                                                  <span class="status-pill crit">● Open</span>
                                              <?php endif; ?>
                                          </td>
                                          <td class="num" style="white-space: nowrap;">
                                              <button type="button" class="btn btn-sm btn-ai" onclick="triggerAiFix(<?= (int)$err['id'] ?>)">
                                                  ✨ Fix
                                              </button>
                                          </td>
                                      </tr>
                                  <?php endforeach; ?>
                              <?php endif; ?>
                          </tbody>
                      </table>
                  </div>
              </div>

          </div>
        <?php endif; ?>

      <?php elseif ($activePage === 'errors'): ?>
        <div style="background: rgba(13, 192, 231, 0.05); border: 1px solid rgba(13, 192, 231, 0.2); border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
          <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 18px; color: #0dc0e7;">🛡️</span>
            <div>
              <strong style="color: var(--text); font-size: 13px;">Error Log Retention Policy</strong>
              <div style="font-size: 11px; color: var(--text-dim);">Resolved incidents are automatically archived after <strong>30 days</strong> and permanently purged after <strong>60 days</strong>.</div>
            </div>
          </div>
          <div style="display: flex; align-items: center; gap: 8px;">
            <!-- Filter Pills -->
            <div style="display: inline-flex; border: 1px solid var(--line); border-radius: 6px; overflow: hidden; font-size: 11px;">
              <a href="?page=errors&error_status=all" style="padding: 4px 10px; text-decoration: none; <?= ($filterErrorStatus === 'all') ? 'background: #0dc0e7; color: #000; font-weight: 600;' : 'color: var(--text-dim); background: var(--surface);' ?>">All</a>
              <a href="?page=errors&error_status=open" style="padding: 4px 10px; text-decoration: none; <?= ($filterErrorStatus === 'open') ? 'background: #ef4444; color: #fff; font-weight: 600;' : 'color: var(--text-dim); background: var(--surface);' ?>">Unresolved</a>
              <a href="?page=errors&error_status=patch_pending" style="padding: 4px 10px; text-decoration: none; <?= ($filterErrorStatus === 'patch_pending') ? 'background: #f59e0b; color: #000; font-weight: 600;' : 'color: var(--text-dim); background: var(--surface);' ?>">Patch Pending</a>
              <a href="?page=errors&error_status=resolved" style="padding: 4px 10px; text-decoration: none; <?= ($filterErrorStatus === 'resolved') ? 'background: #10b981; color: #000; font-weight: 600;' : 'color: var(--text-dim); background: var(--surface);' ?>">Resolved</a>
              <a href="?page=errors&error_status=archived" style="padding: 4px 10px; text-decoration: none; <?= ($filterErrorStatus === 'archived') ? 'background: #94a3b8; color: #000; font-weight: 600;' : 'color: var(--text-dim); background: var(--surface);' ?>">Archived (>30d)</a>
            </div>
            <!-- Manual Sweep Button -->
            <form method="POST" style="margin: 0;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
              <input type="hidden" name="action" value="run_error_lifecycle">
              <button type="submit" class="btn btn-sm" style="border-color: #0dc0e7; color: #0dc0e7; font-size: 11px; padding: 4px 10px;" title="Execute 30d/60d retention sweep now">
                🧹 Run Retention Sweep
              </button>
            </form>
          </div>
        </div>

        <table>
          <thead>
            <tr>
              <th>Timestamp</th>
              <th>Level</th>
              <th>Status</th>
              <th>Client / Domain</th>
              <th>Error Message &amp; Route</th>
              <th style="width:140px; text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentErrors)): ?>
              <tr><td colspan="6" style="text-align:center; padding:40px; color:var(--text-faint);">No crash events or unhandled exceptions logged for this view.</td></tr>
            <?php else: ?>
              <?php foreach ($recentErrors as $err): $e = $err; ?>
                <tr>
                  <td style="white-space:nowrap; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--text-dim);">
                    <?= htmlspecialchars($err['last_seen_at'] ?? 'Just now') ?>
                    <?php if (($err['occurrences'] ?? 1) > 1): ?>
                      <span class="nav-count crit" style="margin-left:4px; font-size:10px;"><?= $err['occurrences'] ?>x</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="status-pill crit"><span class="dot dot-crit"></span><?= htmlspecialchars($err['error_level'] ?? 'ERROR') ?></span>
                  </td>
                  <td>
                    <?php if (($e['status'] ?? '') === 'resolved' || !empty($e['resolved'])): ?>
                        <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #10b981; border: 1px solid #10b981; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block;">
                            ✓ RESOLVED (v<?= htmlspecialchars((string)($e['resolved_by_version'] ?? '')) ?>)
                        </span>
                        <br><small style="color: var(--text-muted, var(--text-faint)); font-size: 0.7rem;">Verified on server</small>

                    <?php elseif (($e['status'] ?? '') === 'archived'): ?>
                        <span class="badge" style="background: rgba(148, 163, 184, 0.2); color: #94a3b8; border: 1px solid #94a3b8; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block;">
                            📦 ARCHIVED (v<?= htmlspecialchars((string)($e['resolved_by_version'] ?? '')) ?>)
                        </span>
                        <br><small style="color: var(--text-muted, var(--text-faint)); font-size: 0.7rem;">Resolved &gt; 30d (Purges at 60d)</small>

                    <?php elseif (($e['status'] ?? '') === 'patch_pending'): ?>
                        <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block;">
                            ⏳ PATCH DEPLOYING (v<?= htmlspecialchars((string)($e['resolved_by_version'] ?? '')) ?>)
                        </span>
                        <br><small style="color: var(--text-muted, var(--text-faint)); font-size: 0.7rem;">Awaiting client OTA sync</small>

                    <?php else: ?>
                        <span class="badge" style="background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid #ef4444; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600; display: inline-block;">
                            ● UNRESOLVED
                        </span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div class="client-cell">
                      <span class="client-name"><?= htmlspecialchars($err['client_name']) ?></span>
                      <span class="client-domain"><?= htmlspecialchars($err['deployment_domain']) ?></span>
                    </div>
                  </td>
                  <td>
                    <div style="font-weight:600; color:var(--crit-text); margin-bottom:2px;"><?= htmlspecialchars($err['error_message']) ?></div>
                    <div style="font-size:11px; color:var(--text-faint); font-family:'JetBrains Mono',monospace;">
                      <?= htmlspecialchars($err['file_path'] ?? '') ?><?= isset($err['line_number']) ? ':' . $err['line_number'] : '' ?>
                      <?php if (!empty($err['request_uri'])): ?> &bull; <?= htmlspecialchars($err['request_uri']) ?><?php endif; ?>
                    </div>
                    <?php if (!empty($err['stack_trace'])): ?>
                      <details style="margin-top:6px;">
                        <summary style="cursor:pointer; color:var(--accent); font-size:11px;">Inspect Trace</summary>
                        <pre style="background:var(--ink); border:1px solid var(--line); padding:8px; border-radius:var(--radius); font-size:11px; color:var(--text-dim); margin-top:4px; max-height:160px; overflow:auto;"><?= htmlspecialchars($err['stack_trace']) ?></pre>
                      </details>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:right; white-space:nowrap;">
                    <div style="display:inline-flex; gap:6px; align-items:center; justify-content:flex-end;">
                      <button type="button" class="btn btn-sm btn-ai" title="AI Diagnosis &amp; Auto-Patch (Gemini 3.8 Flash)" aria-label="AI Diagnosis &amp; Auto-Patch" style="padding:6px 9px;" onclick="triggerAiFix(<?= $e['id'] ?>)">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3-1.9 5.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3z"/></svg>
                      </button>
                      <?php if (($e['status'] ?? '') !== 'resolved' && empty($err['resolved'])): ?>
                        <form method="POST" style="display:inline; margin:0;">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                          <input type="hidden" name="action" value="resolve_error">
                          <input type="hidden" name="error_id" value="<?= (int)$err['id'] ?>">
                          <button type="submit" class="btn btn-sm btn-success" title="Mark error as resolved" aria-label="Resolve error" style="padding:6px 9px;">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                          </button>
                        </form>
                      <?php endif; ?>
                      <button type="button" class="btn btn-sm btn-warn" title="Deploy manual patch package" aria-label="Hotfix patch" style="padding:6px 9px;" onclick="openPatchModal(<?= $err['product_id'] ?? 1 ?>)">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></svg>
                      </button>
                      <a href="?delete_error=<?= $err['id'] ?>" 
                         class="btn btn-sm" 
                         style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; padding:6px 9px; text-decoration:none;"
                         onclick="return confirm('Permanently delete this error trace?');"
                         title="Permanently delete this error trace" aria-label="Delete error">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

      <?php elseif ($activePage === 'tickets'): ?>
        <table>
          <thead>
            <tr>
              <th>Ref</th>
              <th>Client</th>
              <th>Requester</th>
              <th>Priority</th>
              <th>Status</th>
              <th>Subject &amp; Message</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($openTickets)): ?>
              <tr><td colspan="7" style="text-align:center; padding:40px; color:var(--text-faint);">No support tickets matching filters.</td></tr>
            <?php else: ?>
              <?php foreach ($openTickets as $t): 
                $tStatus = $t['status'] ?? 'open';
                $pillClass = match($tStatus) {
                  'resolved' => 'ok',
                  'closed' => 'dead',
                  'in_progress' => 'warn',
                  default => 'crit'
                };
              ?>
                <tr>
                  <td><span class="mono" style="font-size:11px; color:var(--accent);"><?= htmlspecialchars($t['ticket_reference']) ?></span></td>
                  <td><strong><?= htmlspecialchars($t['client_name']) ?></strong></td>
                  <td>
                    <?= htmlspecialchars($t['requester_name']) ?>
                    <div style="font-size:11px; color:var(--text-faint);"><?= htmlspecialchars($t['requester_email']) ?></div>
                  </td>
                  <td>
                    <span class="status-pill <?= ($t['priority'] === 'urgent' || $t['priority'] === 'high') ? 'crit' : 'warn' ?>">
                      <?= strtoupper(htmlspecialchars($t['priority'])) ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-pill <?= $pillClass ?>"><span class="dot dot-<?= $pillClass ?>"></span><?= strtoupper($tStatus) ?></span>
                  </td>
                  <td>
                    <strong style="color:var(--text);"><?= htmlspecialchars($t['subject']) ?></strong>
                    <div style="font-size:12px; color:var(--text-dim); margin-top:2px; max-width:400px;"><?= nl2br(htmlspecialchars($t['message'])) ?></div>
                  </td>
                  <td>
                    <div style="display:flex; gap:6px;">
                      <?php if ($tStatus !== 'resolved'): ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                          <input type="hidden" name="action" value="resolve_ticket">
                          <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                          <input type="hidden" name="status" value="resolved">
                          <button type="submit" class="btn btn-sm" style="color:var(--ok-text); border-color:var(--ok-dim);">Resolve</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($tStatus !== 'closed'): ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                          <input type="hidden" name="action" value="resolve_ticket">
                          <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                          <input type="hidden" name="status" value="closed">
                          <button type="submit" class="btn btn-sm">Close</button>
                        </form>
                      <?php else: ?>
                        <form method="POST" style="display:inline;">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                          <input type="hidden" name="action" value="resolve_ticket">
                          <input type="hidden" name="ticket_id" value="<?= (int)$t['id'] ?>">
                          <input type="hidden" name="status" value="open">
                          <button type="submit" class="btn btn-sm">Reopen</button>
                        </form>
                      <?php endif; ?>
                      <a href="?delete_ticket=<?= $t['id'] ?>" 
                         class="btn btn-sm" 
                         style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; text-decoration: none;"
                         onclick="return confirm('Delete this support ticket permanently?');">
                          Delete
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

      <?php elseif ($activePage === 'releases'): ?>
        <table>
          <thead>
            <tr>
              <th>Target Product</th>
              <th>Version</th>
              <th>SHA-256 Checksum</th>
              <th>Signing Status</th>
              <th>Released At</th>
              <th style="width:100px">Download</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($releases)): ?>
              <tr><td colspan="6" style="text-align:center; padding:40px; color:var(--text-faint);">No update packages published yet. Use "Publish release" to distribute an update.</td></tr>
            <?php else: ?>
              <?php foreach ($releases as $rel): 
                $checksum = (string)($rel['package_checksum'] ?? $rel['checksum_sha256'] ?? '');
                $releasedAt = (string)($rel['released_at'] ?? $rel['created_at'] ?? '—');
                $packageFile = (string)($rel['package_filename'] ?? $rel['package_file'] ?? '');
              ?>
                <tr>
                  <td><strong><?= htmlspecialchars((string)($rel['product_name'] ?? 'Product')) ?></strong></td>
                  <td><span class="version-tag">v<?= htmlspecialchars((string)($rel['version'] ?? '0.0.0')) ?></span></td>
                  <td>
                    <span class="mono" style="font-size:11px; color:var(--text-dim);" title="<?= htmlspecialchars($checksum) ?>">
                      <?= htmlspecialchars(substr($checksum, 0, 16)) ?><?= strlen($checksum) > 16 ? '...' : '' ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-pill ok"><span class="dot dot-ok"></span>RSA-2048 Signed</span>
                  </td>
                  <td><span class="mono" style="font-size:11px; color:var(--text-faint);"><?= htmlspecialchars($releasedAt) ?></span></td>
                  <td>
                    <div style="display:flex; gap:6px; align-items:center;">
                      <?php if (!empty($packageFile)): ?>
                      <a href="downloads/updates/<?= urlencode($packageFile) ?>" class="btn btn-sm">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        Get ZIP
                      </a>
                      <?php else: ?>
                      <span style="color:var(--text-faint); font-size:12px;">No ZIP</span>
                      <?php endif; ?>
                      <a href="?delete_release=<?= $rel['id'] ?>" 
                         class="btn btn-sm" 
                         style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; text-decoration: none;"
                         onclick="return confirm('Delete this release and erase the ZIP file from disk?');">
                          Delete
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

      <?php elseif ($activePage === 'products'): ?>
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>Prefix Code</th>
              <th>Slug</th>
              <th>Latest Version</th>
              <th>Description</th>
              <th>Active Deployments</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): 
              $depCount = 0;
              foreach ($deployments as $d) {
                if ((int)$d['product_id'] === (int)$p['id']) $depCount++;
              }
            ?>
              <tr>
                <td><strong><?= htmlspecialchars($p['product_name']) ?></strong></td>
                <td>
                  <span class="mono" style="font-size:11px; font-weight:700; color:var(--accent); background:rgba(79,168,224,0.1); padding:2px 6px; border-radius:4px; border:1px solid rgba(79,168,224,0.25);">
                    <?= htmlspecialchars($p['product_code'] ?? '—') ?>
                  </span>
                </td>
                <td><span class="mono" style="font-size:11px; color:var(--accent);"><?= htmlspecialchars($p['product_slug']) ?></span></td>
                <td><span class="version-tag">v<?= htmlspecialchars($p['latest_version'] ?? '1.0.0') ?></span></td>
                <td style="color:var(--text-dim);"><?= htmlspecialchars($p['description'] ?? '—') ?></td>
                <td><span class="product-badge"><?= $depCount ?> nodes</span></td>
                <td style="text-align:right;">
                  <div style="display:inline-flex; gap:6px; align-items:center;">
                    <button type="button" class="btn btn-sm btn-outline" style="border-color: #0dc0e7; color: #bef6fd; font-size:11px; padding:3px 8px;" onclick="openModal('codebaseModal'); var sel = document.getElementById('codebaseProductSelect'); if(sel) sel.value = '<?= (int)$p['id'] ?>'; var ver = document.getElementById('codebaseVersionInput'); if(ver) ver.value = '<?= htmlspecialchars($p['latest_version'] ?? '1.0.0') ?>';">
                      📦 Sync Codebase
                    </button>
                    <a href="?delete_product=<?= $p['id'] ?>" 
                       class="btn btn-sm" 
                       style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; font-size:11px; padding:3px 8px; text-decoration:none;"
                       onclick="return confirm('WARNING: Deleting this product will remove its source codebase from disk and remove all associated releases, licenses, and deployments. Proceed?');">
                        Delete Product
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      <?php elseif ($activePage === 'licenses'): ?>
        <?php if (empty($isVendPulseView)): ?>
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding:0 2px;">
            <div style="font-size:13px; color:var(--text-dim); display:flex; align-items:center; gap:8px;">
              <span>Active Client Deployments &amp; Software Licenses</span>
              <span class="product-badge" style="background:var(--panel-2);"><?= count($licensesList) ?> Active / Tracked</span>
            </div>
            <div style="display:flex; gap:8px;">
              <button type="button" class="btn btn-sm btn-outline" style="border-color:#3dd68c; color:#8fefc0;" onclick="openIssueLicenseForProduct('vendpulse')" title="Issue VendPulse Offline Cryptographic License">
                ⚡ Issue VendPulse License
              </button>
              <button type="button" class="btn btn-sm btn-primary" onclick="openModal('licenseModal')">
                <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
                Issue license
              </button>
            </div>
          </div>
          <table>
            <thead>
              <tr>
                <th>License Key</th>
              <th>Product</th>
              <th>Client / Email</th>
              <th>Type</th>
              <th>Status</th>
              <th>Expiration</th>
              <th>Bound Domain</th>
              <th>Created</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($licensesList)): ?>
              <tr>
                <td colspan="9" style="text-align:center; padding:32px; color:var(--text-dim);">
                  No software licenses have been issued yet. Click <strong>Issue license</strong> above to generate a client key.
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($licensesList as $lic): ?>
                <tr>
                  <td>
                    <code class="mono" style="font-size:12px; font-weight:700; color:var(--accent); background:var(--panel-2); padding:3px 7px; border-radius:4px; border:1px solid var(--line);"><?= htmlspecialchars($lic['license_key']) ?></code>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($lic['product_name']) ?></strong>
                    <span class="mono" style="font-size:10px; color:var(--accent); margin-left:4px;">(<?= htmlspecialchars($lic['product_code'] ?? '—') ?>)</span>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($lic['client_name']) ?></strong>
                    <div style="font-size:11px; color:var(--text-dim);"><?= htmlspecialchars($lic['client_email']) ?></div>
                  </td>
                  <td>
                    <span class="product-badge" style="text-transform:capitalize;"><?= htmlspecialchars($lic['license_type']) ?></span>
                  </td>
                  <td>
                    <?php
                      $licStatus = $lic['status'] ?? 'unactivated';
                      $statusClass = 'dead';
                      if ($licStatus === 'active') $statusClass = 'ok';
                      elseif ($licStatus === 'unactivated') $statusClass = 'mono';
                      elseif ($licStatus === 'suspended') $statusClass = 'warn';
                    ?>
                    <span class="status-pill <?= $statusClass ?>">
                      <span class="dot dot-<?= $statusClass === 'mono' ? 'warn' : $statusClass ?>"></span>
                      <?= strtoupper(htmlspecialchars($licStatus)) ?>
                    </span>
                  </td>
                  <td>
                    <?php if (!empty($lic['expires_at'])): ?>
                      <span class="mono" style="font-size:11px; <?= strtotime($lic['expires_at']) < time() ? 'color:var(--crit); font-weight:600;' : '' ?>">
                        <?= htmlspecialchars(date('Y-m-d', strtotime($lic['expires_at']))) ?>
                      </span>
                    <?php else: ?>
                      <span style="color:var(--ok); font-size:12px; font-weight:500;">Perpetual</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($lic['deployment_domain'])): ?>
                      <div class="client-cell">
                        <span class="mono" style="font-size:11px; color:var(--text); font-weight:600;"><?= htmlspecialchars($lic['deployment_domain']) ?></span>
                        <span style="font-size:10px; color:<?= !empty($lic['deployment_is_active']) ? 'var(--ok)' : 'var(--crit)' ?>;">
                          <?= !empty($lic['deployment_is_active']) ? '● Deployment Active' : '○ Deployment Inactive' ?>
                        </span>
                      </div>
                    <?php else: ?>
                      <span style="color:var(--text-faint); font-size:11px; font-style:italic;">Unbound</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="mono" style="font-size:11px; color:var(--text-faint);"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($lic['created_at']))) ?></span>
                  </td>
                  <td style="text-align:right;">
                    <div style="display:inline-flex; gap:6px; align-items:center; justify-content:flex-end;">
                      <!-- Renew Button -->
                      <button type="button" class="btn btn-sm btn-primary" onclick='openRenewModal(<?= json_encode($lic) ?>)' title="Renew License & Deployment">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                        Renew
                      </button>

                      <?php if ($licStatus === 'suspended'): ?>
                        <!-- Reactivate / Resume -->
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Reactivate license \'<?= htmlspecialchars(addslashes($lic['license_key'])) ?>\' and its bound deployment?');">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                          <input type="hidden" name="action" value="reactivate_license">
                          <input type="hidden" name="license_id" value="<?= (int)$lic['id'] ?>">
                          <input type="hidden" name="page" value="licenses">
                          <button type="submit" class="btn btn-sm" style="border-color:var(--ok); color:var(--ok);" title="Resume / Unsuspend License">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                            Resume
                          </button>
                        </form>
                      <?php elseif ($licStatus !== 'revoked'): ?>
                        <!-- Suspend Button -->
                        <button type="button" class="btn btn-sm" style="border-color:var(--warn); color:var(--warn);" onclick='openSuspendModal(<?= json_encode($lic) ?>)' title="Suspend License & Deployment">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>
                          Suspend
                        </button>
                      <?php endif; ?>

                      <?php if ($licStatus !== 'revoked'): ?>
                        <!-- Revoke Button -->
                        <button type="button" class="btn btn-sm btn-danger" onclick='openRevokeModal(<?= json_encode($lic) ?>)' title="Permanently Revoke License">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                          Revoke
                        </button>
                      <?php endif; ?>
                      <a href="?delete_license=<?= $lic['id'] ?>" 
                         class="btn btn-sm" 
                         style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; text-decoration: none;"
                         onclick="return confirm('Permanently remove this client license? Deployed instances with this key will be locked out.');">
                          Delete
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>

        <?php else: ?>
          <!-- Return to licenses banner -->
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
            <a href="?page=licenses" class="btn btn-outline btn-sm" style="text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="15 18 9 12 15 6"></polyline></svg>
              ← Return to Software Licenses Ledger
            </a>
            <div style="font-size:12px; color:var(--text-dim);">
              Environment: <strong style="color:var(--ok);">VendPulse Air-Gapped POS Cryptographic Engine</strong>
            </div>
          </div>
        <?php if (!empty($freshVpLicense)): ?>
          <!-- Freshly Generated License Alert Card -->
          <div class="vp-card mb-4" style="border-color:var(--ok); background:linear-gradient(135deg, rgba(61,214,140,0.08) 0%, rgba(79,168,224,0.06) 100%); margin-bottom:24px;">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px;">
              <div>
                <span class="status-pill ok" style="margin-bottom:8px;"><span class="dot dot-ok"></span>OFFLINE LICENSE ISSUED & SIGNED</span>
                <h2 style="font-size:1.3rem; margin:6px 0 2px; color:var(--text); display:flex; align-items:center; gap:10px;">
                  <span><?= htmlspecialchars($freshVpLicense['client_name']) ?></span>
                  <code class="mono" style="font-size:13px; color:var(--accent); background:var(--ink); padding:2px 8px; border-radius:4px; border:1px solid var(--line);"><?= htmlspecialchars($freshVpLicense['license_id']) ?></code>
                </h2>
                <div style="font-size:12px; color:var(--text-dim);">
                  Operating Tier: <strong><?= htmlspecialchars($freshVpLicense['tier']) ?></strong> &bull;
                  Max Counters: <strong><?= (int)$freshVpLicense['max_counters'] ?></strong> &bull;
                  Validity: <strong><?= !empty($freshVpLicense['expires_at']) ? date('Y-m-d', $freshVpLicense['expires_at']) : 'Perpetual (Lifetime)' ?></strong> &bull;
                  Lock: <strong><?= !empty($freshVpLicense['machine_hwid']) ? htmlspecialchars($freshVpLicense['machine_hwid']) : 'Unconstrained' ?></strong>
                </div>
              </div>
              <div style="display:flex; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="copyVpRawText(<?= htmlspecialchars(json_encode($freshVpLicense['license_bundle'])) ?>, this)">
                  📋 Copy License String
                </button>
                <a href="?download_vp_license=<?= (int)$freshVpLicense['id'] ?>" class="btn btn-primary">
                  📥 Download <?= htmlspecialchars($freshVpLicense['filename'] ?? 'license.vpl') ?>
                </a>
              </div>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:14px;">
              <div>
                <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:var(--text-dim); margin-bottom:6px;">Canonical Normalized Payload (JSON)</div>
                <div class="vp-code-box"><?= htmlspecialchars(json_encode($freshVpLicense['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?></div>
              </div>
              <div>
                <div style="font-size:11px; font-weight:600; text-transform:uppercase; color:var(--text-dim); margin-bottom:6px;">Cryptographic Signature (SHA256withRSA)</div>
                <div class="vp-code-box" style="word-break:break-all; font-size:11px;"><?= htmlspecialchars($freshVpLicense['signature']) ?></div>
                <div style="margin-top:10px; font-size:11px; color:var(--text-dim); line-height:1.4;">
                  💡 <strong>Deployment Note:</strong> Save this bundle as <code class="mono">license.vpl</code> in your offline client's VendPulse directory. The client verifies the signature using your exported public key.
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Quick Stats Strip -->
        <div class="status-strip" style="margin-bottom:20px;">
          <div class="strip-item">
            <div class="strip-label"><span class="dot dot-accent"></span>Total Offline Licenses</div>
            <div class="strip-value"><?= (int)($vpStats['total'] ?? 0) ?></div>
          </div>
          <div class="strip-item">
            <div class="strip-label"><span class="dot dot-ok"></span>Supermarket POS</div>
            <div class="strip-value ok"><?= (int)($vpStats['tiers']['SUPERMARKET'] ?? 0) ?></div>
          </div>
          <div class="strip-item">
            <div class="strip-label"><span class="dot dot-warn"></span>Retail Basic</div>
            <div class="strip-value warn"><?= (int)($vpStats['tiers']['RETAIL_BASIC'] ?? 0) ?></div>
          </div>
          <div class="strip-item">
            <div class="strip-label"><span class="dot" style="background:#a855f7;"></span>Wholesale & B2B</div>
            <div class="strip-value" style="color:#c084fc;"><?= (int)($vpStats['tiers']['ENTERPRISE_WHOLESALE'] ?? 0) ?></div>
          </div>
          <div class="strip-item">
            <div class="strip-label"><span class="dot dot-ok"></span>Perpetual Deployments</div>
            <div class="strip-value"><?= (int)($vpStats['perpetual'] ?? 0) ?></div>
          </div>
        </div>

        <!-- 2-Column Generator Grid -->
        <div class="vp-grid">
          <!-- Left: Provisioning Form -->
          <div class="vp-card" id="vpGeneratorCard">
            <div class="vp-card-header">
              <div>
                <h3 class="vp-card-title">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/><circle cx="12" cy="16" r="1"/></svg>
                  VendPulse License Generator
                </h3>
                <div class="vp-card-subtitle">Issue tamper-proof, cryptographically signed offline licenses for air-gapped environments.</div>
              </div>
              <span class="status-pill ok"><span class="dot dot-ok"></span>RSA-2048 Asymmetric</span>
            </div>

            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
              <input type="hidden" name="action" value="generate_vp_license">
              <input type="hidden" name="page" value="licenses">
              <input type="hidden" name="view" value="vendpulse_generator">

              <!-- Section 1: Client / Deployment Identity -->
              <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--accent); margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent);"></span>
                1. Client / Deployment Identity
              </div>

              <div class="form-group">
                <label for="vpClientName">Client Name / Company Name <span style="color:var(--crit)">*</span></label>
                <input type="text" id="vpClientName" name="client_name" value="<?= htmlspecialchars($_GET['vp_client'] ?? '') ?>" placeholder="e.g. Al-Amana Supermarket - Branch 1" required style="font-size:14px; font-weight:500;">
              </div>

              <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div class="form-group">
                  <label for="vpLicenseId">License Reference Code (Auto-generated unique ID)</label>
                  <div style="display:flex; gap:6px;">
                    <input type="text" id="vpLicenseId" name="license_id" value="<?= htmlspecialchars($vpService->generateUniqueLicenseId()) ?>" class="mono" required style="font-weight:700; color:var(--accent); letter-spacing:0.5px;">
                    <button type="button" class="btn btn-outline" onclick="regenerateVpLicenseId()" title="Generate new random ID" style="padding:0 10px;">↻</button>
                  </div>
                </div>

                <div class="form-group">
                  <label for="vpHwid">Machine / Hardware Fingerprint <span style="color:var(--text-faint); font-weight:normal;">(Optional)</span></label>
                  <input type="text" id="vpHwid" name="machine_hwid" placeholder="Device UUID or leave blank for unconstrained" class="mono" style="font-size:12px;">
                </div>
              </div>

              <!-- Section 2: Operating Tier Selection -->
              <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--accent); margin-top:20px; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent);"></span>
                2. Operating Tier Selection (3 Operating Modes)
              </div>

              <div class="vp-tier-options">
                <!-- Tier 1: Retail Shop -->
                <div class="vp-tier-option" id="cardTierRetail" onclick="selectVpTier('RETAIL_BASIC')">
                  <input type="radio" name="tier" id="tierRetail" value="RETAIL_BASIC">
                  <span class="vp-tier-tag vp-tag-retail">Retail Basic</span>
                  <div class="vp-tier-name">Retail Shop</div>
                  <div class="vp-tier-desc">Standard counter checkout, touch-grid POS, basic inventory, receipt printing.</div>
                </div>

                <!-- Tier 2: Supermarket POS -->
                <div class="vp-tier-option selected" id="cardTierSupermarket" onclick="selectVpTier('SUPERMARKET')">
                  <input type="radio" name="tier" id="tierSupermarket" value="SUPERMARKET" checked>
                  <span class="vp-tier-tag vp-tag-supermarket">Supermarket</span>
                  <div class="vp-tier-name">Supermarket POS</div>
                  <div class="vp-tier-desc">High-speed barcode stream scanning, digital weighing scale integration, multi-till cash drawer balances, cashier shifts.</div>
                </div>

                <!-- Tier 3: Wholesale & Distribution -->
                <div class="vp-tier-option" id="cardTierWholesale" onclick="selectVpTier('ENTERPRISE_WHOLESALE')">
                  <input type="radio" name="tier" id="tierWholesale" value="ENTERPRISE_WHOLESALE">
                  <span class="vp-tier-tag vp-tag-wholesale">Wholesale B2B</span>
                  <div class="vp-tier-name">Wholesale & Dist.</div>
                  <div class="vp-tier-desc">Multi-warehouse management, customer credit limits, B2B tax invoices, institutional document seals.</div>
                </div>
              </div>

              <!-- Allowed Modules Checklist Display -->
              <div class="vp-modules-box">
                <div class="vp-modules-title">Automatically Flagged Allowed Modules for Selected Tier</div>
                <div class="vp-modules-chips" id="vpModulesContainer">
                  <!-- Dynamically populated via JS, with fallbacks -->
                  <label class="vp-module-chip active" id="chip_barcode_rapid_stream">
                    <input type="checkbox" name="allowed_modules[]" value="barcode_rapid_stream" checked style="accent-color:var(--accent);"> barcode_rapid_stream
                  </label>
                  <label class="vp-module-chip active" id="chip_scale_comm">
                    <input type="checkbox" name="allowed_modules[]" value="scale_comm" checked style="accent-color:var(--accent);"> scale_comm
                  </label>
                  <label class="vp-module-chip active" id="chip_multi_till">
                    <input type="checkbox" name="allowed_modules[]" value="multi_till" checked style="accent-color:var(--accent);"> multi_till
                  </label>
                  <label class="vp-module-chip active" id="chip_cashier_shifts">
                    <input type="checkbox" name="allowed_modules[]" value="cashier_shifts" checked style="accent-color:var(--accent);"> cashier_shifts
                  </label>
                  <label class="vp-module-chip" id="chip_catalog_grid">
                    <input type="checkbox" name="allowed_modules[]" value="catalog_grid" style="accent-color:var(--accent);"> catalog_grid
                  </label>
                  <label class="vp-module-chip" id="chip_standard_pos">
                    <input type="checkbox" name="allowed_modules[]" value="standard_pos" style="accent-color:var(--accent);"> standard_pos
                  </label>
                  <label class="vp-module-chip" id="chip_basic_receipt">
                    <input type="checkbox" name="allowed_modules[]" value="basic_receipt" style="accent-color:var(--accent);"> basic_receipt
                  </label>
                  <label class="vp-module-chip" id="chip_multi_warehouse">
                    <input type="checkbox" name="allowed_modules[]" value="multi_warehouse" style="accent-color:var(--accent);"> multi_warehouse
                  </label>
                  <label class="vp-module-chip" id="chip_b2b_invoices">
                    <input type="checkbox" name="allowed_modules[]" value="b2b_invoices" style="accent-color:var(--accent);"> b2b_invoices
                  </label>
                  <label class="vp-module-chip" id="chip_credit_accounts">
                    <input type="checkbox" name="allowed_modules[]" value="credit_accounts" style="accent-color:var(--accent);"> credit_accounts
                  </label>
                  <label class="vp-module-chip" id="chip_institutional_branding">
                    <input type="checkbox" name="allowed_modules[]" value="institutional_branding" style="accent-color:var(--accent);"> institutional_branding
                  </label>
                </div>
              </div>

              <!-- Section 3: System Usage Mode Selection -->
              <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--accent); margin-top:20px; margin-bottom:6px; display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent);"></span>
                3. System Usage Mode (Deployment Environment Lock) <span style="color:var(--crit)">*</span>
              </div>
              <div style="font-size:12px; color:var(--text-dim); margin-bottom:12px;">
                Enforces the operational business environment for the VendPulse deployment. Air-gapped terminals strictly bind active workflows to this authorized mode.
              </div>

              <div class="vp-tier-options">
                <!-- Mode 1: Retail Shop -->
                <div class="vp-tier-option selected" id="cardModeRetail" onclick="selectVpMode('RETAIL_SHOP')">
                  <input type="radio" name="usage_mode" id="modeRetail" value="RETAIL_SHOP" checked>
                  <span class="vp-tier-tag vp-tag-retail">🛍️ RETAIL_SHOP</span>
                  <div class="vp-tier-name" style="margin-top:6px;">Retail Shop</div>
                  <div class="vp-tier-desc">Standard counter sales, barcode lookup, product variants (size, color, brand), standard receipt printing.</div>
                </div>

                <!-- Mode 2: Supermarket -->
                <div class="vp-tier-option" id="cardModeSupermarket" onclick="selectVpMode('SUPERMARKET')">
                  <input type="radio" name="usage_mode" id="modeSupermarket" value="SUPERMARKET">
                  <span class="vp-tier-tag vp-tag-supermarket">🛒 SUPERMARKET</span>
                  <div class="vp-tier-name" style="margin-top:6px;">Supermarket POS</div>
                  <div class="vp-tier-desc">High-throughput barcode continuous stream scanning, digital weighing scale interface, multi-till cash drawers, cashier shift balancing.</div>
                </div>

                <!-- Mode 3: Restaurant & Hospitality -->
                <div class="vp-tier-option" id="cardModeRestaurant" onclick="selectVpMode('RESTAURANT')">
                  <input type="radio" name="usage_mode" id="modeRestaurant" value="RESTAURANT">
                  <span class="vp-tier-tag vp-tag-restaurant">🍽️ RESTAURANT</span>
                  <div class="vp-tier-name" style="margin-top:6px;">Restaurant &amp; Hospitality</div>
                  <div class="vp-tier-desc">Dining table floor plan management, kitchen order tickets (KOT), course management, split bills, and table reservations.</div>
                </div>
              </div>

              <!-- Section 4: Deployment Limits & Validity -->
              <div style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:var(--accent); margin-top:20px; margin-bottom:12px; display:flex; align-items:center; gap:6px;">
                <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent);"></span>
                4. Deployment Limits &amp; Validity
              </div>

              <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                <div class="form-group">
                  <label for="vpMaxCounters">Max Concurrent Registers / Cashier Terminals</label>
                  <input type="number" id="vpMaxCounters" name="max_counters" min="1" max="100" value="3" required style="font-size:14px; font-weight:600;">
                  <div style="font-size:11px; color:var(--text-dim); margin-top:3px;">Maximum POS stations permitted on the local network.</div>
                </div>

                <div class="form-group">
                  <label>Validity &amp; Expiration Policy</label>
                  <div style="display:flex; flex-direction:column; gap:8px; margin-top:4px;">
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:normal; cursor:pointer;">
                      <input type="radio" name="expiry_mode" value="perpetual" checked onchange="toggleVpExpiry(true)" style="accent-color:var(--accent);">
                      <strong>Perpetual / No Expiry</strong> (Lifetime offline deployment)
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; font-weight:normal; cursor:pointer;">
                      <input type="radio" name="expiry_mode" value="dated" onchange="toggleVpExpiry(false)" style="accent-color:var(--accent);">
                      <strong>Expiring License</strong> (Specify validity cutoff date)
                    </label>
                    <div id="vpDatePickerWrap" style="display:none; margin-top:4px;">
                      <input type="date" name="expires_at" id="vpExpiresAt" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 year')) ?>">
                    </div>
                  </div>
                </div>
              </div>

              <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:24px; border-top:1px solid var(--line); padding-top:16px;">
                <button type="reset" class="btn" onclick="selectVpTier('SUPERMARKET'); selectVpMode('RETAIL_SHOP'); toggleVpExpiry(true);">Reset Form</button>
                <button type="submit" class="btn btn-primary" style="padding:9px 22px; font-weight:600;">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/><circle cx="12" cy="12" r="4"/></svg>
                  Generate Offline License
                </button>
              </div>
            </form>
          </div>

          <!-- Right: Guidance & Tools Widgets -->
          <div>
            <!-- Widget 1: Public Key Infrastructure -->
            <div class="vp-side-widget">
              <div class="vp-side-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-2-2l2 2M3 13l7 7 10-10-7-7-10 10zM14 7l3 3"/></svg>
                Cryptographic Key Infrastructure
              </div>
              <p style="font-size:12px; color:var(--text-dim); margin-top:4px; line-height:1.45;">
                Licenses are signed with the SpectraOps private key using <strong>RSA-2048</strong> with <strong>SHA-256</strong>. VendPulse distributions verify signatures completely air-gapped using your public key.
              </p>
              <div style="display:flex; flex-direction:column; gap:8px; margin-top:14px;">
                <button type="button" class="btn btn-outline" onclick="openModal('vpPubKeyModal')" style="justify-content:center; border-color:#0dc0e7; color:#bef6fd;">
                  🔑 View / Copy Public Key
                </button>
                <a href="?download_vp_pubkey=1" class="btn" style="justify-content:center; text-decoration:none;">
                  📥 Download public.pem
                </a>
              </div>
            </div>

            <!-- Widget 2: Offline Air-Gap Delivery Workflow -->
            <div class="vp-side-widget">
              <div class="vp-side-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                Air-Gap Provisioning Steps
              </div>
              <ol class="vp-step-list">
                <li><strong>Distribute Public Key:</strong> Bundle <code class="mono">public.pem</code> inside client's VendPulse instance at <code class="mono">config/spectraops_pub.pem</code>.</li>
                <li><strong>Generate License:</strong> Select client tier and counters limit, then issue the cryptographically signed license file.</li>
                <li><strong>Offline Activation:</strong> Copy the generated <code class="mono">.vpl</code> file onto client machine via USB or local network. VendPulse verifies signature with 0 internet access.</li>
              </ol>
            </div>

            <!-- Widget 3: Live Verification Utility -->
            <div class="vp-side-widget">
              <div class="vp-side-title">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
                Offline License Verifier
              </div>
              <p style="font-size:12px; color:var(--text-dim); margin-top:4px; line-height:1.4;">
                Validate any issued license file or string against the SpectraOps public key to inspect claims and verify signature integrity.
              </p>
              <button type="button" class="btn" onclick="openModal('vpVerifyModal')" style="width:100%; justify-content:center; margin-top:10px; border-color:#3dd68c; color:#8fefc0;">
                🛡️ Open License Verifier Tool
              </button>
            </div>
          </div>
        </div>

        <!-- Section: Issued Offline Licenses Ledger Table -->
        <div class="card" style="margin-top:24px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <div>
              <h3 style="margin:0; font-size:1.1rem; color:var(--text); display:flex; align-items:center; gap:8px;">
                <span>Issued Offline Licenses</span>
                <span class="nav-count ok" style="font-size:12px;"><?= count($vpLicensesList) ?></span>
              </h3>
              <div style="font-size:12px; color:var(--text-dim); margin-top:2px;">Audit trail of cryptographically signed VendPulse deployment credentials.</div>
            </div>

            <div style="display:flex; gap:8px; align-items:center;">
              <form method="GET" style="display:flex; gap:6px;">
                <input type="hidden" name="page" value="licenses">
                <input type="hidden" name="view" value="vendpulse_generator">
                <input type="text" name="vp_search" value="<?= htmlspecialchars($_GET['vp_search'] ?? '') ?>" placeholder="Search client or license ID..." style="font-size:12px; padding:5px 10px; width:200px;">
                <select name="vp_mode" style="font-size:12px; padding:5px 8px; background:var(--ink); color:var(--text); border:1px solid var(--line); border-radius:var(--radius);">
                  <option value="">All Usage Modes</option>
                  <option value="RETAIL_SHOP" <?= (($_GET['vp_mode'] ?? '') === 'RETAIL_SHOP') ? 'selected' : '' ?>>🛍️ Retail Shop</option>
                  <option value="SUPERMARKET" <?= (($_GET['vp_mode'] ?? '') === 'SUPERMARKET') ? 'selected' : '' ?>>🛒 Supermarket</option>
                  <option value="RESTAURANT" <?= (($_GET['vp_mode'] ?? '') === 'RESTAURANT') ? 'selected' : '' ?>>🍽️ Restaurant</option>
                </select>
                <button type="submit" class="btn btn-sm">Filter</button>
                <?php if (!empty($_GET['vp_search']) || !empty($_GET['vp_tier']) || !empty($_GET['vp_mode'])): ?>
                  <a href="?page=licenses&view=vendpulse_generator" class="btn btn-sm">Clear</a>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <table>
            <thead>
              <tr>
                <th>License Ref ID</th>
                <th>Client / Organization</th>
                <th>Operating Tier</th>
                <th>Usage Mode</th>
                <th>HWID Device Lock</th>
                <th>Registers</th>
                <th>Validity / Expiry</th>
                <th>Status</th>
                <th>Created</th>
                <th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($vpLicensesList)): ?>
                <tr>
                  <td colspan="10" style="text-align:center; padding:32px; color:var(--text-dim);">
                    No VendPulse offline licenses issued yet. Use the form above to generate your first client license.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($vpLicensesList as $vpl): ?>
                  <tr>
                    <td>
                      <code class="mono" style="font-size:12px; font-weight:700; color:var(--accent); background:var(--panel-2); padding:3px 7px; border-radius:4px; border:1px solid var(--line);"><?= htmlspecialchars($vpl['license_id']) ?></code>
                    </td>
                    <td>
                      <strong><?= htmlspecialchars($vpl['client_name']) ?></strong>
                    </td>
                    <td>
                      <?php
                        $t = $vpl['tier'];
                        $tClass = 'vp-tag-supermarket';
                        if ($t === 'RETAIL_BASIC') $tClass = 'vp-tag-retail';
                        elseif ($t === 'ENTERPRISE_WHOLESALE') $tClass = 'vp-tag-wholesale';
                      ?>
                      <span class="vp-tier-tag <?= $tClass ?>" style="margin:0;"><?= htmlspecialchars($t) ?></span>
                    </td>
                    <td>
                      <?php
                        $um = $vpl['usage_mode'] ?? 'RETAIL_SHOP';
                        $umClass = 'vp-tag-retail';
                        $umIcon = '🛍️';
                        if ($um === 'SUPERMARKET') {
                          $umClass = 'vp-tag-supermarket';
                          $umIcon = '🛒';
                        } elseif ($um === 'RESTAURANT') {
                          $umClass = 'vp-tag-restaurant';
                          $umIcon = '🍽️';
                        }
                      ?>
                      <span class="vp-tier-tag <?= $umClass ?>" style="margin:0;"><?= $umIcon ?> <?= htmlspecialchars($um) ?></span>
                    </td>
                    <td>
                      <?php if (!empty($vpl['machine_hwid'])): ?>
                        <code class="mono" style="font-size:11px; color:var(--text);"><?= htmlspecialchars(substr($vpl['machine_hwid'], 0, 16)) ?><?= strlen($vpl['machine_hwid']) > 16 ? '...' : '' ?></code>
                      <?php else: ?>
                        <span style="font-size:11px; color:var(--text-faint); font-style:italic;">Unconstrained (Any)</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="mono" style="font-weight:600; font-size:12px;"><?= (int)$vpl['max_counters'] ?> Station<?= (int)$vpl['max_counters'] > 1 ? 's' : '' ?></span>
                    </td>
                    <td>
                      <?php if (!empty($vpl['expires_at'])): ?>
                        <span class="mono" style="font-size:11px; <?= (int)$vpl['expires_at'] < time() ? 'color:var(--crit); font-weight:700;' : '' ?>">
                          <?= htmlspecialchars(date('Y-m-d', (int)$vpl['expires_at'])) ?>
                        </span>
                      <?php else: ?>
                        <span style="color:var(--ok); font-size:12px; font-weight:600;">Perpetual</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php
                        $st = $vpl['status'] ?? 'active';
                        $isExp = !empty($vpl['expires_at']) && (int)$vpl['expires_at'] < time();
                        if ($isExp && $st === 'active') {
                          $st = 'expired';
                        }
                        $pillClass = ($st === 'active') ? 'ok' : (($st === 'revoked') ? 'dead' : 'warn');
                      ?>
                      <span class="status-pill <?= $pillClass ?>">
                        <span class="dot dot-<?= $pillClass ?>"></span>
                        <?= strtoupper(htmlspecialchars($st)) ?>
                      </span>
                    </td>
                    <td>
                      <span class="mono" style="font-size:11px; color:var(--text-faint);"><?= htmlspecialchars(date('Y-m-d H:i', strtotime($vpl['created_at']))) ?></span>
                    </td>
                    <td style="text-align:right;">
                      <div style="display:inline-flex; gap:6px; align-items:center; justify-content:flex-end;">
                        <!-- Download .vpl file -->
                        <a href="?download_vp_license=<?= (int)$vpl['id'] ?>" class="btn btn-sm btn-primary" title="Download License Bundle (.vpl)">
                          📥 .vpl
                        </a>

                        <!-- View / Copy Bundle -->
                        <button type="button" class="btn btn-sm" onclick='viewVpBundleModal(<?= json_encode($vpl) ?>)' title="View Claims & Copy String">
                          📋 View
                        </button>

                        <?php if (($vpl['status'] ?? '') === 'revoked'): ?>
                          <!-- Reactivate -->
                          <form method="POST" style="display:inline;" onsubmit="return confirm('Reactivate license \'<?= htmlspecialchars(addslashes($vpl['license_id'])) ?>\'?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="reactivate_vp_license">
                            <input type="hidden" name="license_id" value="<?= (int)$vpl['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="border-color:var(--ok); color:var(--ok);" title="Reactivate License">
                              Resume
                            </button>
                          </form>
                        <?php else: ?>
                          <!-- Revoke -->
                          <form method="POST" style="display:inline;" onsubmit="return confirm('Revoke offline license \'<?= htmlspecialchars(addslashes($vpl['license_id'])) ?>\'?');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="revoke_vp_license">
                            <input type="hidden" name="license_id" value="<?= (int)$vpl['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="border-color:var(--warn); color:var(--warn);" title="Revoke License">
                              Revoke
                            </button>
                          </form>
                        <?php endif; ?>

                        <!-- Delete -->
                        <a href="?delete_vp_license=<?= (int)$vpl['id'] ?>" 
                           class="btn btn-sm" 
                           style="background:rgba(239, 68, 68, 0.15); color:#ef4444; border:1px solid #ef4444; text-decoration:none;"
                           onclick="return confirm('Permanently remove this license from the audit registry?');">
                          Delete
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>

      <?php elseif ($activePage === 'users'): ?>
        <table>
          <thead>
            <tr>
              <th>Operator Name</th>
              <th>Email Address</th>
              <th>Role</th>
              <th>Created At</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($usersList as $u): 
              $isSelf = ((int)$u['id'] === (int)($_SESSION['fleet_user_id'] ?? 0));
            ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($u['name']) ?></strong>
                  <?php if ($isSelf): ?>
                    <span class="mono" style="font-size:10px; color:var(--accent); margin-left:6px; background:rgba(79,168,224,0.12); padding:1px 6px; border-radius:3px; border:1px solid rgba(79,168,224,0.25);">YOU</span>
                  <?php endif; ?>
                </td>
                <td><span class="mono" style="font-size:12px;"><?= htmlspecialchars($u['email']) ?></span></td>
                <td>
                  <span class="status-pill <?= $u['role'] === 'admin' ? 'ok' : 'dead' ?>">
                    <span class="dot dot-<?= $u['role'] === 'admin' ? 'ok' : 'dead' ?>"></span>
                    <?= strtoupper(htmlspecialchars($u['role'])) ?>
                  </span>
                </td>
                <td><span class="mono" style="font-size:11px; color:var(--text-faint);"><?= htmlspecialchars($u['created_at']) ?></span></td>
                <td style="text-align:right;">
                  <div style="display:inline-flex; gap:6px; align-items:center;">
                    <button type="button" class="btn btn-sm" onclick='openEditUserModal(<?= json_encode($u) ?>)' title="Edit Operator Profile">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                      Edit
                    </button>
                    <button type="button" class="btn btn-sm" onclick="openResetPasswordModal(<?= (int)$u['id'] ?>, '<?= htmlspecialchars(addslashes($u['name'])) ?>')" title="Reset Password">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                      Password
                    </button>
                    <?php if (!$isSelf): ?>
                      <a href="?delete_admin_user=<?= $u['id'] ?>" 
                         class="btn btn-sm" 
                         style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid #ef4444; text-decoration: none;"
                         onclick="return confirm('Permanently revoke and delete this administrator account?');">
                          Delete User
                      </a>
                    <?php else: ?>
                      <span style="font-size: 0.72rem; color: var(--text-muted);">(Active Session)</span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

      <?php elseif ($activePage === 'orders'): ?>
        <!-- Orders & Deployments Interactive KPI Summary Strip -->
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:20px; padding:16px; background:var(--panel); border:1px solid var(--line); border-radius:12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);">
          
          <!-- Card: Total Store Orders -->
          <a href="?page=orders&status=all" style="text-decoration:none; display:block; padding:14px; border-radius:10px; border:1px solid <?= ($orderFilter === '' && ($orderStatus === '' || $orderStatus === 'all')) ? 'var(--accent)' : 'var(--line)' ?>; background:<?= ($orderFilter === '' && ($orderStatus === '' || $orderStatus === 'all')) ? 'rgba(13,192,231,0.08)' : 'rgba(255,255,255,0.02)' ?>; transition:all 0.2s ease;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span style="font-size:11px; text-transform:uppercase; color:var(--text-dim); font-weight:700; letter-spacing:0.5px;">Total Orders</span>
              <span style="font-size:16px;">📦</span>
            </div>
            <div style="font-size:1.75rem; font-weight:800; color:var(--text); margin-top:6px;"><?= $orderStats['total'] ?></div>
            <div style="font-size:11px; color:var(--text-faint); margin-top:4px;">All store checkouts</div>
          </a>

          <!-- Card: Cloud Deployments -->
          <a href="?page=orders&filter=cloud" style="text-decoration:none; display:block; padding:14px; border-radius:10px; border:1px solid <?= ($orderFilter === 'cloud') ? '#0ea5e9' : 'rgba(14,165,233,0.25)' ?>; background:<?= ($orderFilter === 'cloud') ? 'rgba(14,165,233,0.15)' : 'rgba(14,165,233,0.05)' ?>; transition:all 0.2s ease;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span style="font-size:11px; text-transform:uppercase; color:#0ea5e9; font-weight:700; letter-spacing:0.5px;">☁️ Cloud Systems</span>
              <span style="font-size:11px; background:rgba(14,165,233,0.2); color:#38bdf8; padding:2px 6px; border-radius:4px; font-weight:700;">SaaS</span>
            </div>
            <div style="font-size:1.75rem; font-weight:800; color:#38bdf8; margin-top:6px;"><?= $orderStats['cloud'] ?></div>
            <div style="font-size:11px; color:#7dd3fc; margin-top:4px;"><?= $orderStats['cloud_deployed'] ?> Live &bull; <?= $orderStats['cloud_pending'] ?> Pending</div>
          </a>

          <!-- Card: Live Deployed Nodes -->
          <a href="?page=orders&filter=deployed" style="text-decoration:none; display:block; padding:14px; border-radius:10px; border:1px solid <?= ($orderFilter === 'deployed') ? '#10b981' : 'rgba(16,185,129,0.25)' ?>; background:<?= ($orderFilter === 'deployed') ? 'rgba(16,185,129,0.15)' : 'rgba(16,185,129,0.05)' ?>; transition:all 0.2s ease;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span style="font-size:11px; text-transform:uppercase; color:#10b981; font-weight:700; letter-spacing:0.5px;">🟢 Live Nodes</span>
              <span class="dot dot-healthy" style="box-shadow:0 0 8px #10b981;"></span>
            </div>
            <div style="font-size:1.75rem; font-weight:800; color:#34d399; margin-top:6px;"><?= $orderStats['cloud_deployed'] ?></div>
            <div style="font-size:11px; color:#6ee7b7; margin-top:4px;">Reporting active telemetry</div>
          </a>

          <!-- Card: Awaiting Deployment -->
          <a href="?page=orders&filter=pending" style="text-decoration:none; display:block; padding:14px; border-radius:10px; border:1px solid <?= ($orderFilter === 'pending') ? '#f59e0b' : 'rgba(245,158,11,0.25)' ?>; background:<?= ($orderFilter === 'pending') ? 'rgba(245,158,11,0.15)' : 'rgba(245,158,11,0.05)' ?>; transition:all 0.2s ease;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span style="font-size:11px; text-transform:uppercase; color:#fbbf24; font-weight:700; letter-spacing:0.5px;">⏳ Pending Deploy</span>
              <span style="font-size:14px;">⚡</span>
            </div>
            <div style="font-size:1.75rem; font-weight:800; color:#fde68a; margin-top:6px;"><?= $orderStats['cloud_pending'] ?></div>
            <div style="font-size:11px; color:#fde047; margin-top:4px;">Domain unassigned</div>
          </a>

          <!-- Card: Offline POS Orders -->
          <a href="?page=orders&filter=pos" style="text-decoration:none; display:block; padding:14px; border-radius:10px; border:1px solid <?= ($orderFilter === 'pos') ? '#a855f7' : 'rgba(168,85,247,0.25)' ?>; background:<?= ($orderFilter === 'pos') ? 'rgba(168,85,247,0.15)' : 'rgba(168,85,247,0.05)' ?>; transition:all 0.2s ease;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span style="font-size:11px; text-transform:uppercase; color:#c084fc; font-weight:700; letter-spacing:0.5px;">🛒 Offline POS</span>
              <span style="font-size:14px;">🛡️</span>
            </div>
            <div style="font-size:1.75rem; font-weight:800; color:#d8b4fe; margin-top:6px;"><?= $orderStats['offline'] ?></div>
            <div style="font-size:11px; color:#e9d5ff; margin-top:4px;">Air-Gapped Retail Engines</div>
          </a>

          <!-- Card: Total Downloads -->
          <a href="?page=orders&status=all" style="text-decoration:none; display:block; padding:14px; border-radius:10px; border:1px solid rgba(255,255,255,0.08); background:rgba(255,255,255,0.02); transition:all 0.2s ease;">
            <div style="display:flex; justify-content:space-between; align-items:center;">
              <span style="font-size:11px; text-transform:uppercase; color:var(--text-dim); font-weight:700; letter-spacing:0.5px;">📥 Deliveries</span>
              <span style="font-size:14px;">🌐</span>
            </div>
            <div style="font-size:1.75rem; font-weight:800; color:var(--text); margin-top:6px;"><?= $orderStats['downloads'] ?></div>
            <div style="font-size:11px; color:var(--text-faint); margin-top:4px;">Customer portal downloads</div>
          </a>

        </div>

        <!-- Filter & Search Toolbar -->
        <div style="background:var(--panel); border:1px solid var(--line); border-radius:10px; padding:16px; margin-bottom:20px;">
          <form method="GET" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
            <input type="hidden" name="page" value="orders">
            <?php if (!empty($orderFilter)): ?>
              <input type="hidden" name="filter" value="<?= htmlspecialchars($orderFilter) ?>">
            <?php endif; ?>

            <!-- Search Field -->
            <div style="flex:1; min-width:280px; position:relative;">
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute; left:12px; top:50%; transform:translateY(-50%); color:var(--text-dim);"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              <input type="text" name="order_search" value="<?= htmlspecialchars($orderSearch) ?>" placeholder="Search Order ID, Client, Domain, or License Key..." style="width:100%; background:var(--ink); border:1px solid var(--line); border-radius:8px; padding:10px 14px 10px 38px; color:var(--text); font-size:13px;">
            </div>

            <!-- System Mode Filter -->
            <div style="min-width:180px;">
              <select name="mode" style="width:100%; background:var(--ink); border:1px solid var(--line); border-radius:8px; padding:10px 12px; color:var(--text); font-size:13px;">
                <option value="all" <?= ($orderMode === '' || $orderMode === 'all') ? 'selected' : '' ?>>All System Architectures</option>
                <option value="CLOUD_HOSTED" <?= $orderMode === 'CLOUD_HOSTED' ? 'selected' : '' ?>>☁️ Cloud Hosted SaaS</option>
                <option value="SUPERMARKET" <?= $orderMode === 'SUPERMARKET' ? 'selected' : '' ?>>🛒 Supermarket POS</option>
                <option value="RETAIL_SHOP" <?= $orderMode === 'RETAIL_SHOP' ? 'selected' : '' ?>>🛍️ Retail Basic POS</option>
                <option value="RESTAURANT" <?= $orderMode === 'RESTAURANT' ? 'selected' : '' ?>>🍽️ Restaurant &amp; Dining</option>
              </select>
            </div>

            <!-- Order Status Filter -->
            <div style="min-width:140px;">
              <select name="status" style="width:100%; background:var(--ink); border:1px solid var(--line); border-radius:8px; padding:10px 12px; color:var(--text); font-size:13px;">
                <option value="all" <?= ($orderStatus === '' || $orderStatus === 'all') ? 'selected' : '' ?>>All Statuses</option>
                <option value="completed" <?= $orderStatus === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="pending" <?= $orderStatus === 'pending' ? 'selected' : '' ?>>Pending</option>
              </select>
            </div>

            <!-- Action Buttons -->
            <button type="submit" class="btn btn-primary" style="padding:10px 16px;">
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              Filter Orders
            </button>

            <?php if (!empty($orderSearch) || !empty($orderFilter) || (!empty($orderMode) && $orderMode !== 'all') || (!empty($orderStatus) && $orderStatus !== 'all')): ?>
              <a href="?page=orders&status=all" class="btn btn-outline" style="text-decoration:none; padding:10px 14px; color:var(--text-muted);" title="Reset filters">
                ✕ Clear
              </a>
            <?php endif; ?>
          </form>

          <!-- Quick Navigation Tabs -->
          <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; padding-top:14px; border-top:1px solid var(--line);">
            <span style="font-size:11.5px; color:var(--text-dim); align-self:center; margin-right:4px; font-weight:600;">QUICK VIEWS:</span>
            
            <a href="?page=orders&status=all" class="btn btn-sm <?= (empty($orderFilter) && (empty($orderStatus) || $orderStatus === 'all')) ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none; font-size:12px; padding:4px 10px;">
              All Orders (<?= $orderStats['total'] ?>)
            </a>
            
            <a href="?page=orders&filter=cloud" class="btn btn-sm <?= ($orderFilter === 'cloud') ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none; font-size:12px; padding:4px 10px; border-color:rgba(14,165,233,0.4); color:<?= ($orderFilter === 'cloud') ? '#fff' : '#38bdf8' ?>;">
              ☁️ Cloud Systems (<?= $orderStats['cloud'] ?>)
            </a>

            <a href="?page=orders&filter=deployed" class="btn btn-sm <?= ($orderFilter === 'deployed') ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none; font-size:12px; padding:4px 10px; border-color:rgba(16,185,129,0.4); color:<?= ($orderFilter === 'deployed') ? '#fff' : '#34d399' ?>;">
              🟢 Live Deployed Nodes (<?= $orderStats['cloud_deployed'] ?>)
            </a>

            <a href="?page=orders&filter=pending" class="btn btn-sm <?= ($orderFilter === 'pending') ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none; font-size:12px; padding:4px 10px; border-color:rgba(245,158,11,0.4); color:<?= ($orderFilter === 'pending') ? '#fff' : '#fbbf24' ?>;">
              ⏳ Awaiting Deployment (<?= $orderStats['cloud_pending'] ?>)
            </a>

            <a href="?page=orders&filter=pos" class="btn btn-sm <?= ($orderFilter === 'pos') ? 'btn-primary' : 'btn-outline' ?>" style="text-decoration:none; font-size:12px; padding:4px 10px; border-color:rgba(168,85,247,0.4); color:<?= ($orderFilter === 'pos') ? '#fff' : '#c084fc' ?>;">
              🛒 Offline POS Systems (<?= $orderStats['offline'] ?>)
            </a>
          </div>
        </div>

        <!-- Orders & Deployments Table -->
        <table>
          <thead>
            <tr>
              <th>Order Ref &amp; Client</th>
              <th>Software System</th>
              <th>Cloud Deployment &amp; Fleet Node</th>
              <th>Entitlement</th>
              <th>License Credentials</th>
              <th>Self-Service Portal</th>
              <th>Ingress Date</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($ordersList)): ?>
              <tr>
                <td colspan="8" style="text-align:center; padding:56px 16px; color:var(--text-faint);">
                  <div style="font-size:32px; margin-bottom:12px;">📦</div>
                  <div style="font-size:15px; font-weight:600; color:var(--text);">No matching orders found</div>
                  <div style="font-size:12.5px; color:var(--text-dim); margin-top:4px;">
                    <?php if (!empty($orderSearch) || !empty($orderFilter) || !empty($orderMode)): ?>
                      Try clearing search queries or switching filter tabs.
                      <div style="margin-top:12px;">
                        <a href="?page=orders&status=all" class="btn btn-sm btn-outline" style="text-decoration:none;">View All Orders</a>
                      </div>
                    <?php else: ?>
                      Send test purchase order webhooks to <code>/api/v1/orders/provision</code>.
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($ordersList as $o): 
                $portalUrl = $dashOrderService ? $dashOrderService->buildPortalUrl((string)$o['download_token']) : ('portal/download.php?token=' . urlencode((string)$o['download_token']));
                $portalLink = 'portal/download.php?token=' . urlencode((string)$o['download_token']);
                $oMode = strtoupper((string)($o['usage_mode'] ?? 'RETAIL_SHOP'));
                $licId = (string)($o['license_id'] ?? '');
                $prod = strtoupper((string)($o['purchased_product'] ?? 'VENDPULSE'));
                $isCloud = str_starts_with($licId, 'SPEC-') || $oMode === 'CLOUD_HOSTED' || ($prod !== 'VENDPULSE' && $prod !== 'VP');

                // Heartbeat calculation if deployed
                $hbText = 'Never';
                $hbClass = 'hb-dead';
                if (!empty($o['deployment_last_heartbeat'])) {
                    $mins = max(0, (int)floor((time() - strtotime($o['deployment_last_heartbeat'] . ' UTC')) / 60));
                    if ($mins < 2) {
                        $hbText = 'Just now';
                        $hbClass = 'hb-fresh';
                    } elseif ($mins < 60) {
                        $hbText = $mins . 'm ago';
                        $hbClass = 'hb-fresh';
                    } elseif ($mins < 1440) {
                        $hbText = floor($mins / 60) . 'h ' . ($mins % 60) . 'm ago';
                        $hbClass = 'hb-stale';
                    } else {
                        $hbText = floor($mins / 1440) . 'd ago';
                        $hbClass = 'hb-dead';
                    }
                }

                $depStatus = strtolower((string)($o['deployment_status'] ?? 'healthy'));
                if (!in_array($depStatus, ['healthy', 'warning', 'critical', 'unreachable'], true)) {
                    $depStatus = 'healthy';
                }
              ?>
                <tr>
                  <!-- Column 1: Order Ref & Client -->
                  <td>
                    <div style="display:flex; align-items:center; gap:6px;">
                      <span class="mono" style="font-weight:700; color:var(--accent); font-size:12.5px;"><?= htmlspecialchars($o['order_id']) ?></span>
                      <span style="font-size:10px; color:#10b981; font-weight:700;">✓</span>
                    </div>
                    <div style="font-weight:600; color:var(--text); margin-top:3px; font-size:13px;"><?= htmlspecialchars($o['business_name']) ?></div>
                    <div style="font-size:11.5px; color:var(--text-dim);"><?= htmlspecialchars($o['customer_name']) ?> &lt;<?= htmlspecialchars($o['customer_email']) ?>&gt;</div>
                    <?php if (!empty($o['client_code'])): ?>
                      <div class="mono" style="font-size:10px; color:var(--text-faint); margin-top:2px;">Account: <?= htmlspecialchars($o['client_code']) ?></div>
                    <?php endif; ?>
                  </td>

                  <!-- Column 2: Software System & Architecture -->
                  <td>
                    <?php if ($isCloud): ?>
                      <div style="display:inline-flex; align-items:center; gap:6px; padding:3px 8px; border-radius:6px; background:rgba(14,165,233,0.1); border:1px solid rgba(14,165,233,0.3); color:#38bdf8; font-size:11px; font-weight:700;">
                        ☁️ Cloud SaaS
                      </div>
                      <div style="font-weight:700; color:var(--text); font-size:12.5px; margin-top:4px;">
                        <?= htmlspecialchars($o['product_name'] ?? $prod) ?>
                      </div>
                      <div class="mono" style="font-size:10.5px; color:var(--text-dim); margin-top:2px;">
                        Catalog: <?= htmlspecialchars($o['product_code'] ?? $prod) ?>
                      </div>
                    <?php else: ?>
                      <?php 
                        $modeColor = match ($oMode) {
                            'SUPERMARKET' => '#10b981',
                            'RESTAURANT' => '#f59e0b',
                            default => '#3b82f6',
                        };
                        $modeIcon = match ($oMode) {
                            'SUPERMARKET' => '🛒',
                            'RESTAURANT' => '🍽️',
                            default => '🛍️',
                        };
                      ?>
                      <div style="display:inline-flex; align-items:center; gap:6px; padding:3px 8px; border-radius:6px; background:rgba(255,255,255,0.03); border:1px solid <?= $modeColor ?>; color:<?= $modeColor ?>; font-size:11px; font-weight:700;">
                        <?= $modeIcon ?> <?= htmlspecialchars($oMode) ?>
                      </div>
                      <div style="font-weight:600; color:var(--text); font-size:12.5px; margin-top:4px;">
                        VendPulse POS Engine
                      </div>
                      <div style="font-size:10.5px; color:var(--text-dim); margin-top:2px;">
                        Offline Air-Gapped POS
                      </div>
                    <?php endif; ?>
                  </td>

                  <!-- Column 3: Cloud Deployment & Fleet Status -->
                  <td>
                    <?php if ($isCloud): ?>
                      <?php if (!empty($o['deployment_id'])): ?>
                        <div>
                          <a href="https://<?= htmlspecialchars($o['deployment_domain']) ?>" target="_blank" style="color:#38bdf8; font-weight:700; font-size:12.5px; text-decoration:none; display:inline-flex; align-items:center; gap:4px;" title="Open live production domain">
                            🌐 <?= htmlspecialchars($o['deployment_domain']) ?>
                            <svg viewBox="0 0 24 24" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/></svg>
                          </a>
                        </div>
                        <div style="display:flex; align-items:center; gap:6px; margin-top:4px;">
                          <span class="status-pill <?= $depStatus ?>" style="font-size:10.5px; padding:2px 7px;">
                            <span class="dot dot-<?= $depStatus ?>"></span><?= ucfirst($depStatus) ?>
                          </span>
                          <span class="version-tag" style="font-size:10px;">v<?= htmlspecialchars($o['deployment_version'] ?? '1.0.0') ?></span>
                          <span class="hb-cell <?= $hbClass ?>" style="font-size:10.5px; padding:1px 6px;"><?= $hbText ?></span>
                        </div>
                        <div style="margin-top:6px;">
                          <a href="?page=overview&product_id=<?= (int)$o['product_id'] ?>&deployment_id=<?= (int)$o['deployment_id'] ?>#deployment-feed" class="btn btn-xs btn-outline" style="font-size:10.5px; padding:3px 8px; text-decoration:none; display:inline-flex; align-items:center; gap:4px; border-color:rgba(14,165,233,0.4); color:#38bdf8;" title="Inspect Live Telemetry Feed in Fleet">
                            📡 Fleet Node Feed &rarr;
                          </a>
                        </div>
                      <?php else: ?>
                        <div>
                          <span class="status-pill" style="border-color:rgba(245,158,11,0.4); color:#fbbf24; background:rgba(245,158,11,0.06); font-size:10.5px; padding:2px 7px;">
                            <span class="dot" style="background:#f59e0b; box-shadow:0 0 6px #f59e0b;"></span>Awaiting Deployment
                          </span>
                        </div>
                        <div style="font-size:10.5px; color:var(--text-faint); margin-top:3px;">Domain lock unassigned</div>
                        <div style="margin-top:6px;">
                          <button type="button" class="btn btn-xs btn-outline" style="border-color:rgba(14,165,233,0.4); color:#38bdf8; font-size:10.5px; padding:3px 8px; display:inline-flex; align-items:center; gap:4px;" onclick='openQuickPairForOrder(<?= htmlspecialchars(json_encode([
                              "product_id" => (int)($o["product_id"] ?? 1),
                              "client_name" => $o["business_name"],
                              "license_id" => $o["license_id"],
                              "order_id" => $o["order_id"]
                          ]), ENT_QUOTES) ?>)' title="Assign a production domain to this cloud order">
                            🔗 Pair Domain
                          </button>
                        </div>
                      <?php endif; ?>
                    <?php else: ?>
                      <div>
                        <span class="status-pill ok" style="background:rgba(16,185,129,0.06); border-color:rgba(16,185,129,0.3); color:#34d399; font-size:10.5px; padding:2px 7px;">
                          <span class="dot dot-ok"></span>Air-Gapped POS
                        </span>
                      </div>
                      <div style="font-size:10.5px; color:var(--text-dim); margin-top:3px;">No public telemetry required</div>
                    <?php endif; ?>
                  </td>

                  <!-- Column 4: Entitlements & Capacity -->
                  <td>
                    <div style="font-size:13px; font-weight:700; color:var(--text);">
                      <?= (int)$o['max_counters'] ?> <span style="font-size:11px; font-weight:400; color:var(--text-dim);"><?= $isCloud ? 'Seat(s)' : 'Till(s)' ?></span>
                    </div>
                    <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">
                      <?= !empty($o['term_duration_months']) ? (int)$o['term_duration_months'] . ' Months' : 'Perpetual' ?>
                    </div>
                  </td>

                  <!-- Column 5: License Credentials -->
                  <td>
                    <?php if (!empty($o['license_id'])): ?>
                      <div style="display:flex; align-items:center; gap:4px;">
                        <?php if ($isCloud): ?>
                          <a href="?page=licenses&search=<?= urlencode($o['license_id']) ?>" class="mono" style="font-size:11.5px; color:#38bdf8; text-decoration:none; font-weight:700;" title="View cloud license in Central Licenses">
                            <?= htmlspecialchars($o['license_id']) ?>
                          </a>
                        <?php else: ?>
                          <a href="?page=licenses&view=vendpulse&vp_search=<?= urlencode($o['license_id']) ?>" class="mono" style="font-size:11.5px; color:#38bdf8; text-decoration:none; font-weight:700;" title="View offline license details">
                            <?= htmlspecialchars($o['license_id']) ?>
                          </a>
                        <?php endif; ?>
                        <button type="button" class="icon-btn" style="padding:2px 4px; height:auto; width:auto; border:none; background:transparent;" onclick="copyToClipboard('<?= addslashes((string)$o['license_id']) ?>', 'License key copied!')" title="Copy License Key">
                          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                        </button>
                      </div>
                      <div style="font-size:10px; color:#10b981; margin-top:2px;">✓ Verified Key</div>
                    <?php else: ?>
                      <span style="color:var(--text-faint);">—</span>
                    <?php endif; ?>
                  </td>

                  <!-- Column 6: Self-Service Portal -->
                  <td>
                    <div style="display:flex; align-items:center; gap:6px;">
                      <a href="<?= htmlspecialchars($portalLink) ?>" target="_blank" class="btn btn-sm btn-outline" style="text-decoration:none; padding:4px 8px; font-size:11px; display:inline-flex; align-items:center; gap:4px;">
                        🌐 Portal
                      </a>
                      <button type="button" class="btn btn-sm btn-outline" style="padding:4px 8px; font-size:11px;" onclick="copyToClipboard('<?= addslashes((string)$portalUrl) ?>', 'Portal URL copied to clipboard!')" title="Copy Customer Portal URL">
                        📋 Copy
                      </button>
                    </div>
                    <div style="font-size:10px; color:var(--text-dim); margin-top:3px;">
                      <strong><?= (int)$o['download_count'] ?></strong> downloads
                    </div>
                  </td>

                  <!-- Column 7: Ingress Date -->
                  <td>
                    <span style="font-size:12px; color:var(--text-dim);"><?= htmlspecialchars(substr((string)$o['created_at'], 0, 16)) ?></span>
                  </td>

                  <!-- Column 8: Actions -->
                  <td style="text-align:right;">
                    <div style="display:inline-flex; gap:6px; align-items:center;">
                      <button type="button" class="btn btn-sm btn-outline" style="padding:4px 8px; font-size:11.5px;" onclick='openOrderInspectModal(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)' title="Inspect Order &amp; Telemetry Details">
                        🔍 Inspect
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- ================= MODALS ================= -->

<!-- Modal: Comprehensive Order & Deployment Details Inspect Modal -->
<div class="modal-bg" id="orderInspectModal">
  <div class="modal-box" style="max-width:760px;">
    <div class="modal-header">
      <div class="modal-title" style="display:flex; align-items:center; gap:8px;">
        <span>Order &amp; Fleet Deployment Details</span>
        <span class="status-pill ok" id="inspectOrderBadge">✓ Completed</span>
      </div>
      <button type="button" class="icon-btn" onclick="closeModal('orderInspectModal')">✕</button>
    </div>
    <div class="modal-desc" id="inspectOrderSub">Order details, customer account, license status, and cloud node telemetry.</div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin: 16px 0;">
      <!-- Section: Client & Contact -->
      <div style="background:var(--panel-2); border:1px solid var(--line); border-radius:8px; padding:14px;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--text-dim); font-weight:700; margin-bottom:8px;">Customer &amp; Business Profile</div>
        <div style="font-size:15px; font-weight:700; color:var(--text);" id="inspectBusinessName">—</div>
        <div style="font-size:12.5px; color:var(--text-dim); margin-top:2px;" id="inspectCustomerName">—</div>
        <div style="font-size:11px; color:var(--accent); font-family:var(--font-mono); margin-top:6px;" id="inspectClientCode">Account: —</div>
      </div>

      <!-- Section: Product & Architecture -->
      <div style="background:var(--panel-2); border:1px solid var(--line); border-radius:8px; padding:14px;">
        <div style="font-size:11px; text-transform:uppercase; color:var(--text-dim); font-weight:700; margin-bottom:8px;">Software Product &amp; Entitlement</div>
        <div style="font-size:15px; font-weight:700; color:var(--text);" id="inspectProductName">—</div>
        <div style="font-size:12px; color:var(--text-dim); margin-top:2px;" id="inspectSystemType">—</div>
        <div style="font-size:12px; color:var(--accent); margin-top:4px;" id="inspectSeatsTerm">—</div>
      </div>
    </div>

    <!-- Section: Cloud Deployment Telemetry -->
    <div style="background:rgba(14,165,233,0.04); border:1px solid rgba(14,165,233,0.25); border-radius:8px; padding:16px; margin-bottom:16px;">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
        <div style="font-size:12px; font-weight:700; color:#38bdf8; text-transform:uppercase; display:flex; align-items:center; gap:6px;">
          <span>🌐 Cloud Instance Deployment Node</span>
        </div>
        <div id="inspectDepStatusPill"></div>
      </div>
      <div id="inspectDepDetails" style="font-size:12.5px; color:var(--text); line-height:1.6;">
        <!-- Dynamically injected -->
      </div>
    </div>

    <!-- Section: License Key & Credentials -->
    <div style="margin-bottom:16px;">
      <label style="font-size:11.5px; font-weight:700; color:var(--text-dim); text-transform:uppercase;">Cryptographic License Reference</label>
      <div style="display:flex; gap:8px; margin-top:4px;">
        <input type="text" class="mono" id="inspectLicenseKey" readonly style="flex:1; background:var(--ink); padding:8px 12px; border-radius:6px; border:1px solid var(--line); color:#38bdf8; font-weight:700;">
        <button type="button" class="btn btn-sm btn-outline" onclick="copyToClipboard(document.getElementById('inspectLicenseKey').value, 'License key copied!')">Copy</button>
      </div>
    </div>

    <!-- Section: Customer Portal Link -->
    <div style="margin-bottom:16px;">
      <label style="font-size:11.5px; font-weight:700; color:var(--text-dim); text-transform:uppercase;">Customer Self-Service Portal</label>
      <div style="display:flex; gap:8px; margin-top:4px;">
        <input type="text" class="mono" id="inspectPortalUrl" readonly style="flex:1; background:var(--ink); padding:8px 12px; border-radius:6px; border:1px solid var(--line); color:var(--text);">
        <button type="button" class="btn btn-sm btn-outline" onclick="copyToClipboard(document.getElementById('inspectPortalUrl').value, 'Portal link copied!')">Copy</button>
        <a id="inspectPortalBtn" href="#" target="_blank" class="btn btn-sm btn-primary" style="text-decoration:none;">Open Portal</a>
      </div>
    </div>

    <!-- Collapsible: Raw Ingress Webhook Payload -->
    <details style="margin-top:16px; background:var(--panel-2); border:1px solid var(--line); border-radius:6px; padding:10px;">
      <summary style="font-size:11.5px; font-weight:600; color:var(--text-dim); cursor:pointer;">View Ingress Webhook Raw JSON Payload</summary>
      <pre class="mono" id="inspectRawPayload" style="background:#050810; padding:10px; border-radius:6px; font-size:11px; color:#93c5fd; overflow-x:auto; margin-top:8px; max-height:180px;"></pre>
    </details>

    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
      <button type="button" class="btn btn-primary" onclick="closeModal('orderInspectModal')">Close</button>
    </div>
  </div>
</div>
    </div>

  </main>
</div>

<!-- ================= MODALS ================= -->

<!-- Modal: Order Webhook Documentation -->
<div class="modal-bg" id="orderWebhookModal">
  <div class="modal-box" style="max-width:720px;">
    <div class="modal-header">
      <div class="modal-title">E-Commerce Store Order Webhook Integration</div>
      <button type="button" class="icon-btn" onclick="closeModal('orderWebhookModal')">✕</button>
    </div>
    <div class="modal-desc">
      Configure your WooCommerce, Shopify, or custom checkout webhooks to automatically provision customer licenses and self-service portals for both Cloud and Offline systems.
    </div>

    <div style="margin: 16px 0;">
      <label style="font-size:12px; font-weight:600; color:var(--text-dim);">WEBHOOK INGRESS ENDPOINT (POST)</label>
      <div style="display:flex; gap:8px; margin-top:4px;">
        <input type="text" class="mono" readonly value="<?= htmlspecialchars((getenv('SPECTRAOPS_PUBLIC_URL') ?: 'http://127.0.0.1:8080/SPECTRAOPS/public') . '/api/v1/orders/provision') ?>" style="flex:1; background:var(--ink); padding:8px 12px; border-radius:6px; border:1px solid var(--line); color:var(--accent);">
        <button type="button" class="btn btn-sm btn-outline" onclick="copyToClipboard('<?= htmlspecialchars((getenv('SPECTRAOPS_PUBLIC_URL') ?: 'http://127.0.0.1:8080/SPECTRAOPS/public') . '/api/v1/orders/provision') ?>')">Copy</button>
      </div>
    </div>

    <div style="margin: 16px 0;">
      <label style="font-size:12px; font-weight:600; color:var(--text-dim);">REQUIRED AUTHENTICATION HEADER</label>
      <div style="margin-top:4px;">
        <code class="mono" style="display:block; background:var(--ink); padding:8px 12px; border-radius:6px; border:1px solid var(--line); color:#bef6fd;">
          X-SpectraOps-Webhook-Secret: <?= htmlspecialchars(getenv('SPECTRAOPS_WEBHOOK_SECRET') ?: 'spec_whsec_test_secret_2026_pulse') ?>
        </code>
      </div>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin: 16px 0;">
      <div>
        <label style="font-size:11px; font-weight:600; color:#38bdf8;">☁️ CLOUD SYSTEMS (PRISM-EDU, POS-PRO)</label>
        <pre class="mono" style="background:#050810; padding:10px; border-radius:6px; border:1px solid rgba(56,189,248,0.25); font-size:11px; color:#93c5fd; overflow-x:auto; margin-top:4px;">{
  "order_id": "ORD-2026-CLOUD",
  "customer_name": "Dr. Sarah Mansour",
  "customer_email": "sarah@academy.edu",
  "business_name": "Al-Noor Academy",
  "purchased_product": "PRISM-EDU",
  "usage_mode": "CLOUD_HOSTED",
  "term_duration_months": 12,
  "max_seats": 50,
  "domain_lock": "academy.spectraops.cloud"
}</pre>
      </div>
      <div>
        <label style="font-size:11px; font-weight:600; color:#34d399;">🛒 OFFLINE POS (VENDPULSE)</label>
        <pre class="mono" style="background:#050810; padding:10px; border-radius:6px; border:1px solid rgba(52,211,153,0.25); font-size:11px; color:#86efac; overflow-x:auto; margin-top:4px;">{
  "order_id": "ORD-2026-OFFLINE",
  "customer_name": "Ahmed Ibrahim",
  "customer_email": "ahmed@example.com",
  "business_name": "Al-Amana Groceries",
  "purchased_product": "VENDPULSE",
  "usage_mode": "SUPERMARKET",
  "term_duration_months": 12,
  "max_counters": 3,
  "include_installer_bundle": true
}</pre>
      </div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
      <button type="button" class="btn btn-primary" onclick="closeModal('orderWebhookModal')">Close</button>
    </div>
  </div>
</div>

<!-- Pair Deployment Modal -->
<div class="modal-bg" id="pairModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Pair Client Deployment</div>
      <button type="button" class="icon-btn" onclick="closeModal('pairModal')">✕</button>
    </div>
    <div class="modal-desc">Connect a deployed client instance using the deployment key provided by its installation installer.</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="pair_client">
      <div class="form-group">
        <label>Software Product</label>
        <select name="product_id" required>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Client / Organization Name</label>
        <input type="text" name="client_name" placeholder="e.g. Grand Central Retail Flagship" required>
      </div>
      <div class="form-group">
        <label>Assigned Domain / FQDN</label>
        <input type="text" name="deployment_domain" placeholder="e.g. pos.grandcentral.store" required>
      </div>
      <div class="form-group">
        <label>Client Deployment Key</label>
        <input type="text" name="api_key" placeholder="PRISM-DEP-..." class="mono" required>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('pairModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Pair &amp; Authorize</button>
      </div>
    </form>
  </div>
</div>

<!-- Publish Release Modal -->
<div class="modal-bg" id="releaseModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Publish New Release / Hotfix</div>
      <button type="button" class="icon-btn" onclick="closeModal('releaseModal')">✕</button>
    </div>
    <div class="modal-desc">Upload a new release package (.zip). Packages are cryptographically signed with the hub's offline private key.</div>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="publish_release">
      <div class="form-group">
        <label>Target Product</label>
        <select name="product_id" required>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Release Version</label>
        <input type="text" name="version" placeholder="e.g. 2.5.1" class="mono" required>
      </div>
      <div class="form-group">
        <label>Package Archive (.zip only)</label>
        <input type="file" name="package_file" accept=".zip" required>
      </div>
      <div class="form-group">
        <label>Release Notes / Changelog</label>
        <textarea name="changelog" rows="3" placeholder="Summary of fixes or improvements..."></textarea>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('releaseModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Sign &amp; Distribute</button>
      </div>
    </form>
  </div>
</div>

<!-- Register Deployment Modal -->
<div class="modal-bg" id="regModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Register Deployment</div>
      <button type="button" class="icon-btn" onclick="closeModal('regModal')">✕</button>
    </div>
    <div class="modal-desc">Issue a new client credential token for telemetry reporting and update checks.</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="register_deploy">
      <div class="form-group">
        <label>Software Product</label>
        <select name="product_id" required>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Client Name</label>
        <input type="text" name="client_name" placeholder="e.g. Oakridge Preparatory School" required>
      </div>
      <div class="form-group">
        <label>Deployment Domain</label>
        <input type="text" name="deployment_domain" placeholder="e.g. prism.oakridge.edu" required>
      </div>
      <div class="form-group">
        <label>Environment</label>
        <select name="environment">
          <option value="production">Production</option>
          <option value="staging">Staging</option>
          <option value="development">Development</option>
        </select>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('regModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Generate Ingestion Key</button>
      </div>
    </form>
  </div>
</div>

<!-- Register Product Modal -->
<div class="modal-bg" id="regProductModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Register Product</div>
      <button type="button" class="icon-btn" onclick="closeModal('regProductModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="register_product">
      <div class="form-group">
        <label>Product Name</label>
        <input type="text" name="product_name" placeholder="e.g. POS Pro Store Terminal" required>
      </div>
      <div class="form-group">
        <label>Prefix Code (2-8 chars, e.g. PRISM, POS, MED, CRM)</label>
        <input type="text" name="product_code" placeholder="e.g. POS" class="mono" style="text-transform:uppercase;" maxlength="8">
      </div>
      <div class="form-group">
        <label>Product Slug</label>
        <input type="text" name="product_slug" placeholder="e.g. pos-pro" class="mono" required>
      </div>
      <div class="form-group">
        <label>Description</label>
        <input type="text" name="description" placeholder="Brief description">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('regProductModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Product</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Issue Software License -->
<div class="modal-bg" id="licenseModal">
  <div class="modal-box" style="max-width:540px;">
    <div class="modal-header">
      <div class="modal-title">Issue Software License</div>
      <button type="button" class="icon-btn" onclick="closeModal('licenseModal')">✕</button>
    </div>
    <div class="modal-desc">Select deployment environment to provision an online activation key or air-gapped cryptographic bundle.</div>

    <!-- License Issuance Type Switcher Cards -->
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:16px;">
      <div class="vp-choice-card selected" id="choiceCloudCard" onclick="switchLicenseModalTab('standard')">
        <div style="display:flex; align-items:center; gap:6px; font-weight:700; font-size:13px; color:var(--text);">
          <span>🌐</span> Standard Cloud
        </div>
        <div style="font-size:11px; color:var(--text-dim); margin-top:3px; line-height:1.3;">
          Online HMAC key (PRISM, Connected Deployments)
        </div>
      </div>
      <div class="vp-choice-card" id="choiceVendPulseCard" onclick="switchLicenseModalTab('vendpulse')">
        <div style="display:flex; align-items:center; gap:6px; font-weight:700; font-size:13px; color:var(--ok);">
          <span>⚡</span> VendPulse Offline
        </div>
        <div style="font-size:11px; color:var(--text-dim); margin-top:3px; line-height:1.3;">
          Air-gapped RSA bundle with System Usage Mode
        </div>
      </div>
    </div>

    <!-- Standard Cloud Form -->
    <div id="standardLicenseFormWrap">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="issue_license">
        <input type="hidden" name="page" value="licenses">
        <div class="form-group">
          <label>Target System / Product</label>
          <select name="product_id" id="issueLicenseProductSelect" required onchange="handleProductSelectChange(this)">
            <?php foreach ($products as $p): ?>
              <?php $isVp = ($p['product_code'] === 'VP' || ($p['product_slug'] ?? '') === 'vendpulse'); ?>
              <option value="<?= $p['id'] ?>" data-is-vp="<?= $isVp ? '1' : '0' ?>" <?= $isVp ? 'style="color:#3dd68c; font-weight:600;"' : '' ?>>
                <?= htmlspecialchars($p['product_name']) ?> (Prefix: <?= htmlspecialchars($p['product_code'] ?? 'N/A') ?>) <?= $isVp ? '— ⚡ Offline Generator' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Client / School / Business Name</label>
          <input type="text" id="standardClientName" name="client_name" placeholder="e.g. Al-Amal Academy" required>
        </div>
        <div class="form-group">
          <label>Client Admin Email</label>
          <input type="email" name="client_email" placeholder="admin@clientdomain.com" required>
        </div>
        <div class="form-group">
          <label>License Type</label>
          <select name="license_type" onchange="document.getElementById('expGroup').style.display = (this.value === 'perpetual') ? 'none' : 'block';">
            <option value="perpetual">Perpetual (Lifetime)</option>
            <option value="subscription">Subscription (Annual/Monthly)</option>
            <option value="trial">Trial (Temporary)</option>
          </select>
        </div>
        <div class="form-group" id="expGroup" style="display:none;">
          <label>Expiration Date</label>
          <input type="date" name="expires_at">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
          <button type="button" class="btn" onclick="closeModal('licenseModal')">Cancel</button>
          <button type="submit" class="btn btn-primary">Generate License Key</button>
        </div>
      </form>
    </div>

    <!-- VendPulse Offline Provisioning Launch Card -->
    <div id="vendPulseModalLaunchWrap" style="display:none;">
      <div style="background:linear-gradient(135deg, rgba(61,214,140,0.1) 0%, rgba(79,168,224,0.08) 100%); border:1px solid #3dd68c; border-radius:var(--radius); padding:16px;">
        <div style="display:flex; align-items:center; gap:8px; color:#3dd68c; font-weight:700; font-size:14px; margin-bottom:8px;">
          <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
          VendPulse Offline Cryptographic Generator
        </div>
        <div style="font-size:12.5px; color:var(--text); line-height:1.5; margin-bottom:12px;">
          VendPulse instances operate in air-gapped / offline environments and require an asymmetric <strong>RSA-2048 SHA-256 signed bundle</strong> (<code class="mono">.vpl</code>) binding the authorized <strong>System Usage Mode</strong>:
        </div>
        <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px;">
          <span class="vp-tier-tag vp-tag-retail">🛍️ RETAIL_SHOP</span>
          <span class="vp-tier-tag vp-tag-supermarket">🛒 SUPERMARKET</span>
          <span class="vp-tier-tag vp-tag-restaurant">🍽️ RESTAURANT</span>
        </div>
        <div class="form-group" style="margin-bottom:14px;">
          <label style="font-size:12px;">Client / Business Name (Optional, will prefill):</label>
          <input type="text" id="vpModalClientNamePrefill" placeholder="e.g. Al-Amana Groceries" style="font-size:13px;">
        </div>
        <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:14px;">
          <button type="button" class="btn btn-outline" onclick="closeModal('licenseModal')">Cancel</button>
          <button type="button" class="btn btn-primary" onclick="launchVendPulseGeneratorFromModal()" style="display:inline-flex; align-items:center; gap:6px;">
            <span>Open VendPulse Generator</span>
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal: Renew License & Deployment -->
<div class="modal-bg" id="renewLicenseModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Renew Software License &amp; Deployment</div>
      <button type="button" class="icon-btn" onclick="closeModal('renewLicenseModal')">✕</button>
    </div>
    <div class="modal-desc">Extend subscription term, update expiration date, and restore deployment operations.</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="renew_license">
      <input type="hidden" name="page" value="licenses">
      <input type="hidden" name="license_id" id="renewLicId">

      <div class="modal-meta-grid" style="margin-bottom:16px;">
        <div><strong>Key:</strong> <span class="mono text-accent" id="renewLicKey">—</span></div>
        <div><strong>Client:</strong> <span id="renewLicClient">—</span></div>
        <div><strong>Product:</strong> <span id="renewLicProduct">—</span></div>
        <div><strong>Bound Domain:</strong> <span class="mono" id="renewLicDomain">Unbound</span></div>
      </div>

      <div class="form-group">
        <label>Renewal License Type</label>
        <select name="license_type" id="renewLicType" onchange="toggleRenewExpDisplay(this.value)">
          <option value="subscription">Subscription (Fixed Duration)</option>
          <option value="perpetual">Perpetual (No Expiration)</option>
          <option value="trial">Trial (Temporary)</option>
        </select>
      </div>

      <div class="form-group" id="renewExpGroup">
        <label>Quick Term Presets</label>
        <div style="display:flex; gap:6px; margin-bottom:8px; flex-wrap:wrap;">
          <button type="button" class="chip" onclick="setRenewalDate(30)">+30 Days</button>
          <button type="button" class="chip" onclick="setRenewalDate(90)">+90 Days</button>
          <button type="button" class="chip" onclick="setRenewalDate(180)">+6 Months</button>
          <button type="button" class="chip" onclick="setRenewalDate(365)">+1 Year</button>
          <button type="button" class="chip" onclick="setRenewalDate(730)">+2 Years</button>
        </div>
        <label style="margin-top:6px;">New Expiration Date</label>
        <input type="date" name="expires_at" id="renewLicExpiresAt">
      </div>

      <div class="form-group">
        <label>Renewal Reason / Reference Notes (Optional)</label>
        <input type="text" name="reason" placeholder="e.g. Contract extended, renewed via operator console">
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('renewLicenseModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Renew License &amp; Deployment</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Suspend License -->
<div class="modal-bg" id="suspendLicenseModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title" style="color:var(--warn);">Suspend License &amp; Deployment</div>
      <button type="button" class="icon-btn" onclick="closeModal('suspendLicenseModal')">✕</button>
    </div>
    <div class="modal-desc">
      Suspending this license temporarily blocks client activation, release updates, and telemetry ingestion.
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="suspend_license">
      <input type="hidden" name="page" value="licenses">
      <input type="hidden" name="license_id" id="suspendLicId">

      <div class="modal-meta-grid" style="margin-bottom:16px;">
        <div><strong>Key:</strong> <span class="mono text-accent" id="suspendLicKey">—</span></div>
        <div><strong>Client:</strong> <span id="suspendLicClient">—</span></div>
        <div style="grid-column: span 2;"><strong>Bound Deployment Domain:</strong> <span class="mono" id="suspendLicDomain">None</span></div>
      </div>

      <div class="alert-strip warn" style="margin-bottom:14px; padding:10px 12px; font-size:12px; border-radius:var(--radius);">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>The bound deployment will be flagged as inactive and barred from receiving system updates until resumed or renewed.</span>
      </div>

      <div class="form-group">
        <label>Reason for Suspension</label>
        <input type="text" name="reason" placeholder="e.g. Overdue payment, account audit, maintenance" required>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('suspendLicenseModal')">Cancel</button>
        <button type="submit" class="btn" style="background:var(--warn-dim); border-color:var(--warn); color:var(--warn-text); font-weight:600;">Confirm Suspension</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Revoke License -->
<div class="modal-bg" id="revokeLicenseModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title" style="color:var(--crit);">Permanently Revoke License</div>
      <button type="button" class="icon-btn" onclick="closeModal('revokeLicenseModal')">✕</button>
    </div>
    <div class="modal-desc">
      Revoking a license permanently terminates access. The bound client deployment will be disconnected.
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="revoke_license">
      <input type="hidden" name="page" value="licenses">
      <input type="hidden" name="license_id" id="revokeLicId">

      <div class="modal-meta-grid" style="margin-bottom:16px;">
        <div><strong>Key:</strong> <span class="mono text-accent" id="revokeLicKey">—</span></div>
        <div><strong>Client:</strong> <span id="revokeLicClient">—</span></div>
        <div style="grid-column: span 2;"><strong>Bound Deployment Domain:</strong> <span class="mono" id="revokeLicDomain">None</span></div>
      </div>

      <div class="alert-strip danger" style="margin-bottom:14px; padding:10px 12px; font-size:12px; border-radius:var(--radius);">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span>Warning: This action terminates service authorization for the deployment. This should only be executed for contract cancellation or security compromise.</span>
      </div>

      <div class="form-group">
        <label>Reason for Revocation</label>
        <input type="text" name="reason" placeholder="e.g. Contract cancelled, breach of licensing terms" required>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('revokeLicenseModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Revoke License &amp; Decommission</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Delete Deployment Confirmation -->
<div class="modal-bg" id="deleteDeploymentModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title" style="color:var(--crit); display:flex; align-items:center; gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
        Remove Deployed System
      </div>
      <button type="button" class="icon-btn" onclick="closeModal('deleteDeploymentModal')">✕</button>
    </div>
    <div class="modal-desc">
      Are you sure you want to permanently delete this monitored deployment?
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="delete_deployment">
      <input type="hidden" name="page" value="<?= htmlspecialchars($activePage) ?>">
      <input type="hidden" name="deployment_id" id="deleteModalDepId">

      <div class="modal-meta-grid" style="margin-bottom:16px;">
        <div><strong>Product:</strong> <span class="product-badge" id="deleteModalProduct">—</span></div>
        <div><strong>Client:</strong> <span id="deleteModalClient" style="font-weight:600;">—</span></div>
        <div style="grid-column: span 2;"><strong>Domain:</strong> <span class="mono text-accent" id="deleteModalDomain">—</span></div>
      </div>

      <div class="alert-strip danger" style="margin-bottom:16px; padding:12px; font-size:12px; border-radius:var(--radius); display:flex; gap:10px; align-items:flex-start;">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0; margin-top:1px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <div>
          <strong>Permanent Deletion Warning:</strong>
          <p style="margin:4px 0 0 0; color:var(--text-dim);">
            All linked telemetry heartbeats, diagnostic metrics, and crash logs will be permanently deleted. If an active software license is bound to this system, it will be automatically decoupled and reset to unactivated.
          </p>
        </div>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('deleteDeploymentModal')">Cancel</button>
        <button type="submit" class="btn btn-danger" style="display:flex; align-items:center; gap:6px;">
          <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
          Permanently Delete Deployment
        </button>
      </div>
    </form>
  </div>
</div>

<!-- Register User Modal -->
<div class="modal-bg" id="regUserModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Create User Account</div>
      <button type="button" class="icon-btn" onclick="closeModal('regUserModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="register_user">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="user_name" placeholder="e.g. Elena Rostova" required>
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="user_email" placeholder="elena@domain.com" required>
      </div>
      <div class="form-group">
        <label>Role</label>
        <select name="user_role" required>
          <option value="admin">Administrator (Full Control)</option>
          <option value="viewer">Viewer (Read Only)</option>
        </select>
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="user_password" placeholder="Min 8 characters" required minlength="8">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('regUserModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal-bg" id="editUserModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Edit Operator Account</div>
      <button type="button" class="icon-btn" onclick="closeModal('editUserModal')">✕</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="update_user">
      <input type="hidden" name="user_id" id="editUserId">
      <div class="form-group">
        <label>Full Name</label>
        <input type="text" name="user_name" id="editUserName" required>
      </div>
      <div class="form-group">
        <label>Email Address</label>
        <input type="email" name="user_email" id="editUserEmail" required>
      </div>
      <div class="form-group">
        <label>Role</label>
        <select name="user_role" id="editUserRole" required>
          <option value="admin">Administrator (Full Control)</option>
          <option value="viewer">Viewer (Read Only)</option>
        </select>
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('editUserModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-bg" id="resetPasswordModal">
  <div class="modal-box">
    <div class="modal-header">
      <div class="modal-title">Reset Operator Password</div>
      <button type="button" class="icon-btn" onclick="closeModal('resetPasswordModal')">✕</button>
    </div>
    <div class="modal-desc" id="resetPasswordDesc">Update access credentials for operator.</div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      <input type="hidden" name="action" value="reset_user_password">
      <input type="hidden" name="user_id" id="resetPasswordUserId">
      <div class="form-group">
        <label>New Password (min 8 characters)</label>
        <input type="password" name="new_password" placeholder="Enter new password" required minlength="8">
      </div>
      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
        <button type="button" class="btn" onclick="closeModal('resetPasswordModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Update Password</button>
      </div>
    </form>
  </div>
</div>

<!-- System Diagnostics Inspector Modal -->
<div class="modal-bg" id="systemDetailModal">
  <div class="modal-box" style="max-width: 560px;">
    <div class="modal-header">
      <div>
        <div class="modal-title" id="sysModalClient">System Diagnostics</div>
        <div class="mono" id="sysModalDomain" style="font-size:11px; color:var(--text-faint); margin-top:2px;"></div>
      </div>
      <button type="button" class="icon-btn" onclick="closeModal('systemDetailModal')">✕</button>
    </div>

    <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:16px;">
      <div style="background:var(--panel-2); padding:12px; border-radius:var(--radius); border:1px solid var(--line);">
        <div style="font-size:11px; color:var(--text-dim); text-transform:uppercase;">Response Latency</div>
        <div id="sysModalLatency" class="mono" style="font-size:18px; font-weight:600; color:var(--text); margin-top:2px;">—</div>
      </div>
      <div style="background:var(--panel-2); padding:12px; border-radius:var(--radius); border:1px solid var(--line);">
        <div style="font-size:11px; color:var(--text-dim); text-transform:uppercase;">Memory Usage</div>
        <div id="sysModalMemory" class="mono" style="font-size:18px; font-weight:600; color:var(--text); margin-top:2px;">—</div>
      </div>
      <div style="background:var(--panel-2); padding:12px; border-radius:var(--radius); border:1px solid var(--line);">
        <div style="font-size:11px; color:var(--text-dim); text-transform:uppercase;">Free Disk Space</div>
        <div id="sysModalDisk" class="mono" style="font-size:18px; font-weight:600; color:var(--text); margin-top:2px;">—</div>
      </div>
      <div style="background:var(--panel-2); padding:12px; border-radius:var(--radius); border:1px solid var(--line);">
        <div style="font-size:11px; color:var(--text-dim); text-transform:uppercase;">Active Users (24h)</div>
        <div id="sysModalUsers" class="mono" style="font-size:18px; font-weight:600; color:var(--accent); margin-top:2px;">—</div>
      </div>
    </div>

    <div style="background:var(--ink); border:1px solid var(--line); border-radius:var(--radius); padding:12px; font-size:12px; margin-bottom:16px;">
      <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
        <span style="color:var(--text-dim);">Product &amp; Version:</span>
        <strong id="sysModalProduct"></strong>
      </div>
      <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
        <span style="color:var(--text-dim);">Environment:</span>
        <span id="sysModalEnv" class="product-badge"></span>
      </div>
      <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
        <span style="color:var(--text-dim);">Server IP Address:</span>
        <code id="sysModalIp" class="mono"></code>
      </div>
      <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
        <span style="color:var(--text-dim);">PHP &amp; Database:</span>
        <span id="sysModalStack" class="mono"></span>
      </div>
      <div style="display:flex; justify-content:space-between; margin-bottom:6px;">
        <span style="color:var(--text-dim);">Operating System:</span>
        <span id="sysModalOs"></span>
      </div>
      <div style="display:flex; justify-content:space-between;">
        <span style="color:var(--text-dim);">Health Diagnostic Note:</span>
        <span id="sysModalReason" style="color:var(--accent);"></span>
      </div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center;">
      <span id="sysModalStatusBadge" class="status-pill"></span>
      <div style="display:flex; gap:8px;">
        <button type="button" class="btn" onclick="closeModal('systemDetailModal')">Close</button>
        <a id="sysModalFeedBtn" href="#" class="btn btn-outline" style="border-color:var(--accent); color:var(--accent); text-decoration:none;">Errors &amp; Tickets &rarr;</a>
        <a id="sysModalVisitBtn" href="#" target="_blank" class="btn btn-primary">Open Site ↗</a>
      </div>
    </div>
  </div>
</div>

<script>
function openModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.add('active');
}
function closeModal(id) {
  var el = document.getElementById(id);
  if (el) el.classList.remove('active');
}

// Close modals when clicking outside modal box
document.querySelectorAll('.modal-bg').forEach(function(bg) {
  bg.addEventListener('click', function(e) {
    if (e.target === bg) {
      bg.classList.remove('active');
    }
  });
});

function openSystemModal(dep) {
  document.getElementById('sysModalClient').textContent = dep.client_name || 'Deployment';
  document.getElementById('sysModalDomain').textContent = dep.deployment_domain || '';
  document.getElementById('sysModalProduct').textContent = (dep.product_name || '') + ' (v' + (dep.current_version || '1.0.0') + ')';
  document.getElementById('sysModalEnv').textContent = (dep.environment || 'production').toUpperCase();
  document.getElementById('sysModalIp').textContent = dep.server_ip || 'Awaiting heartbeat';
  document.getElementById('sysModalStack').textContent = (dep.php_version ? 'PHP ' + dep.php_version : 'PHP ?') + ' / ' + (dep.mysql_version ? 'DB ' + dep.mysql_version : 'DB ?');
  document.getElementById('sysModalOs').textContent = dep.os_info || 'Unknown OS';
  document.getElementById('sysModalReason').textContent = dep.status_reason || 'Normal operation';

  document.getElementById('sysModalLatency').textContent = (dep.db_latency_ms !== null && dep.db_latency_ms !== undefined) ? dep.db_latency_ms + ' ms' : '—';
  document.getElementById('sysModalMemory').textContent = (dep.memory_usage_mb !== null && dep.memory_usage_mb !== undefined) ? Math.round(dep.memory_usage_mb) + ' MB' : '—';
  document.getElementById('sysModalDisk').textContent = (dep.storage_free_gb !== null && dep.storage_free_gb !== undefined) ? Math.round(dep.storage_free_gb * 10) / 10 + ' GB' : '—';
  document.getElementById('sysModalUsers').textContent = (dep.active_users_24h !== null && dep.active_users_24h !== undefined) ? Number(dep.active_users_24h).toLocaleString() : '—';

  var status = dep.computed_status || dep.status || 'healthy';
  var badge = document.getElementById('sysModalStatusBadge');
  badge.className = 'status-pill ' + (status === 'unreachable' ? 'dead' : status);
  badge.innerHTML = '<span class="dot dot-' + (status === 'unreachable' ? 'dead' : status) + '"></span>' + status;

  document.getElementById('sysModalVisitBtn').href = 'https://' + (dep.deployment_domain || '#');
  var feedBtn = document.getElementById('sysModalFeedBtn');
  if (feedBtn) feedBtn.href = '?page=overview&product_id=' + (dep.product_id || 1) + '&deployment_id=' + (dep.id || 0) + '#deployment-feed';
  openModal('systemDetailModal');
}

function openEditUserModal(u) {
  document.getElementById('editUserId').value = u.id || '';
  document.getElementById('editUserName').value = u.name || '';
  document.getElementById('editUserEmail').value = u.email || '';
  document.getElementById('editUserRole').value = u.role || 'viewer';
  openModal('editUserModal');
}

function openResetPasswordModal(id, name) {
  document.getElementById('resetPasswordUserId').value = id;
  document.getElementById('resetPasswordDesc').textContent = 'Update access password for operator: ' + name;
  openModal('resetPasswordModal');
}

function openRenewModal(lic) {
  document.getElementById('renewLicId').value = lic.id || '';
  document.getElementById('renewLicKey').textContent = lic.license_key || '—';
  document.getElementById('renewLicClient').textContent = lic.client_name || '—';
  document.getElementById('renewLicProduct').textContent = (lic.product_name || '') + ' (' + (lic.product_code || '—') + ')';
  document.getElementById('renewLicDomain').textContent = lic.deployment_domain || 'Unbound';

  var typeSelect = document.getElementById('renewLicType');
  typeSelect.value = lic.license_type || 'subscription';
  toggleRenewExpDisplay(typeSelect.value);

  var expInput = document.getElementById('renewLicExpiresAt');
  if (lic.expires_at) {
    expInput.value = lic.expires_at.split(' ')[0];
  } else {
    expInput.value = '';
  }
  openModal('renewLicenseModal');
}

function toggleRenewExpDisplay(val) {
  var expGroup = document.getElementById('renewExpGroup');
  if (expGroup) {
    expGroup.style.display = (val === 'perpetual') ? 'none' : 'block';
  }
}

function setRenewalDate(days) {
  var d = new Date();
  d.setDate(d.getDate() + days);
  var yyyy = d.getFullYear();
  var mm = String(d.getMonth() + 1).padStart(2, '0');
  var dd = String(d.getDate()).padStart(2, '0');
  var expInput = document.getElementById('renewLicExpiresAt');
  if (expInput) {
    expInput.value = yyyy + '-' + mm + '-' + dd;
  }
  var typeSelect = document.getElementById('renewLicType');
  if (typeSelect && typeSelect.value === 'perpetual') {
    typeSelect.value = 'subscription';
    toggleRenewExpDisplay('subscription');
  }
}

function openSuspendModal(lic) {
  document.getElementById('suspendLicId').value = lic.id || '';
  document.getElementById('suspendLicKey').textContent = lic.license_key || '—';
  document.getElementById('suspendLicClient').textContent = lic.client_name || '—';
  document.getElementById('suspendLicDomain').textContent = lic.deployment_domain || 'Unbound';
  openModal('suspendLicenseModal');
}

function openRevokeModal(lic) {
  document.getElementById('revokeLicId').value = lic.id || '';
  document.getElementById('revokeLicKey').textContent = lic.license_key || '—';
  document.getElementById('revokeLicClient').textContent = lic.client_name || '—';
  document.getElementById('revokeLicDomain').textContent = lic.deployment_domain || 'Unbound';
  openModal('revokeLicenseModal');
}

function openDeleteDeploymentModal(depId, clientName, domain, productName) {
  document.getElementById('deleteModalDepId').value = depId || '';
  document.getElementById('deleteModalClient').textContent = clientName || '—';
  document.getElementById('deleteModalDomain').textContent = domain || '—';
  document.getElementById('deleteModalProduct').textContent = productName || 'System';
  openModal('deleteDeploymentModal');
}

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  try { localStorage.setItem('spectraops_theme', theme); } catch(e) {}
  document.querySelectorAll('.theme-toggle-label').forEach(function(el) {
    el.textContent = theme === 'light' ? 'Light' : 'Dark';
  });
  document.querySelectorAll('.theme-toggle-btn').forEach(function(btn) {
    btn.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
    btn.setAttribute('title', 'Switch to ' + (theme === 'light' ? 'Dark' : 'Light') + ' mode');
  });
}

function toggleTheme() {
  var current = document.documentElement.getAttribute('data-theme') || 'dark';
  applyTheme(current === 'light' ? 'dark' : 'light');
}

document.addEventListener('DOMContentLoaded', function() {
  var saved = 'dark';
  try { saved = localStorage.getItem('spectraops_theme') || 'dark'; } catch(e) {}
  applyTheme(saved);
});
</script>

<!-- Upload & Publish Patch Modal -->
<div class="modal-bg" id="patchModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.75); z-index:9999; align-items:center; justify-content:center;">
    <div class="modal" style="background:#0e2448; padding:24px; border-radius:10px; width:440px; border:1px solid #0dc0e7; color:#eefdff;">
        <h3 style="margin-top:0; color:#bef6fd;">Publish System Patch / Hotfix</h3>
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="publish_patch">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
            
            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:#92c6de;">Target System</label>
                <select name="product_id" id="patchProductId" style="width:100%; padding:8px; background:#091830; border:1px solid rgba(13,192,231,0.3); color:#fff; border-radius:6px;" required>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['product_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:#92c6de;">New Version Tag (SemVer)</label>
                <input type="text" name="version" placeholder="2.5.1" style="width:100%; padding:8px; background:#091830; border:1px solid rgba(13,192,231,0.3); color:#fff; border-radius:6px; box-sizing:border-box;" required>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:#92c6de;">Changelog / Fix Summary</label>
                <textarea name="changelog" rows="3" placeholder="Fixed null pointer exception..." style="width:100%; padding:8px; background:#091830; border:1px solid rgba(13,192,231,0.3); color:#fff; border-radius:6px; box-sizing:border-box;" required></textarea>
            </div>

            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:0.8rem; margin-bottom:4px; color:#92c6de;">Patch Archive (.ZIP)</label>
                <input type="file" name="patch_zip" accept=".zip" style="width:100%; color:#92c6de;" required>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" style="padding:6px 12px; background:transparent; border:1px solid #92c6de; color:#92c6de; border-radius:6px; cursor:pointer;" onclick="closePatchModal()">Cancel</button>
                <button type="submit" class="btn" style="padding:6px 14px; background:#f59e0b; color:#091830; font-weight:700; border:none; border-radius:6px; cursor:pointer;">Publish &amp; Distribute</button>
            </div>
        </form>
    </div>
</div>

<!-- Enhanced AI Review & Visual Diff Modal -->
<div class="modal-bg" id="aiReviewModal">
    <div class="modal-box" style="max-width: 1080px; width: 95vw; max-height: 92vh; display: flex; flex-direction: column; padding: 24px;">
        
        <!-- Header -->
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; padding-bottom:12px; border-bottom:1px solid var(--line);">
            <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <span class="btn-ai" style="display:inline-flex; align-items:center; gap:5px; padding:3px 9px; font-size:11px; border-radius:12px; pointer-events:none;">
                    ✨ AI Diagnostics
                </span>
                <div class="modal-title" style="margin:0; font-size:15px; font-weight:600; color:var(--text);">
                    Automated Hotfix Review
                </div>
                <span id="aiVersionBadge" class="mono" style="font-size:11px; padding:2px 8px; border-radius:var(--radius); background:var(--panel-2); border:1px solid var(--line); color:var(--accent); font-weight:600;"></span>
            </div>
            <div style="display:flex; align-items:center; gap:12px;">
                <div style="font-size:11.5px; color:var(--text-dim);">
                    Target: <code id="aiDiffFilePath" class="mono" style="color:var(--accent); background:var(--ink); padding:2px 7px; border-radius:var(--radius); border:1px solid var(--line);"></code>
                </div>
                <button type="button" class="icon-btn" onclick="closeModal('aiReviewModal')">✕</button>
            </div>
        </div>

        <!-- Scrollable Body Content -->
        <div style="overflow-y:auto; flex:1; padding-right:4px;">
            <!-- Diagnosis Summary Cards -->
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div style="background:var(--panel-2); padding:12px 14px; border-radius:var(--radius); border:1px solid var(--line); border-left:3px solid #a855f7;">
                    <div style="display:flex; align-items:center; gap:6px; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#c084fc;">
                        <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#a855f7;"></span>
                        Root Cause Analysis
                    </div>
                    <p id="aiRootCause" style="margin:6px 0 0; font-size:12.5px; color:var(--text); line-height:1.5;"></p>
                </div>
                <div style="background:var(--panel-2); padding:12px 14px; border-radius:var(--radius); border:1px solid var(--line); border-left:3px solid var(--accent);">
                    <div style="display:flex; align-items:center; gap:6px; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--accent);">
                        <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent);"></span>
                        Proposed Remediation
                    </div>
                    <p id="aiFixSummary" style="margin:6px 0 0; font-size:12.5px; color:var(--text); line-height:1.5;"></p>
                </div>
            </div>

            <!-- Visual Side-by-Side Diff Mount Point -->
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                <div style="display:flex; align-items:center; gap:6px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-dim);">
                    <span class="dot dot-ok"></span>
                    Code Comparison (Side-by-Side Unified Diff)
                </div>
                <div style="font-size:11px; color:var(--text-faint);" class="mono">
                    Unified Git Diff &bull; Auto-verified syntax
                </div>
            </div>
            <div class="diff-container" id="visualDiffMount"></div>
        </div>

        <!-- Footer / Action Controls -->
        <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
            <div style="font-size:11.5px; color:var(--text-dim); display:flex; align-items:center; gap:8px;">
                <span>Archive: <code id="aiArchiveName" class="mono" style="color:var(--accent);"></code></span>
                <span style="color:var(--line);">|</span>
                <span>SHA-256: <code id="aiChecksum" class="mono" style="color:var(--ok); font-size:11px;"></code></span>
            </div>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="action" value="approve_ai_patch">
                <input type="hidden" name="csrf_token" id="aiApproveCsrfToken" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="error_id" id="aiApproveErrorId" value="">
                <div style="display:flex; gap:10px;">
                    <button type="button" class="btn btn-outline" onclick="closeModal('aiReviewModal')">Dismiss</button>
                    <button type="submit" class="btn btn-success" style="display:flex; align-items:center; gap:6px;">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Approve &amp; Release to Fleet
                    </button>
                </div>
            </form>
        </div>

    </div>
</div>

<!-- Modal: Upload & Sync Master Project Codebase -->
<div class="modal-bg" id="codebaseModal">
    <div class="modal-box" style="max-width: 520px;">
        <div class="modal-header">
            <div style="display:flex; align-items:center; gap:8px;">
                <span style="font-size:16px;">📦</span>
                <div class="modal-title">Sync Master Codebase</div>
            </div>
            <button type="button" class="icon-btn" onclick="closeModal('codebaseModal')">✕</button>
        </div>

        <p class="modal-desc">
            Upload the clean baseline source code (<code>.zip</code>) of any existing or new system. SpectraOps will store it under <code>/storage/codebases/{slug}</code> so the AI diagnostic engine can inspect files and build targeted hotfixes.
        </p>

        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="action" value="sync_codebase">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="form-group">
                <label>Target System / Product</label>
                <select name="product_id" id="codebaseProductSelect" required>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= (isset($productId) && $productId === $p['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['product_name']) ?> (<?= htmlspecialchars($p['product_code']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Base Version Tag</label>
                <input type="text" name="base_version" id="codebaseVersionInput" placeholder="1.0.0" required>
            </div>

            <div class="form-group">
                <label>Codebase Archive (.ZIP)</label>
                <input type="file" name="codebase_zip" accept=".zip" required>
                <small style="display:block; font-size:11px; color:var(--text-faint); margin-top:4px;">
                    Ensure <code>.env</code>, secret keys, and client uploads are excluded from this zip.
                </small>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:20px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('codebaseModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Extract &amp; Sync Codebase</button>
            </div>
        </form>

    </div>
</div>

<!-- Modal: VendPulse Public Key Infrastructure -->
<div class="modal-bg" id="vpPubKeyModal">
  <div class="modal-box" style="max-width: 680px; width: 95vw;">
    <div class="modal-header">
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="font-size:20px;">🔑</span>
        <div>
          <div class="modal-title">VendPulse Public Key Infrastructure</div>
          <div style="font-size:12px; color:var(--text-dim); margin-top:2px;">RSA-2048 with SHA-256 (SHA256withRSA) Verification Certificate</div>
        </div>
      </div>
      <button type="button" class="icon-btn" onclick="closeModal('vpPubKeyModal')">✕</button>
    </div>

    <p class="modal-desc" style="margin-top:8px;">
      This public key is distributed with VendPulse client apps to cryptographically verify offline license signatures without network access.
      <strong style="color:var(--ok);">It is 100% safe to embed in client binaries or configuration files.</strong>
      The matching private signing key remains permanently protected on this SpectraOps server.
    </p>

    <div style="background:var(--panel-2); border:1px solid var(--line); border-radius:var(--radius); padding:10px 14px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
      <div style="font-size:11.5px; color:var(--text-dim);">
        Algorithm: <strong style="color:var(--accent);">RSA-2048</strong> &bull;
        Digest: <strong style="color:var(--accent);">SHA-256</strong> &bull;
        Storage: <code class="mono" style="color:var(--text); font-size:11px;">keys/vendpulse_public.pem</code>
      </div>
      <span class="status-pill ok" style="font-size:11px;">
        <span class="dot dot-ok"></span> ACTIVE &amp; SECURED
      </span>
    </div>

    <div class="form-group">
      <label style="display:flex; justify-content:space-between; align-items:center;">
        <span>Public Key PEM Format (SPKI)</span>
        <span style="font-size:11px; color:var(--text-faint);">Click to select all</span>
      </label>
      <textarea id="vpPubKeyText" class="mono" rows="9" readonly onclick="this.select()" style="font-size:11.5px; background:var(--ink); color:#bef6fd; border:1px solid var(--line); padding:12px; width:100%; border-radius:var(--radius); box-sizing:border-box; resize:vertical;"><?= htmlspecialchars($vpPublicKey) ?></textarea>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid var(--line); padding-top:16px;">
      <a href="?download_vp_pubkey=1" class="btn" style="text-decoration:none;">
        📥 Download public.pem
      </a>
      <div style="display:flex; gap:10px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('vpPubKeyModal')">Close</button>
        <button type="button" class="btn btn-primary" onclick="copyVpRawText(document.getElementById('vpPubKeyText').value, this)">
          📋 Copy Public Key
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: VendPulse Offline License Verifier -->
<div class="modal-bg" id="vpVerifyModal">
  <div class="modal-box" style="max-width: 720px; width: 95vw; max-height: 90vh; display: flex; flex-direction: column;">
    <div class="modal-header">
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="font-size:20px;">🛡️</span>
        <div>
          <div class="modal-title">Offline Cryptographic License Verifier</div>
          <div style="font-size:12px; color:var(--text-dim); margin-top:2px;">Validate signature, tamper status, hardware lock, and expiration date</div>
        </div>
      </div>
      <button type="button" class="icon-btn" onclick="closeModal('vpVerifyModal')">✕</button>
    </div>

    <div style="overflow-y:auto; flex:1; padding-right:4px;">
      <p class="modal-desc" style="margin-top:6px;">
        Paste any generated VendPulse license (either raw JSON with <code class="mono">payload</code> and <code class="mono">signature</code>, or Base64 bundle string). SpectraOps will verify it using the public key exactly as an air-gapped terminal does.
      </p>

      <div class="form-group">
        <label for="vpVerifyInput">License Bundle (JSON or Base64 String) <span style="color:var(--crit)">*</span></label>
        <textarea id="vpVerifyInput" class="mono" rows="5" placeholder='Paste {"payload": {...}, "signature": "..."} or Base64 bundle here...' style="font-size:12px; background:var(--ink); color:var(--text); width:100%; box-sizing:border-box; border-radius:var(--radius); border:1px solid var(--line); padding:10px;"></textarea>
      </div>

      <div class="form-group">
        <label for="vpVerifyHwid">Hardware Fingerprint / HWID Check <span style="color:var(--text-faint); font-weight:normal;">(Optional - simulate client machine ID)</span></label>
        <input type="text" id="vpVerifyHwid" class="mono" placeholder="Enter hardware UUID if this license is machine-locked" style="font-size:12px;">
      </div>

      <div style="margin-top:14px;">
        <button type="button" id="btnRunVpVerify" class="btn btn-primary" onclick="runVpVerification(this)" style="width:100%; justify-content:center; padding:10px;">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
          Run Cryptographic Verification
        </button>
      </div>

      <!-- Verification Result Container -->
      <div id="vpVerifyResult" style="display:none; margin-top:18px;"></div>
    </div>

    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px; border-top:1px solid var(--line); padding-top:14px;">
      <button type="button" class="btn btn-outline" onclick="closeModal('vpVerifyModal')">Close</button>
    </div>
  </div>
</div>

<!-- Modal: VendPulse License Details Inspector -->
<div class="modal-bg" id="vpViewModal">
  <div class="modal-box" style="max-width: 780px; width: 95vw; max-height: 90vh; display: flex; flex-direction: column;">
    <div class="modal-header">
      <div style="display:flex; align-items:center; gap:10px;">
        <span style="font-size:20px;">📜</span>
        <div>
          <div class="modal-title">VendPulse License Inspector</div>
          <div style="font-size:12px; color:var(--text-dim); margin-top:2px;">Cryptographic credentials and deployment claims</div>
        </div>
      </div>
      <button type="button" class="icon-btn" onclick="closeModal('vpViewModal')">✕</button>
    </div>

    <div style="overflow-y:auto; flex:1; padding-right:4px;">
      <div style="display:flex; justify-content:space-between; align-items:center; background:var(--panel-2); padding:12px 16px; border-radius:var(--radius); border:1px solid var(--line); margin-bottom:14px;">
        <div>
          <div style="font-size:11px; text-transform:uppercase; color:var(--text-faint); font-weight:600;">License Reference</div>
          <div id="vpViewLicenseId" class="mono" style="font-size:16px; font-weight:700; color:var(--accent);"></div>
          <div id="vpViewClient" style="font-size:13px; color:var(--text); margin-top:2px; font-weight:600;"></div>
        </div>
        <div style="text-align:right;">
          <div id="vpViewStatusBadge"></div>
          <div style="display:flex; gap:6px; justify-content:flex-end; margin-top:6px; align-items:center;">
            <div id="vpViewTierBadge"></div>
            <div id="vpViewUsageModeBadge"></div>
          </div>
        </div>
      </div>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div style="background:var(--panel-2); padding:12px; border-radius:var(--radius); border:1px solid var(--line);">
          <div style="font-size:11px; color:var(--text-faint); text-transform:uppercase; font-weight:600;">Operational Mode &amp; Limits</div>
          <div style="margin-top:6px; font-size:13px;">
            <div>Usage Mode: <strong id="vpViewUsageModeText" style="color:var(--ok);"></strong></div>
            <div style="margin-top:4px;">Max POS Stations: <strong id="vpViewCounters" class="mono" style="color:var(--accent);"></strong></div>
            <div style="margin-top:4px;">Hardware Fingerprint: <code id="vpViewHwid" class="mono" style="font-size:11.5px; color:var(--text-dim);"></code></div>
          </div>
        </div>

        <div style="background:var(--panel-2); padding:12px; border-radius:var(--radius); border:1px solid var(--line);">
          <div style="font-size:11px; color:var(--text-faint); text-transform:uppercase; font-weight:600;">Validity Dates</div>
          <div style="margin-top:6px; font-size:13px;">
            <div>Issued At: <span id="vpViewIssuedAt" class="mono" style="color:var(--text-dim);"></span></div>
            <div style="margin-top:4px;">Expires At: <span id="vpViewExpiresAt" class="mono"></span></div>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>Allowed Application Modules</label>
        <div id="vpViewModules" style="display:flex; flex-wrap:wrap; gap:6px; margin-top:4px;"></div>
      </div>

      <div class="form-group" style="margin-top:14px;">
        <label style="display:flex; justify-content:space-between; align-items:center;">
          <span>Raw Cryptographic Bundle (Canonical Payload + RSA Signature)</span>
          <span style="font-size:11px; color:var(--text-faint);">Format: JSON</span>
        </label>
        <textarea id="vpViewBundleBox" class="mono" rows="7" readonly onclick="this.select()" style="font-size:11.5px; background:var(--ink); color:var(--text); width:100%; border-radius:var(--radius); border:1px solid var(--line); padding:10px; box-sizing:border-box;"></textarea>
      </div>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; border-top:1px solid var(--line); padding-top:14px;">
      <a id="vpViewDownloadLink" href="#" class="btn btn-primary" style="text-decoration:none;">
        📥 Download .vpl File
      </a>
      <div style="display:flex; gap:10px;">
        <button type="button" class="btn btn-outline" onclick="closeModal('vpViewModal')">Close</button>
        <button type="button" class="btn" onclick="copyVpRawText(document.getElementById('vpViewBundleBox').value, this)">
          📋 Copy Bundle JSON
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Load diff parser & diff2html UI -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jsdiff/9.0.0/diff.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/diff2html/bundles/js/diff2html.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/diff2html/bundles/js/diff2html-ui.min.js"></script>

<script>
    function openCodebaseModal(productId, version) {
        if (productId) {
            var sel = document.getElementById('codebaseProductSelect');
            if (sel) sel.value = productId;
        }
        if (version) {
            var ver = document.getElementById('codebaseVersionInput');
            if (ver) ver.value = version;
        }
        openModal('codebaseModal');
    }
    function closeCodebaseModal() {
        closeModal('codebaseModal');
    }

    function openPatchModal(productId) {
        if (productId) {
            var sel = document.getElementById('patchProductId');
            if (sel) sel.value = productId;
        }
        openModal('patchModal');
    }
    function closePatchModal() {
        closeModal('patchModal');
    }

async function triggerAiFix(errorId) {
    const btn = (typeof event !== 'undefined' && event && (event.currentTarget || (event.target.closest ? event.target.closest('button') : event.target))) || (typeof event !== 'undefined' && event ? event.target : null);
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Diagnosing with Gemini...';
    }

    try {
        const fd = new FormData();
        fd.append('action', 'ai_diagnose');
        fd.append('error_id', errorId);
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>');

        const targetUrl = 'dashboard.php';
        const res = await fetch(targetUrl, { 
            method: 'POST', 
            headers: {
                'X-CSRF-Token': '<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>'
            },
            body: fd 
        });

        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (jsonErr) {
            console.error('Non-JSON response from server:', rawText);
            const cleanErr = rawText.replace(/<[^>]*>/g, '').trim().substring(0, 140);
            throw new Error(cleanErr || ('Server returned HTTP ' + res.status));
        }

        if (data.success) {
            document.getElementById('aiVersionBadge').innerText = 'v' + data.new_version;
            document.getElementById('aiDiffFilePath').innerText = data.fixed_file_path;
            document.getElementById('aiRootCause').innerText = data.root_cause;
            document.getElementById('aiFixSummary').innerText = data.fix_summary;
            document.getElementById('aiArchiveName').innerText = data.package_filename;
            document.getElementById('aiChecksum').innerText = data.package_checksum;
            document.getElementById('aiApproveErrorId').value = data.error_id;

            if (data.csrf_token) {
                const approveCsrfInput = document.getElementById('aiApproveCsrfToken');
                if (approveCsrfInput) {
                    approveCsrfInput.value = data.csrf_token;
                }
            }

            // 1. Generate Unified Diff patch string via JsDiff
            const fileName = data.fixed_file_path || 'file.php';
            let diffRendered = false;

            if (typeof Diff !== 'undefined' && typeof Diff2Html !== 'undefined' && typeof Diff2Html.html === 'function') {
                try {
                    const patchText = Diff.createTwoFilesPatch(
                        'Original/' + fileName,
                        'Patched/' + fileName,
                        data.original_code || '',
                        data.corrected_code || '',
                        'Base Version',
                        'v' + data.new_version
                    );

                    // 2. Render Side-by-Side view using Diff2Html
                    const diffHtml = Diff2Html.html(patchText, {
                        drawFileList: false,
                        matching: 'lines',
                        outputFormat: 'side-by-side',
                        renderNothingWhenEmpty: false
                    });

                    document.getElementById('visualDiffMount').innerHTML = diffHtml;
                    diffRendered = true;
                } catch (diffErr) {
                    console.warn('Diff2Html render error, using fallback:', diffErr);
                }
            }

            if (!diffRendered) {
                const escapeHtml = (str) => String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                document.getElementById('visualDiffMount').innerHTML = `
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-family:'JetBrains Mono',monospace; font-size:0.8rem; margin-top:4px;">
                        <div style="background:var(--ink); padding:12px; border-radius:var(--radius); border:1px solid var(--line); overflow:auto; max-height:360px;">
                            <div style="color:var(--crit); font-weight:600; margin-bottom:6px; border-bottom:1px solid var(--line); padding-bottom:4px; font-size:11px; text-transform:uppercase;">Original Code</div>
                            <pre style="margin:0; white-space:pre-wrap; color:var(--text); font-family:inherit;">` + escapeHtml(data.original_code || '') + `</pre>
                        </div>
                        <div style="background:var(--ink); padding:12px; border-radius:var(--radius); border:1px solid var(--line); overflow:auto; max-height:360px;">
                            <div style="color:var(--ok); font-weight:600; margin-bottom:6px; border-bottom:1px solid var(--line); padding-bottom:4px; font-size:11px; text-transform:uppercase;">Patched Code (v` + escapeHtml(data.new_version || '') + `)</div>
                            <pre style="margin:0; white-space:pre-wrap; color:var(--text); font-family:inherit;">` + escapeHtml(data.corrected_code || '') + `</pre>
                        </div>
                    </div>
                `;
            }

            // 3. Open Modal
            openModal('aiReviewModal');
        } else {
            alert('SpectraOps AI Diagnosis Alert: ' + (data.error || 'Diagnostic engine could not generate a patch.'));
        }
    } catch (e) {
        console.error('SpectraOps AI Diagnostic Engine Error:', e);
        alert('SpectraOps AI Diagnostic Engine: ' + (e.message || 'Failed to connect.'));
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}

// --- Issue Software License Modal Handlers ---
function switchLicenseModalTab(type) {
    const cardCloud = document.getElementById('choiceCloudCard');
    const cardVp = document.getElementById('choiceVendPulseCard');
    const wrapCloud = document.getElementById('standardLicenseFormWrap');
    const wrapVp = document.getElementById('vendPulseModalLaunchWrap');
    
    if (type === 'vendpulse') {
        if (cardCloud) cardCloud.classList.remove('selected');
        if (cardVp) cardVp.classList.add('selected');
        if (wrapCloud) wrapCloud.style.display = 'none';
        if (wrapVp) wrapVp.style.display = 'block';
        
        const stdClient = document.getElementById('standardClientName');
        const vpPre = document.getElementById('vpModalClientNamePrefill');
        if (stdClient && vpPre && stdClient.value.trim() !== '' && vpPre.value.trim() === '') {
            vpPre.value = stdClient.value.trim();
        }
    } else {
        if (cardVp) cardVp.classList.remove('selected');
        if (cardCloud) cardCloud.classList.add('selected');
        if (wrapVp) wrapVp.style.display = 'none';
        if (wrapCloud) wrapCloud.style.display = 'block';
    }
}

function handleProductSelectChange(selectEl) {
    if (!selectEl) return;
    const opt = selectEl.options[selectEl.selectedIndex];
    if (opt && opt.getAttribute('data-is-vp') === '1') {
        switchLicenseModalTab('vendpulse');
    }
}

function launchVendPulseGeneratorFromModal() {
    const prefillInput = document.getElementById('vpModalClientNamePrefill');
    const clientVal = prefillInput ? prefillInput.value.trim() : '';
    let target = '?page=licenses&view=vendpulse_generator';
    if (clientVal) {
        target += '&vp_client=' + encodeURIComponent(clientVal);
    }
    window.location.href = target;
}

function openIssueLicenseForProduct(productType) {
    openModal('licenseModal');
    if (productType === 'vendpulse') {
        switchLicenseModalTab('vendpulse');
    } else {
        switchLicenseModalTab('standard');
    }
}

// --- VendPulse Offline License Generator UI Handlers ---
const VP_TIER_MODULES = {
    'RETAIL_BASIC': ['catalog_grid', 'standard_pos', 'basic_receipt'],
    'SUPERMARKET': ['barcode_rapid_stream', 'scale_comm', 'multi_till', 'cashier_shifts'],
    'ENTERPRISE_WHOLESALE': ['multi_warehouse', 'b2b_invoices', 'credit_accounts', 'institutional_branding']
};

function selectVpTier(tier) {
    const radioRetail = document.getElementById('tierRetail');
    const radioSupermarket = document.getElementById('tierSupermarket');
    const radioWholesale = document.getElementById('tierWholesale');
    const cardRetail = document.getElementById('cardTierRetail');
    const cardSupermarket = document.getElementById('cardTierSupermarket');
    const cardWholesale = document.getElementById('cardTierWholesale');

    if (cardRetail) cardRetail.classList.remove('selected');
    if (cardSupermarket) cardSupermarket.classList.remove('selected');
    if (cardWholesale) cardWholesale.classList.remove('selected');

    if (tier === 'RETAIL_BASIC') {
        if (radioRetail) radioRetail.checked = true;
        if (cardRetail) cardRetail.classList.add('selected');
    } else if (tier === 'ENTERPRISE_WHOLESALE') {
        if (radioWholesale) radioWholesale.checked = true;
        if (cardWholesale) cardWholesale.classList.add('selected');
    } else {
        if (radioSupermarket) radioSupermarket.checked = true;
        if (cardSupermarket) cardSupermarket.classList.add('selected');
    }

    // Update checkboxes and chip highlight
    const allowed = VP_TIER_MODULES[tier] || [];
    document.querySelectorAll('#vpModulesContainer .vp-module-chip').forEach(function(chip) {
        const input = chip.querySelector('input[type="checkbox"]');
        if (input) {
            const isIncluded = allowed.includes(input.value);
            input.checked = isIncluded;
            if (isIncluded) {
                chip.classList.add('active');
            } else {
                chip.classList.remove('active');
            }
        }
    });
}

function selectVpMode(mode) {
    const radioRetail = document.getElementById('modeRetail');
    const radioSupermarket = document.getElementById('modeSupermarket');
    const radioRestaurant = document.getElementById('modeRestaurant');
    const cardRetail = document.getElementById('cardModeRetail');
    const cardSupermarket = document.getElementById('cardModeSupermarket');
    const cardRestaurant = document.getElementById('cardModeRestaurant');

    if (cardRetail) cardRetail.classList.remove('selected');
    if (cardSupermarket) cardSupermarket.classList.remove('selected');
    if (cardRestaurant) cardRestaurant.classList.remove('selected');

    if (mode === 'SUPERMARKET') {
        if (radioSupermarket) radioSupermarket.checked = true;
        if (cardSupermarket) cardSupermarket.classList.add('selected');
    } else if (mode === 'RESTAURANT') {
        if (radioRestaurant) radioRestaurant.checked = true;
        if (cardRestaurant) cardRestaurant.classList.add('selected');
    } else {
        if (radioRetail) radioRetail.checked = true;
        if (cardRetail) cardRetail.classList.add('selected');
    }
}

function regenerateVpLicenseId() {
    const chars = '0123456789ABCDEF';
    let code = '';
    for (let i = 0; i < 5; i++) {
        code += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    const input = document.getElementById('vpLicenseId');
    if (input) {
        input.value = 'VP-LIC-2026-' + code;
    }
}

function toggleVpExpiry(isPerpetual) {
    const wrap = document.getElementById('vpDatePickerWrap');
    if (wrap) {
        wrap.style.display = isPerpetual ? 'none' : 'block';
    }
}

function copyVpRawText(text, btn) {
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            if (btn) {
                const orig = btn.innerHTML;
                btn.innerHTML = '✓ Copied!';
                setTimeout(function() { btn.innerHTML = orig; }, 2000);
            }
        }).catch(function() {
            fallbackCopy(text, btn);
        });
    } else {
        fallbackCopy(text, btn);
    }
}

function fallbackCopy(text, btn) {
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '✓ Copied!';
            setTimeout(function() { btn.innerHTML = orig; }, 2000);
        }
    } catch(e) {
        alert('Could not copy automatically. Please select text and press Ctrl+C.');
    }
    document.body.removeChild(ta);
}

function viewVpBundleModal(lic) {
    if (!lic) return;
    const elId = document.getElementById('vpViewLicenseId');
    const elClient = document.getElementById('vpViewClient');
    const elCounters = document.getElementById('vpViewCounters');
    const elHwid = document.getElementById('vpViewHwid');
    const elIssuedAt = document.getElementById('vpViewIssuedAt');
    const elExpiresAt = document.getElementById('vpViewExpiresAt');
    const elStatusBadge = document.getElementById('vpViewStatusBadge');
    const elTierBadge = document.getElementById('vpViewTierBadge');
    const elModules = document.getElementById('vpViewModules');
    const elBundle = document.getElementById('vpViewBundleBox');
    const elDl = document.getElementById('vpViewDownloadLink');

    if (elId) elId.textContent = lic.license_id || 'N/A';
    if (elClient) elClient.textContent = lic.client_name || 'N/A';
    if (elCounters) elCounters.textContent = (lic.max_counters || 1) + ' Station(s)';
    if (elHwid) elHwid.textContent = lic.machine_hwid ? lic.machine_hwid : 'Unconstrained (Any Machine)';
    
    // Dates
    const issuedTs = lic.issued_at ? parseInt(lic.issued_at, 10) : 0;
    const expiresTs = lic.expires_at ? parseInt(lic.expires_at, 10) : 0;
    if (elIssuedAt) elIssuedAt.textContent = issuedTs ? new Date(issuedTs * 1000).toLocaleString() : 'N/A';
    if (elExpiresAt) {
        if (!expiresTs) {
            elExpiresAt.innerHTML = '<span style="color:var(--ok); font-weight:700;">Perpetual (No Expiry)</span>';
        } else {
            const isExp = (expiresTs * 1000) < Date.now();
            elExpiresAt.innerHTML = '<span style="' + (isExp ? 'color:var(--crit); font-weight:700;' : '') + '">' + new Date(expiresTs * 1000).toLocaleDateString() + (isExp ? ' (EXPIRED)' : '') + '</span>';
        }
    }

    // Status badge
    const st = lic.status || 'active';
    let pillClass = (st === 'active') ? 'ok' : ((st === 'revoked') ? 'dead' : 'warn');
    if (expiresTs && (expiresTs * 1000) < Date.now() && st === 'active') {
        pillClass = 'warn';
    }
    if (elStatusBadge) {
        elStatusBadge.innerHTML = '<span class="status-pill ' + pillClass + '"><span class="dot dot-' + pillClass + '"></span> ' + st.toUpperCase() + '</span>';
    }

    // Tier badge
    const t = lic.tier || 'SUPERMARKET';
    let tClass = 'vp-tag-supermarket';
    if (t === 'RETAIL_BASIC') tClass = 'vp-tag-retail';
    else if (t === 'ENTERPRISE_WHOLESALE') tClass = 'vp-tag-wholesale';
    if (elTierBadge) {
        elTierBadge.innerHTML = '<span class="vp-tier-tag ' + tClass + '">' + t + '</span>';
    }

    // Usage Mode badge and text
    const elUsageModeText = document.getElementById('vpViewUsageModeText');
    const elUsageModeBadge = document.getElementById('vpViewUsageModeBadge');
    const um = lic.usage_mode || 'RETAIL_SHOP';
    let umIcon = '🛍️';
    let umClass = 'vp-tag-retail';
    let umLabel = 'Retail Shop';
    if (um === 'SUPERMARKET') {
        umIcon = '🛒';
        umClass = 'vp-tag-supermarket';
        umLabel = 'Supermarket';
    } else if (um === 'RESTAURANT') {
        umIcon = '🍽️';
        umClass = 'vp-tag-restaurant';
        umLabel = 'Restaurant & Hospitality';
    }
    if (elUsageModeText) {
        elUsageModeText.innerHTML = umIcon + ' ' + umLabel;
    }
    if (elUsageModeBadge) {
        elUsageModeBadge.innerHTML = '<span class="vp-tier-tag ' + umClass + '">' + umIcon + ' ' + um + '</span>';
    }

    // Modules
    let mods = [];
    if (Array.isArray(lic.allowed_modules)) {
        mods = lic.allowed_modules;
    } else if (typeof lic.allowed_modules === 'string') {
        try { mods = JSON.parse(lic.allowed_modules); } catch(e) { mods = []; }
    }
    if (elModules) {
        elModules.innerHTML = '';
        if (mods.length === 0) {
            elModules.innerHTML = '<span style="color:var(--text-faint); font-size:12px;">No modules assigned.</span>';
        } else {
            mods.forEach(function(m) {
                const chip = document.createElement('span');
                chip.className = 'vp-module-chip active';
                chip.style.margin = '2px';
                chip.textContent = '✓ ' + m;
                elModules.appendChild(chip);
            });
        }
    }

    // Bundle JSON
    let bundleObj = null;
    if (lic.signature) {
        bundleObj = {
            issuer: "SpectraOps",
            product: "VENDPULSE",
            license_id: lic.license_id,
            client_name: lic.client_name,
            machine_hwid: lic.machine_hwid || null,
            tier: lic.tier,
            usage_mode: lic.usage_mode || 'RETAIL_SHOP',
            allowed_modules: mods,
            max_counters: parseInt(lic.max_counters || 1, 10),
            issued_at: issuedTs,
            expires_at: expiresTs || null
        };
        const fullBundle = {
            payload: bundleObj,
            signature: lic.signature,
            algorithm: "SHA256withRSA",
            version: "1.0"
        };
        if (elBundle) elBundle.value = JSON.stringify(fullBundle, null, 2);
    } else {
        if (elBundle) elBundle.value = JSON.stringify(lic, null, 2);
    }

    // Download link
    if (elDl) {
        elDl.href = '?download_vp_license=' + encodeURIComponent(lic.id);
    }

    openModal('vpViewModal');
}

async function runVpVerification(btn) {
    const inputEl = document.getElementById('vpVerifyInput');
    const hwidEl = document.getElementById('vpVerifyHwid');
    const resultEl = document.getElementById('vpVerifyResult');

    const bundleVal = inputEl ? inputEl.value.trim() : '';
    const hwidVal = hwidEl ? hwidEl.value.trim() : '';

    if (!bundleVal) {
        alert('Please paste a VendPulse license bundle (JSON or Base64).');
        if (inputEl) inputEl.focus();
        return;
    }

    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '⏳ Verifying cryptographic signature...';
    }

    try {
        const fd = new FormData();
        fd.append('action', 'verify_vp_license_ajax');
        fd.append('license_bundle', bundleVal);
        fd.append('expected_hwid', hwidVal);
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>');

        const res = await fetch('dashboard.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': '<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>'
            },
            body: fd
        });

        const data = await res.json();
        resultEl.style.display = 'block';

        if (data.success && data.valid) {
            const p = data.payload || {};
            const expText = p.expires_at ? new Date(p.expires_at * 1000).toLocaleDateString() : 'Perpetual (No Expiry)';
            const hwidMatch = data.hwid_match === true ? '<span style="color:var(--ok);">✓ HWID Matched</span>' : (data.hwid_match === false ? '<span style="color:var(--crit);">✗ HWID Mismatch</span>' : '<span style="color:var(--text-faint);">Not Specified / Unconstrained</span>');

            const vUm = p.usage_mode || 'RETAIL_SHOP';
            let vUmIcon = '🛍️';
            let vUmLabel = 'Retail Shop';
            if (vUm === 'SUPERMARKET') { vUmIcon = '🛒'; vUmLabel = 'Supermarket'; }
            else if (vUm === 'RESTAURANT') { vUmIcon = '🍽️'; vUmLabel = 'Restaurant & Hospitality'; }

            resultEl.innerHTML = `
                <div style="background:rgba(61,214,140,0.08); border:1px solid #3dd68c; border-radius:var(--radius); padding:16px;">
                    <div style="display:flex; align-items:center; gap:8px; color:#3dd68c; font-weight:700; font-size:14px; margin-bottom:8px;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        Cryptographically Valid &amp; Authentic License
                    </div>
                    <div style="font-size:12px; color:var(--text); line-height:1.6;">
                        <div>Client: <strong>${p.client_name || 'N/A'}</strong> (<code>${p.license_id || 'N/A'}</code>)</div>
                        <div>Operating Tier: <strong style="color:var(--accent);">${p.tier || 'N/A'}</strong> &bull; System Mode: <strong style="color:var(--ok);">${vUmIcon} ${vUm} (${vUmLabel})</strong> &bull; Max Stations: <strong>${p.max_counters || 1}</strong></div>
                        <div>Validity: <strong>${expText}</strong></div>
                        <div>Hardware Lock: ${hwidMatch}</div>
                        <div style="margin-top:6px; font-size:11px; color:var(--text-dim);">Signature Algorithm: <strong>${data.algorithm || 'SHA256withRSA'}</strong> &bull; Checked Against: <strong>SpectraOps Public Key</strong></div>
                    </div>
                </div>
            `;
        } else {
            resultEl.innerHTML = `
                <div style="background:rgba(239,68,68,0.08); border:1px solid var(--crit); border-radius:var(--radius); padding:16px;">
                    <div style="display:flex; align-items:center; gap:8px; color:var(--crit); font-weight:700; font-size:14px; margin-bottom:6px;">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                        Verification Failed / Tampered License
                    </div>
                    <div style="font-size:12.5px; color:var(--text); line-height:1.5;">
                        ${data.error || 'The digital signature does not match the canonical payload or key.'}
                    </div>
                </div>
            `;
        }
    } catch (e) {
        resultEl.style.display = 'block';
        resultEl.innerHTML = `
            <div style="background:rgba(239,68,68,0.08); border:1px solid var(--crit); border-radius:var(--radius); padding:14px; color:var(--crit); font-size:12px;">
                Network / Server Error: ${e.message || 'Could not complete verification request.'}
            </div>
        `;
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const cont = document.getElementById('vpModulesContainer');
    if (cont) {
        cont.addEventListener('change', function(e) {
            if (e.target && e.target.type === 'checkbox') {
                const chip = e.target.closest('.vp-module-chip');
                if (chip) {
                    if (e.target.checked) chip.classList.add('active');
                    else chip.classList.remove('active');
                }
            }
        });
    }
});

// --- Store Orders & Cloud Deployments Interactive Handlers ---
function openOrderInspectModal(order) {
    if (!order) return;

    // Order status pill
    const badge = document.getElementById('inspectOrderBadge');
    if (badge) {
        const isCompleted = (order.status || '').toLowerCase() === 'completed';
        badge.className = 'status-pill ' + (isCompleted ? 'ok' : 'warn');
        badge.innerHTML = '<span class="dot dot-' + (isCompleted ? 'ok' : 'warn') + '"></span> ' + (order.status ? order.status.toUpperCase() : 'UNKNOWN');
    }

    // Subtitle
    const sub = document.getElementById('inspectOrderSub');
    if (sub) {
        sub.textContent = 'Order #' + (order.order_id || order.id || 'N/A') + ' • Reference: ' + (order.order_number || order.order_id || 'N/A') + ' • Ingress: ' + (order.created_at || 'N/A');
    }

    // Business & Contact
    const bName = document.getElementById('inspectBusinessName');
    if (bName) bName.textContent = order.business_name || 'N/A';

    const cName = document.getElementById('inspectCustomerName');
    if (cName) {
        const parts = [order.customer_name, order.customer_email, order.customer_phone].filter(Boolean);
        cName.textContent = parts.length > 0 ? parts.join(' • ') : 'No customer contact specified';
    }

    const cCode = document.getElementById('inspectClientCode');
    if (cCode) cCode.textContent = 'Client Account: ' + (order.client_code || 'Pending Linking');

    // Product & Architecture
    const isCloud = (order.license_type === 'CLOUD_SAAS') || (order.purchased_product && order.purchased_product.toUpperCase() !== 'VENDPULSE');
    const pName = document.getElementById('inspectProductName');
    if (pName) {
        pName.textContent = order.product_name || order.purchased_product || 'Spectra Product';
    }

    const sType = document.getElementById('inspectSystemType');
    if (sType) {
        if (isCloud) {
            sType.innerHTML = '<span style="color:#38bdf8; font-weight:700;">☁️ Cloud-Hosted SaaS</span> (Centrally Orchestrated)';
        } else {
            const m = order.usage_mode || 'RETAIL_SHOP';
            sType.innerHTML = '<span style="color:#a855f7; font-weight:700;">🛒 VendPulse POS Engine</span> (' + m + ')';
        }
    }

    const sTerm = document.getElementById('inspectSeatsTerm');
    if (sTerm) {
        const unit = isCloud ? 'Seat(s)' : 'Station(s)';
        const cap = (order.max_counters || 1) + ' ' + unit;
        const dur = order.term_duration_months ? (order.term_duration_months + ' Month Subscription') : 'Perpetual Lifetime License';
        sTerm.textContent = cap + ' • ' + dur;
    }

    // Deployment Telemetry & Pairing
    const depPill = document.getElementById('inspectDepStatusPill');
    const depDetails = document.getElementById('inspectDepDetails');
    if (isCloud) {
        if (order.deployment_id) {
            const dStatus = (order.deployment_status || 'healthy').toLowerCase();
            let pillClass = 'ok';
            if (dStatus === 'degraded' || dStatus === 'warning') pillClass = 'warn';
            else if (dStatus === 'down' || dStatus === 'unreachable') pillClass = 'dead';
            
            if (depPill) {
                depPill.innerHTML = '<span class="status-pill ' + pillClass + '"><span class="dot dot-' + pillClass + '"></span> Node ' + dStatus.toUpperCase() + '</span>';
            }
            if (depDetails) {
                const dom = order.deployment_domain ? ('<a href="https://' + encodeURIComponent(order.deployment_domain) + '" target="_blank" style="color:#38bdf8; font-weight:700; text-decoration:none;">🌐 ' + order.deployment_domain + ' &nearr;</a>') : 'No domain specified';
                const lastHb = order.deployment_last_heartbeat || 'Recently';
                const users = order.deployment_active_users !== undefined ? order.deployment_active_users : 0;
                const env = (order.deployment_environment || 'production').toUpperCase();
                const ver = order.deployment_version || '1.0.0';
                const ip = order.deployment_ip || 'Node IP Auto-Reported';
                
                depDetails.innerHTML = `
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <div>Production FQDN: <strong>${dom}</strong></div>
                        <div>Active Version: <span class="version-tag" style="font-size:11px;">v${ver}</span></div>
                        <div>Environment: <strong style="color:var(--accent);">${env}</strong></div>
                        <div>Live Active Users: <strong style="color:#34d399;">${users} Online</strong></div>
                        <div>Server IP: <code>${ip}</code></div>
                        <div>Last Heartbeat: <code>${lastHb}</code></div>
                    </div>
                    <div style="margin-top:12px; display:flex; gap:8px;">
                        <a href="?page=overview&product_id=${order.product_id || 1}&deployment_id=${order.deployment_id}#deployment-feed" class="btn btn-sm btn-outline" style="border-color:rgba(14,165,233,0.5); color:#38bdf8; text-decoration:none; display:inline-flex; align-items:center; gap:6px;">
                            📡 Open Fleet Live Telemetry Feed &rarr;
                        </a>
                    </div>
                `;
            }
        } else {
            if (depPill) {
                depPill.innerHTML = '<span class="status-pill warn" style="font-size:11px; padding:3px 8px;"><span class="dot dot-warn"></span> AWAITING CLOUD DEPLOYMENT</span>';
            }
            if (depDetails) {
                const orderData = {
                    product_id: order.product_id || 1,
                    client_name: order.business_name || '',
                    license_id: order.license_id || '',
                    order_id: order.order_id || ''
                };
                window._currentQuickPairOrder = orderData;
                
                depDetails.innerHTML = `
                    <div style="color:var(--text-muted); font-size:12px; margin-bottom:8px;">
                        This cloud software license has been successfully provisioned, but has not yet registered an active production domain or cluster deployment node.
                    </div>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <button type="button" class="btn btn-sm btn-outline" style="border-color:#0ea5e9; color:#38bdf8; display:inline-flex; align-items:center; gap:4px;" onclick='closeModal("orderInspectModal"); openQuickPairForOrder(window._currentQuickPairOrder);'>
                            🔗 Assign Domain &amp; Pair Fleet Node
                        </button>
                    </div>
                `;
            }
        }
    } else {
        if (depPill) {
            depPill.innerHTML = '<span class="status-pill ok" style="font-size:11px; padding:3px 8px;"><span class="dot dot-ok"></span> AIR-GAPPED OFFLINE POS</span>';
        }
        if (depDetails) {
            depDetails.innerHTML = `
                <div style="color:var(--text-muted); font-size:12px;">
                    VendPulse operates in secure on-premise retail installations without mandatory internet or central telemetry reporting.
                    The client authenticates stations and usage mode using cryptographically signed RSA license packages downloaded through their portal.
                </div>
            `;
        }
    }

    // License Key
    const licInput = document.getElementById('inspectLicenseKey');
    if (licInput) licInput.value = order.license_id || 'Pending Issuance';

    // Portal Link
    const pUrl = document.getElementById('inspectPortalUrl');
    const pBtn = document.getElementById('inspectPortalBtn');
    const token = order.portal_access_token || '';
    const fullPortalUrl = window.location.origin + window.location.pathname.replace(/dashboard\.php.*$/, '') + 'portal/download.php?token=' + encodeURIComponent(token);
    if (pUrl) pUrl.value = fullPortalUrl;
    if (pBtn) pBtn.href = fullPortalUrl;

    // Raw Webhook Ingress Payload
    const rawEl = document.getElementById('inspectRawPayload');
    if (rawEl) {
        if (order.raw_payload) {
            try {
                const parsed = typeof order.raw_payload === 'string' ? JSON.parse(order.raw_payload) : order.raw_payload;
                rawEl.textContent = JSON.stringify(parsed, null, 2);
            } catch(e) {
                rawEl.textContent = order.raw_payload;
            }
        } else {
            rawEl.textContent = '// No raw webhook payload recorded for this order.';
        }
    }

    openModal('orderInspectModal');
}

function openQuickPairForOrder(data) {
    if (!data) return;
    const modal = document.getElementById('pairModal');
    if (!modal) return;

    if (data.product_id) {
        const sel = modal.querySelector('select[name="product_id"]');
        if (sel) sel.value = data.product_id;
    }
    if (data.client_name) {
        const cInput = modal.querySelector('input[name="client_name"]');
        if (cInput) cInput.value = data.client_name;
    }
    if (data.license_id) {
        const kInput = modal.querySelector('input[name="api_key"]');
        if (kInput) kInput.value = data.license_id;
    }
    const dInput = modal.querySelector('input[name="deployment_domain"]');
    if (dInput) {
        dInput.value = '';
        setTimeout(function() { dInput.focus(); }, 120);
    }
    openModal('pairModal');
}
</script>

</body>
</html>
