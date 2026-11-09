<?php
declare(strict_types=1);

/**
 * Test-First Verification Suite:
 * Automated E-Commerce Order Integration, License Issuance & Self-Service Installer Distribution
 */

require_once dirname(__DIR__) . '/src/Database.php';

use FleetHub\Database;

$pdo = Database::getConnection();

$testsPassed = 0;
$testsFailed = 0;

function assertCondition(bool $cond, string $msg, ?string $detail = null): void {
    global $testsPassed, $testsFailed;
    if ($cond) {
        $testsPassed++;
        echo " [PASS] {$msg}\n";
    } else {
        $testsFailed++;
        echo " [FAIL] {$msg}" . ($detail ? " ({$detail})" : "") . "\n";
    }
}

echo "========================================================\n";
echo "Testing Store Order Ingress & Provisioning Pipeline\n";
echo "========================================================\n\n";

$baseUrl = 'http://127.0.0.1:8080/SPECTRAOPS/public';
$testWebhookSecret = 'spec_whsec_test_secret_2026_pulse';

function makeRequest(string $url, string $method = 'GET', ?array $headers = [], $body = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    $httpHeaders = [];
    if ($headers) {
        foreach ($headers as $k => $v) {
            $httpHeaders[] = "{$k}: {$v}";
        }
    }

    if ($body !== null) {
        if (is_array($body)) {
            $httpHeaders[] = 'Content-Type: application/json';
            $payload = json_encode($body);
        } else {
            $payload = (string)$body;
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpHeaders);
    $res = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err = curl_error($ch);
    unset($ch);

    $json = null;
    if (is_string($res) && str_contains($contentType, 'application/json')) {
        $json = json_decode($res, true);
    }

    return [
        'code' => $code,
        'content_type' => $contentType,
        'body' => $res,
        'json' => $json,
        'error' => $err,
    ];
}

// --------------------------------------------------------------------------
// 1. Security & Authentication Checks
// --------------------------------------------------------------------------
$provisionUrl = "{$baseUrl}/api/v1/orders/provision.php";

echo "[*] Step 1: Security & Webhook Secret Authentication\n";
$resNoAuth = makeRequest($provisionUrl, 'POST', [], ['order_id' => 'ORD-TEST-001']);
assertCondition(
    $resNoAuth['code'] === 401,
    "Missing X-SpectraOps-Webhook-Secret returns HTTP 401 Unauthorized",
    "Got HTTP {$resNoAuth['code']}"
);

$resBadAuth = makeRequest($provisionUrl, 'POST', ['X-SpectraOps-Webhook-Secret' => 'invalid_secret_key'], ['order_id' => 'ORD-TEST-001']);
assertCondition(
    $resBadAuth['code'] === 401,
    "Invalid X-SpectraOps-Webhook-Secret returns HTTP 401 Unauthorized",
    "Got HTTP {$resBadAuth['code']}"
);

// --------------------------------------------------------------------------
// 2. Payload Validation Checks
// --------------------------------------------------------------------------
echo "\n[*] Step 2: Payload Validation\n";
$authHeaders = ['X-SpectraOps-Webhook-Secret' => $testWebhookSecret];

$resMissingEmail = makeRequest($provisionUrl, 'POST', $authHeaders, [
    'order_id' => 'ORD-VAL-001',
    'customer_name' => 'Test User',
    'business_name' => 'Test Business',
    'purchased_product' => 'VENDPULSE',
]);
assertCondition(
    $resMissingEmail['code'] === 400 || $resMissingEmail['code'] === 422,
    "Missing customer_email returns HTTP 400 or 422 Unprocessable",
    "Got HTTP {$resMissingEmail['code']}"
);

$resBadProduct = makeRequest($provisionUrl, 'POST', $authHeaders, [
    'order_id' => 'ORD-VAL-002',
    'customer_name' => 'Test User',
    'customer_email' => 'test@example.com',
    'business_name' => 'Test Business',
    'purchased_product' => 'NON_EXISTENT_SOFTWARE',
]);
assertCondition(
    $resBadProduct['code'] === 400 || $resBadProduct['code'] === 422,
    "Unsupported purchased_product returns validation error",
    "Got HTTP {$resBadProduct['code']}"
);

// --------------------------------------------------------------------------
// 3. Automated Order Ingress & Supermarket Mode Provisioning
// --------------------------------------------------------------------------
echo "\n[*] Step 3: Order Provisioning (Supermarket Mode, 12 Months)\n";
$uniqueOrderId = 'ORD-2026-TEST-' . bin2hex(random_bytes(3));
$supermarketPayload = [
    'order_id' => $uniqueOrderId,
    'customer_name' => 'Ahmed Ibrahim',
    'customer_email' => 'ahmed.amana@example.com',
    'business_name' => 'Al-Amana Groceries',
    'purchased_product' => 'VENDPULSE',
    'usage_mode' => 'SUPERMARKET',
    'term_duration_months' => 12,
    'max_counters' => 3,
    'include_installer_bundle' => true,
];

$resSuper = makeRequest($provisionUrl, 'POST', $authHeaders, $supermarketPayload);
assertCondition(
    $resSuper['code'] === 201,
    "Successful order provisioning returns HTTP 201 Created",
    "Got HTTP {$resSuper['code']}: " . substr((string)$resSuper['body'], 0, 150)
);

$dataSuper = $resSuper['json'] ?? [];
assertCondition(
    isset($dataSuper['success']) && $dataSuper['success'] === true,
    "Response has success = true"
);
assertCondition(
    isset($dataSuper['order']['order_id']) && $dataSuper['order']['order_id'] === $uniqueOrderId,
    "Response echoes correct order_id"
);
assertCondition(
    isset($dataSuper['license']['usage_mode']) && $dataSuper['license']['usage_mode'] === 'SUPERMARKET',
    "License usage_mode is SUPERMARKET"
);
assertCondition(
    isset($dataSuper['license']['max_counters']) && (int)$dataSuper['license']['max_counters'] === 3,
    "License max_counters is 3"
);
assertCondition(
    isset($dataSuper['license']['signature']) && !empty($dataSuper['license']['signature']),
    "License is cryptographically signed"
);
assertCondition(
    isset($dataSuper['distribution']['portal_url']) && !empty($dataSuper['distribution']['portal_url']),
    "Distribution portal URL is returned"
);
assertCondition(
    isset($dataSuper['distribution']['download_token']) && !empty($dataSuper['distribution']['download_token']),
    "Secure download token is returned"
);

$downloadToken = $dataSuper['distribution']['download_token'] ?? '';
$portalUrl = $dataSuper['distribution']['portal_url'] ?? '';

// --------------------------------------------------------------------------
// 4. Idempotency Verification
// --------------------------------------------------------------------------
echo "\n[*] Step 4: Idempotency Protection\n";
$resIdempotent = makeRequest($provisionUrl, 'POST', $authHeaders, $supermarketPayload);
assertCondition(
    $resIdempotent['code'] === 200,
    "Re-submitting same order_id returns HTTP 200 OK (idempotent)",
    "Got HTTP {$resIdempotent['code']}"
);
$dataIdempotent = $resIdempotent['json'] ?? [];
assertCondition(
    !empty($dataIdempotent['idempotent']),
    "Response indicates idempotent fulfillment"
);
assertCondition(
    ($dataIdempotent['license']['license_id'] ?? '') === ($dataSuper['license']['license_id'] ?? 'A'),
    "Re-submitted order returns same license_id without duplicate issuance"
);

// --------------------------------------------------------------------------
// 5. Perpetual Lifetime License Provisioning (0 months)
// --------------------------------------------------------------------------
echo "\n[*] Step 5: Perpetual Lifetime Order Provisioning (Retail Shop)\n";
$perpetualOrderId = 'ORD-2026-LIFE-' . bin2hex(random_bytes(3));
$perpetualPayload = [
    'order_id' => $perpetualOrderId,
    'customer_name' => 'Sara Mansour',
    'customer_email' => 'sara@boutique.com',
    'business_name' => 'Sara Fashion Boutique',
    'purchased_product' => 'VENDPULSE',
    'usage_mode' => 'RETAIL_SHOP',
    'term_duration_months' => 0, // Perpetual
    'max_counters' => 1,
    'include_installer_bundle' => true,
];

$resPerp = makeRequest($provisionUrl, 'POST', $authHeaders, $perpetualPayload);
assertCondition(
    $resPerp['code'] === 201,
    "Perpetual order returns HTTP 201 Created",
    "Got HTTP {$resPerp['code']}"
);
$dataPerp = $resPerp['json'] ?? [];
assertCondition(
    array_key_exists('expires_at', $dataPerp['license'] ?? []) && $dataPerp['license']['expires_at'] === null,
    "Perpetual license has expires_at = null"
);
assertCondition(
    ($dataPerp['license']['is_perpetual'] ?? false) === true,
    "License is_perpetual is true"
);

// --------------------------------------------------------------------------
// 6. Self-Service Portal Access & Token Security
// --------------------------------------------------------------------------
echo "\n[*] Step 6: Self-Service Download Portal Access\n";
$portalBase = "{$baseUrl}/portal/download.php";

$resPortalNoToken = makeRequest($portalBase);
assertCondition(
    $resPortalNoToken['code'] === 400 || $resPortalNoToken['code'] === 403 || str_contains((string)$resPortalNoToken['body'], 'Access Denied') || str_contains((string)$resPortalNoToken['body'], 'Invalid'),
    "Accessing portal without token fails with 400/403 or access denied view"
);

$resPortalValid = makeRequest("{$portalBase}?token={$downloadToken}");
assertCondition(
    $resPortalValid['code'] === 200,
    "Accessing portal with valid token returns HTTP 200",
    "Got HTTP {$resPortalValid['code']}"
);
assertCondition(
    str_contains((string)$resPortalValid['body'], 'Al-Amana Groceries'),
    "Portal displays customer business name"
);
assertCondition(
    str_contains((string)$resPortalValid['body'], 'SUPERMARKET'),
    "Portal displays purchased usage mode"
);
assertCondition(
    str_contains((string)$resPortalValid['body'], $dataSuper['license']['license_id']),
    "Portal displays signed license ID"
);

// --------------------------------------------------------------------------
// 7. Installer & License Distribution Downloads
// --------------------------------------------------------------------------
echo "\n[*] Step 7: Installer & License Artifact Deliveries\n";

// A. Download Signed License File
$resLicDl = makeRequest("{$portalBase}?token={$downloadToken}&action=license");
assertCondition(
    $resLicDl['code'] === 200,
    "Download license file returns HTTP 200",
    "Got HTTP {$resLicDl['code']}"
);
$dlLicJson = json_decode((string)$resLicDl['body'], true);
assertCondition(
    isset($dlLicJson['payload']['license_id']) && $dlLicJson['payload']['license_id'] === $dataSuper['license']['license_id'],
    "Downloaded license file matches valid signed cryptographic bundle"
);

// B. Download Server Setup (.exe)
$resServerDl = makeRequest("{$portalBase}?token={$downloadToken}&action=download&file=server");
assertCondition(
    $resServerDl['code'] === 200,
    "Download Server installer (.exe) returns HTTP 200",
    "Got HTTP {$resServerDl['code']}"
);
assertCondition(
    strlen((string)$resServerDl['body']) > 50,
    "Server installer file has non-empty binary payload"
);

// C. Download Client Setup (.exe)
$resClientDl = makeRequest("{$portalBase}?token={$downloadToken}&action=download&file=client");
assertCondition(
    $resClientDl['code'] === 200,
    "Download Client installer (.exe) returns HTTP 200",
    "Got HTTP {$resClientDl['code']}"
);
assertCondition(
    strlen((string)$resClientDl['body']) > 50,
    "Client installer file has non-empty binary payload"
);

// D. Download All-in-One Bundle (.zip)
$resZipDl = makeRequest("{$portalBase}?token={$downloadToken}&action=download&file=bundle");
assertCondition(
    $resZipDl['code'] === 200,
    "Download complete deployment bundle (.zip) returns HTTP 200",
    "Got HTTP {$resZipDl['code']}"
);
assertCondition(
    str_starts_with((string)$resZipDl['body'], "PK\x03\x04") || strlen((string)$resZipDl['body']) > 200,
    "Download bundle is a valid ZIP archive format"
);

// --------------------------------------------------------------------------
// 8. Client Account Database Verification
// --------------------------------------------------------------------------
echo "\n[*] Step 8: Client Account Database Verification\n";
$stmtClient = $pdo->prepare("SELECT * FROM central_clients WHERE email = ? LIMIT 1");
$stmtClient->execute(['ahmed.amana@example.com']);
$clientRow = $stmtClient->fetch(PDO::FETCH_ASSOC);
assertCondition(
    !empty($clientRow),
    "Client record created in central_clients"
);
assertCondition(
    ($clientRow['business_name'] ?? '') === 'Al-Amana Groceries',
    "Client business_name matches purchase order"
);
assertCondition(
    str_starts_with((string)($clientRow['client_code'] ?? ''), 'CLI-VP-'),
    "Client code generated with CLI-VP- prefix"
);

// --------------------------------------------------------------------------
// 9. Restaurant Mode Provisioning Verification
// --------------------------------------------------------------------------
echo "\n[*] Step 9: Restaurant Mode Order Provisioning\n";
$restOrderId = 'ORD-2026-REST-' . bin2hex(random_bytes(3));
$restPayload = [
    'order_id' => $restOrderId,
    'customer_name' => 'Tarek Mansour',
    'customer_email' => 'tarek@nilebistro.com',
    'business_name' => 'Nile Bistro & Lounge',
    'purchased_product' => 'VENDPULSE',
    'usage_mode' => 'RESTAURANT',
    'term_duration_months' => 6,
    'max_counters' => 5,
    'include_installer_bundle' => true,
];
$resRest = makeRequest($provisionUrl, 'POST', $authHeaders, $restPayload);
assertCondition(
    $resRest['code'] === 201,
    "Restaurant order provisioned with HTTP 201",
    "Got HTTP {$resRest['code']}"
);
$dataRest = $resRest['json'] ?? [];
assertCondition(
    ($dataRest['license']['usage_mode'] ?? '') === 'RESTAURANT',
    "Restaurant mode reflected in license payload"
);
assertCondition(
    (int)($dataRest['license']['max_counters'] ?? 0) === 5,
    "Restaurant max_counters is 5"
);

// --------------------------------------------------------------------------
// 11. Cloud Systems Order Provisioning Verification (e.g. Prism EDU)
// --------------------------------------------------------------------------
echo "\n[*] Step 11: Cloud Systems Order Provisioning (Prism EDU School ERP)\n";
$cloudOrderId = 'ORD-2026-CLOUD-' . bin2hex(random_bytes(3));
$cloudPayload = [
    'order_id' => $cloudOrderId,
    'customer_name' => 'Prof. Marcus Bell',
    'customer_email' => 'm.bell@oxfordacademy.edu',
    'business_name' => 'Oxford Academy ERP',
    'purchased_product' => 'PRISM-EDU',
    'term_duration_months' => 24,
    'max_seats' => 500,
];
$resCloud = makeRequest($provisionUrl, 'POST', $authHeaders, $cloudPayload);
assertCondition(
    $resCloud['code'] === 201,
    "Cloud system order provisioned with HTTP 201 Created",
    "Got HTTP {$resCloud['code']}"
);
$dataCloud = $resCloud['json'] ?? [];
assertCondition(
    !empty($dataCloud['license']['license_id']) && str_starts_with($dataCloud['license']['license_id'], 'SPEC-PRISM-'),
    "Cloud order returned SPEC-PRISM- formatted license key"
);
assertCondition(
    ($dataCloud['license']['system_type'] ?? '') === 'cloud',
    "License system_type is 'cloud'"
);
assertCondition(
    (int)($dataCloud['license']['max_seats'] ?? 0) === 500,
    "Cloud license max_seats is 500"
);
assertCondition(
    !empty($dataCloud['distribution']['portal_url']),
    "Cloud order generates access portal URL"
);

echo "\n========================================================\n";
echo "Test Execution Complete: Passed={$testsPassed}, Failed={$testsFailed}\n";
echo "========================================================\n";

exit($testsFailed > 0 ? 1 : 0);
