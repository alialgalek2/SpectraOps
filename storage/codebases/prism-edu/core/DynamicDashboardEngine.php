<?php
/**
 * core/DynamicDashboardEngine.php
 * Smart Dynamic Engine for Dashboard Modules & Widget Ordering
 * 
 * Verifies commercial license activation, subscribed modules, and granular user/role
 * permissions, producing an independently positionable and customizable widget matrix.
 */

if (!class_exists('DynamicDashboardEngine')) {
    class DynamicDashboardEngine {
        
        /**
         * Get permitted and verified dashboard module widgets for a given user.
         *
         * @param PDO $pdo
         * @param int $userId
         * @param string $role
         * @return array
         */
        public static function getPermittedModules(PDO $pdo, int $userId, string $role): array {
            $is_rtl = function_exists('is_rtl') && is_rtl();
            
            // 1. Verify Commercial License & Subscribed Features
            $licCheck = ['valid' => true];
            if (function_exists('checkSystemLicense')) {
                $licCheck = checkSystemLicense($pdo);
            }
            $is_license_valid = (bool)($licCheck['valid'] ?? true);

            // 2. Fetch Live Dynamic Counters for Badges
            $stats = self::fetchLiveStats($pdo, $role, $userId);

            // 3. Define Master Module Catalog with Subcategories (5 Primary Modules)
            $allModules = [
                'module_administration' => [
                    'id'            => 'module_administration',
                    'system_key'    => 'Administration',
                    'title'         => ($role === 'school_admin') 
                                        ? ($is_rtl ? 'إدارة المدرسة والقبول' : 'School Administration') 
                                        : ($is_rtl ? 'مركز التحكم والإدارة' : 'Admin Control Center'),
                    'subtitle'      => ($role === 'school_admin')
                                        ? ($is_rtl ? 'شؤون الطلاب والقبول، الفصول، المعلمين، والصلاحيات.' : 'Student admissions, roster oversight, and class coordination.')
                                        : ($is_rtl ? 'لوحة القيادة المركزية للنظام والمستخدمين والقبول والهوية.' : 'Comprehensive governance, user roles, admissions, branding, and permissions.'),
                    'icon'          => ($role === 'school_admin') ? 'fa-user-tie' : 'fa-sliders',
                    'color_theme'   => 'primary',
                    'bg_tint'       => 'var(--primary-tint, rgba(79, 70, 229, 0.1))',
                    'icon_color'    => 'var(--primary, #4f46e5)',
                    'landing_url'   => 'modules/Administration/index.php',
                    'badge'         => ($stats['total_users'] ?? 0) . ' ' . ($is_rtl ? 'مستخدم' : 'Users'),
                    'badge_class'   => 'bg-primary-subtle text-primary border border-primary-subtle',
                    'btn_text'      => $is_rtl ? 'فتح مركز الإدارة' : 'Open Admin Hub',
                    'btn_class'     => 'btn-primary',
                    'subcategories' => [
                        ['title' => ($is_rtl ? 'المستخدمين' : 'Users'), 'icon' => 'fa-users', 'url' => 'modules/Administration/users.php'],
                        ['title' => ($is_rtl ? 'قبول الطلاب' : 'Admissions'), 'icon' => 'fa-user-graduate', 'url' => 'modules/Administration/student_admission.php'],
                        ['title' => ($is_rtl ? 'الفصول والمواد' : 'Classes'), 'icon' => 'fa-chalkboard', 'url' => 'modules/Administration/classes.php'],
                        ['title' => ($is_rtl ? 'الصلاحيات' : 'Permissions'), 'icon' => 'fa-user-shield', 'url' => 'modules/Administration/manage_permissions.php'],
                        ['title' => ($is_rtl ? 'التقويم الأكاديمي' : 'Calendar'), 'icon' => 'fa-calendar-days', 'url' => 'modules/Administration/academic_calendar.php'],
                        ['title' => ($is_rtl ? 'هوية المدرسة' : 'Branding'), 'icon' => 'fa-palette', 'url' => 'modules/Administration/institution_settings.php'],
                    ],
                    'permission_check' => function($role) {
                        return in_array($role, ['admin', 'school_admin']) || has_permission('manage_role_permissions') || has_permission('manage_school_profile');
                    }
                ],

                'module_lms' => [
                    'id'            => 'module_lms',
                    'system_key'    => 'LMS',
                    'title'         => $is_rtl ? 'بوابة التعليم والجداول والحضور' : 'LMS & Learning Center',
                    'subtitle'      => $is_rtl ? 'تسجيل حضور الفصل، جداول الحصص الأسبوعية، ودفاتر التحضير والإعلانات.' : 'Class roll-call, RFID badge scanner, lecture timetables, and circulars.',
                    'icon'          => 'fa-clipboard-user',
                    'color_theme'   => 'accent',
                    'bg_tint'       => 'rgba(9, 132, 227, 0.12)',
                    'icon_color'    => '#0984e3',
                    'landing_url'   => 'modules/LMS/index.php',
                    'badge'         => ($stats['total_classes'] ?? 0) . ' ' . ($is_rtl ? 'شعبة نشطة' : 'Active Classes'),
                    'badge_class'   => 'bg-info-subtle text-info border border-info-subtle',
                    'btn_text'      => $is_rtl ? 'فتح بوابة التعليم' : 'Open LMS Hub',
                    'btn_class'     => 'btn-lms-hub',
                    'subcategories' => [
                        ['title' => ($is_rtl ? 'لوحة المعلم' : 'Teacher Hub'), 'icon' => 'fa-chalkboard-user', 'url' => 'modules/LMS/teacher_dashboard.php'],
                        ['title' => ($is_rtl ? 'رصد الحضور' : 'Attendance'), 'icon' => 'fa-clipboard-check', 'url' => 'modules/LMS/attendance.php'],
                        ['title' => ($is_rtl ? 'جدول الحصص' : 'Timetables'), 'icon' => 'fa-calendar-days', 'url' => 'modules/LMS/timetable.php'],
                        ['title' => ($is_rtl ? 'التحضير اليومي' : 'Daily Logs'), 'icon' => 'fa-book-journal-whills', 'url' => 'modules/LMS/teacher_daily_log.php'],
                        ['title' => ($is_rtl ? 'لوحة الإعلانات' : 'Notices'), 'icon' => 'fa-bullhorn', 'url' => 'modules/LMS/announcements.php'],
                    ],
                    'permission_check' => function($role) {
                        return is_module_active('LMS') && (in_array($role, ['admin', 'school_admin', 'teacher', 'student', 'parent']) || has_permission('manage_lms_courses_modules') || has_permission('manage_timetable'));
                    }
                ],

                'module_grading' => [
                    'id'            => 'module_grading',
                    'system_key'    => 'Grading',
                    'title'         => $is_rtl ? 'الامتحانات ورصد الدرجات والشهادات' : 'Exams, Grading & Reports',
                    'subtitle'      => $is_rtl ? 'رصد درجات أعمال السنة والاختبارات وتوليد بطاقات الدرجات والسجل الأكاديمي.' : 'Record continuous marks (40/60), calculate GPA totals, and print scorecards.',
                    'icon'          => 'fa-chart-line',
                    'color_theme'   => 'primary',
                    'bg_tint'       => 'rgba(108, 92, 231, 0.12)',
                    'icon_color'    => '#6c5ce7',
                    'landing_url'   => 'modules/Grading/index.php',
                    'badge'         => $is_rtl ? 'التقييم الفصلي' : 'Active Term GPA',
                    'badge_class'   => 'bg-primary-subtle text-primary border border-primary-subtle',
                    'btn_text'      => $is_rtl ? 'مركز الدرجات والشهادات' : 'Open Grading Hub',
                    'btn_class'     => 'btn-grading-hub',
                    'subcategories' => [
                        ['title' => ($is_rtl ? 'رصد الامتحانات' : 'Exam Marks'), 'icon' => 'fa-square-poll-horizontal', 'url' => 'modules/Grading/exams.php'],
                        ['title' => ($is_rtl ? 'تقييم المعلم (40/60)' : 'Class Grading'), 'icon' => 'fa-chart-simple', 'url' => 'modules/Grading/teacher_grades.php'],
                        ['title' => ($is_rtl ? 'بطاقات الدرجات' : 'Report Cards'), 'icon' => 'fa-id-card-clip', 'url' => 'modules/Grading/report_cards.php'],
                        ['title' => ($is_rtl ? 'السجل الأكاديمي' : 'Transcripts'), 'icon' => 'fa-scroll', 'url' => 'modules/Grading/transcript.php'],
                    ],
                    'permission_check' => function($role) {
                        return is_module_active('Grading') && !in_array($role, ['hr', 'finance']) && (in_array($role, ['admin', 'school_admin', 'teacher', 'student', 'parent']) || has_permission('manage_exams_evaluations') || has_permission('grading'));
                    }
                ],

                'module_finance' => [
                    'id'            => 'module_finance',
                    'system_key'    => 'Finance',
                    'title'         => $is_rtl ? 'المالية والرسوم والخزينة' : 'Finance & Fee Management',
                    'subtitle'      => $is_rtl ? 'إصدار الفواتير، متابعة سداد الرسوم، والحسابات البنكية وصرف المستحقات.' : 'Tuition billing, payment tracking, bank reconciliations, and ledgers.',
                    'icon'          => 'fa-wallet',
                    'color_theme'   => 'warning',
                    'bg_tint'       => 'rgba(245, 158, 11, 0.12)',
                    'icon_color'    => '#f59e0b',
                    'landing_url'   => 'modules/Finance/index.php',
                    'badge'         => ($stats['pending_payments'] ?? 0) . ' ' . ($is_rtl ? 'تحويل معلق' : 'Pending TXs'),
                    'badge_class'   => 'bg-warning-subtle text-warning border border-warning-subtle',
                    'btn_text'      => $is_rtl ? 'إدارة الرسوم والخزينة' : 'Open Finance Desk',
                    'btn_class'     => 'btn-finance-hub',
                    'subcategories' => [
                        ['title' => ($is_rtl ? 'لوحة الخزينة' : 'Treasury Desk'), 'icon' => 'fa-building-columns', 'url' => 'modules/Finance/fees_portal.php'],
                        ['title' => ($is_rtl ? 'هيكل الرسوم' : 'Fee Structures'), 'icon' => 'fa-receipt', 'url' => 'modules/Finance/fees.php'],
                        ['title' => ($is_rtl ? 'إيصالات السداد' : 'Payments'), 'icon' => 'fa-credit-card', 'url' => 'modules/Finance/payments.php'],
                        ['title' => ($is_rtl ? 'كشف الحساب' : 'Statements'), 'icon' => 'fa-file-invoice-dollar', 'url' => 'modules/Finance/account_statement.php'],
                        ['title' => ($is_rtl ? 'صرف الرواتب' : 'Disbursements'), 'icon' => 'fa-money-bill-transfer', 'url' => 'modules/Finance/payroll_disbursement.php'],
                    ],
                    'permission_check' => function($role) {
                        return is_module_active('Finance') && !in_array($role, ['teacher', 'student']) && (in_array($role, ['admin', 'school_admin', 'finance', 'hr']) || has_permission('manage_finance_accounts') || has_permission('manage_tuition_fees_ledger') || has_permission('view_finances'));
                    }
                ],

                'module_hr' => [
                    'id'            => 'module_hr',
                    'system_key'    => 'HR',
                    'title'         => $is_rtl ? 'شؤون الموظفين والرواتب' : 'Personnel & HR Management',
                    'subtitle'      => $is_rtl ? 'إدارة عقود الكوادر، الإجازات، ومسيرات الرواتب الشهرية وسجلات الموظفين.' : 'Staff profiles, leave tracking, payroll compensation, and personnel archives.',
                    'icon'          => 'fa-people-roof',
                    'color_theme'   => 'success',
                    'bg_tint'       => 'rgba(16, 185, 129, 0.12)',
                    'icon_color'    => '#10b981',
                    'landing_url'   => 'modules/HR/index.php',
                    'badge'         => ($stats['total_staff'] ?? 0) . ' ' . ($is_rtl ? 'موظف' : 'Staff Members'),
                    'badge_class'   => 'bg-success-subtle text-success border border-success-subtle',
                    'btn_text'      => $is_rtl ? 'فتح شؤون الموظفين' : 'Open Staff Registry',
                    'btn_class'     => 'btn-hr-hub',
                    'subcategories' => [
                        ['title' => ($is_rtl ? 'دليل الموظفين' : 'Staff Directory'), 'icon' => 'fa-users-gear', 'url' => 'modules/HR/employees.php'],
                        ['title' => ($is_rtl ? 'إضافة موظف' : 'Add Employee'), 'icon' => 'fa-user-plus', 'url' => 'modules/HR/add_employee.php'],
                        ['title' => ($is_rtl ? 'الإجازات' : 'Staff Leaves'), 'icon' => 'fa-person-walking-arrow-right', 'url' => 'modules/HR/leaves.php'],
                        ['title' => ($is_rtl ? 'مسير الرواتب' : 'Payroll Ledger'), 'icon' => 'fa-file-invoice-dollar', 'url' => 'modules/HR/payroll.php'],
                    ],
                    'permission_check' => function($role) {
                        return is_module_active('HR') && (in_array($role, ['admin', 'school_admin', 'hr', 'finance']) || has_permission('manage_staff_directory') || has_permission('manage_payroll_workflows') || has_permission('manage_hr'));
                    }
                ],
            ];

            // 4. Filter Permitted Modules based on License & Permissions
            $permittedModules = [];
            foreach ($allModules as $key => $mod) {
                // Check if system module is enabled in school_settings
                if (!is_module_active($mod['system_key'])) {
                    continue;
                }

                // Check commercial license requirement if enforced
                if (!$is_license_valid && in_array($mod['system_key'], ['Finance', 'HR', 'Grading'])) {
                    // Restrict unlicensed commercial modules if strict license validation fails
                    if (!in_array($role, ['admin', 'super admin'])) {
                        continue;
                    }
                }

                // Check Role & Granular Permissions
                if (is_callable($mod['permission_check'])) {
                    if (call_user_func($mod['permission_check'], $role)) {
                        $permittedModules[$key] = $mod;
                    }
                }
            }

            // 5. Apply User-Customized Sequence Ordering
            $savedOrder = self::getUserModuleOrder($pdo, $userId);
            if (!empty($savedOrder) && is_array($savedOrder)) {
                $ordered = [];
                // Add permitted modules matching user's custom sequence
                foreach ($savedOrder as $modId) {
                    if (isset($permittedModules[$modId])) {
                        $ordered[$modId] = $permittedModules[$modId];
                    }
                }
                // Append any newly permitted or unsequenced modules at the end
                foreach ($permittedModules as $modId => $modData) {
                    if (!isset($ordered[$modId])) {
                        $ordered[$modId] = $modData;
                    }
                }
                return array_values($ordered);
            }

            return array_values($permittedModules);
        }

        /**
         * Fetch live dynamic stats for widget badges.
         */
        private static function fetchLiveStats(PDO $pdo, string $role, int $userId): array {
            $stats = [
                'total_users'      => 0,
                'total_students'   => 0,
                'total_classes'    => 0,
                'total_staff'      => 0,
                'pending_payments' => 0
            ];

            try {
                $stats['total_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
            } catch (\Throwable $e) {}

            try {
                $stats['total_students'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
            } catch (\Throwable $e) {}

            try {
                $stats['total_classes'] = (int)$pdo->query("SELECT COUNT(*) FROM classes")->fetchColumn();
            } catch (\Throwable $e) {}

            try {
                $stats['total_staff'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('teacher', 'admin', 'hr', 'finance', 'school_admin') AND (is_deleted = 0 OR is_deleted IS NULL)")->fetchColumn();
            } catch (\Throwable $e) {}

            try {
                $stats['pending_payments'] = (int)$pdo->query("SELECT COUNT(*) FROM bank_transactions WHERE status = 'Pending Verification'")->fetchColumn();
            } catch (\Throwable $e) {}

            return $stats;
        }

        /**
         * Fetch user's custom module sequence from users table.
         */
        public static function getUserModuleOrder(PDO $pdo, int $userId): ?array {
            if (!$userId) return null;
            try {
                self::ensureModuleOrderColumnExists($pdo);
                $stmt = $pdo->prepare("SELECT dashboard_modules_order FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                $val = $stmt->fetchColumn();
                if (!empty($val)) {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            } catch (\Throwable $e) {}
            return null;
        }

        /**
         * Ensure dashboard_modules_order column exists in users table.
         */
        public static function ensureModuleOrderColumnExists(PDO $pdo): void {
            static $checked = false;
            if ($checked) return;
            try {
                $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'dashboard_modules_order'")->fetch();
                if (!$check) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN dashboard_modules_order TEXT NULL AFTER widget_order");
                }
                $checked = true;
            } catch (\Throwable $e) {}
        }
    }
}
