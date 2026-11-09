<?php
/**
 * Administration Module: System Updates & OTA Patch Center
 * 
 * Provides centralized version tracking, remote build polling, patch authorization,
 * automated database migrations execution, and complete version history audit logs.
 */

require_once __DIR__ . '/../../core/init.php';

// Access Control: Admin / School Admin Only
if (!Auth::hasRole('admin') && !Auth::hasRole('school_admin')) {
    header('Location: ../../login.php');
    exit();
}

$user = Auth::user() ?: [
    'user_id'   => 0,
    'username'  => 'admin',
    'role'      => 'admin',
    'full_name' => 'Administrator'
];

$raw_version = SystemUpdater::getCurrentVersion();
$current_version = (is_array($raw_version) && !empty($raw_version)) ? $raw_version : [
    'version_id'        => 1,
    'version_number'    => 'v2.5.0',
    'build_number'      => 2500,
    'release_name'      => 'EduMaster System Release',
    'release_notes'     => 'Stable Core Foundation',
    'migration_version' => 1,
    'is_current'        => 1,
    'installed_at'      => date('Y-m-d H:i:s'),
    'installed_by'      => 'System',
    'status'            => 'applied'
];

$raw_history = SystemUpdater::getVersionHistory();
$version_history = is_array($raw_history) ? $raw_history : [];

$raw_check = SystemUpdater::checkForUpdates(false);
$update_check = (is_array($raw_check) && !empty($raw_check)) ? $raw_check : [
    'success'           => true,
    'has_update'        => false,
    'current_version'   => $current_version['version_number'] ?? 'v2.5.0',
    'current_build'     => $current_version['build_number'] ?? 2500,
    'latest_version'    => $current_version['version_number'] ?? 'v2.5.0',
    'latest_build'      => $current_version['build_number'] ?? 2500,
    'release_name'      => 'EduMaster System Release',
    'release_date'      => date('Y-m-d'),
    'release_type'      => 'maintenance',
    'changelog'         => []
];

$raw_health = Telemetry::getHealthMetrics();
$health_metrics = (is_array($raw_health) && !empty($raw_health)) ? $raw_health : [
    'health_score'      => 100.0,
    'status_label'      => 'Optimal',
    'total_occurrences' => 0,
    'staged_count'      => 0,
    'critical_count'    => 0,
    'total_critical'    => 0,
    'module_breakdown'  => []
];

$school_name = $global_branding['school_name'] ?? 'School System';
$school_logo = $global_branding['logo_path'] ?? '';
$active_year = $global_branding['academic_year'] ?? '2025/2026';
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'تحديثات النظام والترقيات' : 'System Updates & Upgrades' ?> - <?= htmlspecialchars($school_name) ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .version-badge-pill {
            font-family: var(--font-heading, inherit);
            letter-spacing: 0.05em;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .changelog-item {
            position: relative;
            padding-inline-start: 1.5rem;
            margin-bottom: 0.5rem;
        }
        .changelog-item::before {
            content: "•";
            position: absolute;
            inset-inline-start: 0.5rem;
            color: var(--primary);
            font-weight: bold;
            font-size: 1.1rem;
        }
        .update-progress-bar {
            height: 8px;
            border-radius: 4px;
            overflow: hidden;
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
                    ['label' => is_rtl() ? 'تحديثات النظام' : 'System Updates', 'url' => null],
                ], 'modules/Administration/system_updates.php');
                ?>
                <div class="d-flex align-items-center gap-3">
                    <div class="p-2.5 rounded-lg bg-primary text-white shadow-xs">
                        <i class="fa-solid fa-cloud-arrow-up fs-4"></i>
                    </div>
                    <div>
                        <h1 class="h3 font-heading fw-bold text-foreground mb-0">
                            <?= is_rtl() ? 'مركز تحديثات وترقيات النظام' : 'System Updates & Patch Center' ?>
                        </h1>
                        <p class="text-muted-foreground small mb-0">
                            <?= is_rtl() ? 'إدارة ترقيات الخادم والتحديثات السحابية والترحيل التلقائي لقواعد البيانات.' : 'Manage server updates, OTA patch releases, and automated database migrations.' ?>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Quick Action Buttons -->
            <div class="d-flex align-items-center gap-2">
                <a href="telemetry_logs.php" class="btn btn-outline-border d-flex align-items-center gap-2 shadow-xs">
                    <i class="fa-solid fa-shield-halved text-success"></i>
                    <span><?= is_rtl() ? 'سجل الاتصال والموثوقية' : 'Telemetry & Audit' ?></span>
                </a>
                <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-xs" id="checkUpdateBtn" onclick="checkForUpdates(true)">
                    <i class="fa-solid fa-arrows-rotate" id="checkUpdateIcon"></i>
                    <span><?= is_rtl() ? 'التحقق من التحديثات' : 'Check for Updates' ?></span>
                </button>
            </div>
        </div>

        <div class="row g-4">
            
            <!-- Left Column: Current Version & Update Deployment Wizard -->
            <div class="col-lg-8">
                
                <!-- Current Version Status Card -->
                <div class="card bg-card border-border p-4 shadow-xs mb-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 pb-3 border-bottom border-border mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="avatar-lg rounded-circle bg-accent text-primary d-flex align-items-center justify-content-center fs-3">
                                <i class="fa-solid fa-microchip"></i>
                            </div>
                            <div>
                                <div class="text-muted-foreground text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">
                                    <?= is_rtl() ? 'الإصدار النشط على الخادم' : 'ACTIVE SERVER BUILD' ?>
                                </div>
                                <h4 class="font-heading fw-bold text-foreground mb-0 d-flex align-items-center gap-2">
                                    <span><?= htmlspecialchars($current_version['version_number'] ?? 'v2.5.0') ?></span>
                                    <span class="badge bg-primary text-white version-badge-pill">Build <?= htmlspecialchars((string)($current_version['build_number'] ?? '2500')) ?></span>
                                </h4>
                                <div class="text-muted-foreground small mt-0.5">
                                    <?= htmlspecialchars($current_version['release_name'] ?? 'EduMaster System Release') ?> · <?= is_rtl() ? 'مثبت بتاريخ:' : 'Installed on:' ?> <?= htmlspecialchars($current_version['installed_at'] ?? date('Y-m-d')) ?>
                                </div>
                            </div>
                        </div>

                        <div>
                            <?php if (!empty($update_check['has_update'])): ?>
                                <span class="badge bg-warning text-dark px-3 py-2 fs-6 rounded-pill d-inline-flex align-items-center gap-1.5 shadow-xs">
                                    <i class="fa-solid fa-bell"></i>
                                    <span><?= is_rtl() ? 'تحديث جديد متاح' : 'Update Available' ?></span>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success text-white px-3 py-2 fs-6 rounded-pill d-inline-flex align-items-center gap-1.5 shadow-xs">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <span><?= is_rtl() ? 'النظام محدث بالكامل' : 'System is Up to Date' ?></span>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Environment & Diagnostics Grid -->
                    <div class="row g-3">
                        <div class="col-sm-4">
                            <div class="p-3 bg-secondary rounded-lg border border-border">
                                <div class="text-muted-foreground small" style="font-size: 0.72rem;"><?= is_rtl() ? 'إصدار PHP' : 'PHP Runtime' ?></div>
                                <div class="fw-bold text-foreground mt-1 d-flex align-items-center gap-1.5">
                                    <i class="fa-brands fa-php text-primary"></i>
                                    <span><?= PHP_VERSION ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 bg-secondary rounded-lg border border-border">
                                <div class="text-muted-foreground small" style="font-size: 0.72rem;"><?= is_rtl() ? 'نظام التشغيل' : 'Operating Platform' ?></div>
                                <div class="fw-bold text-foreground mt-1 d-flex align-items-center gap-1.5">
                                    <i class="fa-solid fa-server text-tertiary"></i>
                                    <span><?= PHP_OS_FAMILY ?> (<?= PHP_OS ?>)</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 bg-secondary rounded-lg border border-border">
                                <div class="text-muted-foreground small" style="font-size: 0.72rem;"><?= is_rtl() ? 'مستوى الترحيل الأخير' : 'Migration Level' ?></div>
                                <div class="fw-bold text-foreground mt-1 d-flex align-items-center gap-1.5">
                                    <i class="fa-solid fa-database text-warning"></i>
                                    <span>Batch <?= (int)($current_version['migration_version'] ?? 1) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Update Available Action Card (Dynamic / Ajax Hydrated) -->
                <div class="card bg-card border-border p-4 shadow-xs mb-4" id="updateCardContainer">
                    <?php if (!empty($update_check['has_update'])): ?>
                        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2 mb-3">
                            <div>
                                <span class="badge bg-accent text-primary mb-1 fw-bold"><?= htmlspecialchars(strtoupper($update_check['release_type'] ?? 'PATCH')) ?></span>
                                <h4 class="font-heading fw-bold text-foreground mb-1">
                                    <?= htmlspecialchars($update_check['release_name'] ?? 'EduMaster New Release') ?>
                                    <span class="text-primary">(<?= htmlspecialchars($update_check['latest_version'] ?? '') ?> · Build <?= htmlspecialchars($update_check['latest_build'] ?? '') ?>)</span>
                                </h4>
                                <div class="text-muted-foreground small">
                                    <?= is_rtl() ? 'تاريخ الإصدار:' : 'Release Date:' ?> <?= htmlspecialchars($update_check['release_date'] ?? date('Y-m-d')) ?>
                                </div>
                            </div>
                        </div>

                        <!-- Changelog List -->
                        <div class="bg-secondary p-3.5 rounded-lg border border-border mb-4">
                            <h6 class="fw-bold text-foreground small mb-2 text-uppercase" style="letter-spacing: 0.05em;">
                                <i class="fa-solid fa-list-check text-primary me-1"></i>
                                <?= is_rtl() ? 'سجل التغييرات والتحسينات المضمنة' : 'CHANGELOG & IMPROVEMENTS' ?>
                            </h6>
                            <div class="d-flex flex-column text-foreground small">
                                <?php if (!empty($update_check['changelog'])): ?>
                                    <?php foreach ($update_check['changelog'] as $cl): ?>
                                        <div class="changelog-item"><?= htmlspecialchars($cl) ?></div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="changelog-item"><?= is_rtl() ? 'تحسينات عامة واستقرار النظام.' : 'General stability and performance improvements.' ?></div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Deployment Action & Progress State -->
                        <div id="deploymentProgressWrap" class="mb-3" style="display: none;">
                            <div class="d-flex align-items-center justify-content-between small text-muted-foreground mb-1">
                                <span id="deployStatusText"><?= is_rtl() ? 'جاري تجهيز حزمة التحديث...' : 'Preparing patch package...' ?></span>
                                <span id="deployPercentText" class="fw-bold text-primary">0%</span>
                            </div>
                            <div class="progress update-progress-bar bg-secondary">
                                <div id="deployProgressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width: 0%"></div>
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="small text-muted-foreground">
                                <i class="fa-solid fa-shield-halved text-success me-1"></i>
                                <?= is_rtl() ? 'يتم ترحيل وتحديث قواعد البيانات تلقائياً وبأمان تام دون إيقاف العمليات.' : 'Automated atomic database migrations with zero disruption to active user workflows.' ?>
                            </div>
                            <button type="button" class="btn btn-primary px-4 py-2.5 fw-bold d-flex align-items-center gap-2 shadow-sm" id="deployUpdateBtn" onclick="deploySystemUpdate()">
                                <i class="fa-solid fa-cloud-arrow-down"></i>
                                <span><?= is_rtl() ? 'تثبيت وترقية النظام الآن' : 'Authorize & Deploy Update Now' ?></span>
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fa-regular fa-circle-check text-success display-4 mb-2 d-block"></i>
                            <h5 class="fw-bold text-foreground"><?= is_rtl() ? 'نظامك يعمل بأحدث إصدار مستقر' : 'Your system is running the latest stable release' ?></h5>
                            <p class="text-muted-foreground small mb-3">
                                <?= is_rtl() ? 'لا توجد تحديثات معلقة. يتم التحقق من الخادم الرئيسي بانتظام.' : 'No pending patches detected. The system checks for developer updates periodically.' ?>
                            </p>
                            <button type="button" class="btn btn-outline-border btn-sm" onclick="checkForUpdates(true)">
                                <i class="fa-solid fa-arrows-rotate me-1"></i><?= is_rtl() ? 'إعادة الفحص الآن' : 'Re-check Now' ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Complete Version History Log Table -->
                <div class="card bg-card border-border p-4 shadow-xs">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="fa-solid fa-timeline text-primary fs-5"></i>
                            <h5 class="font-heading fw-bold text-foreground mb-0"><?= is_rtl() ? 'سجل الإصدارات والترقيات السابقة' : 'Version History & Audit Log' ?></h5>
                        </div>
                        <span class="badge bg-secondary text-foreground"><?= count($version_history) ?> <?= is_rtl() ? 'سجلات' : 'Entries' ?></span>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-secondary text-muted-foreground small text-uppercase">
                                <tr>
                                    <th><?= is_rtl() ? 'الإصدار' : 'Version' ?></th>
                                    <th><?= is_rtl() ? 'رقم البناء' : 'Build' ?></th>
                                    <th><?= is_rtl() ? 'مسمى الإصدار' : 'Release Name' ?></th>
                                    <th><?= is_rtl() ? 'تاريخ التثبيت' : 'Installed Date' ?></th>
                                    <th><?= is_rtl() ? 'المثبت' : 'Installed By' ?></th>
                                    <th><?= is_rtl() ? 'الحالة' : 'Status' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($version_history)): ?>
                                    <?php foreach ($version_history as $vh): ?>
                                        <tr>
                                            <td class="fw-bold text-foreground">
                                                <?= htmlspecialchars($vh['version_number'] ?? '') ?>
                                                <?php if (!empty($vh['is_current'])): ?>
                                                    <span class="badge bg-success text-white ms-1 small" style="font-size: 0.65rem;"><?= is_rtl() ? 'نشط' : 'Current' ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?= htmlspecialchars((string)($vh['build_number'] ?? '')) ?></code></td>
                                            <td><?= htmlspecialchars($vh['release_name'] ?? '') ?></td>
                                            <td class="small text-muted-foreground"><?= htmlspecialchars($vh['installed_at'] ?? '') ?></td>
                                            <td class="small"><?= htmlspecialchars(($vh['installed_by'] ?? null) ?: 'System') ?></td>
                                            <td>
                                                <span class="badge bg-accent text-primary"><?= htmlspecialchars(ucfirst(($vh['status'] ?? null) ?: 'Applied')) ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="text-center py-4 text-muted small">
                                            <i class="fa-solid fa-clock-rotate-left me-1"></i>
                                            <?= is_rtl() ? 'لا توجد سجلات إصدارات سابقة مسجلة.' : 'No previous version history recorded.' ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <!-- Right Column: System Governance, Migrations, & Telemetry Status -->
            <div class="col-lg-4">
                
                <!-- System Reliability & Privacy-First Telemetry Card -->
                <div class="card bg-card border-border p-4 shadow-xs mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="font-heading fw-bold text-foreground mb-0"><?= is_rtl() ? 'موثوقية النظام والمراقبة' : 'System Health & Telemetry' ?></h6>
                        <span class="badge bg-success text-white small"><?= $health_metrics['status_label'] ?></span>
                    </div>

                    <div class="text-center py-2">
                        <div class="display-5 font-heading fw-bold text-primary mb-1"><?= $health_metrics['health_score'] ?>%</div>
                        <div class="text-muted-foreground small"><?= is_rtl() ? 'معدل استقرار النظام والتنفيذ' : 'System Stability Score' ?></div>
                    </div>

                    <div class="d-flex flex-column gap-2 mt-3 pt-3 border-top border-border">
                        <div class="d-flex align-items-center justify-content-between text-muted-foreground small">
                            <span><i class="fa-solid fa-bug text-destructive me-1.5"></i><?= is_rtl() ? 'أخطاء موثقة محلياً:' : 'Staged Telemetry Logs:' ?></span>
                            <span class="fw-bold text-foreground"><?= (int)$health_metrics['staged_count'] ?></span>
                        </div>
                        <div class="d-flex align-items-center justify-content-between text-muted-foreground small">
                            <span><i class="fa-solid fa-triangle-exclamation text-warning me-1.5"></i><?= is_rtl() ? 'أخطاء حرجة:' : 'Critical Incidents:' ?></span>
                            <span class="fw-bold text-foreground"><?= (int)$health_metrics['critical_count'] ?></span>
                        </div>
                    </div>

                    <div class="mt-3">
                        <a href="telemetry_logs.php" class="btn btn-outline-border btn-sm w-100 fw-semibold">
                            <span><?= is_rtl() ? 'عرض سجلات الأخطاء المنقحة ←' : 'View Scrubbed Audit Log →' ?></span>
                        </a>
                    </div>
                </div>

                <!-- Database Migrations Quick Action Card -->
                <div class="card bg-card border-border p-4 shadow-xs mb-4">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="fa-solid fa-database text-warning fs-5"></i>
                        <h6 class="font-heading fw-bold text-foreground mb-0"><?= is_rtl() ? 'ترحيل قواعد البيانات التلقائي' : 'Database Migrations' ?></h6>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'تنفيذ التعديلات على الجداول والحقول بصورة تزايدية وآمنة.' : 'Execute incremental database schema scripts safely in an atomic transaction.' ?>
                    </p>

                    <button type="button" class="btn btn-secondary w-100 btn-sm fw-semibold d-flex align-items-center justify-content-center gap-2 border border-border" id="runMigrationsBtn" onclick="runPendingMigrations()">
                        <i class="fa-solid fa-play"></i>
                        <span><?= is_rtl() ? 'تشغيل الترحيلات المعلقة' : 'Run Pending Migrations' ?></span>
                    </button>
                    <div id="migrationAlert" class="mt-2 small" style="display: none;"></div>
                </div>

                <!-- Privacy & Zero-PII Compliance Guarantee -->
                <div class="card bg-secondary border-0 p-3.5 shadow-2xs">
                    <div class="d-flex gap-2.5">
                        <i class="fa-solid fa-lock text-primary fs-5 mt-0.5"></i>
                        <div>
                            <div class="fw-bold text-foreground small mb-1"><?= is_rtl() ? 'ضمان الخصوصية التامة' : 'Zero-PII Privacy Guarantee' ?></div>
                            <p class="text-muted-foreground small mb-0" style="font-size: 0.75rem; line-height: 1.4;">
                                <?= is_rtl() ? 'يتم تنقيح وحذف كافة البيانات الشخصية والبريد وكلمات المرور والمسارات قبل أي تسجيل تقني.' : 'All error telemetry is passed through a strict scrubbing layer that strips personal names, emails, passwords, and server credentials before storage.' ?>
                            </p>
                        </div>
                    </div>
                </div>

            </div>

        </div>

    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const IS_RTL = <?= is_rtl() ? 'true' : 'false' ?>;
        const CSRF_TOKEN = '<?= generateCsrfToken() ?>';

        // Check for updates
        function checkForUpdates(force = true) {
            const btn = document.getElementById('checkUpdateBtn');
            const icon = document.getElementById('checkUpdateIcon');
            if (icon) icon.classList.add('fa-spin');
            if (btn) btn.disabled = true;

            fetch('../../api/system_update_action.php?action=check_update' + (force ? '&force=1' : ''))
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        if (data.has_update) {
                            alert(IS_RTL ? `تم العثور على تحديث جديد: ${data.latest_version} (Build ${data.latest_build})` : `New update available: ${data.latest_version} (Build ${data.latest_build})`);
                            window.location.reload();
                        } else {
                            alert(IS_RTL ? 'نظامك محدث بالكامل بأحدث إصدار مستقر.' : 'System is already up to date.');
                        }
                    } else {
                        alert(IS_RTL ? 'تعذر الاتصال بخادم التحديثات: ' + (data.error || 'خطأ غير معروف') : 'Could not reach update server: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(err => {
                    alert(IS_RTL ? 'خطأ في الشبكة أثناء فحص التحديثات.' : 'Network error while checking updates.');
                })
                .finally(() => {
                    if (icon) icon.classList.remove('fa-spin');
                    if (btn) btn.disabled = false;
                });
        }

        // Deploy system update with animated progress
        function deploySystemUpdate() {
            if (!confirm(IS_RTL ? 'هل أنت متأكد من رغبتك في تثبيت وتطبيق التحديث وترقية النظام الآن؟' : 'Are you sure you want to authorize and deploy this update now?')) {
                return;
            }

            const deployBtn = document.getElementById('deployUpdateBtn');
            const progressWrap = document.getElementById('deploymentProgressWrap');
            const pBar = document.getElementById('deployProgressBar');
            const pText = document.getElementById('deployPercentText');
            const pStatus = document.getElementById('deployStatusText');

            if (deployBtn) deployBtn.disabled = true;
            if (progressWrap) progressWrap.style.display = 'block';

            // Animated progress simulation
            let percent = 10;
            pBar.style.width = percent + '%';
            pText.textContent = percent + '%';
            pStatus.textContent = IS_RTL ? 'جاري التحقق من التوافق وترحيل قواعد البيانات...' : 'Verifying compatibility & executing database migrations...';

            const interval = setInterval(() => {
                if (percent < 85) {
                    percent += 15;
                    pBar.style.width = percent + '%';
                    pText.textContent = percent + '%';
                    if (percent === 40) pStatus.textContent = IS_RTL ? 'جاري تطبيق تعديلات الجداول وفهارس الأداء...' : 'Applying schema migrations and indexes...';
                    if (percent === 70) pStatus.textContent = IS_RTL ? 'جاري تحديث سجل الإصدارات وتهيئة النظام...' : 'Updating version registry and finalizing build...';
                }
            }, 300);

            // Execute backend deployment API
            fetch('../../api/system_update_action.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN 
                },
                body: JSON.stringify({
                    action: 'apply_update',
                    csrf_token: CSRF_TOKEN,
                    version_number: <?= json_encode($update_check['latest_version'] ?? 'v2.5.0') ?>,
                    build_number: <?= (int)($update_check['latest_build'] ?? 2500) ?>,
                    release_name: <?= json_encode($update_check['release_name'] ?? 'EduMaster Nova Patch Release') ?>,
                    changelog: <?= json_encode($update_check['changelog'] ?? []) ?>
                })
            })
            .then(res => res.json())
            .then(data => {
                clearInterval(interval);
                if (data.success) {
                    pBar.style.width = '100%';
                    pText.textContent = '100%';
                    pStatus.textContent = IS_RTL ? 'تمت الترقية بنجاح!' : 'Upgrade completed successfully!';
                    setTimeout(() => {
                        alert(IS_RTL ? `تمت ترقية النظام بنجاح إلى ${data.version_number} (Build ${data.build_number})` : `System successfully upgraded to ${data.version_number} (Build ${data.build_number})`);
                        window.location.reload();
                    }, 500);
                } else {
                    alert(IS_RTL ? 'فشل التحديث: ' + data.error : 'Update failed: ' + data.error);
                    if (deployBtn) deployBtn.disabled = false;
                    if (progressWrap) progressWrap.style.display = 'none';
                }
            })
            .catch(err => {
                clearInterval(interval);
                alert(IS_RTL ? 'خطأ في الاتصال أثناء التحديث.' : 'Connection error during update deployment.');
                if (deployBtn) deployBtn.disabled = false;
                if (progressWrap) progressWrap.style.display = 'none';
            });
        }

        // Run pending migrations manually
        function runPendingMigrations() {
            const btn = document.getElementById('runMigrationsBtn');
            const alertBox = document.getElementById('migrationAlert');
            if (btn) btn.disabled = true;

            fetch('../../api/system_update_action.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN 
                },
                body: JSON.stringify({ action: 'run_migrations', csrf_token: CSRF_TOKEN })
            })
            .then(res => res.json())
            .then(data => {
                if (alertBox) {
                    alertBox.style.display = 'block';
                    if (data.success) {
                        alertBox.className = 'mt-2 alert alert-success py-1.5 px-2.5 mb-0 small';
                        alertBox.textContent = data.message || (IS_RTL ? 'تم ترحيل قواعد البيانات بنجاح.' : 'Migrations applied successfully.');
                    } else {
                        alertBox.className = 'mt-2 alert alert-danger py-1.5 px-2.5 mb-0 small';
                        alertBox.textContent = data.error || (IS_RTL ? 'حدث خطأ أثناء الترحيل.' : 'Migration error.');
                    }
                }
            })
            .catch(err => {
                if (alertBox) {
                    alertBox.style.display = 'block';
                    alertBox.className = 'mt-2 alert alert-danger py-1.5 px-2.5 mb-0 small';
                    alertBox.textContent = IS_RTL ? 'خطأ في الاتصال أثناء الترحيل.' : 'Connection error.';
                }
            })
            .finally(() => {
                if (btn) btn.disabled = false;
            });
        }
    </script>
</body>
</html>
