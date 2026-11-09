<?php
require_once __DIR__ . '/../core/init.php';
$rows = $pdo->query("SELECT log_id, error_message, file_path, line_number, sync_status, created_at FROM system_telemetry_logs WHERE sync_status = 'staged' AND error_level = 'fatal'")->fetchAll(PDO::FETCH_ASSOC);
echo "Count: " . count($rows) . "\n";
print_r($rows);
