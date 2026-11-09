<?php
// bin/register_deployment.php
// Run this on your central support server when a client purchases a system
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/FleetService.php';

function createClientDeployment(PDO $pdo, string $productSlug, string $clientName, string $domain): array {
    // 1. Fetch Product ID
    $stmt = $pdo->prepare("SELECT id FROM central_products WHERE product_slug = ?");
    $stmt->execute([$productSlug]);
    $product = $stmt->fetch();

    if (!$product) {
        throw new Exception("Product slug '{$productSlug}' does not exist.");
    }

    $service = new \FleetHub\FleetService($pdo);
    $registered = $service->registerDeployment((int)$product['id'], $clientName, $domain, 'production', '1.0.0');

    return [
        'deployment_id' => $registered['id'],
        'ingest_token'  => $registered['token'],
        'hub_endpoint'  => 'https://support.yourcentraldomain.com'
    ];
}

// Simple CLI usage
if (php_sapi_name() === 'cli' && $argc === 4) {
    $productSlug = $argv[1];
    $clientName = $argv[2];
    $domain = $argv[3];
    
    try {
        $pdo = \FleetHub\Database::getConnection();
        $result = createClientDeployment($pdo, $productSlug, $clientName, $domain);
        echo "Deployment Registered Successfully!\n";
        echo "Token: " . $result['ingest_token'] . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
} else if (php_sapi_name() === 'cli') {
    echo "Usage: php register_deployment.php <product_slug> <client_name> <domain>\n";
}
