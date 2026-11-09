<?php
require_once __DIR__ . '/core/init.php';

$is_logged_in = Auth::check();
$user = $is_logged_in ? Auth::user() : null;
$role = $is_logged_in ? Auth::role() : 'guest';
$school_name = $global_branding['school_name'] ?? 'EduMaster';
$academic_year = $global_branding['academic_year'] ?? '2025/2026';
$school_logo = $global_branding['school_logo'] ?? '';
$current_system_version = SystemUpdater::getCurrentVersion();
$health_metrics = Telemetry::getHealthMetrics();
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'الدليل الشامل والتوثيق التقني للنظام' : 'Complete System Documentation & User Manual' ?> | <?= htmlspecialchars($school_name) ?></title>
    
    <?= get_bootstrap_css() ?>

    
    <style>
        :root {
            --font-main: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-heading: 'Outfit', sans-serif;
            --background: #f8fafc;
            --foreground: #0f172a;
            --card: #ffffff;
            --card-foreground: #0f172a;
            --popover: #ffffff;
            --popover-foreground: #0f172a;
            --primary: #4338ca;
            --primary-hover: #3730a3;
            --primary-foreground: #ffffff;
            --secondary: #f1f5f9;
            --secondary-foreground: #1e293b;
            --muted: #f8fafc;
            --muted-foreground: #64748b;
            --accent: #f1f5f9;
            --accent-foreground: #0f172a;
            --border: #e2e8f0;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --radius-xl: 24px;
        }

        [data-theme="dark"] {
            --background: #090d16;
            --foreground: #f8fafc;
            --card: #111827;
            --card-foreground: #f8fafc;
            --secondary: #1e293b;
            --secondary-foreground: #f8fafc;
            --muted: #1e293b;
            --muted-foreground: #94a3b8;
            --border: #2d3748;
            --accent: #1e293b;
            --accent-foreground: #f8fafc;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--background);
            color: var(--foreground);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .font-heading { font-family: var(--font-heading); }

        .navbar-custom {
            background-color: var(--card);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1.5rem;
            position: sticky;
            top: 0;
            z-index: 1020;
        }

                .docs-layout-container {
            display: flex;
            position: relative;
            width: 100%;
            min-height: calc(100vh - 85px);
            gap: 0;
            direction: ltr !important; /* Forces sidebar on the LEFT across all languages */
        }

        .docs-sidebar-col {
            width: var(--docs-sidebar-width, 290px);
            min-width: 220px;
            max-width: 600px;
            flex-shrink: 0;
            position: relative;
            align-self: stretch; /* Extends to full height of main content so sticky tracks the entire page */
        }

        [dir="rtl"] .docs-sidebar-col {
            direction: rtl;
            text-align: right;
        }

        .docs-sidebar {
            position: sticky !important;
            top: 85px !important; /* Pinned right below the sticky header */
            height: calc(100vh - 100px);
            max-height: calc(100vh - 100px);
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: thin;
            border-right: 1px solid var(--border) !important;
            border-left: none !important;
            padding-right: 1.25rem !important;
            padding-left: 0.25rem;
        }

        [dir="rtl"] .docs-sidebar {
            border-right: 1px solid var(--border) !important;
            border-left: none !important;
            padding-right: 1.25rem !important;
            padding-left: 0.25rem;
        }

        /* Resizer Drag Handle Bar */
        .docs-resizer {
            width: 14px;
            cursor: col-resize;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            user-select: none;
            touch-action: none;
            z-index: 100;
            margin-left: -7px;
            margin-right: -7px;
            flex-shrink: 0;
            transition: background-color 0.15s ease;
        }

        .docs-resizer:hover, .docs-resizer.is-dragging {
            background: rgba(67, 56, 202, 0.12);
            border-radius: 6px;
        }

        .resizer-handle-pill {
            width: 4px;
            height: 48px;
            border-radius: 9999px;
            background: var(--border);
            transition: background 0.2s ease, height 0.2s ease;
        }

        .docs-resizer:hover .resizer-handle-pill,
        .docs-resizer.is-dragging .resizer-handle-pill {
            background: var(--primary);
            height: 76px;
        }

        body.is-resizing {
            cursor: col-resize !important;
            user-select: none !important;
        }

        .docs-main-col {
            flex: 1;
            min-width: 0;
            padding-left: 1.75rem !important;
            padding-right: 0.5rem;
        }

        [dir="rtl"] .docs-main-col {
            direction: rtl;
            text-align: right;
            padding-left: 1.75rem !important;
            padding-right: 0.5rem;
        }

        /* Smooth scroll offset so anchor links land directly below the sticky navbar */
        .docs-card,
        [id^="sec-"] {
            scroll-margin-top: 95px;
        }

        @media (max-width: 768px) {
            .docs-layout-container {
                flex-direction: column !important;
            }
            .docs-sidebar-col {
                width: 100% !important;
                max-width: 100%;
                min-width: 100%;
                margin-bottom: 1.5rem;
            }
            .docs-resizer {
                display: none;
            }
            .docs-main-col {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }
            .docs-sidebar {
                position: static !important;
                height: auto;
                max-height: none;
                border-right: none !important;
                border-bottom: 1px solid var(--border) !important;
                padding-right: 0 !important;
                padding-bottom: 1.5rem;
            }
        }

        .docs-nav-group-title {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--muted-foreground);
            margin-top: 1.25rem;
            margin-bottom: 0.5rem;
            padding-inline-start: 0.5rem;
        }

        .docs-nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.55rem 0.85rem;
            border-radius: var(--radius-md);
            color: var(--muted-foreground);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.875rem;
            transition: all 0.2s ease;
            margin-bottom: 0.2rem;
        }

        .docs-nav-link:hover, .docs-nav-link.active {
            color: var(--primary);
            background-color: var(--secondary);
        }

        .docs-nav-link.active {
            font-weight: 700;
            border-inline-start: 3px solid var(--primary);
        }

        .docs-card {
            background-color: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 2rem;
            margin-bottom: 2.25rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .docs-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        .docs-code-block {
            background: #0f172a;
            color: #38bdf8;
            border-radius: var(--radius-md);
            padding: 1.25rem;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.875rem;
            position: relative;
            overflow-x: auto;
            border: 1px solid #1e293b;
            margin: 1rem 0;
        }

        .badge-role {
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.35rem 0.65rem;
            border-radius: 9999px;
        }

        .topic-badge {
            background: var(--secondary);
            color: var(--secondary-foreground);
            font-size: 0.75rem;
            font-weight: 600;
            padding: 0.3rem 0.65rem;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
        }

        .step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            background: var(--primary);
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            margin-inline-end: 0.75rem;
            flex-shrink: 0;
        }

        .guide-step {
            display: flex;
            align-items: flex-start;
            margin-bottom: 1rem;
            padding: 0.75rem 1rem;
            background: var(--secondary);
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
        }

        .search-hero {
            background: linear-gradient(135deg, rgba(67, 56, 202, 0.12) 0%, rgba(99, 102, 241, 0.05) 100%);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            padding: 2.5rem 2rem;
            margin-bottom: 2rem;
        }

        .feature-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 0.75rem;
            background: var(--secondary);
            border: 1px solid var(--border);
            border-radius: 9999px;
            font-size: 0.8rem;
            font-weight: 600;
            margin: 0.2rem;
        }

        .module-header-box {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }

        .module-icon-box {
            width: 50px;
            height: 50px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

    <!-- Main Navigation Topbar & Compact Sidebar -->
    <?php
    if (empty($GLOBALS['app_sidebar_rendered'])) {
        require_once __DIR__ . '/core/Layout.php';
        echo Layout::renderSidebar(['active_module' => 'docs']);
    }
    ?>
    <header class="navbar-custom sticky-top">
        <div class="container-fluid d-flex align-items-center justify-content-between flex-nowrap gap-2 gap-lg-3">
            <div class="d-flex align-items-center gap-2 gap-md-3 min-w-0 flex-shrink-1">
                <a href="<?= $is_logged_in ? 'dashboard.php' : 'login.php' ?>" class="d-flex align-items-center gap-2 text-decoration-none text-foreground min-w-0">
                    <?php if (function_exists('render_theme_logo_html')): ?>
                        <?= render_theme_logo_html('max-height: 36px; width: auto; max-width: 120px; object-fit: contain;', 'rounded shadow-xs flex-shrink-0') ?>
                    <?php elseif (!empty($logo_url)): ?>
                        <img src="<?= htmlspecialchars($logo_url) ?>" alt="Logo" class="rounded p-1 bg-white border border-border flex-shrink-0" style="max-height: 36px; width: auto;">
                    <?php else: ?>
                        <div class="bg-primary text-white rounded p-2 d-flex align-items-center justify-content-center flex-shrink-0" style="width: 36px; height: 36px; font-weight: 700;">
                            <?= mb_substr($school_name, 0, 1) ?>
                        </div>
                    <?php endif; ?>
                    <span class="font-heading fw-bold fs-5 text-truncate" style="max-width: 220px;"><?= htmlspecialchars($school_name) ?></span>
                </a>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1.5 fw-semibold d-none d-md-inline-flex flex-shrink-0 text-truncate" style="max-width: 260px;">
                    <i class="fa-solid fa-book-bookmark me-1.5"></i> <?= is_rtl() ? 'الدليل الشامل للتشغيل والتطوير' : 'Master System Manual & API Docs' ?>
                </span>
            </div>

            <div class="d-flex align-items-center flex-nowrap gap-1 gap-sm-2 flex-shrink-0">
                <?php 
                $curr_lang = current_lang(); 
                $target_lang = ($curr_lang === 'ar') ? 'en' : 'ar'; 
                ?>
                <a href="?lang=<?= $target_lang ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-2.5" title="<?= $curr_lang === 'ar' ? 'Switch to English' : 'التحويل إلى العربية' ?>">
                    <i class="fa-solid fa-globe me-1"></i><span class="d-none d-sm-inline"><?= $curr_lang === 'ar' ? 'English' : 'عربي' ?></span>
                </a>

                <button type="button" class="btn btn-outline-secondary btn-sm rounded-pill px-2.5 theme-toggle-btn" onclick="toggleTheme()" title="<?= is_rtl() ? 'تبديل المظهر' : 'Toggle Dark/Light Mode' ?>">
                    <i class="fa-solid fa-moon dark-icon"></i>
                    <i class="fa-solid fa-sun light-icon d-none"></i>
                </button>

                <span class="badge bg-secondary text-secondary-foreground border border-border rounded-pill px-3 py-1.5 small d-none d-xl-inline-flex align-items-center">
                    <i class="fa-solid fa-calendar-check me-1 text-primary"></i> <?= htmlspecialchars($academic_year) ?>
                </span>

                <?php if ($is_logged_in): ?>
                    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
                        <i class="fa-solid fa-arrow-left me-1 <?= is_rtl() ? 'fa-flip-horizontal' : '' ?>"></i> <span class="d-none d-sm-inline"><?= is_rtl() ? 'العودة للوحة التحكم' : 'Return to Dashboard' ?></span>
                    </a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary btn-sm rounded-pill px-3 shadow-xs">
                        <i class="fa-solid fa-right-to-bracket me-1 <?= is_rtl() ? 'fa-flip-horizontal' : '' ?>"></i> <span class="d-none d-sm-inline"><?= is_rtl() ? 'تسجيل الدخول' : 'Sign In' ?></span>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </header>

        <div class="container-fluid px-lg-5 py-4 flex-grow-1">
        <div class="docs-layout-container">
            
            <!-- Resizable Left Documentation Sidebar -->
            <aside id="docsSidebarCol" class="docs-sidebar-col">
                <div class="docs-sidebar">
                    <div class="d-flex align-items-center justify-content-between mb-2 px-1 pb-1 border-bottom border-border">
                        <span class="small fw-bold text-muted-foreground"><i class="fa-solid fa-compass me-1"></i> <?= is_rtl() ? 'فهرس الدليل' : 'Table of Contents' ?></span>
                        <button type="button" id="sidebarResetBtn" class="btn btn-sm btn-outline-secondary py-0 px-2 rounded-pill" style="font-size: 0.72rem;" title="<?= is_rtl() ? 'إعادة ضبط عرض القائمة (290px)' : 'Reset sidebar width (290px)' ?>">
                            <i class="fa-solid fa-arrows-left-right me-1"></i><?= is_rtl() ? 'ضبط' : 'Reset' ?>
                        </button>
                    </div>
                    <div class="docs-nav-group-title"><?= is_rtl() ? 'البيئة والبنية الأساسية' : 'Core & Architecture' ?></div>
                    <nav class="nav flex-column">
                        <a class="docs-nav-link active" href="#sec-how-to-use"><i class="fa-solid fa-book-open-reader text-primary"></i> <?= is_rtl() ? 'دليل الاستخدام والتشغيل الشامل' : 'Master How-To-Use Guide' ?></a>
                        <a class="docs-nav-link" href="#sec-overview"><i class="fa-solid fa-layer-group text-info"></i> <?= is_rtl() ? 'البنية ونظرة عامة' : 'System Architecture' ?></a>
                        <a class="docs-nav-link" href="#sec-dashboard"><i class="fa-solid fa-gauge-high text-info"></i> <?= is_rtl() ? 'لوحة التحكم التفاعلية' : 'Dashboard & Widgets' ?></a>
                        <a class="docs-nav-link" href="#sec-academic-calendar"><i class="fa-solid fa-calendar-days text-purple" style="color: #9333EA;"></i> <?= is_rtl() ? 'التقويم الأكاديمي' : 'Academic Calendar' ?></a>
                        <a class="docs-nav-link" href="#sec-system-updates"><i class="fa-solid fa-cloud-arrow-up text-primary"></i> <?= is_rtl() ? 'التحديثات السحابية (OTA)' : 'OTA System Updates' ?></a>
                        <a class="docs-nav-link" href="#sec-telemetry"><i class="fa-solid fa-shield-halved text-success"></i> <?= is_rtl() ? 'الخصوصية والموثوقية' : 'Zero-PII Telemetry' ?></a>
                        <a class="docs-nav-link" href="#sec-licensing"><i class="fa-solid fa-key text-warning"></i> <?= is_rtl() ? 'إدارة التراخيص والميزات' : 'Commercial Licensing' ?></a>
                    </nav>

                    <div class="docs-nav-group-title"><?= is_rtl() ? 'الوحدات الإدارية والميدانية' : 'Operational Modules' ?></div>
                    <nav class="nav flex-column">
                        <a class="docs-nav-link" href="#sec-administration"><i class="fa-solid fa-school-flag text-primary"></i> <?= is_rtl() ? 'الإدارة المركزية والحوكمة' : 'Administration & RBAC' ?></a>
                        <a class="docs-nav-link" href="#sec-lms"><i class="fa-solid fa-chalkboard-user text-success"></i> <?= is_rtl() ? 'إدارة الفصول والتعلم (LMS)' : 'LMS & Attendance' ?></a>
                        <a class="docs-nav-link" href="#sec-grading"><i class="fa-solid fa-graduation-cap text-danger"></i> <?= is_rtl() ? 'الامتحانات ورصد الدرجات' : 'Grading & Report Cards' ?></a>
                        <a class="docs-nav-link" href="#sec-finance"><i class="fa-solid fa-vault text-warning"></i> <?= is_rtl() ? 'المالية والخزينة والرسوم' : 'Finance & Fee Vault' ?></a>
                        <a class="docs-nav-link" href="#sec-hr"><i class="fa-solid fa-users-gear text-info"></i> <?= is_rtl() ? 'الموارد البشرية والرواتب' : 'HR & Payroll Ledger' ?></a>
                    </nav>

                    <div class="docs-nav-group-title"><?= is_rtl() ? 'المراجع والأدلة المتقدمة' : 'Developer & Guides' ?></div>
                    <nav class="nav flex-column mb-4">
                        <a class="docs-nav-link" href="#sec-portals"><i class="fa-solid fa-network-wired text-secondary-foreground"></i> <?= is_rtl() ? 'بوابات الوصول السريع' : 'Portals & Routing Hub' ?></a>
                        <a class="docs-nav-link" href="#sec-api-reference"><i class="fa-solid fa-code text-primary"></i> <?= is_rtl() ? 'مرجع الـ API ونماذج البيانات' : 'API & Schema Specs' ?></a>
                        <a class="docs-nav-link" href="#sec-shortcuts"><i class="fa-solid fa-keyboard text-muted-foreground"></i> <?= is_rtl() ? 'اختصارات لوحة المفاتيح' : 'Keyboard Shortcuts' ?></a>
                    </nav>

                    <div class="p-3 bg-card border border-border rounded-xl">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="fa-solid fa-circle-check text-success"></i>
                            <span class="fw-bold small"><?= is_rtl() ? 'الإصدار النشط' : 'Active Build' ?></span>
                        </div>
                        <div class="small text-muted-foreground mb-1">
                            <?= htmlspecialchars($current_system_version['version_number'] ?? 'v2.5.0') ?> (Build <?= htmlspecialchars($current_system_version['build_number'] ?? '2500') ?>)
                        </div>
                        <div class="small text-muted-foreground">
                            <?= is_rtl() ? 'معدل الموثوقية:' : 'Reliability Score:' ?> <strong class="text-success"><?= $health_metrics['health_score'] ?>%</strong>
                        </div>
                    </div>
                </div>
            </aside>

            <!-- Interactive Resizer Splitter Gutter -->
            <div id="docsResizer" class="docs-resizer" title="<?= is_rtl() ? 'اسحب لتغيير حجم الشريط الجانبي (انقر مرتين لإعادة الضبط)' : 'Drag to resize sidebar (Double click to reset)' ?>">
                <div class="resizer-handle-pill"></div>
            </div>

            <!-- Right Main Documentation Content Column -->
            <main id="docsMainContent" class="docs-main-col">

                <!-- Search & Welcome Hero Banner -->
                <div class="search-hero">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="font-heading fw-bold text-foreground mb-1">
                                <?= is_rtl() ? 'دليل نظام ' . htmlspecialchars($school_name) . ' الشامل' : htmlspecialchars($school_name) . ' Comprehensive System Manual' ?>
                            </h2>
                            <p class="text-muted-foreground mb-0">
                                <?= is_rtl() 
                                    ? 'توثيق شامل للميزات، الوظائف البرمجية، تصميم وتخطيط الوحدات، وأدلة الاستخدام خطوة بخطوة لكل قسم في المنصة.' 
                                    : 'Complete architectural documentation detailing features, functions, module layouts, and step-by-step user manuals for every component in the system.' ?>
                            </p>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($is_logged_in): ?>
                                <span class="badge bg-primary text-white p-2 px-3 rounded-pill fw-semibold">
                                    <i class="fa-solid fa-user-shield me-1"></i> <?= strtoupper(htmlspecialchars($role)) ?> <?= is_rtl() ? 'جلسة نشطة' : 'Active Context' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary text-secondary-foreground border border-border p-2 px-3 rounded-pill fw-semibold">
                                    <i class="fa-solid fa-book-open me-1 text-primary"></i> <?= is_rtl() ? 'الدليل العام للزوار' : 'Public Documentation' ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Live Dynamic Topic Filter Input -->
                    <div class="position-relative mt-3">
                        <i class="fa-solid fa-magnifying-glass position-absolute top-50 start-0 translate-middle-y ms-3 text-muted-foreground"></i>
                        <input type="text" id="docsSearchInput" class="form-control form-control-lg bg-card text-foreground border-border ps-5 rounded-pill shadow-xs" placeholder="<?= is_rtl() ? 'ابحث عن أي ميزة، موديول، دالة برمجية، أو دليل استخدام...' : 'Search any feature, function, module layout, or user manual...' ?>">
                    </div>

                    <!-- Interactive Module Filter Pills -->
                    <div class="d-flex align-items-center flex-wrap gap-2 mt-3 pt-2">
                        <span class="small text-muted-foreground fw-bold me-1"><?= is_rtl() ? 'تصفية سريعة:' : 'Quick Filters:' ?></span>
                        <button class="btn btn-sm btn-light border filter-btn active" data-filter="all"><?= is_rtl() ? 'الكل' : 'All Sections' ?></button>
                        <button class="btn btn-sm btn-light border filter-btn" data-filter="how-to-use"><?= is_rtl() ? 'دليل الاستخدام وتشغيل النظام' : 'How-To-Use Guide' ?></button>
                        <button class="btn btn-sm btn-light border filter-btn" data-filter="admin"><?= is_rtl() ? 'الإدارة والحوكمة' : 'Administration' ?></button>
                        <button class="btn btn-sm btn-light border filter-btn" data-filter="lms"><?= is_rtl() ? 'التعلم والجداول (LMS)' : 'LMS & Attendance' ?></button>
                        <button class="btn btn-sm btn-light border filter-btn" data-filter="grading"><?= is_rtl() ? 'الدرجات والشهادات' : 'Grading & Exams' ?></button>
                        <button class="btn btn-sm btn-light border filter-btn" data-filter="finance"><?= is_rtl() ? 'المالية والخزينة' : 'Finance & Treasury' ?></button>
                        <button class="btn btn-sm btn-light border filter-btn" data-filter="hr"><?= is_rtl() ? 'الموارد البشرية والرواتب' : 'HR & Payroll' ?></button>
                        <button class="btn btn-sm btn-light border filter-btn" data-filter="core"><?= is_rtl() ? 'البنية التقنية (Core)' : 'Core & OTA' ?></button>
                    </div>
                </div>

                <!-- SECTION 0: MASTER END-TO-END SYSTEM OPERATIONAL GUIDE -->
                <div id="sec-how-to-use" class="docs-card border-primary-subtle shadow-sm" data-category="how-to-use">
                    <div class="module-header-box border-bottom border-primary-subtle pb-3 mb-4">
                        <div class="module-icon-box bg-primary text-white shadow-xs">
                            <i class="fa-solid fa-book-open-reader"></i>
                        </div>
                        <div>
                            <h3 class="font-heading fw-bold mb-1 text-primary"><?= is_rtl() ? 'دليل الاستخدام الشامل والتشغيل خطوة بخطوة لكل قسم وموديول' : 'Master Step-by-Step User Manual & Operational Guide' ?></h3>
                            <span class="topic-badge bg-primary-subtle text-primary border-primary-subtle fw-semibold"><?= is_rtl() ? 'دليل تشغيلي متكامل لجميع الوظائف والعمليات' : 'Complete End-to-End Operational Blueprint' ?></span>
                        </div>
                    </div>

                    <div class="alert bg-primary-subtle text-primary-emphasis border-primary-subtle rounded-xl p-3.5 mb-4">
                        <div class="d-flex align-items-start gap-2.5">
                            <i class="fa-solid fa-circle-info fs-5 mt-0.5"></i>
                            <div>
                                <h6 class="fw-bold mb-1"><?= is_rtl() ? 'كيف تبدأ باستخدام النظام؟' : 'How to Navigate and Operate the System' ?></h6>
                                <p class="small mb-0 leading-relaxed"><?= is_rtl() 
                                    ? 'يوفر هذا القسم دليلاً تنفيذياً متكاملاً يشرح كيفية استخدام النظام من لحظة تسجيل الدخول حتى إتمام كافة العمليات اليومية، الدورية، والفصلية. يحتوي كل قسم على الخطوات المرتبة بالأرقام، المدخلات، المخرجات، ومسارات الملفات المعنية.' 
                                    : 'This section provides an executive blueprint explaining how to operate EduMaster from initial authentication through all daily, monthly, and semester lifecycles with exact step-by-step numbers, inputs, outputs, and controller paths.' ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- 3 Master Lifecycles of School Operation -->
                    <h5 class="fw-bold mb-3 text-foreground"><i class="fa-solid fa-arrows-spin text-primary me-2"></i><?= is_rtl() ? 'الدورات التشغيلية الثلاث الكبرى في النظام (The 3 Master Lifecycles)' : 'The 3 Master Operational Lifecycles' ?></h5>
                    
                    <div class="row g-3 mb-4">
                        <!-- Lifecycle 1 -->
                        <div class="col-lg-4 col-md-6">
                            <div class="p-3 bg-secondary rounded-xl border border-border h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="badge bg-primary text-white rounded-circle p-1 px-2">1</span>
                                        <h6 class="fw-bold mb-0"><?= is_rtl() ? 'الدورة اليومية (Daily Routine)' : 'Daily Routine Cycle' ?></h6>
                                    </div>
                                    <p class="small text-muted-foreground mb-3"><?= is_rtl() ? 'تسجيل حضور الموظفين والطلاب، تنفيذ حصص الجدول الأسبوعي، تكليف وتصحيح الواجبات، وتحصيل الرسوم اليومية بالخزينة.' : 'Faculty check-in, student period attendance, classroom timetable execution, homework grading, and daily tuition cashier receipts.' ?></p>
                                </div>
                                <span class="badge bg-card text-foreground border border-border small text-center py-1.5"><?= is_rtl() ? 'تكرار: يومي مستمر' : 'Cadence: Daily' ?></span>
                            </div>
                        </div>

                        <!-- Lifecycle 2 -->
                        <div class="col-lg-4 col-md-6">
                            <div class="p-3 bg-secondary rounded-xl border border-border h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="badge bg-success text-white rounded-circle p-1 px-2">2</span>
                                        <h6 class="fw-bold mb-0"><?= is_rtl() ? 'الدورة الامتحانية والأكاديمية' : 'Exam & Term Cycle' ?></h6>
                                    </div>
                                    <p class="small text-muted-foreground mb-3"><?= is_rtl() ? 'جدولة مواعيد الاختبارات، رصد الدرجات، حساب المعدل التراكمي GPA، تدقيق المخالصة المالية، وإصدار الشهادات والتقارير.' : 'Exam schedules, numeric grade input, GPA calculation, financial clearance gating, and official report card generation.' ?></p>
                                </div>
                                <span class="badge bg-card text-foreground border border-border small text-center py-1.5"><?= is_rtl() ? 'تكرار: فصلي / نصف سنوي' : 'Cadence: Per Semester' ?></span>
                            </div>
                        </div>

                        <!-- Lifecycle 3 -->
                        <div class="col-lg-4 col-md-12">
                            <div class="p-3 bg-secondary rounded-xl border border-border h-100 d-flex flex-column justify-content-between">
                                <div>
                                    <div class="d-flex align-items-center gap-2 mb-2">
                                        <span class="badge bg-info text-white rounded-circle p-1 px-2">3</span>
                                        <h6 class="fw-bold mb-0"><?= is_rtl() ? 'دورة الرواتب الشهرية (HR)' : 'Monthly Payroll Cycle' ?></h6>
                                    </div>
                                    <p class="small text-muted-foreground mb-3"><?= is_rtl() ? 'مراجعة سجلات الحضور والإجازات، تفعيل محرك احتساب الرواتب والبدلات والخصومات، تدقيق المسير المالي، وطباعة قسائم الرواتب.' : 'Staff attendance review, leave approvals, allowance/deduction compute, payroll approval, and printable payslip ledger.' ?></p>
                                </div>
                                <span class="badge bg-card text-foreground border border-border small text-center py-1.5"><?= is_rtl() ? 'تكرار: نهاية كل شهر' : 'Cadence: Monthly' ?></span>
                            </div>
                        </div>
                    </div>

                    <h5 class="fw-bold mt-4 mb-3 text-foreground"><i class="fa-solid fa-list-check text-primary me-2"></i><?= is_rtl() ? 'دليل الاستخدام والتشغيل التفصيلي خطوة بخطوة لكل وحدة ووظيفة' : 'Detailed Step-by-Step Operating Guide per Function & Module' ?></h5>

                    <!-- Module A: Administration -->
                    <div class="border border-border rounded-xl p-3.5 mb-3 bg-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-primary text-white"><i class="fa-solid fa-school-flag me-1"></i> <?= is_rtl() ? 'الوحدة الإدارية المتكاملة' : 'Integrated Administration Center' ?></span>
                                <strong class="fs-6"><?= is_rtl() ? 'الهوية المؤسسية، الهيكل الأكاديمي، القبول، الأسر، الحضور، السلوك، الأمان، والترحيل السنوي' : 'Institutional Setup, Hierarchy, Admissions, Households, Attendance, Behavior, RBAC & Rollover' ?></strong>
                            </div>
                            <span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-folder-tree me-1 text-primary"></i> <?= is_rtl() ? 'قسم الإدارة والحوكمة' : 'Administration Domain' ?></span>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">1</div>
                            <div>
                                <strong><?= is_rtl() ? 'الهوية المؤسسية، الاعتماد، والهيكل الأكاديمي الشجري:' : 'Institutional Branding, Accreditation & Structural Academic Hierarchy:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>الإدارة &gt; إعدادات المؤسسة</strong> و <strong>الهيكل الأكاديمي والمراحل</strong>. يمكنك ضبط الاسم الرسمي، رقم السجل، ختم الاعتماد، العملة الافتراضية، ساعات العمل وأيام الدوام الأسبوعية (مثل الأحد-الخميس). في شاشة الهيكل الأكاديمي، يتم تعريف المراحل التعليمية (رياض أطفال، ابتدائي، متوسط، ثانوي) وربط الصفوف والشعب مع تحديد السعة الاستيعابية القصوى لمنع التسجيل الزائد.' 
                                    : 'Navigate to <strong>Administration &gt; Institution Settings</strong> and <strong>Academic Hierarchy</strong>. Configure master branding, accreditation seal, official registration numbers, default currency, operating hours, and standard working week. Under Academic Hierarchy, define educational stages (KG to High School), grades, and sections with intake capacity limits to prevent over-enrollment.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">2</div>
                            <div>
                                <strong><?= is_rtl() ? 'مسار القبول والتسجيل وملف الطالب الشامل (360° Dossier):' : 'Admissions Intake Pipeline & Comprehensive 360° Student Dossier:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'افتح <strong>الإدارة &gt; القبول والتسجيل</strong> لمتابعة طلبات التسجيل الجديدة عبر لوحة كانبان، التحقق من الوثائق الرسمية، ودرجات اختبار القبول، مع إمكانية القبول والتحويل الفوري بضغطة زر وتوليد رقم هوية أكاديمية موحد (مثل <code>STU-2026-XXXX</code>) مع فحص سعة الشعبة. وافتح <strong>الإدارة &gt; ملف الطالب الشامل (360°)</strong> لاستعراض السجل الشامل (الصحي، الحساسيات، الأشخاص المصرح لهم بالاستلام مع صور الهوية، ومسار الحافلة المدرسية).' 
                                    : 'Visit <strong>Administration &gt; Student Admissions</strong> to manage prospect inquiries, document verification, and placement test scores with 1-click conversion generating unique student IDs (<code>STU-YYYY-XXXX</code>) and automated section capacity enforcement. Open <strong>Administration &gt; 360° Student Dossier</strong> to review the comprehensive profile including health/allergies, authorized pickup persons with photo IDs, and school bus route mapping.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">3</div>
                            <div>
                                <strong><?= is_rtl() ? 'محرك إدارة الأسر وروابط الإخوة (Household & Sibling Engine):' : 'Parent / Guardian Mapping & Household Sibling Engine:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>الإدارة &gt; العائلات وأولياء الأمور</strong> لربط الطلاب بحسابات أولياء الأمور وتحديد صلة القرابة، المسؤول المالي الرئيسي لدفع الرسوم، جهة الاتصال الأكاديمية، والتنبيه على قيود الحضانة القانونية. يغذي هذا المحرك تلقائياً وحدة المالية لاحتساب خصومات الإخوة وإصدار فواتير العائلة الموحدة.' 
                                    : 'Open <strong>Administration &gt; Households &amp; Guardians</strong> to bind multiple students into unified households, designate primary fee payer/academic contacts, and manage legal custody restriction alerts. This relational mapping automatically feeds the Finance module for multi-child sibling discounts and consolidated family billing.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">4</div>
                            <div>
                                <strong><?= is_rtl() ? 'منصة الحضور اليومي وسجل السلوك والانضباط المدرسي:' : 'Daily Attendance Roll Call & Behavioral Conduct Registry:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>الإدارة &gt; مكتب الحضور والغياب</strong> لتسجيل الحضور السريع للشعبة بكبسة زر "تحضير الكل"، مع رصد آلي للطلاب المعرضين لخطر الحرمان من الاختبارات عند تدني نسبة الحضور عن 75%. وافتح <strong>الإدارة &gt; سجل السلوك والانضباط</strong> لتوثيق سجلات التميز والشهادات التقديرية أو المخالفات السلوكية وإجراءات التحقيق والعقوبات وإشعار أولياء الأمور.' 
                                    : 'Navigate to <strong>Administration &gt; Attendance Registry Desk</strong> for 1-click class roll calls with automated warnings for students falling below the 75% attendance threshold for exam eligibility. Open <strong>Administration &gt; Student Conduct &amp; Merit Registry</strong> to log student merits/commendations, policy infractions, corrective actions (detention, suspension), points, and parental notification records.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">5</div>
                            <div>
                                <strong><?= is_rtl() ? 'مصفوفة الصلاحيات (RBAC) وسجل التدقيق الأمني غير القابل للتعديل:' : 'Role-Based Access Control (RBAC) & Immutable Security Audit Ledger:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>الإدارة &gt; إدارة المستخدمين</strong> لإدارة حسابات المستخدمين وتعيين أدوارهم في المنظومة. وافتح <strong>الإدارة &gt; سجل التدقيق الأمني</strong> للاستعلام عن سجل العمليات الحساسة وتتبع لقطات التغييرات (Before vs After JSON snapshots) مع تسجيل عناوين IP والطوابع الزمنية الدقيقة لحماية نزاهة البيانات والدرجات.' 
                                    : 'Manage system users and access levels via <strong>Administration &gt; User Management</strong>. Open <strong>Administration &gt; Security Audit Ledger</strong> to inspect the immutable audit ledger tracking sensitive actions (logins, grade edits, fee adjustments, deletions) with complete Before/After JSON diff snapshots, user attribution, and client IP addresses.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">6</div>
                            <div>
                                <strong><?= is_rtl() ? 'الترحيل السنوي، براءة الذمة، وإصدار شهادات الانتقال (TC) والأرشيف:' : 'Academic Rollover, Clearance Gates, Transfer Certificates & Alumni Registry:' ?></strong>
                                <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                    ? 'افتح <strong>الإدارة &gt; الترحيل السنوي</strong> لترقية وترحيل دفعات الطلاب بشكل جماعي (ناجح / معيد / متخرج). ويحتوي النظام على بوابة براءة ذمة آلية تشترط تصفير الرسوم المالية قبل إصدار شهادة الانتقال الرسمية المعتمدة بختم SHA-256 ورمز الاستجابة السريعة (<strong>الإدارة &gt; شهادة الانتقال الرسمية TC</strong>)، مع نقل الخريجين تلقائياً إلى سجل الخريجين وإخلاء المقاعد الفعالة.' 
                                    : 'Visit <strong>Administration &gt; Year-End Rollover</strong> for batch cohort promotion (Promoted, Retained, Graduated) into subsequent academic years. Features automated pre-exit clearance checks (verifying zero financial balance before issuing official Transfer Certificates with SHA-256 digital seals via <strong>Administration &gt; Transfer Certificate TC</strong>) and archiving graduates into the Alumni Registry.' ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Module B: LMS -->
                    <div class="border border-border rounded-xl p-3.5 mb-3 bg-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-success text-white"><i class="fa-solid fa-chalkboard-user me-1"></i> <?= is_rtl() ? 'وحدة التعلم والفصول (LMS)' : 'LMS & Classroom Operations' ?></span>
                                <strong class="fs-6"><?= is_rtl() ? 'الجداول الأسبوعية، سجل الحضور والغياب، وإدارة الواجبات وإعادتها' : 'Timetables, Attendance Tracking & Homework Rework' ?></strong>
                            </div>
                            <span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-folder-tree me-1 text-primary"></i> <?= is_rtl() ? 'قسم التعلم وإدارة الفصول' : 'LMS & Academics Domain' ?></span>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">1</div>
                            <div>
                                <strong><?= is_rtl() ? 'بناء الجدول المدرسي الأسبوعي (Weekly Timetable Setup):' : 'Build Weekly Classroom Timetable:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>التعلم الإلكتروني &gt; الجدول الدراسي الأسبوعي</strong>. اختر الفصل الدراسي والشعبة، واستخدم أداة الإضافة السريعة لتوزيع الحصص على أيام الأسبوع (من الأحد إلى الخميس)، وتحديد المادة، المعلم المسؤول، وفترة الحصة. يتيح الجدول للطلاب والمعلمين استعراض مواعيدهم وطباعة الجدول بضغطة زر.' 
                                    : 'Navigate to <strong>LMS &gt; Class Timetable</strong>. Select grade and section. Add class sessions across weekdays (Sun-Thu) selecting subject, assigned educator, and time slot. Timetable displays in weekly grid with instant print support.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">2</div>
                            <div>
                                <strong><?= is_rtl() ? 'رصد وتثبيت الحضور والغياب اليومي (Daily Attendance Register):' : 'Take and Lock Daily Classroom Attendance:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'افتح <strong>التعلم الإلكتروني &gt; رصد الحضور والغياب</strong>. حدد التاريخ والفصل الدراسي. تظهر قائمة الطلاب مسبقاً بحالة "حاضر". انقر على أزرار التبديل لتحديد الطلاب (غائب، متأخر، معذور مع تدوين الملاحظة). اضغط "حفظ الحضور" ليتم قفل السجل واحتساب نسب الحضور تلقائياً.' 
                                    : 'Open <strong>LMS &gt; Daily Attendance Roll Call</strong>. Pick date and class. Roster defaults to "Present". Toggle individual statuses (Absent, Late, Excused with reason). Click "Save Attendance" to commit to ledger and update student metrics.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">3</div>
                            <div>
                                <strong><?= is_rtl() ? 'نشر الواجبات، استلام الحلول، وطلب إعادة المحاولة (Assignment Rework Workflow):' : 'Publish Assignments, Grade Submissions & Request Redo/Rework:' ?></strong>
                                <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                    ? 'يقوم المعلم بزيارة <strong>التعلم الإلكتروني &gt; إدارة الواجبات والتكليفات</strong>، ويحدد المادة، الفصل، العنوان، التعليمات، وتاريخ التسليم الأخير، مع إمكانية إرفاق ملف. يقوم الطالب بحل الواجب من بوابته. يستعرض المعلم تسليمات الطلاب ويمكنه وضع الدرجة أو النقر على "طلب إعادة الحل (Request Redo)" مع كتابة التوجيهات ليقوم الطالب بإعادة رفع الحل المصحح.' 
                                    : 'Teachers access <strong>LMS &gt; Assignments &amp; Grading Desk</strong>, pick course, title, instructions, and deadline. Students submit responses through portal. Teacher grades submission or triggers "Request Redo" with corrective notes, allowing student resubmission.' ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Module C: Grading -->
                    <div class="border border-border rounded-xl p-3.5 mb-3 bg-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-danger text-white"><i class="fa-solid fa-graduation-cap me-1"></i> <?= is_rtl() ? 'وحدة الامتحانات والدرجات' : 'Grading & Examinations Engine' ?></span>
                                <strong class="fs-6"><?= is_rtl() ? 'الاختبارات، رصد الدرجات، تدقيق المخالصة، وإصدار الشهادات' : 'Exams, Bulk Grading, Clearance Gating & Report Cards' ?></strong>
                            </div>
                            <span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-folder-tree me-1 text-primary"></i> <?= is_rtl() ? 'قسم الامتحانات والتقييم' : 'Grading & Assessment Domain' ?></span>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">1</div>
                            <div>
                                <strong><?= is_rtl() ? 'إنشاء دورة امتحانية جديدة (Create Exam Period):' : 'Create Examination Period & Subjects:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>الامتحانات &gt; إعداد وجدولة الاختبارات</strong>. انقر على "إضافة اختبار جديد". حدد عنوان الاختبار (اختبار شهري، نصف الفصل، الاختبار النهائي)، العام الأكاديمي، الفصل، وتاريخ الانعقاد والدرجة العظمى لكل مادة.' 
                                    : 'Visit <strong>Grading &gt; Assessment Configurator</strong>. Click "Add Exam". Set exam label (Quiz, Midterm, Final), active term, academic year, and max scoring benchmarks per subject.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">2</div>
                            <div>
                                <strong><?= is_rtl() ? 'رصد الدرجات الجماعي وحساب المعدل الفوري (Bulk Grade Entry & GPA):' : 'Bulk Grade Input & Automated GPA Calculation:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'افتح <strong>الامتحانات &gt; رصد واعتماد الدرجات</strong>. اختر الامتحان والمادة والفصل. تظهر قائمة الطلاب في جدول تفاعلي. أدخل درجات الطلاب واضغط "حفظ الدرجات". يقوم النظام تلقائياً بالتحقق من عدم تجاوز الدرجة العظمى واحتساب التقديرات (A, B, C...) والمعدل التراكمي الفوري.' 
                                    : 'Open <strong>Grading &gt; Grade Entry Desk</strong>. Select exam, subject, and classroom. Enter scores into tabular grid and submit. System auto-validates against max points, calculates letter grades, and aggregates GPAs.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">3</div>
                            <div>
                                <strong><?= is_rtl() ? 'توليد الشهادات مع فحص المخالصة المالية (Report Cards & Clearance Gating):' : 'Generate Report Cards with Financial Clearance Gating:' ?></strong>
                                <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>الامتحانات &gt; الشهادات وبطاقات الدرجات</strong>. حدد الطالب أو الفصل. يقوم محرك الشهادات تلقائياً بالتحقق من جدول <code>student_clearance</code> في المالية: إذا كان الطالب غير مسدد لرسومه، تظهر علامة "معلق مالياً" ويتم حجب الشهادة لحين السداد. عند اكتمال المخالصة، يتم توليد الشهادة الرسمية متضمنة الدرجات، المعدل، الملاحظات، والختم الرسمي مع دعم الطباعة بصيغة PDF.' 
                                    : 'Visit <strong>Grading &gt; Official Report Cards &amp; Transcripts</strong>. Pick student or entire grade. The engine queries <code>student_clearance</code>: if tuition is overdue, the card is locked with a "Financial Hold" badge. Upon clearance, formal PDF report cards with grades, GPA, and seal are rendered.' ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Module D: Finance -->
                    <div class="border border-border rounded-xl p-3.5 mb-3 bg-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-warning text-dark"><i class="fa-solid fa-vault me-1"></i> <?= is_rtl() ? 'الوحدة المالية والخزينة' : 'Finance, Vault & Billing' ?></span>
                                <strong class="fs-6"><?= is_rtl() ? 'هياكل الرسوم، خصم الأخوة، التحصيل، المخالصة، والتسوية اليومية' : 'Fee Structures, Sibling Discounts, Receipts, Clearance & Daily Settlement' ?></strong>
                            </div>
                            <span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-folder-tree me-1 text-primary"></i> <?= is_rtl() ? 'قسم العمليات المالية والخزينة' : 'Finance & Treasury Domain' ?></span>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">1</div>
                            <div>
                                <strong><?= is_rtl() ? 'بناء هياكل الرسوم الدراسية (Fee Structure Engine):' : 'Configure Annual Tuition Fee Structures:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>المالية &gt; هياكل الرسوم الدراسية</strong>. أنشئ هيكل رسوم لكل مرحلة دراسية، وحدد مبالغ الرسوم الأساسية، رسوم النقل المدرسي، ورسوم الأنشطة. يتم تطبيق الهيكل تلقائياً على جميع الطلاب المسجلين بتلك المرحلة.' 
                                    : 'Open <strong>Finance &gt; Tuition Fee Structures</strong>. Define tuition tiers per grade level detailing core tuition, transport, and activity fees. The billing engine applies these rates to enrolled students.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">2</div>
                            <div>
                                <strong><?= is_rtl() ? 'تطبيق قواعد خصومات الإخوة والتفوق (Discounts & Waivers):' : 'Apply Automated Sibling & Merit Discounts:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'انتقل إلى <strong>المالية &gt; خصومات الأخوة والعائلات</strong>. حدد سياسة خصم الإخوة (مثل 10% للابن الثاني، 20% للابن الثالث فأكثر) أو خصومات التفوق والأيتام. يقوم النظام بربط الطلاب المسجلين تحت نفس ولي الأمر وتطبيق الخصم على الفواتير تلقائياً.' 
                                    : 'Go to <strong>Finance &gt; Sibling &amp; Family Discounts</strong>. Configure sibling discount rules (e.g. 10% for 2nd child, 20% for 3rd+) and scholarships. System connects siblings via parent ID and applies automated invoice waivers.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">3</div>
                            <div>
                                <strong><?= is_rtl() ? 'تحصيل الرسوم وإصدار إيصال استلام رسمي (Fee Collection & Receipt):' : 'Process Tuition Payment & Issue Official Receipt:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'في <strong>المالية &gt; فواتير الرسوم والتحصيل</strong>، ابحث عن اسم الطالب. انقر على "تسجيل دفعة جديدة". أدخل المبلغ المدفوع، طريقة الدفع (نقدي Cash، تحويل بنكي Bank، أو بطاقة Card)، والحساب البنكي المودع به. اضغط "حفظ وطباعة الإيصال" لتوليد إيصال رسمي فوري برقم مرجعي فريد وختم الخزينة.' 
                                    : 'In <strong>Finance &gt; Student Invoicing &amp; Billing</strong>, look up student. Click "Record Payment", enter amount, method (Cash, Transfer, Card), and receiving vault account. Save and print official payment receipt with unique reference code.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">4</div>
                            <div>
                                <strong><?= is_rtl() ? 'إصدار المخالصة المالية للطالب (Financial Clearance):' : 'Grant Official Financial Clearance:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'افتح <strong>المالية &gt; براءة الذمة المالية</strong>. استعرض حالة مديونية الطلاب. انقر على "منح مخالصة مالية" بعد سداد الرسوم بالكامل لفك حظر الشهادات والنتائج الأكاديمية للطالب فوراً.' 
                                    : 'Open <strong>Finance &gt; Financial Clearance Engine</strong>. Review student balance. Click "Grant Clearance" once tuition is settled to lift academic report card release blocks.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">5</div>
                            <div>
                                <strong><?= is_rtl() ? 'التسوية اليومية للخزينة وتدقيق الصندوق (Daily Cashier Settlement):' : 'Perform Daily Cashier Settlement & Day-End Vault Reconciliation:' ?></strong>
                                <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                    ? 'في نهاية كل يوم عمل، يقوم أمين الصندوق بفتح <strong>المالية &gt; المطابقة والإغلاق اليومي للخزينة</strong>. يقوم النظام تلقائياً بتجميع كافة مبالغ التحصيل لليوم، مصنفة حسب طريقة الدفع (كاش، بنك، شيكات). يقوم أمين الصندوق بإدخال المبلغ الفعلي في الخزينة، وتأكيد المطابقة، والضغط على "إقفال اليومية وتوليد محضر التسوية".' 
                                    : 'At close of business, cashier visits <strong>Finance &gt; Daily Cashier Settlement</strong>. The system aggregates all daily receipts classified by payment method. Cashier inputs physical cash count, confirms variance (0.00), and commits day-end audit run.' ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Module E: HR -->
                    <div class="border border-border rounded-xl p-3.5 mb-3 bg-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-info text-white"><i class="fa-solid fa-users-gear me-1"></i> <?= is_rtl() ? 'وحدة الموارد البشرية والرواتب' : 'Human Resources & Payroll Ledger' ?></span>
                                <strong class="fs-6"><?= is_rtl() ? 'ملفات الموظفين، الحضور، الإجازات، مسير الرواتب، وقسائم الصرف' : 'Employee Profiles, Attendance, Leaves, Payroll Engine & Payslips' ?></strong>
                            </div>
                            <span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-folder-tree me-1 text-primary"></i> <?= is_rtl() ? 'قسم الموارد البشرية والرواتب' : 'HR & Payroll Domain' ?></span>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">1</div>
                            <div>
                                <strong><?= is_rtl() ? 'إضافة موظف جديد وحزم البدلات (Staff Onboarding):' : 'Onboard Employee & Configure Compensation Package:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'افتح <strong>الموارد البشرية &gt; تسجيل موظف جديد</strong>. أدخل بيانات الموظف الشخصية، المسمى الوظيفي، القسم، تاريخ التعيين، الراتب الأساسي، وبدلات السكن والمواصلات. يتم إنشاء الملف الوظيفي وربطه بمسير الرواتب الشهري.' 
                                    : 'Access <strong>HR &gt; Employee Onboarding</strong>. Enter employee credentials, job title, department, hire date, base wage, and allowances (housing, transit). Profile syncs with payroll ledger.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">2</div>
                            <div>
                                <strong><?= is_rtl() ? 'رصد الحضور وإدارة طلبات الإجازات (Attendance & Leaves):' : 'Record Staff Attendance & Approve Leave Requests:' ?></strong>
                                <p class="small text-muted-foreground mb-1"><?= is_rtl() 
                                    ? 'من خلال <strong>الموارد البشرية &gt; حضور وانصراف الكادر</strong> يتم تسجيل دوام الموظفين، ومن <strong>الموارد البشرية &gt; طلبات وأرصدة الإجازات</strong> يتم استعراض طلبات الإجازات (مرضية، اعتيادية، عارضة) واعتمادها لخصمها من الرصيد السنوي وضبط خصومات الراتب بدقة.' 
                                    : 'Track daily staff clock-in at <strong>HR &gt; Staff Daily Attendance</strong>. Review and approve vacation/medical leave applications in <strong>HR &gt; Staff Leave Requests</strong> to manage balances and payroll deductions.' ?></p>
                            </div>
                        </div>

                        <div class="guide-step">
                            <div class="step-circle">3</div>
                            <div>
                                <strong><?= is_rtl() ? 'توليد مسير الرواتب الشهري وطباعة قسائم الصرف (Monthly Payroll Run & Payslips):' : 'Execute Monthly Payroll Calculations & Print Employee Payslips:' ?></strong>
                                <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                    ? 'في نهاية كل شهر، افتح <strong>الموارد البشرية &gt; مسير الرواتب الشهري</strong>، اختر الشهر واضغط "توليد مسير الرواتب". يحتسب النظام تلقائياً صافي الرواتب بعد إضافة البدلات وخصم الغيابات. بعد المراجعة، اعتمد المسير وانتقل إلى <strong>الموارد البشرية &gt; سجل الرواتب وقسائم الصرف</strong> لطباعة قسائم الرواتب (Payslips) الرسمية لكل موظف.' 
                                    : 'At month end, navigate to <strong>HR &gt; Monthly Payroll Generation</strong>, select month and trigger "Generate Payroll". Engine calculates net pay adding allowances and deducting absence penalties. After audit, visit <strong>HR &gt; Master Payroll Ledger</strong> to print official employee payslips.' ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Module F: Casual Portals Privacy Shield -->
                    <div class="border border-border rounded-xl p-3.5 bg-card">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge bg-secondary text-secondary-foreground"><i class="fa-solid fa-user-shield me-1"></i> <?= is_rtl() ? 'درع خصوصية البوابات' : 'Casual Portals Privacy Shield' ?></span>
                                <strong class="fs-6"><?= is_rtl() ? 'عزل المعلومات المالية عن بوابات الطلاب والمعلمين وأولياء الأمور' : 'Strict Financial Privacy Isolation' ?></strong>
                            </div>
                            <span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-folder-tree me-1 text-primary"></i> <?= is_rtl() ? 'بوابات الوصول السريع' : 'Direct Access Portals' ?></span>
                        </div>
                        <p class="small text-muted-foreground mb-0 leading-relaxed"><?= is_rtl() 
                            ? 'حرصاً على حماية البيانات المؤسسية، يطبق النظام عزلاً صارماً للمعلومات المالية؛ حيث تم استبدال بطاقات الأرصدة المالية في بوابات المعلمين والطلاب وأولياء الأمور ببطاقات الأداء الأكاديمي والشهادات والواجبات، وتقتصر جميع كشوفات الرسوم والفواتير والخزائن على الإدارة المالية حصراً.' 
                            : 'To protect institutional data integrity, EduMaster enforces strict privacy shields: non-administrative portals (Teachers, Students, Parents) are completely stripped of financial balances, ledger queries, and invoice balances, replacing them with academic reports, timetables, and assignments.' ?></p>
                    </div>

                </div>

                <!-- SECTION 1: ARCHITECTURE & SYSTEM OVERVIEW -->
                <div id="sec-overview" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-primary-subtle text-primary">
                            <i class="fa-solid fa-layer-group"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '1. البنية الهندسية ونظرة عامة على النظام' : '1. Architecture & System Overview' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'بنية المظلة الموحدة (Umbrella Architecture)' : 'Umbrella Architecture & Service Layer' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'المفهوم العام والركائز الهندسية' : 'Core Architectural Principles' ?></h5>
                    <p class="text-muted-foreground leading-relaxed">
                        <?= is_rtl()
                            ? 'تم تصميم نظام EduMaster وفق معمارية <strong>المظلة الموحدة (Umbrella Architecture)</strong>، حيث تتشارك جميع الوحدات (الإدارة، الأكاديميا، الدرجات، المالية، والموارد البشرية) نفس محرك التهيئة والتشغيل المركزي (النواة الأساسية &gt; مشغل المنصة)، ومصفوفة الصلاحيات (الأمان والحوكمة &gt; حارس المصادقة والجلسات)، والعمود الفقري الزمني (التقويم الأكاديمي &gt; المحرك الزمني). تتيح هذه البنية مزامنة فورية خالية من التكرار عبر جميع المستويات.'
                            : 'EduMaster is engineered upon a unified <strong>Umbrella Architecture</strong> where every functional domain (Administration, LMS, Grading, Finance, HR) shares a centralized bootstrap runtime (Core Architecture &gt; Platform Bootstrap), master RBAC service (Security &amp; Governance &gt; Authentication Guard), and temporal backbone (Academic Calendar &gt; Master Temporal Engine). This eliminates data isolation and guarantees zero-latency state synchronization.' ?>
                    </p>

                    <div class="row g-3 my-3">
                        <div class="col-md-4">
                            <div class="p-3 bg-secondary rounded-lg border border-border h-100">
                                <h6 class="fw-bold text-primary mb-1"><i class="fa-solid fa-shield-halved me-1"></i> <?= is_rtl() ? 'الأمان والحوكمة المركزية' : 'Centralized RBAC' ?></h6>
                                <small class="text-muted-foreground"><?= is_rtl() ? 'حوكمة مشددة للأدوار (Admin, Teacher, Student, Parent) مع فحص الصلاحيات قبل تنفيذ أي كود.' : 'Strict multi-tier RBAC authentication enforcing permission boundaries on every request.' ?></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-secondary rounded-lg border border-border h-100">
                                <h6 class="fw-bold text-success mb-1"><i class="fa-solid fa-database me-1"></i> <?= is_rtl() ? 'ترقيات قواعد البيانات الذاتية' : 'Automated Migrations' ?></h6>
                                <small class="text-muted-foreground"><?= is_rtl() ? 'تنفيذ تلقائي وتدريجي لملفات الترحيل من خلال <code>schema_migrations</code> عند كل ترقية.' : 'Atomic, incremental schema migration engine ensuring uninterrupted table evolutions.' ?></small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 bg-secondary rounded-lg border border-border h-100">
                                <h6 class="fw-bold text-info mb-1"><i class="fa-solid fa-lock me-1"></i> <?= is_rtl() ? 'الخصوصية الصارمة (Zero-PII)' : 'Zero-PII Telemetry' ?></h6>
                                <small class="text-muted-foreground"><?= is_rtl() ? 'تنقيح تلقائي فوري لأي بيانات شخصية أو أسرار قبل تسجيلها في سجلات النظام.' : 'Multi-pass regex scrubber purging sensitive PII from errors before staging or sync.' ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SECTION 2: DASHBOARD & WIDGETS -->
                <div id="sec-dashboard" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-info-subtle text-info">
                            <i class="fa-solid fa-gauge-high"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '2. لوحة التحكم التفاعلية والأدوات الذكية' : '2. Interactive Dashboard & Sortable Widgets' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'واجهة الاستخدام التكيفية' : 'Adaptive UI & Custom Ordering' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'المكونات والميزات الرئيسية' : 'Key Layout Features & Functionalities' ?></h5>
                    <div class="d-flex flex-wrap gap-1 mb-3">
                        <span class="feature-pill"><i class="fa-solid fa-chart-pie text-primary"></i> <?= is_rtl() ? 'بطاقات المؤشرات التكيفية حسب الدور' : 'Role-Adaptive KPI Counters' ?></span>
                        <span class="feature-pill"><i class="fa-solid fa-calendar text-purple"></i> <?= is_rtl() ? 'ويدجت التقويم التفاعلي (شهر/أسبوع)' : 'Interactive Mini Calendar Widget' ?></span>
                        <span class="feature-pill"><i class="fa-solid fa-grip-vertical text-warning"></i> <?= is_rtl() ? 'ترتيب الأدوات بالسحب والإفلات (SortableJS)' : 'Drag & Drop Sortable Ordering' ?></span>
                        <span class="feature-pill"><i class="fa-solid fa-magnifying-glass text-success"></i> <?= is_rtl() ? 'البحث الفوري الشامل (Ctrl+K)' : 'Spotlight Search (Ctrl+K)' ?></span>
                    </div>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام: كيف تخصص وترتب لوحة التحكم الخاصة بك؟' : 'How to Use: Customizing Your Dashboard Workspace' ?></h5>
                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'إعادة ترتيب الأدوات بالسحب والإفلات:' : 'Reorder Widgets via Drag & Drop:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'انقر مع الاستمرار على أيقونة السحب (<i class="fa-solid fa-grip-vertical"></i>) في رأس أي بطاقة في عمود "اليوم"، ثم اسحبها إلى الموضع المطلوب.' : 'Click and hold the drag handle (<i class="fa-solid fa-grip-vertical"></i>) on any widget in the Today column and move it to your preferred position.' ?></p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'الحفظ التلقائي الفوري:' : 'Automatic Persistence:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'بمجرد إفلات البطاقة، ترسل المنصة طلباً خلفياً لحفظ ترتيبك المخصص (لوحة التحكم &gt; حفظ تفضيلات الأدوات) في قاعدة البيانات مع إشعار نجاح فوري.' : 'Upon release, SortableJS triggers an asynchronous payload to the layout preference service (Dashboard &gt; Widget Layout Preference), persisting your workspace view.' ?></p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-circle">3</div>
                        <div>
                            <strong><?= is_rtl() ? 'التنقل في التقويم التفاعلي:' : 'Navigating the Interactive Calendar:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'يمكنك التبديل بين عرض الشهر وعرض الأسبوع، وتصفح الأشهر السابقة والقادمة، والنقر على أي يوم لاستعراض تفاصيل العطلات والامتحانات.' : 'Toggle between Month and Week views, navigate forward/backward, and click on any calendar cell to view event details.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 3: ACADEMIC CALENDAR -->
                <div id="sec-academic-calendar" class="docs-card" data-category="admin">
                    <div class="module-header-box">
                        <div class="module-icon-box" style="background: rgba(147, 51, 234, 0.12); color: #9333EA;">
                            <i class="fa-solid fa-calendar-days"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '3. التقويم الأكاديمي والعمود الفقري الزمني' : '3. Academic Calendar & Temporal Engine' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'المحرك الزمني المركزي' : 'Master Schedule & Cross-Module Sync' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'الميزات والوظائف الأساسية لمحرك التقويم الأكاديمي' : 'Core Functions & Capabilities' ?></h5>
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm small">
                            <thead class="table-light">
                                <tr>
                                    <th><?= is_rtl() ? 'الدالة البرمجية' : 'PHP Method' ?></th>
                                    <th><?= is_rtl() ? 'الوصف والتأثير' : 'Description & Impact' ?></th>
                                    <th><?= is_rtl() ? 'الوحدات المتأثرة' : 'Connected Modules' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>AcademicCalendar::isSchoolDay($date)</code></td>
                                    <td><?= is_rtl() ? 'التحقق مما إذا كان التاريخ المحدد يوماً دراسياً فعلياً أو عطلة/إغلاق.' : 'Evaluates if a date is an active teaching session or restricted closure.' ?></td>
                                    <td>LMS Attendance, Teacher Logs</td>
                                </tr>
                                <tr>
                                    <td><code>AcademicCalendar::isHoliday($date)</code></td>
                                    <td><?= is_rtl() ? 'فحص العطلات الرسمية والمدرسية لمنع تسجيل الغياب وتأجيل المهام.' : 'Checks public & institutional holidays to prevent false absenteeism.' ?></td>
                                    <td>Attendance, Timetable</td>
                                </tr>
                                <tr>
                                    <td><code>AcademicCalendar::isExamPeriod($date)</code></td>
                                    <td><?= is_rtl() ? 'التحقق من نافذة الامتحانات النشطة لتفعيل بوابات رصد الدرجات.' : 'Validates active assessment windows for grade entry approval.' ?></td>
                                    <td>Grading Hub, Exams Portal</td>
                                </tr>
                                <tr>
                                    <td><code>AcademicCalendar::getActiveTerm($date)</code></td>
                                    <td><?= is_rtl() ? 'استرجاع الفصل الدراسي الفعال وحدوده الزمنية ومطابقته للعام الأكاديمي.' : 'Retrieves active semester/term boundaries matching active academic year.' ?></td>
                                    <td>Report Cards, Fees Invoicing</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام للمسؤولين: إدارة أحداث التقويم' : 'How to Use: Managing Academic Events' ?></h5>
                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'الوصول لمركز إدارة التقويم:' : 'Access Academic Calendar Desk:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'انتقل إلى <strong>الإدارة &gt; التقويم الأكاديمي</strong> أو انقر على بطاقة "التقويم الأكاديمي" في لوحة الإدارة.' : 'Navigate to <strong>Administration &gt; Academic Calendar Desk</strong> or click Academic Calendar in the Admin Command Hub.' ?></p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'إضافة حدث أو عطلة جديدة:' : 'Create a New Event or Term Boundary:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'انقر على زر "إضافة حدث أكاديمي"، وحدد نوع الحدث (بداية فصل، نهاية فصل، عطلة رسمية، إغلاق مدرسي، فترة امتحانات، أو مناسبة عامة)، وحدد تاريخ البدء والانتهاء، ثم احفظ.' : 'Click "Add Academic Event", select event classification (Term Start/End, Holiday, Closure, Exam Period, Milestone), set date boundaries, and save.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 4: OTA SYSTEM UPDATES -->
                <div id="sec-system-updates" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-primary-subtle text-primary">
                            <i class="fa-solid fa-cloud-arrow-up"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '4. بنية التحديثات السحابية والترحيل الذاتي (OTA)' : '4. OTA System Update & Migration Architecture' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'التحديث الآمن المؤتمت' : 'Zero-Downtime Automated Upgrades' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'دورة التحديث والتحقق' : 'Automated Update Lifecycle' ?></h5>
                    <p class="text-muted-foreground leading-relaxed">
                        <?= is_rtl()
                            ? 'يعمل محرك التحديثات المؤتمت (إدارة النظام &gt; محرك التحديثات والترحيل) على التحقق التلقائي من التحديثات الرسمية عبر الاتصال بقناة الإصدارات المعتمدة، ومقارنة رقم البناء المحلي مقابل أحدث إصدار متاح. عند تفويض التحديث، يقوم المحرك بتنفيذ جميع حزم الترقية وترحيل قواعد البيانات وتسجيلها ثم رفع رقم الإصدار بأمان تام.'
                            : 'The automated update engine (System Administration &gt; OTA Updates &amp; Migrations) checks official releases against the developer update channel, comparing active local build numbers against available releases. When authorized, the engine executes pending database migrations in atomic transactions and updates the system version.' ?>
                    </p>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام: كيف تقوم بتحديث النظام؟' : 'How to Use: Executing an OTA System Update' ?></h5>
                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'فحص التحديثات المتاحة:' : 'Check for Available Releases:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'انتقل إلى <strong>الإدارة &gt; تحديثات وترقيات النظام</strong> وانقر على "التحقق من وجود تحديثات الآن".' : 'Visit <strong>Administration &gt; System Updates &amp; Upgrades</strong> and click "Check for Updates Now".' ?></p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'مراجعة سجل التغييرات والتثبيت:' : 'Review Changelog & Authorize Deployment:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'استعرض الميزات والتصحيحات الجديدة، ثم انقر على "تثبيت التحديث الآن". يتابع النظام تنفيذ الترقيات وتحديث البناء دون انقطاع.' : 'Inspect the changelog, then click "Deploy Update Now". The system applies migrations and bumps the version automatically.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 5: ZERO-PII TELEMETRY -->
                <div id="sec-telemetry" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-success-subtle text-success">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '5. بروتوكول الخصوصية وسجل الموثوقية (Zero-PII Telemetry)' : '5. Privacy-First Telemetry & Zero-PII Audit' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'حماية البيانات الحساسة' : 'Strict Data Scrubber & Health Desk' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'قواعد المنقح المتعدد المراحل (Data Sanitization Rules)' : 'Multi-Pass Zero-PII Sanitizer Rules' ?></h5>
                    <p class="text-muted-foreground leading-relaxed">
                        <?= is_rtl()
                            ? 'لحماية خصوصية الطلاب والمعلمين والبيانات المالية، يعترض محرك حماية الخصوصية والموثوقية (إدارة النظام &gt; سجل القياس والخصوصية Zero-PII) جميع الأخطاء والاستثناءات وينقيها تلقائياً قبل حفظها في سجل التدقيق:'
                            : 'To safeguard user privacy and financial secrets, the privacy-first telemetry interceptor (System Administration &gt; Zero-PII Privacy &amp; Telemetry) intercepts all runtime errors and executes multi-pass regex sanitization before persisting to the audit ledger:' ?>
                    </p>

                    <div class="docs-code-block">
                        <code>// Multi-Pass Sanitization Pipeline in Telemetry::sanitize($input)<br>
$clean = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[REDACTED_EMAIL]', $input);<br>
$clean = preg_replace('/(password|token|apikey|secret|db_pass)\s*[:=]\s*[^"\'\s,]+/i', '$1=[REDACTED_SECRET]', $clean);<br>
$clean = preg_replace('/[a-zA-Z]:\\\\Users\\\\[a-zA-Z0-9_-]+/i', '[SYS_USER_DIR]', $clean);</code>
                    </div>
                </div>

                <!-- SECTION 5.1: COMMERCIAL LICENSING & ENTITLEMENTS -->
                <div id="sec-licensing" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-warning-subtle text-warning">
                            <i class="fa-solid fa-key"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '5.1 إدارة التراخيص والميزات التجارية (Licensing Engine)' : '5.1 Commercial Licensing & Feature Entitlements' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'الأصالة وحماية الحقوق' : 'Authenticity, Tiers & Key Validation' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'نظرة عامة على محرك التراخيص' : 'Licensing Engine Overview' ?></h5>
                    <p class="text-muted-foreground leading-relaxed">
                        <?= is_rtl()
                            ? 'يعتمد النظام على محرك ترخيص رسمي معتمد من <strong>Algale Tech</strong> لإدارة ميزات المنصة، صلاحية النطاق، والتحقق المشفر من مفاتيح التشغيل التجاري عبر جدول <code>system_license</code>. يضمن المحرك عمل كافة الوحدات وفق باقة الاشتراك المعتمدة وتوفير تنبيهات مسبقة قبل انتهاء فترة الصلاحية.'
                            : 'The platform integrates an enterprise licensing engine developed by <strong>Algale Tech</strong> that verifies cryptographic license keys (e.g. <code>ALGALE-XXXX-XXXX-XXXX</code>), checks domain bindings, and manages feature unlocks recorded in <code>system_license</code>. It guarantees genuine installation compliance and displays proactive renewal notices.' ?>
                    </p>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام خطوة بخطوة: تفعيل ومراجعة ترخيص النظام' : 'Step-by-Step Guide: Activating and Inspecting System License' ?></h5>
                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'فحص حالة الترخيص الحالية:' : 'Inspect License Status & Build:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'انتقل إلى <strong>الإدارة &gt; تفاصيل وبيانات الترخيص</strong> للاطلاع على نوع الترخيص (Commercial, Enterprise, Demo)، تاريخ البداية، وتاريخ الانتهاء.' : 'Navigate to <strong>Administration &gt; License &amp; Product Details</strong> to review license classification (Commercial, Enterprise, Trial), activation date, and expiration timeline.' ?></p>
                        </div>
                    </div>
                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'تفعيل مفتاح ترخيص جديد:' : 'Activate a New Commercial License Key:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() ? 'انتقل إلى <strong>الإدارة &gt; تفعيل الترخيص التجاري</strong>، وأدخل مفتاح الترخيص المعتمد المقدم من Algale Tech بصيغة <code>ALGALE-XXXX-XXXX</code>، ثم انقر على "تفعيل الترخيص". يقوم النظام بمطابقة المفتاح وتحديث السجل فوراً.' : 'Open <strong>Administration &gt; Commercial License Activation</strong>, enter the official Algale Tech license key in the <code>ALGALE-XXXX-XXXX</code> format, and click "Activate License". The engine validates the hash and unlocks full capabilities.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 6: ADMINISTRATION MODULE -->
                <div id="sec-administration" class="docs-card" data-category="admin">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-primary-subtle text-primary">
                            <i class="fa-solid fa-school-flag"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '6. وحدة الإدارة المركزية والحوكمة (Administration Module)' : '6. Central Administration & Governance' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'إعدادات المؤسسة، المستخدمين، والصلاحيات' : 'School Settings, RBAC & Student Intake' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'مصفوفة الأقسام والصفحات التابعة لوحدة الإدارة' : 'Administration Sub-modules & Architecture' ?></h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm small align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?= is_rtl() ? 'القسم الفرعي' : 'Sub-module' ?></th>
                                    <th><?= is_rtl() ? 'المسار وقسم الشاشة' : 'Navigation & Location' ?></th>
                                    <th><?= is_rtl() ? 'الصلاحيات المطلوبة' : 'Required Role' ?></th>
                                    <th><?= is_rtl() ? 'الوظيفة والدور التشغيلي' : 'Core Capabilities & Operations' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'لوحة القيادة الإدارية' : 'Admin Command Center' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; لوحة التحكم المركزية' : 'Administration &gt; Command Center' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'لوحة المؤشرات الحيوية، إجمالي المستخدمين، الفصول، المواد، والوصول السريع للأقسام.' : 'Administrative command center with real-time KPI counters, quick action cards, and system shortcuts.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إعدادات الهوية والمؤسسة' : 'Visual Branding & Settings' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; إعدادات المؤسسة والهوية' : 'Administration &gt; Institution Settings' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'تخصيص اسم المدرسة، رفع الشعار، العنوان، البريد، وتعيين العام الأكاديمي الفعال.' : 'Configure school name, upload custom logo, contact info, and switch active academic year.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إدارة المستخدمين والحسابات' : 'User Accounts Directory' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; إدارة المستخدمين والحسابات' : 'Administration &gt; User Accounts Directory' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'عرض كافة الحسابات (إداريين، معلمين، طلاب، أولياء أمور)، إعادة تعيين كلمات المرور، والتعطيل.' : 'Manage all system users, search by role or name, reset passwords, and toggle account activation.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إنشاء حساب مستخدم جديد' : 'Create User Account' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; إنشاء مستخدم جديد' : 'Administration &gt; Create User Account' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'إضافة حساب جديد وتحديد الدور الوظيفي، اسم المستخدم، البريد، وتعيين كلمة المرور الأولية.' : 'Onboard single users, assign specific system roles, configure emails, and initialize secure passwords.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'بوابة قبول وتسجيل الطلاب' : 'Student Intake & Admission' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; بوابة قبول وتسجيل الطلاب' : 'Administration &gt; Student Intake &amp; Admission' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'استمارة التسجيل الأكاديمي، تعيين رقم القيد (Admission No)، ربط ولي الأمر، وتحديد الفصل.' : 'Student intake desk: generate admission numbers, record demographics, link guardians, and assign classes.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'الملف الأكاديمي للطالب' : 'Student Dossier & Profile' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; الملف الأكاديمي للطالب (360°)' : 'Administration &gt; Student Dossier &amp; Profile' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'عرض ملف الطالب الشامل، السجل الدراسي، بيانات الاتصال، والفصل المسجل به.' : 'View individual student dossiers including enrollments, guardian details, and classroom history.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'مصفوفة الصلاحيات الدقيقة' : 'Granular RBAC Permissions' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; مصفوفة الصلاحيات (RBAC)' : 'Administration &gt; Granular Permissions Matrix' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'التحكم البرمجي الدقيق في صلاحيات كل دور وظيفي لكل ميزة في النظام وحفظها في قاعدة البيانات.' : 'Matrix editor controlling granular read/write feature flags per role stored in permissions tables.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إدارة الفصول والشعب' : 'Classes & Sections Roster' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; إدارة الفصول والشعب' : 'Administration &gt; Classes &amp; Sections Roster' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'إنشاء الفصول الدراسية والشعب، تخصيص رواد الفصول، وتحديد الطاقة الاستيعابية للطلاب.' : 'Create academic classes, define sections, assign supervising class teachers, and set seat capacity.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'المناهج والمواد الدراسية' : 'Curriculum & Subjects' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; المناهج والمواد الدراسية' : 'Administration &gt; Curriculum &amp; Subjects' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'تعريف المواد الدراسية، رموز المقررات، الدرجات القصوى ودرجات النجاح، وربطها بالمراحل.' : 'Define subjects, subject codes, passing scores, credit hours, and map them to appropriate classes.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إدارة التقويم الأكاديمي' : 'Academic Calendar Engine' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; إدارة التقويم الأكاديمي' : 'Administration &gt; Academic Calendar Desk' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'جدولة الفصول الدراسية، العطلات المدرسية، مواعيد الامتحانات، وتوليد التقويم القابل للطباعة.' : 'Define semester boundaries, school closures, holiday events, and generate printable academic calendars.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'ترحيل واستيراد البيانات' : 'Bulk Data Migration Wizard' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الإدارة &gt; ترحيل واستيراد البيانات' : 'Administration &gt; Data Migration &amp; Backups' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'تحميل قوالب CSV، الاستيراد الجماعي لبيانات الطلاب والدرجات، وتوليد النسخ الاحتياطية SQL.' : 'Bulk CSV import wizard with pre-validation, downloadable templates, and full SQL database backup export.' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام خطوة بخطوة للعمليات الإدارية' : 'Step-by-Step User Manual: Central Administration' ?></h5>
                    
                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'تخصيص هوية المدرسة والعام الأكاديمي:' : 'Configure Visual Branding & Academic Year:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الإدارة &gt; إعدادات المؤسسة والهوية</strong>. أدخل اسم المدرسة الرسمي (مثل: Algale Academy)، ارفع شعار المدرسة بصيغة PNG أو JPEG، أدخل العنوان ومعلومات الاتصال، ثم حدد العام الأكاديمي النشط (مثل: 2025/2026) وانقر على "حفظ التغييرات". يتم تطبيق الهوية فوراً على كافة الوثائق والشهادات والشاشات.' 
                                : 'Visit <strong>Administration &gt; Institution Settings</strong>. Enter official school name, upload PNG/JPEG logo, enter contact details, and set active academic year (e.g. 2025/2026). Click "Save Settings" to update headers, certificates, and invoices system-wide.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'تسجيل وقبول طالب جديد (Student Admission):' : 'Register a New Student Dossier:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الإدارة &gt; بوابة قبول وتسجيل الطلاب</strong>. أدخل اسم الطالب بالكامل، تاريخ الميلاد، رقم القيد الأكاديمي، وحدد الفصل الدراسي والشعبة. حدد ولي الأمر من القائمة أو أنشئ حساب ولي أمر جديد مرتبط. بعد الحفظ، يتم إنشاء حساب تسجيل الدخول للطالب تلقائياً وإدراجه في سجلات الحضور والدرجات.' 
                                : 'Navigate to <strong>Administration &gt; Student Intake &amp; Admission</strong>. Enter student full name, birth date, admission number, and allocate classroom section. Link an existing parent user or create a new guardian account. Upon submission, student login credentials and enrollment records are generated.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">3</div>
                        <div>
                            <strong><?= is_rtl() ? 'ضبط مصفوفة الصلاحيات والحوكمة (RBAC Matrix):' : 'Adjust Granular RBAC Permissions:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الإدارة &gt; مصفوفة الصلاحيات (RBAC)</strong>. تصفح جدول الميزات حسب الأدوار (Admin, Teacher, Student, Parent, HR, Finance). حدد أو ألغِ تحديد الصلاحيات (مثل: عرض التقارير، تعديل الدرجات، الوصول للمالية)، ثم اضغط "حفظ مصفوفة الصلاحيات" لتحديث قواعد الوصول فورياً دون إعادة تشغيل.' 
                                : 'Open <strong>Administration &gt; Granular Permissions Matrix</strong>. Review the feature-by-role matrix. Toggle checkboxes for specific privileges (e.g., View Reports, Edit Grades, Access Treasury) and click "Save Permission Matrix" to apply RBAC guards instantaneously.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">4</div>
                        <div>
                            <strong><?= is_rtl() ? 'الاستيراد والتصدير الجماعي للبيانات (CSV Import/Export):' : 'Perform Bulk CSV Data Migration & Backup:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الإدارة &gt; ترحيل واستيراد البيانات</strong>. انقر على "تحميل نموذج CSV" للحصول على التنسيق المطلوب، املأ بيانات الطلاب أو المواد في ملف Excel واحفظه بصيغة CSV UTF-8، ثم ارفع الملف عبر المعالج. يقوم النظام بفحص السجلات وتفادي التكرار. كما يمكنك النقر على "تصدير نسخة احتياطية كاملة" لتنزيل ملف SQL كامل لقاعدة البيانات.' 
                                : 'Visit <strong>Administration &gt; Data Migration &amp; Backups</strong>. Download the standard CSV template via <strong>Administration &gt; CSV Template Exporter</strong>, populate student records in UTF-8 CSV, and upload via the import wizard. Use "Export Full Database Dump" to create instant restorable SQL backups.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 7: LMS & CLASSROOM OPERATIONS -->
                <div id="sec-lms" class="docs-card" data-category="lms">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-success-subtle text-success">
                            <i class="fa-solid fa-chalkboard-user"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '7. نظام إدارة التعلم والفصول (LMS & Academic Operations)' : '7. Learning Management System (LMS) & Attendance' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'التحضير اليومي، الحضور، الواجبات، والجداول' : 'Roll Call, Daily Logs, Timetables & Portals' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'مصفوفة الأقسام والصفحات التابعة لوحدة التعلم (LMS)' : 'LMS Sub-modules & Functional Matrix' ?></h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm small align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?= is_rtl() ? 'القسم الفرعي' : 'Sub-module' ?></th>
                                    <th><?= is_rtl() ? 'المسار وقسم الشاشة' : 'Navigation & Location' ?></th>
                                    <th><?= is_rtl() ? 'الصلاحيات المطلوبة' : 'Required Role' ?></th>
                                    <th><?= is_rtl() ? 'الوظيفة والدور التشغيلي' : 'Core Capabilities & Operations' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'دليل المقررات وتخطيط المناهج الأكاديمية' : 'Course Catalog & Curriculum Mapping' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; دليل المقررات وتخطيط المنهج' : 'LMS &gt; Courses &amp; Curriculum Mapping' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-success">teacher</span></td>
                                    <td><?= is_rtl() ? 'ربط المقررات بالعام الأكاديمي والفصل والمادة، تفريع المنهج لشجرة هرمية (وحدات -> موضوعات)، تحديد أهداف التعلم، وحساب نسب الإنجاز التلقائية.' : 'Bind courses to academic year/term/grade/subject, structure hierarchical curriculum trees (Unit -> Topic), track learning objectives, and calculate real-time completion %.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'مستودع الموارد الرقمية والمواد التعليمية' : 'Digital Resource Repository' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; مستودع الموارد الرقمية' : 'LMS &gt; Digital Resource Repository' ?></span></td>
                                    <td><span class="badge bg-secondary">All Roles</span></td>
                                    <td><?= is_rtl() ? 'مشاركة شرائح العرض، أوراق العمل، الفيديوهات والروابط لكل وحدة، فحص الامتدادات وحجم الملفات (25MB)، الجدولة الزمنية، وأقفال الرؤية (معلم فقط/طلاب).' : 'Multi-format learning asset distribution (PDF, DOCX, video, links), 25MB validation, scheduled unlocks, and visibility locks (student, teacher-only, draft).' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'منتديات النقاش التفاعلية لكل درس' : 'Topic Discussion Boards' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; منتديات النقاش التفاعلية' : 'LMS &gt; Topic Discussion Boards' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-success">teacher</span>, <span class="badge bg-info">student</span></td>
                                    <td><?= is_rtl() ? 'حوارات أكاديمية هادفة لكل موضوع دراسي، أسئلة واستفسارات الأقران، تثبيت الإجابة النموذجية من قبل المعلم، وقفل المناقشة عند اكتمال الموضوع.' : 'Topic-level discussion threads for peer Q&A and academic debates with teacher moderation (pin best answer, delete replies, lock threads).' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إدارة الواجبات ومنصة التصحيح والمزامنة' : 'Assignments & CA Grading Desk' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; إدارة الواجبات والتصحيح' : 'LMS &gt; Assignments &amp; CA Grading Desk' ?></span></td>
                                    <td><span class="badge bg-success">teacher</span>, <span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'نشر التكليفات بأنواعها (رفع ملف، محرر نصي، تسليم ورقي)، تطبيق المواعيد الصارمة/المرنة، معاينة المستندات في المتصفح، والمزامنة مع درجات أعمال السنة.' : 'Assignment engine with soft/hard deadlines, in-line file preview, numeric/rubric scoring, and direct sync into Continuous Assessment (grades.ca_score).' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'لوحة تحكم المعلم المركزية' : 'Teacher Command Hub' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; مساحة عمل المعلم' : 'LMS &gt; Teacher Command Hub' ?></span></td>
                                    <td><span class="badge bg-success">teacher</span>, <span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'لوحة تحكم مخصصة للمعلم لعرض الفصول المسندة، جدول اليوم، التسليمات المعلقة، ودفتر التحضير.' : 'Teacher command desk showing allocated classes, daily timetable, pending homework submissions, and quick logs.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'رصد الحضور والغياب اليومي' : 'Daily Attendance Roll Call' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; رصد الحضور اليومي' : 'LMS &gt; Daily Attendance Roll Call' ?></span></td>
                                    <td><span class="badge bg-success">teacher</span>, <span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'تسجيل الحضور (حاضر، غائب، متأخر، معذور)، أقفال العطلات المبرمجة بالتقويم، ورسوم بيانية لنسب الحضور.' : 'Class roll call interface supporting Present/Absent/Late/Excused tags with automated calendar holiday locks.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'دفتر التحضير واليوميات التربوية' : 'Pedagogical Daily Lesson Feed' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; سجل التحضير اليومي للمعلم' : 'LMS &gt; Teacher Daily Activity Log' ?></span></td>
                                    <td><span class="badge bg-success">teacher</span>, <span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'توثيق الدروس المشروحة، الواجبات المنزلية، والملاحظات التربوية الموجهة لأولياء الأمور والطلاب.' : 'Publish lesson summaries, assign homework tasks, and log pedagogical observations visible to students and parents.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'الجدول الأسبوعي وتوزيع الحصص' : 'Interactive Timetable Matrix' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; الجدول الدراسي الأسبوعي' : 'LMS &gt; Timetables &amp; Schedules' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-success">teacher</span></td>
                                    <td><?= is_rtl() ? 'مصفوفة الحصص الأسبوعية، تخصيص المعلم والمادة والقاعة، منع التعارض، وطباعة الجداول.' : 'Dynamic grid scheduler mapping class periods, instructors, rooms with conflict prevention and print export.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'بوابة الطالب الأكاديمية والتسليم' : 'Student Learning & Submission Portal' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; بوابة الطالب التعليمية' : 'LMS &gt; Student Learning Portal' ?></span></td>
                                    <td><span class="badge bg-info">student</span></td>
                                    <td><?= is_rtl() ? 'فرز التكليفات (المرتقبة، قيد المراجعة، المصححة، المتأخرة)، تسليم الحلول الرقمية، وتتبع غرامات التأخير في المواعيد المرنة.' : 'Student desk: filter homework by workflow status, submit digital solutions, and adhere to soft/hard deadline restrictions.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'بوابة أولياء الأمور والمتابعة اليومية' : 'Parent Homework Oversight Desk' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; بوابة متابعة ولي الأمر' : 'LMS &gt; Parent Follow-up Portal' ?></span></td>
                                    <td><span class="badge bg-warning text-dark">parent</span></td>
                                    <td><?= is_rtl() ? 'التبديل بين الأبناء، مراقبة التكليفات اليومية ومواعيدها وحالة تسليمها والدرجات المحققة دون إمكانية التعديل.' : 'Parent desk: switch between linked children, monitor daily homework schedules, review submission statuses and scores in read-only mode.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'المراسلات والتواصل الداخلي' : 'Direct Messages Desk' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; الرسائل والتواصل المباشر' : 'LMS &gt; Direct Communications Desk' ?></span></td>
                                    <td><span class="badge bg-secondary">All Roles</span></td>
                                    <td><?= is_rtl() ? 'نظام المحادثات والمراسلات الآمنة بين المعلمين والإدارة وأولياء الأمور مع دعم المرفقات.' : 'Secure internal messaging system facilitating teacher-parent-admin communications with attachment support.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'لوحة الإعلانات وتنبيهات الفصول' : 'Class Stream & Announcements' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'التعلم الإلكتروني &gt; الإعلانات والتعاميم المدرسية' : 'LMS &gt; School Announcements Board' ?></span></td>
                                    <td><span class="badge bg-secondary">All Roles</span></td>
                                    <td><?= is_rtl() ? 'بث الإعلانات المدرسية، تخصيص الفصول أو المدرسة ككل، إرفاق المستندات الرسمية، وتفعيل تنبيهات الطوارئ العاجلة.' : 'Publish circulars, target specific classes or school-wide audience, attach documents, and flag urgent push alert banners.' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام خطوة بخطوة للعمليات الميدانية والتعليمية' : 'Step-by-Step User Manual: LMS & Classroom Operations' ?></h5>

                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'تخطيط المنهج وهيكلة الوحدات والدروس (Curriculum Mapping):' : 'Course Catalog & Curriculum Mapping Tree:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>التعلم الإلكتروني &gt; المقررات وتخطيط المنهج</strong>. انقر على "إضافة مقرر جديد"، اربط المقرر بالفصل الأكاديمي، المادة، المعلم، والفصل الدراسي. من القائمة الجانبية للمقررات، اختر المقرر ثم انقر على "إضافة وحدة تعليمية" لتنظيم موضوعات المنهج. داخل كل وحدة، أضف الدروس والموضوعات مع تحديد أهداف التعلم (Learning Objectives) وعدد الحصص التقديرية. عند انتهاء تدريس الموضوع، قم بتحديث حالته إلى "مكتمل" لحساب النسبة المئوية لإنجاز المنهج تلقائياً.' 
                                : 'Visit <strong>LMS &gt; Courses &amp; Curriculum Mapping</strong>. Click "New Academic Course", bind to active year, term, subject, class, and instructor. From the course tree, add Curriculum Units (Modules) and attach Topics/Lesson Plans with specific learning objectives and estimated teaching periods. Mark topics as completed to drive real-time syllabus completion %.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'مشاركة الموارد الرقمية والجدولة الزمنية (Resource Repository):' : 'Publish Digital Assets with Scheduled Release & Visibility Locks:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>التعلم الإلكتروني &gt; مستودع الموارد الرقمية</strong>. انقر على "إضافة مورد رقمي"، اختر المقرر والوحدة التابعة، وحدد نوع المورد (عرض شرائح PDF، أوراق عمل، فيديو يوتيوب، أو رابط ويب). اختر طريقة الإتاحة: "إتاحة فورية" للطلاب أو "جدولة زمنية" لفتح المورد في تاريخ ووقت محدد تلقائياً. كما يمكنك تفعيل قفل الرؤية إلى "للمعلمين فقط (دليل المعلم / نماذج الإجابة)" لمنع اطلاع الطلاب عليها.' 
                                : 'Navigate to <strong>LMS &gt; Digital Resource Repository</strong>. Click "Add Digital Resource", select course and unit, choose content format (PDF slides, worksheets, video embed, or external web link). Configure Release Scheduling (Immediate vs. Scheduled Unlock At) and set Visibility Locks (Student-Visible vs. Teacher-Only answer keys vs. Draft).' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">3</div>
                        <div>
                            <strong><?= is_rtl() ? 'إدارة التكليفات ومعاينة المستندات والمزامنة (Grading Desk & CA Sync):' : 'Assignment Engine, In-line Preview & Continuous Assessment Sync:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>التعلم الإلكتروني &gt; إدارة الواجبات والتصحيح</strong>. أنشئ تكليفاً مع تحديد نوع التسليم (رفع ملفات، محرر نصي، أو ورقي)، وحدد نوع الموعد: موعد مرن (يقبل التسليم المتأخر مع وسم التأخير) أو موعد صارم (إغلاق البوابة تماماً عند انتهاء الوقت). عند مراجعة تسليمات الطلاب، استخدم "المعاينة المباشرة" لقراءة ملفات PDF والصور داخل المتصفح، رصد الدرجات مع التوجيهات. بعد الانتهاء، انقر على "مزامنة أعمال السنة" لتحديث درجات الطالب في سجل أعمال السنة (CA 40%) في وحدة الامتحانات تلقائياً دون إعادة إدخال.' 
                                : 'In <strong>LMS &gt; Assignments &amp; Grading Desk</strong>, configure title, instructions, maximum score, submission type, and deadline policy (Soft vs. Hard cut-off). In the assessment roster, click "Preview Document" to view PDF/image files directly in the browser, record scores and qualitative feedback. Finally, click "Sync to CA Gradebook" to automatically aggregate homework scores into the Continuous Assessment pool (40%) in the Grading module.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">4</div>
                        <div>
                            <strong><?= is_rtl() ? 'إدارة منتديات النقاش والأسئلة (Moderated Discussions):' : 'Moderate Topic Discussion Boards & Peer Inquiries:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>التعلم الإلكتروني &gt; منتديات النقاش التفاعلية</strong>. يستطيع المعلمون والطلاب فتح موضوعات نقاشية حول أي درس. يتمتع المعلم بصلاحيات إشرافية كاملة: تثبيت أفضل إجابة نموذجية (Pin Best Answer)، حذف الردود المخالفة، وقفل موضوع النقاش عند استيفاء أهدافه التعليمية.' 
                                : 'Open <strong>LMS &gt; Topic Discussion Boards</strong>. Engage in structured discussion threads linked to course topics. Teachers possess full moderation controls to pin definitive answers, delete inappropriate replies, and lock completed threads.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">5</div>
                        <div>
                            <strong><?= is_rtl() ? 'رصد الحضور والغياب وجداول الحصص:' : 'Attendance Roll Call & Weekly Timetables:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'استخدم <strong>التعلم الإلكتروني &gt; رصد الحضور اليومي</strong> لتسجيل الحضور اليومي مع الحماية التلقائية ضد التسجيل في أيام العطلات، واستخدم <strong>التعلم الإلكتروني &gt; الجدول الدراسي الأسبوعي</strong> لتنظيم الجدول الأسبوعي بدون تعارض المعلمين أو القاعات.' 
                                : 'Use <strong>LMS &gt; Daily Attendance Roll Call</strong> for holiday-locked daily roll calls and <strong>LMS &gt; Timetables &amp; Schedules</strong> for conflict-free weekly period scheduling.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 8: GRADING & EXAMINATIONS -->
                <div id="sec-grading" class="docs-card" data-category="grading">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-danger-subtle text-danger">
                            <i class="fa-solid fa-graduation-cap"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '8. نظام الامتحانات ورصد الدرجات والشهادات (Grading & Transcripts)' : '8. Grading, Exams & Official Transcripts' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'التقييم الأكاديمي، كشوف الدرجات، وحجب الشهادات' : 'Gradebook, Assessment Slabs & Clearance Gating' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'مصفوفة الأقسام والصفحات التابعة لوحدة الدرجات' : 'Grading Sub-modules & Evaluation Architecture' ?></h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm small align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?= is_rtl() ? 'القسم الفرعي' : 'Sub-module' ?></th>
                                    <th><?= is_rtl() ? 'المسار وقسم الشاشة' : 'Navigation & Location' ?></th>
                                    <th><?= is_rtl() ? 'الصلاحيات المطلوبة' : 'Required Role' ?></th>
                                    <th><?= is_rtl() ? 'الوظيفة والدور التشغيلي' : 'Core Capabilities & Operations' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'لوحة قيادة التقييم الأكاديمي' : 'Grading Command Desk' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; لوحة قيادة التقييم الأكاديمي' : 'Grading &gt; Academic Assessment Command' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-danger">grading</span></td>
                                    <td><?= is_rtl() ? 'مؤشرات الأداء الأكاديمي، نسب النجاح العامة، وإحصائيات اعتماد بطاقات الدرجات وجلسات القاعات.' : 'Academic assessment overview, grade distribution charts, and report card readiness.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إعدادات سلاسل الامتحانات ومعادلات الأوزان والسلالم' : 'Exam Series, Weightage & Policy Setup' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; سلاسل الامتحانات ومعادلات الأوزان' : 'Grading &gt; Exam Policies &amp; Weightings' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-success">teacher</span></td>
                                    <td><?= is_rtl() ? 'تهيئة مواسم وسلاسل الامتحانات، صياغة معادلات احتساب الدرجة النهائية (أعمال سنة 20% + نصفي 30% + نهائي 50%)، وتخصيص سلالم التقدير والـ GPA.' : 'Configure exam series windows, define component weightage formulas, customize letter grade schemas (A* through F), and GPA 4.0/5.0 quality points.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'جدولة الامتحانات وتوزيع المقاعد والمراقبة' : 'Timetabling, Seating & Hall Invigilation' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; جدولة الاختبارات وتوزيع المقاعد' : 'Grading &gt; Exam Scheduling &amp; Seating' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-success">teacher</span></td>
                                    <td><?= is_rtl() ? 'توزيع جلسات الاختبارات على القاعات والمختبرات، تخصيص أرقام الجلوس عشوائياً أو حسب القيد، تعيين المراقبين مع حظر تعارض المادة، وطباعة بطاقات الجلوس.' : 'Map exam sessions to halls, distribute seating rosters (roll/alphabetical/anti-cheating random), assign invigilators with conflict checks, and print desk tags.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'شبكة رصد الدرجات ودورة الاعتماد والمراجعة' : '2D Mark Entry Matrix & Moderation' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; شبكة رصد الدرجات والاعتماد' : 'Grading &gt; 2D Mark Entry Matrix &amp; Moderation' ?></span></td>
                                    <td><span class="badge bg-success">teacher</span>, <span class="badge bg-primary">admin</span></td>
                                    <td><?= is_rtl() ? 'رصد فوري لدرجات أوراق الامتحان وأعمال السنة، فحص القيم المسموحة لحظياً، دعم الحالات الخاصة (غياب AB، عذر ML، حرمان DQ)، والحوكمة ثلاثية المراحل (مسودة -> مراجعة القسم -> قفل واعتماد).' : 'Enter raw marks by component (CA, Paper 1, Paper 2), real-time range bounds validation, special condition codes (AB, EX, ML, DQ), and 3-tier moderation locking (Draft -> Review -> Locked).' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'محرك الحوسبة والترتيب والتحليلات الأكاديمية' : 'Performance Analytics & Cohort Rankings' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; التحليلات الأكاديمية والترتيب الصفي' : 'Grading &gt; Performance Analytics &amp; Rankings' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-success">teacher</span></td>
                                    <td><?= is_rtl() ? 'حساب المعدلات التراكمية، الانحراف المعياري، تصنيف الطلاب حسب الترتيب الصفي (Standard vs. Dense)، واكتشاف حالات الإنذار الأكاديمي ولوحة الشرف.' : 'Compute GPA distributions, cohort rankings (standard vs. dense), statistical standard deviation, honor rolls, and at-risk students.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'الشهادات وبطاقات الدرجات المؤمنة' : 'Official Report Cards & Financial Guard' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; الشهادات وبطاقات الدرجات المؤمنة' : 'Grading &gt; Official Report Cards &amp; Clearance' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-secondary">All Roles</span></td>
                                    <td><?= is_rtl() ? 'توليد كشوف الدرجات الرسمية، حجب الشهادات تلقائياً في حال وجود متأخرات مالية، وتضمين رمز QR وبصمة SHA-256 للتحقق، وضبط النشر الذاتي.' : 'Generate official term grade cards, enforce financial clearance locks for unpaid tuition, embed QR verification codes, and control digital release.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'بوابة التحقق الرقمي من صحة الشهادات' : 'Public Credential Verification Portal' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; بوابة التحقق المباشر من الشهادات' : 'Grading &gt; Online Credential Verification' ?></span></td>
                                    <td><span class="badge bg-secondary">Public / All</span></td>
                                    <td><?= is_rtl() ? 'بوابة عامة للتحقق من صحة ومطابقة الشهادات الأكاديمية عبر مسح رمز QR أو إدخال بصمة التشفير الرقمية مباشرة.' : 'Public verification portal confirming academic document authenticity against central institutional records via QR scan or hash.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'السجل الأكاديمي التراكمي الموحد' : 'Official Academic Transcript' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الامتحانات &gt; كشف الدرجات الأكاديمي الرسمي' : 'Grading &gt; Official Master Transcript' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-success">teacher</span></td>
                                    <td><?= is_rtl() ? 'إصدار السجل التراكمي للسنوات المتعددة مع ختم المؤسسة والمعدلات العامة وساعات المقررات للطباعة A4.' : 'Generate multi-year cumulative academic transcripts complete with institution seal and credit weights.' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام خطوة بخطوة لدورة الامتحانات والشهادات' : 'Step-by-Step User Manual: Grading & Certifications' ?></h5>

                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'ضبط سلاسل الامتحانات وصياغة معادلات الأوزان والسلالم:' : 'Configure Exam Series, Weightage Formulas & Grading Scales:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الامتحانات &gt; أوزان وسياسات التقييم</strong>. أنشئ سلسلة اختبارات جديدة وحدد تواريخ البداية والنهاية والرمز الكودي. في محرك معادلات الأوزان، اضبط النسب (مثلاً: 20% أعمال سنة + 30% اختبار نصفي + 50% ورقة الاختبار النهائي) بحيث يساوي المجموع 100%. كما يمكنك تخصيص سلالم التقديرات الحرفية ونقاط الـ GPA ونطاقات الأداء (Distinction, Merit, Pass, Fail).' 
                                : 'Visit <strong>Grading &gt; Exam Policies &amp; Weightings</strong>. Create an Exam Series with start/end windows and code. In the Component Weightage Engine, set formula weights (e.g., CA 20% + Midterm 30% + Final 50% = 100%). Customize letter thresholds, GPA quality points, and performance bands.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'جدولة الجلسات وتوزيع المقاعد والمراقبة بالقاعات (Seating & Invigilation):' : 'Schedule Exam Sessions, Allocate Halls & Assign Invigilators:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الامتحانات &gt; جدولة الاختبارات وتوزيع المقاعد</strong>. اختر "جدولة جلسة امتحان"، حدد المادة، الفصل، القاعة، وتاريخ ووقت الجلسة. اختر خوارزمية توزيع المقاعد (حسب رقم القيد، أبجدياً، أو عشوائياً لمنع الغش). عين رئيس القاعة؛ يقوم النظام بفحص حظر التعارض لمنع المعلم من مراقبة مادته. بعد الحفظ، يمكنك طباعة بطاقات الجلوس للمرشحين وسجل توقيعات الحضور بالقاعة.' 
                                : 'Open <strong>Grading &gt; Exam Scheduling &amp; Seating</strong>. Click "Schedule Session", pick subject, section, hall, and timeslot. Select seating algorithm (roll number, alphabetical, or randomized anti-cheating). Assign chief invigilator with automated conflict prevention rules. Export candidate desk slips and attendance registers.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">3</div>
                        <div>
                            <strong><?= is_rtl() ? 'رصد الدرجات التفصيلي والحوكمة ثلاثية المراحل (Matrix & Locking):' : 'Component-Level Mark Entry & 3-Tier Moderation Workflow:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الامتحانات &gt; شبكة رصد الدرجات والاعتماد</strong>. اختر الفصل والمادة؛ تظهر شبكة ثنائية الأبعاد (2D) لإدخال درجات أعمال السنة، ورقة 1، وورقة 2 مع فحص فوري للحدود القصوى. يدعم النظام الحالات الخاصة (غياب AB، عذر طبي ML، حرمان DQ). بعد اكتمال المسودة، يتم رفع الشيت لمراجعة رئيس القسم الأكاديمي (Department Review)، ثم اعتماد وقفل الشيت نهائياً لحماية البيانات من أي تعديل.' 
                                : 'In <strong>Grading &gt; 2D Mark Entry Matrix &amp; Moderation</strong>, select class and subject. Enter scores component-by-component with real-time range bounds validation and special condition flags (AB, EX, ML, DQ). Advance workflow from preliminary Draft to Department Review, then lock permanently against modifications.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">4</div>
                        <div>
                            <strong><?= is_rtl() ? 'احتساب الترتيب الصفي والتحليلات الأكاديمية (Rankings & Analytics):' : 'Compute Cohort Rankings & Academic Risk Analytics:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الامتحانات &gt; التحليلات الأكاديمية والترتيب الصفي</strong>. اضغط على "مزامنة الترتيب الصفي" لاحتساب مراكز الطلاب وفق خوارزمية الترتيب التنافسي أو المتتالي (Dense/Standard). استعرض المؤشرات الإحصائية العامة: المتوسط الحسابي، الانحراف المعياري، قائمة الطلاب المؤهلين للوحة الشرف، وحالات الإنذار الأكاديمي للتدخل المبكر.' 
                                : 'Visit <strong>Grading &gt; Performance Analytics &amp; Rankings</strong>. Click "Compute & Sync Class Rank" to apply standard or dense ranking. Analyze statistical metrics: cohort mean average, standard deviation, honor roll cadets, and students at academic risk.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">5</div>
                        <div>
                            <strong><?= is_rtl() ? 'توليد الشهادات المؤمنة وفحص براءة الذمة (Report Cards & QR Security):' : 'Secured Report Cards, Financial Clearance & QR Verification:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الامتحانات &gt; الشهادات وبطاقات الدرجات</strong>. يفحص النظام تلقائياً براءة الذمة المالية للطالب؛ إذا كان هناك رصيد غير مسدد يتم حجب الشهادة إلكترونياً مع توجيه ولي الأمر لسداد الرسوم. تتضمن الشهادات المعتمدة رمز استجابة سريعة (QR) وبصمة تشفير SHA-256، ويمكن لأي جهة التحقق الفوري من صحة الوثيقة عبر بوابة <strong>الامتحانات &gt; التحقق من الشهادات</strong>.' 
                                : 'In <strong>Grading &gt; Official Report Cards &amp; Transcripts</strong>, the system queries financial clearance; students with overdue tuition are blocked with an accounts notice. Official report cards feature encrypted QR codes and SHA-256 verification hashes validated in real time via <strong>Grading &gt; Credential Verification</strong>.' ?></p>
                        </div>
                    </div>
                </div>
                </div>

                <!-- SECTION 9: FINANCE & VAULT -->
                <div id="sec-finance" class="docs-card" data-category="finance">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-warning-subtle text-warning">
                            <i class="fa-solid fa-vault"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '9. المالية والخزينة وهيكل الرسوم والمطابقة (Finance & Treasury)' : '9. Finance, Fee Structures, Clearance & Settlement Vault' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'الفوترة، خصومات الأخوة، براءة الذمة، والمطابقة اليومية' : 'Fee Structures, Invoicing, Discounts, Clearance & Daily Settlement' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'مصفوفة الأقسام والصفحات التابعة للوحدة المالية' : 'Finance Sub-modules & Operational Architecture' ?></h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm small align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?= is_rtl() ? 'القسم الفرعي' : 'Sub-module' ?></th>
                                    <th><?= is_rtl() ? 'المسار وقسم الشاشة' : 'Navigation & Location' ?></th>
                                    <th><?= is_rtl() ? 'الصلاحيات المطلوبة' : 'Required Role' ?></th>
                                    <th><?= is_rtl() ? 'الوظيفة والدور التشغيلي' : 'Core Capabilities & Operations' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'مركز العمليات والحسابات البنكية' : 'Bank Accounts & Operations Hub' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; مركز العمليات والحسابات البنكية' : 'Finance &gt; Bank Accounts &amp; Operations Hub' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'المظلة المركزية لإدارة الحسابات البنكية، قنوات السيولة، ومتابعة الأرصدة المتوفرة وإجمالي التحصيلات.' : 'Central umbrella hub managing registered bank accounts, cash vaults, total balances, and operational cards.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'محرك هياكل الرسوم الدراسية' : 'Fee Structures Engine' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; محرك هياكل الرسوم الدراسية' : 'Finance &gt; Fee Structures Engine' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'تهيئة الرسوم الدراسية، رسوم النقل والأنشطة والمعامل، وتخصيص مصفوفات التسعير للفصول.' : 'Configure base tuition rates, bus transit fees, lab fees, and class schedules for billing.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'فواتير الرسوم والتحصيل' : 'Tuition Invoices & Billing' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; فواتير الرسوم والتحصيل' : 'Finance &gt; Tuition Invoices &amp; Billing' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'إصدار الفواتير الدراسية للطلاب، تتبع حالات السداد (مسدد، جزئي، غير مسدد)، وحساب المستحقات.' : 'Issue tuition invoices, track payment statuses, filter student dues, and monitor collection progress.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'خصومات الأخوة والعائلات' : 'Sibling & Multi-Child Discounts' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; خصومات الأخوة والعائلات' : 'Finance &gt; Sibling &amp; Multi-Child Discounts' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'تهيئة مجموعات الخصم العائلي وتطبيق نسب الخصم المئوية للأسر متعددي الأبناء (الطفل الثاني 10%، الثالث 20%).' : 'Manage family discount groups and automate percentage tuition deductions for multi-child households.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'محرك براءة الذمة والاعتماد' : 'Financial Clearance Engine' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; محرك براءة الذمة والاعتماد' : 'Finance &gt; Financial Clearance Engine' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'التحقق الآلي من سداد الرسوم قبل حجب أو إفراج بطاقات الدرجات، مع إمكانية منح اعتماد وتجاوز استثنائي مبرر.' : 'Automated validation of student dues before releasing report cards with authorized administrative overrides.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'المطابقة والإغلاق اليومي للخزينة' : 'Daily Cashier Settlement' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; المطابقة والإغلاق اليومي للخزينة' : 'Finance &gt; Daily Cashier Settlement' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'تدقيق المقبوضات النقدية والمصرفية اليومية، مطابقة أجهزة نقاط البيع POS، وإقفال يومية الصندوق وتوليد القيود.' : 'Audit daily collections, reconcile cash drawer/POS receipts against operational expenses, and record closed ledger snapshots.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'كشف حساب الطالب التفصيلي' : 'Student Account Statement' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; كشف حساب الطالب التفصيلي' : 'Finance &gt; Student Account Statement' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'كشف حساب مالي رسمي يوضح الفواتير الصادرة، الإيصالات المسددة، والذمم المتبقية.' : 'Comprehensive financial statement tracking invoiced, settled, and outstanding student ledger balances.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'صرف وتسوية الرواتب' : 'Payroll Disbursement' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'المالية &gt; صرف وتسوية الرواتب من الخزينة' : 'Finance &gt; Payroll Vault Disbursement' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'صرف مسيرات الرواتب المعتمدة من الموارد البشرية وخصمها من الحسابات البنكية أو الخزينة الرئيسية.' : 'Disburse approved HR payroll runs, select disbursement bank channel, and post payout entries.' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام خطوة بخطوة للعمليات المالية' : 'Step-by-Step User Manual: Financial Operations' ?></h5>

                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'تهيئة بنود وهياكل الرسوم الدراسية (Fee Structures):' : 'Create Fee Structures & Rate Matrix:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>المالية &gt; هياكل الرسوم الدراسية</strong>. انقر على "إضافة هيكل رسوم جديد"، أدخل مسمى الهيكل (مثال: رسوم المرحلة الثانوية)، حدد العام الأكاديمي، حدد الفصل الدراسي، ثم أدخل بنود التسعير (الرسوم الأساسية، رسوم النقل المدرسي، ورسوم الأنشطة والمعامل)، وحدد الفصول المطبقة عليها. يقوم النظام باحتساب الإجمالي وتثبيت الهيكل للفوترة.' 
                                : 'Navigate to <strong>Finance &gt; Tuition Fee Structures</strong>. Click "Add Fee Structure", enter title (e.g. High School Tuition), pick academic year and term, enter itemized breakdown (tuition, bus, lab fees), and bind to target classes.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'تهيئة خصومات الأخوة والأسر (Sibling Discounts):' : 'Configure Sibling & Family Discount Groups:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>المالية &gt; خصومات الأخوة والعائلات</strong>. انقر على "إنشاء مجموعة عائلية جديدة"، أدخل اسم العائلة (مثل: أسرة أحمد)، ثم حدد نسب الخصم المئوية حسب ترتيب الأبناء (مثلاً: الابن الأول 0%، الابن الثاني 10%، الابن الثالث 20%). أضف الأبناء إلى المجموعة. يقوم المحرك تلقائياً بخصم النسب المقررة من فواتير الرسوم السنوية.' 
                                : 'Open <strong>Finance &gt; Sibling &amp; Multi-Child Discounts</strong>. Click "Create Family Group", assign family name, configure discount percentage brackets per child tier (e.g. 1st child 0%, 2nd 10%, 3rd 20%), and add linked siblings to automate fee relief.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">3</div>
                        <div>
                            <strong><?= is_rtl() ? 'تدقيق براءة الذمة المالية لحجب/إفراج الشهادات (Financial Clearance):' : 'Audit Financial Clearance & Gated Release:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>المالية &gt; براءة الذمة والاعتماد</strong>. حدد الفصل الدراسي، يفحص المحرك فواتير جميع الطلاب؛ الطلاب المسددين بالكامل ينالون علامة <code>Cleared / معتمد</code> وتُتاح شهاداتهم فورياً. الطلاب ذوو المتأخرات ينالون علامة <code>Hold / حجب مالي</code>. في الحالات الإنسانية أو الاتفاقيات الخاصة، يمكن للمسؤول المالي النقر على "منح اعتماد استثنائي (Override)" مع كتابة السبب الإداري لفك الحجب.' 
                                : 'Visit <strong>Finance &gt; Financial Clearance Engine</strong>. Select classroom; the engine audits invoice balances. Paid students receive <code>Cleared</code> status allowing report card printout. Overdue students receive <code>Hold</code>. Authorized finance officers can issue administrative overrides with justification.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">4</div>
                        <div>
                            <strong><?= is_rtl() ? 'تنفيذ المطابقة والإغلاق اليومي للخزينة (Daily Cashier Settlement):' : 'Perform Day-End Cashier Settlement & Ledger Audit:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'في نهاية اليوم المالي، ينتقل أمين الصندوق إلى <strong>المالية &gt; المطابقة والإغلاق اليومي للخزينة</strong>. يقوم النظام تلقائياً بتجميع كافة المقبوضات المسجلة عبر النقد والبنك وأجهزة POS. يُدخل أمين الصندوق النقد الفعلي الموجود في الدرج، والمصروفات النثرية المنصرفة بإيصالات. يطابق النظام الفروقات، ويقوم بتوليد قيد إقفال معتمد برقم تسوية موثق.' 
                                : 'At the end of daily trading, cashiers visit <strong>Finance &gt; Daily Cashier Settlement</strong>. The system aggregates all cash, card, and bank receipts. Cashiers verify physical drawer cash and operational payouts, resolve variance, and lock the daily settlement snapshot.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 10: HR & PAYROLL -->
                <div id="sec-hr" class="docs-card" data-category="hr">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-info-subtle text-info">
                            <i class="fa-solid fa-users-gear"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '10. الموارد البشرية وشؤون الموظفين والرواتب (HR & Payroll)' : '10. Human Resources & Staff Payroll' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'دليل الموظفين، الإجازات، مسير الرواتب، وقسائم الصرف' : 'Staff Directory, Leaves & Master Payroll Ledger' ?></span>
                        </div>
                    </div>

                    <h5 class="fw-bold mb-2"><?= is_rtl() ? 'مصفوفة الأقسام والصفحات التابعة للموارد البشرية' : 'HR Sub-modules & Functional Architecture' ?></h5>
                    <div class="table-responsive mb-4">
                        <table class="table table-bordered table-sm small align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th><?= is_rtl() ? 'القسم الفرعي' : 'Sub-module' ?></th>
                                    <th><?= is_rtl() ? 'المسار وقسم الشاشة' : 'Navigation & Location' ?></th>
                                    <th><?= is_rtl() ? 'الصلاحيات المطلوبة' : 'Required Role' ?></th>
                                    <th><?= is_rtl() ? 'الوظيفة والدور التشغيلي' : 'Core Capabilities & Operations' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'مركز عمليات الموارد البشرية' : 'HR Command Center' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; مركز عمليات الموارد البشرية' : 'HR &gt; HR Command Center' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'مؤشرات الكادر الوظيفي، نسب المعلمين النشطين، طلبات الإجازات المعلقة، وإحصائيات الرواتب.' : 'HR operational desk with staff headcounts, active teaching faculty counts, and pending leave requests.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'دليل الموظفين والسجلات الوظيفية' : 'Employee Directory' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; دليل الموظفين والسجلات الوظيفية' : 'HR &gt; Employee Directory &amp; Records' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'دليل شامل لجميع العاملين، الأقسام، المسميات الوظيفية، المؤهلات، والرواتب الأساسية.' : 'Staff registry tracking job titles, departments, hiring dates, basic salaries, and bank accounts.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'الأقسام والهيكل الإداري' : 'Departments & Structure' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; الأقسام والهيكل الإداري' : 'HR &gt; Departments &amp; Structure' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'إدارة الهيكل التنظيمي، تعيين رؤساء الأقسام (HOD)، ربط الموظفين بالأقسام، وتخصيص الميزانيات التشغيلية.' : 'Organizational structure management, Head of Department assignments, staff mapping, and departmental budget tracking.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'سجل حضور وانصراف الكادر' : 'Staff Daily Attendance' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; سجل حضور وانصراف الكادر' : 'HR &gt; Staff Daily Attendance' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'تسجيل دوام الموظفين اليومي، أوقات الحضور والانصراف، حساب دقائق التأخير، والتحضير الجماعي بنقرة واحدة.' : 'Daily employee timesheets, check-in/out logging, minutes late tracking, and one-click bulk attendance marking.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'تقييم الأداء والكفاءة الوظيفية' : 'Staff Performance Appraisals' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; تقييم الأداء والكفاءة الوظيفية' : 'HR &gt; Staff Appraisals &amp; Evaluations' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'تقييم أداء المعلمين والإداريين حسب 4 محاور (جودة التدريس، الانضباط، إدارة الصف، العمل الجماعي) واحتساب التقدير العام.' : 'Multi-metric employee appraisal rubrics measuring teaching quality, punctuality, classroom management, and teamwork.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'السلف والقروض المالية للموظفين' : 'Salary Advances & Loans' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; السلف والقروض المالية للموظفين' : 'HR &gt; Salary Advances &amp; Loans' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span>, <span class="badge bg-warning text-dark">finance</span></td>
                                    <td><?= is_rtl() ? 'إدارة طلبات السلف الطارئة، جدولة خطط السداد الشهرية، تتبع الأرصدة المتبقية، والخصم التلقائي من مسير الرواتب.' : 'Emergency loan requests, installment schedules, outstanding balance ledger, and automated payroll deductions.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'تسجيل موظف جديد' : 'Add Employee Onboarding' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; تسجيل موظف جديد' : 'HR &gt; Add Employee Onboarding' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'إدخال بيانات الموظف، الرقم الوطني، الراتب، البدلات التعاقدية، وتعيين القسم الوظيفي.' : 'Onboarding form for registering employee contracts, basic salary, allowances, and department mappings.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'إدارة الإجازات والأرصدة' : 'Staff Leave Tracking & Approvals' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; إدارة الإجازات والأرصدة' : 'HR &gt; Staff Leave Tracking &amp; Approvals' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'تقديم طلبات الإجازات (سنوية، مرضية، طارئة)، دورة الاعتماد، وخصم الأيام من الرصيد.' : 'Leave management workflow handling employee applications, HR approvals, and balance deductions.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'محرك توليد مسير الرواتب' : 'Payroll Generation Engine' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; محرك توليد مسير الرواتب' : 'HR &gt; Monthly Payroll Generation' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'حساب رواتب الشهر (الأساسي + البدلات - الخصومات وأقساط السلف = صافي الراتب) واعتماد المسير.' : 'Monthly payroll calculator computing Basic + Allowances - Deductions & Advance Installments = Net Salary.' ?></td>
                                </tr>
                                <tr>
                                    <td><strong><?= is_rtl() ? 'سجل الرواتب وقسائم الصرف' : 'Master Payroll Ledger & Payslips' ?></strong></td>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-compass me-1 text-primary"></i> <?= is_rtl() ? 'الموارد البشرية &gt; سجل الرواتب وقسائم الصرف' : 'HR &gt; Master Payroll Ledger &amp; Payslips' ?></span></td>
                                    <td><span class="badge bg-primary">admin</span>, <span class="badge bg-info">hr</span></td>
                                    <td><?= is_rtl() ? 'السجل المالي الشامل للرواتب، تدقيق التحويلات، وتوليد قسائم الراتب الفردية الرسمية (Payslips).' : 'Master salary audit ledger generating individualized printable employee payslips with institution seals.' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <h5 class="fw-bold mt-4 mb-2"><?= is_rtl() ? 'دليل الاستخدام خطوة بخطوة لشؤون الموظفين والرواتب' : 'Step-by-Step User Manual: HR & Payroll' ?></h5>

                    <div class="guide-step">
                        <div class="step-circle">1</div>
                        <div>
                            <strong><?= is_rtl() ? 'تسجيل موظف أو معلم جديد (Employee Onboarding):' : 'Onboard a New Staff Member or Teacher:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الموارد البشرية &gt; تسجيل موظف جديد</strong>. أدخل الاسم الكامل، الرقم الوطني أو الإقامة، المسمى الوظيفي (معلم، إداري، محاسب)، القسم التابع له، الراتب الأساسي التعاقدي، البدلات الثابتة (سكن، نقل)، وبيانات الحساب البنكي، ثم احفظ السجل. يصبح الموظف مسجلاً في دليل الموظفين ومؤهلاً للجدولة ومسير الرواتب.' 
                                : 'Navigate to <strong>HR &gt; Add Employee Onboarding</strong>. Input personal details, identification number, designation (Teacher, Admin, Accountant), department, basic salary, allowances, and bank information, then submit.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">2</div>
                        <div>
                            <strong><?= is_rtl() ? 'إدارة الأقسام وتعيين رؤساء الأقسام والميزانيات (Departments & Structure):' : 'Manage Departments, HODs & Operational Budgets:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الموارد البشرية &gt; الأقسام والهيكل الإداري</strong>. انقر على "إضافة قسم جديد"، أدخل اسم القسم (مثل: قسم العلوم)، كود القسم (SCI)، اختر رئيس القسم (HOD)، وميزانية القسم التشغيلية السنوية. يمكنك مراجعة كادر كل قسم ونسب توزيع الموظفين.' 
                                : 'Open <strong>HR &gt; Departments &amp; Structure</strong>. Click "Add Department", input department title, code, assign Head of Department (HOD), and set operating budget.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">3</div>
                        <div>
                            <strong><?= is_rtl() ? 'رصد دوام وحضور الكادر اليومي (Staff Daily Attendance):' : 'Record Staff Daily Attendance & Punctuality:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الموارد البشرية &gt; سجل حضور وانصراف الكادر</strong>. اختر تاريخ اليوم والقسم، انقر على "تحضير الكل كحاضرين" لتسجيل سريع، أو عدل حالة كل موظف على حدة (حاضر، متأخر، غائب، إجازة) مع تسجيل وقت الحضور ودقائق التأخير. يتم احتساب نسب الانضباط تلقائياً.' 
                                : 'Visit <strong>HR &gt; Staff Daily Attendance</strong>. Select date and department. Use "Mark All Present" for quick batch logging or individually adjust statuses (Present, Late, Absent, Leave) with check-in timestamps and late minutes.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">4</div>
                        <div>
                            <strong><?= is_rtl() ? 'إجراء تقييم الأداء والكفاءة (Staff Appraisals & KPIs):' : 'Conduct Staff Performance Appraisals:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الموارد البشرية &gt; تقييم الأداء والكفاءة الوظيفية</strong>. انقر على "إجراء تقييم جديد"، حدد الموظف وفترة التقييم، ثم قيّم المحاور الأربعة من 1 إلى 5: (جودة التدريس والعمل، الانضباط والحضور، إدارة الفصل/المهام، العمل الجماعي). يحسب النظام النسبة المئوية العامة تلقائياً مع تسجيل التوصيات الإدارية (ترقية، تثبيت، تدريب).' 
                                : 'In <strong>HR &gt; Staff Performance Appraisals</strong>, click "New Evaluation". Grade the employee across 4 core KPIs (1 to 5 scale). The system calculates overall percentage score and records official recommendations.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">5</div>
                        <div>
                            <strong><?= is_rtl() ? 'إدارة السلف والقروض وخصمها من الرواتب (Salary Advances & Deductions):' : 'Manage Salary Advances & Automated Payroll Deductions:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الموارد البشرية &gt; السلف والقروض المالية</strong>. انقر على "طلب سلفة جديدة"، حدد الموظف، المبلغ، وعدد أشهر السداد (1، 3، 6، 12 شهر). يحسب النظام القسط الشهري تلقائياً. بعد موافقة الإدارة/المالية، يتم إدراج القسط الشهري تلقائياً ضمن استقطاعات مسير الراتب (الموارد البشرية &gt; مسير الرواتب الشهري) حتى اكتمال السداد.' 
                                : 'Open <strong>HR &gt; Salary Advances &amp; Loans</strong>. Click "Request Advance", specify employee, amount, and repayment duration. Upon approval, the monthly deduction is automatically factored into payroll in <strong>HR &gt; Monthly Payroll Generation</strong>.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">6</div>
                        <div>
                            <strong><?= is_rtl() ? 'معالجة واعتماد طلبات الإجازات (Leave Approvals):' : 'Process & Approve Staff Leave Requests:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الموارد البشرية &gt; إدارة الإجازات والأرصدة</strong>. تظهر قائمة الطلبات المعلقة مع نوع الإجازة وتاريخ البداية والنهاية ورصيد الموظف المتبقي. انقر على "اعتماد (Approve)" أو "رفض (Reject)" مع ذكر الملاحظات. عند الاعتماد، يقوم المحرك تلقائياً بخصم الأيام وتفادي احتسابها كغياب غير مبرر في مسير الراتب.' 
                                : 'Open <strong>HR &gt; Staff Leave Tracking &amp; Approvals</strong>. Review pending requests with leave types, dates, and remaining balance. Click "Approve" or "Reject". Approved leaves automatically prevent absence salary deductions.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">7</div>
                        <div>
                            <strong><?= is_rtl() ? 'توليد مسير الرواتب الشهري (Monthly Payroll Processing):' : 'Generate Monthly Payroll Calculations:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'في نهاية كل شهر، انتقل إلى <strong>الموارد البشرية &gt; محرك توليد مسير الرواتب</strong>. حدد الشهر والسنة، واضغط على "توليد مسير الرواتب". يقوم المحرك تلقائياً بجلب الراتب الأساسي لكل موظف، إضافة البدلات، وتطبيق الخصومات (أقساط السلف النشطة، والتأخيرات غير المعتمدة)، واحتساب صافي الراتب المستحق. يمكنك مراجعة المبالغ وتعديل المكافآت ثم النقر على "اعتماد وإرسال للمالية".' 
                                : 'At month-end, visit <strong>HR &gt; Monthly Payroll Generation Engine</strong>. Pick month and year, and trigger "Generate Payroll". The engine aggregates base salaries, adds allowances, deducts active advance installments, and computes net payouts.' ?></p>
                        </div>
                    </div>

                    <div class="guide-step">
                        <div class="step-circle">8</div>
                        <div>
                            <strong><?= is_rtl() ? 'طباعة قسائم الرواتب ومطابقة السجل (Payslips & Ledger):' : 'Print Official Employee Payslips & Review Ledger:' ?></strong>
                            <p class="small text-muted-foreground mb-0"><?= is_rtl() 
                                ? 'انتقل إلى <strong>الموارد البشرية &gt; سجل الرواتب وقسائم الصرف</strong>. استعرض السجل المالي التراكمي لجميع الأشهر. انقر على أيقونة "طباعة قسيمة الراتب (Payslip)" بجانب أي موظف لتوليد قسيمة صرف رسمية تتضمن تفصيل الراتب، البدلات، الاستقطاعات، الصافي، وختم المؤسسة.' 
                                : 'Open <strong>HR &gt; Master Payroll Ledger &amp; Payslips</strong>. Browse audited salary runs. Click the "Print Payslip" button on any employee entry to generate formal payslips detailing allowances, deductions, and net pay.' ?></p>
                        </div>
                    </div>
                </div>

                <!-- SECTION 11: PORTALS & ROUTING -->
                <div id="sec-portals" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-secondary text-secondary-foreground">
                            <i class="fa-solid fa-network-wired"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '11. بوابات الوصول السريع وحماية الخصوصية (Portals Hub & Privacy Guard)' : '11. Portals & Global Routing Hub' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'عزل الصلاحيات وحماية البيانات المالية' : 'Role-Based Routing & Privacy Isolation' ?></span>
                        </div>
                    </div>

                    <p class="text-muted-foreground leading-relaxed">
                        <?= is_rtl()
                            ? 'توفر <strong>بوابات الوصول السريع والمباشر (Portals)</strong> واجهات وصول مخصصة تعمل كموجهات ذكية تعيد توجيه المستخدمين تلقائياً إلى لوحاتهم المناسبة مع الحفاظ على تجربة استخدام سهلة وسلسة. كما يطبق النظام مبدأ <strong>العزل التام للمعلومات المالية في بوابات المستخدمين العاديين (Casual Portals)</strong>، حيث تم حجب وحذف أي أرقام فواتير أو موازين مالية عن المعلمين والطلاب وأولياء الأمور لتقتصر الحسابات على الإدارة المالية حصراً.'
                            : 'The <strong>Direct Access Portals</strong> provide unified entry points that route users to the appropriate module dashboards while preserving clean, intuitive navigation patterns across the system. Furthermore, the platform strictly enforces a <strong>Casual Portals Privacy Shield</strong>, hiding and stripping sensitive financial balances and revenue counters from non-administrative views (Teachers, Students, Parents).' ?>
                    </p>
                </div>

                <!-- SECTION 12: API & SCHEMAS -->
                <div id="sec-api-reference" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-primary-subtle text-primary">
                            <i class="fa-solid fa-code"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '12. مرجع الـ API ونماذج قواعد البيانات' : '12. API Reference & Database Schema Specs' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'خدمات النظام والتكامل' : 'System Services & Integration' ?></span>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-sm small">
                            <thead class="table-light">
                                <tr>
                                    <th><?= is_rtl() ? 'الخدمة التابعة وقسم النظام' : 'Connected Service & Navigation' ?></th>
                                    <th><?= is_rtl() ? 'طريقة الطلب' : 'Method' ?></th>
                                    <th><?= is_rtl() ? 'الوظيفة' : 'Purpose' ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-server me-1 text-primary"></i> <?= is_rtl() ? 'لوحة التحكم &gt; حفظ تفضيلات الأدوات' : 'Dashboard &gt; Widget Layout Preferences' ?></span></td>
                                    <td><span class="badge bg-primary">POST</span></td>
                                    <td><?= is_rtl() ? 'حفظ ترتيب الأدوات التفاعلية في لوحة التحكم.' : 'Persists drag-and-drop dashboard widget arrangement.' ?></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-server me-1 text-primary"></i> <?= is_rtl() ? 'إدارة النظام &gt; مزامنة التحديثات السحابية' : 'System Administration &gt; Cloud Updates Sync' ?></span></td>
                                    <td><span class="badge bg-success">GET</span></td>
                                    <td><?= is_rtl() ? 'تزويد النظام بأحدث بيان للتحديثات السحابية والترقيات.' : 'Supplies latest OTA release manifest and changelogs.' ?></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-server me-1 text-primary"></i> <?= is_rtl() ? 'إدارة النظام &gt; معالج الترقية والترحيل' : 'System Administration &gt; Upgrade &amp; Migration Processor' ?></span></td>
                                    <td><span class="badge bg-primary">POST</span></td>
                                    <td><?= is_rtl() ? 'تنفيذ الترقية التلقائية وتشغيل ملفات الترحيل.' : 'Executes automated patch deployment and migrations.' ?></td>
                                </tr>
                                <tr>
                                    <td><span class="badge bg-secondary text-secondary-foreground border border-border"><i class="fa-solid fa-server me-1 text-primary"></i> <?= is_rtl() ? 'إدارة النظام &gt; موجه سجل القياس والتشخيص' : 'System Administration &gt; Diagnostic Telemetry Dispatcher' ?></span></td>
                                    <td><span class="badge bg-primary">POST</span></td>
                                    <td><?= is_rtl() ? 'إرسال حزم الأخطاء المنقحة دورياً عبر HTTPS.' : 'Batches and securely transmits scrubbed telemetry records.' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- SECTION 13: KEYBOARD SHORTCUTS -->
                <div id="sec-shortcuts" class="docs-card" data-category="core">
                    <div class="module-header-box">
                        <div class="module-icon-box bg-secondary text-foreground">
                            <i class="fa-solid fa-keyboard"></i>
                        </div>
                        <div>
                            <h4 class="font-heading fw-bold mb-1"><?= is_rtl() ? '13. اختصارات لوحة المفاتيح والإنتاجية' : '13. Keyboard Shortcuts & Quick Productivity' ?></h4>
                            <span class="topic-badge"><?= is_rtl() ? 'السرعة والإنتاجية' : 'Productivity Boosters' ?></span>
                        </div>
                    </div>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="d-flex align-items-center justify-content-between p-3 bg-secondary rounded-lg border border-border">
                                <span class="small font-medium"><?= is_rtl() ? 'البحث الشامل الفوري' : 'Global Search Spotlight' ?></span>
                                <kbd class="px-2 py-1 bg-card text-foreground border border-border rounded shadow-xs font-monospace">Ctrl + K / ⌘ + K</kbd>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center justify-content-between p-3 bg-secondary rounded-lg border border-border">
                                <span class="small font-medium"><?= is_rtl() ? 'تبديل المظهر (ليلي/نهاري)' : 'Toggle Theme Mode' ?></span>
                                <kbd class="px-2 py-1 bg-card text-foreground border border-border rounded shadow-xs font-monospace">Theme Pill</kbd>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-flex align-items-center justify-content-between p-3 bg-secondary rounded-lg border border-border">
                                <span class="small font-medium"><?= is_rtl() ? 'ترتيب بطاقات اليوم' : 'Reorder Dashboard' ?></span>
                                <kbd class="px-2 py-1 bg-card text-foreground border border-border rounded shadow-xs font-monospace">Drag Handle</kbd>
                            </div>
                        </div>
                    </div>
                </div>

            </main>
        </div>

        <?= render_platform_footer() ?>
    </div>

    <!-- Live Client-Side Documentation Search & Filter Script -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('docsSearchInput');
            const docsCards = document.querySelectorAll('.docs-card');
            const navLinks = document.querySelectorAll('.docs-nav-link');
            const filterBtns = document.querySelectorAll('.filter-btn');

            // =========================================================================
            // Dynamic Resizable Sidebar Engine (Adjustable Width & Persistence)
            // =========================================================================
            const sidebarCol = document.getElementById('docsSidebarCol');
            const resizer = document.getElementById('docsResizer');
            const resetBtn = document.getElementById('sidebarResetBtn');

            const DEFAULT_WIDTH = 290;
            const MIN_WIDTH = 220;
            const MAX_WIDTH = 600;

            // Load saved width from localStorage
            const savedWidth = parseInt(localStorage.getItem('docs_sidebar_width'), 10);
            if (savedWidth && savedWidth >= MIN_WIDTH && savedWidth <= MAX_WIDTH && sidebarCol) {
                sidebarCol.style.width = savedWidth + 'px';
            }

            if (resizer && sidebarCol) {
                let isDragging = false;
                let startX = 0;
                let startWidth = 0;

                function onMouseDown(e) {
                    isDragging = true;
                    startX = e.clientX;
                    startWidth = sidebarCol.getBoundingClientRect().width;
                    resizer.classList.add('is-dragging');
                    document.body.classList.add('is-resizing');
                    e.preventDefault();
                }

                function onMouseMove(e) {
                    if (!isDragging) return;
                    // Sidebar is docked to the left, so moving right increases width
                    const diff = e.clientX - startX;
                    let newWidth = startWidth + diff;

                    if (newWidth < MIN_WIDTH) newWidth = MIN_WIDTH;
                    if (newWidth > MAX_WIDTH) newWidth = MAX_WIDTH;

                    sidebarCol.style.width = newWidth + 'px';
                }

                function onMouseUp() {
                    if (!isDragging) return;
                    isDragging = false;
                    resizer.classList.remove('is-dragging');
                    document.body.classList.remove('is-resizing');

                    const finalWidth = Math.round(sidebarCol.getBoundingClientRect().width);
                    localStorage.setItem('docs_sidebar_width', finalWidth);
                }

                resizer.addEventListener('mousedown', onMouseDown);
                window.addEventListener('mousemove', onMouseMove);
                window.addEventListener('mouseup', onMouseUp);

                // Touch support
                resizer.addEventListener('touchstart', function(e) {
                    if (e.touches.length === 1) {
                        isDragging = true;
                        startX = e.touches[0].clientX;
                        startWidth = sidebarCol.getBoundingClientRect().width;
                        resizer.classList.add('is-dragging');
                        document.body.classList.add('is-resizing');
                    }
                }, { passive: false });

                window.addEventListener('touchmove', function(e) {
                    if (!isDragging || e.touches.length !== 1) return;
                    const diff = e.touches[0].clientX - startX;
                    let newWidth = startWidth + diff;

                    if (newWidth < MIN_WIDTH) newWidth = MIN_WIDTH;
                    if (newWidth > MAX_WIDTH) newWidth = MAX_WIDTH;

                    sidebarCol.style.width = newWidth + 'px';
                }, { passive: false });

                window.addEventListener('touchend', function() {
                    if (!isDragging) return;
                    isDragging = false;
                    resizer.classList.remove('is-dragging');
                    document.body.classList.remove('is-resizing');
                    const finalWidth = Math.round(sidebarCol.getBoundingClientRect().width);
                    localStorage.setItem('docs_sidebar_width', finalWidth);
                });

                // Double click resizer or click reset button to reset width
                function resetSidebarWidth() {
                    sidebarCol.style.width = DEFAULT_WIDTH + 'px';
                    localStorage.setItem('docs_sidebar_width', DEFAULT_WIDTH);
                }

                resizer.addEventListener('dblclick', resetSidebarWidth);
                if (resetBtn) {
                    resetBtn.addEventListener('click', resetSidebarWidth);
                }
            }

            let currentCategory = 'all';

            function filterDocumentation() {
                const query = searchInput ? searchInput.value.trim().toLowerCase() : '';
                
                docsCards.forEach(card => {
                    const text = card.innerText.toLowerCase();
                    const cardCat = card.getAttribute('data-category');
                    
                    const matchesCategory = (currentCategory === 'all' || cardCat === currentCategory);
                    const matchesQuery = (!query || text.includes(query));

                    if (matchesCategory && matchesQuery) {
                        card.style.display = 'block';
                    } else {
                        card.style.display = 'none';
                    }
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', filterDocumentation);
            }

            filterBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    filterBtns.forEach(b => b.classList.remove('active', 'btn-primary'));
                    filterBtns.forEach(b => b.classList.add('btn-light'));
                    this.classList.remove('btn-light');
                    this.classList.add('active', 'btn-primary');
                    
                    currentCategory = this.getAttribute('data-filter');
                    filterDocumentation();
                });
            });

            // Smooth scrolling for sidebar navigation
            let isUserClickingNav = false;
            navLinks.forEach(link => {
                link.addEventListener('click', function(e) {
                    const targetId = this.getAttribute('href');
                    if (targetId.startsWith('#')) {
                        e.preventDefault();
                        const targetEl = document.querySelector(targetId);
                        if (targetEl) {
                            isUserClickingNav = true;
                            navLinks.forEach(l => l.classList.remove('active'));
                            this.classList.add('active');
                            targetEl.style.display = 'block';
                            targetEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                            setTimeout(() => { isUserClickingNav = false; }, 800);
                        }
                    }
                });
            });

            // =========================================================================
            // Dynamic ScrollSpy: Tracks reading position & moves sidebar highlight with scrolling
            // =========================================================================
            const docSections = [];
            navLinks.forEach(link => {
                const targetId = link.getAttribute('href');
                if (targetId && targetId.startsWith('#')) {
                    const secEl = document.querySelector(targetId);
                    if (secEl) {
                        docSections.push({ id: targetId, link: link, el: secEl });
                    }
                }
            });

            const sidebarContainer = document.querySelector('.docs-sidebar');

            function syncSidebarWithScroll() {
                if (isUserClickingNav || docSections.length === 0) return;

                const scrollPosition = window.scrollY + 140;
                let activeSection = docSections[0];

                for (let i = 0; i < docSections.length; i++) {
                    const sec = docSections[i];
                    if (sec.el.offsetTop <= scrollPosition) {
                        activeSection = sec;
                    } else {
                        break;
                    }
                }

                if (activeSection && !activeSection.link.classList.contains('active')) {
                    navLinks.forEach(l => l.classList.remove('active'));
                    activeSection.link.classList.add('active');

                    // Keep active item in visible bounds of sticky sidebar
                    if (sidebarContainer) {
                        const linkRect = activeSection.link.getBoundingClientRect();
                        const sidebarRect = sidebarContainer.getBoundingClientRect();
                        if (linkRect.top < sidebarRect.top + 20 || linkRect.bottom > sidebarRect.bottom - 20) {
                            activeSection.link.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                        }
                    }
                }
            }

            window.addEventListener('scroll', syncSidebarWithScroll, { passive: true });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
