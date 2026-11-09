<?php
/**
 * Compact Android Navigation Bar Component
 * Rendered specifically for mobile app WebViews and mobile client viewports.
 */

// Detect custom Android WebView header or query flag
if (!isset($isAndroidApp)) {
    $isAndroidApp = function_exists('is_android_app') ? is_android_app() : (
        isset($_SERVER['HTTP_X_REQUESTED_WITH']) 
        || str_contains($_SERVER['HTTP_USER_AGENT'] ?? '', 'PrismApp')
        || (isset($_GET['view']) && $_GET['view'] === 'app')
    );
}

$baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
$currentModule = $currentModule ?? ($active_title ?? 'Dashboard');
$userName = class_exists('Auth') && Auth::check() ? Auth::fullName() : ($userName ?? ($_SESSION['full_name'] ?? 'Administrator'));
$userRole = class_exists('Auth') && Auth::check() ? ucfirst(Auth::role()) : ($userRole ?? ucfirst($_SESSION['role'] ?? 'General Manager'));
$userAvatar = class_exists('Auth') && Auth::check() && !empty($_SESSION['avatar']) 
    ? $_SESSION['avatar'] 
    : ($userAvatar ?? ($baseUrl . '/assets/img/default-avatar.png'));

$profileUrl = $baseUrl . '/modules/Administration/student_profile.php';
$rbacUrl = $baseUrl . '/modules/Administration/manage_permissions.php';
$notifUrl = $baseUrl . '/modules/LMS/announcements.php';
$settingsUrl = $baseUrl . '/modules/Administration/institution_settings.php';
$logoutUrl = $baseUrl . '/logout.php';
$isRtl = (function_exists('is_rtl') && is_rtl()) || (isset($_SESSION['lang']) && $_SESSION['lang'] === 'ar');
?>
<header class="prism-app-topbar <?= $isAndroidApp ? 'is-native-app' : '' ?>" id="prismAppTopbar">
    <div class="topbar-left">
        <!-- Compact Brand Glyphs -->
        <a href="<?= htmlspecialchars($baseUrl) ?>/dashboard.php" class="app-brand-compact" style="text-decoration: none;">
            <svg viewBox="0 0 70 70" width="28" height="28" class="app-brand-svg">
                <polygon points="10,54 50,54 44,45 19,45" fill="#36527b" />
                <polygon points="50,54 30,14 24,24 40,47" fill="#0dc0e7" />
                <polygon points="30,14 10,54 20,45 30,26" fill="#2c88c1" />
                <polygon points="30,29 23,43 37,43" fill="#bef6fd" opacity="0.9" />
                <circle cx="30" cy="14" r="2" fill="#eefdff" />
            </svg>
            <span class="compact-title">PRISM <span class="badge-dot"></span></span>
        </a>
    </div>

    <div class="topbar-center">
        <!-- Active Section Indicator -->
        <span class="active-module-pill" title="<?= htmlspecialchars($currentModule ?? 'Dashboard') ?>"><?= htmlspecialchars($currentModule ?? 'Dashboard') ?></span>
    </div>

    <div class="topbar-right">
        <!-- Quick Action: Search -->
        <button class="icon-btn" id="searchQuickBtn" aria-label="Search" title="Search">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </button>

        <!-- Quick Action: Notifications -->
        <a href="<?= htmlspecialchars($notifUrl) ?>" class="icon-btn notif-btn" id="notifQuickBtn" aria-label="Notifications" title="Notifications">
            <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <span class="badge-count">3</span>
        </a>

        <!-- Compact User Trigger (Sheet Toggle) -->
        <button class="user-avatar-btn" id="appUserSheetBtn" aria-label="Account Options" title="Account Options">
            <img src="<?= htmlspecialchars($userAvatar ?? $baseUrl . '/assets/img/default-avatar.png') ?>" alt="User" class="avatar-img">
        </button>
    </div>
</header>

<!-- Compact Expandable Search Overlay for Mobile App -->
<div class="app-compact-search-box" id="appCompactSearchBox" style="display: none;">
    <form action="<?= htmlspecialchars($baseUrl) ?>/dashboard.php" method="GET" class="app-search-inner-form">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" class="search-box-icon">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
        </svg>
        <input type="search" name="q" id="compactSearchInput" placeholder="<?= $isRtl ? 'بحث في النظام...' : 'Search portal...' ?>" autocomplete="off">
        <button type="button" id="compactSearchCloseBtn" class="search-box-close" aria-label="Close search">&times;</button>
    </form>
</div>

<!-- Bottom Sheet for User Credentials, Roles & Session Actions -->
<div class="app-bottom-sheet-overlay" id="userSheetOverlay"></div>
<div class="app-bottom-sheet" id="userSheet" role="dialog" aria-modal="true" aria-labelledby="sheetUserName">
    <div class="sheet-drag-handle"></div>
    
    <div class="sheet-profile-header">
        <img src="<?= htmlspecialchars($userAvatar ?? $baseUrl . '/assets/img/default-avatar.png') ?>" alt="User" class="sheet-avatar-img">
        <div class="profile-info">
            <h4 class="profile-name" id="sheetUserName"><?= htmlspecialchars($userName ?? 'Administrator') ?></h4>
            <span class="profile-role"><?= htmlspecialchars($userRole ?? 'General Manager') ?></span>
        </div>
        <span class="session-status-badge active">Online</span>
    </div>

    <div class="sheet-menu-grid">
        <a href="<?= htmlspecialchars($baseUrl) ?>/dashboard.php" class="sheet-item" data-portal-route="/dashboard.php">
            <span class="item-icon">🏠</span>
            <span class="item-label">Home</span>
        </a>
        <a href="<?= htmlspecialchars($baseUrl) ?>/modules/LMS/index.php" class="sheet-item" data-portal-route="/modules/LMS/index.php">
            <span class="item-icon">📚</span>
            <span class="item-label">Academics</span>
        </a>
        <a href="<?= htmlspecialchars($baseUrl) ?>/modules/Administration/attendance_desk.php" class="sheet-item" data-portal-route="/modules/Administration/attendance_desk.php">
            <span class="item-icon">📋</span>
            <span class="item-label">Attendance</span>
        </a>
        <a href="<?= htmlspecialchars($baseUrl) ?>/modules/Finance/fees.php" class="sheet-item" data-portal-route="/modules/Finance/fees.php">
            <span class="item-icon">💳</span>
            <span class="item-label">Tuition</span>
        </a>
        <a href="<?= htmlspecialchars($profileUrl) ?>" class="sheet-item" data-portal-route="/portal/profile">
            <span class="item-icon">👤</span>
            <span class="item-label">Profile</span>
        </a>
        <a href="<?= htmlspecialchars($rbacUrl) ?>" class="sheet-item" data-portal-route="/portal/rbac">
            <span class="item-icon">🛡️</span>
            <span class="item-label">RBAC</span>
        </a>
        <a href="<?= htmlspecialchars($notifUrl) ?>" class="sheet-item" data-portal-route="/portal/notifications">
            <span class="item-icon">🔔</span>
            <span class="item-label">Alerts</span>
        </a>
        <a href="<?= htmlspecialchars($settingsUrl) ?>" class="sheet-item" data-portal-route="/portal/settings">
            <span class="item-icon">⚙️</span>
            <span class="item-label">Settings</span>
        </a>
    </div>

    <div class="sheet-footer-actions">
        <a href="<?= htmlspecialchars($logoutUrl) ?>" class="btn-app-logout" id="appLogoutBtn" data-portal-route="/auth/logout.php">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span>Sign Out</span>
        </a>
    </div>
</div>
