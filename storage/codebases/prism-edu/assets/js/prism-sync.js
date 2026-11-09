/**
 * PRISM EDU - Client-Side Hybrid Delta-Sync Engine
 * Provides lightweight timestamped delta synchronization, offline queueing,
 * network recovery auto-push, and visibility-aware conditional polling.
 */
class PrismDataSync {
    constructor() {
        this.syncIntervalMs = 30000; // 30-second default sync
        this.lastSyncedAt = localStorage.getItem('prism_last_sync') || '1970-01-01 00:00:00';
        this.syncTimer = null;
        this.isSyncing = false;
        
        this.init();
    }

    getBaseUrl() {
        if (typeof window.BASE_URL !== 'undefined') {
            return window.BASE_URL;
        }
        const path = window.location.pathname;
        if (path.indexOf('/school_system') !== -1) {
            return '/school_system';
        }
        return '';
    }

    getCsrfToken() {
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) return meta.content;
        if (typeof window.CSRF_TOKEN === 'string' && window.CSRF_TOKEN) return window.CSRF_TOKEN;
        const input = document.querySelector('input[name="csrf_token"]');
        if (input && input.value) return input.value;
        return '';
    }

    init() {
        // Start automatic polling loop
        this.startAutoSync();

        // Immediate sync when device reconnects to the network
        window.addEventListener('online', () => {
            console.log('[Sync] Network recovered. Pushing pending mutations.');
            this.syncNow();
        });

        // Pause sync if tab/app is minimized to conserve server CPU & client battery
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.stopAutoSync();
            } else {
                this.syncNow();
                this.startAutoSync();
            }
        });
    }

    startAutoSync() {
        if (!this.syncTimer) {
            this.syncTimer = setInterval(() => this.syncNow(), this.syncIntervalMs);
        }
    }

    stopAutoSync() {
        if (this.syncTimer) {
            clearInterval(this.syncTimer);
            this.syncTimer = null;
        }
    }

    /**
     * Queues a local mutation for offline/delayed synchronization
     * @param {string} entity Entity name (e.g. 'attendance')
     * @param {Object} payload Entity data
     */
    queueMutation(entity, payload) {
        try {
            const queue = JSON.parse(localStorage.getItem('prism_offline_queue') || '[]');
            queue.push({
                id: 'mut_' + Date.now() + '_' + Math.random().toString(36).substr(2, 6),
                entity: entity,
                payload: payload,
                queued_at: new Date().toISOString()
            });
            localStorage.setItem('prism_offline_queue', JSON.stringify(queue));
            if (navigator.onLine) {
                this.syncNow();
            }
        } catch (e) {
            console.error('[Sync] Failed to queue offline mutation:', e);
        }
    }

    async syncNow() {
        if (this.isSyncing || (typeof navigator.onLine !== 'undefined' && !navigator.onLine)) return;
        this.isSyncing = true;

        try {
            // 1. Gather any offline changes queued in localStorage / IndexedDB
            const pendingMutations = JSON.parse(localStorage.getItem('prism_offline_queue') || '[]');

            const headers = {
                'Content-Type': 'application/json'
            };
            const csrfToken = this.getCsrfToken();
            if (csrfToken) {
                headers['X-CSRF-TOKEN'] = csrfToken;
            }

            const syncEndpoint = this.getBaseUrl() + '/api/v1/sync.php';

            const response = await fetch(syncEndpoint, {
                method: 'POST',
                headers: headers,
                body: JSON.stringify({
                    last_synced_at: this.lastSyncedAt,
                    mutations: pendingMutations,
                    csrf_token: csrfToken
                })
            });

            if (!response.ok) throw new Error('Sync failed with HTTP status ' + response.status);

            const result = await response.json();

            if (result.status === 'success') {
                // 2. Clear sent mutations
                localStorage.setItem('prism_offline_queue', JSON.stringify([]));

                // 3. Process new updates from server
                if (result.updates && result.updates.length > 0) {
                    this.applyDeltaUpdates(result.updates);
                }

                // 4. Update sync watermark
                this.lastSyncedAt = result.server_time;
                localStorage.setItem('prism_last_sync', this.lastSyncedAt);
            }
        } catch (error) {
            console.warn('[Sync] Sync postponed:', error.message);
        } finally {
            this.isSyncing = false;
        }
    }

    applyDeltaUpdates(updates) {
        // Dispatches event so UI components (tables, badges) re-render locally
        window.dispatchEvent(new CustomEvent('prism:data-updated', { detail: updates }));
    }
}

// Instantiate globally
window.PrismSyncEngine = new PrismDataSync();
