<?php
// Backward-compatible redirect to modular route
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: ../modules/Administration/data_migration.php" . $qs);
exit();
