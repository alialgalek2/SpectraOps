<?php
require_once __DIR__ . '/core/init.php';

// If already logged in, redirect to dashboard
if (Auth::check()) {
    header("Location: dashboard.php");
    exit();
}

$error = "";
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$lockoutSecondsRemaining = 0;
$isLocked = false;
$timeoutNotice = (isset($_GET['reason']) && $_GET['reason'] === 'timeout') || !empty($_SESSION['timeout_logout']);
if (!empty($_SESSION['timeout_logout'])) {
    unset($_SESSION['timeout_logout']);
}

// Check if client IP is currently locked out on page load
$ipLockCheck = LoginSecurity::checkLockout('', $clientIp);
if ($ipLockCheck['is_locked']) {
    $isLocked = true;
    $lockoutSecondsRemaining = $ipLockCheck['remaining_seconds'];
    $error = is_rtl() ? $ipLockCheck['message_ar'] : $ipLockCheck['message_en'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password = trim($_POST['password'] ?? '');

    // 1. Check if identifier or client IP is currently locked out
    $lockCheck = LoginSecurity::checkLockout($identifier, $clientIp);
    if ($lockCheck['is_locked']) {
        $isLocked = true;
        $lockoutSecondsRemaining = $lockCheck['remaining_seconds'];
        $error = is_rtl() ? $lockCheck['message_ar'] : $lockCheck['message_en'];
    } elseif (!empty($identifier) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (isset($user['status']) && $user['status'] !== 'Active') {
                    $error = is_rtl() ? "تم تعطيل هذا الحساب. يرجى التواصل مع مسؤول النظام." : "This account has been deactivated. Please contact the administrator.";
                } else {
                    // Success: reset failed attempts counter & establish session
                    LoginSecurity::recordSuccessfulLogin($identifier, $clientIp);
                    Auth::login($user);
                    header("Location: dashboard.php");
                    exit();
                }
            } else {
                // Failure: record attempt and check remaining retries
                LoginSecurity::recordFailedAttempt($identifier, $clientIp);
                $postCheck = LoginSecurity::checkLockout($identifier, $clientIp);
                if ($postCheck['is_locked']) {
                    $isLocked = true;
                    $lockoutSecondsRemaining = $postCheck['remaining_seconds'];
                    $error = is_rtl() ? $postCheck['message_ar'] : $postCheck['message_en'];
                } else {
                    $attemptsLeft = $postCheck['attempts_left'];
                    $error = is_rtl()
                        ? "اسم المستخدم أو كلمة المرور غير صحيحة. متبقي لديك {$attemptsLeft} محاولة قبل حظر تسجيل الدخول لمدة 5 دقائق."
                        : "Invalid username/email or password. You have {$attemptsLeft} attempt(s) remaining before a 5-minute lockout.";
                }
            }
        } catch (\PDOException $e) {
            error_log("Login Database Error: " . $e->getMessage());
            $error = is_rtl() ? "حدث خطأ في النظام، يرجى المحاولة لاحقاً." : "System error, please try again.";
        }
    } else {
        $error = is_rtl() ? "يرجى ملء جميع الحقول المطلوبة." : "Please fill in all fields.";
    }
}

// Fetch school branding
$school_name = !empty($global_branding['school_name']) ? $global_branding['school_name'] : 'EduMaster Core';
$inst_logo = get_school_logo_url();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('login') ?> - <?= htmlspecialchars($school_name) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* ====================================================
           Login Page - Perfectly Fitted Responsive Layout
           Fits comfortably on all viewports without scrolling,
           while gracefully scrolling on smaller/zoomed screens.
           ==================================================== */
        *, *::before, *::after { box-sizing: border-box; }

        html, body {
            min-height: 100%;
            min-height: 100svh;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            overflow-y: auto;
        }
        body {
            background: linear-gradient(135deg, #0F172A 0%, #0F3847 45%, #082F49 100%);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            min-height: 100svh;
            color: #F8FAFC;
            padding: 1rem 0.75rem;
            position: relative;
            box-sizing: border-box;
        }

        /* Sleek modern scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.7);
        }
        ::-webkit-scrollbar-thumb {
            background: rgba(13, 192, 231, 0.35);
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(13, 192, 231, 0.65);
        }

        .login-ambient-overlay {
            position: fixed;
            inset: 0;
            background: radial-gradient(circle at 50% 20%, rgba(0, 122, 153, 0.25) 0%, transparent 60%);
            pointer-events: none;
            z-index: 0;
        }
        .login-card-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
        }
        .auth-card {
            background-color: #FFFFFF;
            color: #0F172A;
            border-radius: 1rem;
            box-shadow: 0 16px 36px rgba(0, 0, 0, 0.35);
            padding: 1.25rem 1.5rem !important;
            transition: background-color 0.25s ease, color 0.25s ease, border-color 0.25s ease;
        }
        @media (min-width: 576px) {
            .auth-card {
                padding: 1.5rem 1.75rem !important;
            }
        }
        [data-theme="dark"] .auth-card, html.dark .auth-card {
            background-color: #1E293B !important;
            color: #F8FAFC !important;
            border: 1px solid rgba(255, 255, 255, 0.12) !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.65) !important;
        }
        [data-theme="dark"] .auth-card .form-control, html.dark .auth-card .form-control {
            background-color: #0F172A;
            color: #F8FAFC;
            border-color: #334155;
        }
        [data-theme="dark"] .auth-card .input-group-text, html.dark .auth-card .input-group-text {
            background-color: #1E293B;
            color: #94A3B8;
            border-color: #334155;
        }
        [data-theme="dark"] .auth-card .text-foreground, html.dark .auth-card .text-foreground {
            color: #F8FAFC !important;
        }
        [data-theme="dark"] .auth-card .text-muted-foreground, html.dark .auth-card .text-muted-foreground {
            color: #94A3B8 !important;
        }
        [data-theme="dark"] .auth-card .border-border, html.dark .auth-card .border-border {
            border-color: #334155 !important;
        }

        .auth-card .form-control {
            font-size: 0.88rem;
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
        }
        .auth-card .input-group-text {
            padding: 0.5rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.9rem;
        }
        .auth-card .form-label {
            font-size: 0.8rem;
            margin-bottom: 0.25rem;
        }
        .auth-card .btn-primary {
            padding: 0.55rem 1rem;
            font-size: 0.92rem;
            border-radius: 0.5rem;
        }

        /* Compact, space-fitted App Download Banner specifically for the login container */
        .login-app-banner .app-download-card {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            justify-content: space-between !important;
            background: rgba(15, 23, 42, 0.75) !important;
            backdrop-filter: blur(10px) !important;
            color: #eefdff !important;
            padding: 0.55rem 0.85rem !important;
            border-radius: 0.75rem !important;
            border: 1px solid rgba(13, 192, 231, 0.25) !important;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.25) !important;
            margin: 0.5rem 0 0 0 !important;
            gap: 0.65rem !important;
        }
        .login-app-banner .download-meta {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            gap: 0.6rem !important;
        }
        .login-app-banner .app-icon-wrap {
            padding: 3px !important;
            border-radius: 8px !important;
            background: rgba(255, 255, 255, 0.08) !important;
            border: 1px solid rgba(13, 192, 231, 0.2) !important;
        }
        .login-app-banner .app-icon-wrap img {
            width: 30px !important;
            height: 30px !important;
            border-radius: 6px !important;
        }
        .login-app-banner .download-text h3 {
            font-size: 0.82rem !important;
            font-weight: 700 !important;
            margin: 0 !important;
            color: #bef6fd !important;
            line-height: 1.2 !important;
        }
        .login-app-banner .download-text p,
        .login-app-banner .download-text div,
        .login-app-banner .install-hint {
            display: none !important;
        }
        .login-app-banner .download-action {
            display: flex !important;
            flex-direction: row !important;
            align-items: center !important;
            margin: 0 !important;
            gap: 0 !important;
        }
        .login-app-banner .btn-download-apk {
            padding: 0.38rem 0.7rem !important;
            font-size: 0.78rem !important;
            border-radius: 0.5rem !important;
            font-weight: 700 !important;
            white-space: nowrap !important;
            box-shadow: 0 2px 8px rgba(13, 192, 231, 0.3) !important;
        }
        .login-app-banner .btn-download-apk svg {
            width: 15px !important;
            height: 15px !important;
        }

        .login-footer {
            width: 100%;
            max-width: 420px;
            margin-top: 0.55rem !important;
        }
        .login-footer .text-white-50 {
            font-size: 0.72rem;
        }

        /* Short screen handling */
        @media (max-height: 620px) {
            body {
                justify-content: flex-start;
                padding-top: 0.75rem;
                padding-bottom: 0.75rem;
            }
            .auth-card {
                padding: 1rem 1.25rem !important;
            }
            .branding-subtitle {
                display: none !important;
            }
        }
    </style>
</head>
<body>

    <div class="login-ambient-overlay"></div>

    <!-- Main Authentication Container -->
    <main class="login-card-wrapper">
        <div class="card border-0 auth-card">
            <!-- Institute Branding: Logo & Name Inside Form -->
            <div class="text-center mb-3">
                <div class="mb-1.5 d-flex justify-content-center">
                    <?= render_theme_logo_html('max-height: 46px; max-width: 190px; width: auto; object-fit: contain;', 'rounded-2 shadow-xs') ?>
                </div>
                <h3 class="fw-bold font-heading text-foreground mb-0.5" style="font-size: 1.15rem;"><?= htmlspecialchars($school_name) ?></h3>
                <p class="text-muted-foreground branding-subtitle mb-0" style="font-size: 0.75rem;"><?= is_rtl() ? 'منصة الإدارة المؤسسية والتعليمية الموحدة' : 'Unified Institutional Management &amp; Learning Platform' ?></p>
            </div>

            <?php if ($timeoutNotice): ?>
                <div class="alert alert-warning alert-dismissible fade show d-flex align-items-center mb-2.5 py-2 px-3 border border-warning" role="alert">
                    <i class="fa-solid fa-clock-rotate-left me-2 fs-5 text-warning flex-shrink-0"></i>
                    <div class="small fw-medium" style="font-size: 0.8rem;">
                        <?= is_rtl() 
                            ? 'تم تسجيل خروجك تلقائياً لعدم وجود أي نشاط لمدة 5 دقائق. يرجى تسجيل الدخول مجدداً لمتابعة العمل بأمان.' 
                            : 'You have been automatically logged out due to 5 minutes of inactivity. Please sign in again to continue securely.' ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($isLocked && $lockoutSecondsRemaining > 0): ?>
                <div class="alert alert-danger border border-danger-subtle d-flex align-items-center mb-2.5 py-2 px-3" id="lockoutAlertBox" role="alert">
                    <i class="fa-solid fa-shield-halved me-2 fs-5 text-danger flex-shrink-0"></i>
                    <div class="small fw-medium w-100" style="font-size: 0.8rem;">
                        <div><?= htmlspecialchars($error) ?></div>
                        <div class="mt-1 d-flex align-items-center gap-2">
                            <span class="text-danger fw-bold"><i class="fa-regular fa-hourglass-half me-1"></i><?= is_rtl() ? 'الوقت المتبقي لفك الحظر:' : 'Time until retry allowed:' ?></span>
                            <span id="lockoutCountdownBadge" class="badge bg-danger text-white px-2 py-0.5 font-monospace fs-6 shadow-xs">--:--</span>
                        </div>
                    </div>
                </div>
            <?php elseif (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-2.5 py-2 px-3" role="alert">
                    <i class="fa-solid fa-circle-exclamation me-2 fs-5 text-destructive flex-shrink-0"></i>
                    <div class="small fw-medium" style="font-size: 0.8rem;"><?= htmlspecialchars($error) ?></div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCsrfToken()) ?>">
                <div class="mb-2.5">
                    <label class="form-label fw-semibold small text-foreground"><?= is_rtl() ? 'البريد الإلكتروني / اسم المستخدم' : 'Institutional Email / Username' ?></label>
                    <div class="input-group">
                        <span class="input-group-text bg-secondary border-border text-muted-foreground"><i class="fa-regular fa-envelope"></i></span>
                        <input type="text" name="identifier" class="form-control" placeholder="<?= is_rtl() ? 'admin@school.edu' : 'admin@edumaster.edu' ?>" value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>" required autofocus>
                    </div>
                </div>

                <div class="mb-2.5">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="form-label fw-semibold small text-foreground mb-0"><?= __('password') ?></label>
                        <a href="#" class="small text-primary text-decoration-none fw-medium" style="font-size: 0.78rem;" onclick="alert('<?= is_rtl() ? 'يرجى التواصل مع مسؤول النظام لإعادة ضبط كلمة المرور.' : 'Please contact the system administrator to reset your password.' ?>'); return false;"><?= is_rtl() ? 'نسيت كلمة المرور؟' : 'Forgot password?' ?></a>
                    </div>
                    <div class="input-group">
                        <span class="input-group-text bg-secondary border-border text-muted-foreground"><i class="fa-solid fa-lock"></i></span>
                        <input type="password" id="passwordInput" name="password" class="form-control" placeholder="••••••••" required>
                        <button type="button" class="btn btn-outline-secondary border-border text-muted-foreground" onclick="togglePasswordVisibility()">
                            <i class="fa-regular fa-eye" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="rememberMe" checked>
                    <label class="form-check-label small text-muted-foreground" for="rememberMe" style="font-size: 0.8rem;">
                        <?= is_rtl() ? 'تذكر هذا الجهاز لمدة 30 يوماً' : 'Remember this device for 30 days' ?>
                    </label>
                </div>

                <button type="submit" id="loginSubmitBtn" <?= $isLocked ? 'disabled' : '' ?> class="btn <?= $isLocked ? 'btn-secondary' : 'btn-primary' ?> w-100 py-2 rounded-lg fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                    <span><?= is_rtl() ? 'الدخول إلى البوابة' : 'Sign In to Portal' ?></span>
                    <i class="fa-solid fa-arrow-right rtl-flip"></i>
                </button>
            </form>

            <!-- Card Bottom Utilities: SSL Security, Theme Toggle & Language Switcher -->
            <div class="d-flex justify-content-between align-items-center mt-3 pt-2.5 border-top border-border">
                <span class="small text-muted-foreground d-inline-flex align-items-center gap-1.5" style="font-size: 0.75rem;">
                    <i class="fa-solid fa-shield-halved text-primary"></i>
                    <?= is_rtl() ? 'اتصال مشفر 256-bit' : '256-bit Encrypted' ?>
                </span>
                <div class="d-flex align-items-center gap-1.5">
                    <?= render_theme_switcher('btn-outline-secondary') ?>
                    <a href="<?= htmlspecialchars(get_lang_switch_url(is_rtl() ? 'en' : 'ar')) ?>" class="btn btn-sm btn-outline-secondary rounded-lg px-2 py-1 text-decoration-none d-inline-flex align-items-center gap-1 shadow-xs" title="<?= is_rtl() ? 'Switch to English' : 'التحويل إلى العربية' ?>" style="height: 2rem;">
                        <i class="fa-solid fa-globe text-primary small"></i>
                        <span class="small font-medium" style="font-size: 0.78rem;"><?= is_rtl() ? 'English 🇬🇧' : 'العربية 🇸🇦' ?></span>
                    </a>
                </div>
            </div>
        </div>
    </main>

    <?php
    if (!is_android_app()) {
        $schoolName = $school_name;
        echo '<div class="login-card-wrapper login-app-banner w-100" style="max-width:420px;">';
        include __DIR__ . '/components/app_download_banner.php';
        echo '</div>';
    }
    ?>

    <!-- Bottom Status & Compliance Footer -->
    <footer class="login-footer" style="width:100%;max-width:420px;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 text-white-50 small">
            <div class="d-flex align-items-center gap-2">
                <a href="docs.php" target="_blank" class="text-white-50 text-decoration-none hover-underline"><i class="fa-regular fa-circle-question me-1"></i><?= is_rtl() ? 'دليل المنصة' : 'Docs' ?></a>
                <span>·</span>
                <a href="privacy.php" target="_blank" class="text-white-50 text-decoration-none hover-underline"><i class="fa-solid fa-user-shield me-1"></i><?= is_rtl() ? 'الخصوصية' : 'Privacy' ?></a>
                <span>·</span>
                <span>&copy; <?= date('Y') ?> PRISM EDU</span>
            </div>
            <div class="d-inline-flex align-items-center gap-1.5">
                <span class="badge py-1 px-2" style="background: rgba(16, 185, 129, 0.15); color: #34D399; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 0.7rem;">
                    <i class="fa-solid fa-circle fa-2xs me-1"></i> <?= is_rtl() ? 'متصل' : 'Online' ?>
                </span>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/theme_toggle.js"></script>
    <script>
        function togglePasswordVisibility() {
            const pwd = document.getElementById('passwordInput');
            const icon = document.getElementById('eyeIcon');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                pwd.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // Live Lockout Countdown Timer
        (function() {
            let remainingSecs = <?= (int)$lockoutSecondsRemaining ?>;
            if (remainingSecs <= 0) return;

            const badge = document.getElementById('lockoutCountdownBadge');
            const submitBtn = document.getElementById('loginSubmitBtn');
            const alertBox = document.getElementById('lockoutAlertBox');

            function tick() {
                if (remainingSecs <= 0) {
                    if (badge) badge.textContent = '00:00';
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.classList.remove('btn-secondary');
                        submitBtn.classList.add('btn-primary');
                    }
                    if (alertBox) {
                        alertBox.classList.remove('alert-danger', 'border-danger-subtle');
                        alertBox.classList.add('alert-success', 'border-success-subtle');
                        alertBox.innerHTML = '<i class="fa-solid fa-circle-check me-2 fs-4 text-success flex-shrink-0"></i><div class="small fw-medium"><?= is_rtl() ? "انتهت فترة الحظر المؤقت (5 دقائق). يمكنك الآن إعادة محاولة تسجيل الدخول." : "Lockout period expired. You may now retry signing in." ?></div>';
                    }
                    return;
                }

                const mins = Math.floor(remainingSecs / 60);
                const secs = remainingSecs % 60;
                const formatted = String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
                if (badge) badge.textContent = formatted;

                remainingSecs--;
                setTimeout(tick, 1000);
            }

            tick();
        })();
    </script>
</body>
</html>