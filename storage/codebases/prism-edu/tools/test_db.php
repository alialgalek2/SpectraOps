<?php
// Phase 1 Verification Script
require_once __DIR__ . '/core/Database.php';
$pdo = Database::getConnection();

echo "<h2 style='color: green;'>Phase 1 Architecture Verified!</h2>";
echo "<p>PHP is executing, project paths are intact, and PDO database drivers are loaded.</p>";
?>