<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';

use FleetHub\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This command must be run from the command line.\n");
}

$email = trim((string)($argv[1] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php bin/create_admin.php admin@example.com 'strong password' [name]\n");
}
$password = (string)($argv[2] ?? '');
if (strlen($password) < 12) {
    exit("The administrator password must be at least 12 characters.\n");
}
$name = trim((string)($argv[3] ?? 'System Administrator'));

$pdo = Database::getConnection();
$check = $pdo->prepare('SELECT id FROM central_users WHERE email = ?');
$check->execute([$email]);
if ($check->fetch()) {
    exit("An account with that email already exists.\n");
}
$stmt = $pdo->prepare('INSERT INTO central_users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
$stmt->execute([$name, $email, password_hash($password, PASSWORD_BCRYPT), 'admin']);
echo "Administrator account created.\n";
