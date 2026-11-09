/**
 * EduMaster Core - Global Theme Toggle Engine (Light & Dark Mode)
 * Provides instant zero-flicker switching and seamless session/cookie/database sync.
 */

(function() {
    'use strict';

    function getBaseUrl() {
        if (typeof window.BASE_URL !== 'undefined') {
            return window.BASE_URL;
        }
        var path = window.location.pathname;
        if (path.indexOf('/school_system') !== -1) {
            return '/school_system';
        }
        return '';
    }

    function getStoredTheme() {
        // 1. Check localStorage
        var local = localStorage.getItem('theme_preference');
        if (local === 'dark' || local === 'light') {
            return local;
        }
        // 2. Check Cookie
        var match = document.cookie.match(/(?:^|;\s*)theme_mode=([^;]+)/);
        if (match && (match[1] === 'dark' || match[1] === 'light')) {
            return match[1];
        }
        // 3. Check HTML attribute
        var htmlTheme = document.documentElement.getAttribute('data-theme');
        if (htmlTheme === 'dark' || htmlTheme === 'light') {
            return htmlTheme;
        }
        // 4. Default to light
        return 'light';
    }

    function applyTheme(theme, saveToServer) {
        if (theme !== 'dark' && theme !== 'light') {
            theme = 'light';
        }

        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-bs-theme', theme);
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        // Save to localStorage
        localStorage.setItem('theme_preference', theme);

        // Update Toggle Buttons UI
        updateToggleButtons(theme);

        // Sync with Server (Cookie & Database)
        if (saveToServer) {
            syncThemeWithServer(theme);
        }

        // Dispatch Theme Changed Event
        window.dispatchEvent(new CustomEvent('themeChanged', { detail: { theme: theme } }));
    }

    function updateToggleButtons(theme) {
        var buttons = document.querySelectorAll('.theme-toggle-btn, [data-action="toggle-theme"], #themeToggleBtn');
        var isDark = (theme === 'dark');

        buttons.forEach(function(btn) {
            var icon = btn.querySelector('i');
            if (icon) {
                if (isDark) {
                    icon.className = 'fa-solid fa-sun text-warning';
                } else {
                    icon.className = 'fa-solid fa-moon text-primary';
                }
            }

            var textSpan = btn.querySelector('.theme-toggle-text');
            if (textSpan) {
                var isRtl = document.documentElement.getAttribute('dir') === 'rtl';
                if (isDark) {
                    textSpan.textContent = isRtl ? 'الوضع الفاتح' : 'Light Mode';
                } else {
                    textSpan.textContent = isRtl ? 'الوضع الداكن' : 'Dark Mode';
                }
            }

            var title = isDark 
                ? (document.documentElement.getAttribute('dir') === 'rtl' ? 'التحويل إلى الوضع الفاتح' : 'Switch to Light Mode')
                : (document.documentElement.getAttribute('dir') === 'rtl' ? 'التحويل إلى الوضع الداكن' : 'Switch to Dark Mode');
            btn.setAttribute('title', title);
            btn.setAttribute('aria-label', title);
        });
    }

    function syncThemeWithServer(theme) {
        // Set Cookie on client immediately
        var maxAge = 365 * 24 * 60 * 60;
        document.cookie = 'theme_mode=' + encodeURIComponent(theme) + '; path=/; max-age=' + maxAge + '; SameSite=Lax';

        var apiUrl = getBaseUrl() + '/api/set_theme_preference.php';
        fetch(apiUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ theme: theme })
        }).catch(function(err) {
            console.warn('Could not sync theme preference to server:', err);
        });
    }

    window.toggleTheme = function() {
        var current = document.documentElement.getAttribute('data-theme') || getStoredTheme();
        var nextTheme = (current === 'dark') ? 'light' : 'dark';
        applyTheme(nextTheme, true);
    };

    // =========================================================================
    // Universal Navbar Search Engine (Expandable Icon -> Full Searchbar)
    // =========================================================================
    function initUniversalSearch() {
        var isMac = (navigator.platform && navigator.platform.toUpperCase().indexOf('MAC') >= 0) || 
                    (navigator.userAgent && navigator.userAgent.toUpperCase().indexOf('MAC') >= 0);

        // Update KBD shortcuts for OS
        document.querySelectorAll('.kbd-badge, #searchKbdTriggerBadge').forEach(function(badge) {
            if (badge.id === 'searchKbdTriggerBadge' || badge.dataset.shortcut === 'search') {
                badge.textContent = isMac ? '⌘ K' : 'Ctrl K';
                badge.title = isMac ? 'Press ⌘+K to search' : 'Press Ctrl+K to search';
            }
        });

        // Open Search function
        window.openNavbarSearch = function() {
            var wrapper = document.querySelector('.navbar-search-wrapper');
            if (wrapper) {
                wrapper.classList.add('is-open');
                var input = wrapper.querySelector('input[type="text"], #globalSearchInput');
                if (input) {
                    setTimeout(function() {
                        input.focus();
                        input.select();
                    }, 50);
                }
            }
        };

        // Close Search function
        window.closeNavbarSearch = function() {
            var wrapper = document.querySelector('.navbar-search-wrapper');
            if (wrapper && wrapper.classList.contains('is-open')) {
                wrapper.classList.remove('is-open');
                var input = wrapper.querySelector('input[type="text"], #globalSearchInput');
                if (input) {
                    input.blur();
                    if (input.value.trim() !== '') {
                        input.value = '';
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            }
        };

        // Click handler for Open / Close / Toggle
        document.addEventListener('click', function(e) {
            var openBtn = e.target.closest('.btn-search-trigger, #navbarSearchTrigger, [data-action="open-search"]');
            if (openBtn) {
                e.preventDefault();
                e.stopPropagation();
                window.openNavbarSearch();
                return;
            }

            var closeBtn = e.target.closest('.btn-search-close, #navbarSearchClose, [data-action="close-search"]');
            if (closeBtn) {
                e.preventDefault();
                e.stopPropagation();
                window.closeNavbarSearch();
                return;
            }

            // Click outside to close
            var wrapper = document.querySelector('.navbar-search-wrapper.is-open');
            if (wrapper && !wrapper.contains(e.target)) {
                window.closeNavbarSearch();
            }
        });

        // Global Keyboard Shortcut: Ctrl+K or Cmd+K to open, Esc to close
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                window.openNavbarSearch();
            } else if (e.key === 'Escape') {
                var wrapper = document.querySelector('.navbar-search-wrapper.is-open');
                if (wrapper) {
                    window.closeNavbarSearch();
                }
            }
        });

        // Live instant search filtering across table rows & cards
        document.querySelectorAll('.navbar-search-wrapper input, #globalSearchInput').forEach(function(searchInput) {
            searchInput.addEventListener('input', function() {
                var query = this.value.toLowerCase().trim();

                // 1. Filter table rows
                document.querySelectorAll('table tbody tr').forEach(function(row) {
                    // Skip header or empty state rows with class .no-filter
                    if (row.classList.contains('no-filter')) return;
                    var text = row.textContent.toLowerCase();
                    row.style.display = text.includes(query) ? '' : 'none';
                });

                // 2. Filter module cards & tiles
                document.querySelectorAll('.module-tile-modern, .searchable-card').forEach(function(tile) {
                    var text = tile.textContent.toLowerCase();
                    var parentCol = tile.closest('.col-md-6, .col-lg-4, .col-xl-3, .col-12');
                    if (parentCol) {
                        parentCol.style.display = text.includes(query) ? '' : 'none';
                    } else {
                        tile.style.display = text.includes(query) ? '' : 'none';
                    }
                });
            });
        });
    }

    // Smart Universal Navigation Stack Engine
    // Enables seamless return to previous page via browser back button or in-app back buttons
    function initSmartNavigation() {
        try {
            var currentPath = window.location.pathname + window.location.search;
            var rawStack = sessionStorage.getItem('app_nav_stack');
            var navStack = [];
            if (rawStack) {
                try {
                    navStack = JSON.parse(rawStack);
                    if (!Array.isArray(navStack)) navStack = [];
                } catch(e) {
                    navStack = [];
                }
            }

            // Normalize URLs for comparison (strip trailing slashes, keep queries)
            var cleanCurrent = currentPath.replace(/\/+$/, '') || '/';
            var lastEntry = navStack.length > 0 ? (navStack[navStack.length - 1] || '').replace(/\/+$/, '') : null;

            // Only push if not consecutive duplicate
            if (cleanCurrent !== lastEntry) {
                // If navigating back to the previous item in stack, pop rather than grow
                if (navStack.length >= 2 && (navStack[navStack.length - 2] || '').replace(/\/+$/, '') === cleanCurrent) {
                    navStack.pop();
                } else {
                    navStack.push(currentPath);
                    // Cap stack at 30 items to keep storage compact
                    if (navStack.length > 30) {
                        navStack.shift();
                    }
                }
                sessionStorage.setItem('app_nav_stack', JSON.stringify(navStack));
            }

            // Set up smart click listener for all universal & custom back buttons
            document.addEventListener('click', function(e) {
                var backBtn = e.target.closest('[data-action="smart-back"], .btn-smart-back, .navbar-custom a[title="Back"], .navbar-custom a[title="رجوع"]');
                if (!backBtn) return;

                var fallbackHref = backBtn.getAttribute('href') || backBtn.getAttribute('data-fallback-url');

                // If user holds Ctrl/Meta or right-clicks, let default browser behavior happen
                if (e.ctrlKey || e.metaKey || e.shiftKey || e.which === 2) return;

                e.preventDefault();

                // 1. If we have history within this site tab
                if (window.history.length > 1 && document.referrer && document.referrer.indexOf(window.location.host) !== -1) {
                    window.history.back();
                    return;
                }

                // 2. Check session navigation stack
                var stack = [];
                try {
                    stack = JSON.parse(sessionStorage.getItem('app_nav_stack') || '[]');
                } catch(err) {}

                if (stack.length >= 2) {
                    // Current is top, previous is second from top
                    stack.pop(); // Remove current
                    var targetPrev = stack.pop(); // Target previous
                    sessionStorage.setItem('app_nav_stack', JSON.stringify(stack));
                    if (targetPrev && targetPrev !== currentPath) {
                        window.location.href = targetPrev;
                        return;
                    }
                }

                // 3. Fallback to designated href or dashboard
                if (fallbackHref && fallbackHref !== '#' && !fallbackHref.startsWith('javascript:')) {
                    window.location.href = fallbackHref;
                } else {
                    window.location.href = getBaseUrl() + '/dashboard.php';
                }
            });

            // Handle popstate (browser back/forward arrows)
            window.addEventListener('popstate', function() {
                var stack = [];
                try {
                    stack = JSON.parse(sessionStorage.getItem('app_nav_stack') || '[]');
                    if (stack.length > 0) {
                        stack.pop();
                        sessionStorage.setItem('app_nav_stack', JSON.stringify(stack));
                    }
                } catch(err) {}
            });

        } catch (err) {
            console.warn('Smart navigation init warning:', err);
        }
    }

    // Auto-initialize on DOM ready
    function init() {
        var theme = getStoredTheme();
        applyTheme(theme, false);

        document.addEventListener('click', function(e) {
            var toggleBtn = e.target.closest('.theme-toggle-btn, [data-action="toggle-theme"], #themeToggleBtn');
            if (toggleBtn) {
                e.preventDefault();
                window.toggleTheme();
            }
        });

        initUniversalSearch();
        initSmartNavigation();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
