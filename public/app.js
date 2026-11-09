// ====================================================================
// SpectraOps — Fleet Management Hub Frontend Logic
// ====================================================================

let currentView = 'fleet';
let searchDebounceTimeout = null;
let cachedErrors = [];

document.addEventListener('DOMContentLoaded', () => {
    const savedTheme = localStorage.getItem('spectraops_theme') || 'dark';
    applyTheme(savedTheme);
    initDashboard();
    setInterval(updateClock, 1000);
    // Auto-refresh telemetry every 20 seconds
    setInterval(() => {
        if (document.visibilityState === 'visible') {
            refreshCurrentView(false);
        }
    }, 20000);
});

function updateClock() {
    const el = document.getElementById('hub-clock');
    if (el) {
        const now = new Date();
        el.textContent = 'Live Hub Active • ' + now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    }
}

async function initDashboard() {
    await loadOverview();
    await loadDeployments();
}

function switchView(viewName) {
    currentView = viewName;

    document.querySelectorAll('.rail .nav-item').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.content-view').forEach(view => view.classList.remove('active'));

    const navBtn = document.getElementById(`nav-${viewName}-btn`);
    const viewSection = document.getElementById(`view-${viewName}`);
    const statusStrip = document.getElementById('status-strip');
    const filterBar = document.getElementById('filter-bar');

    if (navBtn) navBtn.classList.add('active');
    if (viewSection) viewSection.classList.add('active');

    const titleEl = document.getElementById('page-title');
    const subtitleEl = document.getElementById('page-subtitle');

    if (viewName === 'fleet') {
        titleEl.textContent = 'Fleet';
        subtitleEl.textContent = 'Every client deployment reporting to this hub, live.';
        if (statusStrip) statusStrip.classList.remove('hidden');
        if (filterBar) filterBar.classList.remove('hidden');
        loadDeployments();
    } else if (viewName === 'errors') {
        titleEl.textContent = 'Errors';
        subtitleEl.textContent = 'Application exceptions, crash events, and deduplicated error stream.';
        if (statusStrip) statusStrip.classList.add('hidden');
        if (filterBar) filterBar.classList.remove('hidden');
        loadErrors();
    } else if (viewName === 'sdk') {
        titleEl.textContent = 'Client SDK';
        subtitleEl.textContent = 'Lightweight non-blocking telemetry reporter library for client apps.';
        if (statusStrip) statusStrip.classList.add('hidden');
        if (filterBar) filterBar.classList.add('hidden');
    }
}

function refreshCurrentView(showToastMessage = true) {
    loadOverview();
    if (currentView === 'fleet') {
        loadDeployments();
    } else if (currentView === 'errors') {
        loadErrors();
    }
    if (showToastMessage) {
        showToast('Telemetry refreshed');
    }
}

// ====================================================================
// Status Strip Filter Shortcuts
// ====================================================================
function setStatusFilter(status) {
    const sel = document.getElementById('filter-status');
    if (sel) {
        sel.value = status;
    }

    // Highlight active status strip item
    const stripItems = document.querySelectorAll('.status-strip .strip-item');
    stripItems.forEach(item => item.classList.remove('active'));

    const statusMap = {
        'all': 0,
        'healthy': 1,
        'warning': 2,
        'critical': 3,
        'unreachable': 4,
        'outdated': 5
    };
    const targetIdx = statusMap[status] ?? 0;
    if (stripItems[targetIdx]) {
        stripItems[targetIdx].classList.add('active');
    }

    loadDeployments();
}

function setProductFilter(productId) {
    const sel = document.getElementById('filter-product');
    if (sel) {
        sel.value = productId;
    }

    // Highlight active chip
    const chips = document.querySelectorAll('#product-chips .chip');
    chips.forEach(c => {
        if (c.getAttribute('data-product-id') === String(productId) || (!productId && !c.getAttribute('data-product-id'))) {
            c.classList.add('active');
        } else {
            c.classList.remove('active');
        }
    });

    loadDeployments();
}

// ====================================================================
// Overview & KPIs
// ====================================================================
async function loadOverview() {
    try {
        const res = await fetch('api/v1/fleet.php?action=overview');
        const json = await res.json();
        if (json.status !== 'success') return;

        const d = json.data;
        const totalNodes = d.total_deployments || 0;
        document.getElementById('kpi-total-val').textContent = totalNodes;
        document.getElementById('kpi-healthy-val').textContent = d.healthy_count || 0;
        document.getElementById('kpi-warning-val').textContent = d.warning_count || 0;
        document.getElementById('kpi-critical-val').textContent = d.critical_count || 0;
        document.getElementById('kpi-unreachable-sub').textContent = d.unreachable_count || 0;
        document.getElementById('kpi-outdated-val').textContent = d.outdated_count || 0;

        const fleetCountEl = document.getElementById('sidebar-fleet-count');
        if (fleetCountEl) {
            fleetCountEl.textContent = totalNodes;
            if (d.critical_count > 0) {
                fleetCountEl.classList.add('crit');
            } else {
                fleetCountEl.classList.remove('crit');
            }
        }

        const errBadge = document.getElementById('sidebar-error-badge');
        if (errBadge) {
            const errCount = d.recent_errors_24h || 0;
            errBadge.textContent = errCount;
            if (errCount > 0) {
                errBadge.style.display = 'inline-block';
            } else {
                errBadge.style.display = 'none';
            }
        }

        populateProductDropdowns(d.products || []);
    } catch (err) {
        console.error('Failed to load fleet overview:', err);
    }
}

function populateProductDropdowns(products) {
    const filterSelect = document.getElementById('filter-product');
    const regSelect = document.getElementById('reg-product');
    const pairSelect = document.getElementById('pair-product');
    const chipRow = document.getElementById('product-chips');

    if (!filterSelect || !regSelect) return;

    const currentFilterVal = filterSelect.value;
    filterSelect.innerHTML = '<option value="">All Products</option>';
    regSelect.innerHTML = '<option value="">Select a Product...</option>';
    if (pairSelect) {
        pairSelect.innerHTML = '<option value="">Select a Product...</option>';
    }

    if (chipRow) {
        chipRow.innerHTML = `<div class="chip ${!currentFilterVal ? 'active' : ''}" onclick="setProductFilter('')">All products</div>`;
    }

    products.forEach(p => {
        const optFilter = document.createElement('option');
        optFilter.value = p.id;
        optFilter.textContent = `${p.product_name} (${p.deployment_count})`;
        filterSelect.appendChild(optFilter);

        const optReg = document.createElement('option');
        optReg.value = p.id;
        optReg.textContent = `${p.product_name} (v${p.latest_version})`;
        regSelect.appendChild(optReg);

        if (pairSelect) {
            const optPair = document.createElement('option');
            optPair.value = p.id;
            optPair.textContent = `${p.product_name} (v${p.latest_version})`;
            pairSelect.appendChild(optPair);
        }

        if (chipRow) {
            const chip = document.createElement('div');
            chip.className = `chip ${String(currentFilterVal) === String(p.id) ? 'active' : ''}`;
            chip.setAttribute('data-product-id', p.id);
            chip.textContent = p.product_name;
            chip.onclick = () => setProductFilter(p.id);
            chipRow.appendChild(chip);
        }
    });

    filterSelect.value = currentFilterVal;
}

// ====================================================================
// Deployments Fleet Table
// ====================================================================
async function loadDeployments() {
    const tbody = document.getElementById('deployments-tbody');
    const countLabel = document.getElementById('result-count-label');
    const productId = document.getElementById('filter-product').value;
    const status = document.getElementById('filter-status').value;
    const search = document.getElementById('search-input').value;

    let url = 'api/v1/fleet.php?action=deployments';
    if (productId) url += `&product_id=${encodeURIComponent(productId)}`;
    if (status && status !== 'all') url += `&status=${encodeURIComponent(status)}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;

    try {
        const res = await fetch(url);
        const json = await res.json();

        if (json.status !== 'success' || !Array.isArray(json.data) || json.data.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="10" style="text-align: center; color: var(--text-faint); padding: 40px 16px;">
                        No client deployments matching current filters.
                    </td>
                </tr>
            `;
            if (countLabel) countLabel.textContent = '0 deployments';
            return;
        }

        if (countLabel) countLabel.textContent = `${json.data.length} deployments`;

        tbody.innerHTML = '';
        json.data.forEach(dep => {
            const tr = document.createElement('tr');
            const statusVal = dep.computed_status || dep.status || 'healthy';
            const isCrit = (statusVal === 'critical');
            if (isCrit) {
                tr.classList.add('flash-crit');
            }

            // Latency formatting
            let latClass = '';
            let latText = '—';
            if (dep.db_latency_ms !== null && dep.db_latency_ms !== undefined) {
                const latVal = parseFloat(dep.db_latency_ms);
                latText = Math.round(latVal) + ' ms';
                if (latVal > 1500) latClass = 'crit';
                else if (latVal > 500) latClass = 'hot';
            }

            // Disk Free formatting
            let diskClass = '';
            let diskText = '—';
            if (dep.storage_free_gb !== null && dep.storage_free_gb !== undefined) {
                const diskVal = parseFloat(dep.storage_free_gb);
                diskText = diskVal.toFixed(1) + ' GB';
                if (diskVal < 1.0) diskClass = 'crit';
                else if (diskVal < 3.0) diskClass = 'hot';
            }

            // Users formatting
            const usersVal = dep.active_users_24h !== null && dep.active_users_24h !== undefined ? parseInt(dep.active_users_24h, 10) : null;
            const usersText = usersVal !== null ? usersVal.toLocaleString() : '—';

            // Error count badge
            const errCount = parseInt(dep.error_count || 0, 10);
            const errBadgeClass = errCount === 0 ? 'err-badge zero' : 'err-badge';

            // Heartbeat calculation
            let hbText = 'Never';
            let hbClass = 'hb-dead';
            if (dep.last_heartbeat_at) {
                const diffSec = Math.floor((new Date() - new Date(dep.last_heartbeat_at)) / 1000);
                if (diffSec < 60) {
                    hbText = `${Math.max(1, diffSec)}s ago`;
                    hbClass = 'hb-fresh';
                } else if (diffSec < 3600) {
                    hbText = `${Math.floor(diffSec / 60)}m ago`;
                    hbClass = 'hb-fresh';
                } else if (diffSec < 86400) {
                    hbText = `${Math.floor(diffSec / 3600)}h ago`;
                    hbClass = 'hb-stale';
                } else {
                    hbText = `${Math.floor(diffSec / 86400)}d ago`;
                    hbClass = 'hb-dead';
                }
            }

            // Version tag
            const isOutdated = dep.is_outdated;
            const versionHtml = `
                <span class="version-tag ${isOutdated ? 'outdated' : ''}" title="Target: v${escapeHtml(dep.target_version || dep.current_version)}">
                    v${escapeHtml(dep.current_version)}
                </span>
            `;

            const statusClass = statusVal === 'unreachable' ? 'dead' : statusVal;

            tr.innerHTML = `
                <td>
                    <div class="client-cell">
                        <span class="client-name">${escapeHtml(dep.client_name)}</span>
                        <span class="client-domain">${escapeHtml(dep.deployment_domain)}</span>
                    </div>
                </td>
                <td><span class="product-badge">${escapeHtml(dep.product_name)}</span></td>
                <td>
                    <span class="status-pill ${statusClass}">
                        <span class="dot dot-${statusClass}"></span>${statusVal}
                    </span>
                </td>
                <td>${versionHtml}</td>
                <td class="num"><span class="metric-num ${latClass}">${latText}</span></td>
                <td class="num"><span class="metric-num ${diskClass}">${diskText}</span></td>
                <td class="num"><span class="metric-num">${usersText}</span></td>
                <td>
                    <span class="${errBadgeClass}">${errCount}</span>
                </td>
                <td><span class="hb-cell ${hbClass}">${hbText}</span></td>
                <td>
                    <div class="row-actions">
                        <a href="https://${escapeHtml(dep.deployment_domain)}" target="_blank" class="icon-btn" title="Open client site">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6M15 3h6v6M10 14 21 3"/></svg>
                        </a>
                        <button class="icon-btn danger" onclick="removeDeployment(${dep.id}, '${escapeHtml(dep.client_name)}', '${escapeHtml(dep.deployment_domain)}')" title="Remove deployed system">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/></svg>
                        </button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="10" style="text-align:center; padding:40px; color:var(--crit-text);">Failed to load fleet data.</td></tr>`;
        console.error(err);
    }
}

function debounceSearch() {
    clearTimeout(searchDebounceTimeout);
    searchDebounceTimeout = setTimeout(() => {
        loadDeployments();
    }, 250);
}

// ====================================================================
// Error Incidents Table
// ====================================================================
async function loadErrors() {
    const tbody = document.getElementById('errors-tbody');
    const countLabel = document.getElementById('result-count-label');

    try {
        const res = await fetch('api/v1/fleet.php?action=errors&limit=50');
        const json = await res.json();

        if (json.status !== 'success' || !Array.isArray(json.data) || json.data.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" style="text-align: center; color: var(--ok-text); padding: 40px 16px;">
                        Zero active error incidents reported across the fleet.
                    </td>
                </tr>
            `;
            cachedErrors = [];
            if (countLabel) countLabel.textContent = '0 incidents';
            return;
        }

        cachedErrors = json.data;
        if (countLabel) countLabel.textContent = `${json.data.length} incidents`;
        tbody.innerHTML = '';

        json.data.forEach((err, idx) => {
            const tr = document.createElement('tr');
            const loc = (err.file_path && err.line_number) ? `${basename(err.file_path)}:${err.line_number}` : (err.request_uri || 'Unknown');

            tr.innerHTML = `
                <td style="white-space:nowrap; font-family:'JetBrains Mono',monospace; font-size:11px; color:var(--text-dim);">
                    ${escapeHtml(err.last_seen_at || 'Just now')}
                </td>
                <td>
                    <span class="status-pill crit"><span class="dot dot-crit"></span>${escapeHtml(err.error_level || 'ERROR')}</span>
                </td>
                <td>
                    <div class="client-cell">
                        <span class="client-name">${escapeHtml(err.client_name)}</span>
                        <span class="client-domain">${escapeHtml(err.deployment_domain)}</span>
                    </div>
                </td>
                <td>
                    <div style="font-weight:600; color:var(--crit-text);">${escapeHtml(err.error_message)}</div>
                    <div style="font-size:11px; color:var(--text-faint); font-family:'JetBrains Mono',monospace; margin-top:2px;">
                        ${escapeHtml(loc)}
                    </div>
                </td>
                <td>
                    <span class="err-badge">${err.occurrences || 1}</span>
                </td>
                <td>
                    <button class="btn" style="padding:4px 8px; font-size:11px;" onclick="openErrorModal(${idx})">View Trace</button>
                </td>
            `;
            tbody.appendChild(tr);
        });

    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; padding:40px; color:var(--crit-text);">Failed to load error incidents.</td></tr>`;
        console.error(err);
    }
}

function openErrorModal(index) {
    const err = cachedErrors[index];
    if (!err) return;

    document.getElementById('err-modal-level').textContent = err.error_level || 'ERROR';
    document.getElementById('err-modal-title').textContent = err.error_message;
    document.getElementById('err-modal-deploy').textContent = `${err.client_name} (${err.deployment_domain})`;
    document.getElementById('err-modal-location').textContent = `${err.file_path || 'N/A'}:${err.line_number || '0'}`;
    document.getElementById('err-modal-occurrences').textContent = `${err.occurrences} events`;
    document.getElementById('err-modal-time').textContent = err.last_seen_at || '-';
    document.getElementById('err-modal-trace').textContent = err.stack_trace || 'No stack trace recorded for this event.';

    document.getElementById('error-modal').classList.add('active');
}

function closeErrorModal() {
    document.getElementById('error-modal').classList.remove('active');
}

// ====================================================================
// Deployment Registration Modal
// ====================================================================
function openRegisterModal() {
    document.getElementById('register-form').classList.remove('hidden');
    document.getElementById('token-reveal-panel').classList.add('hidden');
    document.getElementById('register-form').reset();
    document.getElementById('register-modal').classList.add('active');
}

function closeRegisterModal(shouldRefresh = false) {
    document.getElementById('register-modal').classList.remove('active');
    if (shouldRefresh) {
        loadOverview();
        loadDeployments();
    }
}

async function handleRegisterSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('submit-register-btn');
    btn.disabled = true;
    btn.textContent = 'Generating Token...';

    const payload = {
        product_id: parseInt(document.getElementById('reg-product').value, 10),
        client_name: document.getElementById('reg-client').value,
        deployment_domain: document.getElementById('reg-domain').value,
        environment: document.getElementById('reg-env').value,
        current_version: document.getElementById('reg-version').value,
    };

    try {
        const res = await fetch('api/v1/fleet.php?action=deployments', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        if (json.status !== 'success') {
            alert('Registration failed: ' + (json.message || 'Unknown error'));
            btn.disabled = false;
            btn.textContent = 'Register Node';
            return;
        }

        // Show token reveal panel
        document.getElementById('register-form').classList.add('hidden');
        document.getElementById('token-reveal-panel').classList.remove('hidden');
        document.getElementById('revealed-token').value = json.data.token;
        showToast('Deployment registered!');
    } catch (err) {
        alert('Registration request failed');
        console.error(err);
    } finally {
        btn.disabled = false;
        btn.textContent = 'Register Node';
    }
}

function copyToken() {
    const input = document.getElementById('revealed-token');
    input.select();
    navigator.clipboard.writeText(input.value);
    showToast('Ingest token copied to clipboard!');
}

// ====================================================================
// Deployment Removal Handler (Modal-based Confirmation)
// ====================================================================
let pendingDeleteDeploymentId = null;

function removeDeployment(id, clientName, domain) {
    pendingDeleteDeploymentId = id;
    const clientEl = document.getElementById('delete-modal-client');
    const domainEl = document.getElementById('delete-modal-domain');
    if (clientEl) clientEl.textContent = clientName || '—';
    if (domainEl) domainEl.textContent = domain || '—';

    const modal = document.getElementById('delete-deployment-modal');
    if (modal) modal.classList.add('active');
}

function closeDeleteModal() {
    pendingDeleteDeploymentId = null;
    const modal = document.getElementById('delete-deployment-modal');
    if (modal) modal.classList.remove('active');
}

async function confirmDeleteDeployment() {
    if (!pendingDeleteDeploymentId) return;

    const btn = document.getElementById('confirm-delete-btn');
    const origHtml = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-inline"></span> Deleting...';
    }

    try {
        const res = await fetch('api/v1/fleet.php?action=deployments', {
            method: 'DELETE',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ deployment_id: pendingDeleteDeploymentId })
        });
        const json = await res.json();
        closeDeleteModal();

        if (json.status === 'success') {
            showToast(json.message || 'Deployed system removed successfully.');
            loadDeployments();
            loadOverview();
        } else {
            showToast(json.message || json.error || 'Failed to remove deployment.', 'error');
        }
    } catch (err) {
        closeDeleteModal();
        showToast('Network error attempting to remove deployment.', 'error');
        console.error(err);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    }
}

// ====================================================================
// Deployment Pairing Modal (Existing Client)
// ====================================================================
function openPairModal() {
    const form = document.getElementById('pair-form');
    if (form) form.reset();
    const modal = document.getElementById('pair-modal');
    if (modal) modal.classList.add('active');
}

function closePairModal(shouldRefresh = false) {
    const modal = document.getElementById('pair-modal');
    if (modal) modal.classList.remove('active');
    if (shouldRefresh) {
        loadOverview();
        loadDeployments();
    }
}

async function handlePairSubmit(e) {
    e.preventDefault();
    const btn = document.getElementById('submit-pair-btn');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Pairing...';
    }

    const payload = {
        product_id: parseInt(document.getElementById('pair-product').value, 10),
        client_name: document.getElementById('pair-client').value,
        deployment_domain: document.getElementById('pair-domain').value,
        api_key: document.getElementById('pair-api-key').value,
        environment: document.getElementById('pair-env').value,
        current_version: document.getElementById('pair-version').value,
    };

    try {
        const res = await fetch('api/v1/fleet.php?action=pair', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const json = await res.json();
        if (json.status !== 'success') {
            alert('Pairing failed: ' + (json.message || 'Unknown error'));
            return;
        }

        closePairModal(true);
        showToast('Client deployment paired successfully!');
    } catch (err) {
        alert('Pairing request failed');
        console.error(err);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Pair System';
        }
    }
}

// ====================================================================
// Health Check Sweep
// ====================================================================
async function triggerHealthSweep() {
    const btn = document.getElementById('trigger-health-btn');
    btn.disabled = true;
    btn.innerHTML = 'Sweeping...';

    try {
        const res = await fetch('api/v1/fleet.php?action=check_health', { method: 'POST' });
        const json = await res.json();
        if (json.status === 'success') {
            showToast(`Sweep complete: ${json.data.unreachable_marked} marked unreachable, ${json.data.warning_marked} warnings`);
            loadOverview();
            loadDeployments();
        }
    } catch (err) {
        showToast('Health sweep failed');
        console.error(err);
    } finally {
        btn.disabled = false;
        btn.innerHTML = `
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
            Sweep stale nodes
        `;
    }
}

function showToast(message, type = 'info') {
    if (window.SpectraPopup) {
        window.SpectraPopup.show({
            title: 'System Notification',
            message: message,
            type: type,
            confirmText: 'Dismiss'
        });
    } else {
        const toast = document.getElementById('toast');
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('show');
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function basename(path) {
    return path ? path.split(/[\\/]/).pop() : '';
}

function applyTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    try { localStorage.setItem('spectraops_theme', theme); } catch(e) {}
    document.querySelectorAll('.theme-toggle-label').forEach(el => {
        el.textContent = theme === 'light' ? 'Light' : 'Dark';
    });
    document.querySelectorAll('.theme-toggle-btn').forEach(btn => {
        btn.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
        btn.setAttribute('title', 'Switch to ' + (theme === 'light' ? 'Dark' : 'Light') + ' mode');
    });
}

function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') || 'dark';
    applyTheme(current === 'light' ? 'dark' : 'light');
}

