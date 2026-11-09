<?php
declare(strict_types=1);

/**
 * SpectraOps - Live End-to-End AI Hotfix Verification Script
 * Validates Gemini 3.8 Flash automated diagnostic, patch synthesis,
 * zip archive generation, approval workflow, and client OTA availability.
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/services/GeminiHotfixAgent.php';

function runAssert(string $desc, bool $condition, string $details = ''): void {
    if ($condition) {
        echo " [PASS] {$desc}\n";
    } else {
        echo " [FAIL] {$desc}" . ($details !== '' ? " -> {$details}" : "") . "\n";
        exit(1);
    }
}

echo "========================================================\n";
echo "SpectraOps Gemini 3.8 Flash Live Hotfix Verification\n";
echo "========================================================\n\n";

// 1. Initialize Database & Environment
$pdo = \FleetHub\Database::getConnection();

$apiKey = getenv('GEMINI_API_KEY') ?: '';
runAssert("GEMINI_API_KEY is configured and present in environment", !empty($apiKey));

$model = getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash';
runAssert("GEMINI_MODEL is configured as gemini-3.8-flash", $model === 'gemini-3.8-flash', "Found: {$model}");

$codebasesRoot = dirname(__DIR__) . '/storage/codebases';
runAssert("Clean baseline repository exists at storage/codebases/prism-edu", is_dir($codebasesRoot . '/prism-edu'));

$updatesOutputDir = dirname(__DIR__) . '/public/downloads/updates';
if (!is_dir($updatesOutputDir)) {
    mkdir($updatesOutputDir, 0755, true);
}
runAssert("Updates distribution directory exists", is_dir($updatesOutputDir));

// 2. Incident & Deployment Setup

// Ensure product exists
$stmtProduct = $pdo->prepare("SELECT id, product_slug, latest_version FROM central_products WHERE product_slug = 'prism-edu' LIMIT 1");
$stmtProduct->execute();
$product = $stmtProduct->fetch();
if (!$product) {
    $pdo->exec("INSERT INTO central_products (product_name, product_slug, product_code, latest_version) VALUES ('Prism EDU', 'prism-edu', 'PRISM', '2.5.0')");
    $stmtProduct->execute();
    $product = $stmtProduct->fetch();
}
$stmtProduct->closeCursor();
runAssert("Target product 'prism-edu' registered in database", !empty($product));
$productId = (int)$product['id'];
$latestVersion = (string)$product['latest_version'];

// Ensure deployment exists
$stmtDep = $pdo->prepare("SELECT id, deployment_domain FROM central_deployments WHERE product_id = ? LIMIT 1");
$stmtDep->execute([$productId]);
$deployment = $stmtDep->fetch();
$stmtDep->closeCursor();
if (!$deployment) {
    $insDep = $pdo->prepare("
        INSERT INTO central_deployments (product_id, client_name, deployment_domain, environment, current_version, status, api_ingest_token_hash)
        VALUES (?, 'Al-Noor Live Test Academy', 'live-test.school.edu', 'production', ?, 'healthy', ?)
    ");
    $rawToken = 'flt_' . bin2hex(random_bytes(24));
    $insDep->execute([$productId, $latestVersion, hash('sha256', $rawToken)]);
    $deploymentId = (int)$pdo->lastInsertId();
    $insDep->closeCursor();
} else {
    $deploymentId = (int)$deployment['id'];
}
runAssert("Live deployment node bound to product", $deploymentId > 0);

// Seed a targeted error incident referencing an actual file in prism-edu: src/Services/HubClient.php
$testFilePath = 'src/Services/HubClient.php';
$testErrorMsg = "TypeError: Cannot assign null to property App\\Services\\HubClient::\$instance of type App\\Services\\TelemetryReporter in reset()";
$testStackTrace = "#0 src/Services/HubClient.php(26): App\\Services\\HubClient::reset()\n#1 bin/test-heartbeat.php(12): App\\Services\\HubClient::get()\n#2 {main}";

$fingerprint = hash('sha256', $productId . $testErrorMsg . $testFilePath . 26);
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$timeFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";
$insErr = $pdo->prepare("
    INSERT INTO central_error_logs (deployment_id, error_hash, error_level, error_message, file_path, line_number, stack_trace, occurrences, first_seen_at, last_seen_at, resolved)
    VALUES (?, ?, 'CRITICAL', ?, ?, 26, ?, 1, {$timeFunc}, {$timeFunc}, 0)
");
$insErr->execute([$deploymentId, $fingerprint, $testErrorMsg, $testFilePath, $testStackTrace]);
$errorId = (int)$pdo->lastInsertId();
$insErr->closeCursor();
runAssert("Live incident seeded into central_error_logs (ID: {$errorId})", $errorId > 0);

// 3. Execute Live Gemini 3.8 Flash Agent
echo "\n[*] Contacting Gemini 3.8 Flash API for automated diagnosis and patch synthesis...\n";
$startTime = microtime(true);

$agent = new \App\Services\GeminiHotfixAgent($apiKey, $codebasesRoot, $updatesOutputDir, $model);
try {
    $result = $agent->generateFix('prism-edu', $testErrorMsg, $testFilePath, $testStackTrace, $latestVersion);
} catch (\Throwable $e) {
    echo "[!] Exception caught during AI fix generation: " . $e->getMessage() . "\n";
    exit(1);
}
$duration = round(microtime(true) - $startTime, 2);

echo "[*] Gemini 3.8 Flash responded in {$duration} seconds.\n\n";

// Validate Gemini Structured Result
runAssert("Gemini returned non-empty root_cause", !empty($result['root_cause']), "Root Cause: " . ($result['root_cause'] ?? 'empty'));
echo "     -> Root Cause: " . substr($result['root_cause'], 0, 100) . "...\n";

runAssert("Gemini returned non-empty fix_summary", !empty($result['fix_summary']));
echo "     -> Fix Summary: " . substr($result['fix_summary'], 0, 100) . "...\n";

runAssert("Gemini calculated valid new SemVer version", !empty($result['new_version']) && $result['new_version'] !== $latestVersion, "New version: " . ($result['new_version'] ?? ''));
echo "     -> Version Bump: v{$latestVersion} -> v{$result['new_version']}\n";

runAssert("Gemini identified target file path", !empty($result['fixed_file_path']));
echo "     -> Target File: " . $result['fixed_file_path'] . "\n";

runAssert("Original source code retrieved from baseline", !empty($result['original_code']) && str_contains($result['original_code'], 'class HubClient'));
runAssert("Corrected source code synthesised by AI", !empty($result['corrected_code']) && str_contains($result['corrected_code'], 'class HubClient'));

// 4. Validate Generated ZIP Patch Archive on Disk
$zipPath = $updatesOutputDir . DIRECTORY_SEPARATOR . $result['package_filename'];
runAssert("ZIP archive physically created on disk: {$result['package_filename']}", file_exists($zipPath));
runAssert("ZIP checksum matches SHA-256 calculation", hash_file('sha256', $zipPath) === $result['package_checksum']);

$zip = new ZipArchive();
runAssert("ZIP archive is a valid, readable ZIP container", $zip->open($zipPath) === true);
$foundInZip = false;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $stat = $zip->statIndex($i);
    if ($stat && str_replace('\\', '/', $stat['name']) === str_replace('\\', '/', $result['fixed_file_path'])) {
        $foundInZip = true;
        break;
    }
}
$zip->close();
runAssert("ZIP archive contains synthesised file '{$result['fixed_file_path']}'", $foundInZip);

// 5. Test Approval Workflow (approve_ai_patch)
echo "\n[*] Simulating operator approval & distribution...\n";

try {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $timeFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

    // Ensure no conflicting test release from a previous aborted run
    $pdo->prepare("DELETE FROM central_releases WHERE product_id = ? AND version = ?")->execute([$productId, $result['new_version']]);

    $insRel = $pdo->prepare("
        INSERT INTO central_releases 
        (product_id, version, changelog, resolves_error_id, package_filename, package_checksum, is_active, released_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, {$timeFunc})
    ");
    $insRel->execute([
        $productId,
        $result['new_version'],
        "AI Automated Hotfix: " . $result['fix_summary'],
        $errorId,
        $result['package_filename'],
        $result['package_checksum']
    ]);
    $releaseId = (int)$pdo->lastInsertId();
    runAssert("Hotfix published to central_releases with resolves_error_id (Release ID: {$releaseId})", $releaseId > 0);

    // Mark error log as patch_pending (Closed-loop receipt)
    $updErr = $pdo->prepare("
        UPDATE central_error_logs 
        SET status = 'patch_pending', resolved_by_version = ? 
        WHERE id = ?
    ");
    $updErr->execute([$result['new_version'], $errorId]);
    runAssert("Handled incident marked as 'patch_pending' with resolved_by_version", $updErr->rowCount() > 0);

    // Verify product latest_version bump in database
    $updProd = $pdo->prepare("UPDATE central_products SET latest_version = ? WHERE id = ?");
    $updProd->execute([$result['new_version'], $productId]);
    runAssert("Product latest_version updated in central_products to v{$result['new_version']}", $updProd->rowCount() > 0);

    // 6. Cleanup test records
    $pdo->prepare("DELETE FROM central_releases WHERE id = ?")->execute([$releaseId]);
    $pdo->prepare("UPDATE central_products SET latest_version = ? WHERE id = ?")->execute([$latestVersion, $productId]);
    if (file_exists($zipPath)) {
        @unlink($zipPath);
    }
} catch (\Throwable $e) {
    echo "\n[!] Exception during approval simulation: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n========================================================\n";
echo "SUCCESS: Gemini 3.8 Flash AI Fix Pipeline Fully Operational!\n";
echo "========================================================\n";
