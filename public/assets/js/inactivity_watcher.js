/**
 * Client-Side Inactivity Auto-Logout Watcher
 * 
 * Enforces 5-minute (300s) session inactivity timeout:
 * - Monitors user interactions (mousemove, mousedown, keydown, touch, scroll)
 * - Displays a 30-second warning alert modal at 4m 30s of inactivity
 * - Redirects to login.php?reason=timeout at 5 minutes
 * - Detects background tab wake-up and sleep states via visibilitychange
 */

(function() {
    'use strict';

    if (window.__inactivityWatcherInitialized) {
        return;
    }
    window.__inactivityWatcherInitialized = true;

    // Only run if user is logged in
    const metaAuth = document.querySelector('meta[name="user-authenticated"]');
    // If no meta tag, check if universal navbar or sidebar exists (indicating an authenticated portal)
    const isAuthenticated = (metaAuth && metaAuth.content === 'true') || 
                            document.querySelector('.admin-sidebar, .app-sidebar, #appMainSidebar, .nav-user-dropdown, .prism-app-topbar, a[href*="logout.php"]') !== null;

    if (!isAuthenticated) {
        return;
    }

    const TIMEOUT_SECONDS = 300; // 5 minutes
    const WARNING_SECONDS = 270; // 4 minutes 30 seconds (30s warning)
    const isRtl = document.documentElement.getAttribute('dir') === 'rtl';

    let lastActivity = Date.now();
    let warningModal = null;
    let warningCountdownInterval = null;
    let mainTimerInterval = null;
    let isThrottle = false;

    // Detect base URL from current location
    function getLoginUrl() {
        const path = window.location.pathname;
        const idx = path.indexOf('/school_system');
        const prefix = idx !== -1 ? '/school_system' : '';
        return prefix + '/login.php?reason=timeout';
    }

    function recordActivity() {
        if (isThrottle) return;
        isThrottle = true;
        setTimeout(() => { isThrottle = false; }, 2000);

        lastActivity = Date.now();

        // If warning is open, dismiss it when user interacts
        if (warningModal && warningModal.classList.contains('show')) {
            dismissWarning();
        }
    }

    function createWarningModal() {
        if (document.getElementById('inactivityWarningModal')) {
            return document.getElementById('inactivityWarningModal');
        }

        const modalDiv = document.createElement('div');
        modalDiv.id = 'inactivityWarningModal';
        modalDiv.className = 'modal fade';
        modalDiv.setAttribute('tabindex', '-1');
        modalDiv.setAttribute('data-bs-backdrop', 'static');
        modalDiv.setAttribute('data-bs-keyboard', 'false');
        modalDiv.style.zIndex = '10050';

        const titleText = isRtl ? 'تنبيه عدم النشاط في الجلسة' : 'Session Inactivity Warning';
        const msgText = isRtl 
            ? 'سيتم تسجيل خروجك تلقائياً خلال <strong id="inactivitySecsCounter" class="text-danger fs-5">30</strong> ثانية لعدم وجود نشاط. انقر للاستمرار في العمل.' 
            : 'You will be logged out in <strong id="inactivitySecsCounter" class="text-danger fs-5">30</strong> seconds due to inactivity. Click below to stay signed in.';
        const btnText = isRtl ? 'البقاء في الجلسة ومتابعة العمل' : 'Stay Signed In';

        modalDiv.innerHTML = `
            <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
                <div class="modal-content border border-warning shadow-lg rounded-xl">
                    <div class="modal-body text-center p-4">
                        <div class="d-inline-flex align-items-center justify-content-center bg-warning bg-opacity-10 text-warning rounded-circle mb-3" style="width: 64px; height: 64px;">
                            <i class="fa-solid fa-hourglass-half fs-2"></i>
                        </div>
                        <h5 class="fw-bold mb-2">${titleText}</h5>
                        <p class="text-muted-foreground small mb-4">${msgText}</p>
                        <button type="button" id="staySignedInBtn" class="btn btn-primary w-100 py-2.5 rounded-lg fw-bold shadow-sm">
                            <i class="fa-solid fa-circle-check me-2"></i>${btnText}
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modalDiv);

        document.getElementById('staySignedInBtn').addEventListener('click', function(e) {
            e.preventDefault();
            dismissWarning();
            // Send ping to server to refresh PHP session
            fetch(window.location.href, { method: 'HEAD', cache: 'no-store' }).catch(() => {});
        });

        return modalDiv;
    }

    function showWarning(remainingSecs) {
        const modalEl = createWarningModal();
        warningModal = modalEl;

        const counterEl = document.getElementById('inactivitySecsCounter');
        if (counterEl) counterEl.textContent = Math.max(1, Math.round(remainingSecs));

        if (window.bootstrap && window.bootstrap.Modal) {
            const bsModal = window.bootstrap.Modal.getOrCreateInstance(modalEl);
            bsModal.show();
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
        }

        if (!warningCountdownInterval) {
            warningCountdownInterval = setInterval(() => {
                const elapsed = (Date.now() - lastActivity) / 1000;
                const left = Math.max(0, Math.round(TIMEOUT_SECONDS - elapsed));
                const c = document.getElementById('inactivitySecsCounter');
                if (c) c.textContent = left;

                if (left <= 0) {
                    clearInterval(warningCountdownInterval);
                    logout();
                }
            }, 1000);
        }
    }

    function dismissWarning() {
        if (warningCountdownInterval) {
            clearInterval(warningCountdownInterval);
            warningCountdownInterval = null;
        }

        if (warningModal) {
            if (window.bootstrap && window.bootstrap.Modal) {
                const bsModal = window.bootstrap.Modal.getInstance(warningModal);
                if (bsModal) bsModal.hide();
            } else {
                warningModal.classList.remove('show');
                warningModal.style.display = 'none';
            }
        }
    }

    function logout() {
        window.location.href = getLoginUrl();
    }

    function checkTimer() {
        const elapsed = (Date.now() - lastActivity) / 1000;

        if (elapsed >= TIMEOUT_SECONDS) {
            logout();
        } else if (elapsed >= WARNING_SECONDS) {
            showWarning(TIMEOUT_SECONDS - elapsed);
        }
    }

    // Attach interaction listeners
    ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(evt => {
        window.addEventListener(evt, recordActivity, { passive: true });
    });

    // Check on visibility change (handles background tabs and computer sleep)
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'visible') {
            checkTimer();
        }
    });

    window.addEventListener('focus', checkTimer);

    // Periodic check every 5 seconds
    mainTimerInterval = setInterval(checkTimer, 5000);

})();
