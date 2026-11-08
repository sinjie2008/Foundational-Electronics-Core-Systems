/** Same-origin prototype client; a future host session can provide CSRF protection. */
export class MediaApi {
    static headers() {
        const headers = {};
        const csrf = this.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content;
        if (csrf) headers['X-CSRF-Token'] = csrf;
        return headers;
    }
    static url(action, query = {}) {
        return 'api/media/' + action + '.php?' + new URLSearchParams(
            Object.entries(query).filter(([, value]) => value !== '' && value !== null && value !== undefined)
        );
    }
    static async request(action, { method = 'GET', query = {}, data, signal } = {}) {
        const headers = { ...this.headers(), Accept: 'application/json' };
        let body;
        if (data instanceof FormData) body = data;
        else if (data !== undefined) {
            headers['Content-Type'] = 'application/json';
            body = JSON.stringify(data);
        }
        const response = await fetch(this.url(action, query), { method, headers, body, signal, credentials: 'same-origin', cache: 'no-store' });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.error || payload.success === false) {
            const error = new Error(payload.error?.message || payload.message || 'The operation could not finish.');
            error.code = payload.error?.code || payload.errorCode || 'HTTP_' + response.status;
            error.status = response.status;
            error.details = payload.error?.details;
            throw error;
        }
        if (action === 'capabilities') this.csrfToken = payload.data?.csrfToken;
        return payload.data;
    }
    static async prepare() {
        try { return await this.request('capabilities'); }
        catch { return null; }
    }
    static async blob(url) {
        const destination = new URL(url, location.href);
        if (destination.origin !== location.origin) throw new Error('External preview addresses are not allowed.');
        const response = await fetch(destination, { headers: this.headers(), credentials: 'same-origin' });
        if (!response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.error?.message || 'The file could not be loaded.');
        }
        return response.blob();
    }
    static async download(asset) {
        const blob = await this.blob(asset.downloadUrl);
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url; link.download = asset.filename; link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    static installAjax() {
        if (!window.jQuery || this.ajaxInstalled) return;
        this.ajaxInstalled = true;
        window.jQuery.ajaxPrefilter((options, original, xhr) => {
            if (new URL(options.url, location.href).origin === location.origin) {
                Object.entries(this.headers()).forEach(([name, value]) => xhr.setRequestHeader(name, value));
            }
        });
    }
}
export const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
export const formatSize = (value) => value < 1024 ? value + ' B' : value < 1048576 ? (value / 1024).toFixed(1) + ' KiB' : (value / 1048576).toFixed(1) + ' MiB';
export const operationKey = () => crypto.randomUUID();
// Discard credentials left by the earlier standalone access screen.
try { sessionStorage.removeItem('catalog-media-access'); } catch { /* Storage can be unavailable. */ }
MediaApi.installAjax();
