/* SpectraOps fleet console: data layer for public/index.html.
 * Uses the same API as before (api/v1/fleet.php) and the shared UI kit (assets/js/spectraops-ui.js).
 * Every value is escaped with SO.esc before it reaches innerHTML. */
(function () {
  'use strict';
  var API = 'api/v1/fleet.php';
  var VIEWS = {
    overview: ['Overview', 'What needs you right now across every client system.'],
    deployments: ['Fleet', 'Every client deployment reporting to this hub.'],
    errors: ['Errors', 'Crashes and exceptions, grouped by fingerprint across every client.']
  };
  var esc = SO.esc;
  var $ = function (id) { return document.getElementById(id); };
  var cache = { deployments: [], errors: [] };
  var searchTimer = null;

  function stateRow(cols, title, text, cls) {
    return '<tr class="state-row ' + (cls || '') + '"><td colspan="' + cols + '"><strong>' + esc(title) + '</strong>' + esc(text || '') + '</td></tr>';
  }

  function getJSON(url, opts) {
    return fetch(url, opts).then(function (r) {
      if (r.status === 401 || r.status === 403) { throw new Error('auth'); }
      return r.json();
    });
  }

  /* ---------------- views ---------------- */
  function showView(name) {
    if (!VIEWS[name]) name = 'overview';
    document.querySelectorAll('[data-view-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-view-panel') !== name; });
    document.querySelectorAll('.rail-link[data-view]').forEach(function (a) {
      if (a.getAttribute('data-view') === name) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
    });
    $('view-title').textContent = VIEWS[name][0];
    $('view-sub').textContent = VIEWS[name][1];
    document.title = VIEWS[name][0] + ' · SpectraOps';
    if (name === 'errors') loadErrors();
    if (name === 'deployments') loadDeployments();
    document.body.classList.remove('rail-open');
  }
  function route() { showView((location.hash || '#overview').slice(1)); }

  /* ---------------- overview ---------------- */
  function loadOverview() {
    return getJSON(API + '?action=overview').then(function (json) {
      if (json.status !== 'success') return;
      var d = json.data;
      $('kpi-total-val').textContent = d.total_deployments;
      $('kpi-healthy-val').textContent = d.healthy_count;
      $('kpi-warning-val').textContent = d.warning_count;
      $('kpi-critical-val').textContent = d.critical_count;
      $('kpi-unreachable-val').textContent = d.unreachable_count;
      $('sidebar-total-badge').textContent = d.total_deployments;
      $('sidebar-error-badge').textContent = d.recent_errors_24h;
      fillProducts(d.products || []);
    }).catch(fail('overview'));
  }

  function fillProducts(products) {
    [['filter-product', 'All products'], ['reg-product', 'Select a product']].forEach(function (p) {
      var sel = $(p[0]); if (!sel) return;
      var keep = sel.value;
      sel.innerHTML = '<option value="">' + p[1] + '</option>' + products.map(function (x) {
        return '<option value="' + esc(x.id) + '">' + esc(x.product_name || x.name) + '</option>';
      }).join('');
      sel.value = keep;
    });
  }

  function attention(list) {
    var rank = { critical: 0, warning: 1, unreachable: 2 };
    var bad = list.filter(function (d) { return d.status in rank; }).sort(function (a, b) { return rank[a.status] - rank[b.status]; });
    $('att-count').textContent = bad.length + ' of ' + list.length + ' systems';
    $('attention-tbody').innerHTML = bad.length ? bad.map(function (d) {
      var hb = SO.relTime(d.last_heartbeat_at);
      return '<tr class="' + SO.rowClass(d.status) + '"><td>' + clientCell(d) + '</td><td>' + SO.statusPill(d.status, d.status_reason) +
        '</td><td class="dim">' + esc(d.status_reason || '') + '</td><td class="nowrap num ' + SO.relClass(hb.level) + '">' + esc(hb.text) + '</td></tr>';
    }).join('') : stateRow(4, 'Nothing needs attention', 'Every system is reporting normally.', 'is-ok');
  }

  /* ---------------- deployments ---------------- */
  function clientCell(d) {
    return '<div class="cell-main"><button type="button" class="link-btn" data-open-dep="' + esc(d.id) + '">' + esc(d.client_name) + '</button><span class="cell-sub">' + esc(d.deployment_domain) + '</span></div>';
  }

  function loadDeployments() {
    var q = [];
    var p = $('filter-product').value, s = $('filter-status').value, t = $('search-input').value.trim();
    if (p) q.push('product_id=' + encodeURIComponent(p));
    if (s && s !== 'all') q.push('status=' + encodeURIComponent(s));
    if (t) q.push('search=' + encodeURIComponent(t));
    var tb = $('deployments-tbody');
    return getJSON(API + '?action=deployments' + (q.length ? '&' + q.join('&') : '')).then(function (json) {
      var rows = json.status === 'success' && Array.isArray(json.data) ? json.data : [];
      cache.deployments = rows;
      $('dep-count').textContent = rows.length + (rows.length === 1 ? ' deployment' : ' deployments');
      fillErrorFilter(rows);
      if (!q.length) attention(rows);
      if (!rows.length) { tb.innerHTML = stateRow(8, 'No deployments match', 'Clear the filters, or register a deployment to start monitoring.'); return; }
      tb.innerHTML = rows.map(function (d) {
        var hb = SO.relTime(d.last_heartbeat_at);
        var out = d.is_outdated;
        return '<tr class="' + SO.rowClass(d.status) + '"><td>' + clientCell(d) + '</td>' +
          '<td><span class="tag">' + esc(d.product_name) + '</span></td>' +
          '<td>' + SO.statusPill(d.status, d.status_reason) + '</td>' +
          '<td><span class="ver' + (out ? ' is-outdated' : '') + '"' + (out ? ' title="Latest is v' + esc(d.target_version) + '"' : '') + '>v' + esc(d.current_version) + '</span></td>' +
          '<td class="num ' + SO.metricClass('latency', d.db_latency_ms) + '">' + SO.fmt.ms(d.db_latency_ms) + '</td>' +
          '<td class="num ' + SO.metricClass('disk', d.storage_free_gb) + '">' + SO.fmt.gb(d.storage_free_gb) + '</td>' +
          '<td class="nowrap num ' + SO.relClass(hb.level) + '">' + esc(hb.text) + '</td>' +
          '<td class="col-actions"><div class="row-actions"><button type="button" class="btn btn-sm" data-open-dep="' + esc(d.id) + '">Inspect</button></div></td></tr>';
      }).join('');
    }).catch(function (e) { tb.innerHTML = stateRow(8, "Couldn't load fleet data", 'Check the hub connection, then refresh.', 'is-error'); fail('deployments')(e); });
  }

  function fillErrorFilter(rows) {
    var sel = $('filter-error-deployment'); var keep = sel.value;
    sel.innerHTML = '<option value="">All deployments</option>' + rows.map(function (d) {
      return '<option value="' + esc(d.id) + '">' + esc(d.client_name) + '</option>';
    }).join('');
    sel.value = keep;
  }

  /* ---------------- errors ---------------- */
  function loadErrors() {
    var id = $('filter-error-deployment').value;
    var tb = $('errors-tbody');
    return getJSON(API + '?action=errors&limit=50' + (id ? '&deployment_id=' + encodeURIComponent(id) : '')).then(function (json) {
      var rows = json.status === 'success' && Array.isArray(json.data) ? json.data : [];
      cache.errors = rows;
      $('err-count-label').textContent = rows.length + (rows.length === 1 ? ' incident' : ' incidents');
      if (!rows.length) { tb.innerHTML = stateRow(7, 'No active errors', 'Every system is running cleanly.', 'is-ok'); return; }
      tb.innerHTML = rows.map(function (e, i) {
        var hard = /CRIT|FATAL|EXCEPTION|ERROR/i.test(e.error_level || '');
        var file = e.file_path ? String(e.file_path).split(/[\\/]/).pop() + ':' + (e.line_number || 0) : '—';
        return '<tr class="' + (hard ? 'row-crit' : '') + '"><td>' + SO.errorPill(e.error_level) + '</td>' +
          '<td style="max-width:360px"><div class="truncate" title="' + esc(e.error_message) + '">' + esc(e.error_message) + '</div></td>' +
          '<td><div class="cell-main"><strong>' + esc(e.client_name) + '</strong><span class="cell-sub">' + esc(e.product_name) + '</span></div></td>' +
          '<td class="mono dim nowrap">' + esc(file) + '</td><td class="num">' + SO.fmt.int(e.occurrences) + '×</td>' +
          '<td class="num nowrap dim">' + esc(String(e.last_seen_at || '').slice(5, 16)) + '</td>' +
          '<td class="col-actions"><div class="row-actions"><button type="button" class="btn btn-sm" data-open-err="' + i + '">View trace</button></div></td></tr>';
      }).join('');
    }).catch(function (e) { tb.innerHTML = stateRow(7, "Couldn't load errors", 'Check the hub connection, then refresh.', 'is-error'); fail('errors')(e); });
  }

  /* ---------------- actions ---------------- */
  function register(e) {
    e.preventDefault();
    var btn = $('submit-register-btn');
    var body = {
      product_id: parseInt($('reg-product').value, 10),
      client_name: $('reg-client').value.trim(),
      deployment_domain: $('reg-domain').value.trim(),
      environment: $('reg-env').value,
      current_version: $('reg-version').value.trim()
    };
    if (!body.product_id || !body.client_name || !body.deployment_domain) { SO.toast('Fill in product, client and domain'); return; }
    btn.disabled = true; btn.setAttribute('aria-busy', 'true');
    getJSON(API + '?action=deployments', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
      .then(function (json) {
        if (json.status !== 'success') { SO.toast('Registration failed: ' + (json.message || 'unknown error')); return; }
        $('register-form').hidden = true;
        $('token-reveal-panel').hidden = false;
        btn.hidden = true;
        $('revealed-token').value = json.data.token;
        SO.toast('Deployment registered');
        refreshAll();
      })
      .catch(function () { SO.toast('Registration request failed'); })
      .then(function () { btn.disabled = false; btn.removeAttribute('aria-busy'); });
  }

  function sweep() {
    var btn = $('trigger-health-btn');
    btn.disabled = true; btn.setAttribute('aria-busy', 'true');
    getJSON(API + '?action=check_health', { method: 'POST' }).then(function (json) {
      if (json.status === 'success') {
        SO.toast('Sweep complete: ' + json.data.unreachable_marked + ' unreachable, ' + json.data.warning_marked + ' warnings');
        refreshAll();
      }
    }).catch(function () { SO.toast('Health sweep failed'); })
      .then(function () { btn.disabled = false; btn.removeAttribute('aria-busy'); });
  }

  function fail(where) {
    return function (err) {
      if (err && err.message === 'auth') { location.href = 'dashboard.php'; return; }
      console.error('SpectraOps ' + where + ':', err);
    };
  }

  function refreshAll() {
    loadOverview();
    loadDeployments();
    if (!$('view-errors').hidden) loadErrors();
  }

  /* ---------------- wiring ---------------- */
  document.addEventListener('click', function (e) {
    var dep = e.target.closest('[data-open-dep]');
    if (dep) {
      var id = String(dep.getAttribute('data-open-dep'));
      var row = cache.deployments.filter(function (d) { return String(d.id) === id; })[0];
      if (row) SO.openDeployment(row, {});
      return;
    }
    var er = e.target.closest('[data-open-err]');
    if (er) {
      var item = cache.errors[Number(er.getAttribute('data-open-err'))];
      if (item) SO.openError(item, {});
      return;
    }
    var strip = e.target.closest('.strip-item[data-status]');
    if (strip) { $('filter-status').value = strip.getAttribute('data-status') || 'all'; }
    if (e.target.closest('[data-open="#dlg-register"]')) {
      $('register-form').hidden = false; $('token-reveal-panel').hidden = true; $('submit-register-btn').hidden = false; $('register-form').reset(); $('reg-version').value = '1.0.0';
    }
  });

  window.addEventListener('hashchange', route);
  $('register-form').addEventListener('submit', register);
  $('trigger-health-btn').addEventListener('click', sweep);
  document.querySelector('.toolbar .search').addEventListener('submit', function (e) { e.preventDefault(); loadDeployments(); });
  ['filter-product', 'filter-status'].forEach(function (id) { $(id).addEventListener('change', loadDeployments); });
  $('filter-error-deployment').addEventListener('change', loadErrors);
  $('search-input').addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(loadDeployments, 300); });

  route();
  loadOverview();
  loadDeployments();
  setInterval(function () { if (!document.hidden && !document.querySelector('.overlay.is-open')) refreshAll(); }, 60000);
})();
