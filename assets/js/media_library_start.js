/** Show a recoverable startup error if the browser cannot load the library module. */
(() => {
    const entry = document.currentScript;
    const moduleUrl = new URL('./media_library.js', entry.src);
    moduleUrl.search = new URL(entry.src).search;
    import(moduleUrl.href).catch((error) => {
        const status = document.getElementById('media-status');
        status.textContent = 'Media Library could not start. Reload the page to try again.';
        status.classList.add('is-error');
        const retry = document.getElementById('media-retry');
        retry.hidden = false;
        retry.onclick = () => window.location.reload();
        console.error('Media Library startup failed.', error);
    });
})();
