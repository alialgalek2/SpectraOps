<?php
declare(strict_types=1);

/**
 * Automated Verification Suite for VendPulse Offline Cryptographic License Generator
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Services/VendPulseLicenseService.php';

use FleetHub\Database;
use App\Services\VendPulseLicenseService;

$db = Database::getConnection();
$service = new VendPulseLicenseService($db);

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
echo "Running VendPulse Offline Cryptographic License Tests\n";
echo "========================================================\n\n";

// 1. Key Infrastructure Tests
$pubKey = $service->getPublicKey();
assertCondition(!empty($pubKey) && str_contains($pubKey, 'BEGIN PUBLIC KEY'), "Public key exists and is valid PEM");
assertCondition(!str_contains($pubKey, 'PRIVATE KEY'), "Public key does not leak private key material");

// 2. Canonical Payload & Tier Resolution Tests
$retailMods = $service->getDefaultModulesForTier('RETAIL_BASIC');
assertCondition($retailMods === ["catalog_grid", "standard_pos", "basic_receipt"], "RETAIL_BASIC defaults to standard retail modules");

$superMods = $service->getDefaultModulesForTier('SUPERMARKET');
assertCondition($superMods === ["barcode_rapid_stream", "scale_comm", "multi_till", "cashier_shifts"], "SUPERMARKET defaults to barcode rapid stream and scale modules");

$enterpriseMods = $service->getDefaultModulesForTier('ENTERPRISE_WHOLESALE');
assertCondition($enterpriseMods === ["multi_warehouse", "b2b_invoices", "credit_accounts", "institutional_branding"], "ENTERPRISE_WHOLESALE defaults to wholesale modules");

// 3. License Generation & Signing Pipeline
$clientName = "Al-Amana Supermarket - Branch 1";
$licenseId = $service->generateUniqueLicenseId();
assertCondition(str_starts_with($licenseId, 'VP-LIC-2026-'), "Generated License ID starts with VP-LIC-2026- prefix");

$generated = $service->issueLicense([
    'client_name' => $clientName,
    'license_id' => $licenseId,
    'machine_hwid' => 'HWID-AMANA-POS-01',
    'tier' => 'SUPERMARKET',
    'max_counters' => 3,
    'expires_at' => time() + 31536000, // 1 year
]);

assertCondition(!empty($generated['license_id']), "License generated with valid ID");
assertCondition($generated['payload']['issuer'] === 'SpectraOps', "Payload issuer is SpectraOps");
assertCondition($generated['payload']['product'] === 'VENDPULSE', "Payload product is VENDPULSE");
assertCondition($generated['payload']['tier'] === 'SUPERMARKET', "Payload tier is SUPERMARKET");
assertCondition($generated['payload']['max_counters'] === 3, "Payload max_counters matches input");
assertCondition(!empty($generated['signature']), "Cryptographic signature is generated (base64)");
assertCondition(!empty($generated['license_bundle']), "License bundle string is generated");

// 4. Verification Pipeline
$verifyResult = $service->verifyLicenseBundle($generated['license_bundle']);
assertCondition($verifyResult['valid'] === true, "Cryptographic verification of authentic bundle succeeds");
assertCondition($verifyResult['payload']['client_name'] === $clientName, "Verified payload contains correct client name");

// 5. Tamper Detection
$tamperedBundle = $generated['license_bundle'];
$decoded = json_decode($tamperedBundle, true);
$decoded['payload']['max_counters'] = 99; // Tamper counters
$tamperedBundleJson = json_encode($decoded);
$tamperVerify = $service->verifyLicenseBundle($tamperedBundleJson);
assertCondition($tamperVerify['valid'] === false, "Tampered payload is cryptographically rejected");

// 6. Expired License Verification
$expiredGen = $service->issueLicense([
    'client_name' => 'Expired Test Shop',
    'tier' => 'RETAIL_BASIC',
    'expires_at' => time() - 3600, // Expired 1 hour ago
]);
$expiredVerify = $service->verifyLicenseBundle($expiredGen['license_bundle']);
assertCondition($expiredVerify['valid'] === false && $expiredVerify['status'] === 'expired', "Expired license correctly flagged as expired");

// 7. Perpetual License Support
$perpetualGen = $service->issueLicense([
    'client_name' => 'Perpetual Distribution Co.',
    'tier' => 'ENTERPRISE_WHOLESALE',
    'expires_at' => null, // Perpetual
]);
$perpetualVerify = $service->verifyLicenseBundle($perpetualGen['license_bundle']);
assertCondition($perpetualVerify['valid'] === true && $perpetualVerify['payload']['expires_at'] === null, "Perpetual license verifies successfully with null expires_at");

// 8. Hardware Lock Verification
$hwidVerifyMatch = $service->verifyLicenseBundle($generated['license_bundle'], 'HWID-AMANA-POS-01');
assertCondition($hwidVerifyMatch['valid'] === true, "Matching HWID passes hardware lock verification");

$hwidVerifyMismatch = $service->verifyLicenseBundle($generated['license_bundle'], 'DIFFERENT-UNAUTHORIZED-HWID');
assertCondition($hwidVerifyMismatch['valid'] === false && $hwidVerifyMismatch['status'] === 'hwid_mismatch', "Mismatched HWID is rejected by hardware lock verification");

// 9. Database Persistence & Lookup
$retrieved = $service->getLicenseById($generated['id']);
assertCondition($retrieved !== null && $retrieved['license_id'] === $licenseId, "License retrieved from database by ID");

$retrievedByKey = $service->getLicenseByKey($licenseId);
assertCondition($retrievedByKey !== null && $retrievedByKey['client_name'] === $clientName, "License retrieved from database by license_id");

// 10. Status Management (Revocation)
$revoked = $service->revokeLicense($generated['id'], 'Contract terminated');
assertCondition($revoked === true, "License revocation succeeds");
$afterRevoke = $service->getLicenseById($generated['id']);
assertCondition($afterRevoke['status'] === 'revoked', "Database reflects revoked status");

// 11. System Usage Mode Tests (RETAIL_SHOP, SUPERMARKET, RESTAURANT)
$modes = $service->getAvailableUsageModes();
assertCondition(isset($modes['RETAIL_SHOP']) && isset($modes['SUPERMARKET']) && isset($modes['RESTAURANT']), "All 3 system usage modes are defined");

// 11a. Default mode when omitted is RETAIL_SHOP
$defaultModeGen = $service->issueLicense([
    'client_name' => 'Default Mode Shop',
    'tier' => 'RETAIL_BASIC',
]);
assertCondition($defaultModeGen['usage_mode'] === 'RETAIL_SHOP', "Omitted usage_mode defaults to RETAIL_SHOP");
assertCondition($defaultModeGen['payload']['usage_mode'] === 'RETAIL_SHOP', "Payload reflects default RETAIL_SHOP usage mode");

// 11b. Explicit RESTAURANT mode
$restaurantGen = $service->issueLicense([
    'client_name' => 'Bistro Gourmet & Lounge',
    'tier' => 'ENTERPRISE_WHOLESALE',
    'usage_mode' => 'RESTAURANT',
    'max_counters' => 5,
]);
assertCondition($restaurantGen['usage_mode'] === 'RESTAURANT', "License issued with explicit RESTAURANT usage mode");
assertCondition($restaurantGen['payload']['usage_mode'] === 'RESTAURANT', "Signed payload contains RESTAURANT usage mode");

$restaurantVerify = $service->verifyLicenseBundle($restaurantGen['license_bundle']);
assertCondition($restaurantVerify['valid'] === true, "Cryptographic verification of RESTAURANT bundle succeeds");
assertCondition($restaurantVerify['payload']['usage_mode'] === 'RESTAURANT', "Verified payload preserves RESTAURANT usage mode");

// 11c. Tamper detection on usage_mode
$tamperedModeBundle = json_decode($restaurantGen['license_bundle'], true);
$tamperedModeBundle['payload']['usage_mode'] = 'SUPERMARKET'; // Attempt privilege escalation / mode hijacking
$tamperModeVerify = $service->verifyLicenseBundle(json_encode($tamperedModeBundle));
assertCondition($tamperModeVerify['valid'] === false, "Altered usage_mode causes cryptographic signature verification to fail");

// 11d. Database filtering by usage_mode
$filteredRestaurants = $service->listLicenses(null, null, null, 'RESTAURANT');
$allRestaurant = true;
foreach ($filteredRestaurants as $row) {
    if (($row['usage_mode'] ?? '') !== 'RESTAURANT') {
        $allRestaurant = false;
        break;
    }
}
assertCondition($allRestaurant && count($filteredRestaurants) > 0, "Database listLicenses filters properly by usage_mode");

echo "\n========================================================\n";
echo "Test Results: {$testsPassed} Passed, {$testsFailed} Failed\n";
echo "========================================================\n";

if ($testsFailed > 0) {
    exit(1);
}
exit(0);
