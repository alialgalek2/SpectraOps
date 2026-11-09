<?php
require_once __DIR__ . '/../../core/init.php';

// Ensure user is logged in as admin or school admin
Auth::requireRole(['admin', 'school_admin']);

$msg = "";
$msg_type = "success";

// Handle key submission from client
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_key = trim($_POST['license_key'] ?? '');

    if (!empty($submitted_key)) {
        try {
            $chk = $pdo->query("SELECT license_id FROM system_license ORDER BY license_id DESC LIMIT 1")->fetch();
            if ($chk) {
                $stmt = $pdo->prepare("UPDATE system_license SET license_key = ?, status = 'Active', last_verified_at = NOW() WHERE license_id = ?");
                $stmt->execute([$submitted_key, $chk['license_id']]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO system_license (license_key, domain_lock, max_students, expiration_date, status, last_verified_at) VALUES (?, '*', 1000, DATE_ADD(CURRENT_DATE, INTERVAL 1 YEAR), 'Active', NOW())");
                $stmt->execute([$submitted_key]);
            }

            $msg = is_rtl() ? "تم تفعيل مفتاح الترخيص بنجاح! جاري التحقق من المنظومة..." : "Commercial license key activated successfully! System re-validated.";
            $msg_type = "success";
        } catch (Exception $e) {
            $msg = "Error saving license key: " . $e->getMessage();
            $msg_type = "danger";
        }
    } else {
        $msg = is_rtl() ? "يرجى إدخال مفتاح ترخيص صالح." : "Please enter a valid license key.";
        $msg_type = "danger";
    }
}

require_once __DIR__ . '/../../licensing/license_helper.php';
$licCheck = checkSystemLicense($pdo);
$currLic = $pdo->query("SELECT * FROM system_license ORDER BY license_id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'تفعيل ترخيص النظام' : 'System License Activation' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-background d-flex align-items-center justify-content-center min-vh-100 p-3">
<div class="card p-4 shadow-lg border border-border bg-card rounded-xl" style="max-width: 480px; width: 100%;">
    <div class="d-flex align-items-center gap-3 mb-3">
        <div class="action-icon-box icon-box-warning">
            <i class="fa-solid fa-key fs-5"></i>
        </div>
        <div>
            <h4 class="fw-bold font-heading mb-0 text-foreground"><?= is_rtl() ? 'تفعيل ترخيص المنظومة' : 'Commercial Licensing' ?></h4>
            <small class="text-muted-foreground"><?= is_rtl() ? 'إدارة مفاتيح التشغيل المعتمدة' : 'Official System Authorization Desk' ?></small>
        </div>
    </div>

    <p class="text-muted-foreground small mb-3">
        <?= is_rtl() 
            ? 'أدخل مفتاح الترخيص التجاري الصادر لتفعيل ميزات المنظومة وزيادة سعة الطلاب.' 
            : 'Enter the commercial license key provided by Algale Tech to activate full system capabilities.' ?>
    </p>

    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> py-2 small fw-bold shadow-xs mb-3">
            <i class="fa-solid <?= $msg_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?> me-1"></i>
            <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>

    <div class="mb-3 p-3 rounded-lg border border-border bg-secondary-subtle">
        <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small text-muted-foreground uppercase fw-bold" style="font-size: 0.72rem;">
                <?= is_rtl() ? 'الحالة الحالية' : 'Current Status' ?>
            </span>
            <span class="badge <?= ($licCheck['valid'] ?? false) ? 'bg-success' : 'bg-danger' ?>">
                <i class="fa-solid <?= ($licCheck['valid'] ?? false) ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> me-1"></i>
                <?= ($licCheck['valid'] ?? false) ? (is_rtl() ? 'نشط ومعتمد' : 'Active & Valid') : (is_rtl() ? 'غير صالح / منتهي' : 'Inactive / Invalid') ?>
            </span>
        </div>
        <div class="d-flex justify-content-between align-items-center small text-muted-foreground mt-2">
            <span><?= is_rtl() ? 'المفتاح النشط:' : 'Active Key:' ?></span>
            <span class="font-monospace fw-bold text-foreground">
                <?= htmlspecialchars($currLic['license_key'] ?? 'N/A') ?>
            </span>
        </div>
        <?php if (!empty($currLic['expiration_date'])): ?>
            <div class="d-flex justify-content-between align-items-center small text-muted-foreground mt-1">
                <span><?= is_rtl() ? 'تاريخ الانتهاء:' : 'Expiration:' ?></span>
                <span class="fw-medium text-foreground"><?= htmlspecialchars($currLic['expiration_date']) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label small fw-bold text-foreground"><?= is_rtl() ? 'لصق مفتاح الترخيص:' : 'Paste License Key:' ?></label>
            <input type="text" name="license_key" class="form-control font-monospace" placeholder="ALGALE-XXXX-XXXX-XXXX" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-xs">
            <i class="fa-solid fa-shield-halved me-1"></i> <?= is_rtl() ? 'تفعيل الترخيص الآن' : 'Activate License Key' ?>
        </button>
    </form>

    <div class="text-center mt-3 pt-3 border-top border-border">
        <a href="license_details.php" class="small text-decoration-none text-primary fw-medium">
            <i class="fa-solid fa-arrow-left me-1"></i> <?= is_rtl() ? 'العودة لمركز تحكم الإدارة (تفاصيل الترخيص)' : 'Back to Admin Control Center' ?>
        </a>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
