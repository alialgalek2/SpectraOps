<?php
$baseUrl = 'http://localhost:8080/school_system/';
$cookieFile = __DIR__ . '/../cookie_admin.txt';

$endpoints = [
    'modules/Administration/index.php',
    'modules/Administration/institution_settings.php',
    'modules/Administration/academic_hierarchy.php',
    'modules/Administration/admissions.php',
    'modules/Administration/student_dossier.php',
    'modules/Administration/households.php',
    'modules/Administration/attendance_desk.php',
    'modules/Administration/behavior.php',
    'modules/Administration/security_audit.php',
    'modules/Administration/rollover.php',
    'modules/Administration/tc_print.php?student_id=1'
];

echo "=========================================================\n";
echo "HTTP ENDPOINTS VERIFICATION - ADMIN CENTER\n";
echo "=========================================================\n\n";

$allPassed = true;

foreach ($endpoints as $ep) {
    $url = $baseUrl . $ep;
    $cmd = 'curl.exe -s -b "' . $cookieFile . '" "' . $url . '"';
    $output = [];
    $ret = 0;
    exec($cmd, $output, $ret);
    
    $html = implode("\n", $output);
    $cmdCode = 'curl.exe -s -o NUL -w "%{http_code}" -b "' . $cookieFile . '" "' . $url . '"';
    $code = (int)exec($cmdCode);

    $hasFatal = (stripos($html, 'Fatal error') !== false || stripos($html, 'Parse error') !== false || stripos($html, 'Uncaught ') !== false);

    if ($code === 200 && !$hasFatal) {
        echo "  [PASS] ($code) $ep\n";
    } else {
        echo "  [FAIL] ($code) $ep - " . ($hasFatal ? "HAS PHP FATAL ERROR" : "Non-200 status") . "\n";
        if ($hasFatal) {
            preg_match('/(Fatal error[^\n<]+)/i', $html, $m);
            echo "         Detail: " . ($m[1] ?? 'Unknown error') . "\n";
        }
        $allPassed = false;
    }
}

echo "\n=========================================================\n";
if ($allPassed) {
    echo "ALL 11 ENDPOINTS RETURNED HTTP 200 WITH ZERO FATAL ERRORS!\n";
    exit(0);
} else {
    echo "SOME ENDPOINTS FAILED!\n";
    exit(1);
}
