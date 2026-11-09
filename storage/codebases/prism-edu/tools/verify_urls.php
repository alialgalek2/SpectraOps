<?php
$urls = [
    'http://localhost:8080/school_system/modules/Finance/fee_structures.php',
    'http://localhost:8080/school_system/modules/Finance/discounts.php',
    'http://localhost:8080/school_system/modules/Finance/clearance.php',
    'http://localhost:8080/school_system/modules/Finance/daily_settlement.php',
    'http://localhost:8080/school_system/modules/Finance/index.php'
];

foreach ($urls as $url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    echo "$url => HTTP $httpCode, Bytes: " . strlen($response);
    if ($err) echo " (cURL Error: $err)";
    echo "\n";
}
