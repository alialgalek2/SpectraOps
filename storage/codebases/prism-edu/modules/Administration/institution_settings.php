<?php
/**
 * Administration Module - Institution Profile, Multi-Campus & Operational Settings
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin']);

$success = "";
$error = "";

// Handle Campus Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_campus') {
    $c_name = trim($_POST['campus_name'] ?? '');
    $c_code = strtoupper(trim($_POST['code'] ?? ''));
    $c_addr = trim($_POST['address'] ?? '');

    if (!empty($c_name) && !empty($c_code)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO campuses (campus_name, code, address, status) VALUES (?, ?, ?, 'Active')");
            $stmt->execute([$c_name, $c_code, $c_addr]);
            $success = is_rtl() ? "تمت إضافة الفرع بنجاح!" : "New campus branch added successfully!";
        } catch (\PDOException $e) {
            $error = "Error adding campus: " . $e->getMessage();
        }
    }
}

// Handle Vendor Support Generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_vendor_token') {
    $vendorName = trim($_POST['vendor_name'] ?? '');
    $durationHours = (int)($_POST['duration_hours'] ?? 24);
    
    if (!empty($vendorName)) {
        try {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + ($durationHours * 3600));
            $stmt = $pdo->prepare("INSERT INTO vendor_support_grants (vendor_name, access_token, expires_at, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$vendorName, $token, $expiresAt, Auth::id()]);
            $success = is_rtl() ? "تم إنشاء تصريح الدعم بنجاح!" : "Vendor support token generated successfully!";
        } catch (\Exception $e) {
            $error = "Error generating token: " . $e->getMessage();
        }
    }
}

// Handle Vendor Support Revocation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revoke_vendor_token') {
    $grantId = (int)($_POST['grant_id'] ?? 0);
    if ($grantId > 0) {
        try {
            $stmt = $pdo->prepare("UPDATE vendor_support_grants SET is_active = 0 WHERE grant_id = ?");
            $stmt->execute([$grantId]);
            $success = is_rtl() ? "تم إلغاء التصريح بنجاح!" : "Vendor support token revoked successfully!";
        } catch (\Exception $e) {
            $error = "Error revoking token: " . $e->getMessage();
        }
    }
}

// Handle Institution Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_branding'])) {
    try {
        $school_name      = trim($_POST['school_name'] ?? '');
        $registration_no  = trim($_POST['registration_no'] ?? '');
        $accreditation    = trim($_POST['accreditation_seal'] ?? '');
        $school_phone     = trim($_POST['school_phone'] ?? '');
        $school_email     = trim($_POST['school_email'] ?? '');
        $school_website   = trim($_POST['school_website'] ?? '');
        $school_address   = trim($_POST['school_address'] ?? '');
        $academic_year    = trim($_POST['academic_year'] ?? '2025/2026');
        $default_currency = trim($_POST['default_currency'] ?? 'USD');
        $operating_hours  = trim($_POST['operating_hours'] ?? '07:30 - 15:30');
        $timezone         = trim($_POST['timezone'] ?? 'Africa/Cairo');
        $working_days     = isset($_POST['working_days']) && is_array($_POST['working_days']) 
            ? json_encode($_POST['working_days']) 
            : json_encode(['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday']);

        $logo_path = null;
        if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = BASE_PATH . '/uploads/branding/';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0777, true);
            }
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $file_type = mime_content_type($_FILES['school_logo']['tmp_name']);
            if (in_array($file_type, $allowed_types)) {
                $fileExt = strtolower(pathinfo($_FILES['school_logo']['name'], PATHINFO_EXTENSION));
                $fileName = 'logo_' . time() . '.' . $fileExt;
                $destPath = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $destPath)) {
                    $logo_path = 'uploads/branding/' . $fileName;
                }
            }
        }

        $current = $pdo->query("SELECT * FROM institution_branding LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $finalLogo = $logo_path ?: ($current['logo_path'] ?? null);

        if ($current) {
            $stmt = $pdo->prepare("
                UPDATE institution_branding 
                SET school_name = ?, registration_no = ?, accreditation_seal = ?, default_currency = ?, 
                    working_days_json = ?, operating_hours = ?, timezone = ?, school_email = ?, 
                    school_phone = ?, school_website = ?, school_address = ?, logo_path = ? 
                WHERE branding_id = ?
            ");
            $stmt->execute([
                $school_name, $registration_no, $accreditation, $default_currency,
                $working_days, $operating_hours, $timezone, $school_email,
                $school_phone, $school_website, $school_address, $finalLogo, $current['branding_id']
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO institution_branding 
                (school_name, registration_no, accreditation_seal, default_currency, working_days_json, operating_hours, timezone, school_email, school_phone, school_website, school_address, logo_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $school_name, $registration_no, $accreditation, $default_currency,
                $working_days, $operating_hours, $timezone, $school_email,
                $school_phone, $school_website, $school_address, $finalLogo
            ]);
        }

        // Legacy settings sync
        $leg = [
            'school_name'      => $school_name,
            'registration_no'  => $registration_no,
            'school_phone'     => $school_phone,
            'school_email'     => $school_email,
            'school_website'   => $school_website,
            'school_address'   => $school_address,
            'academic_year'    => $academic_year,
            'current_session'  => $academic_year,
            'default_currency' => $default_currency,
            'operating_hours'  => $operating_hours,
            'timezone'         => $timezone
        ];
        if ($finalLogo) {
            $leg['school_logo'] = $finalLogo;
        }
        $stmtLeg = $pdo->prepare("INSERT INTO school_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($leg as $k => $v) {
            $stmtLeg->execute([$k, $v]);
        }

        $success = is_rtl() ? "تم حفظ تفاصيل الهوية المؤسسية وإعدادات التشغيل بنجاح!" : "Institution profile & operational settings updated successfully!";
    } catch (\Exception $e) {
        $error = "Update Error: " . $e->getMessage();
    }
}

// Fetch current branding & merge with global branding
$bStmt = $pdo->query("SELECT * FROM institution_branding ORDER BY branding_id DESC LIMIT 1");
$bData = $bStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$current_branding = array_merge($global_branding, $bData);

$current_working_days = !empty($current_branding['working_days_json']) 
    ? json_decode($current_branding['working_days_json'], true) 
    : ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
if (!is_array($current_working_days)) {
    $current_working_days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday'];
}

// Fetch Campuses
$campuses = [];
try {
    $campuses = $pdo->query("SELECT * FROM campuses ORDER BY campus_id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Fetch Active Vendor Grants
$vendorGrants = [];
try {
    $vendorGrants = $pdo->query("SELECT * FROM vendor_support_grants WHERE is_active = 1 AND expires_at > NOW() ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'تخصيص هوية المؤسسة والفروع' : 'Institution & Campus Configuration' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
</head>
<body class="bg-background text-foreground">

    <!-- Universal Top Navigation -->
    <?= render_universal_navbar([
        'active_title' => is_rtl() ? 'هوية المؤسسة وإعدادات الفروع' : 'Institution & Multi-Campus Setup',
        'back_url' => 'index.php',
        'extra_nav_items' => [
            '<a href="academic_hierarchy.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sitemap me-1 text-primary"></i> ' . (is_rtl() ? 'الهيكل الأكاديمي' : 'Hierarchy') . '</a>',
            '<a href="academic_calendar.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-calendar-days me-1 text-purple"></i> ' . (is_rtl() ? 'التقويم' : 'Calendar') . '</a>'
        ]
    ]) ?>

    <div class="container-fluid px-3 px-md-4 py-4">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
            <div>
                <h3 class="fw-bold font-heading mb-1 text-foreground">
                    <i class="fa-solid fa-school text-primary me-2"></i><?= is_rtl() ? 'الإعدادات المؤسسية والفروع المعتمدة' : 'Institutional Setup & Multi-Campus Management' ?>
                </h3>
                <p class="text-muted-foreground small mb-0">
                    <?= is_rtl() ? 'إدارة الهوية الرسمية، أختام الاعتماد، العملة، ساعات وأيام الدوام، والفروع الجغرافية.' : 'Configure master branding, accreditation seals, operating schedule, currency, and physical campuses.' ?>
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-primary btn-sm rounded-lg" data-bs-toggle="modal" data-bs-target="#addCampusModal">
                    <i class="fa-solid fa-plus me-1"></i><?= is_rtl() ? 'إضافة فرع مدرسي' : 'Add Campus Branch' ?>
                </button>
            </div>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-circle-check me-2 fs-5"></i>
                <div><?= htmlspecialchars($success) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center mb-4" role="alert">
                <i class="fa-solid fa-triangle-exclamation me-2 fs-5"></i>
                <div><?= htmlspecialchars($error) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- Left Column: Master Institution Profile Form -->
            <div class="col-lg-8">
                <div class="card bg-card border border-border rounded-xl shadow-sm">
                    <div class="card-header bg-card border-bottom border-border py-3">
                        <h5 class="fw-bold font-heading mb-0 text-foreground">
                            <i class="fa-solid fa-sliders me-2 text-primary"></i><?= is_rtl() ? 'بيانات الهوية والاعتماد' : 'Institutional Identity & Governance' ?>
                        </h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST" enctype="multipart/form-data">
                            
                            <!-- Logo Section -->
                            <div class="row g-4 align-items-center mb-4 pb-3 border-bottom border-border">
                                <div class="col-md-3 text-center">
                                    <div class="border border-border rounded-xl p-3 bg-secondary shadow-xs">
                                        <?php if (!empty($current_branding['logo_path']) && file_exists(BASE_PATH . '/' . $current_branding['logo_path'])): ?>
                                            <img src="<?= BASE_URL . '/' . htmlspecialchars($current_branding['logo_path']) ?>" alt="School Logo" class="img-fluid mb-2 rounded-md" style="max-height: 75px; object-fit: contain;">
                                        <?php else: ?>
                                            <div class="avatar-badge avatar-badge-lg font-heading mx-auto mb-2" style="background-color: var(--primary); color: #fff; width: 65px; height: 65px; font-size: 1.75rem; display: flex; align-items: center; justify-content: center; border-radius: 12px;">
                                                <?= mb_substr($current_branding['school_name'] ?? 'S', 0, 1) ?>
                                            </div>
                                        <?php endif; ?>
                                        <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'الشعار المعتمد' : 'Official Seal' ?></p>
                                    </div>
                                </div>
                                <div class="col-md-9">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'تحديث الشعار والختم المؤسسي' : 'Update Official Institution Logo' ?></label>
                                    <input type="file" name="school_logo" class="form-control form-control-sm bg-background border-border text-foreground" accept="image/png, image/jpeg, image/webp, image/gif">
                                    <div class="form-text text-muted-foreground small">
                                        <?= is_rtl() ? 'يُستخدم هذا الشعار في الشهادات، إيصالات الدفع، والتقارير الرسمية.' : 'Rendered dynamically on official report cards, fee invoices, transcripts and public credentials.' ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Basic Information -->
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'اسم المؤسسة الرسمي' : 'Official Institution Name' ?></label>
                                    <input type="text" name="school_name" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['school_name'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'رقم التسجيل الوزاري / الرخصة' : 'Official Registration / License No' ?></label>
                                    <input type="text" name="registration_no" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['registration_no'] ?? 'REG-2026-EG-8841') ?>" placeholder="e.g. MOE-EDU-99482">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'ختم الاعتماد الدولي' : 'Accreditation Body / Seal' ?></label>
                                    <input type="text" name="accreditation_seal" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['accreditation_seal'] ?? 'Cognia / AdvancED International Accredited') ?>" placeholder="e.g. Cognia, Cambridge, IB World School">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'العملة الافتراضية للنظام' : 'Default Billing Currency' ?></label>
                                    <input type="text" name="default_currency" class="form-control bg-background border-border text-foreground font-monospace" value="<?= htmlspecialchars($current_branding['default_currency'] ?? 'USD') ?>" placeholder="USD, EGP, SAR, AED">
                                </div>
                            </div>

                            <!-- Operational Schedule & Timezone -->
                            <h6 class="fw-bold text-foreground border-bottom border-border pb-2 mb-3">
                                <i class="fa-solid fa-clock me-1 text-info"></i><?= is_rtl() ? 'إطار العمل وساعات التشغيل' : 'Operating Framework & Schedule' ?>
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'ساعات العمل الرسمية اليومية' : 'Official Daily Operating Hours' ?></label>
                                    <input type="text" name="operating_hours" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['operating_hours'] ?? '07:30 - 15:30') ?>" placeholder="07:30 - 15:30">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'المنطقة الزمنية للنظام' : 'System Timezone' ?></label>
                                    <select name="timezone" class="form-select bg-background border-border text-foreground">
                                        <?php
                                        $timezones = ['Africa/Cairo', 'Asia/Riyadh', 'Asia/Dubai', 'UTC', 'Europe/London', 'America/New_York'];
                                        $currTz = $current_branding['timezone'] ?? 'Africa/Cairo';
                                        foreach ($timezones as $tz): ?>
                                            <option value="<?= $tz ?>" <?= $currTz === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold small text-muted-foreground mb-2"><?= is_rtl() ? 'أيام العمل الرسمية (الأسبوع الدراسي)' : 'Standard Working Days (Academic Week)' ?></label>
                                    <div class="d-flex flex-wrap gap-3">
                                        <?php
                                        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                        foreach ($weekdays as $day):
                                            $checked = in_array($day, $current_working_days) ? 'checked' : '';
                                        ?>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="working_days[]" value="<?= $day ?>" id="day_<?= $day ?>" <?= $checked ?>>
                                                <label class="form-check-label small" for="day_<?= $day ?>"><?= $day ?></label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Contact & Address Details -->
                            <h6 class="fw-bold text-foreground border-bottom border-border pb-2 mb-3">
                                <i class="fa-solid fa-address-book me-1 text-success"></i><?= is_rtl() ? 'بيانات التواصل والعنوان' : 'Contact & Campus Headquarters' ?>
                            </h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'رقم الهاتف' : 'Contact Phone' ?></label>
                                    <input type="text" name="school_phone" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['school_phone'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'البريد الرسمي' : 'Official Email' ?></label>
                                    <input type="email" name="school_email" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['school_email'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'الموقع الإلكتروني' : 'School Website' ?></label>
                                    <input type="url" name="school_website" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['school_website'] ?? '') ?>" placeholder="https://example.edu">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'العنوان الجغرافي للمقر الرئيسي' : 'Headquarters Address' ?></label>
                                    <input type="text" name="school_address" class="form-control bg-background border-border text-foreground" value="<?= htmlspecialchars($current_branding['school_address'] ?? '') ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold small text-muted-foreground"><?= is_rtl() ? 'العام الدراسي النشط' : 'Active Academic Year' ?></label>
                                    <input type="text" name="academic_year" class="form-control bg-background border-border text-foreground font-monospace" value="<?= htmlspecialchars($current_branding['academic_year'] ?? '2025/2026') ?>" required>
                                </div>
                            </div>

                            <div class="d-flex align-items-center gap-3 mt-4 pt-3 border-top border-border">
                                <button type="submit" name="update_branding" class="btn btn-primary fw-bold px-4">
                                    <i class="fa-solid fa-floppy-disk me-1"></i> <?= is_rtl() ? 'حفظ إعدادات الهوية المؤسسية' : 'Save Institutional Profile' ?>
                                </button>
                                <a href="index.php" class="btn btn-outline-border px-3">
                                    <?= is_rtl() ? 'رجوع للوحة التحكم' : 'Cancel' ?>
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column: Campus Branches Registry -->
            <div class="col-lg-4">
                <div class="card bg-card border border-border rounded-xl shadow-sm mb-4">
                    <div class="card-header bg-card border-bottom border-border py-3 d-flex justify-content-between align-items-center">
                        <h6 class="fw-bold font-heading mb-0 text-foreground">
                            <i class="fa-solid fa-city me-2 text-info"></i><?= is_rtl() ? 'الفروع المدرسية المعتمدة' : 'Registered Campuses' ?>
                        </h6>
                        <span class="badge bg-info-subtle text-info rounded-pill"><?= count($campuses) ?> <?= is_rtl() ? 'فرع' : 'Branches' ?></span>
                    </div>
                    <div class="card-body p-3">
                        <?php if (empty($campuses)): ?>
                            <div class="text-center py-4 text-muted-foreground small">
                                <?= is_rtl() ? 'لا توجد فروع مسجلة حالياً. الفرع الرئيسي افتراضي.' : 'No multi-campus branches registered yet.' ?>
                            </div>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($campuses as $c): ?>
                                    <div class="p-3 border border-border rounded-xl bg-background shadow-xs">
                                        <div class="d-flex justify-content-between align-items-start mb-1">
                                            <div class="fw-bold text-foreground"><?= htmlspecialchars($c['campus_name']) ?></div>
                                            <span class="badge bg-primary-subtle text-primary font-monospace"><?= htmlspecialchars($c['code']) ?></span>
                                        </div>
                                        <div class="small text-muted-foreground mb-2">
                                            <i class="fa-solid fa-location-dot me-1 text-danger"></i><?= htmlspecialchars($c['address'] ?? 'General Campus') ?>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center small pt-2 border-top border-border">
                                            <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($c['status']) ?></span>
                                            <span class="text-muted-foreground font-monospace" style="font-size: 0.75rem;">ID #<?= $c['campus_id'] ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Software Updates Card (Spectra Ops In-Place Updater) -->
                <style>
                    .hidden { display: none !important; }
                </style>
                <div class="card p-4 rounded-xl border border-cyan-500/20 bg-[#091830] text-[#eefdff] mb-4 shadow-sm">
                    <div class="d-flex flex-wrap align-items-center justify-content-between mb-4 gap-2">
                        <div>
                            <h4 class="text-lg font-bold text-[#bef6fd] mb-1">Software Updates</h4>
                            <p class="text-sm text-[#92c6de] mb-0">Current Version: <code class="text-white">v<?= htmlspecialchars(getenv('APP_VERSION') ?: '2.5.0') ?></code></p>
                        </div>
                        <button id="btnCheckUpdate" onclick="runUpdateCheck()" class="px-4 py-2 bg-[#0dc0e7] text-[#091830] font-bold rounded-lg hover:bg-[#bef6fd] transition border-0">
                            Check for Updates
                        </button>
                    </div>

                    <!-- Dynamic Update Banner -->
                    <div id="updateResultArea" class="hidden mt-4 p-4 rounded-lg bg-[#133f83] border border-cyan-500/40">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div>
                                <span class="text-xs font-bold uppercase tracking-wider bg-emerald-500/20 text-emerald-300 px-2 py-1 rounded">New Release Available</span>
                                <h5 id="newVersionTag" class="text-base font-bold mt-1 text-white mb-1"></h5>
                                <p id="changelogText" class="text-sm text-[#92c6de] mt-1 mb-0"></p>
                            </div>
                            <button id="btnApplyUpdate" onclick="applySystemUpdate()" class="px-4 py-2 bg-emerald-400 text-slate-900 font-extrabold rounded-lg hover:bg-emerald-300 border-0">
                                Install Update Now
                            </button>
                        </div>
                        <div id="updateProgressText" class="hidden text-xs text-cyan-200 mt-2 animate-pulse">
                            Downloading update archive, verifying SHA-256 hash, and extracting files... Do not close this tab.
                        </div>
                    </div>
                </div>

                <script>
                let pendingUpdateData = null;

                async function runUpdateCheck() {
                    const btn = document.getElementById('btnCheckUpdate');
                    btn.disabled = true;
                    btn.innerText = 'Checking...';

                    try {
                        const apiEndpoint = (window.location.pathname.includes('/school_system/') ? '/school_system' : '') + '/api/admin/update.php?action=check';
                        const res = await fetch(apiEndpoint);
                        const data = await res.json();

                        if (data.status === 'update_available') {
                            pendingUpdateData = data;
                            document.getElementById('newVersionTag').innerText = 'Version ' + data.latest_version;
                            document.getElementById('changelogText').innerText = data.changelog || 'Performance improvements and bug fixes.';
                            document.getElementById('updateResultArea').classList.remove('hidden');
                        } else {
                            alert(data.message || 'System is already running the latest version.');
                        }
                    } catch (e) {
                        alert('Could not connect to Spectra Ops update service.');
                    } finally {
                        btn.disabled = false;
                        btn.innerText = 'Check for Updates';
                    }
                }

                async function applySystemUpdate() {
                    if (!pendingUpdateData || !confirm(`Apply update ${pendingUpdateData.latest_version}? The system will reload when complete.`)) return;

                    const btn = document.getElementById('btnApplyUpdate');
                    const progress = document.getElementById('updateProgressText');
                    btn.disabled = true;
                    progress.classList.remove('hidden');

                    try {
                        const apiEndpoint = (window.location.pathname.includes('/school_system/') ? '/school_system' : '') + '/api/admin/update.php?action=apply';
                        const res = await fetch(apiEndpoint, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                download_url: pendingUpdateData.download_url,
                                checksum:     pendingUpdateData.checksum,
                                new_version:  pendingUpdateData.latest_version
                            })
                        });

                        const result = await res.json();
                        if (result.success) {
                            alert(result.message);
                            window.location.reload();
                        } else {
                            alert('Update failed: ' + result.error);
                        }
                    } catch (e) {
                        alert('Network interrupted while applying the update.');
                    } finally {
                        btn.disabled = false;
                        progress.classList.add('hidden');
                    }
                }
                </script>

                <!-- Fast Info Widget -->
                <div class="card bg-card border border-border rounded-xl shadow-sm p-3 mb-4">
                    <div class="d-flex align-items-center gap-3">
                        <div class="action-icon-box icon-box-purple">
                            <i class="fa-solid fa-sitemap"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-foreground"><?= is_rtl() ? 'الهيكل الأكاديمي' : 'Academic Hierarchy' ?></div>
                            <div class="small text-muted-foreground"><?= is_rtl() ? 'ربط المراحل، الصفوف والسعة الاستيعابية' : 'Stages, grades & intake capacities' ?></div>
                        </div>
                    </div>
                    <a href="academic_hierarchy.php" class="btn btn-outline-border btn-sm mt-3 w-100 rounded-lg">
                        <i class="fa-solid fa-arrow-right me-1"></i><?= is_rtl() ? 'فتح محرك الهيكل الأكاديمي' : 'Open Hierarchy Engine' ?>
                    </a>
                </div>

                <!-- Vendor Support Widget -->
                <div class="card bg-card border border-border rounded-xl shadow-sm p-3">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="action-icon-box icon-box-danger">
                            <i class="fa-solid fa-headset"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-foreground"><?= is_rtl() ? 'وصول دعم الموردين' : 'Vendor Support Access' ?></div>
                            <div class="small text-muted-foreground"><?= is_rtl() ? 'توليد رموز دخول مؤقتة' : 'Generate temporary access tokens' ?></div>
                        </div>
                    </div>
                    
                    <form method="POST" class="mb-3 border-bottom pb-3 border-border">
                        <input type="hidden" name="action" value="generate_vendor_token">
                        <div class="mb-2">
                            <input type="text" name="vendor_name" class="form-control form-control-sm bg-background border-border text-foreground" placeholder="<?= is_rtl() ? 'اسم المورد / الشركة' : 'Vendor/Company Name' ?>" required>
                        </div>
                        <div class="mb-2">
                            <select name="duration_hours" class="form-select form-select-sm bg-background border-border text-foreground">
                                <option value="2">2 <?= is_rtl() ? 'ساعات' : 'Hours' ?></option>
                                <option value="24">24 <?= is_rtl() ? 'ساعة' : 'Hours' ?></option>
                                <option value="48">48 <?= is_rtl() ? 'ساعة' : 'Hours' ?></option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100 rounded-lg">
                            <i class="fa-solid fa-key me-1"></i><?= is_rtl() ? 'توليد رمز الدخول' : 'Generate Access Token' ?>
                        </button>
                    </form>

                    <?php if (!empty($vendorGrants)): ?>
                        <div class="small fw-bold text-muted-foreground mb-2"><?= is_rtl() ? 'التصاريح النشطة' : 'Active Grants' ?></div>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($vendorGrants as $grant): ?>
                                <div class="p-2 border border-border rounded bg-background shadow-xs d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="small fw-bold text-foreground"><?= htmlspecialchars($grant['vendor_name']) ?></div>
                                        <div class="text-muted-foreground font-monospace" style="font-size: 0.7rem;">Exp: <?= htmlspecialchars($grant['expires_at']) ?></div>
                                    </div>
                                    <form method="POST" class="m-0 p-0">
                                        <input type="hidden" name="action" value="revoke_vendor_token">
                                        <input type="hidden" name="grant_id" value="<?= $grant['grant_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size: 0.75rem;" onclick="return confirm('<?= is_rtl() ? 'هل أنت متأكد؟' : 'Are you sure?' ?>')">Revoke</button>
                                    </form>
                                </div>
                                <div class="px-2 pb-2 small font-monospace text-primary" style="word-break: break-all; font-size: 0.7rem; margin-top: -5px;">
                                    <?= htmlspecialchars($grant['access_token']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center small text-muted-foreground">
                            <?= is_rtl() ? 'لا توجد تصاريح نشطة' : 'No active grants' ?>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <?= render_platform_footer() ?>
    </div>

    <!-- Modal: Add Campus -->
    <div class="modal fade" id="addCampusModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-card border-border">
                <form method="POST">
                    <input type="hidden" name="action" value="add_campus">
                    <div class="modal-header border-border">
                        <h5 class="modal-title font-heading fw-bold text-foreground">
                            <i class="fa-solid fa-city me-2 text-primary"></i><?= is_rtl() ? 'إضافة فرع / مجمع مدرسي' : 'Add Campus Branch' ?>
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'اسم الفرع' : 'Campus Name' ?></label>
                            <input type="text" name="campus_name" class="form-control bg-background border-border text-foreground" placeholder="e.g. West Cairo International Campus" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'كود الفرع المختصر' : 'Campus Code' ?></label>
                            <input type="text" name="code" class="form-control bg-background border-border text-foreground font-monospace" placeholder="e.g. WC-01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label small fw-bold text-muted-foreground"><?= is_rtl() ? 'العنوان وتفاصيل الموقع' : 'Address & Location Details' ?></label>
                            <textarea name="address" class="form-control bg-background border-border text-foreground" rows="3" placeholder="Street, District, City"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-border">
                        <button type="button" class="btn btn-outline-border" data-bs-dismiss="modal"><?= is_rtl() ? 'إلغاء' : 'Cancel' ?></button>
                        <button type="submit" class="btn btn-primary fw-bold"><i class="fa-solid fa-check me-1"></i><?= is_rtl() ? 'حفظ الفرع' : 'Save Campus' ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>