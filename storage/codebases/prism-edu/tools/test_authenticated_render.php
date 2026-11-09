<?php
$loginUrl = 'http://localhost:8080/school_system/login.php';
$cookieFile = __DIR__ . '/cookie.txt';
if (file_exists($cookieFile)) unlink($cookieFile);

// Let's check a user with admin role to login
require_once __DIR__ . '/../config/db.php';
$pdo = getPDO();
$user = $pdo->query("SELECT username FROM users WHERE role = 'admin' AND is_deleted = 0 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "Admin user in DB: " . ($user['username'] ?? 'None') . "\n";

// Alternatively, test by initializing session directly in a mock runner:
foreach ([
    'fee_structures.php',
    'discounts.php',
    'clearance.php',
    'daily_settlement.php',
    'index.php'
] as $file) {
    // Run php-cgi or execute within mock session
    $cmd = "c:\\xampp\\php\\php.exe -c c:\\xampp\\php\\php.ini -r \"
        session_start();
        \$_SESSION['user_id'] = 1;
        \$_SESSION['role'] = 'admin';
        \$_SESSION['full_name'] = 'System Administrator';
        \$_SERVER['REQUEST_METHOD'] = 'GET';
        \$_SERVER['SCRIPT_FILENAME'] = 'c:\\\\xampp\\\\htdocs\\\\school_system\\\\modules\\\\Finance\\\\" . $file . "';
        \$_SERVER['PHP_SELF'] = '/school_system/modules/Finance/" . $file . "';
        ob_start();
        include 'modules/Finance/" . $file . "';
        \$out = ob_get_clean();
        echo '" . $file . " rendered: ' . strlen(\$out) . ' bytes | Title match: ' . (preg_match('/<title>(.*?)<\/title>/', \$out, \$m) ? \$m[1] : 'No title') . PHP_EOL;
    \"";
    $output = shell_exec($cmd);
    echo $output;
}
