<?php
// Backward-compatible redirect to modular route
$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
header("Location: ../modules/Administration/add_user.php" . $qs);
exit();
