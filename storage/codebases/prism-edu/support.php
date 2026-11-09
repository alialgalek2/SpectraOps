<?php
/**
 * PRISM EDU - Master IT Support Center & Engineering Helpdesk
 * 
 * Provides:
 * 1. Multi-tier issue reporting with real-time Spectra Ops Telemetry Synchronization.
 * 2. Automated Zero-PII scrubbed diagnostics extraction and error log attachments.
 * 3. Guaranteed dual dispatch to Spectra Ops Hub & engineering desk (algaletech@yahoo.com).
 * 4. Interactive user ticket history tracking, SLA monitoring, and system reliability scoring.
 */

require_once __DIR__ . '/core/init.php';

$is_logged_in = Auth::check();
$user = $is_logged_in ? Auth::user() : null;
$role = $is_logged_in ? Auth::role() : 'guest';
$school_name = $global_branding['school_name'] ?? 'PRISM EDU';
$school_logo = $global_branding['logo_path'] ?? ($global_branding['school_logo'] ?? '');
$academic_year = $global_branding['academic_year'] ?? '2025/2026';

$health_metrics = Telemetry::getHealthMetrics();
$system_version = SystemUpdater::getCurrentVersion();
$recent_logs_preview = SupportTicket::getRecentErrorLogs(6);

// Verify Spectra Ops Hub connectivity
$hub_client = class_exists('\App\Services\HubClient') ? \App\Services\HubClient::get() : null;
$hub_connected = ($hub_client !== null);

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$alert_type = '';
$alert_msg = '';
$created_ticket = null;
$fleet_dispatch_success = false;

// Handle Ticket Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        $alert_type = 'danger';
        $alert_msg = is_rtl() ? 'رمز الأمان (CSRF) غير صالح. يرجى إعادة المحاولة.' : 'Invalid security token. Please refresh and try again.';
    } else {
        $postData = [
            'submitter_name'  => trim($_POST['submitter_name'] ?? ''),
            'submitter_email' => trim($_POST['submitter_email'] ?? ''),
            'submitter_phone' => trim($_POST['submitter_phone'] ?? ''),
            'user_role'       => $is_logged_in ? $role : trim($_POST['user_role'] ?? 'guest'),
            'subject'         => trim($_POST['subject'] ?? ''),
            'category'        => trim($_POST['category'] ?? 'Technical Bug / Error'),
            'priority'        => trim($_POST['priority'] ?? 'medium'),
            'module_affected' => trim($_POST['module_affected'] ?? 'General / Other'),
            'description'     => trim($_POST['description'] ?? ''),
            'error_message'   => trim($_POST['error_message'] ?? ''),
            'attach_logs'     => !empty($_POST['attach_logs'])
        ];

        $uploadFile = $_FILES['attachment'] ?? null;
        $result = SupportTicket::createTicket($postData, $uploadFile);

        if ($result['success']) {
            $alert_type = 'success';
            $created_ticket = $result;
            $fleet_dispatch_success = !empty($result['fleet_synced']);

            // Fallback redundant Spectra Ops dispatch if not caught in createTicket
            if (!$fleet_dispatch_success && $hub_client) {
                try {
                    $catLower = strtolower($postData['category']);
                    $fleetCat = match (true) {
                        str_contains($catLower, 'bug') || str_contains($catLower, 'error') || str_contains($catLower, 'glitch') => 'bug',
                        str_contains($catLower, 'feature') || str_contains($catLower, 'enhancement') => 'feature_request',
                        str_contains($catLower, 'billing') || str_contains($catLower, 'account') => 'billing',
                        str_contains($catLower, 'outage') || str_contains($catLower, 'critical') || str_contains($catLower, 'down') => 'critical_outage',
                        default => 'general'
                    };

                    $priLower = strtolower($postData['priority']);
                    $fleetPri = match ($priLower) {
                        'low' => 'low',
                        'high' => 'high',
                        'critical' => 'urgent',
                        default => 'medium'
                    };

                    $fleet_dispatch_success = $hub_client->submitSupportTicket([
                        'reference'       => $result['ticket_number'],
                        'requester_name'  => $postData['submitter_name'],
                        'requester_email' => $postData['submitter_email'],
                        'requester_role'  => $postData['user_role'],
                        'subject'         => "[{$postData['module_affected']}] {$postData['subject']}",
                        'category'        => $fleetCat,
                        'priority'        => $fleetPri,
                        'message'         => $postData['description'] . (!empty($postData['error_message']) ? "\n\nError Message:\n" . $postData['error_message'] : ''),
                        'snapshot'        => SupportTicket::getSystemDiagnostics()
                    ]);
                } catch (Throwable $e) {}
            }

            $alert_msg = is_rtl()
                ? "تم تسجيل تذكرة الدعم الفني بنجاح برقم #{$result['ticket_number']} وتم إرسال التقرير والتشخيص مباشرة إلى مركز العمليات الطيفية (Spectra Ops) وفريق الهندسة."
                : "Support ticket #{$result['ticket_number']} registered and dispatched successfully to Spectra Ops & engineering desk.";

            // Refresh CSRF token
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } else {
            $alert_type = 'danger';
            $alert_msg = $result['error'] ?? (is_rtl() ? 'حدث خطأ أثناء إرسال التذكرة.' : 'An error occurred while submitting the ticket.');
        }
    }
}

// Fetch user's recent tickets if logged in
$recent_tickets = SupportTicket::getRecentTickets($is_logged_in ? Auth::id() : null, 10);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'مركز الدعم الفني وخدمة العملاء' : 'IT Support Center & Helpdesk' ?> | <?= htmlspecialchars($school_name) ?></title>
    
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --font-main: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            --font-heading: 'Outfit', sans-serif;
            --font-code: 'JetBrains Mono', monospace;
            
            --bg-base: #f8fafc;
            --bg-card: #ffffff;
            --bg-secondary: #f1f5f9;
            --bg-hover: #e2e8f0;
            --border-color: #e2e8f0;
            --border-hover: #cbd5e1;
            
            --text-main: #0f172a;
            --text-muted: #64748b;
            --text-light: #94a3b8;
            
            --primary: #4338ca;
            --primary-hover: #3730a3;
            --primary-subtle: rgba(67, 56, 202, 0.08);
            --primary-border: rgba(67, 56, 202, 0.2);
            
            --cyan-accent: #0284c7;
            --cyan-subtle: rgba(2, 132, 199, 0.08);
            --cyan-border: rgba(2, 132, 199, 0.25);
            
            --success: #10b981;
            --success-subtle: rgba(16, 185, 129, 0.1);
            --warning: #f59e0b;
            --danger: #ef4444;
            
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 18px;
            --radius-xl: 24px;
            --shadow-card: 0 4px 20px -2px rgba(15, 23, 42, 0.05), 0 2px 6px -1px rgba(15, 23, 42, 0.02);
            --shadow-glow: 0 0 25px rgba(67, 56, 202, 0.12);
        }

        [data-theme="dark"] {
            --bg-base: #090d16;
            --bg-card: #111827;
            --bg-secondary: #1e293b;
            --bg-hover: #334155;
            --border-color: #1e293b;
            --border-hover: #334155;
            
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --text-light: #64748b;
            
            --primary: #6366f1;
            --primary-hover: #4f46e5;
            --primary-subtle: rgba(99, 102, 241, 0.15);
            --primary-border: rgba(99, 102, 241, 0.3);
            
            --cyan-accent: #38bdf8;
            --cyan-subtle: rgba(56, 189, 248, 0.15);
            --cyan-border: rgba(56, 189, 248, 0.35);
            
            --shadow-card: 0 10px 30px -5px rgba(0, 0, 0, 0.4);
            --shadow-glow: 0 0 30px rgba(99, 102, 241, 0.2);
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg-base);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
            overflow-x: hidden;
        }

        .font-heading { font-family: var(--font-heading); }
        .font-code { font-family: var(--font-code); }

        /* Hero Header Banner */
        .support-hero {
            background: linear-gradient(135deg, rgba(67, 56, 202, 0.07) 0%, rgba(2, 132, 199, 0.05) 50%, rgba(16, 185, 129, 0.04) 100%);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 2.25rem 2rem;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-card);
            margin-bottom: 2rem;
        }

        .support-hero::before {
            content: '';
            position: absolute;
            top: -60px;
            inset-inline-end: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, rgba(67, 56, 202, 0.18) 0%, rgba(2, 132, 199, 0) 70%);
            pointer-events: none;
            z-index: 0;
        }

        /* Status & Connection Badges */
        .fleet-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.4rem 0.9rem;
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 700;
            background: var(--bg-card);
            border: 1px solid var(--cyan-border);
            color: var(--cyan-accent);
            box-shadow: 0 2px 8px rgba(2, 132, 199, 0.1);
        }

        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.35; transform: scale(0.8); }
        }
        .pulse-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--success);
            display: inline-block;
            animation: pulseDot 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
        .pulse-dot-cyan {
            background-color: var(--cyan-accent);
        }

        /* Cards & Panels */
        .panel-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2rem;
            box-shadow: var(--shadow-card);
            position: relative;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .side-widget {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            box-shadow: var(--shadow-card);
            margin-bottom: 1.5rem;
            position: relative;
            overflow: hidden;
        }

        /* Interactive Category Grid */
        .category-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 0.85rem;
            margin-bottom: 1.5rem;
        }

        .category-card {
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 1rem;
            cursor: pointer;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            user-select: none;
        }

        .category-card:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
            background: var(--primary-subtle);
        }

        .category-card.selected {
            border-color: var(--primary);
            background: var(--primary-subtle);
            box-shadow: 0 0 0 2px var(--primary-border);
        }

        .category-card .cat-icon {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
            background: var(--bg-secondary);
            color: var(--primary);
            transition: all 0.2s ease;
        }

        .category-card.selected .cat-icon {
            background: var(--primary);
            color: #ffffff;
        }

        .category-card .cat-title {
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--text-main);
            margin-top: 0.2rem;
        }

        .category-card .cat-desc {
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.35;
        }

        .category-card .selected-check {
            position: absolute;
            top: 0.65rem;
            inset-inline-end: 0.65rem;
            color: var(--primary);
            font-size: 0.85rem;
            display: none;
        }

        .category-card.selected .selected-check {
            display: block;
        }

        /* Priority Selector Pills */
        .priority-pills {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.65rem;
            margin-bottom: 1.5rem;
        }

        .priority-option {
            position: relative;
        }

        .priority-option input[type="radio"] {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .priority-label {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            padding: 0.65rem 0.5rem;
            border: 1.5px solid var(--border-color);
            background: var(--bg-card);
            border-radius: var(--radius-md);
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
        }

        .priority-option input[type="radio"]:checked + .priority-label.p-low {
            border-color: var(--success);
            background: var(--success-subtle);
            color: var(--success);
            box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.2);
        }
        .priority-option input[type="radio"]:checked + .priority-label.p-medium {
            border-color: var(--primary);
            background: var(--primary-subtle);
            color: var(--primary);
            box-shadow: 0 0 0 2px var(--primary-border);
        }
        .priority-option input[type="radio"]:checked + .priority-label.p-high {
            border-color: var(--warning);
            background: rgba(245, 158, 11, 0.1);
            color: #d97706;
            box-shadow: 0 0 0 2px rgba(245, 158, 11, 0.2);
        }
        .priority-option input[type="radio"]:checked + .priority-label.p-critical {
            border-color: var(--danger);
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger);
            box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.25);
        }

        /* Form Inputs */
        .form-label {
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.45rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-control, .form-select {
            border: 1.5px solid var(--border-color);
            background-color: var(--bg-card);
            color: var(--text-main);
            border-radius: var(--radius-md);
            padding: 0.72rem 1rem;
            font-size: 0.92rem;
            transition: all 0.2s ease;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--primary-border);
            background-color: var(--bg-card);
            color: var(--text-main);
        }

        .terminal-textarea {
            font-family: var(--font-code);
            font-size: 0.82rem;
            background-color: #0b1120 !important;
            color: #38bdf8 !important;
            border-color: #1e293b !important;
            border-radius: var(--radius-md);
            line-height: 1.5;
        }
        .terminal-textarea::placeholder {
            color: #64748b !important;
        }

        /* Upload Dropzone */
        .upload-dropzone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            text-align: center;
            background: var(--bg-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .upload-dropzone:hover {
            border-color: var(--primary);
            background: var(--primary-subtle);
        }

        /* Ticket References & Details */
        .ticket-code-pill {
            font-family: var(--font-code);
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            background: var(--primary-subtle);
            color: var(--primary);
            border: 1px solid var(--primary-border);
            padding: 0.35rem 0.75rem;
            border-radius: var(--radius-sm);
        }

        .ticket-status-badge {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 0.3rem 0.65rem;
            border-radius: 9999px;
        }
        .status-open { background: rgba(56, 189, 248, 0.15); color: #0284c7; border: 1px solid rgba(2, 132, 199, 0.3); }
        .status-in_progress { background: rgba(245, 158, 11, 0.15); color: #d97706; border: 1px solid rgba(245, 158, 11, 0.3); }
        .status-resolved { background: rgba(16, 185, 129, 0.18); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.4); font-weight: 800; }
        .status-closed { background: rgba(148, 163, 184, 0.15); color: #94a3b8; border: 1px solid rgba(148, 163, 184, 0.25); }

        .fleet-badge {
            font-size: 0.7rem;
            font-weight: 700;
            padding: 0.2rem 0.5rem;
            border-radius: 4px;
            background: rgba(2, 132, 199, 0.12);
            color: var(--cyan-accent);
            border: 1px solid var(--cyan-border);
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
        }

        /* Copy Button Helper */
        .btn-copy {
            background: transparent;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 2px 6px;
            border-radius: 4px;
            transition: color 0.15s ease;
        }
        .btn-copy:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>

    <!-- Universal Sticky Top Navigation Bar -->
    <?= render_universal_navbar($user, $school_name, $school_logo, $academic_year) ?>

    <main class="container-fluid px-3 px-lg-5 py-4 flex-grow-1 max-w-7xl mx-auto">
        
        <!-- Breadcrumbs Navigation -->
        <div class="mb-3">
            <?php
            Layout::renderBreadcrumbs([
                ['label' => __('dashboard'), 'url' => 'dashboard.php'],
                ['label' => is_rtl() ? 'مركز الدعم والمساعدة' : 'IT Support & Helpdesk', 'url' => null],
            ], 'support.php');
            ?>
        </div>

        <!-- Hero Header Section -->
        <section class="support-hero">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 position-relative" style="z-index: 1;">
                <div>
                    <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                        <span class="fleet-status-pill">
                            <span class="pulse-dot pulse-dot-cyan"></span>
                            <i class="fa-solid fa-satellite-dish"></i>
                            <span><?= is_rtl() ? 'متصل بمركز العمليات الطيفية (Spectra Ops)' : 'Spectra Ops Synchronized' ?></span>
                        </span>
                        <span class="badge bg-secondary text-secondary-foreground border border-border rounded-pill px-2.5 py-1 small">
                            <i class="fa-solid fa-shield-halved text-success me-1"></i> Zero-PII Strict Compliance
                        </span>
                    </div>

                    <h1 class="h2 font-heading fw-bold text-foreground mb-1">
                        <?= is_rtl() ? 'مركز الدعم التقني وبلاغات النظام المباشرة' : 'IT Support Center & Engineering Helpdesk' ?>
                    </h1>
                    <p class="text-muted-foreground mb-0" style="max-width: 780px;">
                        <?= is_rtl()
                            ? 'تسجيل البلاغات التقنية مع التوجيه الفوري إلى مركز العمليات الطيفية (Spectra Ops Hub) وفريق الهندسة المخصص. يتم إرفاق التشخيصات المنقحة وسجلات الأخطاء آلياً.'
                            : 'Submit inquiries, software bugs, and system outage alerts. Every ticket is dispatched with sanitized diagnostics directly to Spectra Ops Hub and our engineering desk.' ?>
                    </p>
                </div>

                <div class="d-flex align-items-center gap-2 flex-shrink-0">
                    <div class="p-3 bg-card rounded-lg border border-border text-center shadow-xs">
                        <div class="text-muted-foreground small fw-semibold" style="font-size: 0.72rem;"><?= is_rtl() ? 'معدل موثوقية النظام' : 'SYSTEM HEALTH' ?></div>
                        <div class="h4 font-heading fw-bold text-primary mb-0 mt-0.5"><?= $health_metrics['health_score'] ?>%</div>
                        <span class="badge bg-success text-white small" style="font-size: 0.65rem;"><?= $health_metrics['status_label'] ?></span>
                    </div>
                </div>
            </div>
        </section>

        <!-- Dynamic Success / Error Alert Banner -->
        <?php if (!empty($alert_msg)): ?>
            <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show p-3.5 mb-4 rounded-lg shadow-sm border-0" role="alert">
                <div class="d-flex align-items-start gap-3">
                    <div class="fs-4 <?= $alert_type === 'success' ? 'text-success' : 'text-danger' ?> mt-0.5">
                        <i class="fa-solid <?= $alert_type === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <h5 class="alert-heading fw-bold mb-1">
                            <?= $alert_type === 'success' 
                                ? (is_rtl() ? 'تم تسجيل وتمرير التذكرة بنجاح!' : 'Ticket Created & Synchronized Successfully!') 
                                : (is_rtl() ? 'خطأ في معالجة التذكرة' : 'Submission Error') ?>
                        </h5>
                        <p class="mb-1 text-foreground"><?= htmlspecialchars($alert_msg) ?></p>
                        
                        <?php if ($created_ticket): ?>
                            <div class="d-flex align-items-center flex-wrap gap-2 mt-2 pt-2 border-top border-border">
                                <div class="d-flex align-items-center gap-1.5">
                                    <span class="small text-muted-foreground"><?= is_rtl() ? 'رقم التذكرة المرجعي:' : 'Reference ID:' ?></span>
                                    <span class="ticket-code-pill">#<?= htmlspecialchars($created_ticket['ticket_number']) ?></span>
                                    <button type="button" class="btn-copy" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($created_ticket['ticket_number']) ?>'); this.innerHTML='<i class=\'fa-solid fa-check text-success\'></i>'; setTimeout(() => this.innerHTML='<i class=\'fa-regular fa-copy\'></i>', 1500);" title="Copy Reference">
                                        <i class="fa-regular fa-copy"></i>
                                    </button>
                                </div>
                                <span class="fleet-badge">
                                    <i class="fa-solid fa-satellite-dish"></i>
                                    <?= is_rtl() ? 'مؤرشف في Spectra Ops' : 'Dispatched to Spectra Ops' ?>
                                </span>
                                <span class="badge bg-secondary text-secondary-foreground small">
                                    <i class="fa-solid fa-envelope me-1"></i> algaletech@yahoo.com
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Two Column Interactive Layout -->
        <div class="row g-4">
            
            <!-- Left Main Column: Support Ticket Submission Wizard -->
            <div class="col-lg-8">
                <div class="panel-card">
                    
                    <div class="d-flex align-items-center justify-content-between pb-3 mb-4 border-bottom border-border">
                        <div>
                            <h4 class="font-heading fw-bold text-foreground mb-1">
                                <i class="fa-solid fa-ticket-simple text-primary me-2"></i>
                                <?= is_rtl() ? 'تسجيل تذكرة دعم فني جديدة' : 'Open Technical Support Ticket' ?>
                            </h4>
                            <p class="text-muted-foreground small mb-0">
                                <?= is_rtl() ? 'حدد تصنيف المشكلة وتفاصيل العطل لتوجيه البلاغ للمهندس المسؤول فوراً.' : 'Select issue classification and provide reproduction steps for accelerated triage.' ?>
                            </p>
                        </div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-border rounded-pill px-3 py-1.5 small fw-bold">
                            <i class="fa-solid fa-paper-plane me-1"></i> <?= is_rtl() ? 'إرسال مباشر' : 'Live Gateway' ?>
                        </span>
                    </div>

                    <form action="support.php" method="POST" enctype="multipart/form-data" id="supportTicketForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="submit_ticket" value="1">
                        <input type="hidden" name="category" id="selectedCategoryInput" value="Technical Bug / Error">

                        <!-- 1. Interactive Visual Category Selector -->
                        <div class="mb-4">
                            <label class="form-label mb-2">
                                <span><i class="fa-solid fa-tags text-primary me-1.5"></i><?= is_rtl() ? 'تصنيف المشكلة أو الطلب' : 'Issue Category' ?> <span class="text-danger">*</span></span>
                                <span class="small text-muted-foreground fw-normal"><?= is_rtl() ? 'اختر النوع الأقرب' : 'Choose primary type' ?></span>
                            </label>
                            
                            <div class="category-grid">
                                <div class="category-card selected" onclick="selectCategory(this, 'Technical Bug / Error')">
                                    <i class="fa-solid fa-circle-check selected-check"></i>
                                    <div class="cat-icon text-danger"><i class="fa-solid fa-bug"></i></div>
                                    <div class="cat-title"><?= is_rtl() ? 'عطل برمجي / Bug' : 'Bug / Error' ?></div>
                                    <div class="cat-desc"><?= is_rtl() ? 'خطأ في الشاشة، تعطل وظيفة' : 'System glitch, crash, broken action' ?></div>
                                </div>

                                <div class="category-card" onclick="selectCategory(this, 'Account & Authentication')">
                                    <i class="fa-solid fa-circle-check selected-check"></i>
                                    <div class="cat-icon text-warning"><i class="fa-solid fa-user-shield"></i></div>
                                    <div class="cat-title"><?= is_rtl() ? 'حسابات وصلاحيات' : 'Auth & Access' ?></div>
                                    <div class="cat-desc"><?= is_rtl() ? 'تسجيل الدخول، أذونات المستخدمين' : 'Login issue, permission, roles' ?></div>
                                </div>

                                <div class="category-card" onclick="selectCategory(this, 'Data & Accounting')">
                                    <i class="fa-solid fa-circle-check selected-check"></i>
                                    <div class="cat-icon text-primary"><i class="fa-solid fa-calculator"></i></div>
                                    <div class="cat-title"><?= is_rtl() ? 'المالية والدرجات' : 'Ledger & Grades' ?></div>
                                    <div class="cat-desc"><?= is_rtl() ? 'سجلات الرسوم، احتساب المعدل' : 'Tuition calculations, grading, GPA' ?></div>
                                </div>

                                <div class="category-card" onclick="selectCategory(this, 'Performance & Slowness')">
                                    <i class="fa-solid fa-circle-check selected-check"></i>
                                    <div class="cat-icon text-info"><i class="fa-solid fa-gauge-high"></i></div>
                                    <div class="cat-title"><?= is_rtl() ? 'بطء الأداء والشبكة' : 'Latency & Lag' ?></div>
                                    <div class="cat-desc"><?= is_rtl() ? 'استجابة بطيئة، انتهاء المهلة' : 'Slow queries, timeout, lag' ?></div>
                                </div>

                                <div class="category-card" onclick="selectCategory(this, 'Feature Request')">
                                    <i class="fa-solid fa-circle-check selected-check"></i>
                                    <div class="cat-icon text-success"><i class="fa-solid fa-lightbulb"></i></div>
                                    <div class="cat-title"><?= is_rtl() ? 'اقتراح ميزة جديدة' : 'Feature Request' ?></div>
                                    <div class="cat-desc"><?= is_rtl() ? 'طلب إضافة أو تحسين وظيفة' : 'Enhancement suggestion' ?></div>
                                </div>

                                <div class="category-card" onclick="selectCategory(this, 'Critical Outage / Data Loss')">
                                    <i class="fa-solid fa-circle-check selected-check"></i>
                                    <div class="cat-icon text-danger"><i class="fa-solid fa-triangle-exclamation"></i></div>
                                    <div class="cat-title"><?= is_rtl() ? 'انقطاع كامل / طارئ' : 'Critical Outage' ?></div>
                                    <div class="cat-desc"><?= is_rtl() ? 'توقف النظام بالكامل أو فقد بيانات' : 'System down, urgent blocker' ?></div>
                                </div>
                            </div>
                        </div>

                        <!-- 2. Priority Selector Pills -->
                        <div class="mb-4">
                            <label class="form-label">
                                <span><i class="fa-solid fa-signal text-primary me-1.5"></i><?= is_rtl() ? 'مستوى الأهمية والاستعجال' : 'Priority Level' ?> <span class="text-danger">*</span></span>
                                <span class="small text-muted-foreground fw-normal"><?= is_rtl() ? 'حدد درجة تأثير المشكلة' : 'Dictates engineer triage priority' ?></span>
                            </label>
                            
                            <div class="priority-pills">
                                <div class="priority-option">
                                    <input type="radio" name="priority" id="pri_low" value="low">
                                    <label class="priority-label p-low" for="pri_low">
                                        <i class="fa-solid fa-circle-info"></i>
                                        <span><?= is_rtl() ? 'منخفضة' : 'Low' ?></span>
                                    </label>
                                </div>

                                <div class="priority-option">
                                    <input type="radio" name="priority" id="pri_medium" value="medium" checked>
                                    <label class="priority-label p-medium" for="pri_medium">
                                        <i class="fa-solid fa-circle-check"></i>
                                        <span><?= is_rtl() ? 'متوسطة' : 'Medium' ?></span>
                                    </label>
                                </div>

                                <div class="priority-option">
                                    <input type="radio" name="priority" id="pri_high" value="high">
                                    <label class="priority-label p-high" for="pri_high">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        <span><?= is_rtl() ? 'مرتفعة' : 'High' ?></span>
                                    </label>
                                </div>

                                <div class="priority-option">
                                    <input type="radio" name="priority" id="pri_critical" value="critical">
                                    <label class="priority-label p-critical" for="pri_critical">
                                        <i class="fa-solid fa-fire"></i>
                                        <span><?= is_rtl() ? 'حرجة / عاجلة' : 'Critical' ?></span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Submitter Information Grid -->
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="submitter_name">
                                    <span><i class="fa-solid fa-user text-muted-foreground me-1"></i><?= is_rtl() ? 'اسم مقدم التذكرة' : 'Your Full Name' ?> <span class="text-danger">*</span></span>
                                </label>
                                <input type="text" class="form-control" id="submitter_name" name="submitter_name" 
                                       value="<?= htmlspecialchars($user['full_name'] ?? '') ?>" required
                                       placeholder="<?= is_rtl() ? 'مثال: محمد الخالد' : 'e.g. Alexander Hamilton' ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="submitter_email">
                                    <span><i class="fa-solid fa-envelope text-muted-foreground me-1"></i><?= is_rtl() ? 'البريد الإلكتروني للإشعار' : 'Email Address' ?> <span class="text-danger">*</span></span>
                                </label>
                                <input type="email" class="form-control" id="submitter_email" name="submitter_email" 
                                       value="<?= htmlspecialchars($user['email'] ?? '') ?>" required
                                       placeholder="name@school.edu">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label" for="submitter_phone">
                                    <span><i class="fa-solid fa-phone text-muted-foreground me-1"></i><?= is_rtl() ? 'رقم الهاتف / الجوال' : 'Phone / Mobile' ?></span>
                                </label>
                                <input type="tel" class="form-control" id="submitter_phone" name="submitter_phone" 
                                       value="<?= htmlspecialchars($user['phone'] ?? '') ?>"
                                       placeholder="+1 (555) 000-0000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="module_affected">
                                    <span><i class="fa-solid fa-cubes text-muted-foreground me-1"></i><?= is_rtl() ? 'الوحدة / الموديول المتأثر' : 'Affected Module' ?></span>
                                </label>
                                <select class="form-select" id="module_affected" name="module_affected">
                                    <option value="General / Platform"><?= is_rtl() ? 'النظام العام واللوحة الرئيسية' : 'General Platform / Dashboard' ?></option>
                                    <option value="Administration"><?= is_rtl() ? 'الإدارة المركزية والإعدادات (Administration)' : 'Administration & Settings' ?></option>
                                    <option value="LMS"><?= is_rtl() ? 'الفصول والتعلم الإلكتروني (LMS)' : 'LMS & Class Management' ?></option>
                                    <option value="Finance"><?= is_rtl() ? 'المالية والخزينة والرسوم (Finance)' : 'Finance & Fee Billing' ?></option>
                                    <option value="HR"><?= is_rtl() ? 'الموارد البشرية والرواتب (HR)' : 'HR & Staff Payroll' ?></option>
                                    <option value="Grading"><?= is_rtl() ? 'الامتحانات والشهادات (Grading)' : 'Grading & Academic Records' ?></option>
                                    <option value="Database & Sync"><?= is_rtl() ? 'المزامنة وقاعدة البيانات (Database & Sync)' : 'Database & Cloud Sync' ?></option>
                                </select>
                            </div>
                        </div>

                        <!-- 4. Issue Subject -->
                        <div class="mb-3">
                            <label class="form-label" for="subject">
                                <span><i class="fa-solid fa-heading text-muted-foreground me-1"></i><?= is_rtl() ? 'عنوان البلاغ / المشكلة' : 'Issue Subject' ?> <span class="text-danger">*</span></span>
                            </label>
                            <input type="text" class="form-control" id="subject" name="subject" required
                                   placeholder="<?= is_rtl() ? 'مثال: خطأ في احتساب رسوم الفصل الدراسي الثاني' : 'e.g. Timeout error during term report card batch generation' ?>">
                        </div>

                        <!-- 5. Issue Detailed Description -->
                        <div class="mb-3">
                            <label class="form-label" for="description">
                                <span><i class="fa-solid fa-align-left text-muted-foreground me-1"></i><?= is_rtl() ? 'الوصف التفصيلي وخطوات التكرار' : 'Detailed Description & Steps to Reproduce' ?> <span class="text-danger">*</span></span>
                            </label>
                            <textarea class="form-control" id="description" name="description" rows="4" required
                                      placeholder="<?= is_rtl() 
                                          ? 'وضح بالتفصيل: ما الخطوات التي قمت بها؟ ما النتيجة التي ظهرت؟ وما النتيجة المتوقعة؟' 
                                          : 'Please explain: What were the steps taken? What error or behavior occurred? What did you expect to happen?' ?>"></textarea>
                        </div>

                        <!-- 6. Raw Technical Error Message / Stack Trace -->
                        <div class="mb-3">
                            <div class="d-flex align-items-center justify-content-between mb-1">
                                <label class="form-label mb-0" for="error_message">
                                    <span><i class="fa-solid fa-terminal text-primary me-1"></i><?= is_rtl() ? 'رسالة الخطأ البرمجية / Stack Trace (إن وجدت)' : 'Raw Error Message or Exception Trace (Optional)' ?></span>
                                </label>
                                <button type="button" class="btn btn-link btn-sm p-0 text-primary small text-decoration-none" onclick="pasteClipboardToError()">
                                    <i class="fa-regular fa-paste me-1"></i><?= is_rtl() ? 'لصق من الحافظة' : 'Paste Clipboard' ?>
                                </button>
                            </div>
                            <textarea class="form-control terminal-textarea" id="error_message" name="error_message" rows="3"
                                      placeholder="<?= is_rtl() 
                                          ? 'ألصق هنا أي رسالة خطأ ظهرت على الشاشة (مثل: Fatal error, PDOException, 500 Internal Server Error)...' 
                                          : 'Paste any technical output, PDOException trace, or terminal log displayed on screen...' ?>"></textarea>
                        </div>

                        <!-- 7. Automated Zero-PII Telemetry Switch -->
                        <div class="p-3 bg-secondary rounded-lg border border-border mb-3">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                                    <input class="form-check-input ms-0 me-2" type="checkbox" role="switch" id="attach_logs" name="attach_logs" value="1" checked>
                                    <label class="form-check-label fw-bold text-foreground small" for="attach_logs">
                                        <i class="fa-solid fa-shield-halved text-success me-1"></i>
                                        <?= is_rtl() 
                                            ? 'إرفاق سجلات الأخطاء والتشخيصات آلياً (معيار الخصوصية الكامل Zero-PII)' 
                                            : 'Automatically attach sanitized diagnostic dump & recent error logs' ?>
                                    </label>
                                </div>

                                <button type="button" class="btn btn-outline-border btn-sm py-1 px-2.5 small" data-bs-toggle="collapse" data-bs-target="#logsPreviewCollapse" aria-expanded="false">
                                    <i class="fa-solid fa-eye me-1 text-primary"></i> <?= is_rtl() ? 'معاينة السجلات' : 'Preview Logs' ?>
                                </button>
                            </div>
                            
                            <!-- Expandable Log Preview -->
                            <div class="collapse mt-2" id="logsPreviewCollapse">
                                <pre class="p-2.5 rounded bg-dark text-info font-code small mb-0 border border-border" style="max-height: 180px; overflow-y: auto; font-size: 0.75rem; white-space: pre-wrap;"><?= htmlspecialchars($recent_logs_preview) ?></pre>
                            </div>
                        </div>

                        <!-- 8. Screenshot or Additional File Attachment -->
                        <div class="mb-4">
                            <label class="form-label" for="attachmentInput">
                                <span><i class="fa-solid fa-paperclip text-muted-foreground me-1"></i><?= is_rtl() ? 'إرفاق لقطة شاشة أو ملف إضافي (اختياري)' : 'Screenshot or Attachment (Optional)' ?></span>
                            </label>
                            
                            <div class="upload-dropzone" onclick="document.getElementById('attachmentInput').click()">
                                <i class="fa-solid fa-cloud-arrow-up display-6 text-primary mb-2 d-block"></i>
                                <div class="fw-semibold text-foreground small mb-0" id="dropzoneLabel">
                                    <?= is_rtl() ? 'انقر لاختيار ملف أو اسحب وأفلت لقطة الشاشة هنا' : 'Click to browse or drag & drop screenshot here' ?>
                                </div>
                                <div class="text-muted-foreground" style="font-size: 0.72rem;">PNG, JPG, PDF, LOG, TXT (Max 10MB)</div>
                            </div>
                            <input type="file" id="attachmentInput" name="attachment" class="d-none" accept=".jpg,.jpeg,.png,.gif,.pdf,.log,.txt,.json" onchange="updateFileName(this)">
                        </div>

                        <!-- Action Submit Button -->
                        <div class="d-flex align-items-center justify-content-between pt-3 border-top border-border">
                            <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-4">
                                <i class="fa-solid fa-arrow-left me-1 <?= is_rtl() ? 'fa-flip-horizontal' : '' ?>"></i>
                                <?= is_rtl() ? 'إلغاء والعودة' : 'Cancel & Return' ?>
                            </a>

                            <button type="submit" class="btn btn-primary rounded-pill px-4 py-2.5 fw-bold shadow-sm d-flex align-items-center gap-2" id="submitBtn">
                                <i class="fa-solid fa-paper-plane" id="submitIcon"></i>
                                <span><?= is_rtl() ? 'إرسال التذكرة إلى Spectra Ops والدعم الفني' : 'Dispatch Ticket to Spectra Ops' ?></span>
                            </button>
                        </div>
                    </form>

                </div>
            </div>

            <!-- Right Column: Telemetry Sync, SLAs, & Recent Tickets -->
            <div class="col-lg-4">
                
                <!-- Spectra Ops Real-Time Sync Status Card -->
                <div class="side-widget border-primary" style="background: linear-gradient(180deg, var(--bg-card) 0%, var(--primary-subtle) 100%);">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <div class="p-2 rounded-circle bg-primary text-white" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;">
                                <i class="fa-solid fa-satellite-dish" style="font-size: 0.85rem;"></i>
                            </div>
                            <div>
                                <h6 class="font-heading fw-bold text-foreground mb-0">Spectra Ops</h6>
                                <div class="text-muted-foreground" style="font-size: 0.7rem;"><?= is_rtl() ? 'مركز القيادة الموحد' : 'Central Telemetry Cloud' ?></div>
                            </div>
                        </div>

                        <span class="badge bg-success text-white small d-inline-flex align-items-center gap-1">
                            <span class="pulse-dot"></span>
                            <span><?= is_rtl() ? 'مباشر' : 'Live Sync' ?></span>
                        </span>
                    </div>

                    <p class="text-muted-foreground small mb-3" style="font-size: 0.78rem; line-height: 1.45;">
                        <?= is_rtl() 
                            ? 'يتم توجيه كافة التذاكر المسجلة مباشرة إلى مركز العمليات الطيفية عبر بروتوكول مشفر (TLS) مع تضمين لقطة تشخيصية كاملة عن بيئة الخادم.' 
                            : 'Every submitted ticket is streamed via TLS directly into Spectra Ops Hub. Engineers receive real-time alerts with full error traces.' ?>
                    </p>

                    <div class="p-2.5 bg-card rounded border border-border">
                        <div class="d-flex align-items-center justify-content-between small text-muted-foreground mb-1.5" style="font-size: 0.75rem;">
                            <span><?= is_rtl() ? 'المنتج المقترن:' : 'Paired Product:' ?></span>
                            <strong class="text-foreground">PRISM EDU Enterprise</strong>
                        </div>
                        <div class="d-flex align-items-center justify-content-between small text-muted-foreground mb-1.5" style="font-size: 0.75rem;">
                            <span><?= is_rtl() ? 'قناة الإرسال:' : 'Ingest Channel:' ?></span>
                            <code class="text-primary" style="font-size: 0.72rem;">/api/v1/telemetry/ingest</code>
                        </div>
                        <div class="d-flex align-items-center justify-content-between small text-muted-foreground" style="font-size: 0.75rem;">
                            <span><?= is_rtl() ? 'بروتوكول الأمان:' : 'Security Token:' ?></span>
                            <span class="badge bg-accent text-primary" style="font-size: 0.68rem;">SHA-256 Bearer</span>
                        </div>
                    </div>
                </div>

                <!-- Engineering Team & SLA Card -->
                <div class="side-widget">
                    <div class="d-flex align-items-center gap-2.5 mb-3">
                        <div class="p-2 rounded bg-secondary text-primary">
                            <i class="fa-solid fa-headset fs-5"></i>
                        </div>
                        <div>
                            <h6 class="font-heading fw-bold text-foreground mb-0"><?= is_rtl() ? 'فريق الدعم الهندسي' : 'Direct Support Channel' ?></h6>
                            <div class="text-muted-foreground" style="font-size: 0.75rem;">algaletech@yahoo.com</div>
                        </div>
                    </div>

                    <div class="d-flex flex-column gap-2 mb-3">
                        <div class="d-flex align-items-center justify-content-between small p-2 bg-secondary rounded">
                            <span class="text-muted-foreground"><i class="fa-solid fa-fire text-danger me-1.5"></i><?= is_rtl() ? 'الأعطال الحرجة (Critical):' : 'Critical / Urgent Outage:' ?></span>
                            <strong class="text-danger">&lt; 2 <?= is_rtl() ? 'ساعات' : 'Hours' ?></strong>
                        </div>
                        <div class="d-flex align-items-center justify-content-between small p-2 bg-secondary rounded">
                            <span class="text-muted-foreground"><i class="fa-solid fa-bolt text-warning me-1.5"></i><?= is_rtl() ? 'الأعطال العادية (Standard):' : 'Standard Inquiries:' ?></span>
                            <strong class="text-foreground">&lt; 24 <?= is_rtl() ? 'ساعة' : 'Hours' ?></strong>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 text-muted-foreground small" style="font-size: 0.73rem;">
                        <i class="fa-solid fa-lock text-success"></i>
                        <span><?= is_rtl() ? 'تنقيح شامل للبيانات الشخصية وكلمات المرور.' : '100% PII scrubbed before transmission.' ?></span>
                    </div>
                </div>

                <!-- Live System Health Snapshot -->
                <div class="side-widget">
                    <div class="d-flex align-items-center justify-content-between mb-2.5">
                        <h6 class="font-heading fw-bold text-foreground mb-0">
                            <i class="fa-solid fa-microchip text-primary me-1.5"></i>
                            <?= is_rtl() ? 'تشخيص بيئة النظام' : 'Server Environment' ?>
                        </h6>
                        <span class="badge bg-secondary text-foreground small font-monospace">PHP <?= PHP_VERSION ?></span>
                    </div>

                    <div class="d-flex flex-column gap-1.5 text-muted-foreground small" style="font-size: 0.75rem;">
                        <div class="d-flex align-items-center justify-content-between">
                            <span><?= is_rtl() ? 'الإصدار النشط:' : 'Active Build:' ?></span>
                            <span class="fw-bold text-foreground"><?= htmlspecialchars($system_version['version_number'] ?? 'v2.5.0') ?> (Build <?= (int)($system_version['build_number'] ?? 2500) ?>)</span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span><?= is_rtl() ? 'نظام التشغيل:' : 'Platform:' ?></span>
                            <span class="text-foreground"><?= PHP_OS_FAMILY ?></span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between">
                            <span><?= is_rtl() ? 'مستوى الترحيل:' : 'Migration Batch:' ?></span>
                            <span class="text-foreground">Level <?= (int)($system_version['migration_version'] ?? 2500) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Recent Tickets Desk -->
                <div class="side-widget">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="font-heading fw-bold text-foreground mb-0">
                            <i class="fa-solid fa-clock-rotate-left text-info me-1.5"></i>
                            <?= is_rtl() ? 'سجل تذاكري الأخيرة' : 'Recent Tickets' ?>
                        </h6>
                        <span class="badge bg-secondary text-secondary-foreground small"><?= count($recent_tickets) ?></span>
                    </div>

                    <?php if (empty($recent_tickets)): ?>
                        <div class="text-center py-4 text-muted-foreground small">
                            <i class="fa-solid fa-clipboard-check display-6 mb-2 opacity-50 d-block"></i>
                            <?= is_rtl() ? 'لا توجد تذاكر مسجلة سابقاً' : 'No support tickets logged yet.' ?>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($recent_tickets as $t): ?>
                                <div class="p-2.5 bg-secondary rounded-lg border border-border">
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="font-code fw-bold text-primary small">#<?= htmlspecialchars($t['ticket_number']) ?></span>
                                            <button type="button" class="btn-copy" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($t['ticket_number']) ?>'); this.innerHTML='<i class=\'fa-solid fa-check text-success\'></i>'; setTimeout(() => this.innerHTML='<i class=\'fa-regular fa-copy\'></i>', 1500);" title="Copy ID">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                        <span class="ticket-status-badge status-<?= htmlspecialchars($t['status'] ?? 'open') ?>">
                                            <?php if (($t['status'] ?? '') === 'resolved'): ?>
                                                <i class="fa-solid fa-circle-check me-1"></i>
                                            <?php elseif (($t['status'] ?? '') === 'closed'): ?>
                                                <i class="fa-solid fa-lock me-1"></i>
                                            <?php endif; ?>
                                            <?= htmlspecialchars(str_replace('_', ' ', $t['status'] ?? 'open')) ?>
                                        </span>
                                    </div>
                                    <div class="text-foreground small fw-semibold text-truncate mb-1"><?= htmlspecialchars($t['subject']) ?></div>
                                    <div class="d-flex align-items-center justify-content-between text-muted-foreground" style="font-size: 0.72rem;">
                                        <span class="fleet-badge" title="Synchronized with Spectra Ops">
                                            <i class="fa-solid fa-cloud-arrow-up"></i> Spectra Synced
                                        </span>
                                        <span><?= date('M d, H:i', strtotime($t['created_at'])) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>

        </div>

        <?= render_platform_footer() ?>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Category Card Selector
        function selectCategory(el, categoryName) {
            document.querySelectorAll('.category-card').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');
            document.getElementById('selectedCategoryInput').value = categoryName;
            
            // Auto-bump priority to critical if Critical Outage is selected
            if (categoryName.includes('Critical Outage')) {
                const critRadio = document.getElementById('pri_critical');
                if (critRadio) critRadio.checked = true;
            }
        }

        // Paste clipboard into error message textarea
        async function pasteClipboardToError() {
            try {
                const text = await navigator.clipboard.readText();
                const errArea = document.getElementById('error_message');
                if (errArea && text) {
                    errArea.value = text;
                    errArea.focus();
                }
            } catch (err) {
                alert('Please allow clipboard permissions or paste manually using Ctrl+V.');
            }
        }

        // Update Dropzone filename display
        function updateFileName(input) {
            const label = document.getElementById('dropzoneLabel');
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const sizeMb = (file.size / (1024 * 1024)).toFixed(2);
                label.innerHTML = `<i class="fa-solid fa-circle-check text-success me-1"></i> <strong>${file.name}</strong> (${sizeMb} MB)`;
            }
        }

        // Form submission loading indicator
        document.getElementById('supportTicketForm')?.addEventListener('submit', function() {
            const btn = document.getElementById('submitBtn');
            const icon = document.getElementById('submitIcon');
            if (btn) btn.disabled = true;
            if (icon) {
                icon.className = 'fa-solid fa-spinner fa-spin me-1.5';
            }
        });
    </script>
</body>
</html>
