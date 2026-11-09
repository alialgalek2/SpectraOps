<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Database.php';

$pdo = \FleetHub\Database::getConnection();

echo "========================================================\n";
echo "SPECTRAOPS - REGISTERING BEAMSTOCK CODEBASE & PRODUCT\n";
echo "========================================================\n\n";

$slug = 'beamstock';
$code = 'BEAM';
$name = 'BeamStock Enterprise WMS';
$desc = 'Industrial Warehouse Management System, Spatial Capacity HUD & High-Throughput Inventory Operations';
$version = '2.5.0-ENTERPRISE';
$repoUrl = 'c:/xampp/htdocs/BeamStock';

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$nowFunc = ($driver === 'sqlite') ? "datetime('now')" : "NOW()";

// 1. Check or Insert Product in central_products
$stmt = $pdo->prepare("SELECT id FROM central_products WHERE product_slug = ? LIMIT 1");
$stmt->execute([$slug]);
$productId = $stmt->fetchColumn();

if (!$productId) {
    $ins = $pdo->prepare("
        INSERT INTO central_products (product_slug, product_code, product_name, description, latest_version, repository_url, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, {$nowFunc}, {$nowFunc})
    ");
    $ins->execute([$slug, $code, $name, $desc, $version, $repoUrl]);
    $productId = (int)$pdo->lastInsertId();
    echo "[PASS] Registered product '{$name}' (ID: {$productId})\n";
} else {
    $productId = (int)$productId;
    $upd = $pdo->prepare("
        UPDATE central_products 
        SET product_code = ?, product_name = ?, description = ?, latest_version = ?, repository_url = ?, updated_at = {$nowFunc}
        WHERE id = ?
    ");
    $upd->execute([$code, $name, $desc, $version, $repoUrl, $productId]);
    echo "[PASS] Updated product '{$name}' (ID: {$productId})\n";
}

// 2. Check or Insert Master License in central_licenses
$licenseKey = 'SPEC-BEAM-9F2C-4A81';
$stmtLic = $pdo->prepare("SELECT id FROM central_licenses WHERE license_key = ? LIMIT 1");
$stmtLic->execute([$licenseKey]);
$licenseId = $stmtLic->fetchColumn();

if (!$licenseId) {
    $insLic = $pdo->prepare("
        INSERT INTO central_licenses (product_id, license_key, client_name, client_email, license_type, max_seats, status, created_at)
        VALUES (?, ?, 'Main Facility Warehouse 1 (Self-Hosted)', 'operations@beamstock.internal', 'enterprise', 150, 'active', {$nowFunc})
    ");
    $insLic->execute([$productId, $licenseKey]);
    $licenseId = (int)$pdo->lastInsertId();
    echo "[PASS] Issued master enterprise license '{$licenseKey}' (ID: {$licenseId})\n";
} else {
    $licenseId = (int)$licenseId;
    $updLic = $pdo->prepare("UPDATE central_licenses SET product_id = ?, status = 'active' WHERE id = ?");
    $updLic->execute([$productId, $licenseId]);
    echo "[PASS] Verified master enterprise license '{$licenseKey}' (ID: {$licenseId})\n";
}

// 3. Register Deployment in central_deployments
$instanceUuid = 'b6a82d11-4560-4e89-b883-92f701c0aa12';
$rawSecret = 'spk_sec_7a9f82c410e53db8201ac495f87e2b10';
$tokenHash = hash('sha256', $rawSecret);
$tokenPreview = substr($rawSecret, 0, 8) . '...' . substr($rawSecret, -4);
$domain = 'http://localhost:8080/BeamStock/public';

$stmtDep = $pdo->prepare("SELECT id FROM central_deployments WHERE hardware_hash = ? OR deployment_domain = ? LIMIT 1");
$stmtDep->execute([$instanceUuid, $domain]);
$deploymentId = $stmtDep->fetchColumn();

if (!$deploymentId) {
    $insDep = $pdo->prepare("
        INSERT INTO central_deployments (
            product_id, license_id, client_name, deployment_domain, hardware_hash,
            environment, api_ingest_token_hash, token_preview, current_version,
            php_version, mysql_version, os_info, status, status_reason,
            last_heartbeat_at, is_active, created_at, updated_at
        ) VALUES (
            ?, ?, 'Main Facility Warehouse 1 (Primary Deployment)', ?, ?,
            'production', ?, ?, ?,
            ?, '10.4.32-MariaDB', ?, 'healthy', 'Nominal - Active Rolling Lease',
            {$nowFunc}, 1, {$nowFunc}, {$nowFunc}
        )
    ");
    $insDep->execute([
        $productId, $licenseId, $domain, $instanceUuid,
        $tokenHash, $tokenPreview, $version,
        PHP_VERSION, PHP_OS_FAMILY
    ]);
    $deploymentId = (int)$pdo->lastInsertId();
    echo "[PASS] Registered deployment '{$domain}' (ID: {$deploymentId})\n";
} else {
    $deploymentId = (int)$deploymentId;
    $updDep = $pdo->prepare("
        UPDATE central_deployments SET
            product_id = ?, license_id = ?, deployment_domain = ?, hardware_hash = ?,
            api_ingest_token_hash = ?, token_preview = ?, current_version = ?,
            php_version = ?, os_info = ?, status = 'healthy', status_reason = 'Nominal - Active Rolling Lease',
            last_heartbeat_at = {$nowFunc}, is_active = 1, updated_at = {$nowFunc}
        WHERE id = ?
    ");
    $updDep->execute([
        $productId, $licenseId, $domain, $instanceUuid,
        $tokenHash, $tokenPreview, $version,
        PHP_VERSION, PHP_OS_FAMILY, $deploymentId
    ]);
    echo "[PASS] Synchronized deployment '{$domain}' (ID: {$deploymentId})\n";
}

echo "\nBeamStock successfully integrated into SpectraOps Hub!\n";
