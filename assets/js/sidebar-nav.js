/** AdminLTE-inspired responsive sidebar navigation controller. */
class SidebarNavigation {
    constructor(doc = document, globalObject = window) {
        this.document = doc;
        this.global = globalObject;
        this.root = doc.querySelector('.app-shell');
        this.sidebar = doc.querySelector('.sidebar-panel');
        this.toggleButtons = doc.querySelectorAll('[data-sidebar-toggle]');
        this.backdrop = doc.querySelector('.sidebar-backdrop');
        this.collapseButtons = doc.querySelectorAll('[data-sidebar-collapse]');

        if (!this.root || !this.sidebar) return;

        this.links = Array.from(this.sidebar.querySelectorAll('.nav-link'));
        this.bindEvents();
        this.syncActiveLink();
        this.ensureDesktopState();
        this.dispatchState();
    }

    /** Notify other page components when the sidebar changes state. */
    dispatchState() {
        this.document.dispatchEvent(
            new CustomEvent('sidebar:state', {
                detail: {
                    open: this.root.classList.contains('sidebar-open'),
                    collapsed: this.root.classList.contains('sidebar-collapsed'),
                },
            })
        );
    }

    /** Mark the navigation link that matches the current page. */
    syncActiveLink() {
        const page = this.global.location.pathname.split('/').pop() || 'index.html';
        this.links.forEach((link) => {
            const href = link.getAttribute('href') || '';
            const isActive = page === href || this.global.location.pathname.endsWith(`/${href}`);
            link.classList.toggle('active', isActive);
            if (isActive) {
                link.setAttribute('aria-current', 'page');
            } else {
                link.removeAttribute('aria-current');
            }
        });
    }

    /** Close the mobile sidebar. */
    closeSidebar() {
        this.root.classList.remove('sidebar-open');
        this.dispatchState();
    }

    /** Toggle the mobile sidebar. */
    toggleSidebar() {
        this.root.classList.toggle('sidebar-open');
        this.dispatchState();
    }

    /** Toggle the desktop collapsed state. */
    toggleCollapse() {
        this.root.classList.toggle('sidebar-collapsed');
        this.dispatchState();
    }

    /** Clear mobile slide-over state on desktop widths. */
    ensureDesktopState() {
        if (this.global.matchMedia('(min-width: 992px)').matches) {
            this.root.classList.remove('sidebar-open');
        }
    }

    /** Attach click, resize, and transition listeners. */
    bindEvents() {
        this.toggleButtons.forEach((button) => {
            button.addEventListener('click', () => this.toggleSidebar());
        });

        if (this.backdrop) {
            this.backdrop.addEventListener('click', () => this.closeSidebar());
        }

        this.collapseButtons.forEach((button) => {
            button.addEventListener('click', () => this.toggleCollapse());
        });

        this.global.addEventListener('resize', () => this.ensureDesktopState());
        this.root.addEventListener('transitionend', () => this.dispatchState());
    }
}

new SidebarNavigation();
