/* ==========================================================================
   SpectraOps UI helpers  v1.0   (no dependencies, ES2017)
   - SO.esc / SO.fmt / SO.relTime / SO.metricClass / SO.statusPill / SO.sparkline
   - SO.open / SO.close   (dialogs + drawers, focus trap, Esc, restore focus)
   - SO.toast
   - SO.openDeployment    (fills the diagnostics drawer)
   - delegated hooks: [data-open] [data-close] [data-nav-toggle] [data-copy]
                      [data-confirm] [data-toggle-password] [data-dep-open]
   Thresholds below MUST mirror public/api/v1/telemetry/ingest.php.
   ========================================================================== */
(function () {
  'use strict';
  var SO = (window.SO = window.SO || {});

  /* ---- thresholds (same numbers ingest.php uses to set status) ---------- */
  SO.thresholds = {
    latency: { warn: 500, crit: 1500 },        // ms   (> warn, > crit)
    disk: { warn: 3, crit: 1 },                // GB free (< warn, < crit); 0 = unknown
    heartbeat: { stale: 30 * 60, dead: 2 * 3600 } // seconds (fleet_monitor.php)
  };

  /* ---- text + formatting ------------------------------------------------ */
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  SO.esc = function (s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; });
  };
  var isNum = function (v) { return v !== null && v !== undefined && v !== '' && !isNaN(Number(v)); };
  SO.fmt = {
    int: function (v) { return isNum(v) ? Math.round(Number(v)).toLocaleString() : '—'; },
    ms: function (v) {
      if (!isNum(v)) return '—';
      v = Number(v);
      return v < 0 ? 'DB down' : Math.round(v).toLocaleString() + ' ms';
    },
    mb: function (v) { return isNum(v) && Number(v) > 0 ? Math.round(Number(v)).toLocaleString() + ' MB' : '—'; },
    gb: function (v) { return isNum(v) && Number(v) > 0 ? (Math.round(Number(v) * 10) / 10).toLocaleString() + ' GB' : '—'; }
  };

  /* Returns '' | 'is-hot' | 'is-crit' */
  SO.metricClass = function (kind, v) {
    if (!isNum(v)) return '';
    v = Number(v);
    var t = SO.thresholds;
    if (kind === 'latency') return v < 0 || v > t.latency.crit ? 'is-crit' : v > t.latency.warn ? 'is-hot' : '';
    if (kind === 'disk') return v <= 0 ? '' : v < t.disk.crit ? 'is-crit' : v < t.disk.warn ? 'is-hot' : '';
    return '';
  };

  /* "2026-11-16 13:52:00" (server local) or ISO -> Date. Safari needs the T. */
  SO.parseDate = function (s) {
    if (!s) return null;
    var d = new Date(/^\d{4}-\d{2}-\d{2} \d/.test(s) ? s.replace(' ', 'T') : s);
    return isNaN(d.getTime()) ? null : d;
  };
  /* -> { text, level: 'never' | 'fresh' | 'stale' | 'dead' } */
  SO.relTime = function (s) {
    var d = SO.parseDate(s);
    if (!d) return { text: 'Never', level: 'never' };
    return SO.relFromSec(Math.floor((Date.now() - d.getTime()) / 1000));
  };
  SO.relFromSec = function (sec) {
    var text;
    sec = Math.max(0, Number(sec) || 0);
    if (sec < 60) text = sec + 's ago';
    else if (sec < 3600) text = Math.floor(sec / 60) + 'm ago';
    else if (sec < 86400) text = Math.floor(sec / 3600) + 'h ' + Math.floor((sec % 3600) / 60) + 'm ago';
    else text = Math.floor(sec / 86400) + 'd ago';
    var h = SO.thresholds.heartbeat;
    return { text: text, level: sec >= h.dead ? 'dead' : sec >= h.stale ? 'stale' : 'fresh' };
  };
  SO.relClass = function (level) { return level === 'dead' ? 'is-dead' : level === 'stale' ? 'is-stale' : level === 'never' ? 'is-muted' : ''; };

  /* ---- status ----------------------------------------------------------- */
  var PILL = { healthy: 'pill-ok', warning: 'pill-warn', critical: 'pill-crit', unreachable: 'pill-dead' };
  var ROW = { critical: 'row-crit', warning: 'row-warn', unreachable: 'row-dead' };
  SO.statusPill = function (status, reason) {
    var s = String(status || 'unknown').toLowerCase();
    return '<span class="pill ' + (PILL[s] || 'pill-dead') + '"' + (reason ? ' title="' + SO.esc(reason) + '"' : '') + '>' + SO.esc(s) + '</span>';
  };
  SO.rowClass = function (status) { return ROW[String(status || '').toLowerCase()] || ''; };

  /* ---- tiny sparkline (values -> inline SVG path) ----------------------- */
  SO.sparkline = function (values, label) {
    var v = (values || []).map(Number).filter(function (n) { return !isNaN(n) && n >= 0; });
    if (v.length < 2) return '';
    var W = 240, H = 44, pad = 3, min = Math.min.apply(null, v), max = Math.max.apply(null, v), span = max - min || 1;
    var pts = v.map(function (n, i) {
      return (i / (v.length - 1) * W).toFixed(1) + ' ' + (H - pad - (n - min) / span * (H - pad * 2)).toFixed(1);
    });
    return '<svg class="spark" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" role="img" aria-label="' + SO.esc(label || 'Trend') + '"><path d="M' + pts.join(' L') + '"/></svg>';
  };

  /* ---- toast ------------------------------------------------------------ */
  var toastTimer;
  SO.toast = function (msg) {
    var el = document.getElementById('toast');
    if (!el) {
      el = document.createElement('div');
      el.id = 'toast'; el.className = 'toast';
      el.setAttribute('role', 'status'); el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    el.textContent = msg;
    el.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('is-visible'); }, 3200);
  };

  /* ---- overlays (dialog / drawer) -------------------------------------- */
  var lastFocus = null;
  var FOCUSABLE = 'a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
  var $ = function (sel) { return typeof sel === 'string' ? document.querySelector(sel) : sel; };

  SO.open = function (sel) {
    var el = $(sel);
    if (!el) return;
    lastFocus = document.activeElement;
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
    document.body.classList.add('has-overlay');
    var panel = el.querySelector('.dialog, .drawer');
    var first = el.querySelector('[autofocus]') || (panel && panel.querySelector('input:not([type=hidden]), select, textarea')) || (panel && panel.querySelector(FOCUSABLE)) || panel;
    if (first) first.focus();
  };
  SO.close = function (sel) {
    var el = sel ? $(sel) : document.querySelector('.overlay.is-open');
    if (!el) return;
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
    if (!document.querySelector('.overlay.is-open')) document.body.classList.remove('has-overlay');
    if (lastFocus && document.contains(lastFocus)) lastFocus.focus();
    lastFocus = null;
  };

  /* ---- deployment diagnostics drawer ----------------------------------- *
     Expects the drawer markup from reference/deployments.html (ids dep-*).   */
  SO.openDeployment = function (dep, opts) {
    opts = opts || {};
    var set = function (id, v) { var e = document.getElementById(id); if (e) e.textContent = v; };
    var html = function (id, v) { var e = document.getElementById(id); if (e) e.innerHTML = v; };
    var status = dep.computed_status || dep.status || 'healthy';
    set('dep-title', dep.client_name || 'Deployment');
    set('dep-domain', dep.deployment_domain || '');
    html('dep-status', SO.statusPill(status));
    set('dep-reason', dep.status_reason || 'Normal operation');
    set('dep-product', (dep.product_name || '—') + (dep.current_version ? '  v' + dep.current_version : ''));
    set('dep-env', (dep.environment || 'production'));
    set('dep-ip', dep.server_ip || 'Awaiting heartbeat');
    set('dep-stack', (dep.php_version ? 'PHP ' + dep.php_version : 'PHP ?') + '  /  ' + (dep.mysql_version ? 'DB ' + dep.mysql_version : 'DB ?'));
    set('dep-os', dep.os_info || 'Unknown');
    set('dep-key', dep.token_preview || '—');
    /* heartbeat_ago (seconds) is optional; it lets static demos and APIs skip timestamp parsing */
    var hb = dep.heartbeat_ago != null ? SO.relFromSec(dep.heartbeat_ago) : SO.relTime(dep.last_heartbeat_at || dep.heartbeat_recorded_at);
    set('dep-heartbeat', hb.text);
    var tile = function (id, text, cls) { var e = document.getElementById(id); if (e) { e.textContent = text; e.className = cls || ''; } };
    tile('dep-latency', SO.fmt.ms(dep.db_latency_ms), SO.metricClass('latency', dep.db_latency_ms));
    tile('dep-memory', SO.fmt.mb(dep.memory_usage_mb));
    tile('dep-disk', SO.fmt.gb(dep.storage_free_gb), SO.metricClass('disk', dep.storage_free_gb));
    tile('dep-users', SO.fmt.int(dep.active_users_24h));
    var spark = SO.sparkline(dep.series, 'Database latency, recent heartbeats');
    html('dep-spark', spark);
    var sw = document.getElementById('dep-spark-wrap');
    if (sw) sw.hidden = !spark;
    var visit = document.getElementById('dep-visit');
    if (visit) {
      var ok = /^[a-z0-9]([a-z0-9.-]*[a-z0-9])?(:\d{1,5})?$/i.test(dep.deployment_domain || '');
      if (ok) { visit.href = 'https://' + dep.deployment_domain; visit.hidden = false; } else { visit.hidden = true; }
    }
    var rm = document.getElementById('dep-remove');
    if (rm) { if (opts.removeHref) { rm.href = opts.removeHref; rm.hidden = false; } else { rm.hidden = true; } }
    SO.open('#dep-drawer');
  };

  /* ---- error incident dialog ------------------------------------------- *
     Expects the dialog markup from reference/errors.html (ids err-*).        */
  SO.errorPill = function (level) {
    var l = String(level || '').toUpperCase();
    var cls = /WARN/.test(l) ? 'pill-warn' : /CRIT|FATAL|EXCEPTION|ERROR/.test(l) ? 'pill-crit' : 'pill-dead';
    return '<span class="pill ' + cls + '">' + SO.esc(l || 'UNKNOWN') + '</span>';
  };
  SO.openError = function (err, opts) {
    opts = opts || {};
    var set = function (id, v) { var e = document.getElementById(id); if (e) e.textContent = v; };
    var lvl = document.getElementById('err-level');
    if (lvl) lvl.innerHTML = SO.errorPill(err.error_level);
    set('err-message', err.error_message || '');
    set('err-client', (err.client_name || '—') + (err.deployment_domain ? '  (' + err.deployment_domain + ')' : ''));
    set('err-location', err.file_path ? err.file_path + ':' + (err.line_number || 0) : (err.request_uri || 'Unknown'));
    set('err-count', SO.fmt.int(err.occurrences) + (Number(err.occurrences) === 1 ? ' event' : ' events'));
    set('err-first', err.first_seen_at || '—');
    set('err-last', err.last_seen_at || '—');
    set('err-trace', err.stack_trace || 'No stack trace was sent for this incident.');
    var clr = document.getElementById('err-clear');
    if (clr) { if (opts.clearHref) { clr.href = opts.clearHref; clr.hidden = false; } else { clr.hidden = true; } }
    SO.open('#err-dialog');
  };

  /* ---- delegated behaviour --------------------------------------------- */
  document.addEventListener('click', function (e) {
    var t = e.target;
    var errEl = t.closest('[data-err-open]');
    if (errEl) {
      e.preventDefault();
      try { SO.openError(JSON.parse(errEl.getAttribute('data-err')), { clearHref: errEl.getAttribute('data-clear-href') || '' }); }
      catch (err) { console.error('SpectraOps: bad data-err JSON', err); }
      return;
    }
    var openEl = t.closest('[data-open]');
    if (openEl) { e.preventDefault(); SO.open(openEl.getAttribute('data-open')); return; }
    var depEl = t.closest('[data-dep-open]');
    if (depEl) {
      e.preventDefault();
      try { SO.openDeployment(JSON.parse(depEl.getAttribute('data-dep')), { removeHref: depEl.getAttribute('data-remove-href') || '' }); }
      catch (err) { console.error('SpectraOps: bad data-dep JSON', err); }
      return;
    }
    if (t.closest('[data-close]')) { e.preventDefault(); SO.close(t.closest('.overlay')); return; }
    if (t.classList && t.classList.contains('overlay')) { SO.close(t); return; }
    var nav = t.closest('[data-nav-toggle]');
    if (nav) { document.body.classList.toggle('rail-open'); return; }
    if (document.body.classList.contains('rail-open') && !t.closest('.rail')) document.body.classList.remove('rail-open');
    var cp = t.closest('[data-copy]');
    if (cp) {
      var src = document.querySelector(cp.getAttribute('data-copy'));
      if (src) {
        var val = 'value' in src ? src.value : src.textContent;
        (navigator.clipboard ? navigator.clipboard.writeText(val) : Promise.reject()).then(
          function () { SO.toast('Copied to clipboard'); },
          function () { if (src.select) { src.select(); document.execCommand('copy'); SO.toast('Copied to clipboard'); } }
        );
      }
      return;
    }
    var pw = t.closest('[data-toggle-password]');
    if (pw) {
      var inp = document.querySelector(pw.getAttribute('data-toggle-password'));
      if (inp) {
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        pw.setAttribute('aria-pressed', show ? 'true' : 'false');
        pw.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      }
    }
    var cf = t.closest('[data-confirm]');
    if (cf && !window.confirm(cf.getAttribute('data-confirm'))) { e.preventDefault(); }
  });

  document.addEventListener('keydown', function (e) {
    var open = document.querySelector('.overlay.is-open');
    if (!open) { if (e.key === 'Escape') document.body.classList.remove('rail-open'); return; }
    if (e.key === 'Escape') { SO.close(open); return; }
    if (e.key === 'Tab') {
      var f = Array.prototype.filter.call(open.querySelectorAll(FOCUSABLE), function (n) { return n.offsetParent !== null; });
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  /* POST forms: show a busy state on the submit button (no double submits). */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f || (f.method || '').toLowerCase() !== 'post' || f.hasAttribute('data-no-busy')) return;
    var b = f.querySelector('button[type=submit], button:not([type])');
    if (b) b.setAttribute('aria-busy', 'true');
  });

  document.addEventListener('DOMContentLoaded', function () {
    Array.prototype.forEach.call(document.querySelectorAll('.dialog, .drawer'), function (n) { n.setAttribute('tabindex', '-1'); });
    Array.prototype.forEach.call(document.querySelectorAll('.overlay'), function (n) { n.setAttribute('aria-hidden', 'true'); });
  });
})();
