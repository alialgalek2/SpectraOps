<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/Services/VendPulseLicenseService.php';
require_once dirname(__DIR__, 2) . '/src/Services/InstallerDistributionService.php';
require_once dirname(__DIR__, 2) . '/src/Services/OrderProvisioningService.php';
require_once dirname(__DIR__, 2) . '/src/Services/LicenseManager.php';

use FleetHub\Database;
use App\Services\OrderProvisioningService;
use App\Services\VendPulseLicenseService;
use App\Services\InstallerDistributionService;
use App\Services\LicenseManager;

$pdo = Database::getConnection();
$orderService = new OrderProvisioningService($pdo);
$licenseService = new VendPulseLicenseService($pdo);
$installerService = new InstallerDistributionService();
$cloudLicenseManager = new LicenseManager($pdo);

$token = trim((string)($_GET['token'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$fileParam = trim((string)($_GET['file'] ?? ''));

// Validate token
$order = null;
if ($token !== '') {
    $order = $orderService->getOrderByDownloadToken($token);
}

// Determine if this is a Cloud System or Offline VendPulse
$licenseId = (string)($order['license_id'] ?? '');
$isCloud = false;
$cloudLic = null;
$cloudRelease = null;

if ($order && !empty($licenseId)) {
    $prodUpper = strtoupper((string)($order['purchased_product'] ?? ''));
    $isOffline = ($prodUpper === 'VENDPULSE' || $prodUpper === 'VP' || str_starts_with($licenseId, 'VP-'));
    $isCloud = (!$isOffline) || str_starts_with($licenseId, 'SPEC-');

    if ($isCloud) {
        $cloudLic = $cloudLicenseManager->getLicense($licenseId);
        if ($cloudLic && !empty($cloudLic['product_id'])) {
            $relStmt = $pdo->prepare("SELECT * FROM central_releases WHERE product_id = ? AND is_active = 1 ORDER BY released_at DESC LIMIT 1");
            $relStmt->execute([$cloudLic['product_id']]);
            $cloudRelease = $relStmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// --------------------------------------------------------------------------
// 1. Download Actions
// --------------------------------------------------------------------------
if ($order && empty($order['is_expired'])) {
    if ($isCloud) {
        // Cloud Action A: Download Cloud License JSON file
        if ($action === 'license') {
            $orderService->recordDownload($token);
            while (ob_get_level()) {
                ob_end_clean();
            }
            $filename = strtolower((string)($cloudLic['product_slug'] ?? 'cloud')) . '_license.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo json_encode([
                'product_name' => $cloudLic['product_name'] ?? $order['purchased_product'],
                'product_code' => $cloudLic['product_code'] ?? $order['purchased_product'],
                'license_key' => $licenseId,
                'client_name' => $order['business_name'],
                'client_email' => $order['customer_email'],
                'license_type' => $cloudLic['license_type'] ?? 'perpetual',
                'max_seats' => (int)($cloudLic['max_seats'] ?? $order['max_counters']),
                'expires_at' => $cloudLic['expires_at'] ?? null,
                'status' => $cloudLic['status'] ?? 'unactivated',
                'telemetry_hub_url' => $orderService->getPublicUrl(),
                'issued_at' => $cloudLic['created_at'] ?? $order['created_at'],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Cloud Action B: Download Release Archive (.zip)
        if ($action === 'download_release') {
            if ($cloudRelease && !empty($cloudRelease['package_filename'])) {
                $possiblePaths = [
                    dirname(__DIR__, 2) . '/public/downloads/updates/' . $cloudRelease['package_filename'],
                    dirname(__DIR__, 2) . '/downloads/updates/' . $cloudRelease['package_filename'],
                ];
                $pkgPath = null;
                foreach ($possiblePaths as $p) {
                    if (file_exists($p)) {
                        $pkgPath = $p;
                        break;
                    }
                }
                if ($pkgPath) {
                    $orderService->recordDownload($token);
                    while (ob_get_level()) {
                        ob_end_clean();
                    }
                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="' . basename($pkgPath) . '"');
                    header('Content-Length: ' . filesize($pkgPath));
                    header('Cache-Control: no-cache, no-store, must-revalidate');
                    readfile($pkgPath);
                    exit;
                }
            }
        }
    } else {
        // Offline VendPulse Download Actions
        $licenseData = !empty($order['license_id']) 
            ? $licenseService->getLicenseByCode((string)$order['license_id']) 
            : null;

        $licenseBundle = [];
        if (!empty($licenseData['license_bundle'])) {
            $licenseBundle = json_decode((string)$licenseData['license_bundle'], true) ?: [];
        }

        // Action A: Download License JSON file
        if ($action === 'license') {
            $orderService->recordDownload($token);
            while (ob_get_level()) {
                ob_end_clean();
            }
            $filename = 'vendpulse_license.json';
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            echo json_encode($licenseBundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Action B: Download Installer Executable or Bundle
        if ($action === 'download') {
            $orderService->recordDownload($token);
            if ($fileParam === 'server') {
                $installerService->streamInstallerFile('server');
                exit;
            } elseif ($fileParam === 'client') {
                $installerService->streamInstallerFile('client');
                exit;
            } elseif ($fileParam === 'bundle') {
                $installerService->streamDeploymentBundleZip($order, $licenseBundle);
                exit;
            }
        }
    }
}

// --------------------------------------------------------------------------
// 2. Render Portal UI (or Error State)
// --------------------------------------------------------------------------
$isAccessDenied = (!$order || !empty($order['is_expired']));
$serverInfo = $installerService->getInstallerInfo('server');
$clientInfo = $installerService->getInstallerInfo('client');

$license = null;
$licenseBundleJson = '';
if ($order && !empty($order['license_id']) && !$isCloud) {
    $license = $licenseService->getLicenseByCode((string)$order['license_id']);
    if (!empty($license['license_bundle'])) {
        $licenseBundleJson = $license['license_bundle'];
    }
}

$usageMode = $order['usage_mode'] ?? ($isCloud ? 'CLOUD_HOSTED' : 'RETAIL_SHOP');
$modeInfo = match ($usageMode) {
    'SUPERMARKET' => [
        'icon' => '🛒',
        'badge' => 'Supermarket POS',
        'desc' => 'High-throughput barcode continuous stream scanning, digital weighing scale interface & multi-till balancing.',
        'color' => '#10b981',
    ],
    'RESTAURANT' => [
        'icon' => '🍽️',
        'badge' => 'Restaurant & Hospitality',
        'desc' => 'Dining floor plan management, Kitchen Order Tickets (KOT), course scheduling & split bills.',
        'color' => '#f59e0b',
    ],
    'CLOUD_HOSTED' => [
        'icon' => '☁️',
        'badge' => 'Cloud System SaaS',
        'desc' => 'Multi-tenant web platform with centralized fleet telemetry, dynamic cryptographic licenses & instant OTA updates.',
        'color' => '#0dc0e7',
    ],
    default => [
        'icon' => $isCloud ? '☁️' : '🛍️',
        'badge' => $isCloud ? 'Cloud System' : 'Retail Shop',
        'desc' => $isCloud ? 'Cloud instance with centralized fleet telemetry and health monitoring.' : 'Counter sales, touch catalog grid, product size/color variants, and barcode printing.',
        'color' => '#3b82f6',
    ],
};

$isPerpetual = $isCloud ? empty($cloudLic['expires_at']) : empty($license['expires_at']);
$expiryFormatted = $isPerpetual 
    ? 'Perpetual Lifetime License' 
    : ($isCloud && !empty($cloudLic['expires_at']) ? htmlspecialchars((string)$cloudLic['expires_at']) : date('F j, Y (H:i T)', (int)($license['expires_at'] ?? time())));

$productDisplayName = $isCloud ? ($cloudLic['product_name'] ?? $order['purchased_product']) : 'VendPulse POS Engine';
$productCode = $isCloud ? ($cloudLic['product_code'] ?? $order['purchased_product']) : 'VP';
$portalTitle = $isAccessDenied 
    ? 'Access Denied — SpectraOps Portal' 
    : htmlspecialchars((string)$order['business_name']) . " — {$productDisplayName} Self-Service Portal";
?>
<!DOCTYPE html>
<html lang="en" class="theme-dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $portalTitle ?></title>
    <meta name="description" content="Secure self-service deployment portal and license distribution for SpectraOps Fleet products.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="icon" type="image/svg+xml" href="../spectraops-icon.svg">
    <style>
        :root {
            --bg: #090d16;
            --bg-card: rgba(18, 24, 38, 0.85);
            --bg-card-hover: rgba(26, 35, 54, 0.95);
            --border: rgba(255, 255, 255, 0.08);
            --border-glow: rgba(13, 192, 231, 0.35);
            --accent: #0dc0e7;
            --accent-glow: rgba(13, 192, 231, 0.25);
            --accent-purple: #8b5cf6;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --text-dim: #64748b;
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.12);
            --warning: #f59e0b;
            --warning-bg: rgba(245, 158, 11, 0.12);
            --danger: #ef4444;
            --radius-lg: 18px;
            --radius-md: 12px;
            --font-main: 'Outfit', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-main);
            background-color: var(--bg);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            line-height: 1.5;
            background-image: 
                radial-gradient(circle at 50% 0%, rgba(13, 192, 231, 0.15) 0%, transparent 60%),
                radial-gradient(circle at 10% 30%, rgba(139, 92, 246, 0.10) 0%, transparent 50%),
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 100% 100%, 100% 100%, 36px 36px, 36px 36px;
        }

        .container {
            width: 100%;
            max-width: 1120px;
            margin: 0 auto;
            padding: 32px 20px 60px;
        }

        /* Top Brand Header */
        .portal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 24px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            backdrop-filter: blur(16px);
            margin-bottom: 28px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.35);
        }

        .brand-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo-badge {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #0dc0e7, #6366f1);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            box-shadow: 0 4px 16px var(--accent-glow);
        }

        .brand-title {
            font-size: 1.35rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .brand-title span {
            color: var(--accent);
        }

        .brand-subtitle {
            font-size: 0.82rem;
            color: var(--text-muted);
        }

        .order-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            background: var(--success-bg);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: var(--success);
            border-radius: 9999px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background: var(--success);
            border-radius: 50%;
            box-shadow: 0 0 10px var(--success);
            animation: pulseAnimation 2s infinite;
        }

        @keyframes pulseAnimation {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.4; transform: scale(0.85); }
        }

        /* Hero Banner */
        .hero-card {
            background: linear-gradient(180deg, rgba(18, 24, 38, 0.95) 0%, rgba(13, 17, 28, 0.9) 100%);
            border: 1px solid var(--border);
            border-top: 2px solid var(--accent);
            border-radius: var(--radius-lg);
            padding: 32px 36px;
            margin-bottom: 28px;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.4);
            position: relative;
            overflow: hidden;
        }

        .hero-card::after {
            content: '';
            position: absolute;
            top: -60px;
            right: -60px;
            width: 220px;
            height: 220px;
            background: radial-gradient(circle, var(--accent-glow) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-meta-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .order-tag {
            font-family: var(--font-mono);
            font-size: 0.82rem;
            background: rgba(255, 255, 255, 0.06);
            padding: 4px 10px;
            border-radius: 6px;
            color: var(--accent);
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .hero-title {
            font-size: 2.1rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin-bottom: 8px;
            color: #ffffff;
        }

        .hero-desc {
            font-size: 1.05rem;
            color: var(--text-muted);
            max-width: 720px;
            margin-bottom: 24px;
        }

        .spec-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .spec-item {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.05);
            padding: 14px 16px;
            border-radius: var(--radius-md);
        }

        .spec-label {
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-dim);
            margin-bottom: 4px;
            font-weight: 600;
        }

        .spec-value {
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        /* Section Titles */
        .section-heading {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Download Cards Grid */
        .download-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .download-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
            position: relative;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }

        .download-card:hover {
            transform: translateY(-3px);
            border-color: var(--accent);
            box-shadow: 0 12px 32px rgba(13, 192, 231, 0.15);
        }

        .card-header {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 16px;
        }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(13, 192, 231, 0.1);
            border: 1px solid rgba(13, 192, 231, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            flex-shrink: 0;
        }

        .card-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: #ffffff;
        }

        .card-version {
            font-size: 0.8rem;
            color: var(--accent);
            font-family: var(--font-mono);
            font-weight: 600;
        }

        .card-desc {
            font-size: 0.88rem;
            color: var(--text-muted);
            margin-bottom: 20px;
            line-height: 1.45;
        }

        .card-meta {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 8px;
            padding: 10px 12px;
            margin-bottom: 20px;
            font-size: 0.78rem;
            font-family: var(--font-mono);
            color: var(--text-dim);
        }

        .meta-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 4px;
        }

        .meta-line:last-child {
            margin-bottom: 0;
        }

        .meta-hash {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 170px;
            color: var(--text-muted);
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid transparent;
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #0dc0e7, #0284c7);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(13, 192, 231, 0.35);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #22d3ee, #0369a1);
            box-shadow: 0 6px 20px rgba(13, 192, 231, 0.5);
            transform: translateY(-1px);
        }

        .btn-bundle {
            background: linear-gradient(135deg, #8b5cf6, #6366f1);
            color: #ffffff;
            box-shadow: 0 4px 16px rgba(139, 92, 246, 0.35);
        }

        .btn-bundle:hover {
            background: linear-gradient(135deg, #a78bfa, #4f46e5);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.5);
            transform: translateY(-1px);
        }

        .btn-outline {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.15);
            color: var(--text-main);
        }

        .btn-outline:hover {
            background: rgba(255, 255, 255, 0.1);
            border-color: var(--accent);
            color: #ffffff;
        }

        /* License Showcase Box */
        .license-box {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            padding: 28px;
            margin-bottom: 32px;
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }

        .license-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .license-id-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .license-key-display {
            font-family: var(--font-mono);
            font-size: 1.25rem;
            font-weight: 700;
            color: #38bdf8;
            letter-spacing: 0.05em;
            background: rgba(56, 189, 248, 0.1);
            padding: 8px 16px;
            border-radius: 8px;
            border: 1px dashed rgba(56, 189, 248, 0.3);
        }

        .license-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.85rem;
            width: auto;
        }

        .license-code-block {
            background: #050810;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            padding: 16px;
            font-family: var(--font-mono);
            font-size: 0.8rem;
            color: #93c5fd;
            overflow-x: auto;
            max-height: 220px;
            white-space: pre;
            margin-top: 14px;
        }

        /* Deployment Walkthrough Steps */
        .steps-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }

        .step-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 20px;
            position: relative;
        }

        .step-number {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: var(--accent-glow);
            border: 1px solid var(--accent);
            color: var(--accent);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.95rem;
            margin-bottom: 12px;
        }

        .step-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 8px;
            color: #ffffff;
        }

        .step-text {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.45;
        }

        /* Error View */
        .error-card {
            background: var(--bg-card);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-top: 3px solid var(--danger);
            border-radius: var(--radius-lg);
            padding: 48px 36px;
            text-align: center;
            max-width: 580px;
            margin: 60px auto;
            box-shadow: 0 16px 40px rgba(0, 0, 0, 0.5);
        }

        .error-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }

        .error-title {
            font-size: 1.6rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: #ffffff;
        }

        .error-desc {
            font-size: 0.95rem;
            color: var(--text-muted);
            margin-bottom: 24px;
            line-height: 1.5;
        }

        /* Footer */
        .portal-footer {
            margin-top: auto;
            text-align: center;
            padding: 24px 0 12px;
            color: var(--text-dim);
            font-size: 0.82rem;
            border-top: 1px solid rgba(255, 255, 255, 0.05);
        }

        /* Toast Feedback */
        #toastNotice {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #10b981;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            box-shadow: 0 8px 24px rgba(16, 185, 129, 0.4);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            z-index: 9999;
        }
        #toastNotice.active {
            transform: translateY(0);
            opacity: 1;
        }
    </style>
</head>
<body>

<div class="container">

    <!-- Brand Header -->
    <header class="portal-header">
        <div class="brand-block">
            <div class="brand-logo-badge"><?= $isCloud ? '☁️' : '⚡' ?></div>
            <div>
                <div class="brand-title"><?= htmlspecialchars($productDisplayName) ?> <span style="font-weight:400; color:var(--text-dim); font-size:1.05rem;"><?= $isCloud ? 'Cloud' : 'Distribution' ?></span></div>
                <div class="brand-subtitle"><?= $isCloud ? 'Automated Customer Fulfillment & Central Telemetry Provisioning' : 'Automated Customer Provisioning & Self-Service Installer Portal' ?></div>
            </div>
        </div>

        <?php if (!$isAccessDenied): ?>
        <div class="order-badge-pill">
            <span class="pulse-dot"></span>
            <span>Order Verified: <?= htmlspecialchars((string)$order['order_id']) ?></span>
        </div>
        <?php endif; ?>
    </header>

    <?php if ($isAccessDenied): ?>
        <!-- Access Denied Card -->
        <div class="error-card">
            <div class="error-icon">🔒</div>
            <h1 class="error-title">Access Denied or Link Expired</h1>
            <p class="error-desc">
                The download token provided in the URL is missing, invalid, or has reached its security expiration window.
                If you recently completed a purchase, please check your receipt email for your active link, or contact customer support for an instant re-issue.
            </p>
            <a href="mailto:support@spectraops.io" class="btn btn-outline" style="width:auto; display:inline-flex;">
                ✉️ Contact Support & Fulfillment
            </a>
        </div>
    <?php else: ?>

        <!-- Hero Card -->
        <div class="hero-card">
            <div class="hero-meta-row">
                <span class="order-tag">ORDER #<?= htmlspecialchars((string)$order['order_id']) ?></span>
                <span class="order-tag" style="color:<?= $modeInfo['color'] ?>;"><?= $modeInfo['icon'] ?> <?= htmlspecialchars($modeInfo['badge']) ?></span>
                <?php if ($isCloud): ?>
                  <span class="order-tag" style="color:#a78bfa;">CATALOG: <?= htmlspecialchars($productCode) ?></span>
                <?php endif; ?>
            </div>

            <h1 class="hero-title"><?= htmlspecialchars((string)$order['business_name']) ?></h1>
            <p class="hero-desc">
              <?php if ($isCloud): ?>
                Welcome to your <?= htmlspecialchars($productDisplayName) ?> deployment portal. Your cloud system license has been generated and provisioned. Use the credentials and deployment instructions below to configure and activate your cloud instance.
              <?php else: ?>
                Welcome to your VendPulse self-service deployment portal. Your offline cryptographic license has been generated and validated. Download the Server Engine and Client installer packages below to start operations.
              <?php endif; ?>
            </p>

            <div class="spec-grid">
                <div class="spec-item">
                    <div class="spec-label">Customer / Contact</div>
                    <div class="spec-value"><?= htmlspecialchars((string)$order['customer_name']) ?></div>
                </div>
                <div class="spec-item">
                    <div class="spec-label"><?= $isCloud ? 'System Architecture' : 'Operating Mode' ?></div>
                    <div class="spec-value" style="color:<?= $modeInfo['color'] ?>;">
                        <?= $modeInfo['icon'] ?> <?= htmlspecialchars($isCloud ? 'Multi-Tenant Web / Cloud SaaS' : $usageMode) ?>
                    </div>
                </div>
                <div class="spec-item">
                    <div class="spec-label"><?= $isCloud ? 'Authorized Seat Capacity' : 'Till / Counter Capacity' ?></div>
                    <div class="spec-value"><?= (int)$order['max_counters'] > 0 ? (int)$order['max_counters'] . ($isCloud ? ' Seats' : ' Station(s)') : 'Unlimited Seats' ?></div>
                </div>
                <div class="spec-item">
                    <div class="spec-label">License Term</div>
                    <div class="spec-value"><?= htmlspecialchars($expiryFormatted) ?></div>
                </div>
            </div>
        </div>

        <?php if ($isCloud): ?>
        <!-- ================= CLOUD SYSTEMS SECTIONS ================= -->
        <!-- Section: Cloud License Credentials -->
        <h2 class="section-heading">🔑 Cloud System License Key</h2>

        <div class="license-box">
            <div class="license-header">
                <div>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:4px;">Cryptographic Activation Key (Domain Lock Protected)</div>
                    <div class="license-id-row">
                        <div class="license-key-display mono" id="licenseKeyText" style="font-size:1.4rem; color:#38bdf8;"><?= htmlspecialchars($licenseId) ?></div>
                    </div>
                </div>

                <div class="license-actions">
                    <button type="button" class="btn btn-outline btn-sm" onclick="copyLicenseBundle()">
                        📋 Copy License Key
                    </button>
                    <a href="?token=<?= urlencode($token) ?>&action=license" class="btn btn-primary btn-sm">
                        📥 Download License JSON
                    </a>
                </div>
            </div>

            <div style="font-size:0.82rem; color:var(--text-muted); line-height:1.5;">
                <span style="color:#10b981;">✓ Validated &amp; Ready:</span> This license is bound to your customer profile and will automatically lock to your assigned production domain upon initial activation.
            </div>
        </div>

        <!-- Section: Cloud Deployment Walkthrough -->
        <h2 class="section-heading">🚀 Cloud Instance Deployment &amp; Activation</h2>

        <div class="steps-container">
            <div class="step-card">
                <div class="step-number">1</div>
                <div class="step-title">Access Instance</div>
                <div class="step-text">
                    Open your web deployment domain in your browser and visit the system activation screen (e.g. <code>https://your-domain/activation.php</code>).
                </div>
            </div>

            <div class="step-card">
                <div class="step-number">2</div>
                <div class="step-title">Activate Key</div>
                <div class="step-text">
                    Enter your Cloud License Key <code><?= htmlspecialchars($licenseId) ?></code> and submit to bind this deployment to your authorized domain.
                </div>
            </div>

            <div class="step-card">
                <div class="step-number">3</div>
                <div class="step-title">Connect Telemetry Hub</div>
                <div class="step-text">
                    Set your Central Telemetry Hub URL to <code><?= htmlspecialchars($orderService->getPublicUrl()) ?></code> for automated error tracking and health heartbeats.
                </div>
            </div>

            <div class="step-card">
                <div class="step-number">4</div>
                <div class="step-title">Fleet Synchronization</div>
                <div class="step-text">
                    Receive seamless over-the-air hotfixes, zero-downtime upgrades, and remote monitoring directly from the centralized hub.
                </div>
            </div>
        </div>

        <?php if ($cloudRelease && !empty($cloudRelease['package_filename'])): ?>
        <!-- Section: Software Release Archive -->
        <h2 class="section-heading">📦 Software Release Archive</h2>
        <div class="download-grid">
            <div class="download-card" style="border-color: rgba(13, 192, 231, 0.4); max-width: 480px;">
                <div>
                    <div class="card-header">
                        <div class="card-icon" style="background:rgba(13,192,231,0.15); border-color:rgba(13,192,231,0.3);">☁️</div>
                        <div>
                            <div class="card-title"><?= htmlspecialchars($productDisplayName) ?> Application Package</div>
                            <div class="card-version">Version <?= htmlspecialchars((string)$cloudRelease['version']) ?> • Official Release</div>
                        </div>
                    </div>
                    <p class="card-desc">
                        <?= htmlspecialchars((string)($cloudRelease['changelog'] ?? 'Complete deployable web application distribution archive.')) ?>
                    </p>
                </div>
                <div>
                    <div class="card-meta">
                        <div class="meta-line"><span>File:</span> <strong><?= htmlspecialchars((string)$cloudRelease['package_filename']) ?></strong></div>
                        <div class="meta-line"><span>SHA256:</span> <span class="meta-hash" title="<?= htmlspecialchars((string)$cloudRelease['package_checksum']) ?>"><?= htmlspecialchars(substr((string)$cloudRelease['package_checksum'], 0, 16)) ?>...</span></div>
                    </div>
                    <a href="?token=<?= urlencode($token) ?>&action=download_release" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        Download Release Package (.zip)
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- ================= OFFLINE VENDPULSE SECTIONS ================= -->
        <!-- Section: Software Installers -->
        <h2 class="section-heading">📦 Software Installer Packages</h2>

        <div class="download-grid">
            <!-- 1. Server Installer -->
            <div class="download-card">
                <div>
                    <div class="card-header">
                        <div class="card-icon">🖥️</div>
                        <div>
                            <div class="card-title">VendPulse Server Setup</div>
                            <div class="card-version">Version <?= htmlspecialchars($serverInfo['version']) ?> • 64-bit</div>
                        </div>
                    </div>
                    <p class="card-desc">
                        <?= htmlspecialchars($serverInfo['description']) ?>
                    </p>
                </div>
                <div>
                    <div class="card-meta">
                        <div class="meta-line"><span>File:</span> <strong><?= htmlspecialchars($serverInfo['filename']) ?></strong></div>
                        <div class="meta-line"><span>Size:</span> <span><?= htmlspecialchars($serverInfo['size_formatted']) ?></span></div>
                        <div class="meta-line"><span>SHA256:</span> <span class="meta-hash" title="<?= htmlspecialchars($serverInfo['sha256']) ?>"><?= htmlspecialchars(substr($serverInfo['sha256'], 0, 16)) ?>...</span></div>
                    </div>
                    <a href="?token=<?= urlencode($token) ?>&action=download&file=server" class="btn btn-primary">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        Download Server (.exe)
                    </a>
                </div>
            </div>

            <!-- 2. Client Terminal Installer -->
            <div class="download-card">
                <div>
                    <div class="card-header">
                        <div class="card-icon" style="background:rgba(99,102,241,0.1); border-color:rgba(99,102,241,0.2);">💳</div>
                        <div>
                            <div class="card-title">POS Counter Client</div>
                            <div class="card-version" style="color:#a5b4fc;">Version <?= htmlspecialchars($clientInfo['version']) ?> • 64-bit</div>
                        </div>
                    </div>
                    <p class="card-desc">
                        <?= htmlspecialchars($clientInfo['description']) ?>
                    </p>
                </div>
                <div>
                    <div class="card-meta">
                        <div class="meta-line"><span>File:</span> <strong><?= htmlspecialchars($clientInfo['filename']) ?></strong></div>
                        <div class="meta-line"><span>Size:</span> <span><?= htmlspecialchars($clientInfo['size_formatted']) ?></span></div>
                        <div class="meta-line"><span>SHA256:</span> <span class="meta-hash" title="<?= htmlspecialchars($clientInfo['sha256']) ?>"><?= htmlspecialchars(substr($clientInfo['sha256'], 0, 16)) ?>...</span></div>
                    </div>
                    <a href="?token=<?= urlencode($token) ?>&action=download&file=client" class="btn btn-primary" style="background:linear-gradient(135deg, #6366f1, #4f46e5);">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        Download Client (.exe)
                    </a>
                </div>
            </div>

            <!-- 3. All-in-One Quick Start Bundle -->
            <div class="download-card" style="border-color: rgba(139, 92, 246, 0.4); background: linear-gradient(180deg, rgba(24, 18, 43, 0.9) 0%, rgba(18, 24, 38, 0.85) 100%);">
                <div>
                    <div class="card-header">
                        <div class="card-icon" style="background:rgba(139,92,246,0.15); border-color:rgba(139,92,246,0.3);">⚡</div>
                        <div>
                            <div class="card-title">All-in-One Deployment ZIP</div>
                            <div class="card-version" style="color:#c084fc;">Recommended for IT Engineers</div>
                        </div>
                    </div>
                    <p class="card-desc">
                        Pre-packages the Server Setup, Client Setup, your pre-configured offline license key file, and personalized deployment manual into a single compressed archive.
                    </p>
                </div>
                <div>
                    <div class="card-meta">
                        <div class="meta-line"><span>Includes:</span> <strong>Server + Client + License + Guide</strong></div>
                        <div class="meta-line"><span>Format:</span> <span>ZIP Archive</span></div>
                    </div>
                    <a href="?token=<?= urlencode($token) ?>&action=download&file=bundle" class="btn btn-bundle">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                        Download Complete Bundle (.zip)
                    </a>
                </div>
            </div>
        </div>

        <!-- Section: Offline Cryptographic License Key -->
        <h2 class="section-heading">🔑 Offline Cryptographic License</h2>

        <div class="license-box">
            <div class="license-header">
                <div>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:4px;">Unique License Reference Key</div>
                    <div class="license-id-row">
                        <div class="license-key-display" id="licenseKeyText"><?= htmlspecialchars((string)($license['license_id'] ?? '')) ?></div>
                    </div>
                </div>

                <div class="license-actions">
                    <button type="button" class="btn btn-outline btn-sm" onclick="copyLicenseBundle()">
                        📋 Copy License Data
                    </button>
                    <a href="?token=<?= urlencode($token) ?>&action=license" class="btn btn-primary btn-sm">
                        📥 Download License JSON
                    </a>
                </div>
            </div>

            <details>
                <summary style="font-size:0.85rem; color:var(--text-muted); cursor:pointer; user-select:none;">
                    🔍 Click to Inspect Cryptographic RSA-2048 Bundle JSON
                </summary>
                <pre class="license-code-block" id="licenseJsonBlock"><?= htmlspecialchars($licenseBundleJson) ?></pre>
            </details>
        </div>

        <!-- Section: Deployment Walkthrough -->
        <h2 class="section-heading">🚀 Quick Start Walkthrough</h2>

        <div class="steps-container">
            <div class="step-card">
                <div class="step-number">1</div>
                <div class="step-title">Install Server</div>
                <div class="step-text">
                    Execute <code>VendPulse-Server-Setup.exe</code> on your main PC with administrator privileges to initialize the local POS database.
                </div>
            </div>

            <div class="step-card">
                <div class="step-number">2</div>
                <div class="step-title">Import License</div>
                <div class="step-text">
                    In the server admin console, go to <em>Settings → License</em> and import <code>vendpulse_license.json</code> to activate offline.
                </div>
            </div>

            <div class="step-card">
                <div class="step-number">3</div>
                <div class="step-title">Connect Counters</div>
                <div class="step-text">
                    Install <code>VendPulse-Client-Setup.exe</code> on counter checkout PCs and connect to the Server's local network IP.
                </div>
            </div>

            <div class="step-card">
                <div class="step-number">4</div>
                <div class="step-title">Ready for Sales</div>
                <div class="step-text">
                    Log in with your cashier credentials, pair barcode scanners or weigh scales, and begin checkout operations immediately.
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

    <footer class="portal-footer">
        SpectraOps Cryptographic Fleet Hub & Automated Provisioning Engine • SHA256withRSA Offline Verification
    </footer>

</div>

<div id="toastNotice">Copied to clipboard!</div>

<script>
function copyLicenseBundle() {
    const raw = document.getElementById('licenseJsonBlock')?.textContent || document.getElementById('licenseKeyText')?.textContent;
    if (!raw) return;

    navigator.clipboard.writeText(raw).then(() => {
        showToast("License copied to clipboard!");
    }).catch(() => {
        showToast("Failed to copy automatically. Please select text manually.");
    });
}

function showToast(msg) {
    const toast = document.getElementById('toastNotice');
    if (!toast) return;
    toast.textContent = msg;
    toast.classList.add('active');
    setTimeout(() => {
        toast.classList.remove('active');
    }, 3000);
}
</script>

</body>
</html>
