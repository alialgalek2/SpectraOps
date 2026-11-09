<?php
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../services/GeminiHotfixAgent.php';

$pdo = \FleetHub\Database::getConnection();
$errorId = 48; // SQLite error id

$stmt = $pdo->prepare("
    SELECT e.*, p.id AS product_id, p.product_slug, p.latest_version
    FROM central_error_logs e
    JOIN central_deployments d ON e.deployment_id = d.id
    JOIN central_products p ON d.product_id = p.id
    WHERE e.id = ?
");
$stmt->execute([$errorId]);
$err = $stmt->fetch();

if (!$err) {
    die("Error log record not found for id $errorId\n");
}

echo "Found error:\n" . json_encode($err, JSON_PRETTY_PRINT) . "\n\n";

$apiKey = getenv('GEMINI_API_KEY') ?: '';
$codebasesRoot = dirname(__DIR__) . '/storage/codebases';
$updatesOutputDir = dirname(__DIR__) . '/public/downloads/updates';
$model = getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash';

echo "Initializing GeminiHotfixAgent with model: $model\n";
$agent = new \App\Services\GeminiHotfixAgent($apiKey, $codebasesRoot, $updatesOutputDir, $model);

echo "Calling generateFix...\n";
$res = $agent->generateFix(
    $err['product_slug'],
    $err['error_message'],
    $err['file_path'],
    $err['stack_trace'],
    $err['latest_version']
);

echo "Gemini result:\n";
echo "Root Cause: " . $res['root_cause'] . "\n";
echo "Fix Summary: " . $res['fix_summary'] . "\n";
echo "New Version: " . $res['new_version'] . "\n";
echo "Fixed File: " . $res['fixed_file_path'] . "\n";
echo "Package File: " . $res['package_filename'] . "\n";
echo "Package Checksum: " . $res['package_checksum'] . "\n";
echo "Package exists: " . (file_exists($updatesOutputDir . '/' . $res['package_filename']) ? 'YES' : 'NO') . "\n";
