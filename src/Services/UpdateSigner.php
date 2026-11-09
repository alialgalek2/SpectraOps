<?php
declare(strict_types=1);

namespace FleetHub\Services;

use RuntimeException;
use InvalidArgumentException;

class UpdateSigner {
    private ?string $privateKey = null;
    private ?string $publicKey = null;

    public function __construct(?string $privateKey = null, ?string $publicKey = null) {
        $this->privateKey = $privateKey ?? $this->loadKeyFile('release_private.pem');
        $this->publicKey = $publicKey ?? $this->loadKeyFile('release_public.pem');
    }

    /**
     * Sign a release package's SHA-256 checksum with the offline private key.
     * Returns base64-encoded signature.
     */
    public function signChecksum(string $checksum): string {
        $checksum = trim($checksum);
        if ($checksum === '') {
            throw new InvalidArgumentException("Checksum cannot be empty.");
        }
        if (!$this->privateKey) {
            throw new RuntimeException("Private key is not configured for signing updates.");
        }

        $signature = '';
        $binarySuccess = openssl_sign($checksum, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        if (!$binarySuccess) {
            throw new RuntimeException("OpenSSL signature generation failed: " . (openssl_error_string() ?: 'unknown error'));
        }

        return base64_encode($signature);
    }

    /**
     * Helper to compute SHA-256 and sign package file directly.
     */
    public function signPackage(string $filePath): string {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("Package file does not exist: {$filePath}");
        }
        $checksum = hash_file('sha256', $filePath);
        return $this->signChecksum($checksum);
    }

    /**
     * Verify a release package's SHA-256 checksum against a cryptographic signature.
     */
    public function verifyChecksum(string $checksum, string $signatureBase64): bool {
        $checksum = trim($checksum);
        $signature = base64_decode($signatureBase64, true);
        if ($checksum === '' || $signature === false) {
            return false;
        }
        if (!$this->publicKey) {
            throw new RuntimeException("Public key is not configured for verifying update signatures.");
        }

        $result = openssl_verify($checksum, $signature, $this->publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }

    /**
     * Helper to compute SHA-256 and verify package file directly.
     */
    public function verifyPackage(string $filePath, string $signatureBase64): bool {
        if (!file_exists($filePath)) {
            return false;
        }
        $checksum = hash_file('sha256', $filePath);
        return $this->verifyChecksum($checksum, $signatureBase64);
    }

    private function loadKeyFile(string $filename): ?string {
        $path = dirname(__DIR__, 2) . '/keys/' . $filename;
        if (file_exists($path)) {
            return file_get_contents($path);
        }
        return null;
    }
}
