<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI access only\n");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../core/init.php';

echo "=== DEEP SYSTEM AND SCHEMA INTEGRITY CHECK ===\n\n";

// 1. Collect all DB tables and their columns
$dbSchema = [];
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    $cols = $pdo->query("DESCRIBE `$table`")->fetchAll();
    $dbSchema[$table] = array_column($cols, 'Field');
}

echo "Database contains " . count($dbSchema) . " tables.\n\n";

// 2. Scan all PHP files for table names in SQL statements
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

$missingTableRefs = [];
$suspiciousQueries = [];

foreach ($allFiles as $file) {
    $code = file_get_contents($file);
    $rel = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file);

    // Extract SQL queries matching FROM table, JOIN table, INTO table, UPDATE table
    if (preg_match_all('/\b(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i', $code, $matches)) {
        foreach ($matches[1] as $tbl) {
            $tblLower = strtolower($tbl);
            // Ignore SQL keywords that might be caught
            if (in_array($tblLower, ['select', 'where', 'set', 'values', 'order', 'group', 'limit', 'as', 'on', 'dual', 'table', 'null'])) {
                continue;
            }
            if (!isset($dbSchema[$tblLower]) && !isset($dbSchema[$tbl])) {
                $missingTableRefs[] = [
                    'file' => $rel,
                    'table' => $tbl
                ];
            }
        }
    }
}

echo "--- A. SQL TABLE REFERENCES AUDIT ---\n";
if (empty($missingTableRefs)) {
    echo "All table references across all PHP files exist in `school_db`.\n";
} else {
    echo "Found " . count($missingTableRefs) . " references to tables not in database:\n";
    $seen = [];
    foreach ($missingTableRefs as $ref) {
        $key = $ref['file'] . ' -> ' . $ref['table'];
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            echo "  * {$ref['file']} references missing table `{$ref['table']}`\n";
        }
    }
}

echo "\n--- B. REQUIRE / INCLUDE PATH INTEGRITY ---\n";
$brokenRequires = [];
foreach ($allFiles as $file) {
    $code = file_get_contents($file);
    $rel = str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $file);
    $dir = dirname($file);

    if (preg_match_all('/\b(?:require|require_once|include|include_once)\s*\(?\s*[\'"]([^\'"]+)[\'"]\s*\)?/i', $code, $matches)) {
        foreach ($matches[1] as $inc) {
            if (strpos($inc, '$') === false) { // static path string
                $resolved = realpath($dir . '/' . $inc);
                if (!$resolved || !file_exists($resolved)) {
                    // Check if it's relative to root
                    $resolvedRoot = realpath(dirname(__DIR__) . '/' . ltrim($inc, '/'));
                    if (!$resolvedRoot || !file_exists($resolvedRoot)) {
                        $brokenRequires[] = "{$rel} requires missing static path '{$inc}'";
                    }
                }
            }
        }
    }
}

if (empty($brokenRequires)) {
    echo "All static require/include paths resolve correctly.\n";
} else {
    echo "Found " . count($brokenRequires) . " broken static requires:\n";
    foreach ($brokenRequires as $br) {
        echo "  * $br\n";
    }
}

echo "\n--- C. ROLE PERMISSIONS & ACCESS MATRIX AUDIT ---\n";
$roles = ['admin', 'school_admin', 'teacher', 'finance', 'hr', 'student', 'parent'];
$testKeys = [
    'view_finances', 'manage_payroll', 'manage_hr', 'manage_grades', 
    'manage_classes', 'manage_admissions', 'manage_exams', 'staff_leaves',
    'view_timetable', 'view_grades', 'pay_fees'
];

echo str_pad("Role", 15) . " | " . implode(' | ', array_map(function($k) { return substr($k, 0, 10); }, $testKeys)) . "\n";
echo str_repeat("-", 120) . "\n";

foreach ($roles as $r) {
    $_SESSION['role'] = $r;
    $_SESSION['user_id'] = 9999;
    $row = str_pad($r, 15) . " | ";
    $results = [];
    foreach ($testKeys as $k) {
        $allowed = has_permission($k);
        $results[] = str_pad($allowed ? "YES" : "NO", 10);
    }
    $row .= implode(' | ', $results);
    echo $row . "\n";
}

echo "\n=== DIAGNOSTIC RUN COMPLETE ===\n";
