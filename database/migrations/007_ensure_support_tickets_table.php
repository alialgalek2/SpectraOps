<?php
declare(strict_types=1);

/**
 * Migration 007: Ensure central_support_tickets table exists with full index coverage
 * 
 * Guarantees support tickets table and foreign key cascade exists in both SQLite and MySQL
 * to prevent PDOException errors during deployment deletion and telemetry intake.
 */

$sqlitePaths = [
    dirname(__DIR__) . '/fleet.sqlite',
    dirname(__DIR__, 2) . '/central_fleet_db',
    dirname(__DIR__, 2) . '/public/api/v1/central_fleet_db'
];

foreach ($sqlitePaths as $p) {
    if (file_exists($p)) {
        try {
            $pdo = new PDO('sqlite:' . $p, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS central_support_tickets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    deployment_id INTEGER NOT NULL,
                    ticket_reference TEXT NOT NULL,
                    requester_name TEXT NOT NULL,
                    requester_email TEXT NOT NULL,
                    requester_role TEXT DEFAULT 'Staff',
                    subject TEXT NOT NULL,
                    category TEXT DEFAULT 'bug',
                    priority TEXT DEFAULT 'medium',
                    message TEXT NOT NULL,
                    system_snapshot TEXT,
                    status TEXT DEFAULT 'open',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (deployment_id) REFERENCES central_deployments(id) ON DELETE CASCADE
                );
            ");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ticket_status ON central_support_tickets(status);");
            $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ticket_deploy ON central_support_tickets(deployment_id);");
            echo "[SQLite] Ensured central_support_tickets table exists on {$p}\n";
        } catch (\Throwable $e) {
            echo "[SQLite Notice on {$p}] " . $e->getMessage() . "\n";
        }
    }
}

// MySQL Migration
try {
    $dsn = 'mysql:host=127.0.0.1;port=3306;dbname=central_fleet_db;charset=utf8mb4';
    $user = getenv('DB_USERNAME') ?: 'root';
    $pass = getenv('DB_PASSWORD') ?: '';

    $mysql = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $mysql->exec("
        CREATE TABLE IF NOT EXISTS central_support_tickets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            deployment_id INT NOT NULL,
            ticket_reference VARCHAR(32) NOT NULL,
            requester_name VARCHAR(191) NOT NULL,
            requester_email VARCHAR(191) NOT NULL,
            requester_role VARCHAR(64) DEFAULT 'Staff',
            subject VARCHAR(255) NOT NULL,
            category VARCHAR(64) DEFAULT 'bug',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            message TEXT NOT NULL,
            system_snapshot LONGTEXT,
            status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
            created_at DATETIME NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (deployment_id) REFERENCES central_deployments(id) ON DELETE CASCADE,
            INDEX idx_ticket_status (status),
            INDEX idx_ticket_deploy (deployment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "[MySQL] Ensured central_support_tickets table exists.\n";
} catch (\Throwable $e) {
    echo "[MySQL Notice] " . $e->getMessage() . "\n";
}
