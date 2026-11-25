<?php
declare(strict_types=1);

/**
 * SpectraOps view helpers (server-side twins of assets/js/spectraops-ui.js).
 *
 * Include once from the layout:   require_once __DIR__ . '/so_helpers.php';
 * Thresholds MUST mirror public/api/v1/telemetry/ingest.php and src/FleetService.php.
 */

if (!defined('SO_ASSET_DIR')) {
    // Folder that contains img/icons.svg. Override before including if assets live elsewhere.
    define('SO_ASSET_DIR', dirname(__DIR__) . '/assets');
}

const SO_LATENCY_WARN_MS = 500;
const SO_LATENCY_CRIT_MS = 1500;
const SO_DISK_WARN_GB    = 3.0;
const SO_DISK_CRIT_GB    = 1.0;
const SO_HB_STALE_SEC    = 1800;   // 30 min  -> "stale"
const SO_HB_DEAD_SEC     = 7200;   // 2 h     -> "dead"

/** HTML-escape (always use for output). */
function so_e(mixed $v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Echo the inline SVG sprite once, right after <body>. */
function so_icon_sprite(): void
{
    $f = SO_ASSET_DIR . '/img/icons.svg';
    if (is_file($f)) {
        readfile($f);
    }
}

/** <svg class="icon"><use href="#i-NAME"/></svg>  ($label => accessible name, else decorative) */
function so_icon(string $name, string $class = '', ?string $label = null): string
{
    $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name));
    $cls  = trim('icon ' . $class);
    $a11y = $label !== null ? ' role="img" aria-label="' . so_e($label) . '"' : ' aria-hidden="true" focusable="false"';
    return '<svg class="' . so_e($cls) . '"' . $a11y . '><use href="#i-' . $name . '"/></svg>';
}

/** Status pill. $status: healthy | warning | critical | unreachable */
function so_status_pill(?string $status, ?string $reason = null): string
{
    $map = ['healthy' => 'pill-ok', 'warning' => 'pill-warn', 'critical' => 'pill-crit', 'unreachable' => 'pill-dead'];
    $s   = strtolower((string)$status);
    $cls = $map[$s] ?? 'pill-dead';
    $t   = $reason !== null && $reason !== '' ? ' title="' . so_e($reason) . '"' : '';
    return '<span class="pill ' . $cls . '"' . $t . '>' . so_e($s !== '' ? $s : 'unknown') . '</span>';
}

/** Row edge class for a deployment row. */
function so_row_class(?string $status): string
{
    return ['critical' => 'row-crit', 'warning' => 'row-warn', 'unreachable' => 'row-dead'][strtolower((string)$status)] ?? '';
}

/** '' | 'is-hot' | 'is-crit'  for kind = latency | disk */
function so_metric_class(string $kind, mixed $v): string
{
    if ($v === null || $v === '' || !is_numeric($v)) {
        return '';
    }
    $v = (float)$v;
    if ($kind === 'latency') {
        return ($v < 0 || $v > SO_LATENCY_CRIT_MS) ? 'is-crit' : ($v > SO_LATENCY_WARN_MS ? 'is-hot' : '');
    }
    if ($kind === 'disk') {
        return $v <= 0 ? '' : ($v < SO_DISK_CRIT_GB ? 'is-crit' : ($v < SO_DISK_WARN_GB ? 'is-hot' : ''));
    }
    return '';
}

function so_fmt_ms(mixed $v): string
{
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    return (float)$v < 0 ? 'DB down' : number_format((float)$v, 0) . ' ms';
}

function so_fmt_gb(mixed $v): string
{
    return ($v === null || $v === '' || !is_numeric($v) || (float)$v <= 0) ? '—' : number_format((float)$v, 1) . ' GB';
}

function so_fmt_mb(mixed $v): string
{
    return ($v === null || $v === '' || !is_numeric($v) || (float)$v <= 0) ? '—' : number_format((float)$v, 0) . ' MB';
}

/** Integer format with fallback. */
function so_fmt_int(mixed $v): string
{
    return ($v === null || $v === '' || !is_numeric($v)) ? '—' : number_format((float)$v, 0);
}

/**
 * Relative heartbeat time.
 * @return array{0:string,1:string} [text, level]  level = never|fresh|stale|dead
 */
function so_rel_time(?string $datetime, ?DateTimeInterface $now = null): array
{
    if ($datetime === null || $datetime === '') {
        return ['Never', 'never'];
    }
    try {
        $then = new DateTimeImmutable($datetime);
    } catch (Throwable $e) {
        return ['Never', 'never'];
    }
    $now = $now ?? new DateTimeImmutable('now');
    $sec = max(0, $now->getTimestamp() - $then->getTimestamp());

    if ($sec < 60)         $text = $sec . 's ago';
    elseif ($sec < 3600)   $text = intdiv($sec, 60) . 'm ago';
    elseif ($sec < 86400)  $text = intdiv($sec, 3600) . 'h ' . intdiv($sec % 3600, 60) . 'm ago';
    else                   $text = intdiv($sec, 86400) . 'd ago';

    $level = $sec >= SO_HB_DEAD_SEC ? 'dead' : ($sec >= SO_HB_STALE_SEC ? 'stale' : 'fresh');
    return [$text, $level];
}

/** CSS class for a so_rel_time() level. */
function so_rel_class(?string $level): string
{
    return ['dead' => 'is-dead', 'stale' => 'is-stale', 'never' => 'is-muted'][$level ?? ''] ?? '';
}

/** true when $current is older than $target. */
function so_is_outdated(?string $current, ?string $target): bool
{
    return $current !== null && $target !== null && $target !== '' && version_compare($current, $target, '<');
}

/**
 * Whitelisted payload for <button data-dep-open data-dep="...">.
 * NEVER json_encode a raw "SELECT d.*" row into the page: it contains api_ingest_token_hash.
 */
function so_dep_payload(array $r): array
{
    $keys = [
        'id', 'client_name', 'deployment_domain', 'product_name', 'current_version', 'environment',
        'server_ip', 'php_version', 'mysql_version', 'os_info', 'token_preview', 'status', 'computed_status',
        'status_reason', 'last_heartbeat_at', 'heartbeat_recorded_at', 'db_latency_ms', 'memory_usage_mb',
        'storage_free_gb', 'active_users_24h', 'load_avg',
    ];
    return array_intersect_key($r, array_flip($keys));
}

/** Attribute-safe JSON for data-dep="...". */
function so_json_attr(array $payload): string
{
    return so_e(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
}

/** aria-current helper:  <a <?= so_current($page, 'errors') ?>> */
function so_current(string $active, string $page, string $attr = 'page'): string
{
    return $active === $page ? ' aria-current="' . $attr . '"' : '';
}
