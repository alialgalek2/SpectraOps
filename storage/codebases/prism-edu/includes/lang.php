<?php
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    @session_start();
}

// Handle Language Switch via URL parameter ?lang=ar or ?lang=en
if (isset($_GET['lang'])) {
    $requested_lang = strtolower(trim($_GET['lang']));
    if (in_array($requested_lang, ['en', 'ar'])) {
        $_SESSION['lang'] = $requested_lang;
    }
}

// Set default language (English) if not already set
if (!isset($_SESSION['lang'])) {
    $_SESSION['lang'] = 'en';
}

if (!function_exists('current_lang')) {
    function current_lang(): string {
        return $_SESSION['lang'] ?? 'en';
    }
}

if (!function_exists('is_rtl')) {
    function is_rtl(): bool {
        return current_lang() === 'ar';
    }
}

if (!function_exists('current_theme_mode')) {
    function current_theme_mode(): string {
        if (!empty($_SESSION['theme_preference']) && in_array($_SESSION['theme_preference'], ['light', 'dark'])) {
            return $_SESSION['theme_preference'];
        }
        if (!empty($_COOKIE['theme_mode']) && in_array($_COOKIE['theme_mode'], ['light', 'dark'])) {
            return $_COOKIE['theme_mode'];
        }
        return 'light';
    }
}

if (!function_exists('get_html_dir')) {
    function get_html_dir(): string {
        $theme = current_theme_mode();
        $dirLang = is_rtl() ? 'dir="rtl" lang="ar"' : 'dir="ltr" lang="en"';
        return $dirLang . ' data-theme="' . htmlspecialchars($theme) . '" data-bs-theme="' . htmlspecialchars($theme) . '"';
    }
}

if (!function_exists('get_bootstrap_css')) {
    function get_bootstrap_css(): string {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';

        // Emit CSRF meta tag so static JS files can read the token via
        // document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        $csrfMeta = '<meta name="csrf-token" content="' . htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">' . "\n";
        
        // Zero-flicker pre-render theme script
        $preScript = '<script>
(function() {
    var stored = localStorage.getItem("theme_preference") || (document.cookie.match(/(?:^|;\s*)theme_mode=([^;]+)/) || [])[1] || "' . current_theme_mode() . '";
    if (stored === "dark") {
        document.documentElement.setAttribute("data-theme", "dark");
        document.documentElement.setAttribute("data-bs-theme", "dark");
        document.documentElement.classList.add("dark");
    } else {
        document.documentElement.setAttribute("data-theme", "light");
        document.documentElement.setAttribute("data-bs-theme", "light");
        document.documentElement.classList.remove("dark");
    }
})();
</script>' . "\n";

        $fonts = '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n" .
                 '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n" .
                 '<link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;500;600;700;800&family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">' . "\n";
        
        $faLocal = dirname(__DIR__) . '/assets/vendor/fontawesome/css/all.min.css';
        $faV = file_exists($faLocal) ? filemtime($faLocal) : '6.4.0';
        $faCss = '<link href="' . htmlspecialchars($baseUrl) . '/assets/vendor/fontawesome/css/all.min.css?v=' . $faV . '" rel="stylesheet">' . "\n" .
                 '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">' . "\n";

        $cssFile = dirname(__DIR__) . '/assets/css/theme.css';
        $v = file_exists($cssFile) ? filemtime($cssFile) : '1.0';
        $theme = '<link href="' . htmlspecialchars($baseUrl) . '/assets/css/theme.css?v=' . $v . '" rel="stylesheet">' . "\n";

        $faJsLocal = dirname(__DIR__) . '/assets/vendor/fontawesome/js/all.min.js';
        $faJsV = file_exists($faJsLocal) ? filemtime($faJsLocal) : '6.4.0';
        $faJs = '<script src="' . htmlspecialchars($baseUrl) . '/assets/vendor/fontawesome/js/all.min.js?v=' . $faJsV . '" defer></script>' . "\n";

        $bootstrapCss = is_rtl() 
            ? '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">' . "\n"
            : '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">' . "\n";

        $jsFile = dirname(__DIR__) . '/assets/js/theme_toggle.js';
        $jv = file_exists($jsFile) ? filemtime($jsFile) : '1.0';
        $themeJs = '<script src="' . htmlspecialchars($baseUrl) . '/assets/js/theme_toggle.js?v=' . $jv . '" defer></script>' . "\n";

        $appNavAssets = '';
        if (function_exists('is_android_app') && is_android_app()) {
            $appNavCssFile = dirname(__DIR__) . '/assets/css/app-navbar.css';
            $anv = file_exists($appNavCssFile) ? filemtime($appNavCssFile) : '1.0';
            $appNavJsFile = dirname(__DIR__) . '/assets/js/app-navbar.js';
            $anjv = file_exists($appNavJsFile) ? filemtime($appNavJsFile) : '1.0';
            $appNavAssets = '<link href="' . htmlspecialchars($baseUrl) . '/assets/css/app-navbar.css?v=' . $anv . '" rel="stylesheet">' . "\n" .
                            '<script src="' . htmlspecialchars($baseUrl) . '/assets/js/app-navbar.js?v=' . $anjv . '" defer></script>' . "\n";
        }

        return $csrfMeta . $preScript . $fonts . $faCss . $bootstrapCss . $theme . $faJs . $themeJs . $appNavAssets;
    }
}

// Helper to generate language toggle URL preserving other query parameters
if (!function_exists('get_lang_switch_url')) {
    /**
     * @param string $target_lang
     * @return string
     */
    function get_lang_switch_url(string $target_lang): string {
        $params = $_GET;
        $params['lang'] = $target_lang;
        return '?' . http_build_query($params);
    }
}

// Render modern Language Switcher button
if (!function_exists('render_lang_switcher')) {
    /**
     * @param string $btn_class
     * @return string
     */
    function render_lang_switcher(string $btn_class = 'btn-outline-border'): string {
        $cur = current_lang();
        $is_ar = ($cur === 'ar');
        $toggle_lang = $is_ar ? 'en' : 'ar';
        $toggle_label = $is_ar ? 'English 🇬🇧' : 'العربية 🇸🇦';
        $toggle_url = get_lang_switch_url($toggle_lang);

        return '
        <div class="dropdown d-inline-block">
            <a href="' . htmlspecialchars($toggle_url) . '" class="btn btn-sm ' . htmlspecialchars($btn_class) . ' d-flex align-items-center gap-1 shadow-xs px-3 rounded-lg" title="' . ($is_ar ? 'Switch to English' : 'التحويل إلى العربية') . '">
                <i class="fa-solid fa-globe text-primary"></i>
                <span class="fw-bold">' . $toggle_label . '</span>
            </a>
        </div>';
    }
}

// Render modern Theme Switcher button (Light / Dark Mode)
if (!function_exists('render_theme_switcher')) {
    /**
     * @param string $btn_class
     * @return string
     */
    function render_theme_switcher(string $btn_class = 'btn-outline-border'): string {
        $cur = current_theme_mode();
        $is_dark = ($cur === 'dark');
        $title = is_rtl() ? 'تبديل المظهر (فاتح / داكن)' : 'Toggle Light / Dark Mode';
        
        return '
        <button type="button" class="btn btn-sm ' . htmlspecialchars($btn_class) . ' theme-toggle-btn d-inline-flex align-items-center justify-content-center shadow-xs rounded-lg" id="themeToggleBtn" title="' . htmlspecialchars($title) . '" aria-label="' . htmlspecialchars($title) . '" style="width: 2.25rem; height: 2.25rem;">
            <i class="fa-solid ' . ($is_dark ? 'fa-sun text-warning' : 'fa-moon text-primary') . '" id="themeToggleIcon"></i>
        </button>';
    }
}

// Extract user initials
if (!function_exists('extract_user_initials')) {
    function extract_user_initials(?string $name): string {
        $parts = explode(' ', trim((string)$name));
        $in = '';
        foreach ($parts as $p) {
            if (!empty($p)) {
                $in .= mb_substr($p, 0, 1);
                if (mb_strlen($in) >= 2) break;
            }
        }
        return strtoupper($in) ?: 'AD';
    }
}

// Helper to retrieve correct absolute web URL for the institution logo
if (!function_exists('get_school_logo_url')) {
    /**
     * @param string|null $logo_path
     * @param string|null $theme_mode 'light' | 'dark' | null (auto-detects)
     * @return string
     */
    function get_school_logo_url(?string $logo_path = null, ?string $theme_mode = null): string {
        global $global_branding, $pdo;
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';

        $currentTheme = $theme_mode ?: (function_exists('current_theme_mode') ? current_theme_mode() : 'light');

        // New Official PRISM EDU Branding Variants:
        // - Dark Version (Navy Canvas): for Light View
        // - Light Version (Transparent/White): for Dark View
        $darkCanvasLogo = 'uploads/branding/prism_logo_dark_canvas.png';
        $lightTransLogo = 'uploads/branding/prism_logo_light_transparent.png';

        if (empty($logo_path) || in_array($logo_path, ['auto', 'default', 'light', 'dark', 'light_view', 'dark_view', 'uploads/branding/prism_edu_logo.svg'])) {
            if ($logo_path === 'light' || $logo_path === 'light_view') {
                $path = $darkCanvasLogo;
            } elseif ($logo_path === 'dark' || $logo_path === 'dark_view') {
                $path = $lightTransLogo;
            } else {
                $path = ($currentTheme === 'dark') ? $lightTransLogo : $darkCanvasLogo;
            }
        } else {
            $path = $logo_path;
        }

        // External URL
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $clean = ltrim(str_replace('\\', '/', $path), '/');

        // Remove duplicate base url if already in string
        if (str_starts_with($clean, 'school_system/')) {
            return '/' . $clean;
        }

        return $baseUrl . '/' . $clean;
    }
}

// Render Dynamic Responsive Theme Logo Component (Dark Version for light view, Light Version for dark view)
if (!function_exists('render_theme_logo_html')) {
    /**
     * @param string $style
     * @param string $class
     * @return string
     */
    function render_theme_logo_html(string $style = 'max-height: 38px; width: auto; max-width: 120px; object-fit: contain;', string $class = 'rounded shadow-xs'): string {
        $logoDarkCanvas = get_school_logo_url(null, 'light'); // Dark version (Navy Canvas) for Light View
        $logoLightTrans = get_school_logo_url(null, 'dark');  // Light version (Transparent/White) for Dark View

        return '<div class="brand-logo-wrapper d-inline-flex align-items-center">' .
            '<img src="' . htmlspecialchars($logoDarkCanvas) . '" alt="PRISM Logo (Light View)" class="brand-logo-light-view ' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '">' .
            '<img src="' . htmlspecialchars($logoLightTrans) . '" alt="PRISM Logo (Dark View)" class="brand-logo-dark-view ' . htmlspecialchars($class) . '" style="' . htmlspecialchars($style) . '">' .
            '</div>';
    }
}

// Render Standard Navbar Brand (Logo + Title)
if (!function_exists('render_navbar_brand')) {
    /**
     * @param string $title
     * @param string $url
     * @param string $iconClass
     * @return string
     */
    function render_navbar_brand(string $title = '', string $url = '', string $iconClass = ''): string {
        global $global_branding;
        $school_name = $global_branding['school_name'] ?? 'School System';
        $target_url = !empty($url) ? $url : (defined('BASE_URL') ? BASE_URL . '/dashboard.php' : '/school_system/dashboard.php');
        $display_title = !empty($title) ? $title : $school_name;

        $logo_html = render_theme_logo_html('max-height: 38px; width: auto; max-width: 120px; object-fit: contain;', 'rounded-md shadow-xs me-2');

        return '<a class="navbar-brand fw-bold d-flex align-items-center" href="' . htmlspecialchars($target_url) . '">'
            . $logo_html
            . '<span class="font-heading">' . htmlspecialchars($display_title) . '</span>'
            . '</a>';
    }
}

// Render Universal Topbar & Header Navigation (Identical to Dashboard Design)
if (!function_exists('render_universal_navbar')) {
    /**
     * @param array|mixed $options
     * @param string|null $school_name
     * @param string|null $school_logo
     * @param string|null $active_year
     * @return string
     */
    function render_universal_navbar($options = [], ?string $school_name = null, ?string $school_logo = null, ?string $active_year = null): string {
        global $global_branding;
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
        
        if (!is_array($options)) {
            $options = [
                'user' => $options,
                'school_name' => $school_name,
                'school_logo' => $school_logo,
                'active_year' => $active_year
            ];
        }

        $school_name = $options['school_name'] ?? $global_branding['school_name'] ?? 'EduMaster Core';
        $school_logo = $options['school_logo'] ?? $global_branding['logo_path'] ?? '';
        $active_year = $options['active_year'] ?? $global_branding['academic_year'] ?? '2025/2026';
        $active_title = $options['active_title'] ?? null;
        $show_search = $options['show_search'] ?? true;
        $extra_nav_items = $options['extra_nav_items'] ?? [];
        $back_url = $options['back_url'] ?? null;
        
        $user_name = $_SESSION['full_name'] ?? 'Administrator';
        $user_role = ucfirst($_SESSION['role'] ?? 'Admin');
        $user_initials = extract_user_initials($user_name);

        // Unified Top Navigation & Slide-out Drawer
        if (!empty($options['unified']) || (isset($_GET['nav']) && $_GET['nav'] === 'unified')) {
            return render_unified_navbar($options['active_nav'] ?? 'dashboard');
        }

        // Specialized Compact Android WebView Navigation Bar
        if (function_exists('is_android_app') && is_android_app()) {
            $currentModule = $active_title ?? 'Dashboard';
            $userName = class_exists('Auth') && Auth::check() ? Auth::fullName() : $user_name;
            $userRole = class_exists('Auth') && Auth::check() ? ucfirst(Auth::role()) : $user_role;
            $userAvatar = !empty($_SESSION['avatar']) ? $_SESSION['avatar'] : ($baseUrl . '/assets/img/default-avatar.png');
            $isAndroidApp = true;

            $appNavbarFile = dirname(__DIR__) . '/components/app_navbar.php';
            if (file_exists($appNavbarFile)) {
                ob_start();
                echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl) . '/assets/css/app-navbar.css">' . "\n";
                $isDashboardScript = (!empty($options['hide_sidebar'])) || 
                                     (stripos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), 'dashboard.php') !== false) ||
                                     (!empty($active_title) && in_array(strtolower($active_title), ['dashboard', 'لوحة التحكم', 'system_dashboard']));
                if (empty($GLOBALS['app_sidebar_rendered']) && !$isDashboardScript) {
                    require_once dirname(__DIR__) . '/core/Layout.php';
                    echo Layout::renderSidebar(['active_module' => $currentModule]);
                }
                include $appNavbarFile;
                echo '<script src="' . htmlspecialchars($baseUrl) . '/assets/js/app-navbar.js" defer></script>' . "\n";
                return ob_get_clean();
            }
        }
        
        $dash_url = $baseUrl . '/dashboard.php';
        $logout_url = $baseUrl . '/logout.php';
        $pw_url = $baseUrl . '/modules/Administration/change_password.php';
        
        $is_rtl = is_rtl();
        $lang_btn = render_lang_switcher();
        $theme_btn = render_theme_switcher();
        
        $logo_url = get_school_logo_url($school_logo);
        $brand_icon_html = render_theme_logo_html('max-height: 38px; width: auto; max-width: 120px; object-fit: contain;', 'rounded-md shadow-xs me-2');

        $subtitle = $active_title ? htmlspecialchars($active_title) : __('system_subtitle');

        $back_btn_html = '';
        if ($back_url) {
            $back_btn_html = '
            <a href="' . htmlspecialchars($back_url) . '" class="btn btn-sm btn-outline-border rounded-lg p-2 me-1 btn-smart-back" data-action="smart-back" data-fallback-url="' . htmlspecialchars($back_url) . '" title="' . ($is_rtl ? 'رجوع' : 'Back') . '" aria-label="' . ($is_rtl ? 'رجوع' : 'Back') . '">
                <i class="fa-solid fa-arrow-' . ($is_rtl ? 'right' : 'left') . ' text-primary"></i>
            </a>';
        }

        $search_html = '';
        if ($show_search) {
            $search_html = '
            <div class="navbar-search-wrapper" id="navbarSearchWrapper">
                <!-- Collapsed Search Icon Trigger -->
                <button type="button" class="btn-search-trigger" id="navbarSearchTrigger" title="' . ($is_rtl ? 'بحث (Ctrl+K)' : 'Search (Ctrl+K)') . '" aria-label="Search">
                    <i class="fa-solid fa-magnifying-glass search-trigger-icon"></i>
                    <span class="d-none d-lg-inline small text-muted-foreground">' . ($is_rtl ? 'بحث...' : 'Search...') . '</span>
                    <kbd class="kbd-badge ms-1 d-none d-xl-inline-flex" id="searchKbdTriggerBadge" data-shortcut="search">Ctrl K</kbd>
                </button>

                <!-- Expanded Full Searchbar -->
                <div class="header-search-bar navbar-search-expanded" id="headerSearchContainer">
                    <i class="fa-solid fa-magnifying-glass text-primary"></i>
                    <input type="text" id="globalSearchInput" placeholder="' . ($is_rtl ? 'بحث عن طلاب، موظفين، مواد...' : 'Search students, staff, courses…') . '" autocomplete="off" aria-label="Search">
                    <kbd class="kbd-badge" id="searchKbdBadge" title="' . ($is_rtl ? 'اضغط Esc للإغلاق' : 'Press Esc to close') . '">Esc</kbd>
                    <button type="button" class="btn-search-close" id="navbarSearchClose" title="' . ($is_rtl ? 'إغلاق' : 'Close') . '" aria-label="Close search">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>';
        }

        // Detect active module & script from path
        $scriptPath = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        preg_match('#/modules/([^/]+)/([^/?]+)#i', $scriptPath, $pathMatches);
        $activeModule = !empty($pathMatches[1]) ? $pathMatches[1] : '';
        $activeScript = !empty($pathMatches[2]) ? $pathMatches[2] : basename($scriptPath);

        // Smart In-Module Action Filtering
        // Filters out cross-module links, duplicate back/hub buttons, and guarantees a strict single-row fit.
        $filtered_items = [];
        if (!empty($extra_nav_items) && is_array($extra_nav_items)) {
            foreach ($extra_nav_items as $item) {
                if (!is_string($item) || empty(trim($item))) continue;

                // Inspect href if item is a link or contains one
                if (preg_match('/href=[\'"]([^\'"]+)[\'"]/i', $item, $hrefMatch)) {
                    $href = trim($hrefMatch[1]);
                    $cleanHref = strtok($href, '?#');

                    // 1. Filter out duplicate back / hub buttons if back_url is already present
                    if ($back_url) {
                        if ($cleanHref === 'index.php' || $cleanHref === './index.php' || $href === $back_url || str_ends_with($cleanHref, '/dashboard.php') || $cleanHref === '../../dashboard.php') {
                            continue; // Drop redundant back button
                        }
                    }

                    // 2. Filter out cross-module links pointing to another module directory
                    if (preg_match('#\.\./([A-Za-z0-9_-]+)/#', $cleanHref, $targetModMatch)) {
                        $targetModule = $targetModMatch[1];
                        if (!empty($activeModule) && strcasecmp($targetModule, $activeModule) !== 0) {
                            continue; // Drop unrelated cross-module button
                        }
                    }

                    // 3. Filter out links pointing to the current script itself
                    if ($cleanHref === $activeScript || $cleanHref === basename($scriptPath)) {
                        continue; // Drop self link
                    }
                }

                // Passed all filters - genuine in-module action
                $filtered_items[] = $item;
            }
        }

        // Limit extra in-module buttons to at most 2 to preserve strict single-row layout
        $filtered_items = array_slice($filtered_items, 0, 2);

        $extra_items_html = '';
        foreach ($filtered_items as $item) {
            $extra_items_html .= '<div class="d-none d-md-inline-flex flex-shrink-0">' . $item . '</div>';
        }

        $header_classes = 'navbar-custom sticky-top mb-4';
        if (!empty($options['header_class'])) {
            $header_classes .= ' ' . htmlspecialchars($options['header_class']);
        }

        $sidebarHtml = '';
        $isDashboard = (!empty($options['hide_sidebar'])) || 
                       (stripos(str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? ''), 'dashboard.php') !== false);

        if (empty($GLOBALS['app_sidebar_rendered']) && !$isDashboard) {
            require_once dirname(__DIR__) . '/core/Layout.php';
            $sidebarHtml = Layout::renderSidebar(['active_module' => $activeModule]);
        }

        // Left brand / title section: Streamlined when sidebar is present to eliminate duplicate logo and unclutter actions
        $hasSidebar = !empty($sidebarHtml) || (!empty($GLOBALS['app_sidebar_rendered']) && !$isDashboard);
        if ($hasSidebar) {
            $leftBrandHtml = '
                <div class="d-flex align-items-center flex-nowrap gap-2 gap-md-2.5 flex-shrink-0" style="min-width: 0;">
                    ' . $back_btn_html . '
                    <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 280px;">
                        <span class="badge bg-secondary text-foreground border border-border rounded-lg px-2.5 py-1.5 fw-bold font-heading d-inline-flex align-items-center gap-2 text-truncate" style="font-size: 0.88rem;">
                            <i class="fa-solid fa-shapes text-primary"></i>
                            <span class="text-truncate">' . $subtitle . '</span>
                        </span>
                    </div>
                </div>';
        } else {
            $leftBrandHtml = '
                <div class="d-flex align-items-center flex-nowrap gap-2 gap-md-3 flex-shrink-0" style="min-width: 0;">
                    ' . $back_btn_html . '
                    <a href="' . htmlspecialchars($dash_url) . '" class="d-flex align-items-center gap-2 gap-md-3 text-decoration-none text-foreground flex-shrink-0" style="min-width: 0;">
                        ' . $brand_icon_html . '
                        <div class="d-none d-sm-block text-truncate" style="max-width: 260px;">
                            <div class="font-heading fw-bold text-foreground line-height-sm text-truncate" style="font-size: 1.02rem;">' . htmlspecialchars($school_name) . '</div>
                            <div class="text-muted-foreground small text-truncate" style="font-size: 0.74rem;">' . $subtitle . '</div>
                        </div>
                    </a>
                </div>';
        }

        return $sidebarHtml . '
        <header class="' . $header_classes . '">
            <div class="container-fluid px-3 px-lg-4 d-flex align-items-center justify-content-between flex-nowrap gap-2 gap-lg-3" style="min-width: 0;">
                
                <!-- Left: Brand & Title -->
                ' . $leftBrandHtml . '

                <!-- Center: Global Expandable Search Bar -->
                <div class="d-flex align-items-center justify-content-center flex-shrink-1 min-w-0 mx-1 mx-sm-2" style="overflow: visible;">
                    ' . $search_html . '
                </div>

                <!-- Right: In-Module Actions, Theme, Language, Profile (Strict Single Row) -->
                <div class="d-flex align-items-center flex-nowrap gap-1 gap-sm-2 flex-shrink-0">
                    ' . $extra_items_html . '
                    
                    <div class="d-none d-xxl-flex align-items-center gap-2 bg-secondary px-2.5 py-1.5 rounded-lg text-muted-foreground small font-medium flex-shrink-0">
                        <i class="fa-solid fa-calendar text-primary"></i>
                        <span>' . htmlspecialchars($active_year) . '</span>
                    </div>

                    <div class="flex-shrink-0">
                        ' . $theme_btn . '
                    </div>

                    <div class="flex-shrink-0">
                        ' . $lang_btn . '
                    </div>

                    <div class="d-flex align-items-center gap-2 bg-secondary py-1 px-2 rounded-lg flex-shrink-0">
                        <div class="avatar-badge" style="width: 2rem; height: 2rem; font-size: 0.8rem;">
                            ' . htmlspecialchars($user_initials) . '
                        </div>
                        <div class="d-none d-lg-block text-start me-1">
                            <div class="fw-semibold text-foreground small text-truncate" style="font-size: 0.8125rem; line-height: 1.2; max-width: 110px;">' . htmlspecialchars($user_name) . '</div>
                            <div class="text-muted-foreground text-truncate" style="font-size: 0.7rem; text-transform: capitalize; max-width: 110px;">' . htmlspecialchars($user_role) . '</div>
                        </div>
                    </div>

                    <a href="' . htmlspecialchars($pw_url) . '" class="btn btn-sm btn-outline-border rounded-lg p-2 flex-shrink-0" title="' . __('change_password') . '">
                        <i class="fa-solid fa-key text-muted-foreground"></i>
                    </a>

                    <a href="' . htmlspecialchars($logout_url) . '" class="btn btn-sm btn-outline-border text-destructive rounded-lg px-2.5 py-1.5 fw-semibold flex-shrink-0 d-none d-sm-inline-flex" title="' . __('logout') . '">
                        <i class="fa-solid fa-right-from-bracket"></i>
                        <span class="ms-1">' . __('logout') . '</span>
                    </a>

                    <!-- Mobile Offcanvas Drawer Trigger (Phase 1 Mobile Adaptation) -->
                    <button class="btn btn-sm btn-outline-border rounded-lg p-2 d-inline-flex d-md-none flex-shrink-0" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileAppDrawer" aria-controls="mobileAppDrawer" title="' . ($is_rtl ? 'القائمة الرئيسية' : 'Main Menu') . '">
                        <i class="fa-solid fa-bars text-primary"></i>
                    </button>
                </div>
            </div>
        </header>

        <!-- Bootstrap Offcanvas Mobile Drawer Menu (< 768px) -->
        <div class="offcanvas offcanvas-start offcanvas-mobile-nav" tabindex="-1" id="mobileAppDrawer" aria-labelledby="mobileAppDrawerLabel">
            <div class="offcanvas-header border-bottom border-border p-3">
                <div class="d-flex align-items-center gap-2" style="min-width: 0;">
                    ' . $brand_icon_html . '
                    <div class="text-truncate">
                        <h6 class="offcanvas-title fw-bold text-foreground text-truncate mb-0" id="mobileAppDrawerLabel">' . htmlspecialchars($school_name) . '</h6>
                        <span class="badge bg-secondary text-primary font-monospace" style="font-size: 0.72rem;">' . htmlspecialchars($active_year) . '</span>
                    </div>
                </div>
                <button type="button" class="btn-close text-reset" data-bs-dismiss="offcanvas" aria-label="Close"></button>
            </div>
            <div class="offcanvas-body d-flex flex-column justify-content-between p-3">
                <div>
                    <!-- User Profile Card -->
                    <div class="d-flex align-items-center gap-3 p-2.5 mb-3 rounded-xl bg-secondary border border-border">
                        <div class="avatar-badge" style="width: 2.4rem; height: 2.4rem; font-size: 0.95rem;">
                            ' . htmlspecialchars($user_initials) . '
                        </div>
                        <div class="text-truncate">
                            <div class="fw-bold text-foreground text-truncate" style="font-size: 0.88rem;">' . htmlspecialchars($user_name) . '</div>
                            <span class="badge bg-primary text-white" style="font-size: 0.68rem; text-transform: uppercase;">' . htmlspecialchars($user_role) . '</span>
                        </div>
                    </div>

                    <!-- Navigation Modules List -->
                    <div class="nav flex-column gap-1 mb-3">
                        <a href="' . htmlspecialchars($dash_url) . '" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-gauge text-primary" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'لوحة المؤشرات المركزية' : 'Main Dashboard') . '</span>
                        </a>
                        <a href="' . $baseUrl . '/modules/Administration/index.php" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-shield-halved text-info" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'الإدارة والتحكم المؤسسي' : 'Institutional Admin') . '</span>
                        </a>
                        <a href="' . $baseUrl . '/modules/LMS/index.php" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-graduation-cap text-primary" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'إدارة التعلم والفصول (LMS)' : 'LMS & Class Hub') . '</span>
                        </a>
                        <a href="' . $baseUrl . '/modules/Grading/index.php" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-file-pen text-warning" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'الامتحانات والتقييم الأكاديمي' : 'Grading & Exams') . '</span>
                        </a>
                        <a href="' . $baseUrl . '/modules/Finance/index.php" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-vault text-success" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'الخزينة والمالية والرسوم' : 'Finance & Fees') . '</span>
                        </a>
                        <a href="' . $baseUrl . '/modules/HR/index.php" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-users-gear text-purple" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'الموارد البشرية والكادر' : 'HR & Personnel') . '</span>
                        </a>
                        <a href="' . $baseUrl . '/support.php" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-headset text-danger" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'مركز الدعم الفني IT' : 'IT Support Center') . '</span>
                        </a>
                        <a href="' . $baseUrl . '/docs.php" class="nav-link py-2 px-3 rounded-lg text-foreground d-flex align-items-center gap-2.5 font-medium hover-bg-secondary">
                            <i class="fa-solid fa-book-bookmark text-muted-foreground" style="width: 20px;"></i>
                            <span>' . ($is_rtl ? 'دليل النظام والتوثيق' : 'Documentation') . '</span>
                        </a>
                    </div>
                </div>

                <!-- Bottom User Actions -->
                <div class="border-top border-border pt-3">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <span class="small text-muted-foreground">' . ($is_rtl ? 'المظهر واللغة:' : 'Theme & Language:') . '</span>
                        <div class="d-flex gap-2">
                            ' . $theme_btn . '
                            ' . $lang_btn . '
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <a href="' . htmlspecialchars($pw_url) . '" class="btn btn-outline-border rounded-lg py-2 text-start d-flex align-items-center gap-2">
                            <i class="fa-solid fa-key text-muted-foreground"></i>
                            <span>' . __('change_password') . '</span>
                        </a>
                        <a href="' . htmlspecialchars($logout_url) . '" class="btn btn-danger-subtle text-danger rounded-lg py-2 text-start d-flex align-items-center gap-2">
                            <i class="fa-solid fa-right-from-bracket"></i>
                            <span>' . __('logout') . '</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>';
    }
}

if (!function_exists('render_unified_navbar')) {
    /**
     * Render PRISM EDU Unified Top Navigation & Slide-out Drawer
     *
     * @param string $activeNav Active navigation tab (dashboard, attendance, admissions, finance, academics, reports, profile, settings)
     * @return string HTML output
     */
    function render_unified_navbar(string $activeNav = 'dashboard'): string {
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
        $unifiedFile = dirname(__DIR__) . '/components/unified_navbar.php';
        if (!file_exists($unifiedFile)) {
            return '';
        }
        ob_start();
        echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl) . '/assets/css/prism-unified-nav.css">' . "\n";
        if (empty($GLOBALS['app_sidebar_rendered'])) {
            require_once dirname(__DIR__) . '/core/Layout.php';
            echo Layout::renderSidebar(['active_module' => $activeNav]);
        }
        include $unifiedFile;
        echo '<script src="' . htmlspecialchars($baseUrl) . '/assets/js/prism-unified-nav.js" defer></script>' . "\n";
        return ob_get_clean();
    }
}

// Render Universal Platform Compliance Footer (Identical to Dashboard Design)
if (!function_exists('render_platform_footer')) {
    function render_platform_footer(): string {
        $is_rtl = is_rtl();
        $docs_url = defined('BASE_URL') ? BASE_URL . '/docs.php' : '/school_system/docs.php';
        $privacy_url = defined('BASE_URL') ? BASE_URL . '/privacy.php' : '/school_system/privacy.php';
        $support_url = defined('BASE_URL') ? BASE_URL . '/support.php' : '/school_system/support.php';
        $watcherScript = '';
        if (class_exists('Auth') && Auth::check()) {
            $base = defined('BASE_URL') ? BASE_URL : '/school_system';
            $watcherScript = "\n        <script src=\"" . htmlspecialchars($base) . "/assets/js/inactivity_watcher.js\"></script>" .
                             "\n        <script src=\"" . htmlspecialchars($base) . "/assets/js/prism-sync.js\"></script>";
        }

        return '
        <footer class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-4 pb-2 border-top border-border text-muted-foreground small mt-5">
            <div class="d-flex align-items-center gap-3">
                <span class="d-inline-flex align-items-center gap-1.5 fw-medium text-foreground">
                    <i class="fa-solid fa-circle-check text-success"></i>
                    <span>' . ($is_rtl ? 'النظام محدث: الإصدار v2.5.0 مستقر' : 'System is up to date: v2.5.0 Stable Build') . '</span>
                </span>
                <span>·</span>
                <a href="' . htmlspecialchars($docs_url) . '" class="text-muted-foreground text-decoration-none hover-underline">' . ($is_rtl ? 'دليل المنصة' : 'Platform Documentation') . '</a>
                <span>·</span>
                <a href="' . htmlspecialchars($privacy_url) . '" class="text-muted-foreground text-decoration-none hover-underline">' . ($is_rtl ? 'سياسة الخصوصية' : 'Privacy Policy') . '</a>
            </div>
            <div>
                <a href="' . htmlspecialchars($support_url) . '" class="text-muted-foreground text-decoration-none hover-underline"><i class="fa-solid fa-headset me-1"></i>' . ($is_rtl ? 'مركز الدعم والمساعدة' : 'Support Center') . '</a>
            </div>
        </footer>' . $watcherScript;
    }
}

// Translation Dictionary
$TRANSLATIONS = [
    'en' => [
        // Top Navigation & Auth
        'app_name' => 'School Management System',
        'admin_control_center' => 'Admin Control Center',
        'teacher_hub' => 'Teacher Hub & Grading Desk',
        'student_portal' => 'Student Academic Portal',
        'parent_portal' => 'Parent / Guardian Portal',
        'main_dashboard' => 'Main Dashboard',
        'signed_in_as' => 'Signed in as',
        'welcome' => 'Welcome',
        'login' => 'Sign In',
        'logout' => 'Logout',
        'password' => 'Password',
        'change_password' => 'Change Password',
        'account_security' => 'Account Security',
        'system_dashboard' => 'System Dashboard',
        'system_subtitle' => 'Manage portals, academic progress, timetables, and administration in one centralized hub.',

        // Roles
        'role_admin' => 'Administrator',
        'role_teacher' => 'Teacher',
        'role_hr' => 'HR Manager',
        'role_student' => 'Student',
        'role_parent' => 'Parent / Guardian',
        'role_finance' => 'Finance Manager',
        'role' => 'System Role',

        // Status
        'status_active' => 'Active',
        'status_inactive' => 'Inactive',
        'status_suspended' => 'Suspended',
        'status_paid' => 'Paid',
        'status_unpaid' => 'Unpaid',
        'status_partial' => 'Partially Paid',
        'status_pending' => 'Pending Review',
        'status_accepted' => 'Accepted',
        'status_needs_revision' => 'Needs Revision',

        // Metrics & Stats
        'total_students' => 'Total Students',
        'teaching_staff' => 'Teaching Staff',
        'classes_and_sections' => 'Classes & Sections',
        'classes_and_subjects' => 'Classes & Subjects',
        'active_accounts' => 'Active Accounts',
        'assigned_classes' => 'Assigned Classes',
        'published_daily_logs' => 'Published Daily Logs',
        'pending_reviews' => 'Pending Reviews',
        'academic_term' => 'Academic Term',
        'account_status' => 'Account Status',

        // Admin Action Cards
        'user_management' => 'User Management',
        'user_management_sub' => 'Activate & manage accounts',
        'create_user' => 'Create User',
        'create_user_sub' => 'Add system accounts',
        'manage_classes' => 'Manage Classes',
        'manage_classes_sub' => 'Grades & Sections',
        'manage_subjects' => 'Manage Subjects',
        'manage_subjects_sub' => 'Curriculum & Teachers',
        'branding_details' => 'Branding & Profile',
        'branding_details_sub' => 'Logo & Contact info',
        'student_admission' => 'Student Admission',
        'student_admission_sub' => 'Enroll new students',
        'license_activation' => 'License Activation',
        'license_activation_sub' => 'System serial & status',
        'finance_vault' => 'Finance & Vault',
        'finance_vault_sub' => 'Invoices, fees & expenses',
        'data_migration' => 'Data Migration Hub',
        'data_migration_sub' => 'Import legacy CSV/Excel & backup',

        // Dashboard Modules
        'system_portals_modules' => 'System Portals & Modules',
        'select_workspace' => 'Select a workspace below',
        'role_portal_title' => 'Role Portal',
        'role_portal_sub' => 'Primary role workspace',
        'notice_board' => 'Notice Board',
        'notice_board_sub' => 'Announcements & News',
        'view_notices' => 'View Notices',
        'exams_and_grading' => 'Exams & Grading',
        'exams_grading_sub' => 'Marks & Performance',
        'open_grading_hub' => 'Open Grading Hub',
        'report_cards' => 'Report Cards',
        'report_cards_sub' => 'Printable Evaluation',
        'view_my_report_card' => 'View My Report Card',
        'student_report_cards' => 'Student Report Cards',
        'class_timetables' => 'Class Timetables',
        'class_timetables_sub' => 'Weekly schedules & rooms',
        'open_timetable' => 'Open Timetable',
        'fees_and_finance' => 'Fees & Finance',
        'fees_finance_sub' => 'Billing, Invoices & Vault',
        'open_finance' => 'Open Finance',
        'daily_class_logs' => 'Daily Class Logs',
        'daily_logs_sub' => 'Attendance & Lesson Notes',
        'open_daily_logs' => 'Open Daily Logs',

        // Common Buttons & Actions
        'save' => 'Save',
        'save_changes' => 'Save Changes',
        'update' => 'Update',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'cancel' => 'Cancel',
        'submit' => 'Submit',
        'create_account' => 'Create Account',
        'add_new' => 'Add New',
        'actions' => 'Actions',
        'deactivate' => 'Deactivate',
        'activate' => 'Activate',
        'search' => 'Search',
        'filter' => 'Filter',
        'print' => 'Print',
        'back' => 'Back',

        // Form Fields
        'full_name' => 'Full Name',
        'username' => 'Username',
        'email_address' => 'Email Address',
        'initial_password' => 'Initial Password',
        'class_name' => 'Class Name / Grade',
        'section_clause' => 'Section / Clause (Optional)',
        'subject_name' => 'Subject Name',
        'subject_code' => 'Subject Code',
        'select_teacher' => 'Select Teacher',
        'select_class' => 'Select Class',
        'select_subject' => 'Select Subject',
        'due_date' => 'Due Date',
        'instructions' => 'Instructions',
        'grade_score' => 'Grade / Score',
        'teacher_feedback' => 'Teacher Feedback / Remarks',
        'school_name' => 'Institution / School Name',
        'school_phone' => 'Phone Number / Contact',
        'school_email' => 'Official Email Address',
        'school_website' => 'Website URL',
        'school_address' => 'Physical Address / Location',
        'school_logo' => 'Upload Official School Logo',
        'activation_key' => 'Activation Key / Serial',
        'save_teacher_allocation' => 'Save Teacher Allocation',
        'active_teacher_allocations' => 'Active Teacher Allocations',
        'curriculum_subjects' => 'Curriculum Subjects Catalog',

        // Migration Specific Terms
        'migration_type' => 'Migration Category',
        'import_students' => 'Students & Enrollments',
        'import_teachers' => 'Teachers & Allocations',
        'import_classes' => 'Classes & Sections',
        'import_subjects' => 'Subjects & Courses',
        'import_grades' => 'Academic Marks & Gradebook',
        'import_fees' => 'Fee Invoices & Balances',
        'import_sql' => 'Raw SQL Migration File',
        'upload_file' => 'Select File (CSV, JSON, or SQL)',
        'duplicate_handling' => 'Duplicate & Conflict Policy',
        'mode_skip' => 'Skip Existing Records (Recommended)',
        'mode_update' => 'Update & Overwrite Existing',
        'mode_abort' => 'Halt & Abort on Conflict',
        'auto_create_classes' => 'Auto-create missing Classes & Subjects if not found',
        'download_templates' => 'Download Sample CSV Templates',
        'backup_system' => 'Download Full System Database Backup (.SQL)',
        'migration_results' => 'Migration Execution Summary',
        'total_rows' => 'Total Rows Read',
        'rows_inserted' => 'Successfully Inserted',
        'rows_updated' => 'Updated Records',
        'rows_skipped' => 'Skipped Duplicates',
        'errors_encountered' => 'Errors Encountered',
        'start_migration' => 'Execute Migration Now',

        // Alerts & Messages
        'confirm_delete' => 'Are you sure you want to delete this item?',
        'no_records_found' => 'No records found.',
        'license_active' => 'License Active',
        'license_warning' => 'License Warning',

        // 7 Enterprise Features Terms
        'student_profile_360' => 'Student 360 Profile',
        'medical_notes' => 'Medical Profile & Allergies',
        'blood_group' => 'Blood Group',
        'allergies' => 'Allergies',
        'medical_conditions' => 'Chronic Conditions',
        'medications' => 'Ongoing Medications',
        'emergency_contact' => 'Emergency Contact',
        'previous_school' => 'Previous School Attended',
        'academic_history' => 'Academic History & Transcripts',
        'immunization_status' => 'Immunization Status',
        'rfid_badge_id' => 'RFID / Barcode Card ID',
        'attendance_tracking' => 'Attendance Tracking',
        'attendance_tracking_sub' => 'Automated RFID, Biometrics & Roll-Call',
        'mark_attendance' => 'Take Class Attendance',
        'rfid_scanner' => 'Automated RFID / Barcode Scanner',
        'present' => 'Present',
        'absent' => 'Absent',
        'late' => 'Late',
        'excused' => 'Excused',
        'parent_alert_sent' => 'Instant Parent Alert Sent',
        'parent_teacher_messages' => 'Parent-Teacher Communication',
        'parent_teacher_messages_sub' => 'Tracked direct inquiries and notices',
        'compose_message' => 'Send Official Message',
        'inbox' => 'Inbox',
        'sent_messages' => 'Sent Messages',
        'inquiry_type' => 'Inquiry Category',
        'conflict_detector' => 'Schedule Conflict Resolver',
        'no_conflicts_found' => 'No Schedule Conflicts Found',
        'conflict_warning' => 'Schedule Collision Detected',
        'academic_transcript' => 'Official Academic Transcript',
        'academic_transcript_sub' => 'Cumulative GPA and multi-term records',
        'generate_transcript' => 'Generate Transcript',
        'cumulative_gpa' => 'Cumulative GPA',
        'total_credit_hours' => 'Total Credit Hours',
        'staff_leaves' => 'Staff Leave Management',
        'staff_leaves_sub' => 'Applications, balances & approvals',
        'apply_leave' => 'Apply for Leave',
        'leave_type' => 'Leave Type',
        'leave_status' => 'Approval Status',
        'staff_payroll' => 'HR & Payroll Processing',
        'staff_payroll_sub' => 'Salary calculations & payslips',
        'reporting_analytics' => 'Reporting & Real-Time Analytics',
        'reporting_analytics_sub' => 'Interactive charts & institutional KPIs',
    ],

    'ar' => [
        // Top Navigation & Auth
        'app_name' => 'نظام إدارة المدرسة',
        'admin_control_center' => 'مركز التحكم الإداري',
        'teacher_hub' => 'بوابة المعلمين والتقييم الأكاديمي',
        'student_portal' => 'البوابة الأكاديمية للطلاب',
        'parent_portal' => 'بوابة أولياء الأمور',
        'main_dashboard' => 'لوحة التحكم الرئيسية',
        'signed_in_as' => 'تم تسجيل الدخول باسم',
        'welcome' => 'مرحباً',
        'login' => 'تسجيل الدخول',
        'logout' => 'تسجيل الخروج',
        'password' => 'كلمة المرور',
        'change_password' => 'تغيير كلمة المرور',
        'account_security' => 'أمان الحساب',
        'system_dashboard' => 'لوحة معلومات النظام',
        'system_subtitle' => 'إدارة البوابات والتقدم الأكاديمي والجداول الدراسية والسجلات المالية والإدارية من مركز موحد.',

        // Roles
        'role_admin' => 'مدير النظام',
        'role_teacher' => 'معلم / أستاذ',
        'role_hr' => 'مدير الموارد البشرية (HR)',
        'role_student' => 'طالب',
        'role_parent' => 'ولي أمر',
        'role_finance' => 'المسؤول المالي',
        'role' => 'نوع الحساب / الدور',

        // Status
        'status_active' => 'نشط',
        'status_inactive' => 'غير نشط',
        'status_suspended' => 'معلق',
        'status_paid' => 'مدفوع بالكامل',
        'status_unpaid' => 'غير مدفوع',
        'status_partial' => 'مدفوع جزئياً',
        'status_pending' => 'قيد المراجعة',
        'status_accepted' => 'مقبول ومقيم',
        'status_needs_revision' => 'يتطلب إعادة التقديم',

        // Metrics & Stats
        'total_students' => 'إجمالي الطلاب',
        'teaching_staff' => 'الكادر التعليمي',
        'classes_and_sections' => 'الفصول والشُعب',
        'classes_and_subjects' => 'الفصول والمواد',
        'active_accounts' => 'الحسابات النشطة',
        'assigned_classes' => 'الفصول المسندة',
        'published_daily_logs' => 'الدروس اليومية المنشورة',
        'pending_reviews' => 'الواجبات قيد التقييم',
        'academic_term' => 'الفصل الدراسي',
        'account_status' => 'حالة الحساب',

        // Admin Action Cards
        'user_management' => 'إدارة المستخدمين',
        'user_management_sub' => 'تفعيل وإدارة الحسابات',
        'create_user' => 'إنشاء مستخدم جديد',
        'create_user_sub' => 'إضافة حساب للنظام',
        'manage_classes' => 'إدارة الفصول',
        'manage_classes_sub' => 'المراحل والشُعب الدراسية',
        'manage_subjects' => 'إدارة المواد الدراسية',
        'manage_subjects_sub' => 'المناهج وتعيين المعلمين',
        'branding_details' => 'الهوية والمؤسسة',
        'branding_details_sub' => 'الشعار وبيانات التواصل',
        'student_admission' => 'تسجيل الطلاب',
        'student_admission_sub' => 'قبول وتسجيل المستجدين',
        'license_activation' => 'تفعيل الترخيص',
        'license_activation_sub' => 'سيريال ورخصة النظام',
        'finance_vault' => 'المالية والخزينة',
        'finance_vault_sub' => 'الفواتير والرسوم والمصروفات',
        'data_migration' => 'مركز ترحيل البيانات',
        'data_migration_sub' => 'استيراد بيانات الأنظمة السابقة والنسخ الاحتياطي',

        // Dashboard Modules
        'system_portals_modules' => 'بوابات ووحدات النظام',
        'select_workspace' => 'اختر مساحة العمل المطلوبة أدناه',
        'role_portal_title' => 'بوابة الدور المخصصة',
        'role_portal_sub' => 'مساحة العمل الأساسية لحسابك',
        'notice_board' => 'لوحة الإعلانات',
        'notice_board_sub' => 'التعميمات والأخبار المدرسية',
        'view_notices' => 'عرض الإعلانات',
        'exams_and_grading' => 'الاختبارات والدرجات',
        'exams_grading_sub' => 'رصد الدرجات والتقييم',
        'open_grading_hub' => 'فتح سجل الدرجات',
        'report_cards' => 'الشهادات والنتائج',
        'report_cards_sub' => 'تقارير تقييم الطلاب للطباعة',
        'view_my_report_card' => 'عرض شهادتي الأكاديمية',
        'student_report_cards' => 'شهادات تقييم الطلاب',
        'class_timetables' => 'الجداول المدرسية',
        'class_timetables_sub' => 'الحصص والقاعات الأسبوعية',
        'open_timetable' => 'فتح جدول الحصص',
        'fees_and_finance' => 'الرسوم والمالية',
        'fees_finance_sub' => 'الفواتير والتحصيل والخزينة',
        'open_finance' => 'فتح الإدارة المالية',
        'daily_class_logs' => 'السجل اليومي والتحضير',
        'daily_logs_sub' => 'الدروس المنجزة والواجبات',
        'open_daily_logs' => 'فتح السجل اليومي',

        // Common Buttons & Actions
        'save' => 'حفظ',
        'save_changes' => 'حفظ التعديلات',
        'update' => 'تحديث',
        'edit' => 'تعديل',
        'delete' => 'حذف',
        'cancel' => 'إلغاء',
        'submit' => 'إرسال',
        'create_account' => 'إنشاء الحساب',
        'add_new' => 'إضافة جديد',
        'actions' => 'الإجراءات',
        'deactivate' => 'إلغاء التفعيل',
        'activate' => 'تفعيل الحساب',
        'search' => 'بحث',
        'filter' => 'تصفية',
        'print' => 'طباعة',
        'back' => 'رجوع',

        // Form Fields
        'full_name' => 'الاسم الكامل',
        'username' => 'اسم المستخدم',
        'email_address' => 'البريد الإلكتروني',
        'initial_password' => 'كلمة المرور الأولية',
        'class_name' => 'اسم الفصل / المرحلة',
        'section_clause' => 'الشعبة / الفصل (اختياري)',
        'subject_name' => 'اسم المادة الدراسية',
        'subject_code' => 'رمز المادة',
        'select_teacher' => 'اختر المعلم',
        'select_class' => 'اختر الفصل الدراسي',
        'select_subject' => 'اختر المادة الدراسية',
        'due_date' => 'تاريخ التسليم / الاستحقاق',
        'instructions' => 'التعليمات والتوجيهات',
        'grade_score' => 'الدرجة / التقييم',
        'teacher_feedback' => 'ملاحظات المعلم والتغذية الراجعة',
        'school_name' => 'اسم المؤسسة / المدرسة',
        'school_phone' => 'رقم الهاتف / الاتصال',
        'school_email' => 'البريد الإلكتروني الرسمي',
        'school_website' => 'رابط الموقع الإلكتروني',
        'school_address' => 'العنوان والموقع الجغرافي',
        'school_logo' => 'رفع الشعار الرسمي للمدرسة',
        'activation_key' => 'مفتاح التفعيل / السيريال',
        'save_teacher_allocation' => 'حفظ إسناد المعلم',
        'active_teacher_allocations' => 'جدول إسناد المواد للمعلمين',
        'curriculum_subjects' => 'كتالوج المواد الدراسية',

        // Migration Specific Terms
        'migration_type' => 'نوع البيانات المراد استيرادها',
        'import_students' => 'الطلاب وبيانات التسجيل',
        'import_teachers' => 'المعلمون وإسناد المواد',
        'import_classes' => 'الفصول والمراحل والشعب',
        'import_subjects' => 'المواد والمناهج الدراسية',
        'import_grades' => 'الدرجات وسجل النتائج الأكاديمية',
        'import_fees' => 'الفواتير والرسوم الدراسية',
        'import_sql' => 'ملف سكريبت SQL مباشر',
        'upload_file' => 'اختر الملف (CSV أو JSON أو SQL)',
        'duplicate_handling' => 'سياسة التعامل مع السجلات المكررة',
        'mode_skip' => 'تخطي السجلات الموجودة مسبقاً (موصى به)',
        'mode_update' => 'تحديث واستبدال البيانات السابقة',
        'mode_abort' => 'إيقاف العملية فور وجود تطابق',
        'auto_create_classes' => 'إنشاء الفصول والمواد غير الموجودة تلقائياً',
        'download_templates' => 'تحميل قوالب CSV الجاهزة للاستيراد',
        'backup_system' => 'تحميل نسخة احتياطية كاملة للنظام (.SQL)',
        'migration_results' => 'ملخص تقرير عملية الاستيراد والترحيل',
        'total_rows' => 'إجمالي السجلات المقروءة',
        'rows_inserted' => 'سجلات تم إدخالها بنجاح',
        'rows_updated' => 'سجلات تم تحديثها',
        'rows_skipped' => 'سجلات تم تخطيها',
        'errors_encountered' => 'أخطاء تم رصدها',
        'start_migration' => 'بدء الترحيل والاستيراد الآن',

        // Alerts & Messages
        'confirm_delete' => 'هل أنت متأكد من رغبتك في حذف هذا العنصر؟',
        'no_records_found' => 'لا توجد سجلات مسجلة حالياً.',
        'license_active' => 'الترخيص نشط ومفعل',
        'license_warning' => 'تنبيه بشأن الترخيص',

        // 7 Enterprise Features Terms
        'student_profile_360' => 'ملف الطالب الشامل',
        'medical_notes' => 'الملاحظات الطبية والحساسية',
        'blood_group' => 'فصيلة الدم',
        'allergies' => 'الحساسية',
        'medical_conditions' => 'الأمراض المزمنة',
        'medications' => 'الأدوية المستمرة',
        'emergency_contact' => 'جهة اتصال الطوارئ',
        'previous_school' => 'المدرسة السابقة',
        'academic_history' => 'السجل والمسار الأكاديمي',
        'immunization_status' => 'حالة التطعيمات',
        'rfid_badge_id' => 'معرّف البطاقة الذكية / الباركود',
        'attendance_tracking' => 'سجل الحضور والغياب',
        'attendance_tracking_sub' => 'حضور ذكي بالبطاقة والباركود وقوائم الفصل',
        'mark_attendance' => 'تسجيل حضور الفصل',
        'rfid_scanner' => 'ماسح بطاقات الحضور الذكية',
        'present' => 'حاضر',
        'absent' => 'غائب',
        'late' => 'متأخر',
        'excused' => 'غياب بعذر',
        'parent_alert_sent' => 'تم إرسال تنبيه فوري لولي الأمر',
        'parent_teacher_messages' => 'المراسلات والتواصل مع أولياء الأمور',
        'parent_teacher_messages_sub' => 'استفسارات رسمية وتواصل مباشر موثق',
        'compose_message' => 'إرسال رسالة رسمية',
        'inbox' => 'صندوق الوارد',
        'sent_messages' => 'الرسائل المرسلة',
        'inquiry_type' => 'تصنيف الاستفسار',
        'conflict_detector' => 'كاشف وحال تعارض الجداول',
        'no_conflicts_found' => 'لا يوجد أي تعارض في الجداول والقاعات',
        'conflict_warning' => 'تم رصد تعارض في جدول الحصص أو القاعات',
        'academic_transcript' => 'كشف الدرجات والشهادة الأكاديمية الرسمية',
        'academic_transcript_sub' => 'المعدل التراكمي وسجل الفصول المتعددة',
        'generate_transcript' => 'استخراج كشف الدرجات',
        'cumulative_gpa' => 'المعدل التراكمي العام',
        'total_credit_hours' => 'إجمالي الساعات المعتمدة',
        'staff_leaves' => 'إدارة إجازات المعلمين والموظفين',
        'staff_leaves_sub' => 'تقديم الطلبات، الأرصدة، والموافقات',
        'apply_leave' => 'طلب إجازة جديدة',
        'leave_type' => 'نوع الإجازة',
        'leave_status' => 'حالة الاعتماد',
        'staff_payroll' => 'الرواتب والمسيرات المالية',
        'staff_payroll_sub' => 'احتساب الرواتب والبدلات وقسائم الصرف',
        'reporting_analytics' => 'التقارير ولوحة التحليلات البيانية',
        'reporting_analytics_sub' => 'مؤشرات الأداء والرسوم البيانية التفاعلية',
    ]
];

// Register globally so dictionary is accessible from all nested include scopes
$GLOBALS['TRANSLATIONS'] = $TRANSLATIONS;

if (!function_exists('__')) {
    /**
     * @param string $key
     * @param string|null $default
     * @return string
     */
    function __(string $key, ?string $default = null): string {
        $dict = $GLOBALS['TRANSLATIONS'] ?? [];
        $lang = current_lang();
        
        if (isset($dict[$lang][$key])) {
            return $dict[$lang][$key];
        }
        if (isset($dict['en'][$key])) {
            return $dict['en'][$key];
        }
        return $default !== null ? $default : $key;
    }
}