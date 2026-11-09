<?php
require_once __DIR__ . '/../core/init.php';
$baseUrl = defined('BASE_URL') ? BASE_URL : '/school_system';
header('Location: ' . $baseUrl . '/modules/Administration/institution_settings.php');
exit;
