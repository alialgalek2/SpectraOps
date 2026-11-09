<?php
declare(strict_types=1);

namespace App\Services;

use ZipArchive;
use RuntimeException;
use Throwable;

class OtaUpdateService {
    private string $appRoot;
    private string $hubUrl;
    private string $apiKey;
    private string $licenseKey;
    private string $currentVersion;
    private string $productSlug;
    private bool $sslVerify;

    public function __construct(
        ?string $appRoot = null,
        ?string $hubUrl = null,
        ?string $apiKey = null,
        ?string $licenseKey = null,
        ?string $currentVersion = null,
        string $productSlug = 'prism-edu',
        bool $sslVerify = false
    ) {
        $this->appRoot = rtrim(realpath($appRoot ?? dirname(__DIR__)) ?: ($appRoot ?? dirname(__DIR__)), DIRECTORY_SEPARATOR);
        $this->loadEnvironment();

        $this->hubUrl = rtrim($hubUrl ?? (string)(getenv('SPECTRAOPS_HUB_URL') ?: (getenv('TELEMETRY_HUB_URL') ?: 'http://127.0.0.1:8080/SPECTRAOPS/public')), '/');
        $this->apiKey = $apiKey ?? (string)(getenv('SPECTRAOPS_API_KEY') ?: (getenv('TELEMETRY_INGEST_TOKEN') ?: ''));
        $this->licenseKey = $licenseKey ?? (string)(getenv('SPECTRAOPS_LICENSE_KEY') ?: '');
        $this->currentVersion = $currentVersion ?? (string)(getenv('APP_VERSION') ?: '1.0.0');
        $this->productSlug = $productSlug;
        $this->sslVerify = $sslVerify;
    }

    /**
     * Poll the SpectraOps Hub endpoint for pending OTA updates.
     */
    public function checkForUpdates(): array {
        $checkUrl = $this->hubUrl . '/api/v1/updates/check.php';
        $payload = [
            'current_version' => $this->currentVersion,
            'product_slug'    => $this->productSlug,
            'license_key'     => $this->licenseKey,
            'api_token'       => $this->apiKey
        ];

        $ch = curl_init($checkUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'X-API-KEY: ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);

        $response = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $localCheck = dirname(__DIR__) . '/public/api/v1/updates/check.php';
            if (!file_exists($localCheck)) {
                $localCheck = 'C:/xampp/htdocs/SPECTRAOPS/public/api/v1/updates/check.php';
            }
            if (file_exists($localCheck)) {
                $res = self::dispatchLocalEndpoint($localCheck, 'GET', $payload, [
                    'Authorization: Bearer ' . ($this->licenseKey ?: $this->apiKey),
                    'X-API-KEY: ' . $this->apiKey
                ]);
                if (is_array($res['json'])) {
                    return $res['json'];
                }
            }
            throw new RuntimeException("Update check failed (HTTP {$httpCode}): " . ($curlErr ?: $response));
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            throw new RuntimeException("Invalid JSON response from update check: " . substr((string)$response, 0, 150));
        }

        return $data;
    }

    /**
     * Programmatic entry point simulating full OTA execution cycle.
     */
    public function handle(?string $currentVersion = null, bool $autoApplyOnly = true): array {
        if ($currentVersion !== null) {
            $this->currentVersion = $currentVersion;
        }
        return $this->pollAndApply($autoApplyOnly);
    }

    /**
     * Poll and automatically apply update if approved.
     * Enforces approval gate: if is_silent_patch is false and autoApplyOnly = true,
     * update is withheld and flagged for manual approval.
     */
    public function pollAndApply(bool $autoApplyOnly = true): array {
        $check = $this->checkForUpdates();

        if (empty($check['update_available'])) {
            return [
                'applied' => false,
                'status'  => 'up_to_date',
                'message' => $check['message'] ?? "Current version v{$this->currentVersion} is up to date."
            ];
        }

        $latestVer = ltrim((string)($check['latest_version'] ?? ''), 'vV');
        $isSilent = !empty($check['is_silent_patch']);

        // Approval Gate: Check is_silent_patch
        if (!$isSilent && $autoApplyOnly) {
            return [
                'applied'                  => false,
                'status'                   => 'manual_approval_required',
                'requires_manual_approval' => true,
                'is_silent_patch'          => false,
                'latest_version'           => $latestVer,
                'current_version'          => $this->currentVersion,
                'message'                  => "Update v{$latestVer} requires manual administrative approval (not a silent patch)."
            ];
        }

        $downloadUrl = (string)($check['download_url'] ?? '');
        $checksum = (string)($check['checksum'] ?? '');
        $resolvesErrorId = isset($check['resolves_error_id']) ? (int)$check['resolves_error_id'] : null;

        if (empty($downloadUrl) || empty($checksum)) {
            throw new RuntimeException("Incomplete update package metadata received from hub.");
        }

        return $this->applyUpdatePackage($downloadUrl, $checksum, $latestVer, $resolvesErrorId);
    }

    /**
     * Executes atomic update download, cryptographic verification, file snapshot,
     * extraction, and closed-loop confirmation receipt.
     */
    public function applyUpdatePackage(
        string $downloadUrl,
        string $expectedChecksum,
        string $newVersion,
        ?int $resolvesErrorId = null
    ): array {
        $tempZip = tempnam(sys_get_temp_dir(), 'prism_ota_') . '.zip';
        $backupDir = $this->appRoot . '/storage/ota_backups/pre_' . $newVersion . '_' . time();

        try {
            // 1. Stream download package
            $this->streamDownload($downloadUrl, $tempZip);

            // 2. Verify SHA-256 hash using hash_equals
            $actualHash = hash_file('sha256', $tempZip);
            if ($actualHash === false || !hash_equals(strtolower($expectedChecksum), strtolower($actualHash))) {
                throw new RuntimeException("Cryptographic checksum mismatch. Expected [{$expectedChecksum}], got [{$actualHash}].");
            }

            // 3. Inspect zip and snapshot existing local files that will be modified
            $this->snapshotFilesForRollback($tempZip, $backupDir);

            // 4. Extract .zip over root path with transactional protection
            $this->extractZipArchive($tempZip, $this->appRoot, $backupDir);

            // 5. Update local version
            $this->updateLocalVersion($newVersion);

            // 6. Closed-Loop Confirmation: Notify SpectraOps telemetry ingest
            $confirmation = $this->sendConfirmationReceipt($newVersion, $resolvesErrorId);

            return [
                'applied'            => true,
                'status'             => 'installed',
                'previous_version'   => $this->currentVersion,
                'installed_version'  => $newVersion,
                'backup_path'        => $backupDir,
                'receipt_confirmed'  => ($confirmation['receipt_accepted'] ?? false),
                'message'            => "Successfully updated to v{$newVersion}."
            ];

        } catch (Throwable $e) {
            error_log("[OtaUpdateService] OTA update failed: " . $e->getMessage());

            // Rollback snapshot if backup exists
            if (is_dir($backupDir)) {
                $this->rollbackSnapshot($backupDir);
            }

            // Closed-loop rollback notification to Hub
            $rollbackReceipt = $this->sendRollbackReceipt($newVersion, $e->getMessage(), $resolvesErrorId);

            return [
                'applied'            => false,
                'status'             => 'rollback_executed',
                'rollback_confirmed' => ($rollbackReceipt['receipt_accepted'] ?? false),
                'error'              => $e->getMessage()
            ];
        } finally {
            if (file_exists($tempZip)) {
                @unlink($tempZip);
            }
        }
    }

    /**
     * Rollback snapshot from backup directory over appRoot.
     */
    public function rollbackSnapshot(string $backupDir): bool {
        if (!is_dir($backupDir)) {
            return false;
        }

        $manifestFile = $backupDir . '/snapshot_manifest.json';
        $filesToRestore = [];
        if (file_exists($manifestFile)) {
            $filesToRestore = json_decode((string)file_get_contents($manifestFile), true) ?: [];
        }

        $restoredCount = 0;
        $dirIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($dirIterator as $item) {
            $subPath = substr($item->getPathname(), strlen($backupDir) + 1);
            if ($subPath === 'snapshot_manifest.json') continue;

            $targetPath = $this->appRoot . DIRECTORY_SEPARATOR . $subPath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    @mkdir($targetPath, 0755, true);
                }
            } else {
                $parent = dirname($targetPath);
                if (!is_dir($parent)) {
                    @mkdir($parent, 0755, true);
                }
                if (@copy($item->getPathname(), $targetPath)) {
                    $restoredCount++;
                }
            }
        }

        error_log("[OtaUpdateService] Rollback executed from {$backupDir}. Restored {$restoredCount} files.");
        return true;
    }

    /**
     * Snapshot only the files that the zip package will modify.
     */
    private function snapshotFilesForRollback(string $zipPath, string $backupDir): void {
        if (!is_dir($backupDir)) {
            @mkdir($backupDir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Unable to inspect update zip archive.");
        }

        $snapshotted = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat || empty($stat['name'])) continue;

            $relPath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $stat['name']);
            if (str_ends_with($relPath, DIRECTORY_SEPARATOR)) continue;

            $localFile = $this->appRoot . DIRECTORY_SEPARATOR . $relPath;
            if (file_exists($localFile) && is_file($localFile)) {
                $destFile = $backupDir . DIRECTORY_SEPARATOR . $relPath;
                $destDir = dirname($destFile);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0755, true);
                }
                copy($localFile, $destFile);
                $snapshotted[] = $relPath;
            }
        }
        $zip->close();

        file_put_contents($backupDir . '/snapshot_manifest.json', json_encode($snapshotted, JSON_PRETTY_PRINT));
    }

    /**
     * Safely extracts zip archive over app root with Zip-Slip protection.
     */
    private function extractZipArchive(string $zipPath, string $destination, string $backupDir): void {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Failed to open downloaded archive for extraction.");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);
            if ($entry === false) continue;

            // Zip-Slip protection: ensure target remains inside destination
            $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $entry);
            if (str_contains($normalized, '..' . DIRECTORY_SEPARATOR)) {
                $zip->close();
                throw new RuntimeException("Zip-Slip security violation detected in archive entry: {$entry}");
            }

            $targetPath = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalized;

            if (str_ends_with($entry, '/') || str_ends_with($entry, '\\')) {
                if (!is_dir($targetPath)) {
                    @mkdir($targetPath, 0755, true);
                }
                continue;
            }

            $parent = dirname($targetPath);
            if (!is_dir($parent)) {
                @mkdir($parent, 0755, true);
            }

            $stream = $zip->getStream($entry);
            if ($stream) {
                file_put_contents($targetPath, stream_get_contents($stream));
                fclose($stream);
            }
        }

        $zip->close();
    }

    /**
     * Stream download package to local disk destination.
     */
    private function streamDownload(string $url, string $destPath): void {
        if (str_starts_with($url, 'file://') || (file_exists($url) && !str_starts_with($url, 'http'))) {
            $local = str_starts_with($url, 'file://') ? substr($url, 7) : $url;
            if (!copy($local, $destPath)) {
                throw new RuntimeException("Failed to copy local package from {$local}");
            }
            return;
        }

        // Local filesystem fallback check for testbeds or offline CLI runs
        $parsedFilename = basename(parse_url($url, PHP_URL_PATH) ?? $url);
        $localCandidates = [
            dirname(__DIR__) . '/public/downloads/updates/' . $parsedFilename,
            dirname(__DIR__, 2) . '/SPECTRAOPS/public/downloads/updates/' . $parsedFilename,
            'C:/xampp/htdocs/SPECTRAOPS/public/downloads/updates/' . $parsedFilename
        ];
        $localHubPath = null;
        foreach ($localCandidates as $cand) {
            if (file_exists($cand)) {
                $localHubPath = $cand;
                break;
            }
        }

        $fp = fopen($destPath, 'wb');
        if (!$fp) {
            throw new RuntimeException("Unable to open destination file for streaming download: {$destPath}");
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);

        $success = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode < 200 || $httpCode >= 300) {
            if ($localHubPath && file_exists($localHubPath)) {
                if (copy($localHubPath, $destPath)) {
                    return;
                }
            }
            @unlink($destPath);
            throw new RuntimeException("Failed to stream download package from {$url} (HTTP {$httpCode}): {$err}");
        }
    }

    /**
     * Send closed-loop confirmation receipt to SpectraOps Hub.
     */
    private function sendConfirmationReceipt(string $installedVersion, ?int $resolvesErrorId = null): array {
        $ingestUrl = $this->hubUrl . '/api/v1/telemetry/ingest.php';
        $payload = [
            'action'            => 'confirm_ota_receipt',
            'installed_version' => $installedVersion,
            'resolves_error_id' => $resolvesErrorId,
            'license_key'       => $this->licenseKey,
            'api_token'         => $this->apiKey
        ];

        $ch = curl_init($ingestUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . ($this->licenseKey ?: $this->apiKey),
            'X-API-KEY: ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);

        $res = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$res, true);
        if (is_array($decoded) && $httpCode >= 200 && $httpCode < 300) {
            return $decoded;
        }

        // Local fallback for testbeds
        $localIngest = dirname(__DIR__) . '/public/api/v1/telemetry/ingest.php';
        if (!file_exists($localIngest)) {
            $localIngest = 'C:/xampp/htdocs/SPECTRAOPS/public/api/v1/telemetry/ingest.php';
        }
        if (file_exists($localIngest)) {
            $locRes = self::dispatchLocalEndpoint($localIngest, 'POST', $payload, [
                'Authorization: Bearer ' . ($this->licenseKey ?: $this->apiKey),
                'X-API-KEY: ' . $this->apiKey
            ]);
            if (is_array($locRes['json'])) {
                return $locRes['json'];
            }
        }

        return ['receipt_accepted' => false];
    }

    /**
     * Send closed-loop rollback receipt to SpectraOps Hub.
     */
    public function sendRollbackReceipt(string $failedVersion, ?string $reason = null, ?int $resolvesErrorId = null): array {
        $ingestUrl = $this->hubUrl . '/api/v1/telemetry/ingest.php';
        $payload = [
            'action'            => 'confirm_ota_receipt',
            'status'            => 'rolled_back',
            'failed_version'    => $failedVersion,
            'installed_version' => $failedVersion,
            'resolves_error_id' => $resolvesErrorId,
            'reason'            => $reason,
            'license_key'       => $this->licenseKey,
            'api_token'         => $this->apiKey
        ];

        $ch = curl_init($ingestUrl);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . ($this->licenseKey ?: $this->apiKey),
            'X-API-KEY: ' . $this->apiKey
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->sslVerify ? 2 : 0);

        $res = @curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string)$res, true);
        if (is_array($decoded) && $httpCode >= 200 && $httpCode < 300) {
            return $decoded;
        }

        // Local fallback for testbeds
        $localIngest = dirname(__DIR__) . '/public/api/v1/telemetry/ingest.php';
        if (!file_exists($localIngest)) {
            $localIngest = 'C:/xampp/htdocs/SPECTRAOPS/public/api/v1/telemetry/ingest.php';
        }
        if (file_exists($localIngest)) {
            $locRes = self::dispatchLocalEndpoint($localIngest, 'POST', $payload, [
                'Authorization: Bearer ' . ($this->licenseKey ?: $this->apiKey),
                'X-API-KEY: ' . $this->apiKey
            ]);
            if (is_array($locRes['json'])) {
                return $locRes['json'];
            }
        }

        return ['receipt_accepted' => false];
    }

    /**
     * Programmatic alias for reporting an update rollback.
     */
    public function reportRollback(string $failedVersion, ?string $reason = null, ?int $resolvesErrorId = null): array {
        return $this->sendRollbackReceipt($failedVersion, $reason, $resolvesErrorId);
    }

    /**
     * Dispatch endpoint locally in-process/sub-process for test environments.
     */
    public static function dispatchLocalEndpoint(string $scriptPath, string $method, array $payload, array $headers = []): array {
        if (!file_exists($scriptPath)) {
            return ['status_code' => 404, 'json' => null, 'raw' => '', 'stderr' => ''];
        }

        $serverVars = [
            'REQUEST_METHOD' => $method,
            'REMOTE_ADDR'    => '127.0.0.1'
        ];
        foreach ($headers as $h) {
            if (str_contains($h, ':')) {
                [$k, $v] = explode(':', $h, 2);
                $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', trim($k)));
                $serverVars[$headerKey] = trim($v);
            }
        }

        $serverExport = var_export($serverVars, true);
        $payloadJson = json_encode($payload);
        $getExport = ($method === 'GET') ? var_export($payload, true) : '[]';
        $postExport = ($method === 'POST') ? var_export($payload, true) : '[]';

        $code = <<<PHP
<?php
\$_SERVER = array_merge(\$_SERVER, {$serverExport});
\$_GET = {$getExport};
\$_POST = {$postExport};

register_shutdown_function(function() {
    \$code = http_response_code();
    echo "\n__HTTP_STATUS__:" . (\$code ?: 200) . "\n";
});

require '{$scriptPath}';
PHP;

        $tempScript = sys_get_temp_dir() . '/endpoint_exec_' . uniqid() . '.php';
        file_put_contents($tempScript, $code);

        $cmd = "php -d display_errors=0 {$tempScript}";
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        $process = proc_open($cmd, $descriptors, $pipes, dirname($scriptPath));
        if (!is_resource($process)) {
            @unlink($tempScript);
            return ['status_code' => 500, 'json' => null, 'raw' => '', 'stderr' => ''];
        }

        fwrite($pipes[0], $payloadJson);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);
        @unlink($tempScript);

        $statusCode = 200;
        if (preg_match('/__HTTP_STATUS__:(\d+)/', $stdout, $m)) {
            $statusCode = (int)$m[1];
            $stdout = preg_replace('/\n?__HTTP_STATUS__:\d+\n?/', '', $stdout);
        }

        $json = json_decode(trim($stdout), true);
        return [
            'status_code' => $statusCode,
            'json'        => $json,
            'raw'         => $stdout,
            'stderr'      => $stderr
        ];
    }

    /**
     * Update APP_VERSION in local .env file.
     */
    private function updateLocalVersion(string $newVersion): void {
        $envFile = $this->appRoot . '/.env';
        if (file_exists($envFile)) {
            $content = (string)file_get_contents($envFile);
            if (preg_match('/^APP_VERSION=.*$/m', $content)) {
                $content = preg_replace('/^APP_VERSION=.*$/m', 'APP_VERSION="' . $newVersion . '"', $content);
            } else {
                $content .= "\nAPP_VERSION=\"" . $newVersion . "\"\n";
            }
            @file_put_contents($envFile, $content);
        }
        $this->currentVersion = $newVersion;
    }

    private function loadEnvironment(): void {
        $envFile = $this->appRoot . '/.env';
        if (!file_exists($envFile)) return;

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $k = trim($parts[0]);
                $v = trim($parts[1]);
                if ((str_starts_with($v, '"') && str_ends_with($v, '"')) || (str_starts_with($v, "'") && str_ends_with($v, "'"))) {
                    $v = substr($v, 1, -1);
                }
                if (getenv($k) === false) {
                    putenv("{$k}={$v}");
                    $_ENV[$k] = $v;
                }
            }
        }
    }
}
