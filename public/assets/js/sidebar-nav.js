/**
 * AdminLTE-inspired sidebar: sticky on desktop, slide-over on mobile.
 * - Toggles a class on the root `.app-shell` to slide the sidebar.
 * - Allows desktop collapse (AdminLTE-style) via `.sidebar-collapsed`.
 * - Highlights the active link based on the current page.
 */
(() => {
    const root = document.querySelector('.app-shell'); // Page container used for toggling sidebar state
    const sidebar = document.querySelector('.sidebar-panel'); // Sidebar element
    const toggleButtons = document.querySelectorAll('[data-sidebar-toggle]'); // Toggle buttons (mobile)
    const backdrop = document.querySelector('.sidebar-backdrop'); // Backdrop overlay for mobile
    const collapseButtons = document.querySelectorAll('[data-sidebar-collapse]'); // Collapse buttons (desktop)
    const rootTransitionHandler = () => {
        document.dispatchEvent(
            new CustomEvent('sidebar:state', {
                detail: {
                    open: root.classList.contains('sidebar-open'),
                    collapsed: root.classList.contains('sidebar-collapsed'),
                },
            })
        );
    };

    if (!root || !sidebar) {
        return;
    }

    const links = Array.from(sidebar.querySelectorAll('.nav-link')); // Navigation links inside the sidebar
    const syncActiveLink = () => {
        const page = window.location.pathname.split('/').pop() || 'index.html';
        links.forEach((link) => {
            const href = link.getAttribute('href') || '';
            const isActive = page === href || window.location.pathname.endsWith(`/${href}`);
            link.classList.toggle('active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    const closeSidebar = () => {
        root.classList.remove('sidebar-open');
        rootTransitionHandler();
    };

    const toggleSidebar = () => {
        root.classList.toggle('sidebar-open');
        rootTransitionHandler();
    };

    const toggleCollapse = () => {
        root.classList.toggle('sidebar-collapsed');
        rootTransitionHandler();
    };

    const ensureDesktopState = () => {
        // On desktop ensure slide-over is disabled; keep collapse state if set.
        if (window.matchMedia('(min-width: 992px)').matches) {
            root.classList.remove('sidebar-open');
        }
    };

    toggleButtons.forEach((btn) => {
        btn.addEventListener('click', toggleSidebar);
    });

    if (backdrop) {
        backdrop.addEventListener('click', closeSidebar);
    }

    collapseButtons.forEach((btn) => {
        btn.addEventListener('click', toggleCollapse);
    });

    window.addEventListener('resize', ensureDesktopState);

    root.addEventListener('transitionend', rootTransitionHandler);
    syncActiveLink();
    ensureDesktopState();
    rootTransitionHandler();
})();
