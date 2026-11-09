<?php
/**
 * Developer Update Server API
 * Serves software release manifests, security patches, changelogs, and migration instructions.
 */

if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
}

// Release manifest descriptor for the latest stable build
$manifest = [
    'status'           => 'ok',
    'version_number'   => 'v2.5.0',
    'build_number'     => 2500,
    'release_name'     => 'EduMaster Nova Patch Release',
    'release_date'     => '2026-09-03',
    'release_type'     => 'feature', // 'security', 'feature', 'maintenance'
    'min_php_version'  => '8.0.0',
    'min_mysql_version'=> '5.7.0',
    'changelog'        => [
        'Master System Update & OTA Automated Migration Architecture',
        'Zero-PII Telemetry & Real-Time Error Interception System',
        'Interactive Dashboard Sortable Widgets & Academic Calendar Engine',
        'Enhanced Dual-Language Topbar & Dynamic Institution Branding',
        'High-Performance Query Optimizations for Student & Finance Ledgers'
    ],
    'migrations'       => [
        'add_system_update_and_telemetry_tables.php'
    ],
    'package_hash'     => 'sha256-e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    'server_time'      => date('c')
];

echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
