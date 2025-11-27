/**
 * app_loading.js
 * Global loading overlay + progress bar controller with request counting.
 */
(function (global) {
    'use strict';

    const OVERLAY_ID = 'app-loading-overlay';
    const PROGRESS_ID = 'app-loading-progress';
    const PROGRESS_BAR_CLASS = 'app-loading-progress__bar';
    const BODY_LOCK_CLASS = 'app-loading-locked';
    const START_FLOOR = 15;
    const MAX_IDLE_PROGRESS = 90;
    const COMPLETE_DELAY_MS = 200;

    const state = {
        activeCount: 0,
        overlayEl: null,
        progressEl: null,
        progressBarEl: null,
        progressValue: 0,
        growTimer: null,
        hideTimer: null,
    };

    /**
     * Ensure overlay and progress DOM elements exist and are cached.
     */
    function ensureElements() {
        const doc = global.document;
        const body = doc && doc.body;
        if (!body) {
            return;
        }

        if (!state.overlayEl) {
            const overlay = doc.createElement('div');
            overlay.id = OVERLAY_ID;
            overlay.className = 'app-loading-overlay';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-live', 'polite');

            const message = doc.createElement('div');
            message.className = 'app-loading-message';
            message.textContent = 'Loading, please wait...';
            overlay.appendChild(message);

            state.overlayEl = overlay;
            body.appendChild(overlay);
        }

        if (!state.progressEl) {
            const progress = doc.createElement('div');
            progress.id = PROGRESS_ID;
            progress.className = 'app-loading-progress';

            const bar = doc.createElement('div');
            bar.className = PROGRESS_BAR_CLASS;
            progress.appendChild(bar);

            state.progressEl = progress;
            state.progressBarEl = bar;
            body.appendChild(progress);
        }
    }

    /**
     * Update the progress bar width, clamped between 0 and 100.
     */
    function setProgress(percent) {
        const clamped = Math.max(0, Math.min(100, percent));
        state.progressValue = clamped;
        if (state.progressBarEl) {
            state.progressBarEl.style.width = `${clamped}%`;
        }
    }

    /**
     * Start a gentle auto-increment toward the idle ceiling.
     */
    function startGrowth() {
        stopGrowth();
        state.growTimer = global.setInterval(() => {
            const delta = Math.max(1, (MAX_IDLE_PROGRESS - state.progressValue) * 0.1);
            const target = Math.min(MAX_IDLE_PROGRESS, state.progressValue + delta);
            setProgress(target);
        }, 140);
    }

    /**
     * Stop auto-increment timers.
     */
    function stopGrowth() {
        if (state.growTimer) {
            global.clearInterval(state.growTimer);
            state.growTimer = null;
        }
    }

    /**
     * Reveal the overlay and progress bar, locking the UI surface.
     */
    function showOverlay() {
        ensureElements();
        const body = global.document && global.document.body;
        if (!body) {
            return;
        }
        if (state.hideTimer) {
            global.clearTimeout(state.hideTimer);
            state.hideTimer = null;
        }
        if (state.overlayEl) {
            state.overlayEl.classList.add('is-visible');
        }
        if (state.progressEl) {
            state.progressEl.classList.add('is-visible');
        }
        body.classList.add(BODY_LOCK_CLASS);
        if (state.progressValue < START_FLOOR) {
            setProgress(START_FLOOR);
        }
        startGrowth();
    }

    /**
     * Complete the bar, fade the overlay, and unlock interactions.
     */
    function hideOverlayWhenIdle() {
        stopGrowth();
        setProgress(100);
        const body = global.document && global.document.body;
        if (state.hideTimer) {
            global.clearTimeout(state.hideTimer);
        }
        state.hideTimer = global.setTimeout(() => {
            if (state.overlayEl) {
                state.overlayEl.classList.remove('is-visible');
            }
            if (state.progressEl) {
                state.progressEl.classList.remove('is-visible');
            }
            setProgress(0);
            if (body) {
                body.classList.remove(BODY_LOCK_CLASS);
            }
            state.hideTimer = null;
        }, COMPLETE_DELAY_MS);
    }

    /**
     * Increment the active counter and show the overlay when needed.
     */
    function beginLoading() {
        state.activeCount += 1;
        if (state.activeCount === 1) {
            showOverlay();
        }
    }

    /**
     * Decrement the active counter and hide when all work is done.
     */
    function endLoading() {
        state.activeCount = Math.max(0, state.activeCount - 1);
        if (state.activeCount === 0) {
            hideOverlayWhenIdle();
        }
    }

    /**
     * Wrap a promise or promise factory with loading state management.
     */
    function wrapPromise(promiseOrFactory) {
        beginLoading();
        try {
            const promise =
                typeof promiseOrFactory === 'function'
                    ? promiseOrFactory()
                    : promiseOrFactory;
            return Promise.resolve(promise)
                .then((result) => {
                    endLoading();
                    return result;
                })
                .catch((error) => {
                    endLoading();
                    throw error;
                });
        } catch (error) {
            endLoading();
            throw error;
        }
    }

    global.LoadingOverlay = {
        start: beginLoading,
        end: endLoading,
        wrapPromise,
    };
})(window);
