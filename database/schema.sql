-- =====================================================================
-- Centralized Multi-Product Telemetry & Fleet Management Hub
-- Production Database Schema (Engine: MySQL 8.0+ / MariaDB 10.5+)
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS central_error_logs;
DROP TABLE IF EXISTS central_support_tickets;
DROP TABLE IF EXISTS central_heartbeats;
DROP TABLE IF EXISTS central_releases;
DROP TABLE IF EXISTS central_deployments;
DROP TABLE IF EXISTS central_licenses;
DROP TABLE IF EXISTS central_products;
DROP TABLE IF EXISTS central_users;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. Software Catalog with dynamic Prefix Code
CREATE TABLE central_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_slug VARCHAR(64) UNIQUE NOT NULL,      -- e.g. 'prism-edu', 'pos-pro', 'clinic-core'
    product_code VARCHAR(8) UNIQUE NOT NULL,       -- e.g. 'PRISM', 'POS', 'MED', 'CRM'
    product_name VARCHAR(128) NOT NULL,
    description TEXT DEFAULT NULL,
    latest_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    repository_url VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product_slug (product_slug),
    INDEX idx_product_code (product_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Master Licenses Table
CREATE TABLE central_licenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    license_key VARCHAR(64) UNIQUE NOT NULL,       -- e.g. SPEC-PRISM-9F2C-4A81
    client_name VARCHAR(191) NOT NULL,
    client_email VARCHAR(191) NOT NULL,
    license_type ENUM('perpetual', 'subscription', 'trial') DEFAULT 'perpetual',
    max_seats INT DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    status ENUM('unactivated', 'active', 'suspended', 'revoked') DEFAULT 'unactivated',
    lease_salt VARCHAR(64) DEFAULT NULL,
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
    INDEX idx_key (license_key),
    INDEX idx_prod (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Client Deployment Fleet Registry (bound to Licenses)
CREATE TABLE central_deployments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    license_id INT DEFAULT NULL,
    client_name VARCHAR(191) NOT NULL,             -- e.g. 'Oakridge Academy', 'St. Jude School'
    deployment_domain VARCHAR(191) NOT NULL,       -- e.g. 'prism.oakridge.edu'
    hardware_hash VARCHAR(64) DEFAULT NULL,
    environment ENUM('production', 'staging', 'development') DEFAULT 'production',
    api_ingest_token_hash VARCHAR(64) UNIQUE NOT NULL, -- SHA-256 hash of secret token or license key
    api_ingest_token VARCHAR(128) DEFAULT NULL,
    token_preview VARCHAR(12) NOT NULL,            -- e.g. 'flt_...4b8e' or 'SPEC...4A81' for safe identification
    current_version VARCHAR(32) NOT NULL DEFAULT '1.0.0',
    php_version VARCHAR(32) DEFAULT NULL,
    mysql_version VARCHAR(64) DEFAULT NULL,
    os_info VARCHAR(128) DEFAULT NULL,
    server_ip VARCHAR(45) DEFAULT NULL,
    status ENUM('healthy', 'warning', 'critical', 'unreachable') DEFAULT 'healthy',
    status_reason VARCHAR(255) DEFAULT 'Awaiting initial heartbeat',
    last_heartbeat_at DATETIME DEFAULT NULL,
    memory_usage_mb FLOAT NOT NULL DEFAULT 0,
    db_latency_ms INT UNSIGNED NOT NULL DEFAULT 0,
    disk_free_gb DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    storage_free_gb FLOAT NOT NULL DEFAULT 0,
    active_users_24h INT UNSIGNED NOT NULL DEFAULT 0,
    open_error_count INT UNSIGNED NOT NULL DEFAULT 0,
    load_avg FLOAT DEFAULT NULL,
    paired_at DATETIME DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
    FOREIGN KEY (license_id) REFERENCES central_licenses(id) ON DELETE SET NULL,
    INDEX idx_token_hash (api_ingest_token_hash),
    INDEX idx_status (status),
    INDEX idx_last_heartbeat (last_heartbeat_at),
    INDEX idx_status_heartbeat (status, last_heartbeat_at),
    INDEX idx_domain (deployment_domain)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Telemetry & Diagnostic Heartbeat History (Time-series metrics)
CREATE TABLE central_heartbeats (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    memory_usage_mb FLOAT NOT NULL DEFAULT 0,
    db_latency_ms FLOAT NOT NULL DEFAULT 0,
    storage_free_gb FLOAT NOT NULL DEFAULT 0,
    active_users_24h INT NOT NULL DEFAULT 0,
    load_avg FLOAT DEFAULT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deployment_id) REFERENCES central_deployments(id) ON DELETE CASCADE,
    INDEX idx_deployment_time (deployment_id, recorded_at),
    INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Remote Error & Crash Logs with Fingerprinting / Deduplication
CREATE TABLE central_error_logs (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    deployment_id INT NOT NULL,
    error_hash VARCHAR(32) NOT NULL,               -- MD5 fingerprint for grouping identical errors
    error_level VARCHAR(32) NOT NULL,              -- e.g. 'FATAL', 'EXCEPTION', 'WARNING', 'CRITICAL'
    status ENUM('open', 'patch_pending', 'resolved', 'archived') DEFAULT 'open',
    resolved_by_version VARCHAR(32) DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    error_message TEXT NOT NULL,
    file_path VARCHAR(255) DEFAULT NULL,
    line_number INT DEFAULT NULL,
    stack_trace MEDIUMTEXT DEFAULT NULL,
    request_uri VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    occurrences INT NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    occurred_at DATETIME DEFAULT NULL,
    resolved TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deployment_id) REFERENCES central_deployments(id) ON DELETE CASCADE,
    INDEX idx_deploy_hash (deployment_id, error_hash),
    INDEX idx_last_seen (last_seen_at),
    INDEX idx_error_level (error_level),
    INDEX idx_error_logs_deploy_level (deployment_id, error_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default Seed Catalog Products
INSERT INTO central_products (product_slug, product_code, product_name, description, latest_version)
VALUES 
    ('prism-edu', 'PRISM', 'Prism EDU School ERP', 'Multi-tenant student information & school management system', '2.5.0'),
    ('pos-pro', 'POS', 'Point of Sale Pro', 'Retail POS and inventory control suite', '1.4.2'),
    ('clinic-core', 'MED', 'ClinicCore Health', 'Clinical workflow and medical records platform', '1.1.0');

-- 5. Support Ticketing System
CREATE TABLE central_support_tickets (
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

-- 6. Product Release Distribution Store (Updates pushed out to client instances)
CREATE TABLE central_releases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    version VARCHAR(32) NOT NULL,
    release_type ENUM('patch', 'minor', 'major') NOT NULL DEFAULT 'patch',
    is_silent_patch TINYINT(1) NOT NULL DEFAULT 1,
    changelog TEXT DEFAULT NULL,
    resolves_error_id BIGINT DEFAULT NULL,
    package_filename VARCHAR(255) NOT NULL,
    package_checksum VARCHAR(64) NOT NULL, -- SHA-256 for download verification
    is_active TINYINT(1) DEFAULT 1,
    released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
    FOREIGN KEY (resolves_error_id) REFERENCES central_error_logs(id) ON DELETE SET NULL,
    UNIQUE KEY uq_product_version (product_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Operator & Admin Users
CREATE TABLE central_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(128) NOT NULL,
    email VARCHAR(191) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'operator', 'viewer') NOT NULL DEFAULT 'admin',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. VendPulse Offline Cryptographic Licenses
CREATE TABLE central_vendpulse_licenses (
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

INSERT INTO central_products (product_slug, product_code, product_name, description, latest_version)
VALUES ('vendpulse', 'VP', 'VendPulse POS Engine', 'Standalone retail & supermarket POS with offline cryptographic licensing', '1.0.0')
ON DUPLICATE KEY UPDATE product_name = VALUES(product_name);


