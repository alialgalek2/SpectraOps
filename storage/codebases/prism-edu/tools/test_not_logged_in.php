<?php
require_once __DIR__ . '/../core/init.php';

echo "Testing when NOT logged in:\n";
$_SESSION = [];
ob_start();
try {
    include __DIR__ . '/../modules/LMS/assignments.php';
} catch (Throwable $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}
$out = ob_get_clean();
echo "Headers sent or exit called. Output length: " . strlen($out) . " bytes\n";
echo "Output:\n" . substr($out, 0, 300) . "\n";
