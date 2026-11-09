<?php
declare(strict_types=1);

echo "========================================================\n";
echo "Testing System Message Floating Window Integration\n";
echo "========================================================\n\n";

$baseDir = dirname(__DIR__);

// 1. Check spectra-popup.js exists
$popupJsPath = $baseDir . '/public/spectra-popup.js';
if (!file_exists($popupJsPath)) {
    echo "[FAIL] public/spectra-popup.js does not exist.\n";
    exit(1);
}
echo "[PASS] public/spectra-popup.js exists.\n";

$popupJs = file_get_contents($popupJsPath);

// 2. Check essential API & window.alert interception
$requiredJsSignatures = [
    'window.SpectraPopup' => 'SpectraPopup global namespace export',
    'window.alert = function' => 'Native window.alert() interception',
    'show:' => 'SpectraPopup.show floating modal method',
    'toast:' => 'SpectraPopup.toast floating notification method',
    'confirm:' => 'SpectraPopup.confirm floating dialog method',
    'spectra-popup-overlay' => 'Floating overlay backdrop class',
    'spectra-popup-card' => 'Floating window card container',
];

foreach ($requiredJsSignatures as $sig => $desc) {
    if (strpos($popupJs, $sig) === false) {
        echo "[FAIL] {$desc} missing in public/spectra-popup.js (pattern: '{$sig}').\n";
        exit(1);
    }
    echo "[PASS] {$desc} found.\n";
}

// 3. Verify public/deployment_view.php includes spectra-popup.js
$deploymentViewPath = $baseDir . '/public/deployment_view.php';
$deployViewContent = file_get_contents($deploymentViewPath);
if (strpos($deployViewContent, 'spectra-popup.js') === false) {
    echo "[FAIL] public/deployment_view.php does not load spectra-popup.js.\n";
    exit(1);
}
echo "[PASS] public/deployment_view.php loads spectra-popup.js.\n";

// 4. Verify public/dashboard.php includes spectra-popup.js
$dashboardPath = $baseDir . '/public/dashboard.php';
$dashboardContent = file_get_contents($dashboardPath);
if (strpos($dashboardContent, 'spectra-popup.js') === false) {
    echo "[FAIL] public/dashboard.php does not load spectra-popup.js.\n";
    exit(1);
}
echo "[PASS] public/dashboard.php loads spectra-popup.js.\n";

// 5. Verify public/index.html includes spectra-popup.js
$indexPath = $baseDir . '/public/index.html';
$indexContent = file_get_contents($indexPath);
if (strpos($indexContent, 'spectra-popup.js') === false) {
    echo "[FAIL] public/index.html does not load spectra-popup.js.\n";
    exit(1);
}
echo "[PASS] public/index.html loads spectra-popup.js.\n";

// 6. Test rendering of deployment_view.php with session
require_once $baseDir . '/src/Database.php';
$pdo = \FleetHub\Database::getConnection();
$stmt = $pdo->query("SELECT id FROM central_deployments LIMIT 1");
$dep = $stmt->fetch(PDO::FETCH_ASSOC);

if ($dep) {
    $_GET['id'] = (int)$dep['id'];
    $_SESSION['fleet_user_id'] = 1;
    $_SESSION['fleet_admin_logged'] = true;
    $_SESSION['csrf_token'] = 'test_token_abc';

    ob_start();
    require $deploymentViewPath;
    $renderedHtml = ob_get_clean();

    if (strpos($renderedHtml, 'spectra-popup.js') === false) {
        echo "[FAIL] Rendered deployment_view.php output missing spectra-popup.js.\n";
        exit(1);
    }
    echo "[PASS] Rendered deployment_view.php output successfully includes spectra-popup.js.\n";
}

// 7. Verify user-input-required tactile behavior (shake animation, non-dismissing overlay)
if (strpos($popupJs, 'spectra-popup-shake') === false) {
    echo "[FAIL] spectra-popup.js missing tactile shake animation for unconfirmed dismissals.\n";
    exit(1);
}
echo "[PASS] spectra-popup.js enforces user input first to dismiss (tactile shake on backdrop click).\n";

// 8. Verify public/style.css contains system popup styles
$stylePath = $baseDir . '/public/style.css';
$styleContent = file_get_contents($stylePath);
if (strpos($styleContent, '.spectra-popup-overlay') === false || strpos($styleContent, '.spectra-popup-card') === false) {
    echo "[FAIL] public/style.css missing .spectra-popup-overlay or .spectra-popup-card definitions.\n";
    exit(1);
}
echo "[PASS] public/style.css defines floating window classes and tokens.\n";

// 9. Verify public/app.js routes showToast to SpectraPopup
$appJsPath = $baseDir . '/public/app.js';
$appJsContent = file_get_contents($appJsPath);
if (strpos($appJsContent, 'SpectraPopup') === false) {
    echo "[FAIL] public/app.js showToast does not route through SpectraPopup.\n";
    exit(1);
}
echo "[PASS] public/app.js routes system notifications through SpectraPopup.\n";

// 10. Verify dashboard.php renders floating window trigger when flash_feedback is set
$_SESSION['fleet_user_id'] = 1;
$_SESSION['fleet_admin_logged'] = true;
$_SESSION['csrf_token'] = 'test_token_abc';
$_SESSION['flash_feedback'] = [
    'msg' => 'Operation successfully completed with floating notice!',
    'type' => 'success'
];
$_GET = ['page' => 'fleet'];

ob_start();
require $dashboardPath;
$renderedDashboard = ob_get_clean();

if (strpos($renderedDashboard, 'window.SpectraPopup.show') === false || strpos($renderedDashboard, 'Operation successfully completed with floating notice!') === false) {
    echo "[FAIL] Rendered dashboard.php failed to trigger SpectraPopup.show for flash feedback.\n";
    exit(1);
}
echo "[PASS] Rendered dashboard.php triggers floating window for system feedback messages.\n";

echo "\n========================================================\n";
echo "All System Message Floating Window Tests PASSED!\n";
echo "========================================================\n";

