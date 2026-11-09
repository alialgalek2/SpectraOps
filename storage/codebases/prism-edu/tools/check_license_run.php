<?php
require_once __DIR__ . '/../core/init.php';
require_once __DIR__ . '/../licensing/license_helper.php';

$_SERVER['HTTP_HOST'] = 'localhost:8080';
$res = checkSystemLicense($pdo);
echo "Result for localhost:8080:\n";
print_r($res);

$_SERVER['HTTP_HOST'] = 'localhost';
$res2 = checkSystemLicense($pdo);
echo "\nResult for localhost:\n";
print_r($res2);
