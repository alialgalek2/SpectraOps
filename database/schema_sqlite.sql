-- =====================================================================
-- Centralized Multi-Product Telemetry & Fleet Management Hub
-- SQLite Schema (For local development, testing, and zero-config runner)
-- =====================================================================

-- PRAGMA foreign_keys = ON; (Enforced dynamically in Database.php)

DROP TABLE IF EXISTS central_error_logs;
DROP TABLE IF EXISTS central_support_tickets;
DROP TABLE IF EXISTS central_heartbeats;
DROP TABLE IF EXISTS central_releases;
DROP TABLE IF EXISTS central_deployments;
DROP TABLE IF EXISTS central_licenses;
DROP TABLE IF EXISTS central_products;
DROP TABLE IF EXISTS central_users;

-- 1. Software Catalog with dynamic Prefix Code
CREATE TABLE central_products (
    id INTEGER PRIMARY KEY,
    product_slug TEXT UNIQUE NOT NULL,
    product_code TEXT UNIQUE NOT NULL, -- e.g., PRISM, POS, MED, CRM
    product_name TEXT NOT NULL,
    description TEXT,
    latest_version TEXT NOT NULL DEFAULT '1.0.0',
    repository_url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 2. Master Licenses Table
CREATE TABLE central_licenses (
    id INTEGER PRIMARY KEY,
    product_id INTEGER NOT NULL,
    license_key TEXT UNIQUE NOT NULL, -- e.g., SPEC-PRISM-9F2C-4A81
    client_name TEXT NOT NULL,
    client_email TEXT NOT NULL,
    license_type TEXT DEFAULT 'perpetual',
    max_seats INTEGER DEFAULT 0,
    expires_at DATETIME DEFAULT NULL,
    status TEXT DEFAULT 'unactivated',
    lease_salt TEXT DEFAULT NULL,
    issued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE
);

CREATE INDEX idx_licenses_key ON central_licenses(license_key);
CREATE INDEX idx_licenses_prod ON central_licenses(product_id);

-- 3. Client Deployment Fleet Registry
CREATE TABLE central_deployments (
    id INTEGER PRIMARY KEY,
    product_id INTEGER NOT NULL,
    license_id INTEGER DEFAULT NULL,
    client_name TEXT NOT NULL,
    deployment_domain TEXT NOT NULL,
    hardware_hash TEXT DEFAULT NULL,
    environment TEXT DEFAULT 'production',
    api_ingest_token_hash TEXT UNIQUE NOT NULL,
    token_preview TEXT NOT NULL,
    current_version TEXT NOT NULL DEFAULT '1.0.0',
    php_version TEXT,
    mysql_version TEXT,
    os_info TEXT,
    server_ip TEXT,
    status TEXT DEFAULT 'healthy',
    status_reason TEXT DEFAULT 'Awaiting initial heartbeat',
    last_heartbeat_at DATETIME,
    memory_usage_mb REAL DEFAULT 0,
    db_latency_ms INTEGER DEFAULT 0,
    disk_free_gb REAL DEFAULT 0,
    storage_free_gb REAL DEFAULT 0,
    active_users_24h INTEGER DEFAULT 0,
    open_error_count INTEGER DEFAULT 0,
    load_avg REAL,
    paired_at DATETIME DEFAULT NULL,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
    FOREIGN KEY (license_id) REFERENCES central_licenses(id) ON DELETE SET NULL
);

CREATE INDEX idx_deployments_token_hash ON central_deployments(api_ingest_token_hash);
CREATE INDEX idx_deployments_status ON central_deployments(status);
CREATE INDEX idx_deployments_last_heartbeat ON central_deployments(last_heartbeat_at);
CREATE INDEX idx_deployments_status_heartbeat ON central_deployments(status, last_heartbeat_at);
CREATE INDEX idx_deployments_domain ON central_deployments(deployment_domain);

-- 3. Telemetry & Diagnostic Heartbeat History
CREATE TABLE central_heartbeats (
    id INTEGER PRIMARY KEY,
    deployment_id INTEGER NOT NULL,
    memory_usage_mb REAL NOT NULL DEFAULT 0,
    db_latency_ms REAL NOT NULL DEFAULT 0,
    storage_free_gb REAL NOT NULL DEFAULT 0,
    active_users_24h INTEGER NOT NULL DEFAULT 0,
    load_avg REAL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deployment_id) REFERENCES central_deployments(id) ON DELETE CASCADE
);

CREATE INDEX idx_heartbeats_deployment_time ON central_heartbeats(deployment_id, recorded_at);

-- 4. Remote Error & Crash Logs with Fingerprinting
CREATE TABLE central_error_logs (
    id INTEGER PRIMARY KEY,
    deployment_id INTEGER NOT NULL,
    error_hash TEXT NOT NULL,
    error_level TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    resolved_by_version TEXT DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    error_message TEXT NOT NULL,
    file_path TEXT,
    line_number INTEGER,
    stack_trace TEXT,
    request_uri TEXT,
    ip_address TEXT,
    occurrences INTEGER NOT NULL DEFAULT 1,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    occurred_at DATETIME DEFAULT NULL,
    resolved INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (deployment_id) REFERENCES central_deployments(id) ON DELETE CASCADE
);

CREATE INDEX idx_error_logs_deploy_hash ON central_error_logs(deployment_id, error_hash);
CREATE INDEX idx_error_logs_level ON central_error_logs(error_level);
CREATE INDEX idx_error_logs_deploy_level ON central_error_logs(deployment_id, error_level);

-- Default Seed Catalog Products
INSERT INTO central_products (product_slug, product_code, product_name, description, latest_version)
VALUES 
    ('prism-edu', 'PRISM', 'Prism EDU School ERP', 'Multi-tenant student information & school management system', '2.5.0'),
    ('pos-pro', 'POS', 'Point of Sale Pro', 'Retail POS and inventory control suite', '1.4.2'),
    ('clinic-core', 'MED', 'ClinicCore Health', 'Clinical workflow and medical records platform', '1.1.0');

-- 5. Support Ticketing System
CREATE TABLE central_support_tickets (
    id INTEGER PRIMARY KEY,
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

CREATE INDEX idx_ticket_status ON central_support_tickets(status);
CREATE INDEX idx_ticket_deploy ON central_support_tickets(deployment_id);

-- 6. Product Release Distribution Store
CREATE TABLE central_releases (
    id INTEGER PRIMARY KEY,
    product_id INTEGER NOT NULL,
    version TEXT NOT NULL,
    release_type TEXT NOT NULL DEFAULT 'patch',
    is_silent_patch INTEGER NOT NULL DEFAULT 1,
    changelog TEXT DEFAULT NULL,
    resolves_error_id INTEGER DEFAULT NULL,
    package_filename TEXT NOT NULL,
    package_checksum TEXT NOT NULL,
    is_active INTEGER DEFAULT 1,
    released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES central_products(id) ON DELETE CASCADE,
    FOREIGN KEY (resolves_error_id) REFERENCES central_error_logs(id) ON DELETE SET NULL
);

CREATE UNIQUE INDEX uq_product_version ON central_releases(product_id, version);

-- 7. Operator & Admin Users
CREATE TABLE central_users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT UNIQUE NOT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'admin',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_users_email ON central_users(email);

-- 8. VendPulse Offline Cryptographic Licenses
CREATE TABLE central_vendpulse_licenses (
    id INTEGER PRIMARY KEY,
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

CREATE INDEX idx_vp_license_id ON central_vendpulse_licenses(license_id);
CREATE INDEX idx_vp_status ON central_vendpulse_licenses(status);
CREATE INDEX idx_vp_tier ON central_vendpulse_licenses(tier);
CREATE INDEX idx_vp_usage_mode ON central_vendpulse_licenses(usage_mode);

-- Insert VendPulse in products catalog if not present
INSERT OR IGNORE INTO central_products (product_slug, product_code, product_name, description, latest_version)
VALUES ('vendpulse', 'VP', 'VendPulse POS Engine', 'Standalone retail & supermarket POS with offline cryptographic licensing', '1.0.0');



