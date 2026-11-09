<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Test with teacher role
echo "=== TESTING WITH TEACHER ROLE ===\n";
$_SESSION = [
    'user_id' => 2, // let's check what user exists
    'role' => 'teacher',
    'full_name' => 'Teacher User',
    'username' => 'teacher'
];

ob_start();
include __DIR__ . '/../modules/LMS/assignments.php';
$out = ob_get_clean();
echo "Rendered length: " . strlen($out) . " bytes\n";
echo "First 200 chars:\n" . substr($out, 0, 200) . "\n...\n";
echo "Last 200 chars:\n..." . substr($out, -200) . "\n";
