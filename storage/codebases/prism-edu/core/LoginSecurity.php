<?php
/**
 * Core Login Security & Rate Limiting Engine
 * 
 * Enforces brute-force protection:
 * - 5 max failed password attempts per account/IP
 * - 5-minute (300s) cooldown lockout after 5 consecutive failures
 * - Automated table schema generation and history rotation
 */

class LoginSecurity {

    const MAX_ATTEMPTS = 5;
    const LOCKOUT_SECONDS = 300; // 5 minutes in seconds

    private static bool $tableInitialized = false;

    /**
     * Ensure login_attempts table is ready
     */
    public static function ensureTableExists(): void {
        if (self::$tableInitialized) return;

        global $pdo;
        if (!$pdo) return;

        try {
            $sql = "CREATE TABLE IF NOT EXISTS `login_attempts` (
                `attempt_id` BIGINT AUTO_INCREMENT PRIMARY KEY,
                `identifier` VARCHAR(191) NOT NULL,
                `ip_address` VARCHAR(45) NOT NULL,
                `attempt_time` INT UNSIGNED NOT NULL,
                `is_success` TINYINT(1) NOT NULL DEFAULT 0,
                INDEX `idx_ident_time` (`identifier`, `attempt_time`),
                INDEX `idx_ip_time` (`ip_address`, `attempt_time`),
                INDEX `idx_attempt_time` (`attempt_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
            $pdo->exec($sql);
            self::$tableInitialized = true;
        } catch (\Throwable $e) {
            // Fail silently or handle
        }
    }

    /**
     * Check if an identifier (username/email) or client IP is currently locked out
     * 
     * @param string $identifier
     * @param string $ip
     * @return array
     */
    public static function checkLockout(string $identifier, string $ip): array {
        self::ensureTableExists();
        global $pdo;

        $identifier = strtolower(trim($identifier));
        $ip = trim($ip);
        $windowStart = time() - self::LOCKOUT_SECONDS;

        if (!$pdo) {
            return [
                'is_locked'         => false,
                'remaining_seconds' => 0,
                'attempts_left'     => self::MAX_ATTEMPTS,
                'failed_count'      => 0,
                'message_en'        => '',
                'message_ar'        => ''
            ];
        }

        try {
            // Find failed attempts within the 5-minute sliding window
            if (!empty($identifier)) {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as failed_count, MAX(attempt_time) as last_attempt_time
                    FROM login_attempts
                    WHERE (identifier = ? OR ip_address = ?)
                      AND attempt_time >= ?
                      AND is_success = 0
                ");
                $stmt->execute([$identifier, $ip, $windowStart]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as failed_count, MAX(attempt_time) as last_attempt_time
                    FROM login_attempts
                    WHERE ip_address = ?
                      AND attempt_time >= ?
                      AND is_success = 0
                ");
                $stmt->execute([$ip, $windowStart]);
            }
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $failedCount = (int)($row['failed_count'] ?? 0);
            $lastAttemptTime = (int)($row['last_attempt_time'] ?? 0);

            if ($failedCount >= self::MAX_ATTEMPTS && $lastAttemptTime > 0) {
                $elapsed = time() - $lastAttemptTime;
                if ($elapsed < self::LOCKOUT_SECONDS) {
                    $remaining = self::LOCKOUT_SECONDS - $elapsed;
                    return [
                        'is_locked'         => true,
                        'remaining_seconds' => $remaining,
                        'attempts_left'     => 0,
                        'failed_count'      => $failedCount,
                        'message_en'        => "Too many failed login attempts (5/5). Access locked. Please wait " . self::formatDuration($remaining, false) . " before trying again.",
                        'message_ar'        => "تم تجاوز الحد الأقصى لمحاولات الدخول (5 محاولات). تم حظر الدخول مؤقتاً. يرجى الانتظار " . self::formatDuration($remaining, true) . " قبل إعادة المحاولة."
                    ];
                }
            }

            $attemptsLeft = max(0, self::MAX_ATTEMPTS - $failedCount);
            return [
                'is_locked'         => false,
                'remaining_seconds' => 0,
                'attempts_left'     => $attemptsLeft,
                'failed_count'      => $failedCount,
                'message_en'        => '',
                'message_ar'        => ''
            ];
        } catch (\Throwable $e) {
            return [
                'is_locked'         => false,
                'remaining_seconds' => 0,
                'attempts_left'     => self::MAX_ATTEMPTS,
                'failed_count'      => 0,
                'message_en'        => '',
                'message_ar'        => ''
            ];
        }
    }

    /**
     * Record a failed login attempt
     * 
     * @param string $identifier
     * @param string $ip
     */
    public static function recordFailedAttempt(string $identifier, string $ip): void {
        self::ensureTableExists();
        global $pdo;
        if (!$pdo) return;

        $identifier = strtolower(trim($identifier));
        $ip = trim($ip);
        $now = time();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO login_attempts (identifier, ip_address, attempt_time, is_success)
                VALUES (?, ?, ?, 0)
            ");
            $stmt->execute([$identifier, $ip, $now]);

            // Clean up old attempts older than 24 hours to keep table fast
            $pruneBefore = $now - 86400;
            $pdo->prepare("DELETE FROM login_attempts WHERE attempt_time < ?")->execute([$pruneBefore]);

            // Check if this failure triggered lockout
            $lockout = self::checkLockout($identifier, $ip);
            if ($lockout['is_locked']) {
                // Log security audit event if audit_logs table exists
                try {
                    $auditStmt = $pdo->prepare("
                        INSERT INTO audit_logs (action, table_affected, record_id, details, ip_address, created_at)
                        VALUES ('LOGIN_LOCKOUT_TRIGGERED', 'users', NULL, ?, ?, NOW())
                    ");
                    $auditDetails = "Brute-force lockout triggered (5 failed attempts) for user '{$identifier}' from IP {$ip}. Locked for 5 minutes.";
                    $auditStmt->execute([$auditDetails, $ip]);
                } catch (\Throwable $ae) {}
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Record a successful login and reset failed attempts
     * 
     * @param string $identifier
     * @param string $ip
     */
    public static function recordSuccessfulLogin(string $identifier, string $ip): void {
        self::ensureTableExists();
        global $pdo;
        if (!$pdo) return;

        $identifier = strtolower(trim($identifier));
        $ip = trim($ip);

        try {
            // Delete recent failed attempts for this user and IP so attempts are reset to 5
            $stmt = $pdo->prepare("
                DELETE FROM login_attempts 
                WHERE (identifier = ? OR ip_address = ?)
            ");
            $stmt->execute([$identifier, $ip]);
        } catch (\Throwable $e) {}
    }

    /**
     * Format seconds into human readable bilingual string
     * 
     * @param int $seconds
     * @param bool $isRtl
     * @return string
     */
    public static function formatDuration(int $seconds, bool $isRtl = false): string {
        $minutes = floor($seconds / 60);
        $remainingSecs = $seconds % 60;

        if ($isRtl) {
            if ($minutes > 0 && $remainingSecs > 0) {
                return "{$minutes} دقيقة و {$remainingSecs} ثانية";
            } elseif ($minutes > 0) {
                return "{$minutes} دقيقة";
            } else {
                return "{$remainingSecs} ثانية";
            }
        } else {
            if ($minutes > 0 && $remainingSecs > 0) {
                $minStr = $minutes . ($minutes === 1 ? ' minute' : ' minutes');
                $secStr = $remainingSecs . ($remainingSecs === 1 ? ' second' : ' seconds');
                return "{$minStr} and {$secStr}";
            } elseif ($minutes > 0) {
                return $minutes . ($minutes === 1 ? ' minute' : ' minutes');
            } else {
                return $remainingSecs . ($remainingSecs === 1 ? ' second' : ' seconds');
            }
        }
    }
}
