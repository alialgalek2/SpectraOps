<?php
// modules/Administration/migrations/create_branding_table.php
require_once __DIR__ . '/../../../core/init.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS institution_branding (
        branding_id INT AUTO_INCREMENT PRIMARY KEY,
        school_name VARCHAR(150) NOT NULL,
        school_email VARCHAR(100) NULL,
        school_phone VARCHAR(50) NULL,
        school_website VARCHAR(255) NULL,
        school_address TEXT NULL,
        logo_path VARCHAR(255) NULL,
        primary_color VARCHAR(20) DEFAULT '#4F46E5',
        secondary_color VARCHAR(20) DEFAULT '#18181B',
        accent_color VARCHAR(20) DEFAULT '#10B981',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Insert default row if table is empty
    $count = (int)$pdo->query("SELECT COUNT(*) FROM institution_branding")->fetchColumn();
    if ($count === 0) {
        // Fetch existing school_name or logo if available
        $s_name = 'Algale Academy';
        $s_logo = '';
        try {
            $stmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings");
            while ($row = $stmt->fetch()) {
                if ($row['setting_key'] === 'school_name' && !empty($row['setting_value'])) $s_name = $row['setting_value'];
                if ($row['setting_key'] === 'school_logo' && !empty($row['setting_value'])) $s_logo = $row['setting_value'];
            }
        } catch (Exception $e) {}

        $stmt = $pdo->prepare("INSERT INTO institution_branding (school_name, logo_path, primary_color, secondary_color, accent_color) VALUES (?, ?, '#4F46E5', '#18181B', '#10B981')");
        $stmt->execute([$s_name, $s_logo]);
    }

    echo "Institution branding and dynamic theme table created successfully!\n";
} catch (Exception $e) {
    echo "Error creating branding table: " . $e->getMessage() . "\n";
}
