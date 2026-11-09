<?php
declare(strict_types=1);

// Legacy URL retained for bookmarks. Release publishing is handled by the
// authenticated dashboard, which uses the current central_releases schema.
header('Location: ../dashboard.php?page=releases', true, 302);
exit;
