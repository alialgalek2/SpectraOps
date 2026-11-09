<?php
declare(strict_types=1);

// --- 1. CONFIGURATION & CORE INCLUDES ---
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/FleetService.php';

use FleetHub\Database;
use FleetHub\FleetService;

// Harden session settings before session_start()
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ensure operator is authenticated
if (empty($_SESSION['fleet_user_id']) && empty($_SESSION['fleet_admin_logged'])) {
    header('Location: dashboard.php');
    exit;
}

$pdo = Database::getConnection();
$service = new FleetService($pdo);

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$daysRemainingExpr = ($driver === 'sqlite')
    ? "CAST(julianday(l.expires_at) - julianday('now') AS INTEGER)"
    : "TIMESTAMPDIFF(DAY, UTC_TIMESTAMP(), l.expires_at)";

// 1. Ensure you have the active deployment ID
$deploymentId = isset($_GET['deployment_id']) ? (int)$_GET['deployment_id'] : (int)($_GET['id'] ?? 0);

// 2. Fetch deployment metadata
$stmtDeploy = $pdo->prepare("
    SELECT d.*, 
           p.product_name, p.product_slug, p.product_code,
           l.id AS license_id,
           l.license_key,
           l.client_name AS license_client_name,
           l.client_email AS license_client_email,
           l.license_type,
           l.status AS license_status,
           l.issued_at,
           l.expires_at,
           {$daysRemainingExpr} AS days_remaining
    FROM central_deployments d
    JOIN central_products p ON d.product_id = p.id
    LEFT JOIN central_licenses l ON d.license_id = l.id
    WHERE d.id = ?
");
$stmtDeploy->execute([$deploymentId]);
$deployment = $stmtDeploy->fetch();

if (!$deployment) {
    header('Location: dashboard.php?error=deployment_not_found');
    exit;
}

// License quick action handlers directly on this view:
if (isset($_GET['set_license_status']) && !empty($deployment['license_id'])) {
    $newStatus = in_array($_GET['set_license_status'], ['active', 'suspended', 'revoked']) ? $_GET['set_license_status'] : 'active';
    
    // Update license state
    $updLic = $pdo->prepare("UPDATE central_licenses SET status = ? WHERE id = ?");
    $updLic->execute([$newStatus, $deployment['license_id']]);

    // Force deployment warning if suspended or revoked
    $depStatus = ($newStatus === 'active') ? 'healthy' : 'warning';
    $updDep = $pdo->prepare("UPDATE central_deployments SET status = ? WHERE id = ?");
    $updDep->execute([$depStatus, $deploymentId]);

    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?') . "?id={$deploymentId}&status=license_updated");
    exit;
}

// 3. Fetch Support Tickets for this deployment
$stmtTickets = $pdo->prepare("
    SELECT * FROM central_support_tickets 
    WHERE deployment_id = ? 
    ORDER BY created_at DESC
");
$stmtTickets->execute([$deploymentId]);
$clientTickets = $stmtTickets->fetchAll();

// 4. Fetch Telemetry Error Logs strictly for THIS specific deployment (with OTA reconciliation)
try {
    require_once dirname(__DIR__) . '/src/FleetService.php';
    $fleetSvc = new FleetService($pdo);
    $fleetSvc->reconcilePatchPendingErrors($deploymentId);
} catch (\Throwable $e) {}

$stmtErrors = $pdo->prepare("
    SELECT * FROM central_error_logs 
    WHERE deployment_id = ? 
    ORDER BY occurred_at DESC
    LIMIT 100
");
$stmtErrors->execute([$deploymentId]);
$clientErrors = $stmtErrors->fetchAll();

// Fetch products for patch modal dropdown
$products = $pdo->query("SELECT id, product_name, product_code FROM central_products ORDER BY product_name ASC")->fetchAll();

// Fetch sidebar badge counts
$criticalCount = (int)$pdo->query("SELECT COUNT(*) FROM central_deployments WHERE status = 'critical'")->fetchColumn();
$totalDeployments = (int)$pdo->query("SELECT COUNT(*) FROM central_deployments")->fetchColumn();
$recentErrorsCount = (int)$pdo->query("SELECT COUNT(*) FROM central_error_logs WHERE status = 'open'")->fetchColumn();
$openTicketsCount = (int)$pdo->query("SELECT COUNT(*) FROM central_support_tickets WHERE status = 'open'")->fetchColumn();
$releasesCount = (int)$pdo->query("SELECT COUNT(*) FROM central_releases WHERE is_active = 1")->fetchColumn();
$productsCount = (int)$pdo->query("SELECT COUNT(*) FROM central_products")->fetchColumn();

$operatorName = $_SESSION['fleet_user_name'] ?? 'Fleet Admin';
$operatorInitials = strtoupper(substr($operatorName, 0, 2));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($deployment['client_name']) ?> — Fleet Deployment Node | SpectraOps</title>
<link rel="icon" type="image/svg+xml" href="spectraops-icon.svg">
<link rel="alternate icon" type="image/png" href="spectraops-icon.png">
<link rel="shortcut icon" href="favicon.ico">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<script>
(function() {
  var t = localStorage.getItem('spectraops_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', t);
})();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= file_exists(__DIR__ . '/style.css') ? filemtime(__DIR__ . '/style.css') : 1 ?>">
<link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/diff2html/bundles/css/diff2html.min.css" />
<script src="spectra-popup.js?v=<?= file_exists(__DIR__ . '/spectra-popup.js') ? filemtime(__DIR__ . '/spectra-popup.js') : 1 ?>"></script>

<style>
.d2h-wrapper { background: var(--ink) !important; color: var(--text) !important; font-family: 'JetBrains Mono', monospace !important; font-size: 0.78rem; border-radius: var(--radius); border: 1px solid var(--line); }
.d2h-file-header { background: var(--panel-2) !important; border-bottom: 1px solid var(--line) !important; padding: 6px 14px; }
.d2h-file-name { color: var(--accent) !important; font-weight: 600; }
.d2h-code-side-linenumber { background: var(--panel-2) !important; color: var(--text-faint) !important; border-color: var(--line) !important; font-size: 0.72rem; }
.d2h-del { background-color: rgba(229, 72, 77, 0.16) !important; border-color: rgba(229, 72, 77, 0.3) !important; color: var(--crit-text) !important; }
.d2h-ins { background-color: rgba(61, 214, 140, 0.16) !important; border-color: rgba(61, 214, 140, 0.3) !important; color: var(--ok-text) !important; }
.d2h-code-line del { background-color: rgba(229, 72, 77, 0.38) !important; border-radius: 2px; }
.d2h-code-line ins { background-color: rgba(61, 214, 140, 0.38) !important; border-radius: 2px; }
.d2h-code-side-emptyplaceholder { background: var(--ink) !important; border-color: var(--line) !important; }
.d2h-code-line-ctn { color: var(--text) !important; font-family: 'JetBrains Mono', monospace !important; }
.diff-container { max-height: 400px; overflow-y: auto; overflow-x: auto; border-radius: var(--radius); border: 1px solid var(--line); background: var(--ink); }
</style>
</head>
<body>

<div class="shell">

  <!-- ================= RAIL ================= -->
  <nav class="rail">
    <a href="dashboard.php?page=overview" class="brand">
      <svg width="22" height="22" viewBox="0 0 54 54">
        <polygon points="6,46 22,10 31,10 15,46" fill="#4FA8E0"/>
        <rect x="23" y="28" width="4.2" height="18" rx="1" fill="#2D5A78"/>
        <rect x="31" y="20" width="4.2" height="26" rx="1" fill="#4FA8E0"/>
        <rect x="39" y="12" width="4.2" height="34" rx="1" fill="#7FC4EE"/>
      </svg>
      <div class="brand-name">Spectra<span>Ops</span></div>
    </a>

    <div class="nav-group-label">Monitor</div>
    <a href="dashboard.php?page=overview" class="nav-item active">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4" width="18" height="12" rx="1.5"/><path d="M8 20h8M12 16v4"/></svg>
        Fleet
      </div>
      <span class="nav-count <?= $criticalCount > 0 ? 'crit' : '' ?>"><?= $criticalCount > 0 ? $criticalCount : $totalDeployments ?></span>
    </a>
    <a href="dashboard.php?page=errors" class="nav-item">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 9v4M12 17h.01M10.3 3.9 2.5 18a2 2 0 0 0 1.7 3h15.6a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/></svg>
        Errors
      </div>
      <span class="nav-count <?= $recentErrorsCount > 0 ? 'crit' : '' ?>"><?= $recentErrorsCount ?></span>
    </a>
    <a href="dashboard.php?page=tickets" class="nav-item">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 4h16v12H7l-3 3V4Z"/></svg>
        Tickets
      </div>
      <span class="nav-count <?= $openTicketsCount > 0 ? 'warn' : '' ?>"><?= $openTicketsCount ?></span>
    </a>

    <div class="nav-group-label">Distribute</div>
    <a href="dashboard.php?page=releases" class="nav-item">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3v12m0 0-4-4m4 4 4-4M5 17v2a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-2"/></svg>
        Releases
      </div>
      <span class="nav-count"><?= $releasesCount ?></span>
    </a>
    <a href="dashboard.php?page=products" class="nav-item">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
        Products
      </div>
      <span class="nav-count"><?= $productsCount ?></span>
    </a>

    <div class="nav-group-label">Admin</div>
    <a href="dashboard.php?page=users" class="nav-item">
      <div class="nav-left">
        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.2"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/></svg>
        Users
      </div>
    </a>

    <div class="rail-footer">
      <div class="avatar"><?= htmlspecialchars($operatorInitials) ?></div>
      <div class="rail-user">
        <strong><?= htmlspecialchars($operatorName) ?></strong>
        Operator Console
      </div>
    </div>
  </nav>

  <!-- ================= MAIN ================= -->
  <main class="main">

    <!-- Topbar -->
    <div class="topbar">
      <div class="topbar-title">
        <h1>
          <a href="dashboard.php?page=overview" style="color:var(--text-dim); text-decoration:none;">Fleet</a>
          <span style="color:var(--text-faint); margin:0 4px;">/</span>
          <?= htmlspecialchars($deployment['client_name']) ?>
        </h1>
        <p>
          <span class="mono"><?= htmlspecialchars($deployment['deployment_domain']) ?></span> &bull; 
          Product: <span class="product-badge"><?= htmlspecialchars($deployment['product_name']) ?></span> &bull; 
          Version: <span class="version-tag <?= !empty($deployment['is_outdated']) ? 'outdated' : '' ?>">v<?= htmlspecialchars($deployment['current_version']) ?></span> &bull; 
          Status: <span class="status-pill <?= $deployment['status'] === 'unreachable' ? 'dead' : $deployment['status'] ?>"><span class="dot"></span><?= htmlspecialchars($deployment['status']) ?></span>
        </p>
      </div>
      <div class="topbar-actions">
        <!-- Theme Switcher -->
        <button type="button" class="theme-toggle-btn" onclick="toggleTheme()" title="Switch Light/Dark mode" aria-label="Switch Theme">
          <span class="theme-toggle-track">
            <span class="theme-toggle-icon sun-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            </span>
            <span class="theme-toggle-icon moon-icon">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </span>
            <span class="theme-toggle-thumb"></span>
          </span>
          <span class="theme-toggle-label">Theme</span>
        </button>

        <a href="dashboard.php?page=overview" class="btn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
          Back to Fleet
        </a>
        <button class="btn btn-primary" onclick="openPatchModal(<?= (int)$deployment['product_id'] ?>)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 5v14M5 12h14"/></svg>
          Dispatch Hotfix
        </button>
      </div>
    </div>

    <!-- Cryptographic License Card -->
    <div class="panel-card" style="margin: 16px 24px;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 14px;">
        <div>
          <div style="font-size: 11px; text-transform: uppercase; color: var(--text-faint); font-weight: 600; letter-spacing: 0.5px; margin-bottom: 6px;">
            Cryptographic License Authorization
          </div>
          
          <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px; flex-wrap: wrap;">
            <code class="mono" style="font-size: 14px; font-weight: 600; color: var(--accent); background: var(--panel-2); padding: 5px 12px; border-radius: var(--radius); border: 1px solid var(--line);">
              <?= htmlspecialchars($deployment['license_key'] ?? 'NO_LICENSE_BOUND') ?>
            </code>
            
            <?php 
              $licStatus = strtolower($deployment['license_status'] ?? 'unknown');
              $pillClass = ($licStatus === 'active') ? 'ok' : (($licStatus === 'suspended') ? 'warn' : 'crit');
            ?>
            <span class="status-pill <?= $pillClass ?>">
              <span class="dot"></span>
              <?= htmlspecialchars($licStatus) ?>
            </span>
          </div>

          <div style="display: flex; gap: 20px; font-size: 12px; color: var(--text-dim); flex-wrap: wrap;">
            <div>
              <span style="color: var(--text-faint);">Plan:</span> 
              <strong style="color: var(--text);"><?= ucfirst(htmlspecialchars($deployment['license_type'] ?? 'Standard')) ?></strong>
            </div>
            <div>
              <span style="color: var(--text-faint);">Holder:</span> 
              <span style="color: var(--text);"><?= htmlspecialchars($deployment['license_client_name'] ?? $deployment['client_name']) ?></span>
            </div>
            <div>
              <span style="color: var(--text-faint);">Contact:</span> 
              <a href="mailto:<?= htmlspecialchars($deployment['license_client_email'] ?? '') ?>" style="color: var(--accent); text-decoration: none;">
                <?= htmlspecialchars($deployment['license_client_email'] ?? 'N/A') ?>
              </a>
            </div>
            <div>
              <span style="color: var(--text-faint);">Validity:</span> 
              <?php if (empty($deployment['expires_at'])): ?>
                <strong style="color: var(--ok);">Perpetual (No Expiration)</strong>
              <?php elseif ($deployment['days_remaining'] > 0): ?>
                <span>Expires <span class="mono"><?= htmlspecialchars(substr($deployment['expires_at'], 0, 10)) ?></span> (<strong style="color:var(--text);"><?= $deployment['days_remaining'] ?> days left</strong>)</span>
              <?php else: ?>
                <strong style="color: var(--crit);">Expired on <span class="mono"><?= htmlspecialchars(substr($deployment['expires_at'], 0, 10)) ?></span></strong>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if (!empty($deployment['license_id'])): ?>
          <div style="display: flex; gap: 8px; align-items: center;">
            <?php if ($licStatus === 'active'): ?>
              <a href="?id=<?= $deploymentId ?>&set_license_status=suspended" 
                 class="btn btn-sm btn-warn" 
                 onclick="return confirm('Suspend this license? Prism EDU will enforce lockout on the next lease check.');">
                ⏸ Suspend License
              </a>
            <?php else: ?>
              <a href="?id=<?= $deploymentId ?>&set_license_status=active" 
                 class="btn btn-sm btn-success" 
                 onclick="return confirm('Reactivate this license? The client will immediately regain full access.');">
                ▶ Reactivate License
              </a>
            <?php endif; ?>

            <a href="?id=<?= $deploymentId ?>&set_license_status=revoked" 
               class="btn btn-sm btn-danger" 
               onclick="return confirm('Revoke this license permanently?');">
              ✕ Revoke
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 2-Column Split: Tickets (Left) & Telemetry Error Logs (Right) -->
    <div class="split-grid">
      
      <!-- LEFT PANEL: IT Support Tickets -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">Client Support Tickets (<?= count($clientTickets) ?>)</div>
          <span style="font-size: 11px; color: var(--text-faint);">Human-submitted issues</span>
        </div>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Ref</th>
                <th>Priority</th>
                <th>Subject &amp; Message</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($clientTickets)): ?>
                <tr><td colspan="4" style="text-align: center; color: var(--text-faint); padding: 36px 16px;">No support tickets logged by this deployment.</td></tr>
              <?php else: ?>
                <?php foreach ($clientTickets as $t): ?>
                  <tr>
                    <td style="white-space: nowrap;">
                      <code class="mono" style="font-size:11.5px; color:var(--accent);"><?= htmlspecialchars($t['ticket_reference']) ?></code><br>
                      <small class="mono" style="color: var(--text-faint); font-size:11px;"><?= htmlspecialchars(substr($t['created_at'], 0, 10)) ?></small>
                    </td>
                    <td>
                      <?php
                        $prio = strtolower($t['priority'] ?? 'medium');
                        $prioClass = ($prio === 'urgent' || $prio === 'high') ? 'crit' : (($prio === 'medium') ? 'warn' : 'ok');
                      ?>
                      <span class="status-pill <?= $prioClass ?>">
                        <span class="dot"></span>
                        <?= htmlspecialchars($t['priority'] ?? 'normal') ?>
                      </span>
                    </td>
                    <td>
                      <strong style="color: var(--text); font-size: 13px;"><?= htmlspecialchars($t['subject']) ?></strong>
                      <p style="margin: 4px 0 0 0; color: var(--text-dim); font-size: 12px; line-height: 1.4;"><?= nl2br(htmlspecialchars($t['message'])) ?></p>
                    </td>
                    <td>
                      <span class="status-pill <?= ($t['status'] === 'resolved') ? 'ok' : 'warn' ?>">
                        <span class="dot"></span>
                        <?= htmlspecialchars($t['status']) ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- RIGHT PANEL: Telemetry Error Logs for THIS Specific Deployment -->
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">Telemetry Error Stream (<?= count($clientErrors) ?>)</div>
          <span style="font-size: 11px; color: var(--text-faint);">Runtime traces &amp; exceptions</span>
        </div>
        <div class="table-scroll">
          <table>
            <thead>
              <tr>
                <th>Occurred</th>
                <th>Error Details &amp; Location</th>
                <th>Resolution</th>
                <th class="num">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($clientErrors)): ?>
                <tr><td colspan="4" style="text-align: center; color: var(--text-faint); padding: 36px 16px;">Zero errors recorded. This deployment is running clean.</td></tr>
              <?php else: ?>
                <?php foreach ($clientErrors as $err): ?>
                  <tr>
                    <td style="white-space: nowrap;">
                      <span class="mono" style="color: var(--text-faint); font-size: 11.5px;"><?= htmlspecialchars($err['occurred_at'] ?? $err['last_seen_at'] ?? 'Recent') ?></span><br>
                      <span class="status-pill warn" style="margin-top: 3px; font-size: 10px;">
                        <?= htmlspecialchars($err['error_level']) ?>
                      </span>
                    </td>
                    <td>
                      <div style="font-weight: 500; color: var(--crit); font-size: 12.5px;"><?= htmlspecialchars($err['error_message']) ?></div>
                      <small class="mono" style="color: var(--text-faint); font-size: 11px;">
                        <?= htmlspecialchars(basename($err['file_path'] ?? '')) ?>:<?= (int)($err['line_number'] ?? 0) ?>
                      </small>
                      <?php if (!empty($err['stack_trace'])): ?>
                        <details style="margin-top: 4px;">
                          <summary style="cursor: pointer; color: var(--accent); font-size: 11px;">View Stack Trace</summary>
                          <pre class="mono" style="background: var(--ink); border: 1px solid var(--line); padding: 8px; border-radius: var(--radius); font-size: 11px; max-height: 140px; overflow: auto; color: var(--text-dim); margin-top: 4px;"><?= htmlspecialchars($err['stack_trace']) ?></pre>
                        </details>
                      <?php endif; ?>
                    </td>
                    <td style="white-space: nowrap;">
                      <?php if (($err['status'] ?? 'open') === 'resolved' || !empty($err['resolved'])): ?>
                        <span class="status-pill ok">✓ Resolved <?= !empty($err['resolved_by_version']) ? '(v' . htmlspecialchars($err['resolved_by_version']) . ')' : '' ?></span>
                      <?php elseif (($err['status'] ?? 'open') === 'patch_pending'): ?>
                        <span class="status-pill warn">⏳ Patching <?= !empty($err['resolved_by_version']) ? '(v' . htmlspecialchars($err['resolved_by_version']) . ')' : '' ?></span>
                        <br><small style="color:var(--text-faint); font-size:11px;">Awaiting OTA sync</small>
                      <?php else: ?>
                        <span class="status-pill crit">● Open</span>
                      <?php endif; ?>
                    </td>
                    <td class="num" style="white-space: nowrap;">
                      <div style="display:inline-flex; gap:6px; align-items:center; justify-content: flex-end;">
                        <!-- Direct AI Fix for this error -->
                        <button class="btn btn-sm btn-ai" onclick="triggerAiFix(<?= (int)$err['id'] ?>)">
                          ✨ Fix
                        </button>
                        <?php if (($err['status'] ?? 'open') !== 'resolved' && empty($err['resolved'])): ?>
                          <form method="POST" action="dashboard.php" style="display:inline; margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="resolve_error">
                            <input type="hidden" name="error_id" value="<?= (int)$err['id'] ?>">
                            <input type="hidden" name="return_url" value="deployment_view.php?id=<?= $deploymentId ?>">
                            <button type="submit" class="btn btn-sm btn-success" title="Mark error as resolved" aria-label="Resolve error">
                              ✓ Resolve
                            </button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

  </main>
</div>

<!-- Upload & Publish Patch Modal -->
<div class="modal-bg" id="patchModal">
  <div class="modal-box">
    <div class="modal-header">
      <h3 class="modal-title">Publish System Patch / Hotfix</h3>
      <button type="button" class="icon-btn" onclick="closePatchModal()">✕</button>
    </div>
    <form method="POST" action="dashboard.php" enctype="multipart/form-data">
      <input type="hidden" name="action" value="publish_patch">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
      
      <div class="form-group">
        <label>Target System</label>
        <select name="product_id" id="patchProductId" required>
          <?php foreach ($products as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $p['id'] == $deployment['product_id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['product_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>New Version Tag (SemVer)</label>
        <input type="text" name="version" placeholder="2.5.1" class="mono" required>
      </div>

      <div class="form-group">
        <label>Changelog / Fix Summary</label>
        <textarea name="changelog" rows="3" placeholder="Hotfix for deployment error..." required></textarea>
      </div>

      <div class="form-group">
        <label>Patch Archive (.ZIP)</label>
        <input type="file" name="patch_zip" accept=".zip" required>
      </div>

      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top: 16px;">
        <button type="button" class="btn btn-outline" onclick="closePatchModal()">Cancel</button>
        <button type="submit" class="btn btn-primary">Publish &amp; Distribute</button>
      </div>
    </form>
  </div>
</div>

<!-- Enhanced AI Review & Visual Diff Modal -->
<div class="modal-bg" id="aiReviewModal">
  <div class="modal-box" style="max-width: 1080px; width: 95vw; max-height: 92vh; display: flex; flex-direction: column;">
    
    <!-- Header -->
    <div class="modal-header" style="margin-bottom: 14px;">
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <span class="btn btn-sm btn-ai" style="pointer-events:none; padding: 2px 8px; font-size: 11px;">
          ✨ AI Diagnostics
        </span>
        <div class="modal-title">
          Automated Hotfix Review
        </div>
        <span id="aiVersionBadge" class="version-tag" style="background:var(--panel-2); border:1px solid var(--line); padding:2px 8px; border-radius:4px;"></span>
      </div>
      <div style="display:flex; align-items:center; gap:12px;">
        <div style="font-size:11.5px; color:var(--text-dim);">
          Target: <code id="aiDiffFilePath" class="mono" style="color:var(--accent); background:var(--ink); padding:2px 7px; border-radius:4px; border:1px solid var(--line);"></code>
        </div>
        <button type="button" class="icon-btn" onclick="closeModal('aiReviewModal')">✕</button>
      </div>
    </div>

    <!-- Scrollable Body Content -->
    <div style="overflow-y:auto; flex:1; padding-right:4px;">
      <!-- Diagnosis Summary Cards -->
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
        <div style="background:var(--panel-2); padding:12px 14px; border-radius:var(--radius); border:1px solid var(--line); border-left:3px solid #a855f7;">
          <div style="display:flex; align-items:center; gap:6px; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#c084fc;">
            <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#a855f7;"></span>
            Root Cause Analysis
          </div>
          <p id="aiRootCause" style="margin:6px 0 0; font-size:12.5px; color:var(--text); line-height:1.5;"></p>
        </div>
        <div style="background:var(--panel-2); padding:12px 14px; border-radius:var(--radius); border:1px solid var(--line); border-left:3px solid var(--accent);">
          <div style="display:flex; align-items:center; gap:6px; font-size:10.5px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--accent);">
            <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--accent);"></span>
            Proposed Remediation
          </div>
          <p id="aiFixSummary" style="margin:6px 0 0; font-size:12.5px; color:var(--text); line-height:1.5;"></p>
        </div>
      </div>

      <!-- Visual Side-by-Side Diff Mount Point -->
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
        <div style="display:flex; align-items:center; gap:6px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:var(--text-dim);">
          Code Comparison (Side-by-Side Unified Diff)
        </div>
        <div style="font-size:11px; color:var(--text-faint); font-family:'JetBrains Mono',monospace;">
          Unified Git Diff &bull; Auto-verified syntax
        </div>
      </div>
      <div class="diff-container" id="visualDiffMount"></div>
    </div>

    <!-- Footer / Action Controls -->
    <div style="margin-top:14px; padding-top:14px; border-top:1px solid var(--line); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
      <div style="font-size:11.5px; color:var(--text-dim); display:flex; align-items:center; gap:8px;">
        <span>Archive: <code id="aiArchiveName" class="mono" style="color:var(--accent);"></code></span>
        <span style="color:var(--line);">|</span>
        <span>SHA-256: <code id="aiChecksum" class="mono" style="color:var(--ok); font-size:11px;"></code></span>
      </div>
      <form id="aiApproveForm" method="POST" action="api/v1/releases/approve.php" style="margin:0;" onsubmit="approveAiRelease(event)">
        <input type="hidden" name="csrf_token" id="aiApproveCsrfTokenDep" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="product_id" id="aiApproveProductId" value="<?= (int)$deployment['product_id'] ?>">
        <input type="hidden" name="version" id="aiApproveVersion" value="">
        <input type="hidden" name="staging_filename" id="aiApproveStagingFilename" value="">
        <input type="hidden" name="resolves_error_id" id="aiApproveErrorId" value="">
        <input type="hidden" name="release_type" value="patch">
        <input type="hidden" name="is_silent_patch" value="1">
        <input type="hidden" name="notes" id="aiApproveNotes" value="">
        <div style="display:flex; gap:10px;">
          <button type="button" class="btn btn-outline" onclick="closeModal('aiReviewModal')">Dismiss</button>
          <button type="submit" id="aiApproveBtn" class="btn btn-primary" style="background:var(--ok); border-color:var(--ok); color:#06212F; font-weight:600;">
            ✓ Approve &amp; Release to Fleet
          </button>
        </div>
      </form>
    </div>

  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/diff@5.1.0/dist/diff.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/diff2html/bundles/js/diff2html.min.js"></script>
<script>
window.activeAiPatchData = null;

function toggleTheme() {
    var cur = document.documentElement.getAttribute('data-theme') || 'dark';
    var next = (cur === 'dark') ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try {
        localStorage.setItem('spectraops_theme', next);
    } catch(e) {}
}

function openModal(id) {
    var modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('active');
        modal.style.display = 'flex';
    }
}

function closeModal(id) {
    var modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('active');
        modal.style.display = 'none';
    }
}

function openPatchModal(productId) {
    if (productId) {
        var sel = document.getElementById('patchProductId');
        if (sel) sel.value = productId;
    }
    openModal('patchModal');
}

function closePatchModal() {
    closeModal('patchModal');
}

async function triggerAiFix(errorId) {
    const btn = (typeof event !== 'undefined' && event && (event.currentTarget || (event.target.closest ? event.target.closest('button') : event.target))) || (typeof event !== 'undefined' && event ? event.target : null);
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Diagnosing...';
    }

    try {
        const fd = new FormData();
        fd.append('action', 'ai_diagnose');
        fd.append('error_id', errorId);
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>');

        const targetUrl = 'dashboard.php';
        const res = await fetch(targetUrl, { 
            method: 'POST', 
            headers: {
                'X-CSRF-Token': '<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>'
            },
            body: fd 
        });

        const rawText = await res.text();
        let data;
        try {
            data = JSON.parse(rawText);
        } catch (jsonErr) {
            console.error('Non-JSON response from server:', rawText);
            const cleanErr = rawText.replace(/<[^>]*>/g, '').trim().substring(0, 140);
            throw new Error(cleanErr || ('Server returned HTTP ' + res.status));
        }

        if (data.success) {
            window.activeAiPatchData = data;

            document.getElementById('aiVersionBadge').innerText = 'v' + data.new_version;
            document.getElementById('aiDiffFilePath').innerText = data.fixed_file_path;
            document.getElementById('aiRootCause').innerText = data.root_cause;
            document.getElementById('aiFixSummary').innerText = data.fix_summary;
            document.getElementById('aiArchiveName').innerText = data.package_filename;
            document.getElementById('aiChecksum').innerText = data.package_checksum;

            // Populate Approval Form Inputs
            if (data.csrf_token) {
                const depCsrf = document.getElementById('aiApproveCsrfTokenDep');
                if (depCsrf) depCsrf.value = data.csrf_token;
            }
            document.getElementById('aiApproveProductId').value = data.product_id || '<?= (int)$deployment["product_id"] ?>';
            document.getElementById('aiApproveVersion').value = data.new_version || '';
            document.getElementById('aiApproveStagingFilename').value = data.staging_filename || data.package_filename || '';
            document.getElementById('aiApproveErrorId').value = data.error_id;
            document.getElementById('aiApproveNotes').value = data.fix_summary || '';

            const fileName = data.fixed_file_path || 'file.php';
            let diffRendered = false;

            if (typeof Diff !== 'undefined' && typeof Diff2Html !== 'undefined' && typeof Diff2Html.html === 'function') {
                try {
                    const patchText = Diff.createTwoFilesPatch(
                        'Original/' + fileName,
                        'Patched/' + fileName,
                        data.original_code || '',
                        data.corrected_code || '',
                        'Base Version',
                        'v' + data.new_version
                    );

                    const diffHtml = Diff2Html.html(patchText, {
                        drawFileList: false,
                        matching: 'lines',
                        outputFormat: 'side-by-side',
                        renderNothingWhenEmpty: false
                    });

                    document.getElementById('visualDiffMount').innerHTML = diffHtml;
                    diffRendered = true;
                } catch (diffErr) {
                    console.warn('Diff2Html render error, using fallback:', diffErr);
                }
            }

            if (!diffRendered) {
                const escapeHtml = (str) => String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                document.getElementById('visualDiffMount').innerHTML = `
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-family:'JetBrains Mono',monospace; font-size:0.8rem; margin-top:4px;">
                        <div style="background:var(--ink); padding:12px; border-radius:6px; border:1px solid var(--line); overflow:auto; max-height:360px;">
                            <div style="color:var(--crit); font-weight:600; margin-bottom:6px; border-bottom:1px solid var(--line); padding-bottom:4px; font-size:11px; text-transform:uppercase;">Original Code</div>
                            <pre style="margin:0; white-space:pre-wrap; color:var(--text-dim); font-family:inherit;">` + escapeHtml(data.original_code || '') + `</pre>
                        </div>
                        <div style="background:var(--ink); padding:12px; border-radius:6px; border:1px solid var(--line); overflow:auto; max-height:360px;">
                            <div style="color:var(--ok); font-weight:600; margin-bottom:6px; border-bottom:1px solid var(--line); padding-bottom:4px; font-size:11px; text-transform:uppercase;">Patched Code (v` + escapeHtml(data.new_version || '') + `)</div>
                            <pre style="margin:0; white-space:pre-wrap; color:var(--text-dim); font-family:inherit;">` + escapeHtml(data.corrected_code || '') + `</pre>
                        </div>
                    </div>
                `;
            }

            openModal('aiReviewModal');
        } else {
            alert('SpectraOps AI Diagnosis Alert: ' + (data.error || 'Diagnostic engine could not generate a patch.'));
        }
    } catch (e) {
        console.error('SpectraOps AI Diagnostic Engine Error:', e);
        alert('SpectraOps AI Diagnostic Engine: ' + (e.message || 'Failed to connect.'));
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}

async function approveAiRelease(event) {
    if (event) event.preventDefault();
    const btn = document.getElementById('aiApproveBtn');
    const origText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = 'Publishing &amp; Promoting...';
    }

    try {
        const activeCsrf = (document.getElementById('aiApproveCsrfTokenDep')?.value) || '<?= htmlspecialchars($_SESSION["csrf_token"] ?? "") ?>';
        const payload = {
            product_id: parseInt(document.getElementById('aiApproveProductId').value, 10),
            version: document.getElementById('aiApproveVersion').value,
            staging_filename: document.getElementById('aiApproveStagingFilename').value,
            resolves_error_id: parseInt(document.getElementById('aiApproveErrorId').value, 10) || null,
            release_type: 'patch',
            is_silent_patch: 1,
            notes: document.getElementById('aiApproveNotes').value,
            csrf_token: activeCsrf
        };

        const res = await fetch('api/v1/releases/approve.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': activeCsrf
            },
            body: JSON.stringify(payload)
        });

        const data = await res.json();
        if ((res.status === 201 || res.ok) && data.success) {
            closeModal('aiReviewModal');
            alert('✓ Hotfix v' + data.version + ' approved and published!\n\nPackage: ' + data.package_filename + '\nChecksum: ' + data.package_checksum + '\n\nStaged for instant OTA delivery to fleet nodes.');
            window.location.reload();
        } else {
            alert('Approval Error: ' + (data.error || 'Failed to publish release.'));
        }
    } catch (err) {
        console.error('Release approval error:', err);
        alert('Network/Server error approving release: ' + err.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }
}
</script>
</body>
</html>
