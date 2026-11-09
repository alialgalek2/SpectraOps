/**
 * SpectraOps — System Message Floating Window & Notification Engine
 * 
 * Provides responsive, animated, theme-aware floating message windows.
 * Strictly enforces that all system notifications, messages, alerts, and feedback
 * are displayed in a floating modal window that requires user input first to dismiss.
 */
(function(window, document) {
    'use strict';

    if (window.SpectraPopup && window.SpectraPopup._initialized) {
        return;
    }

    // Dynamic style injection respecting SpectraOps design tokens
    function injectStyles() {
        if (document.getElementById('spectra-popup-styles')) return;

        const css = `
            /* Backdrop Overlay */
            .spectra-popup-overlay {
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                background: rgba(6, 12, 20, 0.82);
                -webkit-backdrop-filter: blur(8px);
                backdrop-filter: blur(8px);
                z-index: 100000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 16px;
                opacity: 0;
                transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            }
            .spectra-popup-overlay.spectra-popup-active {
                opacity: 1;
            }

            /* Floating Window Card */
            .spectra-popup-card {
                position: relative;
                width: 100%;
                max-width: 500px;
                background: var(--panel-2, #141A24);
                color: var(--text, #E8EDF2);
                border: 1px solid var(--line, #1F2733);
                border-radius: var(--radius-lg, 12px);
                box-shadow: 0 25px 65px -10px rgba(0, 0, 0, 0.75), 0 0 0 1px rgba(79, 168, 224, 0.18);
                overflow: hidden;
                transform: scale(0.92) translateY(14px);
                transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.22s ease;
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            }
            .spectra-popup-overlay.spectra-popup-active .spectra-popup-card {
                transform: scale(1) translateY(0);
            }

            /* Subtle Shake animation when clicking backdrop without user action */
            @keyframes spectraShake {
                0%, 100% { transform: translateX(0) scale(1); }
                15%, 45%, 75% { transform: translateX(-6px) scale(1.006); }
                30%, 60%, 90% { transform: translateX(6px) scale(1.006); }
            }
            .spectra-popup-card.spectra-popup-shake {
                animation: spectraShake 0.34s cubic-bezier(0.36, 0.07, 0.19, 0.97) both;
            }

            /* Top Severity Accent Strip */
            .spectra-popup-accent-bar {
                height: 4px;
                width: 100%;
            }
            .spectra-popup-card.type-success .spectra-popup-accent-bar { background: linear-gradient(90deg, #10B981, #3DD68C); }
            .spectra-popup-card.type-error   .spectra-popup-accent-bar { background: linear-gradient(90deg, #E5484D, #EF4444); }
            .spectra-popup-card.type-warning .spectra-popup-accent-bar { background: linear-gradient(90deg, #F0B93D, #F59E0B); }
            .spectra-popup-card.type-info    .spectra-popup-accent-bar { background: linear-gradient(90deg, #4FA8E0, #0EA5C4); }

            /* Body Container */
            .spectra-popup-body {
                padding: 24px 24px 20px 24px;
            }

            .spectra-popup-header-row {
                display: flex;
                align-items: flex-start;
                gap: 16px;
                margin-bottom: 8px;
            }

            /* Icon Avatar */
            .spectra-popup-icon-wrap {
                flex-shrink: 0;
                width: 46px;
                height: 46px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 1.35rem;
            }
            .spectra-popup-card.type-success .spectra-popup-icon-wrap {
                background: rgba(16, 185, 129, 0.15);
                color: #3DD68C;
                border: 1px solid rgba(16, 185, 129, 0.35);
            }
            .spectra-popup-card.type-error .spectra-popup-icon-wrap {
                background: rgba(229, 72, 77, 0.15);
                color: #E5484D;
                border: 1px solid rgba(229, 72, 77, 0.35);
            }
            .spectra-popup-card.type-warning .spectra-popup-icon-wrap {
                background: rgba(240, 185, 61, 0.15);
                color: #F0B93D;
                border: 1px solid rgba(240, 185, 61, 0.35);
            }
            .spectra-popup-card.type-info .spectra-popup-icon-wrap {
                background: rgba(79, 168, 224, 0.15);
                color: #4FA8E0;
                border: 1px solid rgba(79, 168, 224, 0.35);
            }

            .spectra-popup-text-wrap {
                flex: 1;
                min-width: 0;
            }

            .spectra-popup-title {
                margin: 0 0 6px 0;
                font-size: 1.12rem;
                font-weight: 700;
                color: var(--text, #E8EDF2);
                line-height: 1.35;
                letter-spacing: -0.01em;
            }

            .spectra-popup-message {
                margin: 0;
                font-size: 0.92rem;
                color: var(--text-dim, #89A0B8);
                line-height: 1.6;
                word-break: break-word;
            }
            .spectra-popup-message code {
                font-family: 'JetBrains Mono', monospace;
                background: rgba(79, 168, 224, 0.12);
                color: var(--accent, #4FA8E0);
                border: 1px solid rgba(79, 168, 224, 0.28);
                border-radius: 4px;
                padding: 2px 6px;
                font-size: 0.85em;
                word-break: break-all;
            }
            .spectra-popup-message strong, .spectra-popup-message b {
                color: var(--text, #E8EDF2);
                font-weight: 600;
            }
            .spectra-popup-message pre {
                background: var(--ink, #0A0E14);
                border: 1px solid var(--line, #1F2733);
                border-radius: 6px;
                padding: 10px 12px;
                font-family: 'JetBrains Mono', monospace;
                font-size: 0.82rem;
                overflow-x: auto;
                color: var(--text, #E8EDF2);
            }

            /* Action Footer */
            .spectra-popup-footer {
                padding: 14px 24px 18px 24px;
                background: var(--panel, #10141C);
                border-top: 1px solid var(--line, #1F2733);
                display: flex;
                align-items: center;
                justify-content: flex-end;
                gap: 10px;
            }

            .spectra-popup-btn {
                padding: 9px 22px;
                border-radius: var(--radius, 6px);
                font-size: 0.88rem;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.18s ease;
                outline: none;
                border: 1px solid transparent;
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-family: inherit;
            }
            .spectra-popup-btn-confirm {
                background: var(--accent, #4FA8E0);
                color: #0A0E14;
                box-shadow: 0 2px 6px rgba(79, 168, 224, 0.25);
            }
            .spectra-popup-btn-confirm:hover {
                background: var(--accent-hover, #63B5E5);
                transform: translateY(-1px);
            }
            .spectra-popup-btn-confirm:focus {
                box-shadow: 0 0 0 3px rgba(79, 168, 224, 0.45);
            }
            .spectra-popup-btn-confirm:active {
                transform: translateY(0);
            }

            .spectra-popup-card.type-error .spectra-popup-btn-confirm {
                background: #E5484D;
                color: #FFFFFF;
                box-shadow: 0 2px 6px rgba(229, 72, 77, 0.3);
            }
            .spectra-popup-card.type-error .spectra-popup-btn-confirm:hover {
                background: #DC2626;
            }
            .spectra-popup-card.type-error .spectra-popup-btn-confirm:focus {
                box-shadow: 0 0 0 3px rgba(229, 72, 77, 0.45);
            }

            .spectra-popup-card.type-success .spectra-popup-btn-confirm {
                background: #10B981;
                color: #FFFFFF;
                box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);
            }
            .spectra-popup-card.type-success .spectra-popup-btn-confirm:hover {
                background: #059669;
            }
            .spectra-popup-card.type-success .spectra-popup-btn-confirm:focus {
                box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.45);
            }

            .spectra-popup-btn-cancel {
                background: transparent;
                color: var(--text-dim, #89A0B8);
                border-color: var(--line, #1F2733);
            }
            .spectra-popup-btn-cancel:hover {
                background: rgba(255, 255, 255, 0.05);
                color: var(--text, #E8EDF2);
                border-color: var(--text-dim, #89A0B8);
            }
        `;

        const styleEl = document.createElement('style');
        styleEl.id = 'spectra-popup-styles';
        styleEl.textContent = css;
        document.head.appendChild(styleEl);
    }

    // Determine type from message text if unspecified
    function detectType(text) {
        if (!text) return 'info';
        const str = String(text).toLowerCase();
        if (str.includes('error') || str.includes('fail') || str.includes('exception') || str.includes('abort') || str.includes('rejected') || str.includes('invalid') || str.includes('blocked') || str.includes('could not') || str.includes('denied') || str.includes('violation')) {
            return 'error';
        }
        if (str.includes('success') || str.includes('upgraded') || str.includes('updated') || str.includes('complete') || str.includes('saved') || str.includes('paired') || str.includes('registered') || str.includes('copied') || str.includes('issued')) {
            return 'success';
        }
        if (str.includes('warn') || str.includes('caution') || str.includes('pending') || str.includes('attention') || str.includes('notice') || str.includes('suspend') || str.includes('revoke') || str.includes('lockout')) {
            return 'warning';
        }
        return 'info';
    }

    function getDefaultTitle(type) {
        switch (type) {
            case 'success': return 'System Success';
            case 'error':   return 'System Alert';
            case 'warning': return 'Attention Required';
            default:        return 'System Notification';
        }
    }

    function getIconSvg(type) {
        switch (type) {
            case 'success':
                return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            case 'error':
                return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
            case 'warning':
                return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
            default:
                return '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
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

    // Safely sanitize allowed inline formatting tags (strong, b, em, code, pre, span, br)
    function sanitizeSafeHtml(dirty) {
        if (!dirty) return '';
        try {
            const parser = new DOMParser();
            const doc = parser.parseFromString(dirty, 'text/html');
            const allowedTags = new Set(['STRONG', 'B', 'EM', 'I', 'U', 'CODE', 'PRE', 'SPAN', 'BR', 'P', 'DIV', 'SMALL', 'UL', 'OL', 'LI']);
            
            function cleanNode(node) {
                const children = Array.from(node.childNodes);
                for (const child of children) {
                    if (child.nodeType === Node.ELEMENT_NODE) {
                        const tag = child.tagName.toUpperCase();
                        if (!allowedTags.has(tag)) {
                            const textNode = document.createTextNode(child.textContent || '');
                            node.replaceChild(textNode, child);
                        } else {
                            // Strip dangerous attributes (only keep style and class if present, strip all on*, href, etc.)
                            const attrs = Array.from(child.attributes);
                            for (const attr of attrs) {
                                const attrName = attr.name.toLowerCase();
                                if (attrName !== 'style' && attrName !== 'class') {
                                    child.removeAttribute(attr.name);
                                } else if (attrName === 'style' && /expression|javascript|url/i.test(attr.value)) {
                                    child.removeAttribute(attr.name);
                                }
                            }
                            cleanNode(child);
                        }
                    }
                }
            }
            cleanNode(doc.body);
            return doc.body.innerHTML;
        } catch (e) {
            return escapeHtml(dirty);
        }
    }

    const SpectraPopup = {
        _initialized: true,

        /**
         * Show a floating system message window card.
         * Enforces that the window requires explicit user input first to dismiss.
         * 
         * @param {Object|string} options
         *   - title: string
         *   - message: string
         *   - type: 'success' | 'error' | 'warning' | 'info'
         *   - confirmText: string (default: "Dismiss")
         *   - cancelText: string (optional, for confirmations)
         *   - html: boolean (allow safe HTML tags like <strong>, <code>)
         *   - onConfirm: function
         *   - onCancel: function
         */
        show: function(options) {
            injectStyles();

            const opts = typeof options === 'string' ? { message: options } : (options || {});
            let msg = opts.message || opts.msg || '';
            if (typeof msg === 'object') {
                msg = msg.message || msg.error || JSON.stringify(msg);
            }
            const type = opts.type || detectType(msg);
            const title = opts.title || getDefaultTitle(type);
            const confirmText = opts.confirmText || (opts.cancelText ? 'Confirm' : 'Dismiss');
            const cancelText = opts.cancelText || null;
            const onConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
            const onCancel = typeof opts.onCancel === 'function' ? opts.onCancel : null;

            // Detect or respect HTML formatting in message
            const hasHtml = opts.html || opts.isHtml || (typeof msg === 'string' && /<\/?(strong|b|em|code|span|br|pre)/i.test(msg));
            const messageHtml = hasHtml ? sanitizeSafeHtml(msg) : escapeHtml(msg);

            // Remove existing active popups
            const existing = document.querySelector('.spectra-popup-overlay');
            if (existing) {
                existing.remove();
            }

            const overlay = document.createElement('div');
            overlay.className = 'spectra-popup-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');

            overlay.innerHTML = `
                <div class="spectra-popup-card type-${type}">
                    <div class="spectra-popup-accent-bar"></div>
                    <div class="spectra-popup-body">
                        <div class="spectra-popup-header-row">
                            <div class="spectra-popup-icon-wrap">
                                ${getIconSvg(type)}
                            </div>
                            <div class="spectra-popup-text-wrap">
                                <h4 class="spectra-popup-title">${escapeHtml(title)}</h4>
                                <div class="spectra-popup-message">${messageHtml}</div>
                            </div>
                        </div>
                    </div>
                    <div class="spectra-popup-footer">
                        ${cancelText ? `<button type="button" class="spectra-popup-btn spectra-popup-btn-cancel">${escapeHtml(cancelText)}</button>` : ''}
                        <button type="button" class="spectra-popup-btn spectra-popup-btn-confirm">${escapeHtml(confirmText)}</button>
                    </div>
                </div>
            `;

            document.body.appendChild(overlay);

            // Animate entry
            requestAnimationFrame(() => {
                overlay.classList.add('spectra-popup-active');
            });

            const confirmBtn = overlay.querySelector('.spectra-popup-btn-confirm');
            const cancelBtn = overlay.querySelector('.spectra-popup-btn-cancel');

            // Explicit user focus on primary dismiss button
            if (confirmBtn) {
                confirmBtn.focus();
            }

            let isClosed = false;
            function closePopup(confirmed) {
                if (isClosed) return;
                isClosed = true;

                document.removeEventListener('keydown', keyHandler);
                overlay.classList.remove('spectra-popup-active');
                setTimeout(() => {
                    overlay.remove();
                    if (confirmed) {
                        if (onConfirm) onConfirm();
                    } else {
                        if (onCancel) onCancel();
                    }
                }, 220);
            }

            if (confirmBtn) {
                confirmBtn.addEventListener('click', () => closePopup(true));
            }
            if (cancelBtn) {
                cancelBtn.addEventListener('click', () => closePopup(false));
            }

            // CRITICAL: Require user input first to dismiss!
            // Clicking on the backdrop overlay does NOT dismiss the window.
            // It triggers a tactile shake animation and refocuses the dismiss button.
            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    const card = overlay.querySelector('.spectra-popup-card');
                    if (card) {
                        card.classList.remove('spectra-popup-shake');
                        void card.offsetWidth; // Force CSS reflow
                        card.classList.add('spectra-popup-shake');
                    }
                    if (confirmBtn) {
                        confirmBtn.focus();
                    }
                }
            });

            // Keyboard user input handling: Enter or Escape dismisses
            const keyHandler = (e) => {
                if (e.key === 'Escape') {
                    closePopup(false);
                } else if (e.key === 'Enter') {
                    closePopup(true);
                }
            };
            document.addEventListener('keydown', keyHandler);

            return {
                close: () => closePopup(false)
            };
        },

        /**
         * Drop-in replacement for window.alert()
         * Always renders in floating window requiring user input to dismiss.
         */
        alert: function(message, title) {
            const detected = detectType(message);
            return this.show({
                title: title || getDefaultTitle(detected),
                message: message,
                type: detected,
                confirmText: 'Dismiss'
            });
        },

        /**
         * Floating system message window (replaces auto-dismissing toast).
         * Ensures all system notifications strictly require user input to dismiss.
         */
        toast: function(options) {
            const opts = typeof options === 'string' ? { message: options } : (options || {});
            const msg = opts.message || opts.msg || '';
            const type = opts.type || detectType(msg);
            return this.show({
                title: opts.title || getDefaultTitle(type),
                message: msg,
                type: type,
                html: opts.html || false,
                confirmText: opts.confirmText || 'Dismiss'
            });
        },

        /**
         * Promise-based floating confirmation window
         */
        confirm: function(message, title) {
            return new Promise((resolve) => {
                this.show({
                    title: title || 'Confirm Action',
                    message: String(message),
                    type: 'warning',
                    confirmText: 'Yes, Proceed',
                    cancelText: 'Cancel',
                    onConfirm: () => resolve(true),
                    onCancel: () => resolve(false)
                });
            });
        }
    };

    // Expose global API
    window.SpectraPopup = SpectraPopup;
    window.showSystemPopup = function(title, message, type, onConfirm) {
        return SpectraPopup.show({ title, message, type, onConfirm, confirmText: 'Dismiss' });
    };
    window.showSystemToast = function(message, type) {
        return SpectraPopup.toast({ message, type });
    };
    window.showSystemAlert = function(message, type, title) {
        return SpectraPopup.show({ message, type: type || detectType(message), title, confirmText: 'Dismiss' });
    };

    // Override native window.alert to automatically route through SpectraPopup floating window
    window.alert = function(msg) {
        if (document.body) {
            SpectraPopup.alert(msg);
        } else {
            document.addEventListener('DOMContentLoaded', () => SpectraPopup.alert(msg));
        }
    };

    // Override global showToast to guarantee all notifications require user input to dismiss
    window.showToast = function(message, type) {
        SpectraPopup.toast({ message, type });
    };

    // Intercept confirmation alerts (e.g. onclick="return confirm('...')" / onsubmit="return confirm('...')")
    // and route them through SpectraPopup floating confirmation modal
    document.addEventListener('click', function(e) {
        const trigger = e.target.closest('button, a, input[type="submit"]');
        if (!trigger) return;

        const onclickStr = trigger.getAttribute('onclick') || '';
        const match = onclickStr.match(/return\s+confirm\((['"])(.*?)\1\)/);
        if (match) {
            e.preventDefault();
            e.stopImmediatePropagation();
            const message = match[2].replace(/\\'/g, "'").replace(/\\"/g, '"');
            
            SpectraPopup.confirm(message, 'Action Confirmation').then(function(confirmed) {
                if (confirmed) {
                    trigger.removeAttribute('onclick');
                    if (trigger.tagName === 'A' && trigger.href) {
                        window.location.href = trigger.href;
                    } else if (trigger.type === 'submit' && trigger.form) {
                        trigger.form.submit();
                    } else {
                        trigger.click();
                    }
                    setTimeout(function() { trigger.setAttribute('onclick', onclickStr); }, 600);
                }
            });
            return false;
        }
    }, true);

    document.addEventListener('submit', function(e) {
        const form = e.target;
        const onsubmitStr = form.getAttribute('onsubmit') || '';
        const match = onsubmitStr.match(/return\s+confirm\((['"])(.*?)\1\)/);
        if (match) {
            e.preventDefault();
            e.stopImmediatePropagation();
            const message = match[2].replace(/\\'/g, "'").replace(/\\"/g, '"');
            
            SpectraPopup.confirm(message, 'Action Confirmation').then(function(confirmed) {
                if (confirmed) {
                    form.removeAttribute('onsubmit');
                    form.submit();
                    setTimeout(function() { form.setAttribute('onsubmit', onsubmitStr); }, 600);
                }
            });
            return false;
        }
    }, true);

})(window, document);
