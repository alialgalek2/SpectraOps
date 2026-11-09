<?php
/**
 * modules/Administration/manage_permissions.php
 * Granular User Permissions & Access Control Matrix
 * Including Sub-Department, Academic, and Operational Feature Permissions
 * (Core Umbrella Architecture)
 */

require_once __DIR__ . '/../../core/init.php';

// Ensure only System Admin can access permission management
Auth::requireRole('admin');

$msg = $_SESSION['flash_msg'] ?? "";
$msg_type = $_SESSION['flash_msg_type'] ?? "success";
unset($_SESSION['flash_msg'], $_SESSION['flash_msg_type']);

// Target user selection
$target_user_id = intval($_GET['user_id'] ?? 0);
$target_user = null;
$user_perms = [];

// Define all controllable system features/modules categorized by system section
$feature_categories = [
    'Institution Branding & Dynamic Theme Settings' => [
        'icon' => 'fa-palette',
        'color' => 'primary',
        'features' => [
            'manage_branding_logo' => [
                'name' => is_rtl() ? 'رفع وتحديث شعار المؤسسة الرسمي' : 'Upload & Update Institutional Logo File',
                'desc' => is_rtl() ? 'رفع وتعديل ملف الشعار الرسمي للمدرسة وتحديثه في جدول (institution_branding).' : 'Uploading and updating the institutional logo file (institution_branding table).'
            ],
            'manage_school_profile' => [
                'name' => is_rtl() ? 'تعديل بيانات وملف المؤسسة الرسمي' : 'School Profile & Institutional Details',
                'desc' => is_rtl() ? 'تعديل اسم المدرسة، البريد الإلكتروني للتواصل، رقم الهاتف، رابط الموقع، والعنوان الفعلي.' : 'Editing school profile details including school name, contact email, phone number, website URL, and physical address.'
            ],
            'manage_dynamic_theme' => [
                'name' => is_rtl() ? 'تخصيص لوحة الألوان والمظهر الذكي' : 'Dynamic Theme Color Palettes & Smart Engine',
                'desc' => is_rtl() ? 'تخصيص أو استخراج لوحات الألوان التفاعلية (الرئيسية، الثانوية، والتمييزية) عبر محرك الألوان الذكي.' : 'Customizing or auto-extracting dynamic theme color palettes (primary, secondary, and accent colors) via the smart color engine.'
            ]
        ]
    ],
    'Role-Based Permissions & Access Matrix' => [
        'icon' => 'fa-user-shield',
        'color' => 'success',
        'features' => [
            'manage_role_permissions' => [
                'name' => is_rtl() ? 'التحكم في مصفوفة الصلاحيات والأدوار' : 'Role-Based Permissions & Access Matrix',
                'desc' => is_rtl() ? 'تبديل وتخصيص وصول الميزات والصلاحيات الدقيقة عبر الموديولات (المالية، الموارد البشرية، القبول، والجداول) للأدوار والمستخدمين.' : 'Toggling granular feature access and permissions across modules (such as Finance, HR, Admissions, and Timetables) for specific user roles and individual user accounts.'
            ]
        ]
    ],
    'Academic Calendar & Term Management' => [
        'icon' => 'fa-calendar-days',
        'color' => 'purple',
        'features' => [
            'manage_academic_calendar' => [
                'name' => is_rtl() ? 'إدارة التقويم الأكاديمي والفصول والعطلات' : 'Master Academic Calendar & Term Scheduling',
                'desc' => is_rtl() ? 'إنشاء وتعديل وحذف أحداث التقويم، مواعيد بدء ونهاية الفصول، العطلات الرسمية، الإغلاقات، وفترات الامتحانات (academic_calendar).' : 'Creating, editing, and deleting master calendar events, including term start dates, term end/closing dates, official public holidays, institutional school closures, and exam periods (academic_calendar table).'
            ]
        ]
    ],
    'Class Timetable & Schedule Coordination' => [
        'icon' => 'fa-calendar-week',
        'color' => 'info',
        'features' => [
            'manage_timetable_slots' => [
                'name' => is_rtl() ? 'تخطيط الفترات وتوزيع الحصص والأيام' : 'Weekly Time Slots & Period Allocations',
                'desc' => is_rtl() ? 'رسم وتحديد الفترات الزمنية الأسبوعية، توزيع الحصص، وأيام التدريس الفعالة.' : 'Mapping weekly time slots, period allocations, and teaching days.'
            ],
            'manage_teacher_room_assignment' => [
                'name' => is_rtl() ? 'تعيين المعلمين وتوزيع القاعات الدراسية' : 'Teacher & Physical Room Scheduling',
                'desc' => is_rtl() ? 'تعيين معلمي المواد وتوزيع القاعات والفصول الدراسية لمنع تضارب الجداول.' : 'Assigning subject instructors and physical rooms to schedule classes and sections without conflicts.'
            ]
        ]
    ],
    'HR & Personnel Management' => [
        'icon' => 'fa-users-gear',
        'color' => 'warning',
        'features' => [
            'manage_staff_directory' => [
                'name' => is_rtl() ? 'دليل الكادر الوظيفي وملفات الموظفين' : 'Staff Directory & Personnel Dossiers',
                'desc' => is_rtl() ? 'إدارة دليل الكوادر وملفات الموظفين، بما في ذلك الكوادر المساندة كالسائقين والعمال والخدمات.' : 'Maintaining staff directories and managing personnel files, including non-portal staff such as cleaners and drivers.'
            ],
            'manage_payroll_workflows' => [
                'name' => is_rtl() ? 'عقود العمل ومسيرات صرف الرواتب الآلية' : 'Contract Records & Payroll Disbursement Logic',
                'desc' => is_rtl() ? 'إعداد سجلات العقود ومسارات صرف الرواتب المؤتمتة (الصرف النقدي والتحويلات البنكية).' : 'Configuring contract records and automated payroll disbursement workflows (cash and bank account logic).'
            ]
        ]
    ],
    'Financial & Fee Accounting Systems' => [
        'icon' => 'fa-vault',
        'color' => 'success',
        'features' => [
            'manage_finance_accounts' => [
                'name' => is_rtl() ? 'إدارة الحسابات البنكية والخزينة النقدية' : 'Bank Accounts & Cash Registers',
                'desc' => is_rtl() ? 'إدارة حسابات البنوك، دفاتر الصناديق النقدية، والأرصدة الافتتاحية (finance_accounts).' : 'Managing bank accounts and cash registers (finance_accounts table).'
            ],
            'manage_tuition_fees_ledger' => [
                'name' => is_rtl() ? 'هيكل الرسوم الدراسية وسجلات المعاملات' : 'Tuition Fee Structures & Transaction Ledgers',
                'desc' => is_rtl() ? 'إعداد هياكل الرسوم الدراسية، بنود الفوترة، ومتابعة سجلات المعاملات المالية (financial_transactions).' : 'Setting up student tuition fee structures, billing codes, and tracking transaction ledgers (financial_transactions table).'
            ]
        ]
    ],
    'LMS Learning Hub & Portals' => [
        'icon' => 'fa-chalkboard-user',
        'color' => 'primary',
        'features' => [
            'manage_lms_courses_modules' => [
                'name' => is_rtl() ? 'المحتوى التعليمي والمقررات الرقمية' : 'Virtual Course Structures & Learning Hub',
                'desc' => is_rtl() ? 'الإشراف على المقررات الرقمية، المواد الإثرائية، الوحدات التعليمية، والواجبات عبر بوابات المعلمين والطلاب.' : 'Overseeing virtual course structures, instructional materials, learning modules, and assignments across teacher and student portals.'
            ]
        ]
    ],
    'Exams & Grading Management' => [
        'icon' => 'fa-graduation-cap',
        'color' => 'danger',
        'features' => [
            'manage_exams_evaluations' => [
                'name' => is_rtl() ? 'كتل الامتحانات ورصد الدرجات والشهادات' : 'Examination Blocks, Gradebooks & Report Cards',
                'desc' => is_rtl() ? 'إنشاء فترات الاختبارات، رصد درجات الطلاب، تقييم الأداء، وتوليد بطاقات الدرجات الآلية.' : 'Setting up examination blocks, inputting student mark sheets, evaluating academic performance, and generating automated report cards.'
            ]
        ]
    ],
    'System Administration & Licensing' => [
        'icon' => 'fa-server',
        'color' => 'secondary',
        'features' => [
            'manage_system_licensing' => [
                'name' => is_rtl() ? 'تفعيل التراخيص والموديولات المشفرة' : 'Cryptographic License Keys & Module Activation',
                'desc' => is_rtl() ? 'تفعيل ميزات وموديولات النظام باستخدام مفاتيح التراخيص المشفرة (HR، المالية، LMS، والامتحانات).' : 'Activating system features and modules using cryptographic annual license keys (controlling core, HR, finance, LMS, and exam suite access).'
            ],
            'manage_system_updates_migrations' => [
                'name' => is_rtl() ? 'تحديثات النظام وترقيع قواعد البيانات (OTA)' : 'OTA System Updates & Database Migrations',
                'desc' => is_rtl() ? 'فحص إصدارات النظام (system_version) وتشغيل التحديثات التلقائية وملفات الترحيل.' : 'Checking system build versions (system_version table) and triggering application updates or database migration patches.'
            ],
            'manage_telemetry_logs' => [
                'name' => is_rtl() ? 'سجل الموثوقية وتدقيق الأخطاء المنقحة' : 'System Telemetry & Error Audit Logs',
                'desc' => is_rtl() ? 'مراقبة سجلات الأخطاء المنقحة (system_telemetry_logs) للصيانة التقنية وضمان الموثوقية.' : 'Monitoring system telemetry error logs (system_telemetry_logs table) for technical maintenance.'
            ]
        ]
    ],
    'Sub-Departments & Departmental Units' => [
        'icon' => 'fa-network-wired',
        'color' => 'primary',
        'features' => [
            'manage_sub_departments' => [
                'name' => is_rtl() ? 'إدارة الأقسام الفرعية والمراحل الدراسية' : 'Sub-Departments & Educational Stages',
                'desc' => is_rtl() ? 'الإشراف على الأقسام الفرعية (الروضة، الابتدائي، المتوسط، الثانوي/البريطاني) والتنسيق بينها.' : 'Supervise divisional stages (Kindergarten, Primary, Middle, High School / IGCSE) and track stage coordinators.'
            ],
            'manage_department_heads' => [
                'name' => is_rtl() ? 'رؤساء الأقسام الأكاديمية والموجهين' : 'Academic Department Heads & Subject Coordinators',
                'desc' => is_rtl() ? 'إدارة رؤساء المواد (العلوم، الرياضيات، اللغات، الدراسات الإسلامية) وتفويض الصلاحيات الأكاديمية.' : 'Assign and oversee Heads of Departments (Science, Math, Languages, Humanities) and curriculum leads.'
            ],
            'manage_student_affairs' => [
                'name' => is_rtl() ? 'قسم شؤون وإرشاد الطلاب والتوجيه' : 'Student Affairs & Guidance Sub-Department',
                'desc' => is_rtl() ? 'متابعة السلوك والمواظبة، لجان الانضباط، سجلات الإرشاد الطلابي والاجتماعات مع أولياء الأمور.' : 'Manage student conduct logs, disciplinary committees, counseling records, and guardian conferences.'
            ],
            'manage_transport_dept' => [
                'name' => is_rtl() ? 'قسم النقل المدرسي وإدارة أسطول الحافلات' : 'School Transportation & Fleet Sub-Department',
                'desc' => is_rtl() ? 'إدارة مسارات الحافلات، المشرفين، السائقين، وتوزيع الطلاب على خطوط الترحيل اليومية.' : 'Coordinate bus routes, fleet maintenance, driver assignments, and student bus transit manifests.'
            ],
            'manage_facilities_dept' => [
                'name' => is_rtl() ? 'قسم الخدمات والصيانة والأمن المدرسي' : 'School Facilities, Safety & Maintenance Sub-Department',
                'desc' => is_rtl() ? 'متابعة سجلات الحراسة والأمن، أعمال الصيانة الدورية، نظافة المباني وطلبات الخدمات المساندة.' : 'Oversee campus security logs, janitorial shifts, safety compliance, and maintenance work orders.'
            ],
            'manage_activities_dept' => [
                'name' => is_rtl() ? 'قسم الأنشطة المدرسية والنوادي الرياضية' : 'Extracurricular Activities & Sports Sub-Department',
                'desc' => is_rtl() ? 'تنظيم الفعاليات، المسابقات الرياضية والثقافية، الرحلات العلمية والنوادي الطلابية.' : 'Coordinate student clubs, athletic events, academic olympiads, and school excursions.'
            ]
        ]
    ],
    'Core Administration & Admissions' => [
        'icon' => 'fa-sliders',
        'color' => 'success',
        'features' => [
            'manage_admissions' => [
                'name' => is_rtl() ? 'قبول وتسجيل الطلاب الجدد' : 'Student Admissions & Profile Generation',
                'desc' => is_rtl() ? 'تسجيل الطلاب وربط أولياء الأمور وتوليد الأرقام الجامعية/المدرسية.' : 'Enroll students, link parents, and generate auto student credentials.'
            ],
            'manage_classes' => [
                'name' => is_rtl() ? 'إدارة الفصول والشعب الدراسية' : 'School Classes & Section Configuration',
                'desc' => is_rtl() ? 'توزيع الطلاب على الفصول وتعيين رواد الفصول والحد الأقصى للمقاعد.' : 'Configure classroom capacities, assign class teachers, and grade sections.'
            ],
            'manage_timetable' => [
                'name' => is_rtl() ? 'الجدول المدرسي وتوزيع الحصص' : 'Class Timetables & Room Scheduling',
                'desc' => is_rtl() ? 'توزيع الحصص الأسبوعية على المعلمين وتحديد القاعات الدراسية.' : 'Coordinate lecture periods, room allocations, and weekly schedules.'
            ],
            'manage_notices' => [
                'name' => is_rtl() ? 'لوحة التعميمات والمذكرات الإدارية' : 'System Announcements & HR Bulletins',
                'desc' => is_rtl() ? 'نشر وإرسال التعميمات والقرارات لكافة الكوادر والطلاب وأولياء الأمور.' : 'Publish official institutional memos to teachers, staff, and guardians.'
            ]
        ]
    ]
];

// Flatten all features for processing
$all_system_features = [];
foreach ($feature_categories as $cat) {
    foreach ($cat['features'] as $k => $f) {
        $all_system_features[$k] = $f;
    }
}

// If target user is chosen, fetch details
if ($target_user_id > 0) {
    $uStmt = $pdo->prepare("SELECT u.*, r.role_name FROM users u LEFT JOIN roles r ON u.role_id = r.role_id WHERE u.user_id = ?");
    $uStmt->execute([$target_user_id]);
    $target_user = $uStmt->fetch();

    if ($target_user) {
        // Fetch custom permissions from user_permissions table
        $pStmt = $pdo->prepare("SELECT feature_key, is_allowed FROM user_permissions WHERE user_id = ?");
        $pStmt->execute([$target_user_id]);
        while ($row = $pStmt->fetch()) {
            $user_perms[$row['feature_key']] = (int)$row['is_allowed'];
        }
    }
}

// Handle Form Submission for Updating Permissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_permissions') {
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $submitted_perms = $_POST['permissions'] ?? [];

    if ($target_user_id > 0) {
        try {
            $pdo->beginTransaction();

            foreach ($all_system_features as $key => $info) {
                $allowed = isset($submitted_perms[$key]) ? 1 : 0;

                $upsert = $pdo->prepare("
                    INSERT INTO user_permissions (user_id, feature_key, is_allowed) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE is_allowed = VALUES(is_allowed)
                ");
                $upsert->execute([$target_user_id, $key, $allowed]);
            }

            $pdo->commit();
            $_SESSION['flash_msg'] = is_rtl() 
                ? "تم تحديث مصفوفة الصلاحيات (بما فيها الأقسام الفرعية) بنجاح لهذا المستخدم!" 
                : "Permission matrix (including Sub-Department permissions) successfully updated for the selected user account!";
            $_SESSION['flash_msg_type'] = "success";
            header("Location: manage_permissions.php?user_id=" . $target_user_id);
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error updating permissions: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
}

// Fetch all users list for the dropdown selector
$all_users = $pdo->query("SELECT user_id, full_name, email, role FROM users WHERE (is_deleted = 0 OR is_deleted IS NULL) ORDER BY role ASC, full_name ASC")->fetchAll();
?>

<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'مصفوفة الصلاحيات وإدارة الأقسام الفرعية' : 'Granular Permission Matrix & Sub-Departments' ?> - <?= htmlspecialchars($school_name ?? 'School System') ?></title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .hero-banner { 
            background: linear-gradient(135deg, #1f4037 0%, #99f2c8 100%); 
            color: #0f2027; 
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(31, 64, 55, 0.15);
        }
        .card-custom { 
            border: none; 
            border-radius: 14px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.05); 
        }
        .perm-switch {
            width: 3.2em;
            height: 1.6em;
            cursor: pointer;
        }
        .category-header {
            background-color: #f1f3f5;
            font-weight: bold;
            font-size: 0.95rem;
            color: #495057;
            padding: 10px 16px;
            border-top: 2px solid #dee2e6;
        }
    </style>
</head>
<body class="bg-light">

<!-- Universal Top Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'مركز إدارة الصلاحيات والأقسام' : 'Permissions & Sub-Department Access Control',
    'back_url' => 'index.php',
    'extra_nav_items' => []
]) ?>

<div class="container-fluid px-4 py-4">
    <?php if ($msg): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show shadow-sm mb-4 fw-bold" role="alert">
            <i class="fa-solid fa-circle-info me-2"></i><?= $msg ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Hero Header -->
    <div class="p-4 p-md-5 mb-4 hero-banner">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
            <div>
                <span class="badge bg-dark text-white px-3 py-1 fw-bold text-uppercase mb-2">
                    <i class="fa-solid fa-network-wired me-1 text-warning"></i> Access Control &amp; Sub-Departments
                </span>
                <h2 class="fw-bold mb-1 text-dark">
                    <i class="fa-solid fa-user-lock me-2"></i><?= is_rtl() ? 'التحكم الدقيق في صلاحيات المستخدمين والأقسام الفرعية' : 'Granular User &amp; Sub-Department Access Control' ?>
                </h2>
                <p class="mb-0 text-dark opacity-75">
                    <?= is_rtl() ? 'تخصيص الصلاحيات للأقسام الفرعية (المراحل التعليمية، رؤساء المواد، شؤون الطلاب، الترحيل، الصيانة، والأنشطة).' : 'Configure dynamic permissions for sub-department divisions (educational stages, department heads, student affairs, fleet transport, campus facilities, and extracurriculars).' ?>
                </p>
            </div>
            <div>
                <a href="users.php" class="btn btn-dark fw-bold px-4 py-2 shadow-sm">
                    <i class="fa-solid fa-users me-1"></i> <?= is_rtl() ? 'قائمة المستخدمين' : 'User Accounts List' ?>
                </a>
            </div>
        </div>
    </div>

    <!-- User Selector Card -->
    <div class="card card-custom p-4 bg-white mb-4">
        <h5 class="fw-bold text-dark mb-3">
            <i class="fa-solid fa-magnifying-glass me-2 text-success"></i><?= is_rtl() ? 'اختر حساب المستخدم لضبط الصلاحيات' : 'Select User Account to Configure' ?>
        </h5>
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-9">
                <select name="user_id" class="form-select form-select-lg fw-bold" required onchange="this.form.submit()">
                    <option value="" disabled <?= $target_user_id == 0 ? 'selected' : '' ?>>-- <?= is_rtl() ? 'اختر حساب الموظف أو المستخدم' : 'Choose Staff or User Account' ?> --</option>
                    <?php 
                    $current_group = '';
                    foreach ($all_users as $u): 
                        if ($current_group !== $u['role']) {
                            $current_group = $u['role'];
                            echo '<optgroup label="--- ' . strtoupper($u['role']) . ' ---">';
                        }
                    ?>
                        <option value="<?= $u['user_id'] ?>" <?= $target_user_id == $u['user_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name']) ?> (<?= htmlspecialchars($u['email'] ?: $u['role']) ?>) [<?= ucfirst($u['role']) ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success fw-bold w-100 py-2">
                    <i class="fa-solid fa-filter me-1"></i> <?= is_rtl() ? 'تحميل الصلاحيات' : 'Load Permissions' ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ($target_user): ?>
    <!-- Permission Toggle Form Matrix -->
    <div class="card card-custom p-4 bg-white mb-4">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-4 flex-wrap gap-2">
            <div>
                <h4 class="fw-bold text-dark mb-1">
                    <i class="fa-solid fa-circle-user text-primary me-2"></i><?= htmlspecialchars($target_user['full_name']) ?>
                </h4>
                <p class="text-muted small mb-0">
                    Role: <span class="badge bg-primary text-uppercase"><?= htmlspecialchars($target_user['role']) ?></span> 
                    | Email: <code><?= htmlspecialchars($target_user['email'] ?: 'No email') ?></code>
                    | User ID: <code>#<?= $target_user['user_id'] ?></code>
                </p>
            </div>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <button type="button" class="btn btn-outline-success btn-sm fw-bold rounded-pill px-3" onclick="toggleAllPermissions(true)">
                    <i class="fa-solid fa-check-double me-1"></i> <?= is_rtl() ? 'تفعيل الكل' : 'Enable All' ?>
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm fw-bold rounded-pill px-3" onclick="toggleAllPermissions(false)">
                    <i class="fa-solid fa-ban me-1"></i> <?= is_rtl() ? 'تعطيل الكل' : 'Disable All' ?>
                </button>
                <span class="badge bg-success bg-opacity-10 text-success border border-success p-2 fw-bold rounded-pill px-3">
                    <i class="fa-solid fa-sliders me-1"></i> Active Matrix (<?= count($all_system_features) ?> Permissions)
                </span>
            </div>
        </div>

        <div class="mb-3">
            <div class="position-relative">
                <i class="fa-solid fa-magnifying-glass position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                <input type="text" id="permSearchInput" class="form-control ps-5 rounded-pill" placeholder="<?= is_rtl() ? 'بحث سريع في الصلاحيات والوظائف المتاحة...' : 'Quick search permissions and functional modules...' ?>">
            </div>
        </div>

        <form method="POST" action="" id="permissionsForm">
            <input type="hidden" name="action" value="save_permissions">
            <input type="hidden" name="user_id" value="<?= $target_user_id ?>">

            <div class="table-responsive">
                <table class="table table-hover align-middle" id="permTable">
                    <thead class="table-dark">
                        <tr>
                            <th style="width: 55%;"><?= is_rtl() ? 'الوحدة / الوظيفة / القسم الفرعي' : 'System Feature &amp; Sub-Department' ?></th>
                            <th style="width: 25%;"><?= is_rtl() ? 'الحالة الحالية' : 'Current Status' ?></th>
                            <th style="width: 20%;" class="text-end"><?= is_rtl() ? 'تفعيل / تعطيل' : 'Toggle Access' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $catIdx = 0;
                        foreach ($feature_categories as $catTitle => $catData): 
                            $catIdx++;
                        ?>
                            <tr class="category-row" data-cat-id="cat-<?= $catIdx ?>">
                                <td colspan="2" class="category-header">
                                    <i class="fa-solid <?= $catData['icon'] ?> text-<?= $catData['color'] ?> me-2"></i>
                                    <?= htmlspecialchars($catTitle) ?>
                                    <span class="badge bg-secondary ms-2 small"><?= count($catData['features']) ?></span>
                                </td>
                                <td class="category-header text-end">
                                    <div class="btn-group btn-group-sm">
                                        <button type="button" class="btn btn-sm btn-outline-success py-0 px-2 fw-semibold" title="Enable Category" onclick="toggleCategoryPermissions('cat-<?= $catIdx ?>', true)">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger py-0 px-2 fw-semibold" title="Disable Category" onclick="toggleCategoryPermissions('cat-<?= $catIdx ?>', false)">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($catData['features'] as $key => $info): 
                                // Default rule check
                                if (isset($user_perms[$key])) {
                                    $is_checked = ($user_perms[$key] === 1);
                                } else {
                                    // Default allowed based on role
                                    if ($target_user['role'] === 'admin') {
                                        $is_checked = true;
                                    } elseif ($target_user['role'] === 'school_admin') {
                                        $is_checked = in_array($key, [
                                            'manage_branding_logo', 'manage_school_profile', 'manage_dynamic_theme',
                                            'manage_role_permissions', 'manage_academic_calendar',
                                            'manage_timetable_slots', 'manage_teacher_room_assignment', 'manage_timetable',
                                            'manage_staff_directory', 'manage_payroll_workflows', 'manage_hr',
                                            'manage_finance_accounts', 'manage_tuition_fees_ledger', 'view_finances',
                                            'manage_lms_courses_modules', 'manage_exams_evaluations', 'manage_exams',
                                            'manage_system_licensing', 'manage_system_updates_migrations', 'manage_telemetry_logs',
                                            'manage_sub_departments', 'manage_department_heads', 'manage_student_affairs', 
                                            'manage_transport_dept', 'manage_facilities_dept', 'manage_activities_dept',
                                            'manage_admissions', 'manage_classes', 'manage_notices'
                                        ]);
                                    } elseif ($target_user['role'] === 'teacher') {
                                        $is_checked = in_array($key, ['manage_lms_courses_modules', 'manage_exams_evaluations', 'manage_exams', 'manage_timetable_slots', 'manage_timetable', 'manage_notices']);
                                    } elseif ($target_user['role'] === 'finance') {
                                        $is_checked = in_array($key, ['manage_finance_accounts', 'manage_tuition_fees_ledger', 'view_finances', 'manage_payroll_workflows', 'manage_payroll', 'manage_notices']);
                                    } elseif ($target_user['role'] === 'hr') {
                                        $is_checked = in_array($key, ['manage_staff_directory', 'manage_payroll_workflows', 'manage_hr', 'manage_payroll', 'manage_facilities_dept', 'manage_transport_dept', 'manage_notices']);
                                    } else {
                                        $is_checked = false;
                                    }
                                }
                            ?>
                            <tr class="perm-row <?= $catIdx ? 'cat-' . $catIdx : '' ?>">
                                <td class="ps-4">
                                    <div class="fw-bold text-dark fs-6 perm-name"><?= htmlspecialchars($info['name']) ?></div>
                                    <small class="text-muted perm-desc"><?= htmlspecialchars($info['desc']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $is_checked ? 'success' : 'danger' ?> px-3 py-2 fw-bold" id="badge-<?= $key ?>">
                                        <i class="fa-solid <?= $is_checked ? 'fa-circle-check' : 'fa-ban' ?> me-1"></i>
                                        <?= $is_checked ? (is_rtl() ? 'مسموح' : 'Allowed') : (is_rtl() ? 'محظور' : 'Blocked') ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="form-check form-switch d-inline-block">
                                        <input class="form-check-input perm-switch cat-switch-<?= $catIdx ?>" type="checkbox" role="switch" 
                                               name="permissions[<?= $key ?>]" value="1" 
                                               id="switch-<?= $key ?>" 
                                               data-target="badge-<?= $key ?>"
                                               data-allowed-text="<?= is_rtl() ? 'مسموح' : 'Allowed' ?>"
                                               data-blocked-text="<?= is_rtl() ? 'محظور' : 'Blocked' ?>"
                                               <?= $is_checked ? 'checked' : '' ?>>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <a href="index.php" class="btn btn-secondary px-4 fw-bold">
                    <i class="fa-solid fa-arrow-left me-1"></i> <?= is_rtl() ? 'العودة لمركز الإدارة' : 'Back to Control Center' ?>
                </a>
                <button type="submit" class="btn btn-success fw-bold px-5 py-2 shadow fs-6">
                    <i class="fa-solid fa-floppy-disk me-2"></i> <?= is_rtl() ? 'حفظ وتطبيق مصفوفة الصلاحيات' : 'Save Permission Matrix' ?>
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Update individual switch badge
    function updateSwitchBadge(sw) {
        const badgeId = sw.getAttribute('data-target');
        const allowedTxt = sw.getAttribute('data-allowed-text');
        const blockedTxt = sw.getAttribute('data-blocked-text');
        const badge = document.getElementById(badgeId);
        if (!badge) return;
        if (sw.checked) {
            badge.className = 'badge bg-success px-3 py-2 fw-bold';
            badge.innerHTML = '<i class="fa-solid fa-circle-check me-1"></i> ' + allowedTxt;
        } else {
            badge.className = 'badge bg-danger px-3 py-2 fw-bold';
            badge.innerHTML = '<i class="fa-solid fa-ban me-1"></i> ' + blockedTxt;
        }
    }

    document.querySelectorAll('.perm-switch').forEach(sw => {
        sw.addEventListener('change', function() {
            updateSwitchBadge(this);
        });
    });

    // Global Toggle All
    function toggleAllPermissions(enable) {
        document.querySelectorAll('.perm-switch').forEach(sw => {
            sw.checked = enable;
            updateSwitchBadge(sw);
        });
    }

    // Category Level Toggle
    function toggleCategoryPermissions(catId, enable) {
        const catIdx = catId.replace('cat-', '');
        document.querySelectorAll('.cat-switch-' + catIdx).forEach(sw => {
            sw.checked = enable;
            updateSwitchBadge(sw);
        });
    }

    // Live Search Filter
    const searchInput = document.getElementById('permSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.trim().toLowerCase();
            const permRows = document.querySelectorAll('.perm-row');
            const categoryRows = document.querySelectorAll('.category-row');

            if (!query) {
                permRows.forEach(r => r.style.display = '');
                categoryRows.forEach(c => c.style.display = '');
                return;
            }

            // Hide/show matching rows
            categoryRows.forEach(c => c.style.display = 'none');
            const matchedCats = new Set();

            permRows.forEach(row => {
                const nameText = row.querySelector('.perm-name')?.innerText.toLowerCase() || '';
                const descText = row.querySelector('.perm-desc')?.innerText.toLowerCase() || '';
                if (nameText.includes(query) || descText.includes(query)) {
                    row.style.display = '';
                    const classList = Array.from(row.classList);
                    classList.forEach(cls => {
                        if (cls.startsWith('cat-')) {
                            matchedCats.add(cls);
                        }
                    });
                } else {
                    row.style.display = 'none';
                }
            });

            // Show headers for categories with matches
            matchedCats.forEach(catClass => {
                const catHeader = document.querySelector('.category-row[data-cat-id="' + catClass + '"]');
                if (catHeader) catHeader.style.display = '';
            });
        });
    }
</script>
</body>
</html>
