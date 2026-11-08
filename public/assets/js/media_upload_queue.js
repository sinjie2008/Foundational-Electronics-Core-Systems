import { MediaApi, escapeHtml as e, formatSize, operationKey } from './media_api.js?v=2612a8710c9e';

export class MediaUploadQueue {
    constructor(capabilities, onComplete, onMap) {
        this.capabilities = capabilities; this.onComplete = onComplete; this.onMap = onMap; this.items = []; this.batchId = null; this.running = false;
        this.dialog = document.createElement('dialog'); this.dialog.className = 'media-workflow-dialog media-library-page';
        this.dialog.innerHTML = '<div class="media-dialog-heading"><h2>Upload Catalog media</h2><button class="btn btn-outline-secondary" data-close>Close</button></div>' +
            '<p>These files are intended for public Catalog use. ' + e((capabilities.extensions || ['images','PDF','GLB']).join(', ')) + ' · maximum ' + e(formatSize(capabilities.maxBytes)) + ' each.</p>' +
            '<div class="media-drop-zone" tabindex="0" role="button" aria-label="Choose files or drop files here"><strong>Drop files here</strong><p>or choose multiple files</p><input type="file" multiple accept="image/*,.pdf,.glb" class="form-control" aria-label="Choose upload files"></div>' +
            '<div class="media-toolbar"><button class="btn btn-primary" data-start>Upload queued files</button><button class="btn btn-outline-primary" data-map disabled>Map uploaded files</button><button class="btn btn-outline-secondary" data-cancel>Cancel pending</button><button class="btn btn-outline-secondary" data-resume ' + (sessionStorage.getItem('catalog-media-last-upload') ? '' : 'hidden') + '>Review previous batch</button></div>' +
            '<p role="status" aria-live="polite"></p><div class="media-queue"></div><p class="small text-muted">Successful files are retained when other files fail. Uploading does not assign a Catalog field.</p>';
        document.body.append(this.dialog); this.previousFocus = document.activeElement;
        this.dialog.querySelector('[data-close]').addEventListener('click', () => this.close());
        this.dialog.addEventListener('cancel', (event) => { if (this.running) { event.preventDefault(); this.status('Cancel pending files before closing, or wait for the current uploads.'); } });
        this.dialog.addEventListener('close', () => { this.dialog.remove(); this.previousFocus?.focus(); this.onComplete(); });
        const drop = this.dialog.querySelector('.media-drop-zone');
        drop.addEventListener('dragover', (event) => { event.preventDefault(); drop.classList.add('is-dragging'); });
        drop.addEventListener('dragleave', () => drop.classList.remove('is-dragging'));
        drop.addEventListener('drop', (event) => { event.preventDefault(); drop.classList.remove('is-dragging'); this.add([...event.dataTransfer.files]); });
        drop.addEventListener('keydown', (event) => { if (event.target === drop && ['Enter',' '].includes(event.key)) { event.preventDefault(); drop.querySelector('input').click(); } });
        drop.querySelector('input').addEventListener('change', (event) => this.add([...event.target.files]));
        this.dialog.querySelector('[data-start]').addEventListener('click', () => this.start());
        this.dialog.querySelector('[data-cancel]').addEventListener('click', () => this.cancel());
        this.dialog.querySelector('[data-resume]').addEventListener('click', () => this.resume());
        this.dialog.querySelector('[data-map]').addEventListener('click', () => {
            if (this.running) return;
            const assets = this.items.filter((item) => item.state === 'ready' && item.asset).map((item) => ({ ...item.asset, filename: item.file.name }));
            if (!assets.length) return;
            this.dialog.close(); this.onMap?.(assets);
        });
        this.dialog.showModal();
    }
    status(text) { this.dialog.querySelector('[role=status]').textContent = text; }
    close() {
        if (this.running) { this.status('Uploads are still running. Cancel pending files or wait before closing.'); return; }
        this.dialog.close();
    }
    add(files) {
        if (this.batchId || this.running) { this.status('Finish this batch, then open Upload files again to start another.'); return; }
        if (files.length + this.items.length > 1000) this.lastError = 'Only the first 1000 files were queued. Start a new batch for the remaining files.';
        files.forEach((file) => {
            if (this.items.length >= 1000) return;
            const item = { id: operationKey(), file, state: 'queued', progress: 0, keepDuplicate: false };
            if (file.size <= 0 || file.size > this.capabilities.maxBytes) { item.state = 'invalid'; item.message = 'File is empty or too large.'; }
            this.items.push(item);
        });
        this.render();
    }
    render() {
        const list = this.dialog.querySelector('.media-queue'); list.replaceChildren();
        this.items.forEach((item) => {
            const row = document.createElement('div'); row.className = 'media-queue-row';
            row.innerHTML = '<div><strong>' + e(item.file.name) + '</strong><div class="small text-muted">' + e(formatSize(item.file.size)) + '</div><div class="small" role="status">' + e(item.message || item.state.replaceAll('_', ' ')) + '</div></div><div><progress max="100" value="' + item.progress + '" aria-label="Upload progress for ' + e(item.file.name) + '"></progress></div><div class="media-queue-actions"></div>';
            item.progressElement = row.querySelector('progress');
            const actions = row.querySelector('.media-queue-actions');
            const button = (text, callback) => { const el = document.createElement('button'); el.type = 'button'; el.className = 'btn btn-sm btn-outline-secondary'; el.textContent = text; el.addEventListener('click', callback); actions.append(el); };
            if (['failed','checking'].includes(item.state)) button(item.missingFile ? 'Choose file to retry' : 'Retry', () => {
                if (!item.missingFile) { item.state = 'queued'; item.message = ''; this.start(); return; }
                const input = document.createElement('input'); input.type = 'file'; input.accept = 'image/*,.pdf,.glb';
                input.addEventListener('change', () => {
                    const file = input.files[0]; if (!file) return;
                    if (item.file.name && file.name !== item.file.name) { this.status('Choose the original file for this queue item, or start a new batch.'); return; }
                    item.file = file; item.missingFile = false; item.state = 'queued'; item.message = ''; this.start();
                }); input.click();
            });
            if (['queued','failed','invalid'].includes(item.state)) button('Remove', () => {
                if (!this.batchId) this.items = this.items.filter((candidate) => candidate !== item);
                else { item.state = 'cancelled'; MediaApi.request('batch-cancel', { method: 'POST', data: { batchId: this.batchId, itemIds: [item.id] } }).catch((error) => this.status(error.message)); }
                this.render();
            });
            if (item.state === 'uploading') button('Cancel', () => { item.cancelRequested = true; item.xhr?.abort(); });
            if (item.state === 'duplicate_candidate') {
                const select = document.createElement('select'); select.className = 'form-select form-select-sm'; select.setAttribute('aria-label', 'Choose duplicate to reuse');
                item.duplicates.forEach((asset) => { const option = document.createElement('option'); option.value = asset.id; option.textContent = asset.stored_filename; select.append(option); }); actions.append(select);
                button('Reuse existing', async () => {
                    try {
                        const result = await MediaApi.request('batch-reuse', { method: 'POST', data: { batchId: this.batchId, clientItemId: item.id, mediaId: select.value } });
                        item.state = 'ready'; item.asset = result.asset; item.message = 'Existing file reused; no second upload.'; item.progress = 100; this.render();
                    } catch (error) { this.status(error.message); }
                });
                button('Keep separate copy', () => {
                    item.keepDuplicate = true;
                    if (item.missingFile) { item.state = 'failed'; item.message = 'Choose the original file again to upload a separate copy.'; this.render(); }
                    else { item.state = 'queued'; this.start(); }
                });
            }
            list.append(row);
        });
        const successful = this.items.filter((item) => item.state === 'ready').length;
        this.dialog.querySelector('[data-map]').disabled = this.running || !this.items.some((item) => item.state === 'ready' && item.asset);
        this.status(successful + ' successful · ' + this.items.filter((item) => item.state === 'failed').length + ' failed · ' + this.items.filter((item) => item.state === 'queued').length + ' queued' + (this.lastError ? ' — ' + this.lastError : ''));
    }
    async start() {
        if (this.running || !this.items.some((item) => item.state === 'queued')) return;
        this.running = true; this.lastError = ''; this.dialog.querySelector('[data-start]').disabled = true;
        try {
            if (!this.batchId) {
                const data = await MediaApi.request('batches', { method: 'POST', data: { idempotencyKey: operationKey(), items: this.items.filter((item) => item.state !== 'invalid').map((item) => ({ clientItemId: item.id, filename: item.file.name, sizeBytes: item.file.size })) } });
                this.batchId = data.id; sessionStorage.setItem('catalog-media-last-upload', data.id);
            }
            const worker = async () => {
                let item;
                while ((item = this.items.find((candidate) => candidate.state === 'queued'))) {
                    item.state = 'uploading'; item.message = ''; this.render();
                    try {
                        const result = await this.send(item);
                        item.state = result.status; item.asset = result.asset; item.duplicates = result.duplicates;
                        item.progress = result.status === 'ready' ? 100 : item.progress;
                        item.message = result.status === 'ready' ? 'Uploaded — ready to map.' : 'Matching file content was found. Choose reuse or keep a separate copy.';
                    } catch (error) {
                        item.state = item.cancelRequested ? 'checking' : 'failed'; item.message = error.message;
                        if (item.cancelRequested) await this.checkCancelled(item);
                    }
                    this.render();
                }
            };
            await Promise.all([worker(), worker()]);
        } catch (error) { this.lastError = error.message; this.status(error.message); }
        finally { this.running = false; this.dialog.querySelector('[data-start]').disabled = false; this.render(); this.onComplete(); }
    }
    send(item) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest(); item.xhr = xhr; item.cancelRequested = false;
            xhr.open('POST', MediaApi.url('upload'));
            Object.entries(MediaApi.headers()).forEach(([key, value]) => xhr.setRequestHeader(key, value));
            xhr.upload.addEventListener('progress', (event) => { if (event.lengthComputable) { item.progress = Math.round(event.loaded / event.total * 100); if (item.progressElement) item.progressElement.value = item.progress; } });
            xhr.onload = () => {
                let payload = {}; try { payload = JSON.parse(xhr.responseText); } catch { /* handled below */ }
                if (xhr.status >= 200 && xhr.status < 300 && payload.success) resolve(payload.data);
                else reject(new Error(payload.error?.message || 'Upload failed. Retry this file.'));
            };
            xhr.onerror = () => reject(new Error('Connection interrupted. Check batch status before retrying.'));
            xhr.onabort = () => reject(new Error('Upload interrupted; checking the server result.'));
            const data = new FormData(); data.append('file', item.file); data.append('batchId', this.batchId); data.append('clientItemId', item.id);
            if (item.keepDuplicate) data.append('keepDuplicate', '1');
            xhr.send(data);
        });
    }
    async checkCancelled(item) {
        try {
            const data = await MediaApi.request('batch-cancel', { method: 'POST', data: { batchId: this.batchId, itemIds: [item.id] } });
            const result = data.items.find((candidate) => candidate.clientItemId === item.id);
            item.state = result?.status || 'checking';
            item.asset = result?.result?.asset;
            item.message = result?.status === 'ready' ? 'Upload completed on the server; the file was retained.' : result?.status === 'cancelled' ? 'Cancelled.' : 'Result is pending. Use the batch ID to check before retrying.';
        } catch (error) { item.state = 'checking'; item.message = error.message; }
    }
    async resume() {
        if (this.running || this.batchId || this.items.length) { this.status('Finish this queue before reviewing another batch.'); return; }
        const id = sessionStorage.getItem('catalog-media-last-upload'); if (!id) return;
        try {
            let cursor = 0; const items = [];
            do {
                const batch = await MediaApi.request('batches', { query: { id, cursor } });
                items.push(...batch.items); cursor = batch.nextCursor;
            } while (cursor !== null);
            this.batchId = id;
            this.items = items.map((item) => ({
                id: item.clientItemId, file: { name: item.filename, size: item.sizeBytes }, missingFile: true,
                state: item.status === 'queued' ? 'failed' : item.status,
                progress: item.status === 'ready' ? 100 : 0, asset: item.result?.asset, duplicates: item.result?.duplicates,
                message: item.status === 'queued' ? 'Reselect the original file to continue this pending upload.' : item.result?.message || item.status.replaceAll('_', ' '),
            }));
            this.render();
        } catch (error) { this.status(error.message); }
    }
    async cancel() {
        const pending = this.items.filter((item) => ['queued','failed','duplicate_candidate'].includes(item.state));
        pending.forEach((item) => { item.state = 'cancelled'; });
        this.items.filter((item) => item.state === 'uploading').forEach((item) => { item.cancelRequested = true; item.xhr?.abort(); });
        if (this.batchId && pending.length) {
            try { await MediaApi.request('batch-cancel', { method: 'POST', data: { batchId: this.batchId, itemIds: pending.map((item) => item.id) } }); }
            catch (error) { this.status(error.message); }
        }
        this.render();
    }
}
