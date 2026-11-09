<?php
declare(strict_types=1);

/**
 * Main Layout Component & Sidebar State Architecture
 * 
 * Provides unified layout wrapping, responsive collapsible sidebar state management,
 * environment-aware breadcrumb rendering, and scroll boundary coordination
 * for Native Android WebView Hybrid App.
 */

if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/init.php';
}

class Layout
{
    /**
     * Start Main Page Layout
     * 
     * @param array $options [
     *   'title'           => string,
     *   'active_module'   => string,
     *   'breadcrumbs'     => array of ['label' => string, 'url' => string|null],
     *   'file_path'       => string (optional source path, only shown in development mode),
     *   'sidebar_items'   => array (optional custom sidebar items override),
     *   'ptr_enabled'     => bool (default true),
     *   'content_classes' => string (optional classes for scrollable area)
     * ]
     */
    public static function start(array $options = []): void
    {
        global $global_branding, $pdo;

        $title = $options['title'] ?? ($global_branding['school_name'] ?? 'EduMaster School System');
        $active_module = $options['active_module'] ?? 'dashboard';
        $breadcrumbs = $options['breadcrumbs'] ?? [];
        $file_path = $options['file_path'] ?? null;
        $ptr_enabled = $options['ptr_enabled'] ?? true;
        $content_classes = $options['content_classes'] ?? 'p-3 p-md-4';
        
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
        $is_rtl = function_exists('is_rtl') ? is_rtl() : false;
        $school_name = $global_branding['school_name'] ?? 'EduMaster Core';
        $school_logo = $global_branding['logo_path'] ?? '';
        $active_year = $global_branding['academic_year'] ?? '2025/2026';
        
        $user_name = $_SESSION['full_name'] ?? 'Administrator';
        $user_role = ucfirst($_SESSION['role'] ?? 'Admin');
        $user_initials = function_exists('extract_user_initials') ? extract_user_initials($user_name) : 'AD';

        // Default Navigation Items for the Reusable Sidebar
        $default_nav = [
            [
                'id'    => 'dashboard',
                'label' => $is_rtl ? 'لوحة التحكم' : 'Dashboard',
                'url'   => $baseUrl . '/dashboard.php',
                'icon'  => 'fa-solid fa-gauge',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr']
            ],
            [
                'id'    => 'admin',
                'label' => $is_rtl ? 'الإدارة والتحكم' : 'Administration',
                'url'   => $baseUrl . '/modules/Administration/index.php',
                'icon'  => 'fa-solid fa-shield-halved',
                'roles' => ['admin', 'school_admin']
            ],
            [
                'id'    => 'lms',
                'label' => $is_rtl ? 'إدارة التعلم (LMS)' : 'LMS & Classes',
                'url'   => $baseUrl . '/modules/LMS/index.php',
                'icon'  => 'fa-solid fa-graduation-cap',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent']
            ],
            [
                'id'    => 'grading',
                'label' => $is_rtl ? 'الامتحانات والتقييم' : 'Grading & Exams',
                'url'   => $baseUrl . '/modules/Grading/index.php',
                'icon'  => 'fa-solid fa-file-pen',
                'roles' => ['admin', 'school_admin', 'teacher']
            ],
            [
                'id'    => 'finance',
                'label' => $is_rtl ? 'المالية والخزينة' : 'Finance & Treasury',
                'url'   => $baseUrl . '/modules/Finance/index.php',
                'icon'  => 'fa-solid fa-vault',
                'roles' => ['admin', 'school_admin', 'finance']
            ],
            [
                'id'    => 'hr',
                'label' => $is_rtl ? 'الموارد البشرية' : 'HR & Staff',
                'url'   => $baseUrl . '/modules/HR/index.php',
                'icon'  => 'fa-solid fa-users-gear',
                'roles' => ['admin', 'school_admin', 'hr']
            ],
            [
                'id'    => 'support',
                'label' => $is_rtl ? 'مركز الدعم IT' : 'Support Center',
                'url'   => $baseUrl . '/support.php',
                'icon'  => 'fa-solid fa-headset',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr']
            ],
            [
                'id'    => 'docs',
                'label' => $is_rtl ? 'دليل التوثيق' : 'Documentation',
                'url'   => $baseUrl . '/docs.php',
                'icon'  => 'fa-solid fa-book-bookmark',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr']
            ]
        ];

        $nav_items = $options['sidebar_items'] ?? $default_nav;
        $currentUserRole = strtolower($_SESSION['role'] ?? 'guest');

        echo '<div class="app-layout-container" id="appLayoutContainer">';
        
        // --- 1. Reusable Main Sidebar (Default Compact / Collapsed) ---
        echo self::renderSidebar($options);

        // --- 2. Main Content Wrapper ---
        echo '<div class="main-content-wrapper" id="mainContentWrapper">';
        
        // Universal Top Navigation Bar
        if (function_exists('render_universal_navbar')) {
            echo render_universal_navbar([
                'active_title' => $title,
                'show_search'  => true
            ]);
        }

        // --- 3. Defined Scrollable Content Area with Pull-To-Refresh Target View ---
        echo '<main class="main-content-scrollable ' . htmlspecialchars($content_classes) . '" id="mainScrollableContent">';
        echo '  <div class="' . ($ptr_enabled ? 'ptr-content-target' : '') . '" id="ptrTargetView">';

        // Breadcrumbs with Environment Check
        if (!empty($breadcrumbs)) {
            self::renderBreadcrumbs($breadcrumbs, $file_path);
        }
    }

    /**
     * Render Breadcrumb Component with Conditional Environment Check
     * 
     * Requirement: Only renders the source file path element when the application
     * is explicitly running in development mode (APP_ENV === 'development').
     */
    public static function renderBreadcrumbs(array $items, ?string $filePath = null): void
    {
        $is_rtl = function_exists('is_rtl') ? is_rtl() : false;
        
        // Check Environment Variable (Development Mode Check)
        $isDevMode = (defined('APP_ENV') && APP_ENV === 'development') 
                  || (getenv('APP_ENV') === 'development')
                  || (!empty($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development');

        echo '<nav aria-label="breadcrumb" class="mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">';
        echo '  <ol class="breadcrumb mb-0 small text-muted-foreground">';
        
        $total = count($items);
        $idx = 0;
        foreach ($items as $crumb) {
            $idx++;
            $isLast = ($idx === $total);
            if ($isLast || empty($crumb['url'])) {
                echo '<li class="breadcrumb-item active text-foreground fw-semibold" aria-current="page">' . htmlspecialchars($crumb['label']) . '</li>';
            } else {
                echo '<li class="breadcrumb-item"><a href="' . htmlspecialchars($crumb['url']) . '" class="text-decoration-none text-muted-foreground hover-text-primary">' . htmlspecialchars($crumb['label']) . '</a></li>';
            }
        }
        echo '  </ol>';

        // Conditional Filepath Element: Only Rendered in Development Mode
        if ($isDevMode && !empty($filePath)) {
            echo '  <div class="dev-filepath-badge" title="Source Component Path (Development Mode Only)">';
            echo '    <i class="fa-solid fa-code text-primary"></i>';
            echo '    <span class="font-monospace">' . htmlspecialchars($filePath) . '</span>';
            echo '  </div>';
        }

        echo '</nav>';
    }

    /**
     * Render the Reusable Main Sidebar in Compact / Collapsed Mode
     * 
     * @param array $options
     * @return string
     */
    public static function renderSidebar(array $options = []): string
    {
        global $global_branding, $pdo;
        
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
        $is_rtl = function_exists('is_rtl') ? is_rtl() : false;
        $school_name = $global_branding['school_name'] ?? 'EduMaster Core';
        $school_logo = $global_branding['logo_path'] ?? '';
        $active_year = $global_branding['academic_year'] ?? '2025/2026';
        $currentUserRole = strtolower($_SESSION['role'] ?? 'guest');
        
        // Auto-detect active module if not specified
        $active_module = $options['active_module'] ?? '';
        $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');

        // Never render the sidebar on the main dashboard
        if (stripos($scriptPath, 'dashboard.php') !== false || strcasecmp($active_module, 'dashboard') === 0 || !empty($options['hide_sidebar'])) {
            return '';
        }

        if (empty($active_module)) {
            if (stripos($scriptPath, '/modules/Administration/') !== false) {
                $active_module = 'admin';
            } elseif (stripos($scriptPath, '/modules/LMS/') !== false) {
                $active_module = 'lms';
            } elseif (stripos($scriptPath, '/modules/Grading/') !== false) {
                $active_module = 'grading';
            } elseif (stripos($scriptPath, '/modules/Finance/') !== false) {
                $active_module = 'finance';
            } elseif (stripos($scriptPath, '/modules/HR/') !== false) {
                $active_module = 'hr';
            } elseif (stripos($scriptPath, 'support.php') !== false) {
                $active_module = 'support';
            } elseif (stripos($scriptPath, 'docs.php') !== false) {
                $active_module = 'docs';
            }
        }

        // Canonical module alias dictionary for accurate active link detection
        $modAliases = [
            'administration' => 'admin',
            'admin'          => 'admin',
            'grading'        => 'grading',
            'lms'            => 'lms',
            'finance'        => 'finance',
            'hr'             => 'hr',
            'support'        => 'support',
            'docs'           => 'docs'
        ];
        $canonicalActiveMod = $modAliases[strtolower($active_module)] ?? strtolower($active_module);
        
        $active_script = basename($scriptPath);

        // Dynamic in-module menus definitions
        $module_menus = [
            'admin' => [
                'title' => $is_rtl ? 'الإدارة والتحكم' : 'Administration',
                'icon'  => 'fa-solid fa-shield-halved',
                'roles' => ['admin', 'school_admin'],
                'items' => [
                    [
                        'id'      => 'admin_index',
                        'label'   => $is_rtl ? 'لوحة الإدارة' : 'Admin Overview',
                        'url'     => $baseUrl . '/modules/Administration/index.php',
                        'icon'    => 'fa-solid fa-house-chimney',
                        'scripts' => ['index.php', 'admin_portal.php']
                    ],
                    [
                        'id'      => 'admin_users',
                        'label'   => $is_rtl ? 'إدارة المستخدمين' : 'User Accounts',
                        'url'     => $baseUrl . '/modules/Administration/users.php',
                        'icon'    => 'fa-solid fa-users',
                        'scripts' => ['users.php', 'add_user.php']
                    ],
                    [
                        'id'      => 'admin_perms',
                        'label'   => $is_rtl ? 'الصلاحيات والأدوار' : 'Roles & Permissions',
                        'url'     => $baseUrl . '/modules/Administration/manage_permissions.php',
                        'icon'    => 'fa-solid fa-user-shield',
                        'scripts' => ['manage_permissions.php']
                    ],
                    [
                        'id'      => 'admin_admissions',
                        'label'   => $is_rtl ? 'القبول والتسجيل' : 'Admissions Desk',
                        'url'     => $baseUrl . '/modules/Administration/admissions.php',
                        'icon'    => 'fa-solid fa-user-plus',
                        'scripts' => ['admissions.php', 'student_admission.php', 'student_profile.php', 'student_dossier.php', 'households.php', 'tc_print.php']
                    ],
                    [
                        'id'      => 'admin_classes',
                        'label'   => $is_rtl ? 'الفصول والشعب' : 'Classes & Sections',
                        'url'     => $baseUrl . '/modules/Administration/classes.php',
                        'icon'    => 'fa-solid fa-chalkboard-user',
                        'scripts' => ['classes.php']
                    ],
                    [
                        'id'      => 'admin_hierarchy',
                        'label'   => $is_rtl ? 'الهيكل الأكاديمي' : 'Academic Hierarchy',
                        'url'     => $baseUrl . '/modules/Administration/academic_hierarchy.php',
                        'icon'    => 'fa-solid fa-sitemap',
                        'scripts' => ['academic_hierarchy.php', 'subjects.php']
                    ],
                    [
                        'id'      => 'admin_calendar',
                        'label'   => $is_rtl ? 'التقويم الأكاديمي' : 'Academic Calendar',
                        'url'     => $baseUrl . '/modules/Administration/academic_calendar.php',
                        'icon'    => 'fa-solid fa-calendar-days',
                        'scripts' => ['academic_calendar.php', 'print_academic_calendar.php', 'rollover.php']
                    ],
                    [
                        'id'      => 'admin_settings',
                        'label'   => $is_rtl ? 'إعدادات المؤسسة' : 'System Settings',
                        'url'     => $baseUrl . '/modules/Administration/institution_settings.php',
                        'icon'    => 'fa-solid fa-sliders',
                        'scripts' => ['institution_settings.php', 'branding.php', 'system_updates.php', 'license_details.php', 'license_activate.php']
                    ],
                    [
                        'id'      => 'admin_audit',
                        'label'   => $is_rtl ? 'سجل الرقابة والأمان' : 'Security & Audit',
                        'url'     => $baseUrl . '/modules/Administration/security_audit.php',
                        'icon'    => 'fa-solid fa-fingerprint',
                        'scripts' => ['security_audit.php', 'telemetry_logs.php', 'data_migration.php']
                    ]
                ]
            ],
            'grading' => [
                'title' => $is_rtl ? 'الامتحانات والتقييم' : 'Grading & Exams',
                'icon'  => 'fa-solid fa-file-pen',
                'roles' => ['admin', 'school_admin', 'teacher'],
                'items' => [
                    [
                        'id'      => 'grading_index',
                        'label'   => $is_rtl ? 'لوحة التقييم' : 'Grading Overview',
                        'url'     => $baseUrl . '/modules/Grading/index.php',
                        'icon'    => 'fa-solid fa-chart-line',
                        'scripts' => ['index.php', 'analytics.php']
                    ],
                    [
                        'id'      => 'grading_teacher',
                        'label'   => $is_rtl ? 'رصد درجات المعلم' : 'Teacher Grades Entry',
                        'url'     => $baseUrl . '/modules/Grading/teacher_grades.php',
                        'icon'    => 'fa-solid fa-pen-to-square',
                        'scripts' => ['teacher_grades.php']
                    ],
                    [
                        'id'      => 'grading_reports',
                        'label'   => $is_rtl ? 'الشهادات والتقارير' : 'Report Cards',
                        'url'     => $baseUrl . '/modules/Grading/report_cards.php',
                        'icon'    => 'fa-solid fa-award',
                        'scripts' => ['report_cards.php']
                    ],
                    [
                        'id'      => 'grading_matrix',
                        'label'   => $is_rtl ? 'مصفوفة الدرجات' : 'Grade Matrix & Ranks',
                        'url'     => $baseUrl . '/modules/Grading/matrix.php',
                        'icon'    => 'fa-solid fa-table-cells',
                        'scripts' => ['matrix.php']
                    ],
                    [
                        'id'      => 'grading_exams',
                        'label'   => $is_rtl ? 'الاختبارات والتقييمات' : 'Exams & Sessions',
                        'url'     => $baseUrl . '/modules/Grading/exams.php',
                        'icon'    => 'fa-solid fa-clipboard-check',
                        'scripts' => ['exams.php', 'exams_portal.php']
                    ],
                    [
                        'id'      => 'grading_scheduling',
                        'label'   => $is_rtl ? 'جدول الامتحانات' : 'Exam Scheduling',
                        'url'     => $baseUrl . '/modules/Grading/scheduling.php',
                        'icon'    => 'fa-solid fa-calendar-check',
                        'scripts' => ['scheduling.php']
                    ],
                    [
                        'id'      => 'grading_transcripts',
                        'label'   => $is_rtl ? 'السجل الأكاديمي' : 'Transcripts & Records',
                        'url'     => $baseUrl . '/modules/Grading/transcript.php',
                        'icon'    => 'fa-solid fa-scroll',
                        'scripts' => ['transcript.php', 'verify.php']
                    ],
                    [
                        'id'      => 'grading_policies',
                        'label'   => $is_rtl ? 'سياسات وقواعد التقييم' : 'Grading Policies',
                        'url'     => $baseUrl . '/modules/Grading/policies.php',
                        'icon'    => 'fa-solid fa-scale-balanced',
                        'scripts' => ['policies.php']
                    ]
                ]
            ],
            'lms' => [
                'title' => $is_rtl ? 'إدارة التعلم والفصول' : 'LMS & Classes',
                'icon'  => 'fa-solid fa-graduation-cap',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent'],
                'items' => [
                    [
                        'id'      => 'lms_index',
                        'label'   => $is_rtl ? 'لوحة التعلم' : 'LMS Overview',
                        'url'     => $baseUrl . '/modules/LMS/index.php',
                        'icon'    => 'fa-solid fa-shapes',
                        'scripts' => ['index.php', 'teacher_dashboard.php', 'lms_portal.php']
                    ],
                    [
                        'id'      => 'lms_courses',
                        'label'   => $is_rtl ? 'المقررات والمناهج' : 'Courses & Curricula',
                        'url'     => $baseUrl . '/modules/LMS/courses.php',
                        'icon'    => 'fa-solid fa-book-open',
                        'scripts' => ['courses.php']
                    ],
                    [
                        'id'      => 'lms_assignments',
                        'label'   => $is_rtl ? 'الواجبات والتكليفات' : 'Assignments & Tasks',
                        'url'     => $baseUrl . '/modules/LMS/assignments.php',
                        'icon'    => 'fa-solid fa-list-check',
                        'scripts' => ['assignments.php', 'assignment_grading.php']
                    ],
                    [
                        'id'      => 'lms_attendance',
                        'label'   => $is_rtl ? 'حضور وغياب الطلاب' : 'Student Attendance',
                        'url'     => $baseUrl . '/modules/LMS/attendance.php',
                        'icon'    => 'fa-solid fa-user-check',
                        'scripts' => ['attendance.php', 'teacher_daily_log.php']
                    ],
                    [
                        'id'      => 'lms_materials',
                        'label'   => $is_rtl ? 'المكتبة والملفات' : 'Learning Materials',
                        'url'     => $baseUrl . '/modules/LMS/materials.php',
                        'icon'    => 'fa-solid fa-folder-open',
                        'scripts' => ['materials.php']
                    ],
                    [
                        'id'      => 'lms_timetable',
                        'label'   => $is_rtl ? 'الجدول الحصص الأسبوعي' : 'Class Timetable',
                        'url'     => $baseUrl . '/modules/LMS/timetable.php',
                        'icon'    => 'fa-solid fa-clock',
                        'scripts' => ['timetable.php', 'print_timetable.php', 'save_schedule_slot.php']
                    ],
                    [
                        'id'      => 'lms_calendar',
                        'label'   => $is_rtl ? 'تقويم التعلم' : 'LMS Calendar',
                        'url'     => $baseUrl . '/modules/LMS/calendar.php',
                        'icon'    => 'fa-solid fa-calendar-days',
                        'scripts' => ['calendar.php', 'print_academic_calendar.php']
                    ],
                    [
                        'id'      => 'lms_discussions',
                        'label'   => $is_rtl ? 'حلقات النقاش' : 'Discussions & Forum',
                        'url'     => $baseUrl . '/modules/LMS/discussions.php',
                        'icon'    => 'fa-solid fa-comments',
                        'scripts' => ['discussions.php', 'messages.php']
                    ],
                    [
                        'id'      => 'lms_announcements',
                        'label'   => $is_rtl ? 'الإعلانات والتعاميم' : 'Announcements',
                        'url'     => $baseUrl . '/modules/LMS/announcements.php',
                        'icon'    => 'fa-solid fa-bullhorn',
                        'scripts' => ['announcements.php']
                    ]
                ]
            ],
            'finance' => [
                'title' => $is_rtl ? 'المالية والخزينة' : 'Finance & Treasury',
                'icon'  => 'fa-solid fa-vault',
                'roles' => ['admin', 'school_admin', 'finance'],
                'items' => [
                    [
                        'id'      => 'fin_index',
                        'label'   => $is_rtl ? 'لوحة المالية' : 'Finance Overview',
                        'url'     => $baseUrl . '/modules/Finance/index.php',
                        'icon'    => 'fa-solid fa-chart-pie',
                        'scripts' => ['index.php']
                    ],
                    [
                        'id'      => 'fin_fees',
                        'label'   => $is_rtl ? 'بوابة الرسوم والمستحقات' : 'Student Fees Portal',
                        'url'     => $baseUrl . '/modules/Finance/fees_portal.php',
                        'icon'    => 'fa-solid fa-file-invoice-dollar',
                        'scripts' => ['fees_portal.php', 'fees.php', 'account_statement.php']
                    ],
                    [
                        'id'      => 'fin_structures',
                        'label'   => $is_rtl ? 'هياكل وبنود الرسوم' : 'Fee Structures',
                        'url'     => $baseUrl . '/modules/Finance/fee_structures.php',
                        'icon'    => 'fa-solid fa-layer-group',
                        'scripts' => ['fee_structures.php']
                    ],
                    [
                        'id'      => 'fin_payments',
                        'label'   => $is_rtl ? 'التحصيلات وسندات القبض' : 'Payments & Receipts',
                        'url'     => $baseUrl . '/modules/Finance/payments.php',
                        'icon'    => 'fa-solid fa-receipt',
                        'scripts' => ['payments.php']
                    ],
                    [
                        'id'      => 'fin_settlement',
                        'label'   => $is_rtl ? 'التسوية والإغلاق اليومي' : 'Daily Settlement',
                        'url'     => $baseUrl . '/modules/Finance/daily_settlement.php',
                        'icon'    => 'fa-solid fa-cash-register',
                        'scripts' => ['daily_settlement.php']
                    ],
                    [
                        'id'      => 'fin_discounts',
                        'label'   => $is_rtl ? 'الخصومات والمنح' : 'Discounts & Grants',
                        'url'     => $baseUrl . '/modules/Finance/discounts.php',
                        'icon'    => 'fa-solid fa-tags',
                        'scripts' => ['discounts.php']
                    ],
                    [
                        'id'      => 'fin_payroll',
                        'label'   => $is_rtl ? 'صرف الرواتب' : 'Payroll Disbursement',
                        'url'     => $baseUrl . '/modules/Finance/payroll_disbursement.php',
                        'icon'    => 'fa-solid fa-money-check-dollar',
                        'scripts' => ['payroll_disbursement.php']
                    ],
                    [
                        'id'      => 'fin_clearance',
                        'label'   => $is_rtl ? 'براءة الذمة المالية' : 'Financial Clearance',
                        'url'     => $baseUrl . '/modules/Finance/clearance.php',
                        'icon'    => 'fa-solid fa-circle-check',
                        'scripts' => ['clearance.php']
                    ]
                ]
            ],
            'hr' => [
                'title' => $is_rtl ? 'الموارد البشرية' : 'HR & Personnel',
                'icon'  => 'fa-solid fa-users-gear',
                'roles' => ['admin', 'school_admin', 'hr'],
                'items' => [
                    [
                        'id'      => 'hr_index',
                        'label'   => $is_rtl ? 'لوحة الموارد البشرية' : 'HR Overview',
                        'url'     => $baseUrl . '/modules/HR/index.php',
                        'icon'    => 'fa-solid fa-gauge-high',
                        'scripts' => ['index.php', 'hr_dashboard.php', 'hr_portal.php']
                    ],
                    [
                        'id'      => 'hr_employees',
                        'label'   => $is_rtl ? 'سجل الموظفين والكادر' : 'Employee Directory',
                        'url'     => $baseUrl . '/modules/HR/employees.php',
                        'icon'    => 'fa-solid fa-id-card',
                        'scripts' => ['employees.php', 'add_employee.php']
                    ],
                    [
                        'id'      => 'hr_attendance',
                        'label'   => $is_rtl ? 'حضور وانصراف الكادر' : 'Staff Attendance',
                        'url'     => $baseUrl . '/modules/HR/staff_attendance.php',
                        'icon'    => 'fa-solid fa-user-clock',
                        'scripts' => ['staff_attendance.php']
                    ],
                    [
                        'id'      => 'hr_leaves',
                        'label'   => $is_rtl ? 'طلبات الإجازات' : 'Leave Requests',
                        'url'     => $baseUrl . '/modules/HR/leaves.php',
                        'icon'    => 'fa-solid fa-plane-departure',
                        'scripts' => ['leaves.php']
                    ],
                    [
                        'id'      => 'hr_payroll',
                        'label'   => $is_rtl ? 'مسيرات الرواتب' : 'Payroll Ledger',
                        'url'     => $baseUrl . '/modules/HR/payroll.php',
                        'icon'    => 'fa-solid fa-money-bill-wave',
                        'scripts' => ['payroll.php', 'payroll_ledger.php']
                    ],
                    [
                        'id'      => 'hr_departments',
                        'label'   => $is_rtl ? 'الأقسام الإدارية' : 'Departments',
                        'url'     => $baseUrl . '/modules/HR/departments.php',
                        'icon'    => 'fa-solid fa-building-user',
                        'scripts' => ['departments.php']
                    ],
                    [
                        'id'      => 'hr_evaluations',
                        'label'   => $is_rtl ? 'تقييم الأداء السنوي' : 'Staff Evaluations',
                        'url'     => $baseUrl . '/modules/HR/evaluations.php',
                        'icon'    => 'fa-solid fa-star-half-stroke',
                        'scripts' => ['evaluations.php']
                    ],
                    [
                        'id'      => 'hr_advances',
                        'label'   => $is_rtl ? 'السلف المالية' : 'Salary Advances',
                        'url'     => $baseUrl . '/modules/HR/advances.php',
                        'icon'    => 'fa-solid fa-hand-holding-dollar',
                        'scripts' => ['advances.php']
                    ]
                ]
            ],
            'support' => [
                'title' => $is_rtl ? 'الدعم والتوثيق' : 'Support & Docs',
                'icon'  => 'fa-solid fa-circle-question',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr'],
                'items' => [
                    [
                        'id'      => 'support_desk',
                        'label'   => $is_rtl ? 'مركز الدعم الفني' : 'Support Center',
                        'url'     => $baseUrl . '/support.php',
                        'icon'    => 'fa-solid fa-headset',
                        'scripts' => ['support.php']
                    ],
                    [
                        'id'      => 'support_docs',
                        'label'   => $is_rtl ? 'دليل النظام والتوثيق' : 'Documentation',
                        'url'     => $baseUrl . '/docs.php',
                        'icon'    => 'fa-solid fa-book-bookmark',
                        'scripts' => ['docs.php']
                    ]
                ]
            ],
            'docs' => [
                'title' => $is_rtl ? 'الدعم والتوثيق' : 'Support & Docs',
                'icon'  => 'fa-solid fa-circle-question',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr'],
                'items' => [
                    [
                        'id'      => 'docs_main',
                        'label'   => $is_rtl ? 'دليل النظام والتوثيق' : 'Documentation',
                        'url'     => $baseUrl . '/docs.php',
                        'icon'    => 'fa-solid fa-book-bookmark',
                        'scripts' => ['docs.php']
                    ],
                    [
                        'id'      => 'docs_support',
                        'label'   => $is_rtl ? 'مركز الدعم الفني' : 'Support Center',
                        'url'     => $baseUrl . '/support.php',
                        'icon'    => 'fa-solid fa-headset',
                        'scripts' => ['support.php']
                    ]
                ]
            ]
        ];

        // Global Portals & Module Switcher list
        $all_modules = [
            [
                'id'    => 'dashboard',
                'label' => $is_rtl ? 'لوحة المؤشرات' : 'Main Dashboard',
                'url'   => $baseUrl . '/dashboard.php',
                'icon'  => 'fa-solid fa-gauge',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr']
            ],
            [
                'id'    => 'admin',
                'label' => $is_rtl ? 'الإدارة والتحكم' : 'Administration',
                'url'   => $baseUrl . '/modules/Administration/index.php',
                'icon'  => 'fa-solid fa-shield-halved',
                'roles' => ['admin', 'school_admin']
            ],
            [
                'id'    => 'lms',
                'label' => $is_rtl ? 'إدارة التعلم (LMS)' : 'LMS & Classes',
                'url'   => $baseUrl . '/modules/LMS/index.php',
                'icon'  => 'fa-solid fa-graduation-cap',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent']
            ],
            [
                'id'    => 'grading',
                'label' => $is_rtl ? 'الامتحانات والتقييم' : 'Grading & Exams',
                'url'   => $baseUrl . '/modules/Grading/index.php',
                'icon'  => 'fa-solid fa-file-pen',
                'roles' => ['admin', 'school_admin', 'teacher']
            ],
            [
                'id'    => 'finance',
                'label' => $is_rtl ? 'المالية والخزينة' : 'Finance & Treasury',
                'url'   => $baseUrl . '/modules/Finance/index.php',
                'icon'  => 'fa-solid fa-vault',
                'roles' => ['admin', 'school_admin', 'finance']
            ],
            [
                'id'    => 'hr',
                'label' => $is_rtl ? 'الموارد البشرية' : 'HR & Staff',
                'url'   => $baseUrl . '/modules/HR/index.php',
                'icon'  => 'fa-solid fa-users-gear',
                'roles' => ['admin', 'school_admin', 'hr']
            ],
            [
                'id'    => 'support',
                'label' => $is_rtl ? 'الدعم الفني IT' : 'IT Support Desk',
                'url'   => $baseUrl . '/support.php',
                'icon'  => 'fa-solid fa-headset',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr']
            ],
            [
                'id'    => 'docs',
                'label' => $is_rtl ? 'دليل التوثيق' : 'Documentation',
                'url'   => $baseUrl . '/docs.php',
                'icon'  => 'fa-solid fa-book-bookmark',
                'roles' => ['admin', 'school_admin', 'teacher', 'student', 'parent', 'finance', 'hr']
            ]
        ];

        ob_start();
        $GLOBALS['app_sidebar_rendered'] = true;
        
        echo '<aside class="app-sidebar sidebar-collapsed sidebar-compact" id="appMainSidebar" aria-label="Main Navigation">';
        
        // Sidebar Brand Header (Height synchronized with top navbar: 60px)
        echo '<div class="sidebar-brand-header border-bottom border-border d-flex align-items-center justify-content-between px-2" style="height: 60px; min-height: 60px;">';
        echo '  <a href="' . htmlspecialchars($baseUrl . '/dashboard.php') . '" class="d-flex align-items-center gap-2 text-decoration-none text-foreground sidebar-brand-link text-truncate" style="min-width: 0;" title="' . htmlspecialchars($school_name) . '">';
        if (function_exists('render_theme_logo_html')) {
            echo render_theme_logo_html('width: 34px; height: 34px; object-fit: contain;', 'rounded shadow-xs flex-shrink-0 sidebar-brand-logo');
        } elseif (!empty($school_logo)) {
            echo '    <img src="' . htmlspecialchars($school_logo) . '" alt="Logo" class="rounded border p-0.5 bg-white flex-shrink-0 sidebar-brand-logo" style="width: 34px; height: 34px; object-fit: contain;">';
        } else {
            echo '    <div class="topbar-brand-icon flex-shrink-0 sidebar-brand-logo" style="width: 34px; height: 34px; font-size: 0.95rem;">' . htmlspecialchars(mb_substr($school_name, 0, 1)) . '</div>';
        }
        echo '    <span class="fw-bold sidebar-text text-truncate font-heading" style="font-size: 0.95rem;">' . htmlspecialchars($school_name) . '</span>';
        echo '  </a>';
        
        // Header Collapse Toggle (Visible in expanded state)
        echo '  <button type="button" id="sidebarToggleBtn" class="btn btn-sm btn-outline-border p-1 rounded-lg flex-shrink-0 sidebar-toggle-btn" title="' . ($is_rtl ? 'تبديل العرض المضغوط للشريط الجانبي' : 'Toggle compact sidebar view') . '" aria-label="Toggle Sidebar">';
        echo '    <i class="fa-solid fa-angles-' . ($is_rtl ? 'right' : 'left') . ' text-muted-foreground sidebar-toggle-icon"></i>';
        echo '  </button>';
        echo '</div>';

        // Sidebar Navigation Links (Contextual Dynamic Module Links + Module Switcher)
        echo '<nav class="flex-grow-1 p-2 overflow-y-auto" id="sidebarNavContainer" style="min-height: 0;">';
        echo '  <div class="nav flex-column gap-1.5">';

        if (!empty($options['sidebar_items'])) {
            // Explicitly provided custom items
            foreach ($options['sidebar_items'] as $item) {
                if (!empty($item['roles']) && !in_array($currentUserRole, $item['roles']) && $currentUserRole !== 'admin') {
                    continue;
                }
                $is_active = (strcasecmp($canonicalActiveMod, $item['id']) === 0 || (!empty($item['scripts']) && in_array($active_script, $item['scripts'])));
                $activeClass = $is_active ? 'active bg-primary text-white shadow-xs' : 'text-foreground hover-bg-secondary';
                $iconColor = $is_active ? 'text-white' : 'text-primary';

                echo '    <a href="' . htmlspecialchars($item['url']) . '" class="nav-link sidebar-nav-item py-2 px-2.5 rounded-xl d-flex align-items-center gap-3 font-medium ' . $activeClass . '" title="' . htmlspecialchars($item['label']) . '" data-bs-toggle="tooltip" data-bs-placement="' . ($is_rtl ? 'left' : 'right') . '">';
                echo '      <i class="' . htmlspecialchars($item['icon']) . ' sidebar-nav-icon ' . $iconColor . '" style="width: 20px; text-align: center; font-size: 1.15rem;"></i>';
                echo '      <span class="sidebar-text text-truncate">' . htmlspecialchars($item['label']) . '</span>';
                echo '    </a>';
            }
        } elseif (!empty($module_menus[$canonicalActiveMod])) {
            $current_menu = $module_menus[$canonicalActiveMod];
            
            // Section 1: Dynamic In-Module Sub-pages
            echo '    <div class="sidebar-section-header px-2 text-truncate" title="' . htmlspecialchars($current_menu['title']) . '">';
            echo '      <i class="' . htmlspecialchars($current_menu['icon']) . ' me-1 text-primary"></i> ' . htmlspecialchars($current_menu['title']);
            echo '    </div>';

            foreach ($current_menu['items'] as $item) {
                if (!empty($item['roles']) && !in_array($currentUserRole, $item['roles']) && $currentUserRole !== 'admin') {
                    continue;
                }
                $is_active = false;
                if (!empty($item['scripts']) && in_array($active_script, $item['scripts'])) {
                    $is_active = true;
                } elseif (basename($item['url']) === $active_script) {
                    $is_active = true;
                }

                $activeClass = $is_active ? 'active bg-primary text-white shadow-xs' : 'text-foreground hover-bg-secondary';
                $iconColor = $is_active ? 'text-white' : 'text-primary';

                echo '    <a href="' . htmlspecialchars($item['url']) . '" class="nav-link sidebar-nav-item py-2 px-2.5 rounded-xl d-flex align-items-center gap-3 font-medium ' . $activeClass . '" title="' . htmlspecialchars($item['label']) . '" data-bs-toggle="tooltip" data-bs-placement="' . ($is_rtl ? 'left' : 'right') . '">';
                echo '      <i class="' . htmlspecialchars($item['icon']) . ' sidebar-nav-icon ' . $iconColor . '" style="width: 20px; text-align: center; font-size: 1.15rem;"></i>';
                echo '      <span class="sidebar-text text-truncate">' . htmlspecialchars($item['label']) . '</span>';
                echo '    </a>';
            }

            // Section 2: Switch Portals / Other Modules Divider & List
            echo '    <div class="sidebar-section-divider"></div>';
            echo '    <div class="sidebar-section-header px-2 text-truncate">';
            echo '      <i class="fa-solid fa-shuffle me-1 text-muted-foreground"></i> ' . ($is_rtl ? 'الأنظمة الأخرى' : 'Switch Portal');
            echo '    </div>';

            foreach ($all_modules as $mod) {
                if ($mod['id'] === $canonicalActiveMod) {
                    continue; // Skip currently active module
                }
                if (!empty($mod['roles']) && !in_array($currentUserRole, $mod['roles']) && $currentUserRole !== 'admin') {
                    continue;
                }

                echo '    <a href="' . htmlspecialchars($mod['url']) . '" class="nav-link sidebar-nav-item sidebar-switch-item py-2 px-2.5 rounded-xl d-flex align-items-center gap-3 font-medium text-foreground hover-bg-secondary" title="' . htmlspecialchars($mod['label']) . '" data-bs-toggle="tooltip" data-bs-placement="' . ($is_rtl ? 'left' : 'right') . '">';
                echo '      <i class="' . htmlspecialchars($mod['icon']) . ' sidebar-nav-icon text-muted-foreground" style="width: 20px; text-align: center; font-size: 1.1rem;"></i>';
                echo '      <span class="sidebar-text text-truncate small font-medium">' . htmlspecialchars($mod['label']) . '</span>';
                echo '    </a>';
            }
        } else {
            // Fallback default modules navigation
            foreach ($all_modules as $item) {
                if (!empty($item['roles']) && !in_array($currentUserRole, $item['roles']) && $currentUserRole !== 'admin') {
                    continue;
                }
                $is_active = (strcasecmp($canonicalActiveMod, $item['id']) === 0);
                $activeClass = $is_active ? 'active bg-primary text-white shadow-xs' : 'text-foreground hover-bg-secondary';
                $iconColor = $is_active ? 'text-white' : 'text-primary';

                echo '    <a href="' . htmlspecialchars($item['url']) . '" class="nav-link sidebar-nav-item py-2 px-2.5 rounded-xl d-flex align-items-center gap-3 font-medium ' . $activeClass . '" title="' . htmlspecialchars($item['label']) . '" data-bs-toggle="tooltip" data-bs-placement="' . ($is_rtl ? 'left' : 'right') . '">';
                echo '      <i class="' . htmlspecialchars($item['icon']) . ' sidebar-nav-icon ' . $iconColor . '" style="width: 20px; text-align: center; font-size: 1.15rem;"></i>';
                echo '      <span class="sidebar-text text-truncate">' . htmlspecialchars($item['label']) . '</span>';
                echo '    </a>';
            }
        }
        echo '  </div>';
        echo '</nav>';

        // Sidebar Footer & Expand/Collapse Rail Action
        echo '<div class="p-2 border-top border-border sidebar-footer text-center d-flex flex-column gap-2">';
        echo '  <button type="button" id="sidebarToggleFooterBtn" class="btn btn-sm btn-outline-border w-100 py-1.5 rounded-lg d-flex align-items-center justify-content-center gap-2 sidebar-toggle-action" title="' . ($is_rtl ? 'تبديل عرض الشريط الجانبي' : 'Toggle sidebar view') . '" aria-label="Toggle Sidebar">';
        echo '    <i class="fa-solid fa-angles-' . ($is_rtl ? 'left' : 'right') . ' text-muted-foreground sidebar-toggle-icon" style="font-size: 0.9rem;"></i>';
        echo '    <span class="sidebar-text small font-medium text-truncate">' . ($is_rtl ? 'تصغير الشريط' : 'Collapse') . '</span>';
        echo '  </button>';
        echo '  <div class="d-flex align-items-center justify-content-center gap-1.5 text-muted-foreground small sidebar-year-badge text-truncate" title="' . htmlspecialchars($active_year) . '">';
        echo '    <i class="fa-solid fa-calendar text-primary flex-shrink-0" style="font-size: 0.82rem;"></i>';
        echo '    <span class="font-monospace text-truncate sidebar-text" style="font-size: 0.75rem;">' . htmlspecialchars($active_year) . '</span>';
        echo '  </div>';
        echo '</div>';

        echo '</aside>'; // End app-sidebar
        echo '<script>if(document.body && !document.body.classList.contains("dashboard-page")) { document.body.classList.add("has-compact-sidebar"); }</script>';

        return ob_get_clean();
    }


    /**
     * End Main Page Layout & Inject Responsive Sidebar + Scroll Sync JavaScript
     */
    public static function end(): void
    {
        $is_rtl = function_exists('is_rtl') ? is_rtl() : false;

        // Close ptrTargetView, mainScrollableContent, and mainContentWrapper
        echo '  </div>'; // #ptrTargetView
        echo '</main>'; // #mainScrollableContent
        echo '</div>'; // #mainContentWrapper
        echo '</div>'; // #appLayoutContainer

        // Universal Platform Footer
        if (function_exists('render_platform_footer')) {
            echo render_platform_footer();
        }

        // Layout Engine Script: Sidebar state management, Window resize listener & Scroll Position Check
        ?>
        <script>
        (function() {
            // -------------------------------------------------------------
            // 1. Sidebar State Management (isCollapsed) & Resize Synchronization
            // -------------------------------------------------------------
            const sidebar = document.getElementById('appMainSidebar');
            const toggleBtn = document.getElementById('sidebarToggleBtn');
            const toggleIcon = toggleBtn ? toggleBtn.querySelector('.sidebar-toggle-icon') : null;
            const isRtl = document.documentElement.getAttribute('dir') === 'rtl';

            // Match media query for responsive breakpoint (992px)
            const mediaQuery = window.matchMedia('(max-width: 992px)');
            let isCollapsed = true; // Always start collapsed on opening any page

            function applySidebarState() {
                if (!sidebar) return;
                if (document.body && document.body.classList.contains('dashboard-page')) {
                    document.body.classList.remove('has-compact-sidebar', 'has-expanded-sidebar');
                    return;
                }
                if (isCollapsed) {
                    sidebar.classList.add('sidebar-collapsed', 'sidebar-compact');
                    sidebar.classList.remove('sidebar-expanded');
                    if (document.body) {
                        document.body.classList.add('has-compact-sidebar');
                        document.body.classList.remove('has-expanded-sidebar');
                    }
                } else {
                    sidebar.classList.remove('sidebar-collapsed', 'sidebar-compact');
                    sidebar.classList.add('sidebar-expanded');
                    if (document.body) {
                        document.body.classList.remove('has-compact-sidebar');
                        document.body.classList.add('has-expanded-sidebar');
                    }
                }

                // Update Header & Footer Toggle Buttons
                const toggleBtns = document.querySelectorAll('#sidebarToggleBtn, #sidebarToggleFooterBtn, [data-action="toggle-sidebar"]');
                toggleBtns.forEach(function(btn) {
                    const icon = btn.querySelector('.sidebar-toggle-icon, i');
                    if (icon) {
                        if (isRtl) {
                            icon.className = isCollapsed 
                                ? 'fa-solid fa-angles-left text-muted-foreground sidebar-toggle-icon' 
                                : 'fa-solid fa-angles-right text-muted-foreground sidebar-toggle-icon';
                        } else {
                            icon.className = isCollapsed 
                                ? 'fa-solid fa-angles-right text-muted-foreground sidebar-toggle-icon' 
                                : 'fa-solid fa-angles-left text-muted-foreground sidebar-toggle-icon';
                        }
                    }
                    const textSpan = btn.querySelector('.sidebar-text');
                    if (textSpan) {
                        textSpan.textContent = isCollapsed 
                            ? (isRtl ? 'توسيع الشريط' : 'Expand') 
                            : (isRtl ? 'تصغير الشريط' : 'Collapse');
                    }
                    btn.title = isCollapsed 
                        ? (isRtl ? 'توسيع الشريط الجانبي' : 'Expand sidebar') 
                        : (isRtl ? 'تصغير الشريط الجانبي' : 'Collapse sidebar');
                });
            }

            // Initial apply
            applySidebarState();

            // Toggle Click Listener (delegated for all header & footer triggers)
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('#sidebarToggleBtn, #sidebarToggleFooterBtn, [data-action="toggle-sidebar"]');
                if (btn) {
                    e.preventDefault();
                    isCollapsed = !isCollapsed;
                    localStorage.setItem('app_sidebar_collapsed', isCollapsed);
                    applySidebarState();
                }
            });

            // Responsive Window Resize Listener
            function handleWindowResize(e) {
                if (e.matches) {
                    // Mobile/Tablet viewport (< 992px) -> Compact view by default
                    if (!isCollapsed) {
                        isCollapsed = true;
                        applySidebarState();
                    }
                }
            }

            if (typeof mediaQuery.addEventListener === 'function') {
                mediaQuery.addEventListener('change', handleWindowResize);
            } else if (typeof mediaQuery.addListener === 'function') {
                mediaQuery.addListener(handleWindowResize);
            }

            // -------------------------------------------------------------
            // 2. Scroll Position Check & Pull-to-Refresh Gesture Coordination
            // -------------------------------------------------------------
            // Defines the scrollable area within the main content wrapper.
            // Verifies that the container's scroll top is zero before triggering refresh.
            const scrollContainer = document.getElementById('mainScrollableContent') || window;

            function getScrollTop() {
                if (scrollContainer === window) {
                    return window.pageYOffset || document.documentElement.scrollTop || 0;
                }
                return scrollContainer.scrollTop || 0;
            }

            function syncScrollPositionWithNativeHost() {
                const currentScrollTop = Math.round(getScrollTop());

                // Notify Native Android WebView Interface if present
                if (window.AndroidInterface && typeof window.AndroidInterface.onScrollPositionChanged === 'function') {
                    window.AndroidInterface.onScrollPositionChanged(currentScrollTop);
                }

                // Add or remove scroll-boundary class on PTR target container
                const ptrTarget = document.getElementById('ptrTargetView');
                if (ptrTarget) {
                    if (currentScrollTop === 0) {
                        ptrTarget.setAttribute('data-scroll-at-top', 'true');
                    } else {
                        ptrTarget.removeAttribute('data-scroll-at-top');
                    }
                }
            }

            // Bind passive scroll listener to the defined scrollable container
            if (scrollContainer === window) {
                window.addEventListener('scroll', syncScrollPositionWithNativeHost, { passive: true });
            } else {
                scrollContainer.addEventListener('scroll', syncScrollPositionWithNativeHost, { passive: true });
                window.addEventListener('scroll', syncScrollPositionWithNativeHost, { passive: true });
            }

            // Sync immediately on initial load
            syncScrollPositionWithNativeHost();
        })();
        </script>
        <?php
    }
}
