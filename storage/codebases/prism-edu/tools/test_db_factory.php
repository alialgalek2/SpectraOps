<?php
require 'config/Database.php';
$pdo1 = \App\Config\Database::getConnection();
echo 'App\Config\Database: ' . get_class($pdo1) . ' | Emulate prepares: ' . ($pdo1->getAttribute(PDO::ATTR_EMULATE_PREPARES) ? 'true' : 'false') . "\n";

require 'core/Database.php';
$pdo2 = Database::getConnection();
echo 'Database::getConnection(): ' . ($pdo1 === $pdo2 ? 'Identical Instance (OK)' : 'Different') . "\n";
