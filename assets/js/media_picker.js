import { MediaApi, escapeHtml, formatSize } from './media_api.js';

/** A reusable picker changes only the form draft; its original Save button persists it. */
export class MediaPicker {
    static selected = new WeakMap();

    static get(input) { return input ? this.selected.get(input) : undefined; }
    static selections(container, attribute) {
        const output = {};
        container?.querySelectorAll('input[' + attribute + ']').forEach((input) => {
            const asset = this.get(input);
            if (asset) output[input.getAttribute(attribute)] = { mediaId: asset.id };
        });
        return output;
    }
    static async pick({ imageOnly = false } = {}) {
        await MediaApi.request('capabilities');
        return new Promise((resolve) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'media-picker-dialog';
            dialog.innerHTML = '<form method="dialog" class="media-picker-heading"><h2>Select from Media Library</h2><button class="btn btn-outline-secondary" aria-label="Close media picker">Close</button></form>' +
                '<div class="media-picker-search"><label>Search files<input type="search" class="form-control"></label><button type="button" class="btn btn-outline-primary">Search</button></div>' +
                '<p role="status" aria-live="polite"></p><div class="media-picker-results"></div><div class="media-picker-pagination"><button type="button" data-page="-1" class="btn btn-outline-secondary">Previous</button><span></span><button type="button" data-page="1" class="btn btn-outline-secondary">Next</button></div>';
            document.body.append(dialog);
            const previousFocus = document.activeElement;
            let page = 1, lastPage = 1, chosen = null, requestId = 0;
            const status = dialog.querySelector('[role=status]');
            const results = dialog.querySelector('.media-picker-results');
            const load = async () => {
                const request = ++requestId;
                status.textContent = 'Loading files…';
                try {
                    const data = await MediaApi.request('list', { query: { q: dialog.querySelector('input').value, type: imageOnly ? 'image' : '', status: 'ready', page, perPage: 24 } });
                    if (request !== requestId || !dialog.open) return;
                    results.replaceChildren();
                    data.items.forEach((asset) => {
                        const button = document.createElement('button');
                        button.type = 'button'; button.className = 'media-picker-card';
                        button.innerHTML = '<span class="media-file-icon" aria-hidden="true">' + (asset.mimeType.startsWith('image/') ? 'IMG' : asset.mimeType === 'application/pdf' ? 'PDF' : '3D') + '</span><strong>' + escapeHtml(asset.filename) + '</strong><span>' + escapeHtml(formatSize(asset.sizeBytes)) + ' · ' + asset.referenceCount + ' uses</span>';
                        button.addEventListener('click', () => { chosen = asset; dialog.close(); });
                        results.append(button);
                    });
                    lastPage = data.pagination.lastPage;
                    dialog.querySelector('.media-picker-pagination span').textContent = page + ' / ' + lastPage;
                    dialog.querySelector('[data-page="-1"]').disabled = page <= 1;
                    dialog.querySelector('[data-page="1"]').disabled = page >= lastPage;
                    status.textContent = data.items.length ? 'Choose a file. Your form is saved only when you press Save.' : 'No matching files. Try another search or upload a file first.';
                } catch (error) { status.textContent = error.message; }
            };
            dialog.addEventListener('close', () => { dialog.remove(); previousFocus?.focus(); resolve(chosen); }, { once: true });
            dialog.querySelector('.media-picker-search button').addEventListener('click', () => { page = 1; load(); });
            dialog.querySelector('input').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); page = 1; load(); } });
            dialog.querySelectorAll('[data-page]').forEach((button) => button.addEventListener('click', () => { page += Number(button.dataset.page); load(); }));
            dialog.showModal(); load();
        });
    }

    static enhance(root = document) {
        const enhance = () => {
            root.querySelectorAll('input[type=file][data-field-file],input[type=file][data-metadata-file],#category-field-file,#varData[type=file]').forEach((input) => {
                if (input.dataset.libraryEnhanced) return;
                input.dataset.libraryEnhanced = '1';
                const button = document.createElement('button');
                button.type = 'button'; button.className = 'btn btn-sm btn-outline-primary media-picker-trigger';
                button.textContent = 'Select from Media Library';
                const note = document.createElement('div');
                note.className = 'small media-draft-note'; note.setAttribute('aria-live', 'polite');
                input.insertAdjacentElement('afterend', button); button.insertAdjacentElement('afterend', note);
                const clear = () => { this.selected.delete(input); note.replaceChildren(); };
                input.addEventListener('change', clear);
                input.closest('.file-controls')?.querySelector('input[type=checkbox]')?.addEventListener('change', clear);
                if (input.id === 'category-field-file') document.getElementById('category-field-clear-file')?.addEventListener('change', clear);
                input.form?.addEventListener('reset', clear);
                button.addEventListener('click', async () => {
                    button.disabled = true;
                    try {
                        const asset = await this.pick({ imageOnly: input.id === 'category-field-file' || input.id === 'varData' });
                        if (!asset || !input.isConnected) return;
                        this.selected.set(input, asset); input.value = '';
                        input.closest('.file-controls')?.querySelectorAll('input[type=checkbox]').forEach((box) => { box.checked = false; });
                        if (input.id === 'category-field-file') {
                            const box = document.getElementById('category-field-clear-file'); if (box) box.checked = false;
                        }
                        note.textContent = 'Selected: ' + asset.filename + ' — press Save to apply. ';
                        const link = document.createElement('a');
                        link.href = 'media_library.html?mediaId=' + encodeURIComponent(asset.id);
                        link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'Media details'; note.append(link);
                    } catch (error) { note.textContent = error.message; }
                    finally { button.disabled = false; }
                });
            });
        };
        enhance();
        const observer = new MutationObserver(enhance);
        observer.observe(root === document ? document.body : root, { childList: true, subtree: true });
        return observer;
    }
}
