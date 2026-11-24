<?php
declare(strict_types=1);

// --- 1. CONFIGURATION & CORE INCLUDES ---
define('SO_ASSET_DIR', __DIR__ . '/assets');
require_once dirname(__DIR__) . '/src/so_helpers.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';
require_once dirname(__DIR__) . '/src/RateLimiter.php';
require_once dirname(__DIR__) . '/src/Services/LicenseManager.php';
require_once dirname(__DIR__) . '/src/Services/VendPulseLicenseService.php';
require_once dirname(__DIR__) . '/src/Services/QuickLinkService.php';
if (file_exists(dirname(__DIR__) . '/services/GeminiHotfixAgent.php')) {
    require_once dirname(__DIR__) . '/services/GeminiHotfixAgent.php';
} elseif (file_exists(dirname(__DIR__) . '/src/Services/GeminiHotfixAgent.php')) {
    require_once dirname(__DIR__) . '/src/Services/GeminiHotfixAgent.php';
}

use FleetHub\Database;
use FleetHub\FleetService;
use FleetHub\RateLimiter;
use FleetHub\Services\QuickLinkService;
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
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="dark">
        <meta name="theme-color" content="#0A0E14">
        <title>Sign in · SpectraOps</title>
        <link rel="icon" type="image/svg+xml" href="assets/img/spectraops-icon.svg">
        <link rel="stylesheet" href="assets/css/spectraops.css">
    </head>
    <body>
    <?php so_icon_sprite(); ?>
    <a class="skip-link" href="#main">Skip to content</a>
    <main class="auth" id="main"><div class="auth-card">
    <div class="auth-brand"><svg class="brand-mark" viewBox="0 0 54 54" aria-hidden="true" focusable="false"><defs><linearGradient id="wedge-auth" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0DC0E7"/><stop offset="60%" stop-color="#2C88C1"/><stop offset="100%" stop-color="#133F83"/></linearGradient></defs><polygon points="6,46 22,10 31,10 15,46" fill="url(#wedge-auth)"/><rect x="23" y="28" width="4.5" height="18" rx="2" fill="#36527B"/><rect x="31" y="20" width="4.5" height="26" rx="2" fill="#2C88C1"/><rect x="39" y="12" width="4.5" height="34" rx="2" fill="#0DC0E7"/><rect x="47" y="22" width="4.5" height="24" rx="2" fill="#BEF6FD"/></svg><h1>SpectraOps</h1><p>Sign in to the fleet console.</p></div>
    <form method="post" class="stack" style="gap:14px" action="<?= so_e(strtok($_SERVER['REQUEST_URI'] ?? 'dashboard.php', '?')) ?>">
      <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
      <div class="field"><label for="login_email">Email</label><input class="input" id="login_email" name="login_email" type="email" autocomplete="username" required autofocus></div>
      <div class="field"><label for="login_pass">Password</label><div class="pw"><input class="input" id="login_pass" name="login_pass" type="password" autocomplete="current-password" required><button type="button" class="btn btn-icon btn-sm" data-toggle-password="#login_pass" aria-pressed="false" aria-label="Show password"><?= so_icon('eye') ?></button></div></div>
      <?php if (!empty($loginError)): ?>
        <div class="alert alert-danger" role="alert"><svg class="icon " aria-hidden="true" focusable="false"><use href="#i-circle-x"/></svg><div><?= so_e($loginError) ?></div></div>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary">Sign in</button>
    </form></div></main>
    <script src="assets/js/spectraops-ui.js"></script>
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

// --- DOWNLOADABLE CONNECTION BUNDLE (spectraops.json) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'download_bundle') {
    $depId = (int)($_GET['id'] ?? ($_GET['deployment_id'] ?? 0));
    if ($depId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Valid deployment id is required.']);
        exit;
    }
    try {
        $quickLink = new QuickLinkService($pdo);
        $bundle = $quickLink->buildConnectionBundle($depId);
        while (ob_get_level()) { ob_end_clean(); }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="spectraops.json"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit;
    } catch (\Throwable $e) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
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

// Action: Unified One-Click Deployment Link & Connection Bundle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'link_deployment') {
    try {
        $quickLink = new QuickLinkService($pdo);
        $linked = $quickLink->linkDeployment([
            'product_slug' => trim((string)($_POST['product_slug'] ?? '')),
            'product_id'   => (int)($_POST['product_id'] ?? 0),
            'client_name'  => trim((string)($_POST['client_name'] ?? '')),
            'client_email' => trim((string)($_POST['client_email'] ?? '')),
            'domain'       => trim((string)($_POST['domain'] ?? ($_POST['deployment_domain'] ?? ''))),
            'environment'  => trim((string)($_POST['environment'] ?? 'production')),
            'license_tier' => trim((string)($_POST['license_tier'] ?? 'perpetual')),
            'expiry_days'  => $_POST['expiry_days'] ?? null,
        ]);
        $dlUrl = 'dashboard.php?action=download_bundle&id=' . (int)$linked['deployment_id'];
        $feedbackBundleUrl = $dlUrl;
        $dlIconBtn = '<a href="' . htmlspecialchars($dlUrl) . '" download="spectraops.json" class="icon-btn" title="Download spectraops.json" aria-label="Download spectraops.json" style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;margin-left:8px;vertical-align:middle;border:1px solid currentColor;border-radius:6px;color:inherit;text-decoration:none;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="15" height="15"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></a>';
        $feedbackMsg = 'Deployment linked &amp; active license bound! '
            . 'License: <code>' . htmlspecialchars($linked['license_key']) . '</code> &nbsp;|&nbsp; '
            . 'Key: <code>' . htmlspecialchars($linked['deployment_key']) . '</code> '
            . $dlIconBtn;
        $feedbackType = 'success';
    } catch (\Throwable $e) {
        error_log("[Dashboard] Link deployment error: " . $e->getMessage());
        $feedbackMsg = "Failed to link deployment: " . htmlspecialchars($e->getMessage());
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
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0A0E14">
<title><?= so_e(ucfirst($activePage)) ?> · SpectraOps</title>
<link rel="icon" type="image/svg+xml" href="assets/img/spectraops-icon.svg">
<link rel="stylesheet" href="assets/css/spectraops.css">
</head>
<body>
<?php so_icon_sprite(); ?>
<a class="skip-link" href="#main">Skip to content</a>
<div class="app">

<!-- ================= RAIL ================= -->
<nav class="rail" aria-label="Primary">
<a class="brand" href="?page=overview" aria-label="SpectraOps home">
  <svg class="brand-mark" viewBox="0 0 54 54" aria-hidden="true" focusable="false"><defs><linearGradient id="wedge-rail" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#0DC0E7"/><stop offset="60%" stop-color="#2C88C1"/><stop offset="100%" stop-color="#133F83"/></linearGradient></defs><polygon points="6,46 22,10 31,10 15,46" fill="url(#wedge-rail)"/><rect x="23" y="28" width="4.5" height="18" rx="2" fill="#36527B"/><rect x="31" y="20" width="4.5" height="26" rx="2" fill="#2C88C1"/><rect x="39" y="12" width="4.5" height="34" rx="2" fill="#0DC0E7"/><rect x="47" y="22" width="4.5" height="24" rx="2" fill="#BEF6FD"/></svg>
  <span class="brand-name">Spectra<b>Ops</b></span>
</a>
<div class="rail-group">Monitor</div>
<a class="rail-link" href="?page=overview" <?= so_current($activePage, 'overview') ?> title="Overview"><span class="rail-link-main"><?= so_icon('layout-dashboard') ?><span class="rail-label">Overview</span></span></a>
<a class="rail-link" href="?page=deployments" <?= so_current($activePage, 'deployments', 'fleet') ?> title="Fleet"><span class="rail-link-main"><?= so_icon('server') ?><span class="rail-label">Fleet</span></span><span class="count <?= $criticalCount > 0 ? 'count-crit' : '' ?>"><?= (int)$totalDeployments ?></span></a>
<a class="rail-link" href="?page=metrics" <?= so_current($activePage, 'metrics') ?> title="Metrics"><span class="rail-link-main"><?= so_icon('activity') ?><span class="rail-label">Metrics</span></span></a>
<a class="rail-link" href="?page=errors" <?= so_current($activePage, 'errors') ?> title="Errors"><span class="rail-link-main"><?= so_icon('bug') ?><span class="rail-label">Errors</span></span><span class="count <?= count($recentErrors) > 0 ? 'count-crit' : '' ?>"><?= count($recentErrors) ?></span></a>
<a class="rail-link" href="?page=tickets" <?= so_current($activePage, 'tickets') ?> title="Tickets"><span class="rail-link-main"><?= so_icon('life-buoy') ?><span class="rail-label">Tickets</span></span><span class="count <?= $openTicketsCount > 0 ? 'count-warn' : '' ?>"><?= (int)$openTicketsCount ?></span></a>
<div class="rail-group">Distribute</div>
<a class="rail-link" href="?page=releases" <?= so_current($activePage, 'releases', 'updates') ?> title="Releases"><span class="rail-link-main"><?= so_icon('package') ?><span class="rail-label">Releases</span></span><span class="count"><?= count($releases) ?></span></a>
<a class="rail-link" href="?page=products" <?= so_current($activePage, 'products') ?> title="Products"><span class="rail-link-main"><?= so_icon('package') ?><span class="rail-label">Products</span></span><span class="count"><?= count($products) ?></span></a>
<div class="rail-group">Licensing</div>
<a class="rail-link" href="?page=licenses" <?= so_current($activePage, 'licenses') ?> title="Licenses"><span class="rail-link-main"><?= so_icon('key-round') ?><span class="rail-label">Licenses</span></span><span class="count"><?= count($licensesList) + (int)($vpStats['total'] ?? 0) ?></span></a>
<a class="rail-link" href="?page=orders" <?= so_current($activePage, 'orders') ?> title="Orders"><span class="rail-link-main"><?= so_icon('hard-drive') ?><span class="rail-label">Orders</span></span><span class="count"><?= (int)$ordersCount ?></span></a>
<?php if ($isAdmin): ?>
<div class="rail-group">Admin</div>
<a class="rail-link" href="?page=users" <?= so_current($activePage, 'users') ?> title="Users"><span class="rail-link-main"><?= so_icon('users') ?><span class="rail-label">Users</span></span><span class="count"><?= count($usersList) ?></span></a>
<?php endif; ?>
<div class="rail-foot">
  <span class="avatar" aria-hidden="true"><?= so_e(strtoupper(substr($_SESSION['fleet_user_name'] ?? 'AM', 0, 2))) ?></span>
  <div class="rail-user"><strong><?= so_e($_SESSION['fleet_user_name'] ?? 'Fleet admin') ?></strong><span><?= so_e(ucfirst($_SESSION['fleet_user_role'] ?? 'Administrator')) ?></span></div>
  <a class="btn btn-icon btn-sm" href="?logout=1" aria-label="Sign out" title="Sign out"><?= so_icon('log-out') ?></a>
</div>
</nav>

<!-- ================= MAIN CONTAINER ================= -->
<div class="main">
<header class="topbar">
  <button type="button" class="btn btn-icon nav-toggle" data-nav-toggle aria-label="Open menu"><?= so_icon('menu') ?></button>
  <div class="topbar-title">
    <?php if ($activePage === 'overview'): ?>
      <h1>Overview</h1><p>What needs you right now across every client system.</p>
    <?php elseif (in_array($activePage, ['deployments', 'fleet'])): ?>
      <h1>Fleet</h1><p>Every client deployment reporting to this hub.</p>
    <?php elseif ($activePage === 'metrics'): ?>
      <h1>Metrics</h1><p>How the fleet is performing, using the same thresholds as the status engine.</p>
    <?php elseif ($activePage === 'errors'): ?>
      <h1>Errors</h1><p>Crashes and exceptions, grouped by fingerprint across every client.</p>
    <?php elseif ($activePage === 'tickets'): ?>
      <h1>Tickets</h1><p>Support requests sent from client systems. Status changes sync back to the client.</p>
    <?php elseif (in_array($activePage, ['releases', 'updates'])): ?>
      <h1>Releases</h1><p>Update packages clients download from this hub.</p>
    <?php elseif ($activePage === 'products'): ?>
      <h1>Products</h1><p>Managed products and version catalog.</p>
    <?php elseif ($activePage === 'licenses'): ?>
      <h1>Licenses</h1><p>Cryptographic activation keys and offline license bundles.</p>
    <?php elseif ($activePage === 'orders'): ?>
      <h1>Orders</h1><p>Store purchase fulfillment and automated customer provisioning.</p>
    <?php elseif ($activePage === 'users'): ?>
      <h1>Users</h1><p>People who can sign in to this console.</p>
    <?php else: ?>
      <h1><?= so_e(ucfirst($activePage)) ?></h1><p>SpectraOps Central Operations</p>
    <?php endif; ?>
  </div>
  <div class="topbar-actions">
    <?php if ($activePage === 'overview'): ?>
      <span class="live" id="live-clock">live</span>
      <button type="button" class="btn btn-primary" data-open="#dlg-link-deploy"><?= so_icon('plus') ?>Link deployment</button>
      <button type="button" class="btn" data-open="#dlg-register"><?= so_icon('plus') ?>Register deployment</button>
    <?php elseif (in_array($activePage, ['deployments', 'fleet'])): ?>
      <button type="button" class="btn" data-open="#dlg-pair"><?= so_icon('key-round') ?>Pair client</button>
      <button type="button" class="btn btn-primary" data-open="#dlg-link-deploy"><?= so_icon('plus') ?>Link deployment</button>
    <?php elseif (in_array($activePage, ['releases', 'updates'])): ?>
      <button type="button" class="btn" data-open="#dlg-product"><?= so_icon('plus') ?>New product</button>
      <button type="button" class="btn btn-primary" data-open="#dlg-publish"><?= so_icon('upload') ?>Publish release</button>
    <?php elseif ($activePage === 'products'): ?>
      <button type="button" class="btn btn-primary" data-open="#dlg-product"><?= so_icon('plus') ?>New product</button>
    <?php elseif ($activePage === 'licenses'): ?>
      <button type="button" class="btn" data-open="#dlg-vp-pubkey"><?= so_icon('shield-check') ?>PKI Public Key</button>
      <button type="button" class="btn btn-primary" data-open="#dlg-license"><?= so_icon('plus') ?>Issue license</button>
    <?php elseif ($activePage === 'orders'): ?>
      <button type="button" class="btn" data-open="#dlg-order-webhook"><?= so_icon('external-link') ?>Webhook info</button>
    <?php elseif ($activePage === 'users'): ?>
      <button type="button" class="btn btn-primary" data-open="#dlg-user"><?= so_icon('user-plus') ?>Add user</button>
    <?php endif; ?>
  </div>
</header>

<main id="main">

<?php if (!empty($feedbackMsg)): ?>
  <div class="alert alert-<?= $feedbackType === 'error' ? 'danger' : 'success' ?>" role="alert" style="margin: 16px 20px 0;">
    <?= $feedbackType === 'error' ? so_icon('circle-x') : so_icon('circle-check') ?>
    <div style="flex:1"><?= $feedbackMsg ?></div>
    <?php if (!empty($feedbackBundleUrl)): ?>
      <a href="<?= so_e($feedbackBundleUrl) ?>" download="spectraops.json" class="btn btn-sm btn-icon" title="Download spectraops.json" aria-label="Download spectraops.json"><?= so_icon('download') ?></a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($activePage === 'overview'): ?>
<!-- ================= VIEW: OVERVIEW ================= -->
<div class="strip" role="group" aria-label="Filter by status">
  <a class="strip-item" href="?page=deployments" aria-current="true"><div class="strip-label"><span class="dot dot-accent"></span>All systems</div><div class="strip-value"><?= (int)$totalDeployments ?></div></a>
  <a class="strip-item" href="?page=deployments&status=healthy"><div class="strip-label"><span class="dot dot-ok"></span>Healthy</div><div class="strip-value is-ok"><?= (int)$healthyCount ?></div></a>
  <a class="strip-item" href="?page=deployments&status=warning"><div class="strip-label"><span class="dot dot-warn"></span>Warning</div><div class="strip-value is-warn"><?= (int)$warningCount ?></div></a>
  <a class="strip-item" href="?page=deployments&status=critical"><div class="strip-label"><span class="dot dot-crit"></span>Critical</div><div class="strip-value is-crit"><?= (int)$criticalCount ?></div></a>
  <a class="strip-item" href="?page=deployments&status=unreachable"><div class="strip-label"><span class="dot dot-dead"></span>Unreachable</div><div class="strip-value is-dead"><?= (int)$unreachableCount ?></div></a>
  <a class="strip-item" href="?page=releases"><div class="strip-label"><?= so_icon('rotate-cw', 'icon-sm') ?>Outdated builds</div><div class="strip-value"><?= (int)$outdatedCount ?></div></a>
</div>

<div class="summary">
  <span>Fleet health<b><?= (int)$fleetHealthPct ?>%</b></span>
  <span>Avg database latency<b><?= so_fmt_ms($avgLatency) ?></b></span>
  <span>Active users (24h)<b><?= so_fmt_int($totalActiveUsers24h) ?></b></span>
  <span>Version adoption<b><?= (int)$versionAdoptionPct ?>%</b></span>
</div>

<div class="content">
<div class="grid-2">
  <section class="panel" aria-labelledby="att-h">
    <div class="panel-head">
      <h2 id="att-h">Needs attention</h2>
      <small><?= count(array_filter($deployments, fn($d) => ($d['computed_status'] ?? '') !== 'healthy')) ?> of <?= (int)$totalDeployments ?> systems</small>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Client</th>
            <th scope="col">Status</th>
            <th scope="col">Why</th>
            <th scope="col">Last heartbeat</th>
            <th scope="col"><span class="sr-only">Actions</span></th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $attentionItems = array_filter($deployments, fn($d) => ($d['computed_status'] ?? '') !== 'healthy');
          if (empty($attentionItems)): 
          ?>
            <tr><td colspan="5" class="dim" style="text-align:center; padding:24px;">All client deployments are healthy and reporting on schedule.</td></tr>
          <?php else: ?>
            <?php foreach ($attentionItems as $d): 
              $s = strtolower((string)($d['computed_status'] ?? 'unknown'));
              $rCls = so_row_class($s);
              $rel = so_rel_time($d['last_heartbeat_at'] ?? null);
              $payload = so_dep_payload($d);
            ?>
              <tr class="<?= $rCls ?>">
                <td>
                  <div class="cell-main">
                    <button type="button" class="link-btn" data-dep-open data-dep="<?= so_json_attr($payload) ?>" data-remove-href="?delete_deployment=<?= (int)$d['id'] ?>&page=deployments"><?= so_e($d['client_name']) ?></button>
                    <span class="cell-sub"><?= so_e($d['deployment_domain']) ?></span>
                  </div>
                </td>
                <td><?= so_status_pill($s, $d['status_reason'] ?? null) ?></td>
                <td class="dim"><?= so_e($d['status_reason'] ?? '—') ?></td>
                <td class="nowrap num <?= so_rel_class($rel['level']) ?>"><?= so_e($rel['text']) ?></td>
                <td class="col-actions">
                  <div class="row-actions">
                    <a class="btn btn-icon btn-sm" href="dashboard.php?action=download_bundle&id=<?= (int)$d['id'] ?>" download="spectraops.json" title="Download spectraops.json" aria-label="Download spectraops.json"><?= so_icon('download') ?></a>
                    <button type="button" class="btn btn-icon btn-sm" data-dep-open data-dep="<?= so_json_attr($payload) ?>" data-remove-href="?delete_deployment=<?= (int)$d['id'] ?>&page=deployments" aria-label="Open diagnostics for <?= so_e($d['client_name']) ?>"><?= so_icon('eye') ?></button>
                    <a class="btn btn-icon btn-sm" href="https://<?= so_e($d['deployment_domain']) ?>" target="_blank" rel="noopener" aria-label="Open <?= so_e($d['client_name']) ?> site"><?= so_icon('external-link') ?></a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-foot"><a href="?page=deployments">View the whole fleet</a></div>
  </section>

  <div class="stack">
    <section class="panel" aria-labelledby="tk-h">
      <div class="panel-head"><h2 id="tk-h">Open tickets</h2><small><?= (int)$openTicketsCount ?> open</small></div>
      <ul class="list">
        <?php 
        $openTickets = array_filter($tickets ?? [], fn($t) => in_array($t['status'] ?? '', ['open', 'in_progress'], true));
        $openTicketsSlice = array_slice($openTickets, 0, 5);
        if (empty($openTicketsSlice)): 
        ?>
          <li class="dim" style="padding:16px;">No unresolved support tickets.</li>
        <?php else: ?>
          <?php foreach ($openTicketsSlice as $t): 
            $prCls = ($t['priority'] ?? '') === 'urgent' ? 'pill-crit' : ((($t['priority'] ?? '') === 'high') ? 'pill-warn' : 'pill-info');
          ?>
            <li>
              <div class="list-main">
                <strong class="truncate"><?= so_e($t['subject'] ?? 'Support Request') ?></strong>
                <span class="cell-sub"><?= so_e($t['reference'] ?? '') ?> · <?= so_e($t['client_name'] ?? 'Client') ?></span>
              </div>
              <span class="pill <?= $prCls ?> pill-plain"><?= so_e($t['priority'] ?? 'medium') ?></span>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
      <div class="panel-foot"><a href="?page=tickets">Open the support desk</a></div>
    </section>

    <section class="panel" aria-labelledby="er-h">
      <div class="panel-head"><h2 id="er-h">Recent errors</h2><small>last 24 hours</small></div>
      <ul class="list">
        <?php if (empty($recentErrors)): ?>
          <li class="dim" style="padding:16px;">Zero exception incidents recorded recently.</li>
        <?php else: ?>
          <?php foreach (array_slice($recentErrors, 0, 5) as $err): 
            $lvl = strtoupper((string)($err['error_level'] ?? 'ERROR'));
            $lvlCls = in_array($lvl, ['FATAL', 'CRITICAL']) ? 'pill-crit' : 'pill-warn';
          ?>
            <li>
              <div class="list-main">
                <strong class="truncate"><?= so_e($err['error_message'] ?? '') ?></strong>
                <span class="cell-sub"><?= so_e($err['client_name'] ?? 'Unknown client') ?> · <?= (int)($err['occurrences'] ?? 1) ?>×</span>
              </div>
              <span class="pill <?= $lvlCls ?> pill-plain"><?= so_e(strtolower($lvl)) ?></span>
            </li>
          <?php endforeach; ?>
        <?php endif; ?>
      </ul>
      <div class="panel-foot"><a href="?page=errors">See every error</a></div>
    </section>
  </div>
</div>
</div>

<?php elseif (in_array($activePage, ['deployments', 'fleet'])): ?>
<!-- ================= VIEW: FLEET DEPLOYMENTS ================= -->
<div class="strip" role="group" aria-label="Filter by status">
  <a class="strip-item" href="?page=deployments" <?= $filterStatus === 'all' ? 'aria-current="true"' : '' ?>><div class="strip-label"><span class="dot dot-accent"></span>All systems</div><div class="strip-value"><?= (int)$totalDeployments ?></div></a>
  <a class="strip-item" href="?page=deployments&status=healthy" <?= $filterStatus === 'healthy' ? 'aria-current="true"' : '' ?>><div class="strip-label"><span class="dot dot-ok"></span>Healthy</div><div class="strip-value is-ok"><?= (int)$healthyCount ?></div></a>
  <a class="strip-item" href="?page=deployments&status=warning" <?= $filterStatus === 'warning' ? 'aria-current="true"' : '' ?>><div class="strip-label"><span class="dot dot-warn"></span>Warning</div><div class="strip-value is-warn"><?= (int)$warningCount ?></div></a>
  <a class="strip-item" href="?page=deployments&status=critical" <?= $filterStatus === 'critical' ? 'aria-current="true"' : '' ?>><div class="strip-label"><span class="dot dot-crit"></span>Critical</div><div class="strip-value is-crit"><?= (int)$criticalCount ?></div></a>
  <a class="strip-item" href="?page=deployments&status=unreachable" <?= $filterStatus === 'unreachable' ? 'aria-current="true"' : '' ?>><div class="strip-label"><span class="dot dot-dead"></span>Unreachable</div><div class="strip-value is-dead"><?= (int)$unreachableCount ?></div></a>
  <a class="strip-item" href="?page=deployments&status=outdated" <?= $filterStatus === 'outdated' ? 'aria-current="true"' : '' ?>><div class="strip-label"><?= so_icon('rotate-cw', 'icon-sm') ?>Outdated builds</div><div class="strip-value"><?= (int)$outdatedCount ?></div></a>
</div>

<div class="toolbar">
  <form class="search" role="search" method="get" action="dashboard.php">
    <input type="hidden" name="page" value="deployments">
    <label class="sr-only" for="q">Search deployments</label>
    <?= so_icon('search') ?>
    <input class="input" id="q" name="search" type="search" placeholder="Search client, domain, or IP" value="<?= so_e($searchQuery ?? '') ?>">
  </form>
  <div class="chips" role="group" aria-label="Filter by product">
    <a class="chip" href="?page=deployments" <?= empty($filterProduct) ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>>All products</a>
    <?php foreach ($products as $p): ?>
      <a class="chip" href="?page=deployments&product_id=<?= (int)$p['id'] ?>" <?= ($filterProduct ?? 0) === (int)$p['id'] ? 'aria-pressed="true"' : 'aria-pressed="false"' ?>><?= so_e($p['product_name']) ?></a>
    <?php endforeach; ?>
  </div>
  <span class="toolbar-spacer"></span>
  <span class="result-count"><?= count($filteredDeployments) ?> deployments</span>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th scope="col">Client</th>
        <th scope="col">Product</th>
        <th scope="col">Status</th>
        <th scope="col">Version</th>
        <th scope="col" class="num">Database</th>
        <th scope="col" class="num">Free disk</th>
        <th scope="col" class="num">Users 24h</th>
        <th scope="col">Errors</th>
        <th scope="col">Last heartbeat</th>
        <th scope="col"><span class="sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($filteredDeployments)): ?>
        <tr><td colspan="10" class="dim" style="text-align:center; padding:32px;">No deployments matched the current filters.</td></tr>
      <?php else: ?>
        <?php foreach ($filteredDeployments as $d): 
          $s = strtolower((string)($d['computed_status'] ?? 'unknown'));
          $rCls = so_row_class($s);
          $rel = so_rel_time($d['last_heartbeat_at'] ?? null);
          $payload = so_dep_payload($d);
        ?>
          <tr class="<?= $rCls ?>">
            <td>
              <div class="cell-main">
                <div style="display:flex; align-items:center; gap:6px;">
                  <button type="button" class="link-btn" data-dep-open data-dep="<?= so_json_attr($payload) ?>" data-remove-href="?delete_deployment=<?= (int)$d['id'] ?>&page=deployments"><?= so_e($d['client_name']) ?></button>
                  <a href="dashboard.php?action=download_bundle&id=<?= (int)$d['id'] ?>" download="spectraops.json" class="btn btn-icon btn-sm" title="Download spectraops.json" aria-label="Download spectraops.json"><?= so_icon('download') ?></a>
                </div>
                <span class="cell-sub"><?= so_e($d['deployment_domain']) ?></span>
              </div>
            </td>
            <td><span class="tag"><?= so_e($d['product_name']) ?></span></td>
            <td><?= so_status_pill($s, $d['status_reason'] ?? null) ?></td>
            <td><span class="ver <?= !empty($d['is_outdated']) ? 'is-outdated' : '' ?>" <?= !empty($d['is_outdated']) ? 'title="Latest is v' . so_e($d['latest_version']) . '"' : '' ?>>v<?= so_e($d['current_version'] ?? '—') ?></span></td>
            <td class="num <?= so_metric_class('latency', $d['db_latency_ms']) ?>"><?= so_fmt_ms($d['db_latency_ms']) ?></td>
            <td class="num <?= so_metric_class('disk', $d['storage_free_gb']) ?>"><?= so_fmt_gb($d['storage_free_gb']) ?></td>
            <td class="num"><?= so_fmt_int($d['active_users_24h']) ?></td>
            <td><span class="badge-n <?= empty($d['error_count']) ? 'is-zero' : '' ?>"><?= (int)($d['error_count'] ?? 0) ?></span></td>
            <td class="nowrap num <?= so_rel_class($rel['level']) ?>"><?= so_e($rel['text']) ?></td>
            <td class="col-actions">
              <div class="row-actions">
                <button type="button" class="btn btn-icon btn-sm" data-dep-open data-dep="<?= so_json_attr($payload) ?>" data-remove-href="?delete_deployment=<?= (int)$d['id'] ?>&page=deployments" aria-label="Open diagnostics for <?= so_e($d['client_name']) ?>"><?= so_icon('eye') ?></button>
                <a class="btn btn-icon btn-sm" href="https://<?= so_e($d['deployment_domain']) ?>" target="_blank" rel="noopener" aria-label="Open <?= so_e($d['client_name']) ?> site"><?= so_icon('external-link') ?></a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($activePage === 'metrics'): ?>
<!-- ================= VIEW: METRICS ================= -->
<div class="summary">
  <span>Fleet health<b><?= (int)$fleetHealthPct ?>%</b></span>
  <span>Avg database latency<b><?= so_fmt_ms($avgLatency) ?></b></span>
  <span>Active users (24h)<b><?= so_fmt_int($totalActiveUsers24h) ?></b></span>
  <span>Version adoption<b><?= (int)$versionAdoptionPct ?>%</b></span>
</div>

<div class="content">
<div class="grid-auto">
  <section class="panel">
    <div class="panel-head"><h2>Database latency</h2><small>warn over 500 ms, critical over 1,500 ms</small></div>
    <div class="panel-body">
      <?php if (empty($latencyList)): ?>
        <p class="dim" style="padding:12px;">No latency metrics recorded yet.</p>
      <?php else: ?>
        <?php foreach (array_slice($latencyList, 0, 8) as $item): 
          $lat = (float)$item['latency'];
          $latCls = so_metric_class('latency', $lat);
          $barPct = min(100, max(5, round(($lat / $maxLatencyBar) * 100)));
        ?>
          <div class="meter-row">
            <div class="meter-head">
              <span><?= so_e($item['name']) ?> <span class="faint"><?= so_e($item['domain'] ?? '') ?></span></span>
              <span class="num <?= $latCls ?>"><?= so_fmt_ms($lat) ?></span>
            </div>
            <div class="meter"><i class="<?= $latCls === 'is-crit' ? 'is-crit' : ($latCls === 'is-hot' ? 'is-warn' : '') ?>" style="--v:<?= $barPct ?>%"></i></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Free disk</h2><small>warn under 3 GB, critical under 1 GB</small></div>
    <div class="panel-body">
      <?php 
      $diskNodes = array_filter($deployments, fn($d) => !empty($d['storage_free_gb']));
      usort($diskNodes, fn($a, $b) => ($a['storage_free_gb'] <=> $b['storage_free_gb']));
      if (empty($diskNodes)): ?>
        <p class="dim" style="padding:12px;">No disk telemetry recorded yet.</p>
      <?php else: ?>
        <?php foreach (array_slice($diskNodes, 0, 8) as $d): 
          $diskVal = (float)$d['storage_free_gb'];
          $dCls = so_metric_class('disk', $diskVal);
        ?>
          <div class="meter-row">
            <div class="meter-head">
              <span><?= so_e($d['client_name']) ?> <span class="faint"><?= so_e($d['deployment_domain']) ?></span></span>
              <span class="num <?= $dCls ?>"><?= so_fmt_gb($diskVal) ?></span>
            </div>
            <div class="meter"><i class="<?= $dCls === 'is-crit' ? 'is-crit' : ($dCls === 'is-hot' ? 'is-warn' : '') ?>" style="--v:<?= min(100, max(3, round($diskVal))) ?>%"></i></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Memory in use</h2><small>peak per heartbeat</small></div>
    <div class="panel-body">
      <?php if (empty($memoryList)): ?>
        <p class="dim" style="padding:12px;">No memory metrics reported yet.</p>
      <?php else: ?>
        <?php foreach (array_slice($memoryList, 0, 8) as $item): 
          $mem = (float)$item['memory'];
          $barPct = min(100, max(5, round(($mem / $maxMemoryBar) * 100)));
        ?>
          <div class="meter-row">
            <div class="meter-head">
              <span><?= so_e($item['name']) ?></span>
              <span class="num"><?= so_fmt_mb($mem) ?></span>
            </div>
            <div class="meter"><i class="is-info" style="--v:<?= $barPct ?>%"></i></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Heartbeat freshness</h2><small><?= (int)$totalDeployments ?> systems</small></div>
    <div class="panel-body">
      <?php
      $fresh = 0; $stale = 0; $dead = 0;
      foreach ($deployments as $d) {
        $rt = so_rel_time($d['last_heartbeat_at'] ?? null);
        if ($rt['level'] === 'dead' || $rt['level'] === 'never') $dead++;
        elseif ($rt['level'] === 'stale') $stale++;
        else $fresh++;
      }
      $tot = max(1, $totalDeployments);
      ?>
      <div class="meter-row"><div class="meter-head"><span>Under 30 minutes</span><span class="num"><?= $fresh ?></span></div><div class="meter"><i style="--v:<?= round(($fresh / $tot) * 100) ?>%"></i></div></div>
      <div class="meter-row"><div class="meter-head"><span>30 minutes to 2 hours</span><span class="num"><?= $stale ?></span></div><div class="meter"><i class="is-warn" style="--v:<?= round(($stale / $tot) * 100) ?>%"></i></div></div>
      <div class="meter-row"><div class="meter-head"><span>Over 2 hours</span><span class="num"><?= $dead ?></span></div><div class="meter"><i class="is-dead" style="--v:<?= round(($dead / $tot) * 100) ?>%"></i></div></div>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>PHP versions</h2></div>
    <div class="panel-body">
      <?php 
      $phpVersions = [];
      foreach ($deployments as $d) {
        $ver = !empty($d['php_version']) ? 'PHP ' . strtok($d['php_version'], ' ') : 'Unknown';
        $phpVersions[$ver] = ($phpVersions[$ver] ?? 0) + 1;
      }
      arsort($phpVersions);
      foreach ($phpVersions as $ver => $c): ?>
        <div class="meter-row">
          <div class="meter-head"><span><?= so_e($ver) ?></span><span class="num"><?= (int)$c ?></span></div>
          <div class="meter"><i class="is-info" style="--v:<?= round(($c / max(1, $totalDeployments)) * 100) ?>%"></i></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Operating systems</h2></div>
    <div class="panel-body">
      <?php 
      $osDist = [];
      foreach ($deployments as $d) {
        $os = !empty($d['os_info']) ? $d['os_info'] : 'Unknown';
        $osDist[$os] = ($osDist[$os] ?? 0) + 1;
      }
      arsort($osDist);
      foreach (array_slice($osDist, 0, 8) as $os => $c): ?>
        <div class="meter-row">
          <div class="meter-head"><span><?= so_e($os) ?></span><span class="num"><?= (int)$c ?></span></div>
          <div class="meter"><i class="is-info" style="--v:<?= round(($c / max(1, $totalDeployments)) * 100) ?>%"></i></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
</div>

<?php elseif ($activePage === 'errors'): ?>
<!-- ================= VIEW: ERRORS ================= -->
<div class="toolbar">
  <div class="field" style="min-width:240px">
    <label class="sr-only" for="f-dep">Deployment</label>
    <select class="select" id="f-dep" data-no-busy>
      <option value="">All deployments</option>
      <?php foreach ($deployments as $d): ?>
        <option value="<?= so_e($d['client_name']) ?>"><?= so_e($d['client_name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <span class="toolbar-spacer"></span>
  <span class="result-count"><?= count($errorLogs ?? []) ?> incidents</span>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th scope="col">Level</th>
        <th scope="col">Message</th>
        <th scope="col">System</th>
        <th scope="col">Location</th>
        <th scope="col" class="num">Seen</th>
        <th scope="col" class="num">Last seen</th>
        <th scope="col"><span class="sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($errorLogs)): ?>
        <tr><td colspan="7" class="dim" style="text-align:center; padding:32px;">Zero exception incidents found. System operational health is stable.</td></tr>
      <?php else: ?>
        <?php foreach ($errorLogs as $err): 
          $lvl = strtoupper((string)($err['error_level'] ?? 'ERROR'));
          $isCrit = in_array($lvl, ['FATAL', 'CRITICAL']);
          $errPayload = [
            'id' => $err['id'] ?? 0,
            'error_level' => $lvl,
            'error_message' => $err['error_message'] ?? '',
            'client_name' => $err['client_name'] ?? 'Deployment #' . ($err['deployment_id'] ?? 0),
            'deployment_domain' => $err['deployment_domain'] ?? '',
            'product_name' => $err['product_name'] ?? '',
            'file_path' => $err['file_path'] ?? '',
            'line_number' => $err['line_number'] ?? 0,
            'occurrences' => $err['occurrences'] ?? 1,
            'last_seen_at' => $err['last_seen_at'] ?? ($err['occurred_at'] ?? ''),
            'first_seen_at' => $err['first_seen_at'] ?? ($err['occurred_at'] ?? ''),
            'stack_trace' => $err['stack_trace'] ?? '',
            'request_uri' => $err['request_uri'] ?? ''
          ];
        ?>
          <tr class="<?= $isCrit ? 'row-crit' : '' ?>">
            <td><span class="pill <?= $isCrit ? 'pill-crit' : 'pill-warn' ?>"><?= so_e($lvl) ?></span></td>
            <td style="max-width:360px"><div class="truncate" title="<?= so_e($err['error_message']) ?>"><?= so_e($err['error_message']) ?></div></td>
            <td>
              <div class="cell-main">
                <strong><?= so_e($err['client_name'] ?? 'Node #' . ($err['deployment_id'] ?? 0)) ?></strong>
                <span class="cell-sub"><?= so_e($err['product_name'] ?? '') ?></span>
              </div>
            </td>
            <td class="mono dim nowrap"><?= so_e(basename((string)($err['file_path'] ?? ''))) ?><?= !empty($err['line_number']) ? ':' . (int)$err['line_number'] : '' ?></td>
            <td class="num"><?= (int)($err['occurrences'] ?? 1) ?>×</td>
            <td class="num nowrap dim"><?= so_e(substr((string)($err['last_seen_at'] ?? ($err['occurred_at'] ?? '')), 5, 11)) ?></td>
            <td class="col-actions">
              <div class="row-actions">
                <button type="button" class="btn btn-sm" data-err-open data-err="<?= so_e(json_encode($errPayload, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)) ?>" data-clear-href="?delete_error=<?= (int)$err['id'] ?>&page=errors">View trace</button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($activePage === 'tickets'): ?>
<!-- ================= VIEW: SUPPORT TICKETS ================= -->
<div class="toolbar">
  <div class="seg" role="group" aria-label="Filter by status">
    <a class="seg-item" href="?page=tickets" <?= ($filterTicketStatus ?? 'all') === 'all' ? 'aria-current="true"' : '' ?>>All <span class="num"><?= count($tickets ?? []) ?></span></a>
    <a class="seg-item" href="?page=tickets&ticket_status=open" <?= ($filterTicketStatus ?? '') === 'open' ? 'aria-current="true"' : '' ?>>Open</a>
    <a class="seg-item" href="?page=tickets&ticket_status=resolved" <?= ($filterTicketStatus ?? '') === 'resolved' ? 'aria-current="true"' : '' ?>>Resolved</a>
    <a class="seg-item" href="?page=tickets&ticket_status=closed" <?= ($filterTicketStatus ?? '') === 'closed' ? 'aria-current="true"' : '' ?>>Closed</a>
  </div>
  <span class="toolbar-spacer"></span>
  <span class="result-count"><?= count($tickets ?? []) ?> tickets</span>
</div>

<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th scope="col">Ticket</th>
        <th scope="col">Client and requester</th>
        <th scope="col">Priority</th>
        <th scope="col">Status</th>
        <th scope="col" class="num">Opened</th>
        <th scope="col"><span class="sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($tickets)): ?>
        <tr><td colspan="6" class="dim" style="text-align:center; padding:32px;">No support tickets in this view.</td></tr>
      <?php else: ?>
        <?php foreach ($tickets as $t): 
          $st = strtolower((string)($t['status'] ?? 'open'));
          $pr = strtolower((string)($t['priority'] ?? 'medium'));
          $prCls = ($pr === 'urgent' || $pr === 'critical') ? 'pill-crit' : (($pr === 'high') ? 'pill-warn' : 'pill-info');
          $stCls = $st === 'resolved' ? 'pill-ok' : ($st === 'closed' ? 'pill-dead' : ($st === 'in_progress' ? 'pill-warn' : 'pill-info'));
        ?>
          <tr>
            <td>
              <div class="cell-main">
                <strong><?= so_e($t['subject'] ?? 'Ticket') ?></strong>
                <span class="cell-sub"><?= so_e($t['reference'] ?? '') ?> · <?= so_e($t['category'] ?? 'general') ?></span>
              </div>
            </td>
            <td>
              <div class="cell-main">
                <strong><?= so_e($t['client_name'] ?? 'Client') ?></strong>
                <span class="cell-sub"><?= so_e($t['requester_name'] ?? '') ?><?= !empty($t['requester_role']) ? ', ' . so_e($t['requester_role']) : '' ?></span>
              </div>
            </td>
            <td><span class="pill <?= $prCls ?>"><?= so_e($pr) ?></span></td>
            <td><span class="pill <?= $stCls ?>"><?= so_e($st) ?></span></td>
            <td class="num nowrap dim"><?= so_e(substr((string)($t['created_at'] ?? ''), 5, 11)) ?></td>
            <td class="col-actions">
              <div class="row-actions" style="opacity:1">
                <?php if ($st === 'open' || $st === 'in_progress'): ?>
                  <a class="btn btn-sm" href="?resolve_ticket=<?= urlencode((string)$t['reference']) ?>&status=resolved&page=tickets">Resolve</a>
                  <a class="btn btn-sm btn-ghost" href="?resolve_ticket=<?= urlencode((string)$t['reference']) ?>&status=closed&page=tickets">Close</a>
                <?php else: ?>
                  <a class="btn btn-sm btn-ghost" href="?resolve_ticket=<?= urlencode((string)$t['reference']) ?>&status=open&page=tickets">Reopen</a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif (in_array($activePage, ['releases', 'updates'])): ?>
<!-- ================= VIEW: RELEASES ================= -->
<div class="content">
<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2>Published releases</h2><small><?= count($releases ?? []) ?> packages</small></div>
    <div class="table-wrap">
      <table class="table" style="min-width:760px">
        <thead>
          <tr>
            <th scope="col">Product</th>
            <th scope="col">Version</th>
            <th scope="col">Changelog</th>
            <th scope="col">Package</th>
            <th scope="col">SHA-256</th>
            <th scope="col" class="num">Released</th>
            <th scope="col">State</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($releases)): ?>
            <tr><td colspan="7" class="dim" style="text-align:center; padding:32px;">No releases published yet. Use "Publish release" to upload an OTA update.</td></tr>
          <?php else: ?>
            <?php foreach ($releases as $rel): ?>
              <tr>
                <td><span class="tag"><?= so_e($rel['product_name'] ?? 'Product') ?></span></td>
                <td><span class="ver" style="color:var(--text)">v<?= so_e($rel['version'] ?? '') ?></span></td>
                <td class="dim" style="max-width:320px"><div class="truncate" title="<?= so_e($rel['changelog'] ?? '') ?>"><?= so_e($rel['changelog'] ?? '—') ?></div></td>
                <td class="mono dim nowrap"><?= so_e($rel['package_filename'] ?? '') ?></td>
                <td class="mono faint nowrap" title="<?= so_e($rel['package_checksum'] ?? '') ?>"><?= so_e(substr((string)($rel['package_checksum'] ?? ''), 0, 12)) ?>…</td>
                <td class="num nowrap dim"><?= so_e(substr((string)($rel['released_at'] ?? ''), 0, 10)) ?></td>
                <td><span class="pill <?= !empty($rel['is_active']) ? 'pill-ok' : 'pill-dead' ?>"><?= !empty($rel['is_active']) ? 'active' : 'retired' ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Version adoption</h2><small><?= (int)$versionAdoptionPct ?>% on latest</small></div>
    <div class="panel-body">
      <?php foreach ($products as $p): 
        $pid = (int)$p['id'];
        $pNodes = array_filter($deployments, fn($d) => (int)($d['product_id'] ?? 0) === $pid);
        $totalP = count($pNodes);
        if ($totalP === 0) continue;
        $currP = count(array_filter($pNodes, fn($d) => empty($d['is_outdated'])));
        $pctP = round(($currP / $totalP) * 100);
      ?>
        <div class="meter-row">
          <div class="meter-head">
            <span><?= so_e($p['product_name']) ?> <span class="faint">v<?= so_e($p['latest_version'] ?? '1.0.0') ?></span></span>
            <span class="num"><?= $currP ?> of <?= $totalP ?> current</span>
          </div>
          <div class="meter"><i class="<?= $pctP < 60 ? 'is-crit' : ($pctP < 90 ? 'is-warn' : '') ?>" style="--v:<?= $pctP ?>%"></i></div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
</div>

<?php elseif ($activePage === 'products'): ?>
<!-- ================= VIEW: PRODUCTS ================= -->
<div class="table-wrap">
  <table class="table" style="min-width:700px">
    <thead>
      <tr>
        <th scope="col">Slug</th>
        <th scope="col">Product Name</th>
        <th scope="col">Description</th>
        <th scope="col">Repository</th>
        <th scope="col" class="num">Deployments</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($products as $p): 
        $cnt = count(array_filter($deployments, fn($d) => (int)($d['product_id'] ?? 0) === (int)$p['id']));
      ?>
        <tr>
          <td class="mono font-semibold"><?= so_e($p['product_slug']) ?></td>
          <td><strong style="font-weight:600"><?= so_e($p['product_name']) ?></strong></td>
          <td class="dim" style="max-width:320px"><div class="truncate"><?= so_e($p['description'] ?? '—') ?></div></td>
          <td class="mono dim nowrap"><?= so_e($p['repository_url'] ?? '—') ?></td>
          <td class="num"><?= $cnt ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php elseif ($activePage === 'licenses'): ?>
<!-- ================= VIEW: LICENSES ================= -->
<div class="content">
<div class="grid-2">
  <section class="panel">
    <div class="panel-head"><h2>Cloud Issued Licenses</h2><small><?= count($licensesList ?? []) ?> total</small></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">License Key</th>
            <th scope="col">Client</th>
            <th scope="col">Status</th>
            <th scope="col">Expiry</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($licensesList)): ?>
            <tr><td colspan="4" class="dim" style="text-align:center; padding:24px;">No central licenses issued yet.</td></tr>
          <?php else: ?>
            <?php foreach (array_slice($licensesList, 0, 15) as $lic): 
              $lst = strtolower((string)($lic['status'] ?? 'active'));
              $lstCls = $lst === 'active' ? 'pill-ok' : ($lst === 'suspended' ? 'pill-warn' : 'pill-dead');
            ?>
              <tr>
                <td class="mono font-semibold"><span title="<?= so_e($lic['license_key'] ?? '') ?>"><?= so_e($lic['license_key'] ?? '') ?></span></td>
                <td>
                  <div class="cell-main">
                    <strong><?= so_e($lic['client_name'] ?? 'Client') ?></strong>
                    <span class="cell-sub"><?= so_e($lic['product_name'] ?? '') ?></span>
                  </div>
                </td>
                <td><span class="pill <?= $lstCls ?>"><?= so_e($lst) ?></span></td>
                <td class="mono dim nowrap"><?= !empty($lic['expires_at']) ? so_e(substr((string)$lic['expires_at'], 0, 10)) : 'Perpetual' ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="panel">
    <div class="panel-head"><h2>Offline VendPulse Licenses</h2><small><?= (int)($vpStats['total'] ?? 0) ?> cryptographic leases</small></div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th scope="col">Reference ID</th>
            <th scope="col">Client</th>
            <th scope="col">Mode</th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($vpLicenses)): ?>
            <tr><td colspan="4" class="dim" style="text-align:center; padding:24px;">No offline VendPulse licenses generated yet.</td></tr>
          <?php else: ?>
            <?php foreach (array_slice($vpLicenses, 0, 15) as $vplic): 
              $vst = strtolower((string)($vplic['status'] ?? 'active'));
              $vstCls = $vst === 'active' ? 'pill-ok' : ($vst === 'suspended' ? 'pill-warn' : 'pill-dead');
            ?>
              <tr>
                <td class="mono font-semibold"><?= so_e($vplic['license_id'] ?? '') ?></td>
                <td><?= so_e($vplic['client_name'] ?? '') ?></td>
                <td><span class="tag"><?= so_e($vplic['usage_mode'] ?? 'RETAIL') ?></span></td>
                <td><span class="pill <?= $vstCls ?>"><?= so_e($vst) ?></span></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
</div>

<?php elseif ($activePage === 'orders'): ?>
<!-- ================= VIEW: STORE ORDERS ================= -->
<div class="table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th scope="col">Order Reference</th>
        <th scope="col">Customer</th>
        <th scope="col">Product</th>
        <th scope="col">Status</th>
        <th scope="col" class="num">Downloads</th>
        <th scope="col" class="num">Created</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($orders)): ?>
        <tr><td colspan="6" class="dim" style="text-align:center; padding:32px;">No orders found in registry.</td></tr>
      <?php else: ?>
        <?php foreach ($orders as $ord): 
          $ost = strtolower((string)($ord['status'] ?? 'completed'));
          $ostCls = $ost === 'completed' ? 'pill-ok' : ($ost === 'pending' ? 'pill-warn' : 'pill-dead');
        ?>
          <tr>
            <td class="mono font-semibold"><?= so_e($ord['order_reference'] ?? '') ?></td>
            <td>
              <div class="cell-main">
                <strong><?= so_e($ord['business_name'] ?? ($ord['customer_name'] ?? 'Customer')) ?></strong>
                <span class="cell-sub"><?= so_e($ord['customer_email'] ?? '') ?></span>
              </div>
            </td>
            <td><span class="tag"><?= so_e($ord['purchased_product'] ?? '') ?></span></td>
            <td><span class="pill <?= $ostCls ?>"><?= so_e($ost) ?></span></td>
            <td class="num"><?= (int)($ord['download_count'] ?? 0) ?></td>
            <td class="num dim nowrap"><?= so_e(substr((string)($ord['created_at'] ?? ''), 0, 10)) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php elseif ($activePage === 'users'): ?>
<!-- ================= VIEW: USERS ================= -->
<div class="table-wrap">
  <table class="table" style="min-width:600px">
    <thead>
      <tr>
        <th scope="col">Name</th>
        <th scope="col">Email</th>
        <th scope="col">Role</th>
        <th scope="col" class="num">Added</th>
        <th scope="col"><span class="sr-only">Actions</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($usersList as $u): ?>
        <tr>
          <td><strong style="font-weight:500"><?= so_e($u['name'] ?? 'Operator') ?></strong></td>
          <td class="mono dim"><?= so_e($u['email'] ?? '') ?></td>
          <td><span class="pill pill-info pill-plain"><?= so_e($u['role'] ?? 'viewer') ?></span></td>
          <td class="num dim"><?= so_e(substr((string)($u['created_at'] ?? ''), 0, 10)) ?></td>
          <td class="col-actions">
            <div class="row-actions">
              <?php if ((int)($u['id'] ?? 0) !== (int)($_SESSION['fleet_user_id'] ?? 0)): ?>
                <a class="btn btn-icon btn-sm btn-ghost" href="?delete_user=<?= (int)$u['id'] ?>&page=users" data-confirm="Permanently delete user <?= so_e($u['name']) ?>?" aria-label="Delete user <?= so_e($u['name']) ?>"><?= so_icon('trash-2') ?></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

</main>
</div>
</div>

<!-- ================= OVERLAYS, DRAWERS & DIALOGS ================= -->

<!-- 1. Deployment Diagnostics Drawer -->
<div class="overlay overlay-end" id="dep-drawer" aria-hidden="true">
  <div class="drawer" role="dialog" aria-modal="true" aria-labelledby="dep-drawer-t">
    <div class="overlay-head">
      <div>
        <h2 id="dep-drawer-t"><span id="dep-title">Deployment</span></h2>
        <p><span id="dep-domain"></span></p>
      </div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <div id="dep-status"></div>
      <p class="dim" id="dep-reason"></p>
      <div class="tiles">
        <div class="tile"><span>Database latency</span><strong id="dep-latency">—</strong></div>
        <div class="tile"><span>Memory</span><strong id="dep-memory">—</strong></div>
        <div class="tile"><span>Free disk</span><strong id="dep-disk">—</strong></div>
        <div class="tile"><span>Active users (24h)</span><strong id="dep-users">—</strong></div>
      </div>
      <div id="dep-spark-wrap"><div class="section-label">Database latency, recent heartbeats</div><div id="dep-spark"></div></div>
      <dl class="kv">
        <dt>Product</dt><dd id="dep-product"></dd>
        <dt>Environment</dt><dd id="dep-env"></dd>
        <dt>Server IP</dt><dd class="mono" id="dep-ip"></dd>
        <dt>Runtime</dt><dd class="mono" id="dep-stack"></dd>
        <dt>Operating system</dt><dd id="dep-os"></dd>
        <dt>Last heartbeat</dt><dd class="mono" id="dep-heartbeat"></dd>
        <dt>Ingest key</dt><dd class="mono" id="dep-key"></dd>
      </dl>
    </div>
    <div class="overlay-foot">
      <a class="btn btn-danger is-left" id="dep-remove" href="#" data-confirm="Remove this deployment and all of its telemetry?" hidden><?= so_icon('trash-2') ?>Remove</a>
      <a class="btn" id="dep-visit" href="#" target="_blank" rel="noopener">Open site<?= so_icon('external-link', 'icon-sm') ?></a>
      <button type="button" class="btn" data-close>Close</button>
    </div>
  </div>
</div>

<!-- 2. Error Incident Trace Dialog -->
<div class="overlay" id="err-dialog" aria-hidden="true">
  <div class="dialog dialog-wide" role="dialog" aria-modal="true" aria-labelledby="err-dialog-t">
    <div class="overlay-head">
      <div>
        <h2 id="err-dialog-t"><span id="err-level"></span> Error incident</h2>
      </div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <p id="err-message" style="font-weight:500;overflow-wrap:anywhere"></p>
      <dl class="kv">
        <dt>System</dt><dd id="err-client"></dd>
        <dt>Location</dt><dd class="mono" id="err-location"></dd>
        <dt>Occurrences</dt><dd class="mono" id="err-count"></dd>
        <dt>First seen</dt><dd class="mono" id="err-first"></dd>
        <dt>Last seen</dt><dd class="mono" id="err-last"></dd>
      </dl>
      <div>
        <div class="section-label">Stack trace</div>
        <pre class="codeblock" id="err-trace"></pre>
      </div>
    </div>
    <div class="overlay-foot">
      <a class="btn btn-danger is-left" id="err-clear" href="#" data-confirm="Clear this error from the stream?" hidden><?= so_icon('trash-2') ?>Clear error</a>
      <button type="button" class="btn" data-close>Close</button>
    </div>
  </div>
</div>

<!-- 3. One-Click Link New Deployment & Connection Bundle Dialog -->
<div class="overlay" id="dlg-link-deploy" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-link-t">
    <div class="overlay-head">
      <div>
        <h2 id="dlg-link-t">Link New Deployment</h2>
        <p>Provisions deployment &amp; license bundle (spectraops.json)</p>
      </div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <form id="dlg-link-f" method="post">
        <input type="hidden" name="action" value="link_deployment">
        <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-grid">
          <div class="field">
            <label for="link_product_slug">Product</label>
            <select class="select" id="link_product_slug" name="product_slug" required>
              <option value="">Select product...</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= so_e($p['product_slug']) ?>"><?= so_e($p['product_name']) ?> (<?= so_e($p['product_slug']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="link_client_name">Client Name</label>
            <input class="input" id="link_client_name" name="client_name" type="text" placeholder="Westminster Demo" required>
          </div>
          <div class="field span-2">
            <label for="link_domain">Deployment Domain</label>
            <input class="input mono" id="link_domain" name="domain" type="text" placeholder="https://demo.prismedu.com" required>
            <div class="field-hint">Target site URL (e.g. https://demo.prismedu.com).</div>
          </div>
          <div class="field">
            <label for="link_environment">Environment</label>
            <select class="select" id="link_environment" name="environment">
              <option value="production">Production</option>
              <option value="staging">Staging</option>
              <option value="demo" selected>Demo</option>
            </select>
          </div>
          <div class="field">
            <label for="link_license_tier">License Tier</label>
            <select class="select" id="link_license_tier" name="license_tier">
              <option value="demo">Demo / Trial</option>
              <option value="standard" selected>Standard Enterprise</option>
              <option value="premium">Premium Pro</option>
            </select>
          </div>
        </div>
      </form>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" form="dlg-link-f" class="btn btn-primary">Link &amp; Generate Bundle</button>
    </div>
  </div>
</div>

<!-- 4. Register Deployment Modal -->
<div class="overlay" id="dlg-register" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-register-t">
    <div class="overlay-head">
      <div>
        <h2 id="dlg-register-t">Register a deployment</h2>
        <p>Creates an ingest token for the client app.</p>
      </div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <form id="dlg-register-f" method="post">
        <input type="hidden" name="action" value="register_deploy">
        <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-grid">
          <div class="field">
            <label for="r-product_id">Product</label>
            <select class="select" id="r-product_id" name="product_id" required>
              <option value="">Select a product</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= so_e($p['product_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="client_name">Client name</label>
            <input class="input" id="client_name" name="client_name" type="text" placeholder="Oakridge Preparatory School" required>
          </div>
          <div class="field">
            <label for="deployment_domain">Deployment domain</label>
            <input class="input" id="deployment_domain" name="deployment_domain" type="text" placeholder="prism.oakridge.edu" required aria-describedby="deployment_domain-h">
            <div class="field-hint" id="deployment_domain-h">Hostname only, no https://.</div>
          </div>
          <div class="field">
            <label for="r-environment">Environment</label>
            <select class="select" id="r-environment" name="environment">
              <option value="production">Production</option>
              <option value="staging">Staging</option>
              <option value="development">Development</option>
            </select>
          </div>
        </div>
      </form>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" form="dlg-register-f" class="btn btn-primary">Register deployment</button>
    </div>
  </div>
</div>

<!-- 5. Pair Client Modal -->
<div class="overlay" id="dlg-pair" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-pair-t">
    <div class="overlay-head">
      <div>
        <h2 id="dlg-pair-t">Pair an existing client</h2>
        <p>Links a client that already has its own key.</p>
      </div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <form id="dlg-pair-f" method="post">
        <input type="hidden" name="action" value="pair_client">
        <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-grid">
          <div class="field">
            <label for="p-product_id">Product</label>
            <select class="select" id="p-product_id" name="product_id" required>
              <option value="">Select a product</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= so_e($p['product_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="p_client_name">Client name</label>
            <input class="input" id="p_client_name" name="client_name" type="text" placeholder="Horizon Science Academy" required>
          </div>
          <div class="field">
            <label for="p_deployment_domain">Deployment domain</label>
            <input class="input" id="p_deployment_domain" name="deployment_domain" type="text" placeholder="sis.horizon.edu" required>
          </div>
          <div class="field span-2">
            <label for="p-api_key">Client API key</label>
            <input class="input mono" id="p-api_key" name="api_key" required autocomplete="off" aria-describedby="p-k-h">
            <div class="field-hint" id="p-k-h">Paste the key generated inside the client app.</div>
          </div>
        </div>
      </form>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" form="dlg-pair-f" class="btn btn-primary">Pair client</button>
    </div>
  </div>
</div>

<!-- 6. Publish Release Modal -->
<div class="overlay" id="dlg-publish" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-publish-t">
    <div class="overlay-head">
      <div>
        <h2 id="dlg-publish-t">Publish a release</h2>
        <p>Clients see it the next time they check for updates.</p>
      </div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <form id="dlg-publish-f" method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="publish_release">
        <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-grid">
          <div class="field">
            <label for="u-product_id">Product</label>
            <select class="select" id="u-product_id" name="product_id" required>
              <option value="">Select a product</option>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= so_e($p['product_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="version">Version</label>
            <input class="input" id="version" name="version" type="text" placeholder="2.5.1" required>
          </div>
          <div class="field span-2">
            <label for="u-changelog">Changelog</label>
            <textarea class="textarea" id="u-changelog" name="changelog" placeholder="One change per line"></textarea>
          </div>
          <div class="field span-2">
            <label for="u-pkg">Release package (.zip)</label>
            <input class="input input-file" id="u-pkg" name="package_file" type="file" accept=".zip,application/zip" required>
            <div class="field-hint">The SHA-256 checksum is calculated for you.</div>
          </div>
          <label class="check span-2"><input type="checkbox" name="is_active" value="1" checked> Make this release available to clients</label>
        </div>
      </form>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" form="dlg-publish-f" class="btn btn-primary">Publish release</button>
    </div>
  </div>
</div>

<!-- 7. New Product Modal -->
<div class="overlay" id="dlg-product" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-product-t">
    <div class="overlay-head">
      <div><h2 id="dlg-product-t">New product</h2></div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <form id="dlg-product-f" method="post">
        <input type="hidden" name="action" value="register_product">
        <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-grid">
          <div class="field">
            <label for="product_slug">Slug</label>
            <input class="input" id="product_slug" name="product_slug" type="text" placeholder="school-erp" required aria-describedby="product_slug-h">
            <div class="field-hint" id="product_slug-h">Lowercase letters, numbers and dashes.</div>
          </div>
          <div class="field">
            <label for="product_name">Name</label>
            <input class="input" id="product_name" name="product_name" type="text" placeholder="School ERP" required>
          </div>
          <div class="field span-2">
            <label for="np-d">Description</label>
            <textarea class="textarea" id="np-d" name="description"></textarea>
          </div>
          <div class="field span-2">
            <label for="repository_url">Repository URL</label>
            <input class="input" id="repository_url" name="repository_url" type="url" placeholder="https://github.com/…">
          </div>
        </div>
      </form>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" form="dlg-product-f" class="btn btn-primary">Create product</button>
    </div>
  </div>
</div>

<!-- 8. Add User Modal -->
<div class="overlay" id="dlg-user" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-user-t">
    <div class="overlay-head">
      <div><h2 id="dlg-user-t">Add a user</h2></div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <form id="dlg-user-f" method="post">
        <input type="hidden" name="action" value="register_user">
        <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-grid">
          <div class="field">
            <label for="user_name">Full name</label>
            <input class="input" id="user_name" name="user_name" type="text" placeholder="Jane Doe" required>
          </div>
          <div class="field">
            <label for="user_email">Email</label>
            <input class="input" id="user_email" name="user_email" type="email" placeholder="jane@company.com" required>
          </div>
          <div class="field">
            <label for="user_role">Role</label>
            <select class="select" id="user_role" name="user_role">
              <option value="viewer">Viewer (read only)</option>
              <option value="admin">Administrator (full access)</option>
            </select>
          </div>
          <div class="field">
            <label for="user_password">Temporary password</label>
            <input class="input" id="user_password" name="user_password" type="password" required aria-describedby="user_password-h" minlength="8" autocomplete="new-password">
            <div class="field-hint" id="user_password-h">At least 8 characters.</div>
          </div>
        </div>
      </form>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" form="dlg-user-f" class="btn btn-primary">Add user</button>
    </div>
  </div>
</div>

<!-- 9. Issue License Modal -->
<div class="overlay" id="dlg-license" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-license-t">
    <div class="overlay-head">
      <div><h2 id="dlg-license-t">Issue Software License</h2><p>Online cloud activation or offline cryptographic bundle.</p></div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <form id="dlg-license-f" method="post">
        <input type="hidden" name="action" value="issue_license">
        <input type="hidden" name="csrf_token" value="<?= so_e($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-grid">
          <div class="field">
            <label for="lic_product_id">Product</label>
            <select class="select" id="lic_product_id" name="product_id" required>
              <?php foreach ($products as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= so_e($p['product_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="lic_client_name">Client name</label>
            <input class="input" id="lic_client_name" name="client_name" type="text" placeholder="Client Name" required>
          </div>
          <div class="field">
            <label for="lic_client_email">Client email</label>
            <input class="input" id="lic_client_email" name="client_email" type="email" placeholder="client@example.com">
          </div>
          <div class="field">
            <label for="lic_tier">Tier / Type</label>
            <select class="select" id="lic_tier" name="license_tier">
              <option value="standard">Standard</option>
              <option value="enterprise">Enterprise</option>
              <option value="trial">Trial / Demo</option>
            </select>
          </div>
          <div class="field span-2">
            <label for="lic_domain">Bound domain</label>
            <input class="input mono" id="lic_domain" name="domain" type="text" placeholder="subdomain.example.com">
          </div>
        </div>
      </form>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn" data-close>Cancel</button>
      <button type="submit" form="dlg-license-f" class="btn btn-primary">Issue License</button>
    </div>
  </div>
</div>

<!-- 10. PKI Public Key Modal -->
<div class="overlay" id="dlg-vp-pubkey" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-vp-pk-t">
    <div class="overlay-head">
      <div><h2 id="dlg-vp-pk-t">VendPulse RSA-2048 Public Key</h2><p>Used by offline edge stations to verify digital signatures.</p></div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <pre class="codeblock" id="vp-pubkey-block"><?= so_e(file_exists(dirname(__DIR__) . '/keys/vendpulse_public.pem') ? file_get_contents(dirname(__DIR__) . '/keys/vendpulse_public.pem') : 'Public key file not generated yet.') ?></pre>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn btn-primary" data-copy="#vp-pubkey-block"><?= so_icon('copy') ?>Copy Public Key</button>
      <button type="button" class="btn" data-close>Close</button>
    </div>
  </div>
</div>

<!-- 11. Store Order Webhook Integration Info Modal -->
<div class="overlay" id="dlg-order-webhook" aria-hidden="true">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-webhook-t">
    <div class="overlay-head">
      <div><h2 id="dlg-webhook-t">Store Webhook Endpoint</h2><p>Connect your WooCommerce or Shopify store for automated fulfillment.</p></div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <div class="field">
        <label>Webhook URL</label>
        <div class="token-row">
          <input class="input mono" id="wh-url" readonly value="<?= so_e((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api/v1/store/order_webhook.php') ?>">
          <button type="button" class="btn" data-copy="#wh-url"><?= so_icon('copy') ?>Copy</button>
        </div>
      </div>
      <div class="field" style="margin-top:14px;">
        <label>Expected Payload</label>
        <pre class="codeblock">{"order_id":1001,"customer_name":"John Doe","email":"john@example.com","product":"PRISM_EDU"}</pre>
      </div>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn btn-primary" data-close>Done</button>
    </div>
  </div>
</div>

<!-- 12. Reveal Credentials / Key Modal -->
<?php if (!empty($regSuccess) || !empty($linkSuccess)): 
  $tokData = $regSuccess ?: $linkSuccess;
  $tokVal = $tokData['token'] ?? ($tokData['deployment_key'] ?? '');
  $tokId = (int)($tokData['id'] ?? ($tokData['deployment_id'] ?? 0));
?>
<div class="overlay is-open" id="dlg-token" aria-hidden="false">
  <div class="dialog" role="dialog" aria-modal="true" aria-labelledby="dlg-token-t">
    <div class="overlay-head">
      <div><h2 id="dlg-token-t">Deployment Registered &amp; Linked</h2></div>
      <button type="button" class="btn btn-icon btn-sm" data-close aria-label="Close"><?= so_icon('x') ?></button>
    </div>
    <div class="overlay-body">
      <div class="alert alert-warning">
        <?= so_icon('triangle-alert') ?>
        <div>Save credentials now. Ingest tokens and deployment keys are stored as cryptographic hashes and cannot be shown again.</div>
      </div>
      <div class="field" style="margin-top:14px;">
        <label for="tok">Credential / Key</label>
        <div class="token-row">
          <input class="input mono" id="tok" readonly value="<?= so_e($tokVal) ?>">
          <button type="button" class="btn" data-copy="#tok"><?= so_icon('copy') ?>Copy</button>
        </div>
      </div>
      <?php if ($tokId > 0): ?>
      <div style="margin-top:18px;">
        <a href="dashboard.php?action=download_bundle&id=<?= $tokId ?>" download="spectraops.json" class="btn btn-primary">
          <?= so_icon('download') ?> Download Connection Bundle (spectraops.json)
        </a>
      </div>
      <?php endif; ?>
    </div>
    <div class="overlay-foot">
      <button type="button" class="btn btn-primary" data-close>Done</button>
    </div>
  </div>
</div>
<?php endif; ?>

<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="assets/js/spectraops-ui.js"></script>
</body>
</html>
