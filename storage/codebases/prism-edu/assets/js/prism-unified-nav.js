/**
 * PRISM EDU - Unified Top Navigation & Drawer Controller
 * Handles offcanvas drawer interactions, theme toggling, bilingual RTL switching, and global search shortcut.
 */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Drawer Controls
    const navToggle = document.getElementById('globalNavToggleBtn');
    const userMenuBtn = document.getElementById('navUserMenuTrigger');
    const drawer = document.getElementById('unifiedNavDrawer');
    const backdrop = document.getElementById('unifiedNavBackdrop');

    const openDrawer = (e) => {
        if (e) e.preventDefault();
        drawer?.classList.add('active');
        backdrop?.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    const closeDrawer = (e) => {
        if (e) e.preventDefault();
        drawer?.classList.remove('active');
        backdrop?.classList.remove('active');
        document.body.style.overflow = '';
    };

    navToggle?.addEventListener('click', openDrawer);
    userMenuBtn?.addEventListener('click', openDrawer);
    backdrop?.addEventListener('click', closeDrawer);

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && drawer?.classList.contains('active')) {
            closeDrawer();
        }
    });

    // 2. Dark / Light Theme Toggle & Persistence
    const themeBtn = document.getElementById('themeToggleBtn');
    
    // Check existing theme from document or localStorage (default dark for PRISM EDU)
    const existingTheme = document.documentElement.getAttribute('data-theme') 
        || document.documentElement.getAttribute('data-bs-theme')
        || localStorage.getItem('prism_theme') 
        || 'dark';

    const applyTheme = (theme) => {
        document.documentElement.setAttribute('data-theme', theme);
        document.documentElement.setAttribute('data-bs-theme', theme);
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }
        localStorage.setItem('prism_theme', theme);
        localStorage.setItem('theme', theme);
    };

    applyTheme(existingTheme);

    themeBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        const currentTheme = document.documentElement.getAttribute('data-theme') || 'dark';
        const nextTheme = currentTheme === 'dark' ? 'light' : 'dark';
        applyTheme(nextTheme);
    });

    // 3. Language Switcher (EN / AR with RTL support)
    const langEnBtn = document.getElementById('langEnBtn');
    const langArBtn = document.getElementById('langArBtn');

    function setLanguage(lang, triggerReload = false) {
        localStorage.setItem('prism_lang', lang);
        document.documentElement.setAttribute('lang', lang);
        document.documentElement.setAttribute('dir', lang === 'ar' ? 'rtl' : 'ltr');

        if (lang === 'ar') {
            langArBtn?.classList.add('active');
            langEnBtn?.classList.remove('active');
        } else {
            langEnBtn?.classList.add('active');
            langArBtn?.classList.remove('active');
        }

        // If triggerReload is true and URL parameter can change backend language session
        if (triggerReload) {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', lang);
            window.location.href = url.toString();
        }
    }

    langEnBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        setLanguage('en', true);
    });
    langArBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        setLanguage('ar', true);
    });

    // Initialize persisted language
    const currentDocLang = document.documentElement.getAttribute('lang') || localStorage.getItem('prism_lang') || 'en';
    setLanguage(currentDocLang, false);

    // 4. Global Search Keyboard Shortcut (⌘K / Ctrl+K)
    const searchInput = document.getElementById('globalSearchInput');
    document.addEventListener('keydown', (e) => {
        if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            searchInput?.focus();
            searchInput?.select();
        }
    });

    // Search input Enter navigation
    searchInput?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && searchInput.value.trim() !== '') {
            const query = encodeURIComponent(searchInput.value.trim());
            // Route to users or global search endpoint
            const baseUrl = window.location.pathname.startsWith('/school_system') ? '/school_system' : '';
            window.location.href = `${baseUrl}/modules/Administration/users.php?search=${query}`;
        }
    });
});
