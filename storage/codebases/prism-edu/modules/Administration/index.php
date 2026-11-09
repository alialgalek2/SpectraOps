<?php
/**
 * Administration Module - Main Control Center & Operational Hub
 * (Core Umbrella Architecture)
 * 6 Core Operational Desks:
 * 1. Institutional Setup, Academic Calendar & Structural Hierarchy
 * 2. Student Master Registry & Admissions Lifecycle
 * 3. Parent / Guardian Mapping & Household Relationships
 * 4. Daily Attendance, Discipline & Behavioral Conduct
 * 5. Role-Based Access Control (RBAC), Security & System Audit Logs
 * 6. Academic Year Rollover, Promotion & Alumni Archiving
 */

require_once __DIR__ . '/../../core/init.php';

Auth::requireRole(['admin', 'school_admin']);

$user_role = Auth::role();
$user_id   = Auth::id();
$full_name = Auth::fullName();

// Live Summary Metrics
$total_students = 0;
$total_classes  = 0;
$total_apps     = 0;
$total_incidents= 0;
$total_audit    = 0;
$total_alumni   = 0;
$total_users    = 0;

try {
    $total_students  = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE is_deleted = 0 AND (enrollment_status = 'Active' OR enrollment_status IS NULL)")->fetchColumn();
    $total_classes   = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
    $total_apps      = (int)$pdo->query("SELECT COUNT(*) FROM student_applications WHERE status NOT IN ('Enrolled', 'Rejected')")->fetchColumn();
    $total_incidents = (int)$pdo->query("SELECT COUNT(*) FROM student_behavior_records WHERE status IN ('Reported', 'Under Review')")->fetchColumn();
    $total_audit     = (int)$pdo->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();
    $total_alumni    = (int)$pdo->query("SELECT COUNT(*) FROM students WHERE enrollment_status IN ('Graduated', 'Withdrawn')")->fetchColumn();
    $total_users     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'Active'")->fetchColumn();
} catch (\Throwable $e) {}

// License details
require_once __DIR__ . '/../../licensing/license_helper.php';
$system_license_check = checkSystemLicense($pdo);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'مركز الإدارة العامة والتحكم المؤسسي' : 'Admin Control Center' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .admin-action-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-xl, 1rem);
            background: var(--card);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .admin-action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.08);
            border-color: var(--primary) !important;
            color: inherit;
        }
        .stat-card-clean:hover {
            transform: translateY(-2px);
            transition: transform 0.2s ease;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar with Users, Admissions, Classes Prominently Pinned -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'مركز الإدارة والتحكم المؤسسي' : 'Admin Control Center',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => [
        '<a href="users.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-users-gear me-1 text-primary"></i> ' . (is_rtl() ? 'المستخدمون' : 'Users') . '</a>',
        '<a href="student_admission.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-user-plus me-1 text-success"></i> ' . (is_rtl() ? 'القبول' : 'Admissions') . '</a>',
        '<a href="classes.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-door-open me-1 text-info"></i> ' . (is_rtl() ? 'الفصول' : 'Classes') . '</a>',
        '<a href="academic_hierarchy.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-sitemap me-1 text-purple"></i> ' . (is_rtl() ? 'الهيكل' : 'Hierarchy') . '</a>',
        '<a href="households.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-people-roof me-1 text-primary"></i> ' . (is_rtl() ? 'العائلات' : 'Households') . '</a>',
        '<a href="rollover.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-arrows-split-up-and-left me-1 text-warning"></i> ' . (is_rtl() ? 'الترحيل' : 'Rollover') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-sliders text-primary me-2"></i><?= is_rtl() ? 'مركز الإدارة العامة والتحكم المؤسسي' : 'Institutional Administration & Control Hub' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'إدارة المستخدمين والصلاحيات، قبول الطلاب، الفصول والشعب، الهيكل الأكاديمي، الأمان والتدقيق، والترحيل السنوي.' : 'User account governance, student admissions, class sections, institutional hierarchy, security audit, and year rollover.' ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="license_details.php" class="badge bg-card border border-border text-foreground px-3 py-2 fs-6 shadow-sm rounded-xl text-decoration-none">
                <i class="fa-solid fa-certificate <?= ($system_license_check['valid'] ?? false) ? 'text-warning' : 'text-danger' ?> me-1"></i>
                <?= ($system_license_check['valid'] ?? false) ? (is_rtl() ? 'الترخيص: نشط' : 'Commercial Active') : (is_rtl() ? 'بحاجة لتجديد' : 'Needs Renewal') ?>
            </a>
            <span class="badge bg-card border border-border text-foreground px-3 py-2 fs-6 shadow-sm rounded-xl">
                <i class="fa-solid fa-user-circle text-primary me-1"></i> <?= htmlspecialchars($full_name) ?>
            </span>
        </div>
    </div>

    <!-- Live Metrics Overview Grid (Status Cards) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-xl-2">
            <a href="users.php" class="text-decoration-none">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-users-gear"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($total_users) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'المستخدمون' : 'Active Users' ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="student_dossier.php" class="text-decoration-none">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-user-graduate"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($total_students) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'الطلاب المقيدون' : 'Enrolled Students' ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="classes.php" class="text-decoration-none">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-door-open"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_classes ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'الفصول والشعب' : 'Class Sections' ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="student_admission.php" class="text-decoration-none">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_apps ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'طلبات قيد القبول' : 'Intake Pipeline' ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="security_audit.php" class="text-decoration-none">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= number_format($total_audit) ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'أحداث التدقيق' : 'Audit Events' ?></div>
                    </div>
                </div>
            </a>
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <a href="rollover.php" class="text-decoration-none">
                <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                    <div class="action-icon-box icon-box-secondary me-3">
                        <i class="fa-solid fa-graduation-cap"></i>
                    </div>
                    <div>
                        <div class="fs-4 fw-bold text-foreground"><?= $total_alumni ?></div>
                        <div class="small text-muted-foreground"><?= is_rtl() ? 'سجل الخريجين' : 'Alumni Archive' ?></div>
                    </div>
                </div>
            </a>
        </div>
    </div>

    <!-- PRIMARY CORE MANAGEMENT DESK: USERS | ADMISSIONS | CLASSES -->
    <div class="mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold text-foreground mb-0">
                <i class="fa-solid fa-id-card-clip me-2 text-primary"></i><?= is_rtl() ? 'إدارة المستخدمين والقبول والشعب الدراسية' : 'Core Identity, Admissions & Section Roster' ?>
            </h5>
            <span class="badge bg-secondary text-secondary-foreground border border-border small">
                <?= is_rtl() ? 'الخدمات الأساسية ذات الأولوية' : 'Primary Core Hub' ?>
            </span>
        </div>

        <div class="row g-3">
            <!-- 1. USERS -->
            <div class="col-lg-4 col-md-6">
                <a href="users.php" class="admin-action-card p-3 p-xl-4 h-100 border border-primary border-opacity-25 bg-card shadow-sm rounded-xl">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="action-icon-box icon-box-primary">
                                <i class="fa-solid fa-users-gear fs-5"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold font-heading mb-0 text-foreground">
                                    <?= is_rtl() ? 'إدارة المستخدمين' : 'Users & Accounts' ?>
                                </h5>
                            </div>
                        </div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2.5 py-1 rounded-pill small">
                            <?= number_format($total_users) ?> <?= is_rtl() ? 'حساب نشط' : 'Active Users' ?>
                        </span>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'إدارة حسابات الإداريين والمعلمين والطلاب وأولياء الأمور، ضبط كلمات المرور، تفعيل وتعطيل الحسابات، وصلاحيات الدخول.' : 'Central user registry across all 8 roles. Manage login credentials, status toggles, and account provisioning.' ?>
                    </p>
                    <div class="d-flex align-items-center text-primary fw-semibold small pt-2 border-top border-border">
                        <span><?= is_rtl() ? 'فتح إدارة المستخدمين' : 'Manage User Accounts' ?></span>
                        <i class="fa-solid <?= is_rtl() ? 'fa-arrow-left me-2' : 'fa-arrow-right ms-2' ?>"></i>
                    </div>
                </a>
            </div>

            <!-- 2. ADMISSIONS -->
            <div class="col-lg-4 col-md-6">
                <a href="student_admission.php" class="admin-action-card p-3 p-xl-4 h-100 border border-success border-opacity-25 bg-card shadow-sm rounded-xl">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="action-icon-box icon-box-success">
                                <i class="fa-solid fa-user-plus fs-5"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold font-heading mb-0 text-foreground">
                                    <?= is_rtl() ? 'تسجيل وقبول الطلاب' : 'Student Admissions' ?>
                                </h5>
                            </div>
                        </div>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2.5 py-1 rounded-pill small">
                            <i class="fa-solid fa-file-signature me-1"></i><?= is_rtl() ? 'تسجيل وقبول' : 'Direct Intake' ?>
                        </span>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'نموذج تسجيل وقبول الطلاب الجدد، رفع شهادات الميلاد والمستندات الرسمية، وربط حساب ولي الأمر تلقائياً بالمنظومة.' : 'Direct student enrollment intake, official document upload, class section assignment, and guardian account linkage.' ?>
                    </p>
                    <div class="d-flex align-items-center text-success fw-semibold small pt-2 border-top border-border">
                        <span><?= is_rtl() ? 'تسجيل طالب جديد الآن' : 'Enroll New Student' ?></span>
                        <i class="fa-solid <?= is_rtl() ? 'fa-arrow-left me-2' : 'fa-arrow-right ms-2' ?>"></i>
                    </div>
                </a>
            </div>

            <!-- 3. CLASSES -->
            <div class="col-lg-4 col-md-12">
                <a href="classes.php" class="admin-action-card p-3 p-xl-4 h-100 border border-info border-opacity-25 bg-card shadow-sm rounded-xl">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <div class="d-flex align-items-center gap-3">
                            <div class="action-icon-box icon-box-info">
                                <i class="fa-solid fa-door-open fs-5"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold font-heading mb-0 text-foreground">
                                    <?= is_rtl() ? 'الفصول والشعب الدراسية' : 'Classes & Sections' ?>
                                </h5>
                            </div>
                        </div>
                        <span class="badge bg-info-subtle text-info border border-info-subtle px-2.5 py-1 rounded-pill small">
                            <?= $total_classes ?> <?= is_rtl() ? 'فصل دراسي' : 'Class Sections' ?>
                        </span>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'إنشاء الفصول والشعب، متابعة الكثافة الطلابية، توزيع الحصص، وتعيين المعلمين ورواد الفصول الدراسية.' : 'Manage class sections, view student rosters, monitor seat capacities, and allocate classroom teachers.' ?>
                    </p>
                    <div class="d-flex align-items-center text-info fw-semibold small pt-2 border-top border-border">
                        <span><?= is_rtl() ? 'إدارة الفصول والشعب' : 'Manage Classes & Sections' ?></span>
                        <i class="fa-solid <?= is_rtl() ? 'fa-arrow-left me-2' : 'fa-arrow-right ms-2' ?>"></i>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <!-- 6 CORE OPERATIONAL DESKS -->
    <h5 class="fw-bold text-foreground mb-3">
        <i class="fa-solid fa-cubes-stacked me-2 text-primary"></i><?= is_rtl() ? 'المكاتب التشغيلية لإدارة النظام' : 'Enterprise Operational Desks' ?>
    </h5>

    <div class="row g-4 mb-4">

        <!-- DESK 1: INSTITUTIONAL SETUP & HIERARCHY -->
        <div class="col-lg-4 col-md-6">
            <div class="card bg-card border border-border rounded-xl p-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-primary me-3">
                            <i class="fa-solid fa-school"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold font-heading mb-0 text-foreground"><?= is_rtl() ? '1. الإعداد المؤسسي والهيكل الأكاديمي' : '1. Institutional Setup & Hierarchy' ?></h6>
                            <small class="text-muted-foreground"><?= is_rtl() ? 'الفروع، المراحل، والسعة الاستيعابية' : 'Campuses, Stages & Capacity' ?></small>
                        </div>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'تكوين بيانات المؤسسة، رخص الاعتماد، ساعات العمل، تنظيم المراحل التعليمية (KG - ثانوي) وضبط الحد الأقصى لسعة الفصول.' : 'Configure branding, accreditation seals, operating schedule, educational stages, and section intake limits.' ?>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="classes.php" class="btn btn-primary btn-sm flex-fill fw-bold">
                        <i class="fa-solid fa-door-open me-1"></i><?= is_rtl() ? 'الفصول والشعب' : 'Classes & Sections' ?>
                    </a>
                    <a href="academic_hierarchy.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-sitemap me-1 text-purple"></i><?= is_rtl() ? 'الهيكل' : 'Hierarchy' ?>
                    </a>
                    <a href="institution_settings.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-sliders me-1 text-primary"></i><?= is_rtl() ? 'المؤسسة' : 'Settings' ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- DESK 2: STUDENT REGISTRY & ADMISSIONS -->
        <div class="col-lg-4 col-md-6">
            <div class="card bg-card border border-border rounded-xl p-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-success me-3">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold font-heading mb-0 text-foreground"><?= is_rtl() ? '2. القبول وملف الطالب 360°' : '2. Admissions & Student Dossier' ?></h6>
                            <small class="text-muted-foreground"><?= is_rtl() ? 'الالتحاق، الفحص، وتوليد أرقام القيد' : 'Intake, Verification & Auto-IDs' ?></small>
                        </div>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'مسار استيعاب المتقدمين الجدد، تدقيق المستندات واختبارات القبول، التحويل بنقرة واحدة لرقم قيد رسمي (STU-YYYY-XXXX) والملف الصحي الشامل.' : 'Manage admissions pipeline, verify documents, conduct placement tests, and 1-click active enrollment with 360° medical/transport dossier.' ?>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="student_admission.php" class="btn btn-success btn-sm flex-fill fw-bold">
                        <i class="fa-solid fa-user-plus me-1"></i><?= is_rtl() ? 'تسجيل طالب جديد' : 'New Admission' ?>
                    </a>
                    <a href="admissions.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-clipboard-check me-1 text-success"></i><?= is_rtl() ? 'مسار القبول' : 'Pipeline' ?>
                    </a>
                    <a href="student_dossier.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-id-card me-1 text-info"></i><?= is_rtl() ? 'الملف الشامل' : 'Dossier' ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- DESK 3: HOUSEHOLDS & SIBLINGS -->
        <div class="col-lg-4 col-md-6">
            <div class="card bg-card border border-border rounded-xl p-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-primary me-3">
                            <i class="fa-solid fa-people-roof"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold font-heading mb-0 text-foreground"><?= is_rtl() ? '3. العائلات والأشقاء والصلاحيات' : '3. Households & Sibling Engine' ?></h6>
                            <small class="text-muted-foreground"><?= is_rtl() ? 'تجميع الأسرة، المسؤول المالي، والوصاية' : 'Billing Payer & Custody Alerts' ?></small>
                        </div>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'ربط الأشقاء بحساب عائلي موحد لتغذية خصومات الأخوة بالمالية، تحديد المسؤول المالي وجهة الاتصال الأكاديمية وقيود الحضانة القانونية.' : 'Normalized sibling mapping, primary billing contact designations, and custody restriction alerts feeding multi-child finance discounts.' ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="households.php" class="btn btn-primary btn-sm flex-fill fw-bold">
                        <i class="fa-solid fa-diagram-project me-1"></i><?= is_rtl() ? 'محرك العائلات والربط' : 'Household Matrix' ?>
                    </a>
                    <a href="../Finance/discounts.php" class="btn btn-outline-border btn-sm" title="Sibling Discounts">
                        <i class="fa-solid fa-percent text-warning"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- DESK 4: ATTENDANCE & BEHAVIOR -->
        <div class="col-lg-4 col-md-6">
            <div class="card bg-card border border-border rounded-xl p-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-info me-3">
                            <i class="fa-solid fa-calendar-check"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold font-heading mb-0 text-foreground"><?= is_rtl() ? '4. الحضور اليومي والسلوك والانضباط' : '4. Attendance & Behavior Desk' ?></h6>
                            <small class="text-muted-foreground"><?= is_rtl() ? 'الرصد اليومي، الحرمان من الامتحان، والتكريمات' : 'Roll Call, Eligibility & Merits' ?></small>
                        </div>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'رصد الحضور اليومي بنقرة واحدة، التنبيه التلقائي عند تجاوز الغياب للحد المسموح (< 75%) المؤدي للحرمان، وسجل التكريمات والمخالفات.' : 'Daily class attendance desk, automatic exam disqualification threshold warnings (< 75%), and merits/infractions registry.' ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="attendance_desk.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-clipboard-user me-1 text-success"></i><?= is_rtl() ? 'كشف الحضور' : 'Attendance' ?>
                    </a>
                    <a href="behavior.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-shield-heart me-1 text-warning"></i><?= is_rtl() ? 'السلوك والتكريم' : 'Conduct & Merits' ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- DESK 5: RBAC & SYSTEM AUDIT TRAIL -->
        <div class="col-lg-4 col-md-6">
            <div class="card bg-card border border-border rounded-xl p-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-purple me-3">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold font-heading mb-0 text-foreground"><?= is_rtl() ? '5. مصفوفة الصلاحيات وسجل التدقيق' : '5. RBAC & Security Audit Ledger' ?></h6>
                            <small class="text-muted-foreground"><?= is_rtl() ? 'الرقابة، تعديل الدرجات، والفروقات' : 'Granular Matrix & Snapshots' ?></small>
                        </div>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'مصفوفة صلاحيات تفصيلية (إنشاء، قراءة، تعديل، حذف، اعتماد، تصدير)، وسجل تدقيق أمني غير قابل للتعديل مع مقارنة لقطات البيانات (Before / After).' : 'Granular privilege toggles across 8 roles, and immutable audit logging tracking logins, IP addresses, grade/fee alterations, and data diffs.' ?>
                    </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="users.php" class="btn btn-primary btn-sm flex-fill fw-bold">
                        <i class="fa-solid fa-users-gear me-1"></i><?= is_rtl() ? 'إدارة المستخدمين' : 'User Accounts' ?>
                    </a>
                    <a href="manage_permissions.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-user-shield me-1 text-primary"></i><?= is_rtl() ? 'الصلاحيات' : 'RBAC' ?>
                    </a>
                    <a href="security_audit.php" class="btn btn-outline-border btn-sm flex-fill">
                        <i class="fa-solid fa-database me-1 text-purple"></i><?= is_rtl() ? 'التدقيق' : 'Audit' ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- DESK 6: YEAR ROLLOVER & ALUMNI ARCHIVING -->
        <div class="col-lg-4 col-md-6">
            <div class="card bg-card border border-border rounded-xl p-4 shadow-sm h-100 d-flex flex-column justify-content-between">
                <div>
                    <div class="d-flex align-items-center mb-3">
                        <div class="action-icon-box icon-box-warning me-3">
                            <i class="fa-solid fa-arrows-split-up-and-left"></i>
                        </div>
                        <div>
                            <h6 class="fw-bold font-heading mb-0 text-foreground"><?= is_rtl() ? '6. الترحيل السنوي وسجل الخريجين' : '6. Year Rollover & Alumni' ?></h6>
                            <small class="text-muted-foreground"><?= is_rtl() ? 'ترقية الدفعات، شهادات النقل وبراءة الذمة' : 'Cohort Promotion & TC Gate' ?></small>
                        </div>
                    </div>
                    <p class="text-muted-foreground small mb-3">
                        <?= is_rtl() ? 'ترحيل الدفعات بالاعتماد على نتائج الامتحانات، حظر شهادات النقل (TC) إلا بعد تصفية الرسوم والكتب، وتحويل الخريجين للأرشيف لتحرير التراخيص.' : 'End-of-year batch promotion, mandatory financial clearance gate for Transfer Certificates, and alumni archiving to release active licenses.' ?>
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <a href="rollover.php" class="btn btn-primary btn-sm flex-fill fw-bold">
                        <i class="fa-solid fa-arrow-up-right-dots me-1"></i><?= is_rtl() ? 'محرك الترحيل والترقية' : 'Promotion Engine' ?>
                    </a>
                </div>
            </div>
        </div>

    </div>

    <?= render_platform_footer() ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
