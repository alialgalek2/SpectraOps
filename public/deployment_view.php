<?php
declare(strict_types=1);

$depId = isset($_GET['deployment_id']) ? (int)$_GET['deployment_id'] : (int)($_GET['id'] ?? 0);
$loc = 'dashboard.php?page=deployments' . ($depId > 0 ? '&deployment_id=' . $depId : '');
header('Location: ' . $loc, true, 302);
exit;
