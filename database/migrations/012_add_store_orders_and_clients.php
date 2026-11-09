<?php
declare(strict_types=1);

/**
 * Migration 012: Store Orders and Client Ingress Pipeline
 */

return function (PDO $pdo): void {
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        // 1. Client Accounts
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS central_clients (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                client_code TEXT UNIQUE NOT NULL,
                business_name TEXT NOT NULL,
                contact_name TEXT NOT NULL,
                email TEXT UNIQUE NOT NULL,
                phone TEXT DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clients_email ON central_clients(email);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_clients_code ON central_clients(client_code);");

        // 2. Ingress Orders & Provisioning
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS central_orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id TEXT UNIQUE NOT NULL,
                client_id INTEGER DEFAULT NULL,
                customer_name TEXT NOT NULL,
                customer_email TEXT NOT NULL,
                business_name TEXT NOT NULL,
                purchased_product TEXT NOT NULL DEFAULT 'VENDPULSE',
                usage_mode TEXT NOT NULL DEFAULT 'RETAIL_SHOP',
                term_duration_months INTEGER DEFAULT NULL,
                max_counters INTEGER NOT NULL DEFAULT 1,
                include_installer_bundle INTEGER NOT NULL DEFAULT 1,
                license_id TEXT DEFAULT NULL,
                download_token TEXT UNIQUE NOT NULL,
                token_expires_at DATETIME DEFAULT NULL,
                download_count INTEGER NOT NULL DEFAULT 0,
                last_download_at DATETIME DEFAULT NULL,
                status TEXT NOT NULL DEFAULT 'completed',
                raw_payload TEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES central_clients(id) ON DELETE SET NULL
            );
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_order_id ON central_orders(order_id);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_download_token ON central_orders(download_token);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_customer_email ON central_orders(customer_email);");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_orders_license_id ON central_orders(license_id);");
    } else {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS central_clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_code VARCHAR(64) UNIQUE NOT NULL,
                business_name VARCHAR(191) NOT NULL,
                contact_name VARCHAR(191) NOT NULL,
                email VARCHAR(191) UNIQUE NOT NULL,
                phone VARCHAR(64) DEFAULT NULL,
                status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_clients_email (email),
                INDEX idx_clients_code (client_code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS central_orders (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id VARCHAR(128) UNIQUE NOT NULL,
                client_id INT DEFAULT NULL,
                customer_name VARCHAR(191) NOT NULL,
                customer_email VARCHAR(191) NOT NULL,
                business_name VARCHAR(191) NOT NULL,
                purchased_product VARCHAR(64) NOT NULL DEFAULT 'VENDPULSE',
                usage_mode VARCHAR(64) NOT NULL DEFAULT 'RETAIL_SHOP',
                term_duration_months INT DEFAULT NULL,
                max_counters INT NOT NULL DEFAULT 1,
                include_installer_bundle TINYINT(1) NOT NULL DEFAULT 1,
                license_id VARCHAR(64) DEFAULT NULL,
                download_token VARCHAR(128) UNIQUE NOT NULL,
                token_expires_at DATETIME DEFAULT NULL,
                download_count INT NOT NULL DEFAULT 0,
                last_download_at DATETIME DEFAULT NULL,
                status ENUM('completed', 'pending', 'refunded', 'cancelled') NOT NULL DEFAULT 'completed',
                raw_payload LONGTEXT DEFAULT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (client_id) REFERENCES central_clients(id) ON DELETE SET NULL,
                INDEX idx_orders_order_id (order_id),
                INDEX idx_orders_download_token (download_token),
                INDEX idx_orders_customer_email (customer_email),
                INDEX idx_orders_license_id (license_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
};
