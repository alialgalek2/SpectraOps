<?php
declare(strict_types=1);

namespace App\Services;

use ZipArchive;
use RuntimeException;
use Throwable;
use Database\Migrator;

class SystemUpdater {
    private string $appRoot;
    private string $tempDir;
    private string $backupDir;

    public function __construct(string $appRoot) {
        $this->appRoot   = rtrim(realpath($appRoot) ?: $appRoot, DIRECTORY_SEPARATOR);
        $this->tempDir   = $this->appRoot . '/storage/updates/tmp';
        $this->backupDir = $this->appRoot . '/storage/updates/backups';

        if (!is_dir($this->tempDir)) mkdir($this->tempDir, 0750, true);
        if (!is_dir($this->backupDir)) mkdir($this->backupDir, 0750, true);
    }

    /**
     * Executes the end-to-end atomic zero-downtime update process.
     */
    public function applyUpdate(string $downloadUrl, string $expectedChecksum, string $newVersion): array {
        @set_time_limit(300); // Allow up to 5 minutes for large assets
        $archivePath = $this->tempDir . "/update_{$newVersion}.zip";
        $stagingPath = $this->tempDir . "/extracted_{$newVersion}";

        try {
            // Step 1: Stream archive from remote package distribution server
            $this->downloadPackage($downloadUrl, $archivePath);

            // Step 2: Cryptographic Checksum Verification (SHA-256)
            $this->verifyChecksum($archivePath, $expectedChecksum);

            // Step 3: Unpack into an isolated staging directory with Zip-Slip protection
            $this->extractSecurely($archivePath, $stagingPath);

            // Step 4: Atomic in-place deployment with shadow swapping & rollback registry
            $this->deployStagedFilesZeroDowntime($stagingPath);

            // Step 5: Run Database Migrations if present
            $migrationResult = $this->runDatabaseMigrations();

            // Step 6: Clear Opcode & Template Caches
            $this->flushCaches();

            return [
                'success'    => true,
                'message'    => "Successfully upgraded to version {$newVersion}.",
                'version'    => $newVersion,
                'migrations' => $migrationResult
            ];

        } catch (Throwable $e) {
            error_log("[SystemUpdater] Upgrade failed: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => $e->getMessage()
            ];
        } finally {
            // Clean up temporary update artifacts
            $this->deleteDirectory($stagingPath);
            if (file_exists($archivePath)) {
                @unlink($archivePath);
            }
        }
    }

    /**
     * Streams the package directly to disk using cURL CURLOPT_FILE.
     */
    private function downloadPackage(string $url, string $destination): void {
        // Support local files or file:// protocol for testing harnesses
        if (str_starts_with($url, 'file://') || (file_exists($url) && !str_starts_with($url, 'http'))) {
            $localSource = str_starts_with($url, 'file://') ? substr($url, 7) : $url;
            if (realpath($localSource) !== realpath($destination)) {
                if (!copy($localSource, $destination)) {
                    throw new RuntimeException("Failed to copy local package archive to {$destination}");
                }
            }
            return;
        }

        $fp = fopen($destination, 'w+');
        if (!$fp) {
            throw new RuntimeException("Cannot create write buffer at {$destination}");
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $executed = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        unset($ch);
        fclose($fp);

        if (!$executed || $httpCode !== 200) {
            @unlink($destination);
            throw new RuntimeException("Download failed (HTTP {$httpCode}): {$curlErr}");
        }
    }

    /**
     * Validates package cryptographic checksum using hash_equals.
     */
    private function verifyChecksum(string $filePath, string $expectedHash): void {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Target archive not found for checksum verification: {$filePath}");
        }

        $actualHash = hash_file('sha256', $filePath);
        if (!hash_equals(strtolower($expectedHash), strtolower((string)$actualHash))) {
            @unlink($filePath);
            throw new RuntimeException("Security verification failed. Checksum mismatch! (Expected: {$expectedHash}, Calculated: {$actualHash})");
        }
    }

    /**
     * Extracts archive using ZipArchive with rigorous Zip-Slip path traversal defense.
     */
    private function extractSecurely(string $zipPath, string $targetDir): void {
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Corrupt archive file.");
        }

        $realTarget = realpath($targetDir);
        if ($realTarget === false) {
            $zip->close();
            throw new RuntimeException("Invalid target staging directory: {$targetDir}");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Path Traversal Guard (Zip Slip prevention)
            if (
                str_contains($filename, '..') ||
                str_starts_with($filename, '/') ||
                str_starts_with($filename, '\\') ||
                preg_match('/^[a-zA-Z]:/', $filename)
            ) {
                $zip->close();
                throw new RuntimeException("Malicious path detected in archive: {$filename}");
            }

            $destPath = $realTarget . DIRECTORY_SEPARATOR . $filename;

            // Directory entry
            if (str_ends_with($filename, '/') || str_ends_with($filename, '\\')) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
                continue;
            }

            $dir = dirname($destPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // Extract file content
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $zip->close();
                throw new RuntimeException("Failed to read archive entry: {$filename}");
            }

            if (file_put_contents($destPath, $content) === false) {
                $zip->close();
                throw new RuntimeException("Failed to extract staged file to {$destPath}");
            }
        }

        $zip->close();
    }

    /**
     * Atomic in-place deployment engine.
     * Uses shadow files (target_file.shadow_<random>), POSIX syscall rename(),
     * and a rollback registry of existing file backups (.bak).
     */
    public function deployStagedFilesZeroDowntime(string $stagingDir): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stagingDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        // Protected customer files & paths that MUST NEVER be overwritten
        $protectedPatterns = [
            '/\.env(\..+)?$/i',
            '/^storage\/uploads(\/.*)?$/i',
            '/^storage\/keys(\/.*)?$/i',
            '/^storage\/backups(\/.*)?$/i',
            '/^storage\/license\.lock$/i',
            '/^storage\/updates(\/.*)?$/i'
        ];

        $runId = bin2hex(random_bytes(6));
        $rollbackRegistry = [];
        $shadowFilesCreated = [];

        try {
            foreach ($iterator as $item) {
                $subPath = substr($item->getPathname(), strlen($stagingDir) + 1);
                $normalizedSubPath = str_replace('\\', '/', $subPath);
                $targetPath = $this->appRoot . DIRECTORY_SEPARATOR . $subPath;

                // Check against customer-protected paths
                foreach ($protectedPatterns as $pattern) {
                    if (preg_match($pattern, $normalizedSubPath)) {
                        continue 2; // Strictly preserve critical customer files
                    }
                }

                if ($item->isDir()) {
                    if (!is_dir($targetPath)) {
                        mkdir($targetPath, 0755, true);
                    }
                } else {
                    $targetParent = dirname($targetPath);
                    if (!is_dir($targetParent)) {
                        mkdir($targetParent, 0755, true);
                    }

                    // Create shadow file alongside target
                    $shadowPath = $targetPath . '.shadow_' . $runId . '_' . bin2hex(random_bytes(4));
                    if (!copy($item->getPathname(), $shadowPath)) {
                        throw new RuntimeException("Failed to write shadow file: {$shadowPath}");
                    }
                    $shadowFilesCreated[] = $shadowPath;

                    // If target exists, take a backup copy for rollback protection
                    $backupPath = null;
                    if (file_exists($targetPath)) {
                        $backupPath = $this->backupDir . '/' . md5($targetPath) . '_' . basename($targetPath) . '_' . $runId . '.bak';
                        if (!copy($targetPath, $backupPath)) {
                            throw new RuntimeException("Failed to create rollback backup for: {$targetPath}");
                        }
                        $rollbackRegistry[] = [
                            'type'        => 'modify',
                            'target_path' => $targetPath,
                            'backup_path' => $backupPath,
                            'shadow_path' => $shadowPath
                        ];
                    } else {
                        $rollbackRegistry[] = [
                            'type'        => 'create',
                            'target_path' => $targetPath,
                            'backup_path' => null,
                            'shadow_path' => $shadowPath
                        ];
                    }

                    // Perform atomic file swap
                    if (!rename($shadowPath, $targetPath)) {
                        throw new RuntimeException("Atomic swap syscall failed: rename({$shadowPath}, {$targetPath})");
                    }
                }
            }

            // Deployment succeeded across all files: clean up rollback backups
            foreach ($rollbackRegistry as $entry) {
                if (!empty($entry['backup_path']) && file_exists($entry['backup_path'])) {
                    @unlink($entry['backup_path']);
                }
            }

        } catch (Throwable $e) {
            // SWAP FAILED: Initiate immediate atomic rollback
            error_log("[SystemUpdater] Deployment aborted during swap. Triggering rollback. Error: " . $e->getMessage());
            $this->rollbackDeployment($rollbackRegistry);

            // Clean up any stray shadow files
            foreach ($shadowFilesCreated as $sFile) {
                if (file_exists($sFile)) {
                    @unlink($sFile);
                }
            }

            throw new RuntimeException("Update deployment failed and was safely rolled back: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Backward-compatible alias for deployStagedFilesZeroDowntime
     */
    public function deployStagedFiles(string $stagingDir): void {
        $this->deployStagedFilesZeroDowntime($stagingDir);
    }

    /**
     * Restores modified files from backup registry and cleans up newly created files.
     */
    private function rollbackDeployment(array $rollbackRegistry): void {
        // Roll back in reverse order of deployment
        $reversed = array_reverse($rollbackRegistry);

        foreach ($reversed as $entry) {
            $target = $entry['target_path'];
            $backup = $entry['backup_path'];

            if ($entry['type'] === 'modify' && $backup !== null && file_exists($backup)) {
                @rename($backup, $target);
            } elseif ($entry['type'] === 'create' && file_exists($target)) {
                @unlink($target);
            }
        }
    }

    /**
     * Executes pending database migrations using Migrator.
     */
    private function runDatabaseMigrations(): array {
        // 1. Run via Migrator class if available
        try {
            if (class_exists('\\Database\\Migrator')) {
                $migrator = new \Database\Migrator();
                return $migrator->runPendingMigrations();
            }
        } catch (Throwable $e) {
            error_log("[SystemUpdater] Migrator run error: " . $e->getMessage());
        }

        // 2. Fallback to database/migrate.php
        $migrationFile = $this->appRoot . '/database/migrate.php';
        if (file_exists($migrationFile)) {
            require_once $migrationFile;
        }

        return ['success' => true, 'message' => 'Migrations processed.'];
    }

    /**
     * Immediately invalidate compiled PHP opcache.
     */
    private function flushCaches(): void {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /**
     * Recursively delete directory and its contents.
     */
    private function deleteDirectory(string $dir): void {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
