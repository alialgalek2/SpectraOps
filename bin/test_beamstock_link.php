<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/services/GeminiHotfixAgent.php';

echo "========================================================\n";
echo "SPECTRAOPS - BEAMSTOCK CODEBASE INTEGRATION VERIFICATION\n";
echo "========================================================\n\n";

$pass = 0;
$total = 0;

function assertCheck(string $desc, bool $condition): void {
    global $pass, $total;
    $total++;
    if ($condition) {
        $pass++;
        echo " [PASS] {$desc}\n";
    } else {
        echo " [FAIL] {$desc}\n";
    }
}

// 1. Filesystem & Repositories Check
$repoDir = dirname(__DIR__) . '/repos/beamstock';
$codebaseDir = dirname(__DIR__) . '/storage/codebases/beamstock';

assertCheck("Junction exists in repos/beamstock", is_dir($repoDir));
assertCheck("Target BeamStock index.php accessible via repos/beamstock", file_exists($repoDir . '/public/index.php'));
assertCheck("Target BeamStock routes.php accessible via repos/beamstock", file_exists($repoDir . '/app/routes.php'));
assertCheck("Junction exists in storage/codebases/beamstock", is_dir($codebaseDir));
assertCheck("Baseline controller accessible via storage/codebases/beamstock", file_exists($codebaseDir . '/app/Modules/Movement/MovementController.php'));

// 2. Database Catalog Check
$pdo = \FleetHub\Database::getConnection();

$stmtProd = $pdo->prepare("SELECT * FROM central_products WHERE product_slug = 'beamstock' LIMIT 1");
$stmtProd->execute();
$prod = $stmtProd->fetch(PDO::FETCH_ASSOC);

assertCheck("Product 'beamstock' found in central_products", !empty($prod));
assertCheck("Product code is 'BEAM'", ($prod['product_code'] ?? '') === 'BEAM');
assertCheck("Product version is '2.5.0-ENTERPRISE'", ($prod['latest_version'] ?? '') === '2.5.0-ENTERPRISE');

$productId = (int)($prod['id'] ?? 0);

$stmtLic = $pdo->prepare("SELECT * FROM central_licenses WHERE product_id = ? LIMIT 1");
$stmtLic->execute([$productId]);
$lic = $stmtLic->fetch(PDO::FETCH_ASSOC);

assertCheck("License found in central_licenses", !empty($lic));
assertCheck("License status is 'active'", ($lic['status'] ?? '') === 'active');
assertCheck("License key has SPEC-BEAM prefix", str_starts_with((string)($lic['license_key'] ?? ''), 'SPEC-BEAM'));

$stmtDep = $pdo->prepare("SELECT * FROM central_deployments WHERE product_id = ? LIMIT 1");
$stmtDep->execute([$productId]);
$dep = $stmtDep->fetch(PDO::FETCH_ASSOC);

assertCheck("Deployment found in central_deployments", !empty($dep));
assertCheck("Deployment status is 'healthy'", ($dep['status'] ?? '') === 'healthy');
assertCheck("Deployment domain matches BeamStock public URL", ($dep['deployment_domain'] ?? '') === 'http://localhost:8080/BeamStock/public');

// 3. Hotfix Agent Source Code Access
$apiKey = getenv('GEMINI_API_KEY') ?: 'dummy_key';
$codebasesRoot = dirname(__DIR__) . '/storage/codebases';
$updatesOut = dirname(__DIR__) . '/public/downloads/updates';

$agent = new \App\Services\GeminiHotfixAgent($apiKey, $codebasesRoot, $updatesOut);
$targetFile = $codebasesRoot . '/beamstock/app/Modules/Movement/MovementController.php';
assertCheck("GeminiHotfixAgent can locate target BeamStock source files directly", file_exists($targetFile) && is_readable($targetFile));

// 4. Ingest Endpoint Auth Check via cURL
$token = 'spk_sec_7a9f82c410e53db8201ac495f87e2b10';
$ch = curl_init('http://localhost:8080/SPECTRAOPS/public/api/v1/updates/check.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $token,
    'Accept: application/json'
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
unset($ch);

$decoded = json_decode((string)$res, true);
assertCheck("Updates check API returns HTTP 200 with Bearer token", $httpCode === 200);
assertCheck("Updates check recognizes BeamStock deployment", is_array($decoded) && isset($decoded['update_available']));

echo "\n========================================================\n";
echo "RESULT: {$pass} / {$total} VERIFICATIONS PASSED\n";
echo "========================================================\n";

if ($pass === $total) {
    echo "🎉 BEAMSTOCK CODEBASE FULLY INTEGRATED INTO SPECTRAOPS!\n";
    exit(0);
} else {
    echo "❌ INTEGRATION INCOMPLETE.\n";
    exit(1);
}
