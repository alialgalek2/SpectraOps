<?php
require_once __DIR__ . '/../core/init.php';

$stmt = $pdo->query("SELECT setting_key, setting_value FROM school_settings");
print_r($stmt->fetchAll(PDO::FETCH_KEY_PAIR));
