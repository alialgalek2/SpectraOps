<?php
declare(strict_types=1);

/**
 * VendPulse Offline Cryptographic License CLI Tool
 *
 * Usage:
 *   php bin/vendpulse_license.php export-key [--out=public.pem]
 *   php bin/vendpulse_license.php generate --client="Al-Amana Supermarket" [--tier=SUPERMARKET] [--counters=3] [--expires="2027-10-18"] [--hwid="HWID-1234"] [--out=license.vpl]
 *   php bin/vendpulse_license.php verify --file=license.vpl [--hwid="HWID-1234"]
 *   php bin/vendpulse_license.php list [--tier=SUPERMARKET] [--search="Amana"]
 *   php bin/vendpulse_license.php revoke <license_id> [--reason="Subscription expired"]
 */

require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Services/VendPulseLicenseService.php';

use FleetHub\Database;
use App\Services\VendPulseLicenseService;

$pdo = Database::getConnection();
$service = new VendPulseLicenseService($pdo);

$argv = $_SERVER['argv'] ?? [];
$action = $argv[1] ?? 'help';

// Parse options like --key=val or --flag
$options = [];
for ($i = 2; $i < count($argv); $i++) {
    $arg = $argv[$i];
    if (str_starts_with($arg, '--')) {
        $eqPos = strpos($arg, '=');
        if ($eqPos !== false) {
            $key = substr($arg, 2, $eqPos - 2);
            $val = substr($arg, $eqPos + 1);
            $options[$key] = $val;
        } else {
            $options[substr($arg, 2)] = true;
        }
    } elseif (!isset($options['_args'])) {
        $options['_args'] = [];
        $options['_args'][] = $arg;
    } else {
        $options['_args'][] = $arg;
    }
}

switch (strtolower($action)) {
    case 'export-key':
    case 'export-public-key':
    case 'public-key':
        $pubKey = $service->getPublicKey();
        $meta = $service->exportPublicKeyMetadata();
        $outFile = $options['out'] ?? $options['file'] ?? null;

        if ($outFile) {
            file_put_contents($outFile, $pubKey);
            echo "✓ Public Key exported successfully to: {$outFile}\n";
            echo "  Algorithm:   {$meta['algorithm']}\n";
            echo "  Bits:        {$meta['bits']}\n";
            echo "  Fingerprint: {$meta['public_key_fingerprint']}\n";
        } else {
            echo $pubKey;
        }
        break;

    case 'generate':
    case 'issue':
        $client = $options['client'] ?? $options['name'] ?? null;
        if (!$client) {
            fwrite(STDERR, "Error: --client is required (e.g. --client=\"Al-Amana Supermarket\").\n");
            exit(1);
        }

        $tier = strtoupper((string)($options['tier'] ?? 'SUPERMARKET'));
        $usageMode = strtoupper((string)($options['mode'] ?? $options['usage-mode'] ?? $options['usage_mode'] ?? 'RETAIL_SHOP'));
        $counters = (int)($options['counters'] ?? $options['terminals'] ?? 1);
        $hwid = $options['hwid'] ?? $options['fingerprint'] ?? null;
        $expires = $options['expires'] ?? $options['expiry'] ?? null;
        $outFile = $options['out'] ?? $options['file'] ?? null;

        try {
            $lic = $service->issueLicense([
                'client_name' => $client,
                'tier' => $tier,
                'usage_mode' => $usageMode,
                'max_counters' => $counters,
                'machine_hwid' => $hwid,
                'expires_at' => $expires,
            ]);

            echo "\n=======================================================\n";
            echo " VendPulse Offline Cryptographic License Issued\n";
            echo "=======================================================\n";
            echo " License ID:     {$lic['license_id']}\n";
            echo " Client Name:    {$lic['client_name']}\n";
            echo " Operating Tier: {$lic['tier']}\n";
            echo " Usage Mode:     {$lic['usage_mode']}\n";
            echo " Max Counters:   {$lic['max_counters']}\n";
            echo " Hardware Lock:  " . ($lic['machine_hwid'] ?: 'Unconstrained (Offline Any-Device)') . "\n";
            echo " Expiration:     " . ($lic['expires_at'] ? date('Y-m-d H:i:s T', $lic['expires_at']) : 'Perpetual (No Expiry)') . "\n";
            echo " Modules:        " . implode(', ', $lic['allowed_modules']) . "\n";
            echo " Signature (b64):" . substr($lic['signature'], 0, 32) . "...\n";
            echo "=======================================================\n\n";

            if ($outFile) {
                file_put_contents($outFile, $lic['license_bundle']);
                echo "✓ License bundle saved to: {$outFile}\n";
            } else {
                echo "License Bundle (JSON):\n";
                echo $lic['license_bundle'] . "\n";
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "Error issuing license: " . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'verify':
        $bundleStr = null;
        if (isset($options['file']) && file_exists($options['file'])) {
            $bundleStr = file_get_contents($options['file']);
        } elseif (isset($options['license'])) {
            $bundleStr = $options['license'];
        } elseif (!empty($options['_args'][0])) {
            $argVal = $options['_args'][0];
            if (file_exists($argVal)) {
                $bundleStr = file_get_contents($argVal);
            } else {
                $bundleStr = $argVal;
            }
        }

        if (!$bundleStr) {
            fwrite(STDERR, "Error: Must supply --file=<path> or --license=<string> to verify.\n");
            exit(1);
        }

        $testHwid = $options['hwid'] ?? null;
        $res = $service->verifyLicenseBundle($bundleStr, $testHwid);

        if ($res['valid']) {
            echo "✓ VALID LICENSE: Authenticity & integrity cryptographically verified.\n";
            echo "  Client:     " . ($res['payload']['client_name'] ?? 'N/A') . "\n";
            echo "  License ID: " . ($res['payload']['license_id'] ?? 'N/A') . "\n";
            echo "  Tier:       " . ($res['payload']['tier'] ?? 'N/A') . "\n";
            echo "  Usage Mode: " . ($res['payload']['usage_mode'] ?? 'RETAIL_SHOP') . "\n";
            echo "  Counters:   " . ($res['payload']['max_counters'] ?? 'N/A') . "\n";
            echo "  Expires:    " . (!empty($res['payload']['expires_at']) ? date('Y-m-d H:i:s T', $res['payload']['expires_at']) : 'Perpetual') . "\n";
            echo "  HWID Lock:  " . ($res['payload']['machine_hwid'] ?? 'None (Unconstrained)') . "\n";
            echo "  Modules:    " . implode(', ', $res['payload']['allowed_modules'] ?? []) . "\n";
            exit(0);
        } else {
            fwrite(STDERR, "✗ INVALID LICENSE: {$res['error']} (Status: {$res['status']})\n");
            exit(1);
        }
        break;

    case 'list':
        $search = $options['search'] ?? null;
        $tier = $options['tier'] ?? null;
        $mode = $options['mode'] ?? $options['usage-mode'] ?? null;
        $status = $options['status'] ?? null;
        $licenses = $service->listLicenses($search, $tier, $status, $mode);

        echo "Found " . count($licenses) . " VendPulse offline license(s):\n\n";
        printf("%-18s | %-22s | %-16s | %-14s | %-6s | %-12s | %-8s\n", "License ID", "Client", "Tier", "Usage Mode", "MaxReg", "Expires", "Status");
        echo str_repeat('-', 110) . "\n";
        foreach ($licenses as $l) {
            $expStr = !empty($l['expires_at']) ? date('Y-m-d', (int)$l['expires_at']) : 'Perpetual';
            printf(
                "%-18s | %-22s | %-16s | %-14s | %-6d | %-12s | %-8s\n",
                substr($l['license_id'], 0, 18),
                substr($l['client_name'], 0, 22),
                substr($l['tier'], 0, 16),
                substr($l['usage_mode'] ?? 'RETAIL_SHOP', 0, 14),
                (int)$l['max_counters'],
                $expStr,
                strtoupper($l['status'])
            );
        }
        break;

    case 'revoke':
        $targetId = $options['_args'][0] ?? $options['id'] ?? null;
        if (!$targetId) {
            fwrite(STDERR, "Error: Specify license ID to revoke (e.g. php bin/vendpulse_license.php revoke VP-LIC-2026-XXXXX).\n");
            exit(1);
        }
        $reason = $options['reason'] ?? 'Revoked via CLI';
        $ok = $service->revokeLicense($targetId, $reason);
        if ($ok) {
            echo "✓ License {$targetId} revoked successfully.\n";
        } else {
            fwrite(STDERR, "✗ Failed to revoke license {$targetId}.\n");
            exit(1);
        }
        break;

    case 'help':
    default:
        echo "SpectraOps VendPulse Offline Cryptographic License CLI\n";
        echo "====================================================\n";
        echo "Commands:\n";
        echo "  export-key [--out=public.pem]             Export SpectraOps Public Key for VendPulse\n";
        echo "  generate --client=\"Name\" [options]        Generate a cryptographically signed license\n";
        echo "      --tier=RETAIL_BASIC|SUPERMARKET|ENTERPRISE_WHOLESALE (Default: SUPERMARKET)\n";
        echo "      --mode=RETAIL_SHOP|SUPERMARKET|RESTAURANT            (Default: RETAIL_SHOP)\n";
        echo "      --counters=N                          Max concurrent cashier terminals (Default: 1)\n";
        echo "      --expires=YYYY-MM-DD                  Expiration date (Omit for Perpetual)\n";
        echo "      --hwid=UUID                           Optional hardware machine lock\n";
        echo "      --out=file.vpl                        Save signed bundle to file\n";
        echo "  verify --file=file.vpl [--hwid=UUID]      Verify license signature and expiration\n";
        echo "  list [--search=term] [--tier=TIER] [--mode=MODE] List issued licenses\n";
        echo "  revoke <license_id> [--reason=\"...\"]      Revoke an active license\n";
        break;
}
