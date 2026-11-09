<?php
/**
 * Core Initialization & Bootstrap Engine (Umbrella Architecture)
 * Centralizes authentication, database, licensing, i18n, and pluggable module registry.
 */

// 0. Production Security & Session Bootstrap
require_once dirname(__DIR__) . '/bootstrap/security.php';

// 0.1 License Gatekeeper Middleware
require_once dirname(__DIR__) . '/bootstrap/license_check.php';

// 1. Core Path and URL Definitions
if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
if (!defined('MODULES_PATH')) {
    define('MODULES_PATH', BASE_PATH . '/modules');
}
if (!defined('CORE_PATH')) {
    define('CORE_PATH', BASE_PATH . '/core');
}
if (!defined('BASE_URL')) {
    define('BASE_URL', '/school_system');
}

// 2. Global Database Service & PDO Instance
require_once CORE_PATH . '/Database.php';
$pdo = Database::getConnection();

if (!function_exists('getPDO')) {
    function getPDO(): PDO {
        global $pdo;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        return Database::getConnection();
    }
}

// 3. Global Authentication & Access Control
require_once CORE_PATH . '/Auth.php';
require_once CORE_PATH . '/LoginSecurity.php';

// Verify session inactivity timeout globally if user is logged in
if (isset($_SESSION['user_id'])) {
    Auth::check();
}

// 4. Global Event Dispatcher (Inter-module cross-talk)
require_once CORE_PATH . '/EventDispatcher.php';

// 4.1 Master Academic Calendar Engine
require_once CORE_PATH . '/AcademicCalendar.php';

// 4.2 Privacy-First Telemetry & Bug Reporting Engine
require_once CORE_PATH . '/Telemetry.php';
Telemetry::registerHandlers();


// 4.3 Master System Update Architecture & Non-Blocking Migrator
require_once CORE_PATH . '/SystemUpdater.php';
require_once BASE_PATH . '/database/Migrator.php';

// 4.4 IT Support & Ticketing Architecture
require_once CORE_PATH . '/SupportTicket.php';

// 4.5 Application Environment & Layout Architecture
if (!defined('APP_ENV')) {
    define('APP_ENV', getenv('APP_ENV') ?: (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']) ? 'development' : 'production'));
}
require_once CORE_PATH . '/Layout.php';

// 4.5.1 App Services Autoloader & Dynamic Packaging Architecture
spl_autoload_register(function ($class) {
    $prefix = 'App\\Services\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) === 0) {
        $relativeClass = substr($class, $len);
        $dirs = [
            defined('BASE_PATH') ? BASE_PATH . '/src/Services/' : __DIR__ . '/../src/Services/',
            defined('BASE_PATH') ? BASE_PATH . '/services/' : __DIR__ . '/../services/'
        ];
        foreach ($dirs as $dir) {
            $file = $dir . str_replace('\\', '/', $relativeClass) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// Wire Central Fleet Hub Reporting
$hubClient = \App\Services\HubClient::get();
if ($hubClient && method_exists(\App\Services\TelemetryReporter::class, 'registerGlobalHandlers')) {
    \App\Services\TelemetryReporter::registerGlobalHandlers($hubClient);
}

// 4.6 Android App & WebView Detection Architecture
if (!function_exists('is_android_app')) {
    function is_android_app(): bool {
        if (isset($_GET['view'])) {
            if ($_GET['view'] === 'app') {
                $_SESSION['is_android_app'] = true;
                return true;
            } elseif (in_array($_GET['view'], ['web', 'desktop'])) {
                $_SESSION['is_android_app'] = false;
                return false;
            }
        }
        if (!empty($_SESSION['is_android_app'])) {
            return true;
        }
        $isNative = isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
            || str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'PrismApp')
            || (isset($_GET['view']) && $_GET['view'] === 'app');
        if ($isNative) {
            $_SESSION['is_android_app'] = true;
        }
        return $isNative;
    }
}
$isAndroidApp = is_android_app();


// 5. Global i18n / Bilingual & Theme Engine
require_once BASE_PATH . '/includes/lang.php';

// 5.1 Hydrate User Theme Preference from Database if Logged In
if (isset($_SESSION['user_id']) && empty($_SESSION['theme_preference']) && isset($pdo)) {
    try {
        $uPrefStmt = $pdo->prepare("SELECT theme_preference FROM users WHERE user_id = ?");
        $uPrefStmt->execute([$_SESSION['user_id']]);
        $uPref = $uPrefStmt->fetchColumn();
        if ($uPref && in_array($uPref, ['light', 'dark'])) {
            $_SESSION['theme_preference'] = $uPref;
            if (empty($_COOKIE['theme_mode']) || $_COOKIE['theme_mode'] !== $uPref) {
                setcookie('theme_mode', $uPref, time() + (86400 * 365), '/', '', false, false);
            }
        }
    } catch (Exception $e) {}
}

// 6. Global Institution Profile & Branding
$global_branding = [
    'school_name'    => 'School System',
    'school_phone'   => '',
    'school_email'   => '',
    'school_website' => '',
    'school_address' => '',
    'logo_path'      => '',
    'academic_year'  => '2025/2026'
];

try {
    $bStmt = $pdo->query("SELECT * FROM institution_branding ORDER BY branding_id DESC LIMIT 1");
    $bData = $bStmt->fetch();
    if ($bData) {
        $global_branding = array_merge($global_branding, $bData);
    }
    // Check school_settings for branding and academic year
    $sStmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings WHERE setting_key IN ('school_name', 'school_logo', 'academic_year', 'current_session')");
    $sRows = $sStmt->fetchAll();
    foreach ($sRows as $row) {
        if ($row['setting_key'] === 'school_name' && !empty($row['setting_value'])) {
            $global_branding['school_name'] = $row['setting_value'];
        }
        if ($row['setting_key'] === 'school_logo' && !empty($row['setting_value'])) {
            $global_branding['logo_path'] = $row['setting_value'];
        }
        if (($row['setting_key'] === 'academic_year' || $row['setting_key'] === 'current_session') && !empty($row['setting_value'])) {
            $global_branding['academic_year'] = $row['setting_value'];
        }
    }
} catch (Exception $e) {}

// 7. Global License Verification & Dynamic Dashboard Service
if (file_exists(BASE_PATH . '/licensing/license_helper.php')) {
    require_once BASE_PATH . '/licensing/license_helper.php';
}
if (file_exists(__DIR__ . '/DynamicDashboardEngine.php')) {
    require_once __DIR__ . '/DynamicDashboardEngine.php';
}

// 7. Pluggable Module Registry (Toggle modules on/off dynamically)
$active_modules = [
    'Administration' => true,
    'LMS'            => true,
    'Grading'        => true,
    'Finance'        => true,
    'HR'             => true
];

// Helper to check module active status
if (!function_exists('is_module_active')) {
    /**
     * @param string $module_name
     * @return bool
     */
    function is_module_active(string $module_name): bool {
        global $active_modules;
        return $active_modules[$module_name] ?? false;
    }
}

// Helper to get relative module URL
if (!function_exists('get_module_url')) {
    /**
     * @param string $module_name
     * @param string $page
     * @return string
     */
    function get_module_url(string $module_name, string $page = 'index.php'): string {
        return BASE_URL . '/modules/' . $module_name . '/' . ltrim($page, '/');
    }
}

// Helper to get absolute module directory path
if (!function_exists('get_module_path')) {
    /**
     * @param string $module_name
     * @return string
     */
    function get_module_path(string $module_name): string {
        return MODULES_PATH . '/' . $module_name;
    }
}

// Check if current user is allowed to access specific operational zones
if (!function_exists('user_has_access')) {
    /**
     * @param array|string $allowed_roles
     * @return bool
     */
    function user_has_access($allowed_roles = []): bool {
        if (!isset($_SESSION['role'])) return false;
        
        // System Administrator (admin) always has absolute access
        if ($_SESSION['role'] === 'admin') return true;
        
        if (is_string($allowed_roles)) {
            $allowed_roles = [$allowed_roles];
        }
        
        // Check if the current user's role is in the allowed list
        return in_array($_SESSION['role'], $allowed_roles);
    }
}

if (!function_exists('has_permission')) {
    /**
     * Check if current user or specified role has granular permission for a specific feature key.
     * Supports both signatures:
     *   - has_permission(string $feature_key): bool
     *   - has_permission(PDO $pdo, string $role, string $feature_key): bool
     *
     * @param mixed $arg1 Feature key string or PDO instance
     * @param string|null $arg2 Role name (legacy signature)
     * @param string|null $arg3 Feature key (legacy signature)
     * @return bool
     */
    function has_permission($arg1, $arg2 = null, $arg3 = null): bool {
        global $pdo;
        
        // Determine calling signature
        if ($arg2 === null && $arg3 === null) {
            // Calling style: has_permission('view_finances')
            $feature_key = (string)$arg1;
            $db = $pdo ?? Database::getConnection();
            $user_id = $_SESSION['user_id'] ?? null;
            $role = strtolower($_SESSION['role'] ?? '');
        } else {
            // Calling style: has_permission($pdo, $role, $feature_key)
            $db = is_object($arg1) ? $arg1 : ($pdo ?? Database::getConnection());
            $role = strtolower((string)$arg2);
            $feature_key = (string)$arg3;
            $user_id = $_SESSION['user_id'] ?? null;
        }

        if (empty($role) && empty($user_id)) {
            return false;
        }

        // Admin always has absolute access
        if ($role === 'admin' || $role === 'super admin') {
            return true;
        }

        // 1. Check user_permissions matrix table for explicit user-level override
        if ($user_id && $db) {
            try {
                $stmt = $db->prepare("SELECT is_allowed FROM user_permissions WHERE user_id = ? AND feature_key = ?");
                $stmt->execute([$user_id, $feature_key]);
                $res = $stmt->fetch();
                if ($res !== false && isset($res['is_allowed'])) {
                    return (int)$res['is_allowed'] === 1;
                }
            } catch (\Throwable $e) {}
        }

        // 2. Role-based standard defaults
        $defaults = [
            'school_admin' => [
                'manage_branding_logo', 'manage_school_profile', 'manage_dynamic_theme',
                'manage_role_permissions', 'manage_academic_calendar',
                'manage_timetable_slots', 'manage_teacher_room_assignment', 'manage_timetable',
                'manage_staff_directory', 'manage_payroll_workflows', 'manage_hr',
                'manage_finance_accounts', 'manage_tuition_fees_ledger', 'view_finances',
                'manage_lms_courses_modules', 'manage_exams_evaluations', 'manage_exams',
                'manage_system_licensing', 'manage_system_updates_migrations', 'manage_telemetry_logs',
                'manage_sub_departments', 'manage_department_heads', 'manage_student_affairs', 
                'manage_transport_dept', 'manage_facilities_dept', 'manage_activities_dept',
                'manage_admissions', 'manage_classes', 'manage_notices', 'view_students', 'grading'
            ],
            'teacher'      => [
                'manage_lms_courses_modules', 'manage_exams_evaluations', 'manage_exams', 'manage_grades', 
                'manage_timetable_slots', 'manage_timetable', 'view_timetable', 'manage_notices', 'post_announcements',
                'view_students', 'daily_logs', 'review_assignments', 'grading', 'lesson_plans'
            ],
            'finance'      => [
                'manage_finance_accounts', 'manage_tuition_fees_ledger', 'view_finances', 
                'manage_payroll_workflows', 'manage_payroll', 'manage_notices', 'view_fees', 'pay_fees'
            ],
            'hr'           => [
                'manage_staff_directory', 'manage_payroll_workflows', 'manage_hr', 'manage_payroll', 
                'manage_facilities_dept', 'manage_transport_dept', 'manage_notices', 'staff_leaves'
            ],
            'student'      => ['view_grades', 'view_timetable', 'view_announcements', 'submit_assignments', 'view_fees'],
            'parent'       => ['view_grades', 'view_announcements', 'view_fees', 'pay_fees', 'view_attendance']
        ];

        if (isset($defaults[$role]) && in_array($feature_key, $defaults[$role])) {
            return true;
        }

        // 3. Fallback check on role_permissions if available
        if ($db) {
            try {
                $stmt = $db->prepare("
                    SELECT 1 FROM roles r 
                    JOIN role_permissions rp ON r.role_id = rp.role_id 
                    JOIN permissions p ON rp.permission_id = p.permission_id 
                    WHERE (LOWER(r.role_name) = ? OR LOWER(r.role_name) = ?) 
                      AND (p.action = ? OR p.module = ?)
                    LIMIT 1
                ");
                $stmt->execute([$role, ucfirst($role), $feature_key, $feature_key]);
                if ($stmt->fetch()) {
                    return true;
                }
            } catch (\Throwable $e) {}
        }

        return false;
    }
}



