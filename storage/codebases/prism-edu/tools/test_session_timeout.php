<?php
session_start();
$_SESSION['user_id'] = 123;
$_SESSION['last_activity'] = time() - 400;

require_once __DIR__ . '/../core/Auth.php';
$checked = Auth::check();
echo "Checked result: " . ($checked ? 'true' : 'false') . "\n";
echo "Session contents:\n";
var_dump($_SESSION);
echo "Headers list:\n";
print_r(headers_list());
