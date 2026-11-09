<?php
declare(strict_types=1);

/**
 * Seed realistic multi-client deployments with heartbeats and errors for demo
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

echo "Seeding demo fleet data...\n";

$service = new FleetService();

// Register a few schools and businesses
$clients = [
    [
        'prod_id' => 1, // Prism EDU
        'name' => 'St. Jude International Academy',
        'domain' => 'prism.stjude.edu',
        'env' => 'production',
        'version' => '2.5.0',
        'hb' => [
            'version' => '2.5.0',
            'php_version' => '8.2.14',
            'mysql_version' => '8.0.35',
            'os_info' => 'Linux Ubuntu 22.04',
            'memory_usage_mb' => 118.4,
            'db_latency_ms' => 14.2,
            'storage_free_gb' => 142.8,
            'active_users_24h' => 612,
        ]
    ],
    [
        'prod_id' => 1, // Prism EDU
        'name' => 'Oakridge Preparatory School',
        'domain' => 'prism.oakridge.edu',
        'env' => 'production',
        'version' => '2.4.2', // Outdated
        'hb' => [
            'version' => '2.4.2',
            'php_version' => '8.1.28',
            'mysql_version' => '8.0.32',
            'os_info' => 'Debian 11 (Bullseye)',
            'memory_usage_mb' => 248.1,
            'db_latency_ms' => 580.0, // High latency -> Warning
            'storage_free_gb' => 18.5,
            'active_users_24h' => 410,
        ],
        'errors' => [
            [
                'level' => 'WARNING',
                'message' => 'High DB query response latency on students table index scan',
                'file' => '/var/www/prism/models/Student.php',
                'line' => 88,
                'trace' => '#0 /var/www/prism/controllers/EnrollmentController.php(112): Student::getActiveRoster()',
                'uri' => '/students/roster',
            ]
        ]
    ],
    [
        'prod_id' => 1, // Prism EDU
        'name' => 'Horizon Science Academy',
        'domain' => 'sis.horizon.edu',
        'env' => 'staging',
        'version' => '2.5.0',
        'hb' => [
            'version' => '2.5.0',
            'php_version' => '8.3.2',
            'mysql_version' => '8.2.0',
            'os_info' => 'Alpine Linux 3.19',
            'memory_usage_mb' => 92.0,
            'db_latency_ms' => 8.4,
            'storage_free_gb' => 95.0,
            'active_users_24h' => 45,
        ]
    ],
    [
        'prod_id' => 2, // POS Pro
        'name' => 'Grand Central Retail Flagship',
        'domain' => 'pos.grandcentral.store',
        'env' => 'production',
        'version' => '1.4.2',
        'hb' => [
            'version' => '1.4.2',
            'php_version' => '8.2.14',
            'mysql_version' => '8.0.35',
            'os_info' => 'Windows Server 2022',
            'memory_usage_mb' => 164.2,
            'db_latency_ms' => 11.0,
            'storage_free_gb' => 310.5,
            'active_users_24h' => 1280,
        ],
        'errors' => [
            [
                'level' => 'CRITICAL',
                'message' => 'Payment gateway timeout during batch transaction settlement',
                'file' => '/app/Services/StripeGateway.php',
                'line' => 241,
                'trace' => '#0 /app/Jobs/BatchSettlement.php(64): StripeGateway->capturePending()\n#1 /app/Console/Kernel.php(90): BatchSettlement->handle()',
                'uri' => 'CLI:queue:work',
            ]
        ]
    ],
];

foreach ($clients as $c) {
    $dep = $service->registerDeployment($c['prod_id'], $c['name'], $c['domain'], $c['env'], $c['version']);
    echo "  Registered: {$c['name']} (ID: {$dep['id']}, Token: {$dep['token_preview']})\n";

    $payload = ['heartbeat' => $c['hb']];
    if (isset($c['errors'])) {
        $payload['errors'] = $c['errors'];
    }

    $service->ingestTelemetry($dep['id'], $payload, '127.0.0.1');
}

echo "Seed complete! All sample nodes and telemetry loaded.\n";
