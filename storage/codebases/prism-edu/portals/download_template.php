<?php
// Backward-compatible redirect to modular route
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: ../modules/Administration/download_template.php" . $qs);
exit();
