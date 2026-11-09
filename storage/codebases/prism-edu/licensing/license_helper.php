<?php
/**
 * Commercial Licensing Helper Engine
 * Validates domain binding, student limits, and subscription expiry.
 */
require_once dirname(__DIR__) . '/core/Database.php';

function checkSystemLicense(\PDO $pdo): array {
    try {
        $stmt = $pdo->prepare("SELECT * FROM system_license WHERE status = 'Active' ORDER BY license_id DESC LIMIT 1");
        $stmt->execute();
        $license = $stmt->fetch();

        // If no active license record exists, return invalid
        if (!$license) {
            return ['valid' => false, 'reason' => 'No active license found in database.'];
        }

        // 1. Expiration Check
        $today = date('Y-m-d');
        if ($today > $license['expiration_date']) {
            return ['valid' => false, 'reason' => 'Commercial License expired on ' . $license['expiration_date']];
        }

        // 2. Domain / Host Binding Check
        $current_host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        if ($license['domain_lock'] !== '*' && strpos($current_host, $license['domain_lock']) === false) {
            return ['valid' => false, 'reason' => 'Domain mismatch. Authorized for: ' . $license['domain_lock']];
        }

        // 3. Student Capacity Limit Check
        $countStmt = $pdo->query("SELECT COUNT(*) AS total FROM students WHERE is_deleted = 0");
        $active_students = $countStmt->fetch()['total'];

        if ($active_students >= $license['max_students']) {
            return [
                'valid' => false, 
                'reason' => 'Maximum capacity reached (' . $license['max_students'] . ' students). Upgrade license to proceed.'
            ];
        }

        return ['valid' => true, 'license' => $license, 'active_students' => $active_students];

    } catch (\PDOException $e) {
        return ['valid' => false, 'reason' => 'Licensing Validation Error: ' . $e->getMessage()];
    }
}
?>