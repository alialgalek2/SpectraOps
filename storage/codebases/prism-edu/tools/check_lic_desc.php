<?php
require_once __DIR__ . '/../core/init.php';
$lics = $pdo->query("SELECT * FROM system_license")->fetchAll(PDO::FETCH_ASSOC);
print_r($lics);


