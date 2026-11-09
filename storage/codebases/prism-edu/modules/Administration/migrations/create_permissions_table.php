<?php
// modules/Administration/migrations/create_permissions_table.php
require_once __DIR__ . '/../../../core/init.php';

try {
    // Permission Matrix Table: stores specific module/feature toggles per user
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_permissions (
        permission_id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        feature_key VARCHAR(100) NOT NULL, -- e.g., 'manage_admissions', 'view_finances', 'edit_grades', 'manage_hr'
        is_allowed TINYINT(1) DEFAULT 0, -- 1 for allowed, 0 for blocked
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY user_feature_unique (user_id, feature_key),
        FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    echo "[+] User Permissions table created successfully!\n";
} catch (Exception $e) {
    echo "[-] Error creating permissions table: " . $e->getMessage() . "\n";
}
