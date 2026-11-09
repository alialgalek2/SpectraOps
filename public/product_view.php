<?php
declare(strict_types=1);

// If action POST or deletion GET is sent to product_view.php, delegate directly to dashboard.php
$hasDeleteAction = isset($_GET['delete_deployment']) 
    || isset($_GET['delete_error']) 
    || isset($_GET['purge_resolved_errors']) 
    || isset($_GET['delete_ticket']) 
    || isset($_GET['delete_release']) 
    || isset($_GET['delete_product']) 
    || isset($_GET['delete_license']) 
    || isset($_GET['delete_admin_user']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' || $hasDeleteAction) {
    require __DIR__ . '/dashboard.php';
    exit;
}

// Keep bookmarks working while routing through the single authenticated operator console
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$status = isset($_GET['status']) ? (string)$_GET['status'] : '';
$target = 'dashboard.php?page=' . (($status === 'patch_approved' || $status === 'patch_published') ? 'releases' : 'overview');
if ($id > 0) {
    $target .= '&product_id=' . $id;
}
if ($status !== '') {
    $target .= '&status=' . urlencode($status);
}

header('Location: ' . $target, true, 302);
exit;
