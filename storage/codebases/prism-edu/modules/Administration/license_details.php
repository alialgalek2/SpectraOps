<?php
/**
 * Administration Module - Commercial License & Subscription Details Desk
 * Dedicated Standalone Management Page
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin or School Admin
Auth::requireRole(['admin', 'school_admin']);

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "info";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Handle License Key Update / Renewal Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_license') {
    $new_license_key = trim($_POST['license_key'] ?? '');
    $domain_lock     = trim($_POST['domain_lock'] ?? '*');
    $max_students    = intval($_POST['max_students'] ?? 1000);
    $expiration_date = trim($_POST['expiration_date'] ?? '');
    $status          = in_array($_POST['status'] ?? 'Active', ['Active', 'Expired', 'Suspended']) ? $_POST['status'] : 'Active';

    if (!empty($new_license_key)) {
        try {
            if (empty($expiration_date)) {
                $expiration_date = date('Y-m-d', strtotime('+1 year'));
            }
            $existingLic = $pdo->query("SELECT license_id FROM system_license ORDER BY license_id DESC LIMIT 1")->fetch();
            if ($existingLic) {
                $stmt = $pdo->prepare("UPDATE system_license SET license_key = ?, domain_lock = ?, max_students = ?, expiration_date = ?, status = ?, last_verified_at = NOW() WHERE license_id = ?");
                $stmt->execute([$new_license_key, $domain_lock, $max_students, $expiration_date, $status, $existingLic['license_id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_license (license_key, domain_lock, max_students, expiration_date, status, last_verified_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([$new_license_key, $domain_lock, $max_students, $expiration_date, $status]);
            }

            $_SESSION['flash_msg'] = is_rtl() ? "تم حفظ وتفعيل ترخيص النظام بنجاح!" : "System commercial license updated and activated successfully!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: license_details.php");
            exit();
        } catch (\Exception $e) {
            $msg = "Error updating license: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = is_rtl() ? "يرجى إدخال مفتاح ترخيص صالح." : "Please enter a valid license key.";
        $msg_type = "danger";
    }
}

// Fetch System License Details & Student Counts
require_once __DIR__ . '/../../licensing/license_helper.php';
$system_license_check = checkSystemLicense($pdo);
$system_license = $pdo->query("SELECT * FROM system_license ORDER BY license_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$active_students_count = (int)($pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0")->fetchColumn() ?: 0);

$licKey = $system_license['license_key'] ?? 'N/A';
$maskedKey = strlen($licKey) > 12 ? substr($licKey, 0, 8) . '••••••••' . substr($licKey, -4) : $licKey;
$expDate = !empty($system_license['expiration_date']) ? $system_license['expiration_date'] : null;
$daysLeft = $expDate ? (int)ceil((strtotime($expDate) - time()) / 86400) : 0;
$maxStudents = (int)($system_license['max_students'] ?? 1000);
$capPercent = $maxStudents > 0 ? min(100, round(($active_students_count / $maxStudents) * 100, 1)) : 0;
$isLicValid = ($system_license_check['valid'] ?? false);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'تفاصيل الترخيص والاشتراك' : 'Commercial License Details' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background">

<!-- Top Universal Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'تفاصيل ترخيص المنظومة' : 'Commercial License Details',
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container-fluid py-4 px-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show fw-bold shadow-sm" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Welcome Banner -->
    <div class="hero-banner p-4 p-md-5 mb-4 shadow-sm rounded-xl">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <div class="d-inline-flex align-items-center gap-2 mb-2 flex-wrap">
                    <span class="badge rounded-full font-medium px-3 py-1 text-white" style="background: rgba(255, 255, 255, 0.18); backdrop-filter: blur(8px);">
                        <i class="fa-solid fa-circle-check text-emerald-300 me-1"></i> System Status: Operational
                    </span>
                    <span class="badge rounded-full font-medium px-3 py-1 text-white shadow-xs" style="background: rgba(255, 255, 255, 0.22); backdrop-filter: blur(8px);">
                        <i class="fa-solid fa-certificate <?= $isLicValid ? 'text-warning' : 'text-danger' ?> me-1"></i>
                        <?= $isLicValid ? (is_rtl() ? 'الترخيص: نشط ومعتمد' : 'License: Commercial Active') : (is_rtl() ? 'الترخيص: بحاجة لتجديد' : 'License: Action Required') ?>
                    </span>
                </div>
                <h2 class="font-heading fw-bold text-white mb-1">
                    <i class="fa-solid fa-id-card-clip me-2"></i><?= is_rtl() ? 'تفاصيل الترخيص والاشتراك التجاري' : 'Commercial License & Subscription Details' ?>
                </h2>
                <p class="text-white-50 mb-0 small">
                    <?= is_rtl() 
                        ? 'إدارة تراخيص تشغيل المنظومة، سعة مقاعد الطلاب المسجلين، قيود المضيف، وتفعيل المفاتيح الرسمية.' 
                        : 'Manage system operating licenses, student seat capacity quotas, domain locks, and commercial key renewals.' ?>
                </p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a href="license_activate.php" class="btn btn-light text-primary fw-bold shadow-xs rounded-lg px-3 py-2">
                    <i class="fa-solid fa-key me-1"></i> <?= is_rtl() ? 'معالج التفعيل السريع' : 'Quick Key Wizard' ?>
                </a>
                <a href="index.php" class="btn btn-outline-light rounded-lg px-3 py-2">
                    <i class="fa-solid fa-arrow-left me-1"></i> <?= is_rtl() ? 'مركز الإدارة' : 'Control Center' ?>
                </a>
                <a href="system_updates.php" class="btn btn-primary text-white fw-bold rounded-lg px-3 py-2">
                    <i class="fa-solid fa-cloud-arrow-up me-1"></i> <?= is_rtl() ? 'تحديثات OTA' : 'OTA System Updates' ?>
                </a>
                <a href="telemetry_logs.php" class="btn btn-info text-white fw-bold rounded-lg px-3 py-2">
                    <i class="fa-solid fa-chart-line me-1"></i> <?= is_rtl() ? 'تقارير Telemetry' : 'Zero-PII Telemetry' ?>
                </a>
            </div>
        </div>
    </div>

    <!-- 4 Summary KPI Cards for License -->
    <div class="row g-3 mb-4">
        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'حالة الاعتماد والترخيص' : 'Validation Status' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: <?= $isLicValid ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)' ?>; color: <?= $isLicValid ? '#10B981' : '#EF4444' ?>;">
                        <i class="fa-solid <?= $isLicValid ? 'fa-shield-check' : 'fa-shield-xmark' ?> fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-4 my-1"><?= $isLicValid ? 'Fully Authorized' : 'Invalid / Expired' ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="<?= $isLicValid ? 'text-success' : 'text-danger' ?> fw-semibold">
                        <i class="fa-solid <?= $isLicValid ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-1"></i> <?= htmlspecialchars($system_license['status'] ?? 'Active') ?>
                    </span>
                    <span class="text-muted-foreground">· Enterprise Tier</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'تاريخ انتهاء الصلاحية' : 'Expiration Date' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(59,130,246,0.12); color: #3B82F6;">
                        <i class="fa-solid fa-calendar-check fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-4 my-1"><?= $expDate ? date('M d, Y', strtotime($expDate)) : '—' ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="<?= $daysLeft <= 30 ? 'text-danger fw-bold' : 'text-primary fw-semibold' ?>">
                        <i class="fa-solid fa-clock me-1"></i> <?= $daysLeft > 0 ? "$daysLeft days left" : "Expired" ?>
                    </span>
                    <span class="text-muted-foreground">· Subscription</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'سعة مقاعد الطلاب' : 'Student Quota Capacity' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(2,132,199,0.12); color: #0284C7;">
                        <i class="fa-solid fa-user-graduate fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-4 my-1"><?= number_format($active_students_count) ?> / <?= number_format($maxStudents) ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-info fw-semibold">
                        <i class="fa-solid fa-chart-pie me-1"></i> <?= $capPercent ?>% used
                    </span>
                    <span class="text-muted-foreground">· Enrolled seats</span>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-sm-6">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-4 shadow-sm h-100 position-relative overflow-hidden">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted-foreground small font-medium"><?= is_rtl() ? 'النطاق المعتمد (Host Lock)' : 'Authorized Domain Lock' ?></span>
                    <div class="p-2 rounded-lg d-flex align-items-center justify-content-center" style="width: 2.25rem; height: 2.25rem; background: rgba(168,85,247,0.12); color: #A855F7;">
                        <i class="fa-solid fa-globe fs-6"></i>
                    </div>
                </div>
                <div class="font-heading fw-bold text-foreground fs-4 my-1 font-monospace"><?= htmlspecialchars($system_license['domain_lock'] ?? '*') ?></div>
                <div class="d-flex align-items-center gap-2 small">
                    <span class="text-purple fw-semibold">
                        <i class="fa-solid fa-network-wired me-1"></i> <?= ($system_license['domain_lock'] ?? '*') === '*' ? 'Wildcard' : 'Single Host' ?>
                    </span>
                    <span class="text-muted-foreground">· Binding valid</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Student Capacity Progress Bar Card -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
            <div>
                <h6 class="fw-bold font-heading mb-0 text-foreground">
                    <i class="fa-solid fa-chart-simple text-primary me-2"></i>
                    <?= is_rtl() ? 'مقياس استيعاب مقاعد الطلاب' : 'Enrolled Student Capacity Meter' ?>
                </h6>
                <small class="text-muted-foreground">
                    <?= is_rtl() 
                        ? 'مستوى استخدام السعة التخزينية القصوى لعدد الطلاب المسجلين بالمنظومة وفقاً للعقد التجاري.' 
                        : 'Licensed seat allocation versus currently active student enrollments.' ?>
                </small>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary px-3 py-1.5 rounded-full font-medium fs-6">
                <?= number_format($active_students_count) ?> / <?= number_format($maxStudents) ?> seats (<?= $capPercent ?>%)
            </span>
        </div>
        <div class="progress mt-2" style="height: 10px; border-radius: var(--radius-full);">
            <div class="progress-bar <?= $capPercent > 90 ? 'bg-danger' : ($capPercent > 75 ? 'bg-warning' : 'bg-primary') ?>" 
                 role="progressbar" style="width: <?= $capPercent ?>%;" aria-valuenow="<?= $capPercent ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
    </div>

    <!-- License Key Reveal & Copy Card -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
        <div class="row align-items-center g-3">
            <div class="col-md-8">
                <small class="text-muted-foreground text-uppercase fw-bold d-block mb-1" style="font-size: 0.72rem; letter-spacing: 0.04em;">
                    <i class="fa-solid fa-key me-1 text-warning"></i> <?= is_rtl() ? 'مفتاح الترخيص المشفر الحالي' : 'Active Commercial License Key' ?>
                </small>
                <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                    <span id="displayLicenseKey" class="font-monospace fs-6 fw-bold text-foreground bg-secondary-subtle px-3 py-2 rounded-md border border-border">
                        <?= htmlspecialchars($maskedKey) ?>
                    </span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-2 px-3 rounded-lg" id="btnToggleKey" onclick="toggleLicKeyVisibility()" title="Show/Hide Key">
                        <i class="fa-solid fa-eye" id="toggleIcon"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary py-2 px-3 rounded-lg" onclick="copyLicKey()" title="Copy to Clipboard">
                        <i class="fa-solid fa-copy me-1"></i> <?= is_rtl() ? 'نسخ المفتاح' : 'Copy Key' ?>
                    </button>
                    <span id="copySuccessMsg" class="badge bg-success-subtle text-success small d-none px-2.5 py-1.5"><?= is_rtl() ? 'تم النسخ!' : 'Copied to clipboard!' ?></span>
                </div>
            </div>
            <div class="col-md-4 text-md-end">
                <small class="text-muted-foreground d-block mb-1">
                    <?= is_rtl() ? 'معرف سجل الترخيص:' : 'License Record ID:' ?> <strong class="text-foreground">#<?= $system_license['license_id'] ?? 1 ?></strong>
                </small>
                <small class="text-muted-foreground d-block mb-1">
                    <?= is_rtl() ? 'تاريخ الإصدار الأولي:' : 'Issued Date:' ?> <span class="text-foreground"><?= !empty($system_license['created_at']) ? date('M d, Y', strtotime($system_license['created_at'])) : '2026-08-26' ?></span>
                </small>
                <small class="text-muted-foreground d-block">
                    <?= is_rtl() ? 'آخر تحقق وتأكيد:' : 'Last Verified:' ?> <span class="text-foreground"><?= !empty($system_license['last_verified_at']) ? htmlspecialchars($system_license['last_verified_at']) : 'Recent' ?></span>
                </small>
            </div>
        </div>
    </div>

    <!-- License Key Renewal / Modification Form Card -->
    <div class="card border border-border bg-card shadow-sm rounded-xl p-4 mb-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3 pb-3 border-bottom border-border">
            <div>
                <h5 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-arrows-rotate text-primary me-2"></i><?= is_rtl() ? 'تحديث أو تجديد مفتاح الترخيص' : 'Renew or Upgrade Commercial License' ?>
                </h5>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() 
                        ? 'أدخل المفتاح التجاري الجديد الصادر لتوسيع سعة الطلاب المسجلين أو تمديد فترة الصلاحية.' 
                        : 'Enter a newly issued commercial license key to expand student enrollment capacity or extend system subscription.' ?>
                </p>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="update_license">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-foreground"><?= is_rtl() ? 'مفتاح الترخيص الجديد' : 'New License Key' ?> *</label>
                    <input type="text" name="license_key" class="form-control font-monospace" placeholder="e.g. DEV-KEY-ALGALE-2026 or ALGALE-XXXX-XXXX" value="<?= htmlspecialchars($system_license['license_key'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-foreground"><?= is_rtl() ? 'سعة مقاعد الطلاب' : 'Max Student Seats' ?></label>
                    <input type="number" name="max_students" class="form-control" value="<?= (int)($system_license['max_students'] ?? 1000) ?>" min="1" step="10">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small text-foreground"><?= is_rtl() ? 'تاريخ انتهاء الصلاحية' : 'Expiration Date' ?></label>
                    <input type="date" name="expiration_date" class="form-control" value="<?= htmlspecialchars($system_license['expiration_date'] ?? date('Y-m-d', strtotime('+1 year'))) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-foreground"><?= is_rtl() ? 'قفل النطاق المعتمد (Domain Lock)' : 'Authorized Domain / Host Lock' ?></label>
                    <input type="text" name="domain_lock" class="form-control font-monospace" value="<?= htmlspecialchars($system_license['domain_lock'] ?? '*') ?>" placeholder="* for wildcard, or localhost">
                    <div class="form-text text-muted-foreground small"><?= is_rtl() ? 'استخدم * للسماح بكافة الاستضافات والمضيفين المحليين.' : 'Use * to authorize all local development domains and production subdomains.' ?></div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold small text-foreground"><?= is_rtl() ? 'حالة الترخيص' : 'License Status' ?></label>
                    <select name="status" class="form-select">
                        <option value="Active" <?= ($system_license['status'] ?? '') === 'Active' ? 'selected' : '' ?>>Active (<?= is_rtl() ? 'نشط ومعتمد' : 'Authorized' ?>)</option>
                        <option value="Expired" <?= ($system_license['status'] ?? '') === 'Expired' ? 'selected' : '' ?>>Expired (<?= is_rtl() ? 'منتهي' : 'Expired' ?>)</option>
                        <option value="Suspended" <?= ($system_license['status'] ?? '') === 'Suspended' ? 'selected' : '' ?>>Suspended (<?= is_rtl() ? 'معلق مؤقتاً' : 'Suspended' ?>)</option>
                    </select>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3 mt-4">
                <button type="submit" class="btn btn-primary fw-bold px-4 py-2.5 rounded-lg shadow-xs">
                    <i class="fa-solid fa-shield-halved me-1"></i>
                    <span><?= is_rtl() ? 'حفظ وتفعيل الترخيص' : 'Save & Validate License' ?></span>
                </button>
                <a href="index.php" class="btn btn-outline-secondary px-3 py-2.5 rounded-lg">
                    <span><?= __('cancel') ?></span>
                </a>
            </div>
        </form>
    </div>

    <?= render_platform_footer() ?>
</div>

<script>
    const rawKey = <?= json_encode($system_license['license_key'] ?? '') ?>;
    const maskedKey = <?= json_encode($maskedKey) ?>;
    let isRevealed = false;

    function toggleLicKeyVisibility() {
        const el = document.getElementById('displayLicenseKey');
        const icon = document.getElementById('toggleIcon');
        if (!el || !icon) return;
        isRevealed = !isRevealed;
        el.textContent = isRevealed ? rawKey : maskedKey;
        icon.className = isRevealed ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
    }

    function copyLicKey() {
        if (!rawKey) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(rawKey).then(() => {
                showCopyFeedback();
            }).catch(() => {
                fallbackCopy(rawKey);
            });
        } else {
            fallbackCopy(rawKey);
        }
    }

    function fallbackCopy(text) {
        const input = document.createElement('input');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        showCopyFeedback();
    }

    function showCopyFeedback() {
        const msg = document.getElementById('copySuccessMsg');
        if (msg) {
            msg.classList.remove('d-none');
            setTimeout(() => msg.classList.add('d-none'), 2500);
        }
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
