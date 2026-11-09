<?php
require_once __DIR__ . '/../test_http_endpoints.php';
$code = test_endpoint('http://localhost:8080/school_system/login.php');
echo "HTTP response code on port 8080 for login.php: " . $code . "\n";
