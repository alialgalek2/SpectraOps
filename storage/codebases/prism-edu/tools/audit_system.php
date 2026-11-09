<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../core/init.php';

echo "================ SYSTEM AUDIT REPORT ================\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n\n";

// 1. Database Check
echo "--- 1. DATABASE TABLES & RECORD COUNTS ---\n";
try {
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Found " . count($tables) . " tables in `school_db`:\n";
    foreach ($tables as $t) {
        $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        echo "  - $t ($count rows)\n";
    }
} catch (Exception $e) {
    echo "Database query error: " . $e->getMessage() . "\n";
}

echo "\n--- 2. ACTIVE MODULES ---\n";
try {
    $modules = $pdo->query("SELECT * FROM modules")->fetchAll();
    foreach ($modules as $m) {
        echo "  - " . $m['module_name'] . " (" . ($m['is_active'] ? 'ACTIVE' : 'INACTIVE') . ")\n";
    }
} catch (Exception $e) {
    echo "Modules query error: " . $e->getMessage() . "\n";
}

echo "\n--- 3. USER ROLES & PERMISSIONS CHECK ---\n";
try {
    $roles = $pdo->query("SELECT role_id, role_name FROM roles")->fetchAll();
    echo "Defined Roles: " . implode(', ', array_column($roles, 'role_name')) . "\n";
    
    $userCount = $pdo->query("SELECT role, count(*) as cnt FROM users GROUP BY role")->fetchAll();
    foreach ($userCount as $uc) {
        echo "  - Users with role '{$uc['role']}': {$uc['cnt']}\n";
    }
} catch (Exception $e) {
    echo "Roles error: " . $e->getMessage() . "\n";
}

echo "\n--- 4. ENDPOINT CODE & INCLUDE AUDIT ---\n";
$allFiles = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(dirname(__DIR__), RecursiveDirectoryIterator::SKIP_DOTS)
);
foreach ($iter as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        if (strpos($path, 'vendor') === false && strpos($path, 'scratch') === false) {
            $allFiles[] = $path;
        }
    }
}

echo "Total PHP source files scanned: " . count($allFiles) . "\n";

$issues = [];
foreach ($allFiles as $file) {
    $content = file_get_contents($file);
    $rel = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file);

    // Check for raw unsafe $_GET/$_POST query concatenations
    if (preg_match('/\$pdo->query\([^)]*\$_(GET|POST|REQUEST)/i', $content)) {
        $issues[] = "[Potential SQL Injection] $rel: Direct concatenation in \$pdo->query()";
    }

    // Check for undefined deprecated functions like mysql_query
    if (preg_match('/\b(mysql_query|mysql_connect|split\(|ereg\()\b/i', $content, $m)) {
        $issues[] = "[Deprecated Function] $rel: {$m[1]}()";
    }

    // Check for unescaped output of raw GET/POST in echo
    if (preg_match('/echo\s+\$_(GET|POST|REQUEST)\[/i', $content)) {
        $issues[] = "[Potential XSS] $rel: Direct echo of user input without htmlspecialchars()";
    }
}

if (empty($issues)) {
    echo "No obvious SQL injection patterns, deprecated functions, or raw unescaped echos found.\n";
} else {
    echo "Found " . count($issues) . " warnings/issues:\n";
    foreach ($issues as $issue) {
        echo "  * $issue\n";
    }
}

echo "\n================ AUDIT COMPLETED ================\n";
