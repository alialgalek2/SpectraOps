<?php
require __DIR__ . '/../core/init.php';
$stmt = $pdo->query("SELECT * FROM system_telemetry_errors ORDER BY id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
