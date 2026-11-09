<?php
/**
 * Administration Module: Privacy-First Telemetry & Bug Reporting Desk
 * 
 * Displays scrubbed error logs, system health scores, module failure distribution,
 * and handles manual telemetry transmission and log management.
 */

require_once __DIR__ . '/../../core/init.php';

// Access Control: Admin / School Admin Only
if (!Auth::hasRole('admin') && !Auth::hasRole('school_admin')) {
    header('Location: ../../login.php');
    exit();
}

$user = Auth::user();
$health_metrics = Telemetry::getHealthMetrics();

// Handle Clear Synced Logs action
$action_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_synced'])) {
    try {
        $delStmt = $pdo->prepare("DELETE FROM system_telemetry_logs WHERE sync_status = 'synced'");
        $delStmt->execute();
        $deleted = $delStmt->rowCount();
        $action_msg = is_rtl() ? "تم مسح $deleted سجل تم إرساله بنجاح." : "Successfully cleared $deleted synced log record(s).";
    } catch (Exception $e) {
        $action_msg = "Error: " . $e->getMessage();
    }
}

// Module and Status Filters
$filter_module = trim($_GET['module'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_level  = trim($_GET['level'] ?? '');

$sql = "SELECT * FROM system_telemetry_logs WHERE 1=1";
$params = [];

if (!empty($filter_module)) {
    $sql .= " AND module_name = ?";
    $params[] = $filter_module;
}
if (!empty($filter_status)) {
    $sql .= " AND sync_status = ?";
    $params[] = $filter_status;
}
if (!empty($filter_level)) {
    $sql .= " AND error_level = ?";
    $params[] = $filter_level;
}

$sql .= " ORDER BY last_seen_at DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$school_name = $global_branding['school_name'] ?? 'School System';
$school_logo = $global_branding['logo_path'] ?? '';
$active_year = $global_branding['academic_year'] ?? '2025/2026';
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'سجل الاتصال والموثوقية التقنية' : 'Privacy Telemetry & Error Audit' ?> - <?= htmlspecialchars($school_name) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .code-snippet {
            font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.78rem;
            background: var(--secondary);
            border: 1px solid var(--border);
            padding: 8px 12px;
            border-radius: var(--radius-md, 8px);
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 180px;
            overflow-y: auto;
        }
        @keyframes pulseDot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.3; transform: scale(0.85); }
        }
        .pulse-dot {
            animation: pulseDot 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
        }
    </style>
</head>
<body>

    <!-- Main Navigation Topbar -->
    <?= render_universal_navbar($user, $school_name, $school_logo, $active_year) ?>

    <main class="container-fluid px-3 px-lg-4 py-4 max-w-7xl mx-auto">
        
        <!-- Breadcrumbs & Header -->
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
            <div>
                <?php
                Layout::renderBreadcrumbs([
                    ['label' => __('dashboard'), 'url' => '../../dashboard.php'],
                    ['label' => __('administration'), 'url' => 'index.php'],
                    ['label' => is_rtl() ? 'تحديثات النظام' : 'System Updates', 'url' => 'system_updates.php'],
                    ['label' => is_rtl() ? 'سجل الموثوقية والأخطاء' : 'Telemetry Logs', 'url' => null],
                ], 'modules/Administration/telemetry_logs.php');
                ?>
                <div class="d-flex align-items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-success text-white shadow-xs">
                        <i class="fa-solid fa-shield-halved fs-4"></i>
                    </div>
                    <div>
                        <h1 class="h3 font-heading fw-bold text-foreground mb-0">
                            <?= is_rtl() ? 'سجل الموثوقية والأخطاء المنقحة' : 'Privacy-First Telemetry & Health Desk' ?>
                        </h1>
                        <p class="text-muted-foreground small mb-0">
                            <?= is_rtl() ? 'مراقبة تقنية مع تنقيح كامل للبيانات الحساسة وحذف أي معلومات شخصية.' : 'Automated runtime error interception with 100% Zero-PII sanitization and telemetry staging.' ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Quick Sync Actions -->
            <div class="d-flex align-items-center flex-wrap gap-2">
                <!-- Full Resync Button (New + Previous Data) -->
                <button type="button" class="btn btn-outline-primary d-flex align-items-center gap-2 shadow-xs" id="resyncAllBtn" onclick="syncTelemetryNow(true)" title="<?= is_rtl() ? 'مزامنة السجلات المعلقة محلياً والسجلات السابقة' : 'Synchronize both new staged and previous telemetry data' ?>">
                    <i class="fa-solid fa-rotate" id="resyncAllIcon"></i>
                    <span><?= is_rtl() ? 'مزامنة الكل (جديد وسابق)' : 'Sync All (New & Previous)' ?></span>
                </button>

                <!-- Sync Staged Now Button -->
                <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-xs" id="syncTelemetryBtn" onclick="syncTelemetryNow(false)">
                    <i class="fa-solid fa-satellite-dish" id="syncIcon"></i>
                    <span><?= is_rtl() ? 'مزامنة السجلات المعلقة' : 'Sync Staged Now' ?></span>
                </button>
            </div>
        </div>

        <!-- Live Sync Status Banner -->
        <div id="liveSyncAlert" class="alert alert-success alert-dismissible fade show p-3 mb-4 rounded-lg shadow-xs d-none align-items-center justify-content-between" role="alert">
            <div class="d-flex align-items-center gap-2">
                <i class="fa-solid fa-circle-check text-success fs-5"></i>
                <span id="liveSyncAlertText" class="small fw-semibold text-foreground"></span>
            </div>
            <span class="text-muted-foreground font-monospace" style="font-size: 0.75rem;" id="liveSyncTimestamp"></span>
        </div>

        <?php if (!empty($action_msg)): ?>
            <div class="alert alert-info alert-dismissible fade show p-3 mb-4 rounded-lg shadow-xs" role="alert">
                <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($action_msg) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Key Metrics Row -->
        <div class="row g-3 mb-4">
            <div class="col-md-3">
                <div class="card bg-card border-border p-3 shadow-xs h-100">
                    <div class="text-muted-foreground text-uppercase fw-semibold" style="font-size: 0.7rem;"><?= is_rtl() ? 'معدل موثوقية النظام' : 'SYSTEM HEALTH SCORE' ?></div>
                    <div class="d-flex align-items-baseline gap-2 mt-1">
                        <div class="display-6 font-heading fw-bold text-primary" id="healthScoreVal"><?= $health_metrics['health_score'] ?>%</div>
                        <span class="badge bg-success text-white small" id="healthScoreBadge"><?= $health_metrics['status_label'] ?></span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-card border-border p-3 shadow-xs h-100">
                    <div class="text-muted-foreground text-uppercase fw-semibold" style="font-size: 0.7rem;"><?= is_rtl() ? 'سجلات معلقة محلياً' : 'STAGED TELEMETRY LOGS' ?></div>
                    <div class="display-6 font-heading fw-bold text-foreground mt-1" id="stagedCountVal"><?= (int)$health_metrics['staged_count'] ?></div>
                    <div class="text-muted-foreground small" style="font-size: 0.72rem;"><?= is_rtl() ? 'بانتظار الإرسال الآمن' : 'Pending secure HTTPS dispatch' ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-card border-border p-3 shadow-xs h-100">
                    <div class="text-muted-foreground text-uppercase fw-semibold" style="font-size: 0.7rem;"><?= is_rtl() ? 'إجمالي الحوادث المسجلة' : 'TOTAL OCCURRENCES' ?></div>
                    <div class="display-6 font-heading fw-bold text-foreground mt-1" id="totalOccurrencesVal"><?= (int)$health_metrics['total_occurrences'] ?></div>
                    <div class="text-muted-foreground small" style="font-size: 0.72rem;"><?= is_rtl() ? 'مع تجميع التكرار المتماثل' : 'Deduplicated by error hash' ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-card border-border p-3 shadow-xs h-100">
                    <div class="text-muted-foreground text-uppercase fw-semibold" style="font-size: 0.7rem;"><?= is_rtl() ? 'معيار حماية الخصوصية' : 'PRIVACY COMPLIANCE' ?></div>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <span class="badge bg-accent text-primary fs-6 fw-bold px-2.5 py-1.5"><i class="fa-solid fa-lock me-1"></i>Zero-PII</span>
                    </div>
                    <div class="text-muted-foreground small mt-1" style="font-size: 0.72rem;"><?= is_rtl() ? 'تم تنقيح وحذف كافة البيانات الحساسة' : '100% PII scrubbed before staging' ?></div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="card bg-card border-border p-3 shadow-xs mb-4">
            <form method="GET" action="telemetry_logs.php" class="row g-2 align-items-center">
                <div class="col-md-3">
                    <select name="module" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value=""><?= is_rtl() ? 'جميع الوحدات البرمجية' : 'All Modules' ?></option>
                        <option value="Administration" <?= $filter_module === 'Administration' ? 'selected' : '' ?>>Administration</option>
                        <option value="HR" <?= $filter_module === 'HR' ? 'selected' : '' ?>>HR</option>
                        <option value="Finance" <?= $filter_module === 'Finance' ? 'selected' : '' ?>>Finance</option>
                        <option value="LMS" <?= $filter_module === 'LMS' ? 'selected' : '' ?>>LMS</option>
                        <option value="Grading" <?= $filter_module === 'Grading' ? 'selected' : '' ?>>Grading</option>
                        <option value="Core" <?= $filter_module === 'Core' ? 'selected' : '' ?>>Core</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="level" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value=""><?= is_rtl() ? 'جميع درجات الخطورة' : 'All Severity Levels' ?></option>
                        <option value="critical" <?= $filter_level === 'critical' ? 'selected' : '' ?>>Critical</option>
                        <option value="fatal" <?= $filter_level === 'fatal' ? 'selected' : '' ?>>Fatal</option>
                        <option value="error" <?= $filter_level === 'error' ? 'selected' : '' ?>>Error</option>
                        <option value="warning" <?= $filter_level === 'warning' ? 'selected' : '' ?>>Warning</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value=""><?= is_rtl() ? 'حالة الإرسال (الكل)' : 'All Sync Statuses' ?></option>
                        <option value="staged" <?= $filter_status === 'staged' ? 'selected' : '' ?>><?= is_rtl() ? 'معلق محلياً (Staged)' : 'Staged (Pending)' ?></option>
                        <option value="synced" <?= $filter_status === 'synced' ? 'selected' : '' ?>><?= is_rtl() ? 'تم الإرسال (Synced)' : 'Synced' ?></option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <a href="telemetry_logs.php" class="btn btn-outline-border btn-sm w-50"><?= is_rtl() ? 'إلغاء الفلتر' : 'Reset' ?></a>
                    <button type="submit" class="btn btn-secondary btn-sm w-50"><?= is_rtl() ? 'تطبيق' : 'Filter' ?></button>
                </div>
            </form>
        </div>

        <!-- Telemetry Logs Table -->
        <div class="card bg-card border-border p-4 shadow-xs">
            <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
                <h5 class="font-heading fw-bold text-foreground mb-0">
                    <?= is_rtl() ? 'سجلات الأخطاء المنقحة محلياً' : 'Scrubbed Technical Error Queue' ?>
                    <span class="badge bg-secondary text-foreground ms-1"><?= count($logs) ?></span>
                </h5>
                <form method="POST" action="telemetry_logs.php" onsubmit="return confirm(IS_RTL ? 'هل أنت متأكد من مسح جميع السجلات التي تم إرسالها؟' : 'Are you sure you want to clear all synced logs?');">
                    <button type="submit" name="clear_synced" value="1" class="btn btn-outline-border btn-sm">
                        <i class="fa-solid fa-trash-can me-1 text-muted-foreground"></i>
                        <?= is_rtl() ? 'مسح السجلات المرسلة' : 'Clear Synced Logs' ?>
                    </button>
                </form>
            </div>

            <?php if (empty($logs)): ?>
                <div class="text-center py-5">
                    <i class="fa-regular fa-circle-check text-success display-4 mb-2 d-block"></i>
                    <h5 class="fw-bold text-foreground"><?= is_rtl() ? 'لا توجد أخطاء مسجلة حالياً' : 'No recorded error telemetry logs' ?></h5>
                    <p class="text-muted-foreground small">
                        <?= is_rtl() ? 'النظام يعمل بكفاءة واستقرار تام.' : 'The system runtime is executing smoothly across all modules.' ?>
                    </p>
                </div>
            <?php else: ?>
                <div class="d-flex flex-column gap-3">
                    <?php foreach ($logs as $log): ?>
                        <div class="card bg-secondary border-border p-3 shadow-2xs">
                            <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-2">
                                <div class="d-flex align-items-center gap-2">
                                    <?php 
                                    $lvlBadge = match($log['error_level']) {
                                        'critical', 'fatal' => 'bg-danger text-white',
                                        'warning' => 'bg-warning text-dark',
                                        default => 'bg-primary text-white'
                                    };
                                    ?>
                                    <span class="badge <?= $lvlBadge ?> fw-bold small text-uppercase"><?= htmlspecialchars($log['error_level']) ?></span>
                                    <span class="badge bg-accent text-primary small fw-semibold"><?= htmlspecialchars($log['module_name']) ?></span>
                                    <code class="text-foreground fw-bold" style="font-size: 0.8rem;"><?= htmlspecialchars($log['error_code']) ?></code>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge <?= $log['sync_status'] === 'synced' ? 'bg-success' : 'bg-secondary text-muted-foreground border' ?> small">
                                        <?= htmlspecialchars(ucfirst($log['sync_status'])) ?>
                                    </span>
                                    <span class="text-muted-foreground small" style="font-size: 0.72rem;">
                                        <?= is_rtl() ? 'تكرار:' : 'Count:' ?> <strong class="text-foreground"><?= (int)$log['occurrence_count'] ?></strong>
                                    </span>
                                </div>
                            </div>

                            <div class="fw-semibold text-foreground mb-1" style="font-size: 0.88rem;">
                                <?= htmlspecialchars($log['sanitized_message']) ?>
                            </div>

                            <div class="text-muted-foreground small mb-2" style="font-size: 0.75rem;">
                                <i class="fa-regular fa-folder-open me-1"></i>
                                <code><?= htmlspecialchars($log['file_path']) ?>:<?= (int)$log['line_number'] ?></code>
                                <span class="mx-2">·</span>
                                <i class="fa-regular fa-clock me-1"></i>
                                <?= is_rtl() ? 'آخر ظهور:' : 'Last Seen:' ?> <?= htmlspecialchars($log['last_seen_at']) ?>
                            </div>

                            <?php if (!empty($log['sanitized_trace'])): ?>
                                <details class="mt-1">
                                    <summary class="small text-primary cursor-pointer fw-semibold"><?= is_rtl() ? 'عرض مسار التتبع المنقح (Sanitized Trace) ▾' : 'View Sanitized Trace ▾' ?></summary>
                                    <div class="code-snippet mt-2"><?= htmlspecialchars($log['sanitized_trace']) ?></div>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const IS_RTL = <?= is_rtl() ? 'true' : 'false' ?>;
        const AUTO_SYNC_INTERVAL_SEC = 300; // 5 minutes background sync
        let autoSyncActive = true;
        let isSyncInProgress = false;

        function toggleAutoSync() {
            autoSyncActive = !autoSyncActive;
            const dot = document.getElementById('autoSyncPulseDot');
            const icon = document.getElementById('autoSyncToggleIcon');
            const statusText = document.getElementById('autoSyncStatusText');

            if (autoSyncActive) {
                if (dot) {
                    dot.className = 'd-inline-block rounded-circle bg-success pulse-dot';
                }
                if (icon) {
                    icon.className = 'fa-solid fa-pause';
                }
                if (statusText) {
                    statusText.innerText = IS_RTL ? 'مزامنة تلقائية (5د)' : 'Auto-Sync (5m)';
                }
                showLiveAlert(IS_RTL ? 'تم استئناف المزامنة التلقائية كل 5 دقائق.' : 'Auto-sync active (every 5 minutes).', 'info');
            } else {
                if (dot) {
                    dot.className = 'd-inline-block rounded-circle bg-warning';
                }
                if (icon) {
                    icon.className = 'fa-solid fa-play';
                }
                if (statusText) {
                    statusText.innerText = IS_RTL ? 'المزامنة متوقفة' : 'Auto-Sync Paused';
                }
                showLiveAlert(IS_RTL ? 'تم إيقاف المزامنة التلقائية مؤقتاً.' : 'Auto-sync paused.', 'warning');
            }
        }

        function showLiveAlert(msg, type = 'success') {
            const alertBox = document.getElementById('liveSyncAlert');
            const alertText = document.getElementById('liveSyncAlertText');
            const timestamp = document.getElementById('liveSyncTimestamp');

            if (alertBox && alertText) {
                alertBox.className = `alert alert-${type} alert-dismissible fade show p-3 mb-4 rounded-lg shadow-xs d-flex align-items-center justify-content-between`;
                alertText.innerText = msg;
                if (timestamp) {
                    const now = new Date();
                    timestamp.innerText = now.toLocaleTimeString();
                }
            }
        }

        async function syncTelemetryNow(includePrevious = true, isAuto = false) {
            if (isSyncInProgress) return;
            isSyncInProgress = true;

            const syncBtn = document.getElementById('syncTelemetryBtn');
            const syncIcon = document.getElementById('syncIcon');
            const resyncBtn = document.getElementById('resyncAllBtn');
            const resyncIcon = document.getElementById('resyncAllIcon');

            if (includePrevious) {
                if (resyncIcon) resyncIcon.classList.add('fa-spin');
                if (resyncBtn) resyncBtn.disabled = true;
            } else {
                if (syncIcon) syncIcon.classList.add('fa-spin');
                if (syncBtn) syncBtn.disabled = true;
            }

            try {
                const url = `../../api/telemetry_dispatch.php?include_previous=${includePrevious ? '1' : '0'}&limit=100`;
                const res = await fetch(url);
                const data = await res.json();

                if (data.success) {
                    // Update metric indicators live in the DOM if health metrics returned
                    if (data.health_metrics) {
                        const hVal = document.getElementById('healthScoreVal');
                        const hBadge = document.getElementById('healthScoreBadge');
                        const sVal = document.getElementById('stagedCountVal');
                        const tVal = document.getElementById('totalOccurrencesVal');

                        if (hVal) hVal.innerText = `${data.health_metrics.health_score}%`;
                        if (hBadge) {
                            hBadge.innerText = data.health_metrics.status_label;
                            hBadge.className = `badge small text-white ${data.health_metrics.health_score >= 95 ? 'bg-success' : (data.health_metrics.health_score >= 85 ? 'bg-warning text-dark' : 'bg-danger')}`;
                        }
                        if (sVal) sVal.innerText = data.health_metrics.staged_count;
                        if (tVal) tVal.innerText = data.health_metrics.total_occurrences;
                    }

                    const syncMsg = IS_RTL
                        ? `تمت المزامنة بنجاح: ${data.new_count || 0} سجل جديد و ${data.previous_count || 0} سجل سابق.`
                        : `Sync complete: ${data.new_count || 0} new and ${data.previous_count || 0} previous log(s) synchronized.`;

                    // Only display visual alerts for user-initiated manual syncs
                    if (!isAuto) {
                        showLiveAlert(syncMsg, 'success');
                    } else {
                        console.debug('[Telemetry Background Sync]', syncMsg);
                    }

                    // If manual sync triggered and records changed, refresh view
                    if (!isAuto && (data.synced_count > 0)) {
                        setTimeout(() => window.location.reload(), 1400);
                    }
                } else {
                    if (!isAuto) {
                        showLiveAlert(IS_RTL ? 'فشل المزامنة: ' + (data.error || 'خطأ غير معروف') : 'Sync failed: ' + (data.error || 'Unknown error'), 'danger');
                    } else {
                        console.warn('[Telemetry Background Sync]', data.error || 'Unknown error');
                    }
                }
            } catch (err) {
                if (!isAuto) {
                    showLiveAlert(IS_RTL ? 'خطأ في الشبكة أثناء مزامنة السجلات.' : 'Network error while syncing telemetry.', 'danger');
                } else {
                    console.warn('[Telemetry Background Sync Network Error]', err);
                }
            } finally {
                if (syncIcon) syncIcon.classList.remove('fa-spin');
                if (syncBtn) syncBtn.disabled = false;
                if (resyncIcon) resyncIcon.classList.remove('fa-spin');
                if (resyncBtn) resyncBtn.disabled = false;
                isSyncInProgress = false;
            }
        }

        // Initialize Background Auto-Sync Engine (Runs quietly in the background every 5 minutes)
        function initAutoSyncScheduler() {
            setInterval(() => {
                if (!autoSyncActive) return;
                // Auto-sync new and previous data silently in the background
                syncTelemetryNow(true, true);
            }, AUTO_SYNC_INTERVAL_SEC * 1000);
        }

        document.addEventListener('DOMContentLoaded', initAutoSyncScheduler);
    </script>
</body>
</html>
