<?php
$urls = [
    'http://localhost:8080/school_system/modules/LMS/assignments.php',
    'http://localhost/school_system/modules/LMS/assignments.php',
    'http://127.0.0.1:8080/school_system/modules/LMS/assignments.php',
    'http://127.0.0.1/school_system/modules/LMS/assignments.php'
];

foreach ($urls as $u) {
    $ch = curl_init($u);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    echo "$u => HTTP $code (Error: $err)\n";
    if ($res) {
        echo substr($res, 0, 300) . "\n";
    }
    echo "---\n";
}
