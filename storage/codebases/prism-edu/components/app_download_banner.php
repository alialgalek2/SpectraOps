<?php
/**
 * Student / Staff Mobile Portal Download Card Component
 * Pre-configured self-hosted APK download banner with PRISM EDU branding.
 */

// Dynamically resolve school name from environment, session, or database settings
if (!isset($schoolName)) {
    if (!empty($_SESSION['school_name'])) {
        $schoolName = $_SESSION['school_name'];
    } elseif (isset($pdo)) {
        try {
            $stmt = $pdo->query("SELECT setting_value FROM school_settings WHERE setting_key = 'school_name' LIMIT 1");
            $schoolName = $stmt ? $stmt->fetchColumn() : null;
        } catch (\Throwable $e) {
            $schoolName = null;
        }
    }
    $schoolName = $schoolName ?: 'PRISM EDU';
}

require_once (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . '/services/ApkBuilderService.php';
$systemUrl = \App\Services\ApkBuilderService::detectSystemUrl();
$downloadUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '/school_system') . '/download_apk.php';
$isRtl = function_exists('is_rtl') && is_rtl();
?>

<div class="app-download-card" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
    <div class="download-meta">
        <div class="app-icon-wrap" aria-hidden="true">
            <img src="<?= (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '/school_system') ?>/assets/images/prism_app_icon.png" width="44" height="44" style="border-radius: 10px; object-fit: cover; box-shadow: 0 2px 8px rgba(0,0,0,0.3);" alt="Prism EDU Icon">
        </div>
        <div class="download-text">
            <h3><?= $isRtl ? 'تطبيق Prism EDU للهواتف الذكية' : 'Get the Prism EDU Mobile App' ?></h3>
            <p><?= $isRtl ? 'تطبيق مهيأ وجاهز لبوابتك التعليمية. حمّل الآن، وسجل دخولك برقمك التعريفي وابدأ التعلم.' : 'Pre-configured for your portal. Download, sign in with your student ID, and start learning.' ?></p>
            <div style="margin-top: 6px; display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; color: #38bdf8; background: rgba(56,189,248,0.1); padding: 2px 8px; border-radius: 6px; border: 1px solid rgba(56,189,248,0.2);">
                <span style="display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #38bdf8;"></span>
                <span><?= $isRtl ? 'الرابط المعتمد المضمّن:' : 'Auto-configured for:' ?> <strong><?= htmlspecialchars($systemUrl) ?></strong></span>
            </div>
        </div>
    </div>

    <div class="download-action">
        <a href="<?= htmlspecialchars($downloadUrl) ?>" class="btn-download-apk" download="PrismEDU-App.apk">
            <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
            </svg>
            <span><?= $isRtl ? 'تحميل للأندرويد (.APK)' : 'Download for Android (.APK)' ?></span>
        </a>
        <small class="install-hint"><?= $isRtl ? 'يتطلب تفعيل "التثبيت من مصادر غير معروفة" عند التثبيت لأول مرة.' : 'Requires enabling "Install from Unknown Sources" on first launch.' ?></small>
    </div>
</div>

<style>
.app-download-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #091830;
    color: #eefdff;
    padding: 24px;
    border-radius: 16px;
    border: 1px solid rgba(13, 192, 231, 0.25);
    box-shadow: 0 8px 24px rgba(0,0,0,0.25);
    margin: 20px 0;
    font-family: inherit;
    box-sizing: border-box;
}
.download-meta { 
    display: flex; 
    align-items: center; 
    gap: 16px; 
}
.app-icon-wrap { 
    background: rgba(255,255,255,0.06); 
    padding: 12px; 
    border-radius: 12px; 
    display: flex; 
    align-items: center; 
    justify-content: center;
    border: 1px solid rgba(13, 192, 231, 0.15);
}
.download-text h3 { 
    margin: 0 0 6px 0; 
    color: #bef6fd; 
    font-size: 1.15rem; 
    font-weight: 700;
}
.download-text p { 
    margin: 0; 
    color: #92c6de; 
    font-size: 0.88rem; 
    line-height: 1.4;
}
.download-action { 
    display: flex; 
    flex-direction: column; 
    align-items: flex-end; 
    gap: 6px; 
}
[dir="rtl"] .download-action {
    align-items: flex-start;
}
.btn-download-apk {
    background: #0dc0e7;
    color: #091830;
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 800;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: transform 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
    box-shadow: 0 4px 14px rgba(13, 192, 231, 0.3);
}
.btn-download-apk:hover { 
    background: #bef6fd; 
    color: #091830;
    transform: translateY(-2px); 
    box-shadow: 0 6px 20px rgba(13, 192, 231, 0.5);
}
.install-hint { 
    color: #94a3b8; 
    font-size: 0.75rem; 
}
@media (max-width: 768px) {
    .app-download-card { 
        flex-direction: column; 
        text-align: center; 
        gap: 18px; 
    }
    .download-meta { 
        flex-direction: column; 
    }
    .download-action { 
        align-items: center !important; 
    }
}
</style>
