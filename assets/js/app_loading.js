/** Global loading overlay and progress controller with request counting. */
class LoadingOverlayController {
    static OVERLAY_ID = 'app-loading-overlay';
    static PROGRESS_ID = 'app-loading-progress';
    static PROGRESS_BAR_CLASS = 'app-loading-progress__bar';
    static BODY_LOCK_CLASS = 'app-loading-locked';
    static START_FLOOR = 15;
    static MAX_IDLE_PROGRESS = 90;
    static COMPLETE_DELAY_MS = 200;

    constructor(globalObject) {
        this.global = globalObject;
        this.state = {
            activeCount: 0,
            overlayEl: null,
            progressEl: null,
            progressBarEl: null,
            progressValue: 0,
            growTimer: null,
            hideTimer: null,
        };
    }

    /** Ensure overlay and progress elements exist and are cached. */
    ensureElements() {
        const doc = this.global.document;
        const body = doc && doc.body;
        if (!body) return;

        if (!this.state.overlayEl) {
            const overlay = doc.createElement('div');
            overlay.id = LoadingOverlayController.OVERLAY_ID;
            overlay.className = 'app-loading-overlay';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-live', 'polite');

            const message = doc.createElement('div');
            message.className = 'app-loading-message';
            message.textContent = 'Loading, please wait...';
            overlay.appendChild(message);

            this.state.overlayEl = overlay;
            body.appendChild(overlay);
        }

        if (!this.state.progressEl) {
            const progress = doc.createElement('div');
            progress.id = LoadingOverlayController.PROGRESS_ID;
            progress.className = 'app-loading-progress';

            const bar = doc.createElement('div');
            bar.className = LoadingOverlayController.PROGRESS_BAR_CLASS;
            progress.appendChild(bar);

            this.state.progressEl = progress;
            this.state.progressBarEl = bar;
            body.appendChild(progress);
        }
    }

    /** Update the progress bar width, clamped between zero and one hundred. */
    setProgress(percent) {
        const clamped = Math.max(0, Math.min(100, percent));
        this.state.progressValue = clamped;
        if (this.state.progressBarEl) {
            this.state.progressBarEl.style.width = `${clamped}%`;
        }
    }

    /** Start a gentle auto-increment toward the idle ceiling. */
    startGrowth() {
        this.stopGrowth();
        this.state.growTimer = this.global.setInterval(() => {
            const delta = Math.max(1, (LoadingOverlayController.MAX_IDLE_PROGRESS - this.state.progressValue) * 0.1);
            const target = Math.min(
                LoadingOverlayController.MAX_IDLE_PROGRESS,
                this.state.progressValue + delta
            );
            this.setProgress(target);
        }, 140);
    }

    /** Stop auto-increment timers. */
    stopGrowth() {
        if (this.state.growTimer) {
            this.global.clearInterval(this.state.growTimer);
            this.state.growTimer = null;
        }
    }

    /** Reveal the overlay and progress bar, locking the page surface. */
    showOverlay() {
        this.ensureElements();
        const body = this.global.document && this.global.document.body;
        if (!body) return;

        if (this.state.hideTimer) {
            this.global.clearTimeout(this.state.hideTimer);
            this.state.hideTimer = null;
        }
        if (this.state.overlayEl) {
            this.state.overlayEl.classList.add('is-visible');
        }
        if (this.state.progressEl) {
            this.state.progressEl.classList.add('is-visible');
        }
        body.classList.add(LoadingOverlayController.BODY_LOCK_CLASS);
        if (this.state.progressValue < LoadingOverlayController.START_FLOOR) {
            this.setProgress(LoadingOverlayController.START_FLOOR);
        }
        this.startGrowth();
    }

    /** Hide the overlay when no active request remains. */
    hideOverlayWhenIdle() {
        const globalObject = this.global;
        const body = globalObject.document && globalObject.document.body;
        if (this.state.activeCount > 0) return;

        this.stopGrowth();
        this.setProgress(100);
        if (this.state.hideTimer) {
            globalObject.clearTimeout(this.state.hideTimer);
        }
        this.state.hideTimer = globalObject.setTimeout(() => {
            if (this.state.activeCount > 0) return;
            if (this.state.overlayEl) {
                this.state.overlayEl.classList.remove('is-visible');
            }
            if (this.state.progressEl) {
                this.state.progressEl.classList.remove('is-visible');
            }
            this.setProgress(0);
            if (body) {
                body.classList.remove(LoadingOverlayController.BODY_LOCK_CLASS);
            }
            this.state.hideTimer = null;
        }, LoadingOverlayController.COMPLETE_DELAY_MS);
    }

    /** Increment the active counter and show the overlay when needed. */
    beginLoading() {
        this.state.activeCount += 1;
        if (this.state.activeCount === 1) {
            this.showOverlay();
        }
    }

    /** Decrement the active counter and hide when all work is done. */
    endLoading() {
        this.state.activeCount = Math.max(0, this.state.activeCount - 1);
        if (this.state.activeCount === 0) {
            this.hideOverlayWhenIdle();
        }
    }

    /** Wrap a promise or promise factory with loading state management. */
    wrapPromise(promiseOrFactory) {
        this.beginLoading();
        try {
            const promise =
                typeof promiseOrFactory === 'function'
                    ? promiseOrFactory()
                    : promiseOrFactory;
            return Promise.resolve(promise)
                .then((result) => {
                    this.endLoading();
                    return result;
                })
                .catch((error) => {
                    this.endLoading();
                    throw error;
                });
        } catch (error) {
            this.endLoading();
            throw error;
        }
    }
}

const loadingOverlayController = new LoadingOverlayController(window);
window.LoadingOverlay = {
    start: loadingOverlayController.beginLoading.bind(loadingOverlayController),
    end: loadingOverlayController.endLoading.bind(loadingOverlayController),
    wrapPromise: loadingOverlayController.wrapPromise.bind(loadingOverlayController),
};
