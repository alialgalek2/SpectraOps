/**
 * Sheet & Navigation Interaction JavaScript for Android Navigation Bar
 */
document.addEventListener('DOMContentLoaded', () => {
    // 1. Mark body as native app to trigger responsive layout overrides
    document.body.classList.add('is-native-app');

    // 2. User Bottom Sheet Elements
    const userBtn = document.getElementById('appUserSheetBtn');
    const sheet = document.getElementById('userSheet');
    const overlay = document.getElementById('userSheetOverlay');

    function openSheet() {
        sheet?.classList.add('active');
        overlay?.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSheet() {
        sheet?.classList.remove('active');
        overlay?.classList.remove('active');
        document.body.style.overflow = '';
    }

    userBtn?.addEventListener('click', openSheet);
    overlay?.addEventListener('click', closeSheet);

    // Close on Escape key press
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sheet?.classList.contains('active')) {
            closeSheet();
        }
    });

    // Native touch swipe down to close
    let startY = 0;
    sheet?.addEventListener('touchstart', (e) => {
        startY = e.touches[0].clientY;
    }, { passive: true });

    sheet?.addEventListener('touchend', (e) => {
        const endY = e.changedTouches[0].clientY;
        if (endY - startY > 60) {
            closeSheet();
        }
    }, { passive: true });

    // 3. Compact Search Box Interaction
    const searchBtn = document.getElementById('searchQuickBtn');
    const searchBox = document.getElementById('appCompactSearchBox');
    const searchInput = document.getElementById('compactSearchInput');
    const searchCloseBtn = document.getElementById('compactSearchCloseBtn');

    searchBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        // Check if desktop search trigger exists first
        const desktopSearchTrigger = document.getElementById('navbarSearchTrigger');
        const desktopGlobalSearch = document.getElementById('globalSearchInput');

        if (desktopSearchTrigger && window.innerWidth >= 992) {
            desktopSearchTrigger.click();
            return;
        } else if (desktopGlobalSearch && window.innerWidth >= 992) {
            desktopGlobalSearch.focus();
            return;
        }

        // Otherwise toggle compact mobile search box
        if (searchBox) {
            const isVisible = searchBox.style.display !== 'none';
            searchBox.style.display = isVisible ? 'none' : 'block';
            if (!isVisible) {
                setTimeout(() => searchInput?.focus(), 50);
            }
        }
    });

    searchCloseBtn?.addEventListener('click', () => {
        if (searchBox) {
            searchBox.style.display = 'none';
        }
    });

    // 4. Notifications interaction: clears badge count on click
    const notifBtn = document.getElementById('notifQuickBtn');
    notifBtn?.addEventListener('click', () => {
        const notifBadge = notifBtn.querySelector('.badge-count');
        if (notifBadge) {
            notifBadge.style.display = 'none';
        }
    });

    // 5. Native Android Logout Bridge Integration
    const logoutBtn = document.getElementById('appLogoutBtn');
    logoutBtn?.addEventListener('click', (e) => {
        if (window.AndroidInterface && typeof window.AndroidInterface.logoutAndExit === 'function') {
            e.preventDefault();
            window.AndroidInterface.logoutAndExit();
        }
    });
});
