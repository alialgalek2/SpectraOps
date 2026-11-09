<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once dirname(__DIR__, 2) . '/src/Database.php';
require_once dirname(__DIR__, 2) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

$pdo = Database::getConnection();
$service = new FleetService($pdo);

// CSRF Token setup
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// Handle Deployment Registration POST from modal
$regSuccess = null;
$regError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register_deployment') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $regError = "Invalid CSRF security token.";
    } else {
        try {
            $productId = (int)($_POST['product_id'] ?? 0);
            $clientName = trim((string)($_POST['client_name'] ?? ''));
            $domain = trim((string)($_POST['domain'] ?? ''));
            $env = in_array($_POST['environment'] ?? '', ['production', 'staging', 'development'], true) ? (string)$_POST['environment'] : 'production';
            $initialVer = trim((string)($_POST['initial_version'] ?? '1.0.0'));

            if ($productId <= 0 || $clientName === '' || $domain === '') {
                throw new \InvalidArgumentException("All required fields must be filled.");
            }

            $res = $service->registerDeployment($productId, $clientName, $domain, $env, $initialVer);
            $regSuccess = [
                'id' => $res['id'],
                'client_name' => $clientName,
                'token' => $res['token'],
                'preview' => $res['token_preview']
            ];
            $_SESSION['flash_notice'] = "Deployment registered successfully for {$clientName}!";
            $_SESSION['flash_type'] = 'ok';
        } catch (\Throwable $e) {
            $regError = $e->getMessage();
        }
    }
}

// 1. Fetch Global Counts for Rail Sidebar
$countCrit = (int)$pdo->query("SELECT COUNT(*) FROM central_deployments WHERE status = 'critical'")->fetchColumn();
$countErrors = (int)$pdo->query("SELECT COUNT(*) FROM central_error_logs WHERE status = 'open'")->fetchColumn();
$countTickets = (int)$pdo->query("SELECT COUNT(*) FROM central_support_tickets WHERE status = 'open'")->fetchColumn();
$countReleases = (int)$pdo->query("SELECT COUNT(*) FROM central_releases WHERE is_active = 1")->fetchColumn();
$countProducts = (int)$pdo->query("SELECT COUNT(*) FROM central_products")->fetchColumn();
$countUsers = (int)$pdo->query("SELECT COUNT(*) FROM central_users")->fetchColumn();

// 2. Fetch Software Catalog Products for Filters & Registration Modal
$products = $pdo->query("SELECT id, product_slug, product_code, product_name, latest_version FROM central_products ORDER BY product_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch All Deployments with bound product & license metadata
$deployments = $pdo->query("
    SELECT d.*,
           p.product_slug, p.product_name, p.product_code, p.latest_version AS product_latest_version,
           l.license_key, l.status AS license_status, l.lease_salt
    FROM central_deployments d
    JOIN central_products p ON d.product_id = p.id
    LEFT JOIN central_licenses l ON d.license_id = l.id
    ORDER BY 
        CASE d.status
            WHEN 'critical' THEN 1
            WHEN 'warning' THEN 2
            WHEN 'unreachable' THEN 3
            WHEN 'healthy' THEN 4
            ELSE 5
        END,
        d.last_heartbeat_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Calculate Summary Metrics for the Status Strip
$totalNodes = count($deployments);
$healthyNodes = 0;
$warningNodes = 0;
$criticalNodes = 0;
$unreachableNodes = 0;
$outdatedNodes = 0;

foreach ($deployments as $d) {
    $st = strtolower((string)$d['status']);
    if ($st === 'healthy') $healthyNodes++;
    elseif ($st === 'warning') $warningNodes++;
    elseif ($st === 'critical') $criticalNodes++;
    elseif ($st === 'unreachable') $unreachableNodes++;

    $currV = ltrim((string)$d['current_version'], 'vV');
    $lateV = ltrim((string)$d['product_latest_version'], 'vV');
    if ($currV !== '' && $lateV !== '' && version_compare($lateV, $currV, '>')) {
        $outdatedNodes++;
    }
}

// Flash notification handler
$flashNotice = $_SESSION['flash_notice'] ?? null;
$flashType = $_SESSION['flash_type'] ?? 'ok';
unset($_SESSION['flash_notice'], $_SESSION['flash_type']);

// Relative time helper
function timeAgo(?string $datetime): array {
    if (empty($datetime)) {
        return ['text' => 'Never', 'class' => 'hb-dead'];
    }
    $ts = strtotime($datetime);
    if ($ts === false) return ['text' => 'Unknown', 'class' => 'hb-dead'];
    $diff = time() - $ts;
    if ($diff < 0) $diff = 0;

    if ($diff < 60) {
        return ['text' => "{$diff}s ago", 'class' => 'hb-fresh'];
    } elseif ($diff < 300) {
        $m = floor($diff / 60);
        return ['text' => "{$m}m ago", 'class' => 'hb-fresh'];
    } elseif ($diff < 1800) {
        $m = floor($diff / 60);
        return ['text' => "{$m}m ago", 'class' => 'hb-warn'];
    } elseif ($diff < 7200) {
        $m = floor($diff / 60);
        return ['text' => "{$m}m ago", 'class' => 'hb-stale'];
    } elseif ($diff < 86400) {
        $h = floor($diff / 3600);
        return ['text' => "{$h}h ago", 'class' => 'hb-dead'];
    } else {
        $d = floor($diff / 86400);
        return ['text' => "{$d}d ago", 'class' => 'hb-dead'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dynamic Fleet Hub — SpectraOps</title>
    <link rel="icon" type="image/svg+xml" href="../spectraops-icon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0A0E14;
            --panel: #10141C;
            --panel-2: #141A24;
            --panel-hover: #17202D;
            --line: #1F2733;
            --line-soft: #171D28;
            --text: #E8EDF2;
            --text-dim: #89A0B8;
            --text-faint: #4C5A6B;
            --accent: #4FA8E0;
            --accent-dim: #2D5A78;
            --accent-hover: #63B5E5;
            --ok: #3DD68C;
            --ok-dim: #1D5B3F;
            --ok-text: #8FEFC0;
            --warn: #F0B93D;
            --warn-dim: #6B5420;
            --warn-text: #F7D485;
            --crit: #E5484D;
            --crit-dim: #6B2426;
            --crit-text: #F7A3A6;
            --dead: #5B6472;
            --dead-dim: #2B313C;
            --dead-text: #9AA5B2;
            --radius: 6px;
            --radius-lg: 10px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: var(--ink);
            color: var(--text);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 14px;
            line-height: 1.5;
            display: flex;
            min-height: 100vh;
            overflow-x: hidden;
        }

        code, pre, .mono {
            font-family: 'JetBrains Mono', monospace;
        }

        /* App Rail Sidebar */
        .rail-sidebar {
            width: 240px;
            background: var(--panel);
            border-right: 1px solid var(--line);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
        }

        .brand-container {
            padding: 20px 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text);
        }

        .brand-logo {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(79, 168, 224, 0.12);
            border: 1px solid rgba(79, 168, 224, 0.3);
            border-radius: var(--radius);
        }

        .brand-title {
            font-weight: 800;
            font-size: 1.05rem;
            letter-spacing: -0.3px;
            color: #bef6fd;
        }

        .brand-badge {
            font-size: 0.65rem;
            text-transform: uppercase;
            font-weight: 700;
            background: var(--line);
            color: var(--accent);
            padding: 2px 6px;
            border-radius: 4px;
            margin-left: auto;
        }

        .nav-list {
            list-style: none;
            padding: 14px 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex-grow: 1;
        }

        .nav-item a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: var(--radius);
            text-decoration: none;
            color: var(--text-dim);
            font-weight: 500;
            transition: all 0.15s ease;
        }

        .nav-item a:hover {
            background: var(--panel-hover);
            color: var(--text);
        }

        .nav-item.active a {
            background: rgba(79, 168, 224, 0.12);
            color: var(--accent);
            font-weight: 600;
            border: 1px solid rgba(79, 168, 224, 0.25);
        }

        .nav-counter {
            margin-left: auto;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 2px 7px;
            border-radius: 10px;
            background: var(--line);
            color: var(--text-dim);
        }

        .nav-counter.crit {
            background: var(--crit);
            color: #fff;
            animation: pulse-crit 2s infinite ease-in-out;
        }

        .user-card {
            padding: 16px;
            border-top: 1px solid var(--line);
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(0,0,0,0.15);
        }

        .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--accent), #2563eb);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.85rem;
            color: #fff;
        }

        .user-info {
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .user-name {
            font-weight: 600;
            font-size: 0.82rem;
            color: var(--text);
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 0.7rem;
            color: var(--text-dim);
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .status-dot-sm {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--ok);
        }

        /* Main Workspace Content */
        .workspace-content {
            margin-left: 240px;
            flex-grow: 1;
            padding: 26px 32px;
            display: flex;
            flex-direction: column;
            gap: 22px;
            max-width: 1720px;
        }

        /* Topbar Header */
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .topbar-header h1 {
            font-size: 1.45rem;
            font-weight: 800;
            color: #bef6fd;
            letter-spacing: -0.4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .topbar-header p {
            font-size: 0.82rem;
            color: var(--text-dim);
            margin-top: 2px;
        }

        .topbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 8px 15px;
            border-radius: var(--radius);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.15s ease;
            border: 1px solid transparent;
        }

        .btn-sweep {
            background: var(--panel-2);
            border-color: var(--line);
            color: var(--text-dim);
        }

        .btn-sweep:hover {
            background: var(--panel-hover);
            color: var(--text);
            border-color: var(--accent-dim);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent), #2563eb);
            color: #fff;
            box-shadow: 0 2px 8px rgba(79, 168, 224, 0.25);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--accent-hover), #3b82f6);
            transform: translateY(-1px);
        }

        /* Flash Banner */
        .flash-banner {
            padding: 12px 16px;
            border-radius: var(--radius);
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            animation: fadeIn 0.25s ease;
        }

        .flash-ok {
            background: rgba(61, 214, 140, 0.1);
            border: 1px solid rgba(61, 214, 140, 0.3);
            color: var(--ok-text);
        }

        .flash-warn {
            background: rgba(240, 185, 61, 0.1);
            border: 1px solid rgba(240, 185, 61, 0.3);
            color: var(--warn-text);
        }

        .flash-crit {
            background: rgba(229, 72, 77, 0.1);
            border: 1px solid rgba(229, 72, 77, 0.3);
            color: var(--crit-text);
        }

        /* Status Strip */
        .status-strip {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
        }

        @media (max-width: 1200px) {
            .status-strip {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 768px) {
            .status-strip {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        .strip-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 14px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            position: relative;
            overflow: hidden;
            transition: border-color 0.15s ease;
        }

        .strip-card:hover {
            border-color: var(--accent-dim);
        }

        .strip-card-header {
            font-size: 0.72rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-dim);
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .strip-value {
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1;
        }

        .strip-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .dot-all { background: var(--accent); }
        .dot-healthy { background: var(--ok); }
        .dot-warning { background: var(--warn); }
        .dot-critical { background: var(--crit); animation: pulse-crit 1.6s infinite ease-in-out; }
        .dot-unreachable { background: var(--dead); }
        .dot-outdated { background: #a855f7; }

        .strip-card.crit-card {
            border-color: rgba(229, 72, 77, 0.35);
            background: linear-gradient(180deg, rgba(229, 72, 77, 0.08) 0%, var(--panel) 100%);
        }

        /* Filter Bar */
        .filter-bar {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 10px 14px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .search-box {
            position: relative;
            flex-grow: 1;
            max-width: 360px;
        }

        .search-box input {
            width: 100%;
            background: var(--ink);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 8px 12px 8px 34px;
            color: var(--text);
            font-size: 0.82rem;
            outline: none;
            transition: border-color 0.15s ease;
        }

        .search-box input:focus {
            border-color: var(--accent);
        }

        .search-box svg {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-faint);
        }

        .product-pills {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .pill-btn {
            background: var(--panel-2);
            border: 1px solid var(--line);
            color: var(--text-dim);
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 0.74rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .pill-btn:hover {
            color: var(--text);
            border-color: var(--accent-dim);
        }

        .pill-btn.active {
            background: rgba(79, 168, 224, 0.15);
            color: var(--accent);
            border-color: rgba(79, 168, 224, 0.4);
        }

        /* Data Table Container */
        .table-container {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            box-shadow: 0 4px 20px rgba(0,0,0,0.25);
        }

        table.fleet-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            font-size: 0.82rem;
        }

        table.fleet-table th {
            background: rgba(0, 0, 0, 0.3);
            color: var(--text-dim);
            padding: 12px 14px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 1px solid var(--line);
            position: sticky;
            top: 0;
            z-index: 2;
        }

        table.fleet-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--line-soft);
            vertical-align: middle;
        }

        table.fleet-table tbody tr {
            transition: background 0.12s ease;
        }

        table.fleet-table tbody tr:hover td {
            background: var(--panel-hover);
        }

        /* Critical row highlight */
        tr.flash-crit {
            background: rgba(229, 72, 77, 0.08) !important;
            border-left: 3px solid var(--crit);
        }

        tr.flash-crit:hover td {
            background: rgba(229, 72, 77, 0.14) !important;
        }

        /* Status Pills */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 9px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .status-pill.status-healthy {
            background: rgba(61, 214, 140, 0.12);
            color: var(--ok-text);
            border: 1px solid rgba(61, 214, 140, 0.3);
        }

        .status-pill.status-warning {
            background: rgba(240, 185, 61, 0.12);
            color: var(--warn-text);
            border: 1px solid rgba(240, 185, 61, 0.3);
        }

        .status-pill.status-critical {
            background: rgba(229, 72, 77, 0.15);
            color: var(--crit-text);
            border: 1px solid rgba(229, 72, 77, 0.4);
            animation: pulse-crit 2s infinite ease-in-out;
        }

        .status-pill.status-unreachable {
            background: rgba(91, 100, 114, 0.15);
            color: var(--dead-text);
            border: 1px solid rgba(91, 100, 114, 0.3);
        }

        .indicator-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .status-healthy .indicator-dot { background: var(--ok); }
        .status-warning .indicator-dot { background: var(--warn); }
        .status-critical .indicator-dot { background: var(--crit); }
        .status-unreachable .indicator-dot { background: var(--dead); }

        /* Badges & Tags */
        .product-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 2px 7px;
            border-radius: 4px;
            background: rgba(79, 168, 224, 0.12);
            color: #bef6fd;
            border: 1px solid rgba(79, 168, 224, 0.25);
            font-size: 0.72rem;
            font-weight: 600;
        }

        .version-tag {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 4px;
            background: var(--ink);
            border: 1px solid var(--line);
            color: var(--text);
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .version-outdated {
            border-color: rgba(168, 85, 247, 0.4);
            color: #d8b4fe;
            background: rgba(168, 85, 247, 0.12);
        }

        .metric-cell {
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.78rem;
            font-weight: 600;
        }

        .val-ok { color: var(--ok-text); }
        .val-warn { color: var(--warn-text); }
        .val-crit { color: var(--crit-text); font-weight: 700; }
        .val-dead { color: var(--dead-text); }

        .error-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 20px;
            height: 20px;
            padding: 0 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 700;
            background: var(--line);
            color: var(--text-faint);
        }

        .error-badge.has-errors {
            background: rgba(229, 72, 77, 0.2);
            color: var(--crit-text);
            border: 1px solid rgba(229, 72, 77, 0.4);
        }

        /* Heartbeat indicator classes */
        .hb-fresh { color: var(--ok-text); }
        .hb-warn { color: var(--warn-text); }
        .hb-stale { color: var(--dead-text); }
        .hb-dead { color: var(--dead); font-style: italic; }

        .row-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .btn-action {
            padding: 5px 9px;
            border-radius: 4px;
            font-size: 0.72rem;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: var(--panel-2);
            border: 1px solid var(--line);
            color: var(--text);
            transition: all 0.12s ease;
        }

        .btn-action:hover {
            background: var(--accent-dim);
            color: #bef6fd;
            border-color: var(--accent);
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(6, 15, 30, 0.8);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            width: 100%;
            max-width: 520px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            overflow: hidden;
            animation: modalPop 0.2s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .modal-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--line);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--panel-2);
        }

        .modal-header h3 {
            font-size: 1.05rem;
            font-weight: 700;
            color: #bef6fd;
        }

        .modal-close {
            background: none;
            border: none;
            color: var(--text-dim);
            font-size: 1.25rem;
            cursor: pointer;
            padding: 0 5px;
        }

        .modal-close:hover {
            color: var(--text);
        }

        .modal-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 0.74rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-dim);
        }

        .form-group input, .form-group select {
            background: var(--ink);
            border: 1px solid var(--line);
            border-radius: var(--radius);
            padding: 9px 12px;
            color: var(--text);
            font-size: 0.85rem;
            outline: none;
        }

        .form-group input:focus, .form-group select:focus {
            border-color: var(--accent);
        }

        .modal-footer {
            padding: 14px 20px;
            background: var(--panel-2);
            border-top: 1px solid var(--line);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        @keyframes pulse-crit {
            0% { box-shadow: 0 0 0 0 rgba(229, 72, 77, 0.4); }
            70% { box-shadow: 0 0 0 6px rgba(229, 72, 77, 0); }
            100% { box-shadow: 0 0 0 0 rgba(229, 72, 77, 0); }
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body>

    <!-- Rail Sidebar -->
    <aside class="rail-sidebar">
        <div>
            <a href="fleet_view.php" class="brand-container">
                <div class="brand-logo">
                    <svg width="20" height="20" viewBox="0 0 54 54">
                        <polygon points="6,46 22,10 31,10 15,46" fill="#4FA8E0"/>
                        <rect x="23" y="28" width="4.2" height="18" rx="1" fill="#2D5A78"/>
                        <rect x="31" y="20" width="4.2" height="26" rx="1" fill="#4FA8E0"/>
                        <rect x="39" y="12" width="4.2" height="34" rx="1" fill="#7FC4EE"/>
                    </svg>
                </div>
                <div>
                    <div class="brand-title">SpectraOps</div>
                    <div style="font-size: 0.68rem; color: var(--text-dim);">Fleet Operations</div>
                </div>
                <span class="brand-badge">HUB</span>
            </a>

            <ul class="nav-list">
                <li class="nav-item active">
                    <a href="fleet_view.php">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        <span>Fleet</span>
                        <span class="nav-counter <?= ($countCrit > 0) ? 'crit' : '' ?>" title="<?= $countCrit ?> Critical Nodes"><?= $countCrit > 0 ? "{$countCrit} CRIT" : $totalNodes ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../dashboard.php?page=errors">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <span>Errors</span>
                        <span class="nav-counter"><?= $countErrors ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../dashboard.php?page=tickets">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"></path></svg>
                        <span>Tickets</span>
                        <span class="nav-counter"><?= $countTickets ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../dashboard.php?page=releases">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"></path></svg>
                        <span>Releases</span>
                        <span class="nav-counter"><?= $countReleases ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../dashboard.php?page=products">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
                        <span>Products</span>
                        <span class="nav-counter"><?= $countProducts ?></span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="../dashboard.php?page=users">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        <span>Users</span>
                        <span class="nav-counter"><?= $countUsers ?></span>
                    </a>
                </li>
            </ul>
        </div>

        <div class="user-card">
            <div class="avatar">OP</div>
            <div class="user-info">
                <div class="user-name">Lead Fleet Engineer</div>
                <div class="user-role">
                    <span class="status-dot-sm"></span>
                    <span>Operator Active</span>
                </div>
            </div>
        </div>
    </aside>

    <!-- Workspace Content -->
    <main class="workspace-content">
        <!-- Topbar Actions -->
        <header class="topbar">
            <div class="topbar-header">
                <h1>
                    <span>Fleet Operations Registry</span>
                    <span style="font-size: 0.72rem; padding: 2px 8px; border-radius: 4px; background: rgba(79, 168, 224, 0.15); color: var(--accent); border: 1px solid rgba(79, 168, 224, 0.3);">REALTIME</span>
                </h1>
                <p>Live node telemetry heartbeats, dynamic health classification & OTA distribution</p>
            </div>
            <div class="topbar-actions">
                <form method="POST" action="../api/v1/fleet/sweep.php" style="margin: 0;">
                    <button type="submit" class="btn btn-sweep" title="Mark nodes inactive > 120 minutes as unreachable">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.033 8.033 0 01-15.357-2m15.357 2H15"></path></svg>
                        <span>Sweep stale nodes</span>
                    </button>
                </form>

                <button type="button" class="btn btn-primary" onclick="openRegisterModal()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <span>Register deployment</span>
                </button>
            </div>
        </header>

        <!-- Flash Notice Banner if set -->
        <?php if (!empty($flashNotice)): ?>
            <div class="flash-banner flash-<?= htmlspecialchars($flashType) ?>">
                <span><?= htmlspecialchars($flashNotice) ?></span>
                <span style="cursor: pointer; opacity: 0.7;" onclick="this.parentElement.remove();">&times;</span>
            </div>
        <?php endif; ?>

        <?php if (!empty($regSuccess)): ?>
            <div class="flash-banner flash-ok" style="flex-direction: column; align-items: flex-start; gap: 8px;">
                <div><strong>Deployment Registered:</strong> <?= htmlspecialchars($regSuccess['client_name']) ?> (ID #<?= (int)$regSuccess['id'] ?>)</div>
                <div style="font-size: 0.8rem;">
                    Save the API Ingest Token now — it will not be shown again:
                    <code style="background: rgba(0,0,0,0.4); padding: 4px 8px; border-radius: 4px; color: var(--accent); user-select: all;"><?= htmlspecialchars($regSuccess['token']) ?></code>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($regError)): ?>
            <div class="flash-banner flash-crit">
                <span><strong>Registration Error:</strong> <?= htmlspecialchars($regError) ?></span>
            </div>
        <?php endif; ?>

        <!-- Status Strip (Metrics Aggregate) -->
        <section class="status-strip">
            <div class="strip-card">
                <div class="strip-card-header">
                    <span class="strip-dot dot-all"></span>
                    <span>All Nodes</span>
                </div>
                <div class="strip-value" style="color: var(--text);"><?= $totalNodes ?></div>
            </div>

            <div class="strip-card">
                <div class="strip-card-header">
                    <span class="strip-dot dot-healthy"></span>
                    <span>Healthy</span>
                </div>
                <div class="strip-value" style="color: var(--ok-text);"><?= $healthyNodes ?></div>
            </div>

            <div class="strip-card">
                <div class="strip-card-header">
                    <span class="strip-dot dot-warning"></span>
                    <span>Warning</span>
                </div>
                <div class="strip-value" style="color: var(--warn-text);"><?= $warningNodes ?></div>
            </div>

            <div class="strip-card <?= ($criticalNodes > 0) ? 'crit-card' : '' ?>">
                <div class="strip-card-header">
                    <span class="strip-dot dot-critical"></span>
                    <span>Critical</span>
                </div>
                <div class="strip-value" style="color: var(--crit-text);"><?= $criticalNodes ?></div>
            </div>

            <div class="strip-card">
                <div class="strip-card-header">
                    <span class="strip-dot dot-unreachable"></span>
                    <span>Unreachable</span>
                </div>
                <div class="strip-value" style="color: var(--dead-text);"><?= $unreachableNodes ?></div>
            </div>

            <div class="strip-card">
                <div class="strip-card-header">
                    <span class="strip-dot dot-outdated"></span>
                    <span>Outdated Builds</span>
                </div>
                <div class="strip-value" style="color: #d8b4fe;"><?= $outdatedNodes ?></div>
            </div>
        </section>

        <!-- Filter Bar -->
        <section class="filter-bar">
            <div class="search-box">
                <svg width="15" height="15" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <input type="text" id="searchInput" placeholder="Filter by client name or domain..." oninput="applyFilters()">
            </div>

            <div class="product-pills" id="productFilterPills">
                <button type="button" class="pill-btn active" data-slug="all" onclick="selectProductPill(this)">All products</button>
                <?php foreach ($products as $p): ?>
                    <button type="button" class="pill-btn" data-slug="<?= htmlspecialchars($p['product_slug']) ?>" onclick="selectProductPill(this)">
                        <?= htmlspecialchars($p['product_name']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Dynamic Data Table -->
        <section class="table-container">
            <table class="fleet-table" id="fleetTable">
                <thead>
                    <tr>
                        <th>Client & Domain</th>
                        <th>Product</th>
                        <th>Status</th>
                        <th>Version</th>
                        <th>DB Latency</th>
                        <th>Disk Free</th>
                        <th>24h Users</th>
                        <th>Errors</th>
                        <th>Heartbeat</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($deployments)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px; color: var(--text-dim);">
                                No registered deployments found in the fleet registry.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($deployments as $d): ?>
                            <?php
                                $status = strtolower((string)$d['status']);
                                $isCrit = ($status === 'critical');
                                
                                // Threshold coloring: DB Latency
                                $dbLat = (float)($d['db_latency_ms'] ?? 0);
                                if ($dbLat >= 1500) $latClass = 'val-crit';
                                elseif ($dbLat >= 500) $latClass = 'val-warn';
                                else $latClass = 'val-ok';

                                // Threshold coloring: Disk Free
                                $diskGb = (float)($d['disk_free_gb'] ?? ($d['storage_free_gb'] ?? 0));
                                if ($diskGb < 1.0) $diskClass = 'val-crit';
                                elseif ($diskGb < 20.0) $diskClass = 'val-warn';
                                else $diskClass = 'val-ok';

                                // Outdated version calculation
                                $currVer = ltrim((string)$d['current_version'], 'vV');
                                $latestVer = ltrim((string)$d['product_latest_version'], 'vV');
                                $isOutdated = ($currVer !== '' && $latestVer !== '' && version_compare($latestVer, $currVer, '>'));

                                // Open error count
                                $errCount = (int)($d['open_error_count'] ?? 0);

                                // Heartbeat relative time
                                $hb = timeAgo($d['last_heartbeat_at'] ?? null);
                            ?>
                            <tr class="<?= $isCrit ? 'flash-crit' : '' ?>" 
                                data-client="<?= htmlspecialchars(strtolower($d['client_name'])) ?>" 
                                data-domain="<?= htmlspecialchars(strtolower($d['deployment_domain'])) ?>" 
                                data-product="<?= htmlspecialchars($d['product_slug']) ?>"
                                data-status="<?= $status ?>">
                                
                                <!-- Client & Domain -->
                                <td>
                                    <div style="font-weight: 700; color: #bef6fd;"><?= htmlspecialchars($d['client_name']) ?></div>
                                    <div style="font-size: 0.74rem; color: var(--text-dim); display: flex; align-items: center; gap: 6px;">
                                        <span><?= htmlspecialchars($d['deployment_domain']) ?></span>
                                        <span style="font-size: 0.65rem; background: var(--line); padding: 1px 5px; border-radius: 3px; color: var(--accent);">
                                            <?= htmlspecialchars(strtoupper((string)($d['environment'] ?? 'prod'))) ?>
                                        </span>
                                    </div>
                                </td>

                                <!-- Product Badge -->
                                <td>
                                    <span class="product-badge">
                                        <?= htmlspecialchars($d['product_code'] ?? 'SYS') ?>
                                    </span>
                                </td>

                                <!-- Status Pill -->
                                <td>
                                    <span class="status-pill status-<?= $status ?>">
                                        <span class="indicator-dot"></span>
                                        <span><?= htmlspecialchars(ucfirst($status)) ?></span>
                                    </span>
                                </td>

                                <!-- Version Tag with upward indicator if outdated -->
                                <td>
                                    <span class="version-tag <?= $isOutdated ? 'version-outdated' : '' ?>" title="<?= $isOutdated ? "Outdated: Latest is v{$latestVer}" : 'Latest Version' ?>">
                                        <?php if ($isOutdated): ?>
                                            <span style="font-weight: 800; font-size: 0.85rem;">↑</span>
                                        <?php endif; ?>
                                        <span>v<?= htmlspecialchars($currVer) ?></span>
                                    </span>
                                </td>

                                <!-- DB Latency -->
                                <td>
                                    <span class="metric-cell <?= $latClass ?>">
                                        <?= round($dbLat, 1) ?> ms
                                    </span>
                                </td>

                                <!-- Disk Free -->
                                <td>
                                    <span class="metric-cell <?= $diskClass ?>">
                                        <?= round($diskGb, 2) ?> GB
                                    </span>
                                </td>

                                <!-- 24h Users -->
                                <td>
                                    <span class="metric-cell" style="color: var(--text);">
                                        <?= number_format((int)($d['active_users_24h'] ?? 0)) ?>
                                    </span>
                                </td>

                                <!-- Error Count Badge -->
                                <td>
                                    <span class="error-badge <?= ($errCount > 0) ? 'has-errors' : '' ?>" title="<?= $errCount ?> open error logs">
                                        <?= $errCount ?>
                                    </span>
                                </td>

                                <!-- Heartbeat Time -->
                                <td>
                                    <span class="metric-cell <?= $hb['class'] ?>">
                                        <?= htmlspecialchars($hb['text']) ?>
                                    </span>
                                </td>

                                <!-- Row Action Buttons -->
                                <td style="text-align: right;">
                                    <div class="row-actions" style="justify-content: flex-end;">
                                        <a href="../deployment_view.php?id=<?= (int)$d['id'] ?>" class="btn-action" title="Open Deep Diagnostic View">
                                            <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                            <span>Inspect</span>
                                        </a>

                                        <?php if (!empty($d['deployment_domain'])): ?>
                                            <a href="http://<?= htmlspecialchars($d['deployment_domain']) ?>" target="_blank" rel="noopener noreferrer" class="btn-action" title="Open Deployment Website">
                                                <svg width="12" height="12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </section>
    </main>

    <!-- Registration Modal -->
    <div class="modal-overlay" id="registerModal" onclick="if(event.target===this) closeRegisterModal();">
        <div class="modal-card">
            <div class="modal-header">
                <h3>Register New Fleet Deployment</h3>
                <button type="button" class="modal-close" onclick="closeRegisterModal()">&times;</button>
            </div>
            <form method="POST" action="fleet_view.php">
                <input type="hidden" name="action" value="register_deployment">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="modal-body">
                    <div class="form-group">
                        <label for="product_id">Target Software Product *</label>
                        <select name="product_id" id="product_id" required>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= (int)$p['id'] ?>">
                                    <?= htmlspecialchars($p['product_name']) ?> (v<?= htmlspecialchars($p['latest_version']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="client_name">Client / Institution Name *</label>
                        <input type="text" name="client_name" id="client_name" placeholder="e.g. Oakridge Academy" required>
                    </div>

                    <div class="form-group">
                        <label for="domain">Deployment Hostname / FQDN *</label>
                        <input type="text" name="domain" id="domain" placeholder="e.g. prism.oakridge.edu" required>
                        <small style="color: var(--text-dim); font-size: 0.72rem;">Must be a valid public hostname (no raw IPs).</small>
                    </div>

                    <div class="form-group">
                        <label for="environment">Environment</label>
                        <select name="environment" id="environment">
                            <option value="production">Production</option>
                            <option value="staging">Staging</option>
                            <option value="development">Development</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="initial_version">Initial Installed Version</label>
                        <input type="text" name="initial_version" id="initial_version" value="1.0.0">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-sweep" onclick="closeRegisterModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Generate Deployment Token &rarr;</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Live Client-side Filtering Script -->
    <script>
        let activeProduct = 'all';

        function selectProductPill(btn) {
            document.querySelectorAll('#productFilterPills .pill-btn').forEach(el => el.classList.remove('active'));
            btn.classList.add('active');
            activeProduct = btn.getAttribute('data-slug');
            applyFilters();
        }

        function applyFilters() {
            const query = (document.getElementById('searchInput').value || '').toLowerCase().trim();
            const rows = document.querySelectorAll('#fleetTable tbody tr');

            rows.forEach(row => {
                if (!row.hasAttribute('data-client')) return;

                const client = row.getAttribute('data-client') || '';
                const domain = row.getAttribute('data-domain') || '';
                const product = row.getAttribute('data-product') || '';

                const matchesQuery = (query === '' || client.includes(query) || domain.includes(query));
                const matchesProduct = (activeProduct === 'all' || product === activeProduct);

                if (matchesQuery && matchesProduct) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        function openRegisterModal() {
            document.getElementById('registerModal').classList.add('active');
        }

        function closeRegisterModal() {
            document.getElementById('registerModal').classList.remove('active');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeRegisterModal();
            }
        });
    </script>
</body>
</html>
