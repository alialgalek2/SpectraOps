<?php
/**
 * HR Module - Central Personnel & Human Resources Hub
 * (Core Umbrella Architecture - LMS Layout & Unified Theme)
 */

require_once __DIR__ . '/../../core/init.php';

// Security check: Must be Admin, School Admin, HR, or Finance
Auth::requireRole(['admin', 'school_admin', 'hr', 'finance']);

$user_role = Auth::role();
$full_name = Auth::fullName();

// Fetch summary metrics for the HR Hub
$total_staff = 0;
$teachers_count = 0;
$pending_leaves = 0;
$departments_count = 0;

try {
    $total_staff = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'admin', 'hr', 'finance', 'school_admin') AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
    $teachers_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'teacher' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
    $pending_leaves = (int)$pdo->query("SELECT COUNT(*) FROM staff_leaves WHERE status = 'Pending'")->fetchColumn();
    $departments_count = (int)$pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'شؤون الموظفين والموارد البشرية' : 'Personnel & HR Management Hub' ?> - School System</title>
    <?= get_bootstrap_css() ?>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../../assets/css/theme.css">
    <style>
        .admin-action-card {
            border: 1px solid var(--border);
            border-radius: var(--radius-xl, 1rem);
            background: var(--card);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .admin-action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 10px 24px rgba(0,0,0,0.08);
            color: inherit;
        }
    </style>
</head>
<body class="bg-background text-foreground">

<!-- Top Universal Single-Row Navbar -->
<?= render_universal_navbar([
    'active_title' => is_rtl() ? 'شؤون الكادر والموارد البشرية' : 'Personnel & HR Hub',
    'back_url' => '../../dashboard.php',
    'extra_nav_items' => [
        '<a href="employees.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-users me-1 text-primary"></i> ' . (is_rtl() ? 'الموظفون' : 'Staff') . '</a>',
        '<a href="staff_attendance.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-user-clock me-1 text-success"></i> ' . (is_rtl() ? 'الحضور' : 'Attendance') . '</a>',
        '<a href="leaves.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-person-walking-arrow-right me-1 text-warning"></i> ' . (is_rtl() ? 'الإجازات' : 'Leaves') . '</a>',
        '<a href="payroll.php" class="btn btn-outline-border btn-sm"><i class="fa-solid fa-file-invoice-dollar me-1 text-purple"></i> ' . (is_rtl() ? 'الرواتب' : 'Payroll') . '</a>'
    ]
]) ?>

<div class="container-fluid px-3 px-md-4 py-4">

    <!-- Header Banner -->
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold font-heading mb-1 text-foreground">
                <i class="fa-solid fa-people-roof text-primary me-2"></i><?= is_rtl() ? 'إدارة الموارد البشرية وشؤون الموظفين' : 'Personnel & Human Resources Center' ?>
            </h3>
            <p class="text-muted-foreground small mb-0">
                <?= is_rtl() ? 'ملفات الكوادر التعليمية والإدارية، سجلات الدوام، الإجازات، تقييمات الأداء، ومسيرات الرواتب الشهرية والوثائق الرسمية.' : 'Comprehensive employee lifecycle, staff archives, daily attendance, leave approvals, performance evaluations, and monthly payroll ledgers.' ?>
            </p>
        </div>
        <div>
            <span class="badge bg-card border border-border text-foreground px-3 py-2 fs-6 shadow-sm rounded-xl">
                <i class="fa-solid fa-user-circle text-primary me-1"></i> <?= htmlspecialchars($full_name) ?> <span class="badge bg-primary-subtle text-primary ms-1"><?= ucfirst($user_role) ?></span>
            </span>
        </div>
    </div>

    <!-- Live Metrics Overview -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-primary me-3">
                    <i class="fa-solid fa-users-gear"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $total_staff ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'إجمالي الكادر المسجل' : 'Total Staff Members' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-info me-3">
                    <i class="fa-solid fa-chalkboard-user"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $teachers_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الكادر التعليمي' : 'Teaching Faculty' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-warning me-3">
                    <i class="fa-solid fa-person-walking-arrow-right"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $pending_leaves ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'طلبات إجازة معلقة' : 'Pending Leave Requests' ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="stat-card-clean bg-card border border-border rounded-xl p-3 shadow-sm h-100 d-flex align-items-center">
                <div class="action-icon-box icon-box-purple me-3">
                    <i class="fa-solid fa-sitemap"></i>
                </div>
                <div>
                    <div class="fs-4 fw-bold text-foreground"><?= $departments_count ?></div>
                    <div class="small text-muted-foreground"><?= is_rtl() ? 'الأقسام الإدارية' : 'Active Departments' ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1: Staff Directory & Organization -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-users text-primary me-2"></i><?= is_rtl() ? 'دليل الكوادر والتعيين والهيكل الإداري' : 'Staff Directory, Onboarding & Org Structure' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- 1. Staff & Employee Directory -->
        <div class="col-md-4 col-sm-6">
            <a href="employees.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-primary me-3">
                        <i class="fa-solid fa-users-gear"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'دليل وسجلات الموظفين' : 'Staff Directory' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الملفات الوظيفية والعقود' : 'Personnel Records' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'استعراض سجلات الكوادر التعليمية والإدارية، المسميات الوظيفية، والوثائق الرسمية.' : 'Browse active staff profiles, contract types, job positions, and personnel files.' ?></p>
            </a>
        </div>

        <!-- 2. Onboard New Employee -->
        <div class="col-md-4 col-sm-6">
            <a href="add_employee.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-user-plus"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'إضافة موظف جديد' : 'Add Employee' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'التعيين والتسكين الوظيفي' : 'Staff Onboarding' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تسجيل كوادر تعليمية وإدارية جديدة وتحديد الأقسام والرواتب الأساسية.' : 'Register new faculty and admin personnel, assign roles, departments, and base salary scales.' ?></p>
            </a>
        </div>

        <!-- 3. Academic & Administrative Departments -->
        <div class="col-md-4 col-sm-6">
            <a href="departments.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-sitemap"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'الأقسام والهيكل الإداري' : 'Departments & Structure' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الهيكل والموازنات ورؤساء الأقسام' : 'HODs & Budgets' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'إدارة الأقسام الأكاديمية والإدارية، تعيين رؤساء الأقسام، وتخصيص الميزانيات التشغيلية.' : 'Manage academic and administrative departments, assign Department Heads, and monitor operational budgets.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 2: Daily Operations, Attendance & Appraisals -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-calendar-check text-success me-2"></i><?= is_rtl() ? 'العمليات اليومية والدوام والإجازات والتقييم' : 'Daily Operations, Attendance, Leaves & KPIs' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- 4. Staff Daily Attendance -->
        <div class="col-md-4 col-sm-6">
            <a href="staff_attendance.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-success me-3">
                        <i class="fa-solid fa-user-clock"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'حضور وغياب الكادر' : 'Staff Daily Attendance' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'سجل الدوام والبصمة' : 'Daily Timesheets & Punctuality' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تسجيل أوقات الحضور والانصراف اليومية، رصد التأخيرات، والتحضير الجماعي للموظفين.' : 'Track check-in/out times, record punctuality and minutes late, with bulk attendance logging.' ?></p>
            </a>
        </div>

        <!-- 5. Leave Requests & Management -->
        <div class="col-md-4 col-sm-6">
            <a href="leaves.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-person-walking-arrow-right"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'إدارة الإجازات والأذونات' : 'Staff Leaves' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الطلبات والاعتمادات' : 'Leave Requests & Balances' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تقديم واعتماد الإجازات الاعتيادية والمرضية، ومتابعة أرصدة الإجازات السنوية.' : 'Review and approve annual, sick, or emergency leaves and track quota balances.' ?></p>
            </a>
        </div>

        <!-- 6. Staff Performance Appraisals -->
        <div class="col-md-4 col-sm-6">
            <a href="evaluations.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-award"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'تقييم الأداء والكفاءة' : 'Staff Appraisals & KPIs' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'مؤشرات الأداء والتوصيات' : 'Performance Rubrics' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'تقييم جودة التدريس، الانضباط، إدارة الصف، والعمل الجماعي مع احتساب التقييم العام.' : 'Measure teaching quality, punctuality, classroom management, and teamwork with rubric scoring.' ?></p>
            </a>
        </div>
    </div>

    <!-- Section 3: Compensation, Payroll & Advances -->
    <h5 class="fw-bold font-heading text-foreground mb-3">
        <i class="fa-solid fa-file-invoice-dollar text-purple me-2"></i><?= is_rtl() ? 'التعويضات ومسيرات الرواتب والسلف' : 'Compensation, Payroll & Advances' ?>
    </h5>
    <div class="row g-3 mb-4">
        <!-- 7. Monthly Payroll Compensation -->
        <div class="col-md-4 col-sm-6">
            <a href="payroll.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-purple me-3">
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'مسير الرواتب الشهري' : 'Payroll Management' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الحسابات والاستقطاعات' : 'Compensation & Slips' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'احتساب الرواتب، البدلات، المكافآت والاستقطاعات، وتوليد قسائم الرواتب الشهرية.' : 'Calculate monthly allowances, bonuses, tax deductions, and generate payslips.' ?></p>
            </a>
        </div>

        <!-- 8. Salary Advances & Loans -->
        <div class="col-md-4 col-sm-6">
            <a href="advances.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-warning me-3">
                        <i class="fa-solid fa-hand-holding-dollar"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'السلف والقروض المالية' : 'Salary Advances & Loans' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الطلبات والاستقطاع الشهري' : 'Installments & Approvals' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'إدارة طلبات السلف الطارئة، جدولة أقساط السداد الشهرية، وخصمها تلقائياً من مسير الرواتب.' : 'Emergency loan requests, repayment scheduling, and automatic monthly deductions via payroll.' ?></p>
            </a>
        </div>

        <!-- 9. Historical Payroll Ledger -->
        <div class="col-md-4 col-sm-6">
            <a href="payroll_ledger.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-info me-3">
                        <i class="fa-solid fa-book-bookmark"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'سجل الرواتب التاريخي' : 'Payroll Ledger' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'الأرشيف والتقارير' : 'Historical Pay-Runs' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'أرشيف مسيرات الرواتب السابقة وتقارير تكاليف الكوادر للأعوام والفصول الدراسية.' : 'Audit historical salary disbursements and view annualized staffing expenditure records.' ?></p>
            </a>
        </div>

        <!-- 10. HR Analytics & Executive Summary -->
        <div class="col-md-4 col-sm-6">
            <a href="hr_dashboard.php" class="card admin-action-card p-4 h-100 shadow-sm">
                <div class="d-flex align-items-center mb-3">
                    <div class="action-icon-box icon-box-secondary me-3">
                        <i class="fa-solid fa-chart-pie"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold mb-0 text-foreground"><?= is_rtl() ? 'تحليلات الكادر والموارد' : 'HR Analytics' ?></h5>
                        <small class="text-muted-foreground"><?= is_rtl() ? 'المؤشرات والبيانات' : 'Workforce Overview' ?></small>
                    </div>
                </div>
                <p class="text-muted-foreground small mb-0"><?= is_rtl() ? 'مؤشرات الأداء، نسب الحضور، وتوزيع الكادر حسب الأقسام والتخصصات.' : 'Staff distribution across departments, teacher-to-student ratios, and metrics.' ?></p>
            </a>
        </div>
    </div>

    <?= render_platform_footer() ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>