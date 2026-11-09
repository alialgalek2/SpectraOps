<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once dirname(__DIR__) . '/core/init.php';

// Normalize administrator roles so super_admin checks succeed for system administrators (admin / super_admin only)
if (isset($_SESSION['role']) && in_array(strtolower((string)$_SESSION['role']), ['admin', 'super_admin'], true)) {
    if (empty($_SESSION['user_role'])) {
        $_SESSION['user_role'] = 'super_admin';
    }
}
