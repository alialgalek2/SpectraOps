<?php
declare(strict_types=1);

namespace FleetHub;

use PDO;
use RuntimeException;

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::loadEnv();

        $connection = strtolower((string)(getenv('DB_CONNECTION') ?: 'sqlite'));

        if ($connection === 'sqlite') {
            if (!extension_loaded('pdo_sqlite')) {
                // Attempt dynamic extension load if possible
                if (function_exists('dl')) {
                    @dl('php_pdo_sqlite.dll');
                }
                if (!extension_loaded('pdo_sqlite')) {
                    throw new RuntimeException("The 'pdo_sqlite' extension is missing or disabled in php.ini. Please enable it.");
                }
            }

            $rawDb = (string)(getenv('DB_DATABASE') ?: '');
            if ($rawDb !== '' && (str_contains($rawDb, '/') || str_contains($rawDb, '\\') || str_ends_with($rawDb, '.sqlite') || str_ends_with($rawDb, '.db'))) {
                $dbPath = $rawDb;
            } else {
                $dbPath = dirname(__DIR__) . '/database/fleet.sqlite';
            }
            $isNew = !file_exists($dbPath);
            
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $dsn = "sqlite:" . $dbPath;
            $pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $pdo->exec("PRAGMA foreign_keys = ON;");
            $pdo->exec("PRAGMA journal_mode = WAL;");
            $pdo->exec("PRAGMA busy_timeout = 5000;");
            $pdo->exec("PRAGMA synchronous = NORMAL;");

            if ($isNew) {
                $schemaFile = dirname(__DIR__) . '/database/schema_sqlite.sql';
                if (file_exists($schemaFile)) {
                    $pdo->exec(file_get_contents($schemaFile));
                }
            }

            self::ensureCoreTables($pdo);
            self::$instance = $pdo;
            return self::$instance;
        }

        // MySQL / MariaDB connection
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $database = getenv('DB_DATABASE') ?: 'central_fleet_db';
        $username = getenv('DB_USERNAME') ?: 'fleet_user';
        $password = getenv('DB_PASSWORD') ?: '';
        $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset={$charset}";

        try {
            self::$instance = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE utf8mb4_unicode_ci",
            ]);
            self::ensureCoreTables(self::$instance);
        } catch (\PDOException $e) {
            throw new RuntimeException("Fleet Database connection error: " . $e->getMessage(), (int)$e->getCode(), $e);
        }

        return self::$instance;
    }

    private static function ensureCoreTables(PDO $pdo): void {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS central_users (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        name TEXT NOT NULL,
                        email TEXT UNIQUE NOT NULL,
                        password_hash TEXT NOT NULL,
                        role TEXT NOT NULL DEFAULT 'admin',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                ");
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
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS central_releases (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        product_id INTEGER NOT NULL,
                        version TEXT NOT NULL,
                        changelog TEXT DEFAULT NULL,
                        package_filename TEXT NOT NULL,
                        package_checksum TEXT NOT NULL,
                        is_active INTEGER DEFAULT 1,
                        released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
                        UNIQUE(product_id, version)
                    );
                ");
                $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_product_version ON central_releases(product_id, version);");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_releases_prod_ver ON central_releases(product_id, version);");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_error_logs_deploy_level ON central_error_logs(deployment_id, error_level);");

                // Check and migrate columns on central_error_logs
                try {
                    $errorCols = array_column($pdo->query("PRAGMA table_info(central_error_logs)")->fetchAll(), 'name');
                    if (!empty($errorCols)) {
                        if (!in_array('status', $errorCols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN status TEXT DEFAULT 'open';");
                        }
                        if (!in_array('resolved_by_version', $errorCols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_by_version TEXT DEFAULT NULL;");
                        }
                        if (!in_array('resolved_at', $errorCols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_at DATETIME DEFAULT NULL;");
                        }
                        if (!in_array('occurred_at', $errorCols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN occurred_at DATETIME DEFAULT NULL;");
                            $pdo->exec("UPDATE central_error_logs SET occurred_at = COALESCE(last_seen_at, created_at) WHERE occurred_at IS NULL;");
                        }
                    }
                } catch (\Throwable) {}

                // Check and migrate columns on central_releases
                try {
                    $relCols = array_column($pdo->query("PRAGMA table_info(central_releases)")->fetchAll(), 'name');
                    if (!empty($relCols)) {
                        if (!in_array('resolves_error_id', $relCols)) {
                            $pdo->exec("ALTER TABLE central_releases ADD COLUMN resolves_error_id INTEGER DEFAULT NULL;");
                        }
                        if (!in_array('release_type', $relCols)) {
                            $pdo->exec("ALTER TABLE central_releases ADD COLUMN release_type TEXT DEFAULT 'patch';");
                        }
                        if (!in_array('is_silent_patch', $relCols)) {
                            $pdo->exec("ALTER TABLE central_releases ADD COLUMN is_silent_patch INTEGER DEFAULT 1;");
                        }
                    }
                } catch (\Throwable) {}

                // Check and migrate columns on central_error_logs
                try {
                    $errCols = array_column($pdo->query("PRAGMA table_info(central_error_logs)")->fetchAll(), 'name');
                    if (!empty($errCols) && !in_array('manual_intervention_required', $errCols, true)) {
                        $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN manual_intervention_required INTEGER DEFAULT 0;");
                    }
                } catch (\Throwable) {}

                // Check and migrate columns on central_licenses
                try {
                    $licCols = array_column($pdo->query("PRAGMA table_info(central_licenses)")->fetchAll(), 'name');
                    if (!empty($licCols)) {
                        if (!in_array('issued_at', $licCols)) {
                            $pdo->exec("ALTER TABLE central_licenses ADD COLUMN issued_at DATETIME DEFAULT NULL;");
                            $pdo->exec("UPDATE central_licenses SET issued_at = created_at WHERE issued_at IS NULL;");
                        }
                    }
                } catch (\Throwable) {}

                // Ensure central_vendpulse_licenses on SQLite
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS central_vendpulse_licenses (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        license_id TEXT UNIQUE NOT NULL,
                        client_name TEXT NOT NULL,
                        machine_hwid TEXT DEFAULT NULL,
                        tier TEXT NOT NULL,
                        usage_mode TEXT NOT NULL DEFAULT 'RETAIL_SHOP',
                        allowed_modules TEXT NOT NULL,
                        max_counters INTEGER NOT NULL DEFAULT 1,
                        issued_at INTEGER NOT NULL,
                        expires_at INTEGER DEFAULT NULL,
                        signature TEXT NOT NULL,
                        license_bundle TEXT NOT NULL,
                        status TEXT NOT NULL DEFAULT 'active',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    );
                ");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_license_id ON central_vendpulse_licenses(license_id);");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_status ON central_vendpulse_licenses(status);");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_tier ON central_vendpulse_licenses(tier);");

                try {
                    $vpCols = array_column($pdo->query("PRAGMA table_info(central_vendpulse_licenses)")->fetchAll(), 'name');
                    if (!empty($vpCols) && !in_array('usage_mode', $vpCols, true)) {
                        $pdo->exec("ALTER TABLE central_vendpulse_licenses ADD COLUMN usage_mode TEXT NOT NULL DEFAULT 'RETAIL_SHOP';");
                    }
                    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_vp_usage_mode ON central_vendpulse_licenses(usage_mode);");
                } catch (\Throwable) {}

                // Ensure central_clients on SQLite
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

                // Ensure central_orders on SQLite
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
                    CREATE TABLE IF NOT EXISTS central_users (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(128) NOT NULL,
                        email VARCHAR(191) UNIQUE NOT NULL,
                        password_hash VARCHAR(255) NOT NULL,
                        role ENUM('admin', 'operator', 'viewer') NOT NULL DEFAULT 'admin',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_user_email (email)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                $pdo->exec("
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
                $pdo->exec("
                    CREATE TABLE IF NOT EXISTS central_releases (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        product_id INT NOT NULL,
                        version VARCHAR(32) NOT NULL,
                        changelog TEXT DEFAULT NULL,
                        resolves_error_id BIGINT DEFAULT NULL,
                        package_filename VARCHAR(255) NOT NULL,
                        package_checksum VARCHAR(64) NOT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
                        FOREIGN KEY (resolves_error_id) REFERENCES central_error_logs(id) ON DELETE SET NULL,
                        UNIQUE KEY uq_product_version (product_id, version)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ");
                try {
                    $pdo->exec("ALTER TABLE central_error_logs ADD INDEX idx_error_logs_deploy_level (deployment_id, error_level);");
                } catch (\Throwable) {}

                try {
                    $cols = array_column($pdo->query("SHOW COLUMNS FROM central_error_logs")->fetchAll(), 'Field');
                    if (!empty($cols)) {
                        if (!in_array('status', $cols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN status ENUM('open', 'patch_pending', 'resolved', 'archived') DEFAULT 'open' AFTER error_level;");
                        } else {
                            try {
                                $pdo->exec("ALTER TABLE central_error_logs MODIFY COLUMN status ENUM('open', 'patch_pending', 'resolved', 'archived') DEFAULT 'open';");
                            } catch (\Throwable) {}
                        }
                        if (!in_array('resolved_by_version', $cols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_by_version VARCHAR(32) DEFAULT NULL AFTER status;");
                        }
                        if (!in_array('resolved_at', $cols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN resolved_at DATETIME DEFAULT NULL AFTER resolved_by_version;");
                        }
                        if (!in_array('occurred_at', $cols)) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN occurred_at DATETIME DEFAULT NULL AFTER last_seen_at;");
                            $pdo->exec("UPDATE central_error_logs SET occurred_at = COALESCE(last_seen_at, created_at) WHERE occurred_at IS NULL;");
                        }
                    }

                    $relCols = array_column($pdo->query("SHOW COLUMNS FROM central_releases")->fetchAll(), 'Field');
                    if (!empty($relCols)) {
                        if (!in_array('resolves_error_id', $relCols)) {
                            $pdo->exec("ALTER TABLE central_releases ADD COLUMN resolves_error_id BIGINT DEFAULT NULL AFTER changelog;");
                            try {
                                $pdo->exec("ALTER TABLE central_releases ADD CONSTRAINT fk_release_error FOREIGN KEY (resolves_error_id) REFERENCES central_error_logs(id) ON DELETE SET NULL;");
                            } catch (\Throwable) {}
                        }
                    }

                    $licCols = array_column($pdo->query("SHOW COLUMNS FROM central_licenses")->fetchAll(), 'Field');
                    if (!empty($licCols)) {
                        if (!in_array('issued_at', $licCols)) {
                            $pdo->exec("ALTER TABLE central_licenses ADD COLUMN issued_at DATETIME DEFAULT CURRENT_TIMESTAMP AFTER status;");
                            $pdo->exec("UPDATE central_licenses SET issued_at = created_at WHERE issued_at IS NULL;");
                        }
                    }

                    // Ensure central_vendpulse_licenses on MySQL
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS central_vendpulse_licenses (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            license_id VARCHAR(64) UNIQUE NOT NULL,
                            client_name VARCHAR(191) NOT NULL,
                            machine_hwid VARCHAR(128) DEFAULT NULL,
                            tier VARCHAR(32) NOT NULL,
                            usage_mode ENUM('RETAIL_SHOP', 'SUPERMARKET', 'RESTAURANT') NOT NULL DEFAULT 'RETAIL_SHOP',
                            allowed_modules TEXT NOT NULL,
                            max_counters INT NOT NULL DEFAULT 1,
                            issued_at BIGINT NOT NULL,
                            expires_at BIGINT DEFAULT NULL,
                            signature TEXT NOT NULL,
                            license_bundle LONGTEXT NOT NULL,
                            status ENUM('active', 'suspended', 'revoked') NOT NULL DEFAULT 'active',
                            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                            INDEX idx_vp_license_id (license_id),
                            INDEX idx_vp_status (status),
                            INDEX idx_vp_tier (tier),
                            INDEX idx_vp_usage_mode (usage_mode)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                    ");

                    try {
                        $vpCols = array_column($pdo->query("SHOW COLUMNS FROM central_vendpulse_licenses")->fetchAll(), 'Field');
                        if (!empty($vpCols) && !in_array('usage_mode', $vpCols, true)) {
                            $pdo->exec("ALTER TABLE central_vendpulse_licenses ADD COLUMN usage_mode ENUM('RETAIL_SHOP', 'SUPERMARKET', 'RESTAURANT') NOT NULL DEFAULT 'RETAIL_SHOP' AFTER tier;");
                            $pdo->exec("ALTER TABLE central_vendpulse_licenses ADD INDEX idx_vp_usage_mode (usage_mode);");
                        }
                    } catch (\Throwable) {}

                    // Ensure central_clients on MySQL
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

                    // Ensure central_orders on MySQL
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

                    try {
                        $colCheck = $pdo->query("SHOW COLUMNS FROM central_error_logs LIKE 'manual_intervention_required'")->fetch();
                        if (!$colCheck) {
                            $pdo->exec("ALTER TABLE central_error_logs ADD COLUMN manual_intervention_required TINYINT(1) DEFAULT 0;");
                        }
                    } catch (\Throwable) {}
                } catch (\Throwable) {}
            }

            // Ensure VendPulse product exists in central_products
            try {
                $stmt = $pdo->prepare("SELECT id FROM central_products WHERE product_slug = 'vendpulse' OR product_code = 'VP'");
                $stmt->execute();
                if (!$stmt->fetch()) {
                    $ins = $pdo->prepare("
                        INSERT INTO central_products (product_slug, product_code, product_name, description, latest_version)
                        VALUES ('vendpulse', 'VP', 'VendPulse POS Engine', 'Standalone retail & supermarket POS with offline cryptographic licensing', '1.0.0')
                    ");
                    $ins->execute();
                }
            } catch (\Throwable) {}

            // Run Schema Migrations (v2 Fleet Hub specifications)
            try {
                require_once dirname(__DIR__) . '/database/migrations/SchemaMigrator.php';
                \FleetHub\Database\SchemaMigrator::migrate($pdo);
            } catch (\Throwable) {}
        } catch (\Throwable) {
            // Ignore if schema creation or query fails
        }
    }

    public static function setConnection(PDO $pdo): void {
        self::$instance = $pdo;
    }

    public static function reset(): void {
        self::$instance = null;
    }

    private static function loadEnv(): void {
        $envFile = dirname(__DIR__) . '/.env';
        if (!file_exists($envFile)) {
            return;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $name = trim($parts[0]);
                $value = trim($parts[1]);
                // Strip outer quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }

                if (getenv($name) === false) {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $value;
                }
            }
        }
    }
}
