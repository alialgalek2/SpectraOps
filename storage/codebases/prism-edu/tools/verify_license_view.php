<?php
require_once __DIR__ . '/../core/init.php';
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
$_SESSION['username'] = 'admin';

// Test 1: Admin Index default
ob_start();
$_GET = [];
include __DIR__ . '/../modules/Administration/index.php';
$out1 = ob_get_clean();

$hasCardLink = strpos($out1, 'href="license_details.php"') !== false;
$hasActionTitle = strpos($out1, 'License Details') !== false || strpos($out1, 'تفاصيل الترخيص') !== false;
echo 'Index default - Has license_details.php link: ' . ($hasCardLink ? 'YES' : 'NO') . ', Has action title: ' . ($hasActionTitle ? 'YES' : 'NO') . PHP_EOL;

// Test 2: Dedicated license_details.php page
ob_start();
$_GET = [];
include __DIR__ . '/../modules/Administration/license_details.php';
$out2 = ob_get_clean();

$hasDetailsPage = strpos($out2, 'Commercial License & Subscription Details') !== false || strpos($out2, 'تفاصيل الترخيص') !== false;
$hasCapacityMeter = strpos($out2, 'Enrolled Student Capacity Meter') !== false || strpos($out2, 'مقياس استيعاب') !== false;
$hasKeyForm = strpos($out2, 'update_license') !== false;
echo 'license_details.php - Dedicated Page: ' . ($hasDetailsPage ? 'YES' : 'NO') . ', Capacity Meter: ' . ($hasCapacityMeter ? 'YES' : 'NO') . ', KeyForm: ' . ($hasKeyForm ? 'YES' : 'NO') . PHP_EOL;

// Test 3: license_activate.php
ob_start();
$_GET = [];
include __DIR__ . '/../modules/Administration/license_activate.php';
$out3 = ob_get_clean();
$hasWizard = strpos($out3, 'Commercial Licensing') !== false || strpos($out3, 'تفعيل ترخيص') !== false;
echo 'license_activate.php - Wizard: ' . ($hasWizard ? 'YES' : 'NO') . PHP_EOL;

