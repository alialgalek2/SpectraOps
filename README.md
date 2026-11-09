# Centralized Multi-Product Telemetry & Fleet Management Hub

A high-throughput, secure, multi-tenant fleet operations and telemetry hub designed to monitor client deployments (e.g. **Prism EDU**, ERPs, POS Pro, custom tools) across dozens or hundreds of distributed customer installations.

---

## 🏛️ Architecture Highlights

- **Multi-Product Software Catalog**: Decouples software products (`central_products`) from individual customer deployments (`central_deployments`).
- **Cryptographic Token Isolation**: Deployments authenticate using Bearer tokens stored as **SHA-256 hashes**. Raw tokens are never stored in plaintext.
- **FastCGI Non-Blocking Reporting**: Client SDK utilizes `fastcgi_finish_request()` and shutdown hooks so telemetry dispatch never adds latency to user page requests.
- **Error Fingerprinting & Deduplication**: Groups identical uncaught exceptions by MD5 hash (`error_hash`) and increments occurrence counters, preventing error log database flooding.
- **Automated Stale Node Sweep**: Periodic background monitor automatically flags silent deployments as `warning` (30m+) or `unreachable` (2h+).
- **PII & Credential Redaction**: Embedded scrubber redacts database credentials, authorization headers, passwords, and API keys before dispatch.
- **Dual Database Support**: Production-ready for **MySQL 8.0+ / MariaDB 10.5+**, with out-of-the-box **SQLite** support for zero-dependency local testing.

---

## 📁 Directory Structure

```
fleet-hub/
├── bin/
│   └── fleet_monitor.php        # CLI cron for stale heartbeat checks & log pruning
├── client-sdk/
│   ├── TelemetryReporter.php    # Non-blocking client SDK with PII redaction & offline queue
│   └── demo_client.php          # Sample client integration script
├── database/
│   ├── schema.sql               # Production MySQL / MariaDB schema
│   ├── schema_sqlite.sql        # Dev / SQLite schema
│   └── seed_demo.php            # Seeds realistic client nodes and telemetry
├── public/
│   ├── index.html               # Glassmorphism dark-mode operations dashboard
│   ├── style.css                # Inter typography, modern glow accents, and responsive UI
│   ├── app.js                   # Dashboard client logic & real-time telemetry polling
│   └── api/v1/
│       ├── fleet.php            # Fleet management & metrics REST API
│       └── telemetry/
│           └── ingest.php       # Hardened telemetry ingestion endpoint
├── src/
│   ├── Database.php             # PDO database connection manager (MySQL + SQLite)
│   └── FleetService.php         # Core fleet operations, auth, and analytics service
├── tests/
│   └── run_tests.php            # Automated test suite (15 assertions)
├── .env.example
├── .env
└── README.md
```

---

## 🚀 Quick Start (Local Development)

You can run the entire hub locally in seconds using PHP's built-in web server:

1. **Seed Demo Data**:
   ```bash
   php -d extension=pdo_sqlite database/seed_demo.php
   ```

2. **Start the Web Server**:
   ```bash
   php -d extension=pdo_sqlite -S 127.0.0.1:8080 -t public
   ```

3. **Open the Dashboard**:
   Visit [http://127.0.0.1:8080](http://127.0.0.1:8080) in your browser.

4. **Run the Automated Test Suite**:
   ```bash
   php -d extension=pdo_sqlite tests/run_tests.php
   ```

5. **Create the first operator account** (there are no default credentials):
   ```bash
   php bin/create_admin.php admin@example.com "a-long-unique-password" "System Administrator"
   ```

---

## 🛠️ Production Setup (MySQL / MariaDB)

### 1. Database Creation & Migration
Import the schema into your MySQL instance:
```bash
mysql -u root -p -e "CREATE DATABASE central_fleet_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p central_fleet_db < database/schema.sql
```

### 2. Configure Environment (`.env`)
Set database credentials in `.env`:
```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=central_fleet_db
DB_USERNAME=fleet_user
DB_PASSWORD=YOUR_STRONG_PASSWORD
```

### 3. Web Server Authorization Headers
FastCGI setups frequently strip the `Authorization` header by default. Configure your web server accordingly:

#### Nginx:
```nginx
location / {
    try_files $uri $uri/ /index.html;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}
```

#### Apache (`.htaccess` or VirtualHost):
```apache
CGIPassAuth On
SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
```

---

## ⏰ Cron Setup (Fleet Monitoring)

Add a crontab entry on your central hub to sweep stale deployments every 5 minutes:
```bash
*/5 * * * * php /var/www/fleet-hub/bin/fleet_monitor.php >> /var/log/fleet_monitor.log 2>&1
```

---

## 📦 Client SDK Integration (e.g. Prism EDU)

Copy `client-sdk/TelemetryReporter.php` into your application and add the following bootstrap code:

```php
<?php
use App\Services\TelemetryReporter;

// 1. Initialize Reporter
$reporter = TelemetryReporter::init(
    'https://hub.yourdomain.com',
    'flt_YOUR_DEPLOYMENT_SECRET_TOKEN',
    '2.5.0' // Current product version
);

// 2. Automatically capture uncaught exceptions & fatal errors
TelemetryReporter::registerGlobalHandlers($reporter);

// 3. Queue Periodic Heartbeat with diagnostics
$reporter->queueHeartbeat([
    'active_users_24h' => 240,
    'db_latency_ms'   => $reporter->measureDbLatency($pdo),
]);

// 4. Reporting custom handled exceptions
try {
    // Application logic...
} catch (\Throwable $e) {
    $reporter->reportError($e, 'CRITICAL');
}
```
