<?php
/**
 * Shared Global Header & Responsive Head Template
 * Part of Hybrid WebView & Mobile Adaptation (Phase 1)
 */
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/init.php';
}

$baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
$pageTitle = $pageTitle ?? ($global_branding['school_name'] ?? 'EduMaster School System');
$isRtl = function_exists('is_rtl') ? is_rtl() : false;
$activeTheme = $_SESSION['theme_preference'] ?? ($_COOKIE['theme_mode'] ?? 'light');
?>
<!-- Responsive Viewport Settings -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0f172a">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">

<style>
    /* Prevent horizontal page blowouts on small screens */
    html, body {
        max-width: 100%;
        overflow-x: hidden;
    }

    /* Make all tabular modules scroll smoothly on mobile */
    .table-responsive-wrapper {
        width: 100%;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        margin-bottom: 1rem;
    }

    /* Touch-friendly buttons and inputs */
    @media (max-width: 768px) {
        .btn, .form-control, .form-select {
            min-height: 44px;
            font-size: 1rem;
        }
        .card {
            margin-bottom: 1rem;
            border-radius: 12px;
        }
        .timetable-cell {
            min-width: 140px; /* Ensures readable columns when scrolling horizontally */
        }
    }
</style>
