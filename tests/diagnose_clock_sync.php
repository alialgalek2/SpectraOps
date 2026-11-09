<?php
/**
 * Clock & Time Sync Diagnostic Probe between SpectraOps and Prism EDU
 */

echo "========================================================\n";
echo " SPECTRAOPS <-> PRISM EDU TIME & CLOCK SYNC REPORT\n";
echo "========================================================\n\n";

// 1. Host OS System Clock
$osTime = time();
$osLocalStr = date('Y-m-d H:i:s P');
$osUtcStr = gmdate('Y-m-d H:i:s') . ' UTC';
$defaultTz = date_default_timezone_get();

echo "[1. Host & PHP Environment]\n";
echo " - Default Timezone: {$defaultTz}\n";
echo " - Local Timestamp:  {$osLocalStr}\n";
echo " - UTC Time:         {$osUtcStr}\n";
echo " - Unix Epoch:       {$osTime}\n\n";

// 2. SpectraOps Database Time
require_once __DIR__ . '/../src/Database.php';
$spectraPdo = \FleetHub\Database::getConnection();
$spectraDriver = $spectraPdo->getAttribute(PDO::ATTR_DRIVER_NAME);

echo "[2. SpectraOps Database Clock]\n";
echo " - Driver: {$spectraDriver}\n";
if ($spectraDriver === 'sqlite') {
    $row = $spectraPdo->query("SELECT datetime('now') AS dt, strftime('%s', 'now') AS ts")->fetch(PDO::FETCH_ASSOC);
    $spectraDbTime = (int)$row['ts'];
    $diff = $spectraDbTime - $osTime;
    echo " - SQLite UTC now:   {$row['dt']} UTC\n";
    echo " - SQLite Epoch:     {$row['ts']}\n";
    echo " - Drift from PHP:   {$diff}s " . ($diff === 0 ? "(PERFECT SYNC)" : "(DRIFT DETECTED)") . "\n\n";
} else {
    $row = $spectraPdo->query("SELECT UTC_TIMESTAMP() AS utc_dt, NOW() AS local_dt, UNIX_TIMESTAMP() AS ts")->fetch(PDO::FETCH_ASSOC);
    $spectraDbTime = (int)$row['ts'];
    $diff = $spectraDbTime - $osTime;
    echo " - MySQL UTC now:    {$row['utc_dt']} UTC\n";
    echo " - MySQL Local now:  {$row['local_dt']}\n";
    echo " - MySQL Epoch:      {$row['ts']}\n";
    echo " - Drift from PHP:   {$diff}s\n\n";
}

// 3. Prism EDU Database Clock (MySQL/MariaDB)
echo "[3. Prism EDU Database Clock]\n";
$prismDbFile = 'c:/xampp/htdocs/Prism EDU/core/Database.php';
if (file_exists($prismDbFile)) {
    try {
        require_once $prismDbFile;
        $prismPdo = Database::getConnection();
        $prismDriver = $prismPdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        echo " - Driver: {$prismDriver}\n";
        $row = $prismPdo->query("SELECT UTC_TIMESTAMP() AS utc_dt, NOW() AS local_dt, UNIX_TIMESTAMP() AS ts, @@global.time_zone AS global_tz, @@session.time_zone AS session_tz")->fetch(PDO::FETCH_ASSOC);
        $prismDbTime = (int)$row['ts'];
        $diff = $prismDbTime - $osTime;
        echo " - MySQL UTC now:    {$row['utc_dt']} UTC\n";
        echo " - MySQL Local now:  {$row['local_dt']}\n";
        echo " - Global Timezone:  {$row['global_tz']}\n";
        echo " - Session Timezone: {$row['session_tz']}\n";
        echo " - MySQL Epoch:      {$row['ts']}\n";
        echo " - Drift from PHP:   {$diff}s " . (abs($diff) <= 1 ? "(PERFECT SYNC)" : "(DRIFT DETECTED)") . "\n\n";
    } catch (\Throwable $e) {
        echo " - Error connecting to Prism EDU DB: " . $e->getMessage() . "\n\n";
    }
} else {
    echo " - Prism EDU Database.php not found.\n\n";
}

// 4. Live SpectraOps Ingestion API Response Time Check
echo "[4. Live Heartbeat / Ingestion Server Time Response]\n";
$hubUrl = 'http://127.0.0.1:8080/SPECTRAOPS/public/api/v1/telemetry/ingest.php';
// Get a valid deployment token
$dep = $spectraPdo->query("SELECT d.id, d.token_preview, d.api_ingest_token_hash, l.license_key FROM central_deployments d LEFT JOIN central_licenses l ON d.license_id = l.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);

if ($dep && !empty($dep['license_key'])) {
    $authKey = $dep['license_key'];
    $clientSendTime = time();
    $payload = json_encode([
        'product_slug' => 'prism-edu',
        'license_key'  => $authKey,
        'heartbeat'    => [
            'version'   => '2.5.0',
            'client_ts' => $clientSendTime,
            'client_utc'=> gmdate('c')
        ]
    ]);

    $ch = curl_init($hubUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $authKey]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($res, true);
        $serverTimeUtc = $data['server_time_utc'] ?? null;
        $serverTs = $serverTimeUtc ? strtotime($serverTimeUtc) : null;
        $leaseValidUntil = $data['lease_valid_until'] ?? null;
        $leaseRemaining = $leaseValidUntil ? ($leaseValidUntil - time()) : null;

        echo " - HTTP Status:       {$httpCode}\n";
        echo " - Client Sent (UTC): " . gmdate('c', $clientSendTime) . " (Epoch: {$clientSendTime})\n";
        echo " - Server Time (UTC): {$serverTimeUtc} (Epoch: {$serverTs})\n";
        if ($serverTs !== null) {
            $clockSkew = $serverTs - $clientSendTime;
            echo " - Clock Skew:        {$clockSkew}s " . (abs($clockSkew) <= 1 ? "(IN SYNC)" : "(DRIFT DETECTED)") . "\n";
        }
        echo " - Lease Valid Until: {$leaseValidUntil} (~" . round($leaseRemaining / 60, 1) . " minutes remaining)\n\n";
    } else {
        echo " - Live check returned HTTP {$httpCode}: {$res}\n\n";
    }
} else {
    echo " - No active deployment found in SpectraOps to test live API.\n\n";
}

// 5. Update Check Endpoint Time Validation
echo "[5. Live Update Check Endpoint Response Time]\n";
$updateUrl = 'http://127.0.0.1:8080/SPECTRAOPS/public/api/v1/updates/check.php?debug=1';
if ($dep && !empty($dep['license_key'])) {
    $ch = curl_init($updateUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['current_version' => '2.5.0']));
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $dep['license_key']]);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($res, true);
        $serverTimeUtc = $data['debug']['server_time_utc'] ?? null;
        $serverTs = $serverTimeUtc ? strtotime($serverTimeUtc) : null;
        echo " - HTTP Status:       {$httpCode}\n";
        echo " - Server Time (UTC): {$serverTimeUtc} (Epoch: {$serverTs})\n";
        if ($serverTs !== null) {
            $clockSkew = $serverTs - time();
            echo " - Clock Skew:        {$clockSkew}s " . (abs($clockSkew) <= 1 ? "(IN SYNC)" : "(DRIFT DETECTED)") . "\n";
        }
    } else {
        echo " - Update check returned HTTP {$httpCode}: {$res}\n";
    }
}

// 6. Prism EDU License Lock File Analysis
echo "\n[6. Prism EDU Local License Lock File Analysis]\n";
$lockFile = 'c:/xampp/htdocs/Prism EDU/storage/license.lock';
if (file_exists($lockFile)) {
    $lockData = json_decode(file_get_contents($lockFile), true);
    $lastSyncUtc = $lockData['last_sync_utc'] ?? 'Never';
    $leaseValidUntil = $lockData['lease_valid_until'] ?? 0;
    $remainingSec = $leaseValidUntil - time();
    $isExpired = $remainingSec <= 0;

    echo " - File Path:           {$lockFile}\n";
    echo " - License Status:      " . ($lockData['license_status'] ?? 'unknown') . "\n";
    echo " - Last Sync (UTC):     {$lastSyncUtc}\n";
    echo " - Lease Valid Until:   {$leaseValidUntil} (" . date('Y-m-d H:i:s T', $leaseValidUntil) . ")\n";
    echo " - Current System Time: " . time() . " (" . date('Y-m-d H:i:s T') . ")\n";
    echo " - Lease Remaining:     " . round($remainingSec / 60, 1) . " minutes (" . ($isExpired ? "EXPIRED" : "VALID") . ")\n";
} else {
    echo " - License lock file not found at {$lockFile}\n";
}
