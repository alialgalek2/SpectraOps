<?php
/**
 * Auth Logout Endpoint Fallback
 * Redirects to the central logout engine.
 */
require_once __DIR__ . '/../core/init.php';
$baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
header('Location: ' . $baseUrl . '/logout.php');
exit;
