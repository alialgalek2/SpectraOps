#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Automated Telemetry Heartbeat & Zero-Downtime Patch Runner
 * 
 * Invoked via background cron or systemd timer:
 * 1. Collects server health metrics and transmits heartbeat to SpectraOps.
 * 2. Checks remote update manifest via TelemetryReporter::checkForUpdates().
 * 3. If a minor patch release (semver patch component: x.y.Z) is available:
 *    - Silently downloads, verifies, and applies update via SystemUpdater.
 *    - Runs pending database migrations via Database\Migrator.
 *    - Atomically updates APP_VERSION in .env.
 *    - Dispatches a post-update confirmation heartbeat.
 */

$appRoot = dirname(__DIR__);

// Load application bootstrap
require_once $appRoot . '/bootstrap/app.php';
require_once $appRoot . '/database/Migrator.php';
require_once $appRoot . '/src/Services/SystemUpdater.php';
require_once $appRoot . '/src/Services/TelemetryReporter.php';

use App\Services\TelemetryReporter;
use App\Services\SystemUpdater;
use Database\Migrator;

$isCli = (PHP_SAPI === 'cli');
$options = $isCli ? getopt('', ['quiet', 'force-apply', 'dry-run']) : [];
$isQuiet = isset($options['quiet']);

function logOutput(string $message, bool $quiet = false): void {
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    }
}

logOutput("Starting automated telemetry heartbeat routine...", $isQuiet);

// Resolve configuration
$hubUrl = getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: 'https://support.yourcentraldomain.com');
$apiKey = getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: '');
$currentVersion = getenv('APP_VERSION') ?: '2.5.0';

if (empty($apiKey)) {
    logOutput("Warning: SPECTRAOPS_API_KEY is not configured. Heartbeat skipped.", $isQuiet);
    exit(0);
}

$reporter = new TelemetryReporter($hubUrl, $apiKey, 'prism-edu', $currentVersion);

// 1. Dispatch operational heartbeat to SpectraOps
logOutput("Dispatching live operational telemetry metrics to SpectraOps...", $isQuiet);
$hbSuccess = $reporter->sendHeartbeat([
    'status_note' => 'Automated background cron probe'
]);

if ($hbSuccess) {
    logOutput("Heartbeat successfully ingested and acknowledged by SpectraOps.", $isQuiet);
} else {
    logOutput("Warning: Heartbeat dispatch failed or endpoint unreachable.", $isQuiet);
}

// 2. Poll remote update channel for pending updates
logOutput("Checking for updates from SpectraOps release channel...", $isQuiet);
$updateData = null;
try {
    $updateData = $reporter->checkForUpdates();
} catch (\Throwable $e) {
    logOutput("Notice: Update check error: " . $e->getMessage(), $isQuiet);
}

if (!$updateData || empty($updateData['update_available']) || empty($updateData['latest_version'])) {
    logOutput("System is up to date (running v{$currentVersion}).", $isQuiet);
    exit(0);
}

$newVersion = (string)$updateData['latest_version'];
$downloadUrl = (string)($updateData['download_url'] ?? '');
$checksum = (string)($updateData['checksum'] ?? '');

logOutput("New candidate release discovered: v{$newVersion} (Current: v{$currentVersion})", $isQuiet);

// 3. Semver Component Verification: Identify if candidate is a patch release
function isSemverPatch(string $current, string $candidate): bool {
    $cur = ltrim(trim($current), 'vV');
    $cand = ltrim(trim($candidate), 'vV');

    $curParts = explode('.', $cur);
    $candParts = explode('.', $cand);

    if (count($curParts) < 3 || count($candParts) < 3) {
        return false;
    }

    $curMajor = (int)$curParts[0];
    $curMinor = (int)$curParts[1];
    $curPatch = (int)$curParts[2];

    $candMajor = (int)$candParts[0];
    $candMinor = (int)$candParts[1];
    $candPatch = (int)$candParts[2];

    // Major and minor versions match; candidate patch is strictly greater
    return ($candMajor === $curMajor && $candMinor === $curMinor && $candPatch > $curPatch);
}

$isPatch = isSemverPatch($currentVersion, $newVersion);
$forceApply = isset($options['force-apply']);

if (!$isPatch && !$forceApply) {
    logOutput("Release v{$newVersion} is a major or minor feature update. Skipping silent auto-patch (requires super-admin console approval).", $isQuiet);
    exit(0);
}

if (isset($options['dry-run'])) {
    logOutput("[DRY RUN] Patch v{$newVersion} is eligible for silent background deployment.", $isQuiet);
    exit(0);
}

// 4. Trigger silent zero-downtime update in background
logOutput("Applying zero-downtime patch v{$newVersion} silently...", $isQuiet);

if (empty($downloadUrl) || empty($checksum)) {
    logOutput("Error: Update manifest missing download_url or checksum.", $isQuiet);
    exit(1);
}

$updater = new SystemUpdater($appRoot);
$updateResult = $updater->applyUpdate($downloadUrl, $checksum, $newVersion);

if (!$updateResult['success']) {
    logOutput("Automated patch application failed: " . ($updateResult['error'] ?? 'Unknown error'), $isQuiet);
    // Report error to SpectraOps telemetry
    $reporter->sendError('critical', "Automated patch update to v{$newVersion} failed: " . ($updateResult['error'] ?? 'Unknown'));
    exit(1);
}

logOutput("Zero-downtime patch applied successfully: " . ($updateResult['message'] ?? ''), $isQuiet);

// 5. Run pending database migrations from database/migrations/
logOutput("Executing pending database migrations from database/migrations/...", $isQuiet);
try {
    $migrator = new Migrator();
    $migResult = $migrator->runPendingMigrations();
    if (!empty($migResult['applied'])) {
        logOutput("Applied migrations: " . implode(', ', $migResult['applied']), $isQuiet);
    } else {
        logOutput("Database schema is already current.", $isQuiet);
    }
} catch (\Throwable $e) {
    logOutput("Warning: Migration execution encountered an issue: " . $e->getMessage(), $isQuiet);
    $reporter->sendError('warning', "Post-patch migration warning: " . $e->getMessage());
}

// 6. Atomically update APP_VERSION in .env
$envPath = $appRoot . '/.env';
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
    $updatedEnv = preg_replace('/^APP_VERSION=.*/m', 'APP_VERSION="' . $newVersion . '"', $envContent);
    $tmpPath = $envPath . '.tmp.' . bin2hex(random_bytes(6));
    if (file_put_contents($tmpPath, $updatedEnv, LOCK_EX) !== false) {
        if (@rename($tmpPath, $envPath)) {
            logOutput("Updated .env APP_VERSION to \"{$newVersion}\" atomically.", $isQuiet);
        } else {
            @unlink($tmpPath);
        }
    }
}

// 7. Dispatch post-update heartbeat with upgraded version to SpectraOps
logOutput("Notifying SpectraOps of upgraded version v{$newVersion}...", $isQuiet);
$reporter->sendHeartbeat([
    'version'     => $newVersion,
    'status_note' => "Successfully upgraded to v{$newVersion} via automated zero-downtime patch runner"
]);

logOutput("Automated update runner completed successfully.", $isQuiet);
exit(0);
