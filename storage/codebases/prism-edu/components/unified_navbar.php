<?php
/**
 * PRISM EDU - Unified Top Navigation Component
 * Provides sticky unified header, integrated search, theme switcher, and slide-out offcanvas account drawer.
 */

$baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
$userName = class_exists('Auth') && Auth::check() ? Auth::fullName() : ($userName ?? 'Administrator');
$userRole = class_exists('Auth') && Auth::check() ? ucfirst(Auth::role()) : ($userRole ?? 'General Manager');
$userAvatar = class_exists('Auth') && Auth::check() && !empty($_SESSION['avatar']) 
    ? $_SESSION['avatar'] 
    : ($userAvatar ?? $baseUrl . '/assets/img/default-avatar.png');
$activeNav = $activeNav ?? 'dashboard';

// Operational routes
$homeUrl = $baseUrl . '/dashboard.php';
$attendanceUrl = $baseUrl . '/modules/Administration/attendance_desk.php';
$admissionsUrl = $baseUrl . '/modules/Administration/users.php';
$financeUrl = $baseUrl . '/modules/Finance/fees.php';
$academicsUrl = $baseUrl . '/modules/LMS/index.php';
$reportsUrl = $baseUrl . '/modules/Grading/analytics.php';
$profileUrl = $baseUrl . '/modules/Administration/users.php';
$settingsUrl = $baseUrl . '/modules/Administration/institution_settings.php';
$logoutUrl = $baseUrl . '/logout.php';
$isArabic = (isset($_SESSION['lang']) && $_SESSION['lang'] === 'ar') || (function_exists('is_rtl') && is_rtl());
?>
<header class="prism-navbar-unified">
    <div class="nav-cluster-left">
        <!-- 1. Offcanvas Drawer Toggle -->
        <button id="globalNavToggleBtn" class="nav-action-btn" aria-label="Open Navigation Drawer" title="Navigation Menu">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <!-- 2. Interactive Logo & Title (Home Route) -->
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-brand-anchor" title="Return to Dashboard">
            <svg class="brand-svg-mark" viewBox="0 0 70 70" width="30" height="30">
                <polygon points="10,54 50,54 44,45 19,45" fill="#36527b" />
                <polygon points="50,54 30,14 24,24 40,47" fill="#0dc0e7" />
                <polygon points="30,14 10,54 19,45 30,26" fill="#2c88c1" />
                <polygon points="30,28 24,42 36,42" fill="#bef6fd" />
            </svg>
            <span class="brand-wordmark">PRISM <span class="brand-pill">EDU</span></span>
        </a>

        <!-- 3. Inline Search Bar -->
        <div class="nav-search-container" id="navSearchContainer">
            <svg class="search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
            <input type="text" id="globalSearchInput" class="nav-search-input" placeholder="<?= $isArabic ? 'البحث عن الطلاب، الفصول، الفواتير...' : 'Search students, classes, invoices...' ?>" autocomplete="off">
            <kbd class="search-kbd">⌘K</kbd>
        </div>
    </div>

    <div class="nav-cluster-right">
        <!-- Direct Home Shortcut -->
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="nav-action-btn" title="Dashboard Home" aria-label="Dashboard Home">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
            </svg>
        </a>

        <!-- Dark / Light Theme Toggle -->
        <button class="nav-action-btn" id="themeToggleBtn" title="Toggle Theme" aria-label="Toggle Dark/Light Mode">
            <!-- Sun Icon (Active in Dark Mode) -->
            <svg class="theme-icon-sun" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <!-- Moon Icon (Active in Light Mode) -->
            <svg class="theme-icon-moon" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
        </button>

        <!-- Notification Center -->
        <button class="nav-action-btn notif-wrapper" id="navNotifTrigger" title="Notifications" aria-label="Notifications">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <span class="notif-badge">3</span>
        </button>

        <!-- Profile Drawer Trigger -->
        <button class="nav-user-avatar-btn" id="navUserMenuTrigger" aria-label="Open User Menu">
            <img src="<?= htmlspecialchars($userAvatar) ?>" alt="Avatar" class="avatar-ring">
        </button>
    </div>
</header>

<!-- Slide-out Drawer / Account Sheet -->
<div class="prism-sheet-backdrop" id="unifiedNavBackdrop"></div>

<aside class="prism-nav-drawer" id="unifiedNavDrawer">
    <div class="drawer-drag-pill"></div>
    
    <!-- User Profile Header -->
    <div class="drawer-user-card">
        <img src="<?= htmlspecialchars($userAvatar) ?>" alt="User" class="drawer-avatar">
        <div class="drawer-user-meta">
            <h4 class="user-display-name"><?= htmlspecialchars($userName) ?></h4>
            <span class="user-role-tag"><?= htmlspecialchars($userRole) ?></span>
        </div>
        <span class="live-status-pill"><?= $isArabic ? 'متصل' : 'Active' ?></span>
    </div>

    <hr class="drawer-divider">

    <!-- Fast-Navigation Grid -->
    <div class="drawer-nav-grid">
        <a href="<?= htmlspecialchars($homeUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'dashboard') ? 'active' : '' ?>">
            <div class="grid-icon">🏠</div>
            <span class="grid-label" data-i18n="dashboard"><?= $isArabic ? 'لوحة التحكم' : 'Dashboard' ?></span>
        </a>
        <a href="<?= htmlspecialchars($attendanceUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'attendance') ? 'active' : '' ?>">
            <div class="grid-icon">📋</div>
            <span class="grid-label" data-i18n="attendance"><?= $isArabic ? 'الحضور والغياب' : 'Attendance' ?></span>
        </a>
        <a href="<?= htmlspecialchars($admissionsUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'admissions' || $activeNav === 'students') ? 'active' : '' ?>">
            <div class="grid-icon">🎓</div>
            <span class="grid-label" data-i18n="students"><?= $isArabic ? 'الطلاب' : 'Students' ?></span>
        </a>
        <a href="<?= htmlspecialchars($financeUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'finance' || $activeNav === 'tuition') ? 'active' : '' ?>">
            <div class="grid-icon">💳</div>
            <span class="grid-label" data-i18n="tuition"><?= $isArabic ? 'الرسوم المالية' : 'Tuition' ?></span>
        </a>
        <a href="<?= htmlspecialchars($academicsUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'academics') ? 'active' : '' ?>">
            <div class="grid-icon">📚</div>
            <span class="grid-label" data-i18n="academics"><?= $isArabic ? 'الأكاديميات' : 'Academics' ?></span>
        </a>
        <a href="<?= htmlspecialchars($reportsUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'reports') ? 'active' : '' ?>">
            <div class="grid-icon">📊</div>
            <span class="grid-label" data-i18n="reports"><?= $isArabic ? 'التقارير' : 'Reports' ?></span>
        </a>
        <a href="<?= htmlspecialchars($profileUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'profile') ? 'active' : '' ?>">
            <div class="grid-icon">👤</div>
            <span class="grid-label" data-i18n="profile"><?= $isArabic ? 'الملف الشخصي' : 'Profile' ?></span>
        </a>
        <a href="<?= htmlspecialchars($settingsUrl) ?>" class="drawer-grid-item <?= ($activeNav === 'settings') ? 'active' : '' ?>">
            <div class="grid-icon">⚙️</div>
            <span class="grid-label" data-i18n="settings"><?= $isArabic ? 'الإعدادات' : 'Settings' ?></span>
        </a>
    </div>

    <!-- Bottom Drawer Controls: Language Switcher & Logout -->
    <div class="drawer-footer-cluster">
        <div class="drawer-lang-toggle">
            <span class="lang-label" data-i18n="language"><?= $isArabic ? 'اللغة' : 'Language' ?></span>
            <div class="lang-pill-selector">
                <button class="lang-btn <?= !$isArabic ? 'active' : '' ?>" id="langEnBtn" data-lang="en">English</button>
                <button class="lang-btn <?= $isArabic ? 'active' : '' ?>" id="langArBtn" data-lang="ar">العربية</button>
            </div>
        </div>

        <a href="<?= htmlspecialchars($logoutUrl) ?>" class="btn-drawer-logout">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span data-i18n="signOut"><?= $isArabic ? 'تسجيل الخروج' : 'Sign Out' ?></span>
        </a>
    </div>
</aside>
