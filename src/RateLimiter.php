<?php
declare(strict_types=1);

namespace FleetHub;

use PDO;
use Throwable;

class RateLimiter {
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null) {
        $this->pdo = $pdo;
    }

    /**
     * Static helper to check and record an attempt in one call.
     * Returns true if rate limited (exceeded), false if allowed.
     */
    public static function isRateLimited(string $key, int $maxAttempts, int $decaySeconds, ?PDO $pdo = null): bool {
        $limiter = new self($pdo ?? (class_exists('FleetHub\Database') ? Database::getConnection() : null));
        if ($limiter->tooManyAttempts($key, $maxAttempts, $decaySeconds)) {
            return true;
        }
        $limiter->hit($key, $decaySeconds);
        return false;
    }

    /**
     * Check if an action is within limits. Returns true if allowed, false if rate limited.
     */
    public function tooManyAttempts(string $key, int $maxAttempts, int $decaySeconds): bool {
        $attempts = $this->getAttempts($key, $decaySeconds);
        return $attempts >= $maxAttempts;
    }

    /**
     * Record an attempt.
     */
    public function hit(string $key, int $decaySeconds): int {
        $now = time();
        $expiresAt = $now + $decaySeconds;

        if ($this->pdo) {
            try {
                $this->ensureTable();
                // Clean up expired records occasionally
                if (random_int(1, 20) === 1) {
                    $del = $this->pdo->prepare("DELETE FROM central_rate_limits WHERE expires_at < ?");
                    $del->execute([$now]);
                }

                $stmt = $this->pdo->prepare("INSERT INTO central_rate_limits (rate_key, created_at, expires_at) VALUES (?, ?, ?)");
                $stmt->execute([$key, $now, $expiresAt]);

                return $this->getAttempts($key, $decaySeconds);
            } catch (Throwable $e) {
                // Fall back to file-based
            }
        }

        return $this->fileHit($key, $decaySeconds);
    }

    /**
     * Clear all attempts for a key.
     */
    public function clear(string $key): void {
        if ($this->pdo) {
            try {
                $this->ensureTable();
                $stmt = $this->pdo->prepare("DELETE FROM central_rate_limits WHERE rate_key = ?");
                $stmt->execute([$key]);
            } catch (Throwable $e) {}
        }
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Get current count of active attempts.
     */
    public function getAttempts(string $key, int $decaySeconds): int {
        $now = time();
        if ($this->pdo) {
            try {
                $this->ensureTable();
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM central_rate_limits WHERE rate_key = ? AND expires_at >= ?");
                $stmt->execute([$key, $now]);
                return (int)$stmt->fetchColumn();
            } catch (Throwable $e) {}
        }

        return $this->fileGetAttempts($key, $decaySeconds);
    }

    private function ensureTable(): void {
        static $ensured = false;
        if ($ensured || !$this->pdo) {
            return;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS central_rate_limits (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    rate_key TEXT NOT NULL,
                    created_at INTEGER NOT NULL,
                    expires_at INTEGER NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_rate_limits_lookup ON central_rate_limits(rate_key, expires_at);
            ");
        } else {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS central_rate_limits (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    rate_key VARCHAR(191) NOT NULL,
                    created_at INT NOT NULL,
                    expires_at INT NOT NULL,
                    INDEX idx_rate_limits_lookup (rate_key, expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        }
        $ensured = true;
    }

    private function getFilePath(string $key): string {
        $dir = sys_get_temp_dir() . '/fleet_ratelimits';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    private function fileHit(string $key, int $decaySeconds): int {
        $file = $this->getFilePath($key);
        $now = time();
        $attempts = [];

        if (file_exists($file)) {
            $data = json_decode((string)file_get_contents($file), true) ?? [];
            foreach ($data as $t) {
                if ($t >= $now - $decaySeconds) {
                    $attempts[] = (int)$t;
                }
            }
        }

        $attempts[] = $now;
        @file_put_contents($file, json_encode($attempts), LOCK_EX);
        return count($attempts);
    }

    private function fileGetAttempts(string $key, int $decaySeconds): int {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return 0;
        }
        $now = time();
        $valid = 0;
        $data = json_decode((string)file_get_contents($file), true) ?? [];
        foreach ($data as $t) {
            if ($t >= $now - $decaySeconds) {
                $valid++;
            }
        }
        return $valid;
    }
}
