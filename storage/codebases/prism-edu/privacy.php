<?php
require_once __DIR__ . '/core/init.php';

$school_name = $global_branding['school_name'] ?? 'EduMaster';
$academic_year = $global_branding['academic_year'] ?? '2025/2026';
$school_logo = $global_branding['school_logo'] ?? '';
$school_email = $global_branding['contact_email'] ?? 'privacy@school.edu';
$school_phone = $global_branding['contact_phone'] ?? '+1 (555) 019-2834';
$school_address = $global_branding['school_address'] ?? 'Campus Central, Educational District';
$is_logged_in = Auth::check();
$role = $is_logged_in ? Auth::role() : 'guest';
$logo_url = get_school_logo_url($school_logo);
?>
<!DOCTYPE html>
<html <?= get_html_dir() ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= is_rtl() ? 'سياسة الخصوصية وحماية البيانات' : 'Privacy Policy & Data Protection Charter' ?> | <?= htmlspecialchars($school_name) ?></title>
    
    <!-- Early Theme Initializer to Prevent Flash -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

    <!-- Google Fonts: Outfit & Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- FontAwesome 6.4.0 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Dynamic Bootstrap RTL/LTR CSS -->
    <?= get_bootstrap_css() ?>
    
    <style>
        :root {
            --font-main: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            --font-heading: 'Outfit', sans-serif;
            --background: #f8fafc;
            --foreground: #0f172a;
            --card: #ffffff;
            --card-foreground: #0f172a;
            --primary: #4338ca;
            --primary-hover: #3730a3;
            --primary-foreground: #ffffff;
            --secondary: #f1f5f9;
            --secondary-foreground: #1e293b;
            --muted: #f8fafc;
            --muted-foreground: #64748b;
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
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .theme-toggle-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 34px;
            height: 34px;
            padding: 0;
        }
        [data-theme="dark"] .dark-icon { display: none; }
        [data-theme="dark"] .light-icon { display: inline-block !important; }
        [data-theme="light"] .light-icon { display: none; }
        [data-theme="light"] .dark-icon { display: inline-block; }

        .privacy-hero {
            background: linear-gradient(135deg, rgba(67, 56, 202, 0.12) 0%, rgba(16, 185, 129, 0.08) 100%);
            border-radius: var(--radius-xl);
            border: 1px solid var(--border);
            padding: 3rem 2rem;
            margin-bottom: 2.5rem;
            text-align: center;
        }

        .policy-card {
            background-color: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 2.25rem;
            margin-bottom: 2rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .policy-card:hover {
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        .policy-icon-badge {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .compliance-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.45rem 0.9rem;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 9999px;
            font-size: 0.825rem;
            font-weight: 600;
            color: var(--foreground);
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }

        .highlight-box {
            background: var(--secondary);
            border-inline-start: 4px solid var(--primary);
            border-radius: var(--radius-sm);
            padding: 1rem 1.25rem;
            margin: 1rem 0;
        }
    </style>
</head>
<body>

    <!-- Main Navigation Topbar -->
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
                <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill px-3 py-1.5 fw-semibold d-none d-md-inline-flex flex-shrink-0 text-truncate" style="max-width: 260px;">
                    <i class="fa-solid fa-shield-halved me-1.5"></i> <?= is_rtl() ? 'سياسة الخصوصية وحماية البيانات' : 'Privacy & Data Protection' ?>
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

                <a href="docs.php" class="btn btn-outline-secondary btn-sm rounded-pill px-2.5 d-none d-sm-inline-flex align-items-center" title="<?= is_rtl() ? 'دليل المنصة الشامل' : 'Platform Documentation' ?>">
                    <i class="fa-solid fa-book-bookmark me-1 text-primary"></i> <span class="d-none d-md-inline"><?= is_rtl() ? 'دليل النظام' : 'Docs' ?></span>
                </a>

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

    <div class="container py-4 flex-grow-1" style="max-width: 960px;">

        <!-- Hero Header -->
        <div class="privacy-hero">
            <div class="d-inline-flex align-items-center justify-content-center p-3 rounded-circle bg-primary text-white mb-3 shadow-sm" style="width: 64px; height: 64px;">
                <i class="fa-solid fa-user-shield fs-2"></i>
            </div>
            <h1 class="font-heading fw-bold text-foreground mb-2">
                <?= is_rtl() ? 'ميثاق الخصوصية وحماية البيانات التعليمية' : 'Institutional Privacy Policy & Data Charter' ?>
            </h1>
            <p class="text-muted-foreground mx-auto mb-4" style="max-width: 720px;">
                <?= is_rtl() 
                    ? 'نلتزم في ' . htmlspecialchars($school_name) . ' بأعلى معايير الخصوصية والأمان لحماية السجلات الأكاديمية للطلاب، بيانات المعلمين، والمعاملات المالية.' 
                    : 'At ' . htmlspecialchars($school_name) . ', we uphold the highest international and institutional standards to safeguard educational records, student privacy, and financial vaults.' ?>
            </p>

            <!-- Compliance Badges -->
            <div class="d-flex justify-content-center flex-wrap gap-2">
                <span class="compliance-pill"><i class="fa-solid fa-circle-check text-success"></i> <?= is_rtl() ? 'معيار حظر البيانات الشخصية (Zero-PII)' : 'Zero-PII Telemetry Standard' ?></span>
                <span class="compliance-pill"><i class="fa-solid fa-lock text-primary"></i> <?= is_rtl() ? 'تشفير كامل TLS 1.3' : 'End-to-End Encryption' ?></span>
                <span class="compliance-pill"><i class="fa-solid fa-graduation-cap text-purple" style="color: #9333EA;"></i> <?= is_rtl() ? 'امتثال السجلات التعليمية (FERPA/GDPR)' : 'FERPA & Educational Compliance' ?></span>
            </div>
        </div>

        <!-- SECTION 1: ZERO-PII TELEMETRY GUARANTEE -->
        <div class="policy-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="policy-icon-badge bg-success-subtle text-success">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <h4 class="font-heading fw-bold mb-0"><?= is_rtl() ? '1. ضمان الخصوصية التامة (Zero-PII Architecture)' : '1. Zero-PII Privacy & Telemetry Guarantee' ?></h4>
                    <small class="text-muted-foreground"><?= is_rtl() ? 'تنقيح شامل لجميع رسائل النظام والأخطاء' : 'Comprehensive Multi-Pass Data Sanitization' ?></small>
                </div>
            </div>
            <p class="text-muted-foreground leading-relaxed">
                <?= is_rtl()
                    ? 'تعتمد المنصة معياراً هندسياً صارماً لحماية البيانات (Privacy-by-Design). يتم تنقيح ومعالجة جميع الأخطاء التشغيلية والاستثناءات عبر محرك حماية الخصوصية والموثوقية (<strong>إدارة النظام &gt; سجل القياس والخصوصية Zero-PII</strong>) الذي يزيل تلقائياً وفورياً أي بيانات شخصية (PII) مثل:'
                    : 'The platform enforces a strict Privacy-by-Design architecture. All internal diagnostics processed by the Zero-PII privacy engine (<strong>System Administration &gt; Zero-PII Privacy &amp; Telemetry</strong>) undergo real-time sanitization that purges Personally Identifiable Information (PII) before local storage or transmission:' ?>
            </p>
            <div class="highlight-box">
                <ul class="mb-0 small">
                    <li><strong><?= is_rtl() ? 'البريد الإلكتروني وأرقام الهواتف:' : 'Emails & Contact Identifiers:' ?></strong> <?= is_rtl() ? 'يتم استبدالها برمز [REDACTED_EMAIL] و [REDACTED_PHONE].' : 'Redacted and replaced with secure anonymized tokens.' ?></li>
                    <li><strong><?= is_rtl() ? 'كلمات المرور والتوكنات السرية:' : 'Passwords & Security Tokens:' ?></strong> <?= is_rtl() ? 'يتم إخفاؤها وحذف قيمها تماماً من أي رسائل أو سجلات تتبع.' : 'Completely removed from stack traces, request payloads, and audit logs.' ?></li>
                    <li><strong><?= is_rtl() ? 'مسارات الخادم ونظام التشغيل:' : 'Local Filesystem & User Paths:' ?></strong> <?= is_rtl() ? 'يتم تعميم مسارات نظام التشغيل إلى [APP_ROOT] و [SYS_USER_DIR].' : 'Normalized to protect local infrastructure topology.' ?></li>
                </ul>
            </div>
        </div>

        <!-- SECTION 2: STUDENT & EDUCATIONAL RECORDS -->
        <div class="policy-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="policy-icon-badge bg-primary-subtle text-primary">
                    <i class="fa-solid fa-graduation-cap"></i>
                </div>
                <div>
                    <h4 class="font-heading fw-bold mb-0"><?= is_rtl() ? '2. سرية السجلات الأكاديمية والنتائج' : '2. Student Educational Records & Confidentiality' ?></h4>
                    <small class="text-muted-foreground"><?= is_rtl() ? 'الدرجات، الشهادات، والحضور والغياب' : 'Grades, Transcripts & Attendance Tracking' ?></small>
                </div>
            </div>
            <p class="text-muted-foreground leading-relaxed">
                <?= is_rtl()
                    ? 'تخضع جميع السجلات الأكاديمية (الدرجات، كشوف العلامات، السجلات التراكمية، وسجلات الحضور والغياب) لضوابط وصول مشددة وفق مصفوفة الصلاحيات (RBAC). لا يمكن لأي مستخدم الاطلاع على بيانات أي طالب إلا إذا كان ولي أمره المعتمد، معلمه المباشر، أو مسؤول النظام المصرح له.'
                    : 'All student educational records (gradebooks, exam scores, cumulative GPA transcripts, and daily roll calls) are strictly isolated via Role-Based Access Control (RBAC). Access is restricted exclusively to authorized guardians, direct classroom teachers, and school leadership.' ?>
            </p>
        </div>

        <!-- SECTION 3: FINANCIAL & PAYMENT VAULT SECURITY -->
        <div class="policy-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="policy-icon-badge bg-warning-subtle text-warning">
                    <i class="fa-solid fa-vault"></i>
                </div>
                <div>
                    <h4 class="font-heading fw-bold mb-0"><?= is_rtl() ? '3. أمان المعاملات المالية والخزينة' : '3. Financial Vault & Payment Ledger Security' ?></h4>
                    <small class="text-muted-foreground"><?= is_rtl() ? 'الرسوم الدراسية، سندات القبض، ومسيرات الرواتب' : 'Tuition Invoices, Receipts & Payroll Ledgers' ?></small>
                </div>
            </div>
            <p class="text-muted-foreground leading-relaxed">
                <?= is_rtl()
                    ? 'تدار العمليات المالية داخل قسم العمليات المالية والخزينة (<strong>المالية &gt; مركز العمليات والخزينة</strong>) ومسيرات رواتب الموظفين (<strong>الموارد البشرية &gt; مسير الرواتب الشهري</strong>) بسجلات مدققة مشفرة تضمن عدم مشاركة أو كشف أي بيانات بنكية أو تفاصيل رواتب مع أي طرف خارجي غير مخول.'
                    : 'Transactions processed within the Finance Vault (<strong>Finance &gt; Treasury &amp; Financial Operations</strong>) and Staff Payroll Ledger (<strong>HR &gt; Monthly Payroll Run</strong>) are recorded with tamper-evident audit logs, ensuring that bank account details, payment receipts, and salary slips remain confidential.' ?>
            </p>
        </div>

        <!-- SECTION 4: DATA RETENTION & SECURITY -->
        <div class="policy-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="policy-icon-badge bg-info-subtle text-info">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <div>
                    <h4 class="font-heading fw-bold mb-0"><?= is_rtl() ? '4. تشفير البيانات وفترات الاحتفاظ' : '4. Data Encryption, Backups & Retention' ?></h4>
                    <small class="text-muted-foreground"><?= is_rtl() ? 'التشفير أثناء النقل وفي السكون' : 'Transit Encryption & Lifecycle Governance' ?></small>
                </div>
            </div>
            <p class="text-muted-foreground leading-relaxed">
                <?= is_rtl()
                    ? 'يتم تشفير كلمات المرور باستخدام خوارزميات التجزئة القوية (<code>PASSWORD_BCRYPT</code>). وتتم حماية الاتصالات عبر بروتوكول HTTPS المشفر. كما يتم الاحتفاظ بسجلات التحديثات وترقية قواعد البيانات المؤتمتة لضمان استمرارية النظام واستعادة البيانات عند الحاجة.'
                    : 'User credentials are secure using salted <code>PASSWORD_BCRYPT</code> hashing. Communication is secured via HTTPS/TLS. Automated database update and migration registries ensure seamless disaster recovery and data integrity across releases.' ?>
            </p>
        </div>

        <!-- SECTION 5: CONTACT & DATA PROTECTION -->
        <div class="policy-card">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="policy-icon-badge bg-secondary text-foreground">
                    <i class="fa-solid fa-headset"></i>
                </div>
                <div>
                    <h4 class="font-heading fw-bold mb-0"><?= is_rtl() ? '5. مكتب حماية البيانات والتواصل' : '5. Data Protection Officer (DPO) & Inquiries' ?></h4>
                    <small class="text-muted-foreground"><?= is_rtl() ? 'حقوق الوصول والاستفسارات' : 'Rights of Access, Correction & Export' ?></small>
                </div>
            </div>
            <p class="text-muted-foreground leading-relaxed">
                <?= is_rtl()
                    ? 'يحق للطلاب وأولياء الأمور والمعلمين طلب مراجعة بياناتهم المسجلة أو تصحيحها في أي وقت عبر التواصل مع إدارة المدرسة أو مسؤول حماية البيانات:'
                    : 'Users reserve the right to review, update, or request copies of their educational and employment records by contacting the school administration or Data Protection Officer:' ?>
            </p>
            <div class="row g-3 mt-2">
                <div class="col-md-6">
                    <div class="p-3 bg-secondary rounded-lg border border-border">
                        <small class="text-muted-foreground d-block mb-1"><?= is_rtl() ? 'البريد الإلكتروني للخصوصية:' : 'Privacy Inquiries Email:' ?></small>
                        <strong class="text-foreground"><i class="fa-solid fa-envelope me-1.5 text-primary"></i><?= htmlspecialchars($school_email) ?></strong>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="p-3 bg-secondary rounded-lg border border-border">
                        <small class="text-muted-foreground d-block mb-1"><?= is_rtl() ? 'هاتف المؤسسة:' : 'Institution Phone:' ?></small>
                        <strong class="text-foreground"><i class="fa-solid fa-phone me-1.5 text-success"></i><?= htmlspecialchars($school_phone) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <?= render_platform_footer() ?>
    </div>

    <!-- Theme Switcher Logic -->
    <script>
        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
