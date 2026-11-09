<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use ZipArchive;

class UpdateApplier {
    /**
     * Production Public Key embedded into the Client SDK at deploy time.
     * Never fetched over the wire at runtime.
     */
    private string $trustedPublicKey = <<<PEM
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAvbUHw3Ps/jmqccT/DnXR
1deQoP25UXUUGK1YTmObehlWDklPOGknAEITicOZmz4uSrx3mZRHiBSRvmQ9lyZH
oekrQWIFuqn9NWCVaEEs0wCBbsbvrYdoGg8RSeuylNaqFwm9gRKKXR+u08sdQm2P
Nh/sOFyJKaRlhRnwszFqjilOTen6HQsrPzUUTqYtZ2gQtt4iTllH0R/VCase0glg
/WnhfPFzwOoHv7JHwUttuBaOsMv4A+7NyQkknwZ3JJW0E6kdYHAe4qWm0sblJDm9
xFNBSn7XZWWfKmqlLXLySptCyiT9pWdxXSCnNTGlxqKcwTE+oZEv1SsgERRnm6LZ
mwIDAQAB
-----END PUBLIC KEY-----
PEM;

    private string $appRoot;

    public function __construct(?string $appRoot = null, ?string $customPublicKey = null) {
        $this->appRoot = $appRoot ?? dirname(__DIR__);
        if ($customPublicKey !== null) {
            $this->trustedPublicKey = $customPublicKey;
        }
    }

    /**
     * Verify cryptographic signature against a checksum using embedded public key.
     */
    public function verifyChecksumSignature(string $checksum, string $signatureBase64): bool {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false || trim($checksum) === '') {
            return false;
        }
        $sigVerified = openssl_verify($checksum, $signature, $this->trustedPublicKey, OPENSSL_ALGO_SHA256);
        return $sigVerified === 1;
    }

    /**
     * Verify cryptographic signature of a local file package using embedded public key.
     */
    public function verifyPackage(string $filePath, string $signatureBase64): bool {
        if (!file_exists($filePath)) {
            return false;
        }
        $checksum = hash_file('sha256', $filePath);
        return $this->verifyChecksumSignature($checksum, $signatureBase64);
    }

    /**
     * Download an update package, verify cryptographic signature and checksum, and apply.
     * 
     * @param string $downloadUrl
     * @param string $expectedChecksum SHA-256 checksum of the package
     * @param string $signatureBase64 Cryptographic signature of the expected checksum
     * @return array [success => bool, message => string]
     */
    public function applyUpdate(string $downloadUrl, string $expectedChecksum, string $signatureBase64): array {
        // 1. Verify Cryptographic Signature of Checksum BEFORE touching filesystem
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            $this->logSecurityAlert("Failed to decode base64 signature for package update: {$downloadUrl}");
            return ['success' => false, 'error' => 'Invalid update signature encoding'];
        }

        $sigVerified = openssl_verify($expectedChecksum, $signature, $this->trustedPublicKey, OPENSSL_ALGO_SHA256);
        if ($sigVerified !== 1) {
            $this->logSecurityAlert("Cryptographic signature verification FAILED for update checksum [{$expectedChecksum}] from [{$downloadUrl}]!");
            return ['success' => false, 'error' => 'Cryptographic signature verification failed. Update package rejected.'];
        }

        // 2. Download package to temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'update_pkg_');
        if (!$tempFile) {
            return ['success' => false, 'error' => 'Unable to create temporary file for update'];
        }

        $ch = curl_init($downloadUrl);
        $fp = fopen($tempFile, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $execResult = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$execResult || $httpCode < 200 || $httpCode >= 300) {
            @unlink($tempFile);
            return ['success' => false, 'error' => "Failed to download update package (HTTP {$httpCode})"];
        }

        // 3. Compute and verify SHA-256 Checksum of downloaded file
        $actualChecksum = hash_file('sha256', $tempFile);
        if (!hash_equals($expectedChecksum, (string)$actualChecksum)) {
            @unlink($tempFile);
            $this->logSecurityAlert("Checksum mismatch on downloaded update package: expected {$expectedChecksum}, got {$actualChecksum}");
            return ['success' => false, 'error' => 'Downloaded file integrity check failed (SHA-256 checksum mismatch)'];
        }

        // 4. Safely extract archive
        if (!class_exists('ZipArchive')) {
            @unlink($tempFile);
            return ['success' => false, 'error' => 'ZipArchive extension required to unpack release'];
        }

        $zip = new ZipArchive();
        if ($zip->open($tempFile) !== true) {
            @unlink($tempFile);
            return ['success' => false, 'error' => 'Failed to open downloaded update zip archive'];
        }

        $archiveError = $this->validateArchive($zip);
        if ($archiveError !== null) {
            $zip->close();
            @unlink($tempFile);
            $this->logSecurityAlert("Rejected unsafe update archive: {$archiveError}");
            return ['success' => false, 'error' => 'Unsafe update archive rejected: ' . $archiveError];
        }

        // The archive has been authenticated and path-validated before it can
        // write into the application directory.
        $zip->extractTo($this->appRoot);
        $zip->close();
        @unlink($tempFile);

        return [
            'success' => true,
            'message' => 'Update downloaded, cryptographically verified, and applied successfully.',
            'checksum' => $actualChecksum
        ];
    }

    private function logSecurityAlert(string $msg): void {
        error_log("[SECURITY ALERT][UpdateApplier] " . $msg);
    }

    private function validateArchive(ZipArchive $zip): ?string {
        $maxEntries = 5000;
        $maxUncompressedBytes = 512 * 1024 * 1024;
        if ($zip->numFiles > $maxEntries) {
            return 'archive contains too many files';
        }

        $totalBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = (string)($stat['name'] ?? '');
            $normalName = str_replace('\\', '/', $name);
            if ($normalName === '' || str_starts_with($normalName, '/') || preg_match('/^[A-Za-z]:\//', $normalName) || in_array('..', explode('/', $normalName), true)) {
                return 'archive contains an absolute or traversal path';
            }
            $totalBytes += (int)($stat['size'] ?? 0);
            if ($totalBytes > $maxUncompressedBytes) {
                return 'archive exceeds the 512 MB extraction limit';
            }

            if (method_exists($zip, 'getExternalAttributesIndex')) {
                $opsys = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                    $fileType = ($attributes >> 16) & 0170000;
                    if ($fileType === 0120000) {
                        return 'archive contains a symbolic link';
                    }
                }
            }
        }
        return null;
    }
}
