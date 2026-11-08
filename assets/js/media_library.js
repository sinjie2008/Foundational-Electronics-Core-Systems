import { MediaApi, escapeHtml as e, formatSize } from './media_api.js';
import { MediaUploadQueue } from './media_upload_queue.js';
import { MediaMapping } from './media_mapping.js';

export class MediaLibraryPage {
    constructor() {
        this.query = { page: 1, perPage: 24 };
        this.items = []; this.selected = new Map(); this.objectUrls = new Set(); this.cardUrls = new Set(); this.thumbnailObservers = new Set(); this.requestId = 0;
        this.$ = (id) => document.getElementById(id);
        this.bind(); this.connect();
    }
    message(text, error = false) {
        this.$('media-status').textContent = text;
        this.$('media-status').classList.toggle('is-error', error);
    }
    can(permission) { return this.capabilities?.permissions?.some((value) => value === '*' || value === permission); }
    async connect() {
        const connection = this.connectionId = (this.connectionId || 0) + 1;
        this.$('media-retry').hidden = true;
        this.message('Loading Media Library…');
        try {
            this.capabilities = await MediaApi.request('capabilities');
            if (connection !== this.connectionId) return;
            this.$('media-workspace').hidden = false;
            this.$('media-upload').hidden = !this.can('upload'); this.$('media-index').hidden = !this.can('index');
            this.$('media-csv-map').disabled = !this.can('attach');
            this.message('');
            await Promise.all([this.load(), this.folders()]);
            const id = new URLSearchParams(location.search).get('mediaId');
            if (id) await this.details(id);
        } catch (error) {
            if (connection !== this.connectionId) return;
            this.$('media-workspace').hidden = true;
            this.$('media-upload').hidden = true; this.$('media-index').hidden = true;
            this.$('media-retry').hidden = false;
            this.message(error.message, !['MEDIA_DISABLED','MEDIA_NOT_INSTALLED'].includes(error.code));
        }
    }
    bind() {
        this.$('media-retry').addEventListener('click', () => this.connect());
        this.$('media-folder-search').addEventListener('input', () => this.renderFolders());
        this.$('media-folder-search').addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && event.target.value) {
                event.preventDefault(); event.stopPropagation(); this.clearFolderSearch();
            }
        });
        this.$('media-search-form').addEventListener('submit', (event) => { event.preventDefault(); this.query.q = this.$('media-search').value; this.query.page = 1; this.load(); });
        this.$('media-type').addEventListener('change', () => { this.query.type = this.$('media-type').value; this.query.page = 1; this.load(); });
        this.$('media-status-filter').addEventListener('change', () => { this.query.status = this.$('media-status-filter').value; this.query.page = 1; this.load(); });
        this.$('media-sort').addEventListener('change', () => { this.query.sort = this.$('media-sort').value; this.query.direction = this.query.sort === 'filename' ? 'asc' : 'desc'; this.load(); });
        this.$('media-previous').addEventListener('click', () => { this.query.page--; this.load(); });
        this.$('media-next').addEventListener('click', () => { this.query.page++; this.load(); });
        this.$('media-view-toggle').addEventListener('click', () => {
            const list = this.$('media-files').classList.toggle('is-list'); this.$('media-view-toggle').textContent = list ? 'Grid view' : 'List view';
        });
        this.$('media-clear-filters').addEventListener('click', () => { this.query = { page: 1, perPage: 24 }; this.clearFolderSearch(); this.syncControls(); this.$('media-breadcrumb').textContent = 'All folders'; this.load(); });
        this.$('media-select-page').addEventListener('change', (event) => {
            this.items.filter((asset) => asset.status === 'ready').forEach((asset) => { if (event.target.checked) this.selected.set(asset.id, asset); else this.selected.delete(asset.id); });
            this.renderFiles(); this.selection();
        });
        this.$('media-clear-selection').addEventListener('click', () => { this.selected.clear(); this.renderFiles(); this.selection(); });
        this.$('media-folders-open').addEventListener('click', () => this.openPanel('media-folders', this.$('media-folders-open')));
        document.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', () => this.closePanel()));
        document.addEventListener('keydown', (event) => {
            if (!this.activePanel || document.querySelector('dialog[open]')) return;
            if (event.key === 'Escape') { event.preventDefault(); this.closePanel(); }
            if (event.key === 'Tab') {
                const controls = [...this.activePanel.querySelectorAll('button:not(:disabled),a[href],input:not(:disabled),select:not(:disabled),[tabindex="0"]')].filter((el) => el.getClientRects().length);
                const first = controls[0], last = controls.at(-1);
                if (!first) { event.preventDefault(); this.activePanel.focus(); }
                else if (event.shiftKey && (document.activeElement === first || document.activeElement === this.activePanel)) { event.preventDefault(); last.focus(); }
                else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        window.addEventListener('resize', () => { if (this.activePanel && getComputedStyle(this.activePanel).position !== 'fixed') this.closePanel(); });
        this.$('media-folders').addEventListener('click', (event) => {
            const button = event.target.closest('button'); if (!button) return;
            if (button.hasAttribute('data-all-folders')) {
                this.clearFolderSearch();
                delete this.query.categoryId; delete this.query.seriesId; delete this.query.fieldId; delete this.query.fieldScope;
                this.query.page = 1;
            } else if (button.dataset.node) {
                delete this.query.categoryId; delete this.query.seriesId; delete this.query.fieldId; delete this.query.fieldScope;
                this.query[button.dataset.kind === 'category' ? 'categoryId' : 'seriesId'] = button.dataset.node;
                if (button.dataset.field) { this.query.fieldId = button.dataset.field; this.query.fieldScope = button.dataset.fieldScope; }
                this.query.page = 1;
            } else return;
            this.syncControls();
            this.$('media-breadcrumb').textContent = button.textContent;
            if (this.activePanel?.id === 'media-folders') this.closePanel();
            this.load();
        });
        this.$('media-upload').addEventListener('click', () => new MediaUploadQueue(this.capabilities, async () => { await this.load(); }, (assets) => new MediaMapping(assets, this.capabilities, () => this.load())));
        this.$('media-map').addEventListener('click', () => new MediaMapping([...this.selected.values()], this.capabilities, () => this.load()));
        this.$('media-csv-map').addEventListener('click', () => new MediaMapping([], this.capabilities, () => this.load(), true));
        this.$('media-index').addEventListener('click', () => this.index());
        this.$('media-index-report')?.addEventListener('click', () => this.downloadIndexReport());
        this.$('media-review-mapping')?.addEventListener('click', () => {
            const id = sessionStorage.getItem('catalog-media-last-mapping');
            if (id) MediaMapping.review(id, this.capabilities, () => this.load());
            else this.message('There is no previous mapping batch in this tab.');
        });
    }
    async load() {
        const request = ++this.requestId;
        this.$('media-files').setAttribute('aria-busy', 'true');
        this.message('Loading files…');
        try {
            const data = await MediaApi.request('list', { query: this.query });
            if (request !== this.requestId) return;
            this.items = data.items; this.query.page = data.pagination.page;
            this.renderFiles(); this.selection();
            this.$('media-page-label').textContent = 'Page ' + data.pagination.page + ' of ' + data.pagination.lastPage + ' · ' + data.pagination.total + ' files';
            this.$('media-previous').disabled = data.pagination.page <= 1; this.$('media-next').disabled = data.pagination.page >= data.pagination.lastPage;
            this.message('');
        } catch (error) { if (request === this.requestId) this.message(error.message, true); }
        finally { if (request === this.requestId) this.$('media-files').setAttribute('aria-busy', 'false'); }
    }
    selection() {
        this.$('media-selection-count').textContent = this.selected.size + ' selected across pages';
        this.$('media-map').disabled = this.selected.size === 0 || !this.can('attach');
        const ready = this.items.filter((asset) => asset.status === 'ready');
        this.$('media-select-page').checked = ready.length > 0 && ready.every((asset) => this.selected.has(asset.id));
    }
    renderFiles() {
        this.thumbnailObservers.forEach((observer) => observer.disconnect()); this.thumbnailObservers.clear();
        this.cardUrls.forEach((url) => URL.revokeObjectURL(url)); this.cardUrls.clear();
        const container = this.$('media-files'); container.replaceChildren();
        if (!this.items.length) {
            container.innerHTML = '<div class="media-empty"><h2>No files here yet</h2><p>Clear your filters, upload files, or ask an administrator to check existing files.</p></div>';
            return;
        }
        this.items.forEach((asset) => {
            const card = document.createElement('article'); card.className = 'media-card';
            card.innerHTML = '<label class="media-card-check"><input type="checkbox" aria-label="Select ' + e(asset.filename) + '" ' + (this.selected.has(asset.id) ? 'checked' : '') + (asset.status !== 'ready' ? ' disabled' : '') + '></label>' +
                '<button type="button" class="media-card-open"><span class="media-thumbnail"><span class="media-file-icon" aria-hidden="true">' + (asset.mimeType.startsWith('image/') ? 'IMG' : asset.mimeType === 'application/pdf' ? 'PDF' : asset.mimeType === 'model/gltf-binary' ? 'GLB' : 'FILE') + '</span></span><span class="media-card-copy"><strong>' + e(asset.filename) + '</strong><small>' + e(formatSize(asset.sizeBytes)) + ' · ' + asset.referenceCount + ' uses</small><small>' + (asset.uploadedAt ? e(asset.uploadedAt + ' UTC') : 'Upload date unknown') + '</small><span class="media-badge">' + e(asset.status === 'ready' && !asset.confirmedReferenceCount ? 'Not linked to Catalog' : asset.status.replaceAll('_', ' ')) + '</span></span></button>';
            card.querySelector('input').addEventListener('change', (event) => { if (event.target.checked) this.selected.set(asset.id, asset); else this.selected.delete(asset.id); this.selection(); });
            card.querySelector('button').addEventListener('click', () => this.details(asset.id));
            container.append(card);
            if (asset.mimeType.startsWith('image/') && asset.previewUrl && asset.sizeBytes < 2097152 && asset.mimeType !== 'image/svg+xml') {
                const observer = new IntersectionObserver(async (entries) => {
                    if (!entries.some((entry) => entry.isIntersecting)) return;
                    observer.disconnect();
                    this.thumbnailObservers.delete(observer);
                    try {
                        const url = URL.createObjectURL(await MediaApi.blob(asset.previewUrl)); this.cardUrls.add(url);
                        if (!card.isConnected) { URL.revokeObjectURL(url); this.cardUrls.delete(url); return; }
                        const img = document.createElement('img'); img.src = url; img.alt = ''; img.loading = 'lazy'; card.querySelector('.media-thumbnail').replaceChildren(img);
                    } catch { /* Keep the type icon if a thumbnail cannot be loaded. */ }
                });
                this.thumbnailObservers.add(observer);
                observer.observe(card);
            }
        });
    }
    async folders() {
        try {
            this.folderData = await MediaApi.request('folders');
            this.renderFolders();
        } catch (error) { this.$('media-catalog-folders').textContent = error.message; }
    }
    clearFolderSearch() {
        this.$('media-folder-search').value = '';
        this.renderFolders();
    }
    renderFolders() {
        if (!this.folderData) return;
        const container = this.$('media-catalog-folders');
        const query = this.$('media-folder-search').value.trim().toLocaleLowerCase();
        const searching = query.length > 0;
        if (!this.folderSearchActive) {
            this.folderExpanded = new Set([...container.querySelectorAll('details[open][data-folder-branch]')].map((branch) => Number(branch.dataset.folderBranch)));
        }
        const matches = (value) => String(value ?? '').toLocaleLowerCase().includes(query);
        const children = new Map(), fields = new Map();
        this.folderData.nodes.forEach((node) => { const key = Number(node.parentId || 0); if (!children.has(key)) children.set(key, []); children.get(key).push(node); });
        this.folderData.fields.forEach((field) => { const key = Number(field.seriesId); if (!fields.has(key)) fields.set(key, []); fields.get(key).push(field); });
        const seen = new Set();
        let count = 0;
        const render = (parent, inheritedMatch = false) => (children.get(parent) || []).map((node) => {
            const id = Number(node.id); if (seen.has(id)) return ''; seen.add(id);
            const ownMatch = searching && matches(node.name);
            const includeBranch = !searching || inheritedMatch || ownMatch;
            if (ownMatch) count++;
            const nested = render(id, includeBranch) + (fields.get(id) || []).map((field) => {
                const fieldMatch = searching && (matches(field.label) || matches(field.fieldKey));
                if (!includeBranch && !fieldMatch) return '';
                if (fieldMatch) count++;
                return '<button' + (fieldMatch ? ' class="media-folder-match"' : '') + ' data-node="' + id + '" data-kind="series" data-field="' + Number(field.id) + '" data-field-scope="' + e(field.fieldScope) + '">↳ ' + e(field.label || field.fieldKey) + '</button>';
            }).join('');
            if (!includeBranch && !nested) return '';
            const matchClass = ownMatch ? ' class="media-folder-match"' : '';
            const button = includeBranch ? '<button' + matchClass + ' data-node="' + id + '" data-kind="' + e(node.type) + '">' + e(node.name) + '</button>' : '';
            return nested ? '<details data-folder-branch="' + id + '"' + (searching || this.folderExpanded.has(id) ? ' open' : '') + '><summary' + matchClass + '>' + e(node.name) + '</summary>' + button + nested + '</details>' : button;
        }).join('');
        container.innerHTML = render(0) || (searching ? '' : '<p class="small text-muted">No Catalog folders.</p>');
        const status = this.$('media-folder-search-status');
        status.hidden = !searching;
        status.textContent = searching ? (count ? count + (count === 1 ? ' match' : ' matches') : 'No matches. Try another name.') : '';
        this.folderSearchActive = searching;
    }
    async details(id) {
        const request = this.detailRequestId = (this.detailRequestId || 0) + 1;
        this.activeId = id;
        if (this.detailUrl) { URL.revokeObjectURL(this.detailUrl); this.objectUrls.delete(this.detailUrl); this.detailUrl = null; }
        this.openPanel('media-details');
        const content = this.$('media-detail-content'); content.textContent = 'Loading file details…';
        try {
            const asset = await MediaApi.request('details', { query: { id } });
            if (this.activeId !== id || request !== this.detailRequestId) return;
            this.activeAsset = asset;
            const physicalLocation = asset.absolutePath || asset.storageLocation;
            content.innerHTML = '<div class="media-preview">Preview unavailable</div><h2>' + e(asset.filename) + '</h2><dl>' +
                '<dt>Type</dt><dd>' + e(asset.mimeType) + '</dd><dt>Size</dt><dd>' + e(formatSize(asset.sizeBytes)) + ' (' + asset.sizeBytes + ' bytes)</dd>' +
                '<dt>Uploaded</dt><dd>' + e(asset.uploadedAt || 'Unknown — discovered existing file') + '</dd><dt>Physical storage location</dt><dd>' + e(physicalLocation) + '</dd>' +
                '<dt>Source</dt><dd>' + e(asset.sourceKind) + '</dd><dt>Catalog references</dt><dd>' + asset.referenceCount + ' (' + asset.confirmedReferenceCount + ' confirmed)</dd></dl>' +
                '<div class="media-details-actions"><button class="btn btn-sm btn-outline-secondary" data-copy="path">Copy path</button><button class="btn btn-sm btn-outline-secondary" data-copy="url">Copy media URL</button><button class="btn btn-sm btn-primary" data-download>Download</button></div>' +
                '<div class="media-details-actions"><button class="btn btn-sm btn-outline-danger" data-trash ' + (asset.referenceCount || !this.can('trash') ? 'disabled' : '') + '>' + (asset.status === 'trashed' ? 'Restore file' : 'Move to Trash') + '</button></div><h2>Where it is used</h2><div class="media-usages"></div>';
            content.querySelector('[data-download]').disabled = !['ready','unsupported'].includes(asset.status);
            content.querySelector('[data-download]').addEventListener('click', () => MediaApi.download(asset).catch((error) => this.message(error.message, true)));
            content.querySelectorAll('[data-copy]').forEach((button) => button.addEventListener('click', async () => {
                try { await navigator.clipboard.writeText(button.dataset.copy === 'path' ? physicalLocation : new URL(asset.publicUrl || asset.downloadUrl, window.location.href).href); this.message('Copied.'); }
                catch { this.message('Copy is unavailable in this browser. Select and copy the text instead.', true); }
            }));
            content.querySelector('[data-trash]').addEventListener('click', async () => {
                const restore = asset.status === 'trashed';
                const reason = restore ? '' : window.prompt('Reason for moving this unused file to Trash. Existing public URLs are retained.');
                if (!restore && !reason) return;
                try { await MediaApi.request(restore ? 'restore' : 'asset', { method: restore ? 'POST' : 'DELETE', data: { id, expectedVersion: asset.version, reason } }); await this.details(id); await this.load(); }
                catch (error) { this.message(error.message, true); }
            });
            const usages = content.querySelector('.media-usages');
            if (!asset.relationships.length) usages.textContent = 'No confirmed Catalog links. Directory names alone do not establish a relationship. External webpage usage may still exist.';
            if (asset.generationContext) {
                const context = document.createElement('div'); context.className = 'media-usage';
                context.innerHTML = '<strong>Generated PDF</strong><div>' + e(asset.generationContext.category?.name || '') + '</div><div>' + e(asset.generationContext.series.name) + '</div><p>Generated for Series ID ' + asset.generationContext.series.id + '. A latest-template attachment is tracked separately.</p><a href="' + e(asset.generationContext.catalogUrl) + '">Open Catalog record</a>';
                usages.insertAdjacentElement('beforebegin', context);
            }
            const renderUsage = (usage) => {
                const div = document.createElement('div'); div.className = 'media-usage';
                div.innerHTML = '<strong>' + e(usage.category?.name || usage.owner_type) + '</strong><div>' + e(usage.series?.name || '') + '</div><div>' + e(usage.productSku || '') + '</div><div>' + e(usage.field?.label || usage.field || usage.field_scope) + '</div><div>Record ID: ' + Number(usage.owner_id) + ' · Field ID: ' + Number(usage.field_id) + '</div>';
                if (usage.relationship_status !== 'linked') {
                    const warning = document.createElement('p'); warning.className = 'text-danger'; warning.textContent = 'This reference has an invalid or missing Catalog target. Review it before reassignment.'; div.append(warning);
                }
                if (usage.effectiveRecordCount !== undefined) {
                    const hint = document.createElement('p'); hint.textContent = 'Default fallback affects ' + usage.effectiveRecordCount + ' records in the Public Product API.'; div.append(hint);
                }
                if (usage.catalogUrl) { const link = document.createElement('a'); link.href = usage.catalogUrl; link.textContent = 'Open Catalog record'; div.append(link); }
                if (this.can('attach') && ['product','series','typst_variable'].includes(usage.owner_type)) {
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-sm btn-link'; button.textContent = 'Detach';
                    button.addEventListener('click', async () => {
                        if (!window.confirm('Clear this Catalog attachment? The file will be retained.')) return;
                        const target = { type: usage.owner_type, id: Number(usage.owner_id), fieldId: Number(usage.field_id), fieldScope: usage.field_scope };
                        try { const state = await MediaApi.request('targets', { query: target }); await MediaApi.request('detach', { method: 'POST', data: { target, expectedVersion: state.target.version } }); await this.details(id); await this.load(); }
                        catch (error) { this.message(error.message, true); }
                    }); div.append(button);
                }
                usages.append(div);
            };
            asset.relationships.forEach(renderUsage);
            if (asset.relationshipPagination.lastPage > 1) {
                let page = 1;
                const more = document.createElement('button'); more.type = 'button'; more.className = 'btn btn-outline-secondary'; more.textContent = 'More usages';
                more.addEventListener('click', async () => {
                    more.disabled = true;
                    try {
                        const result = await MediaApi.request('relationships', { query: { mediaId: id, page: page + 1, perPage: 100 } });
                        if (this.activeId !== id || request !== this.detailRequestId) return;
                        result.items.forEach(renderUsage); page = result.pagination.page; more.hidden = page >= result.pagination.lastPage;
                    } catch (error) { this.message(error.message, true); }
                    finally { more.disabled = false; }
                }); usages.insertAdjacentElement('afterend', more);
            }
            if (asset.previewUrl && ['ready'].includes(asset.status) && (asset.mimeType.startsWith('image/') || asset.mimeType === 'application/pdf')) {
                try {
                    const url = URL.createObjectURL(await MediaApi.blob(asset.previewUrl)); this.objectUrls.add(url);
                    if (this.activeId !== id || request !== this.detailRequestId || !content.isConnected) { URL.revokeObjectURL(url); this.objectUrls.delete(url); return; }
                    this.detailUrl = url;
                    const preview = document.createElement(asset.mimeType === 'application/pdf' ? 'iframe' : 'img');
                    preview.src = url;
                    if (preview.tagName === 'IFRAME') { preview.title = 'PDF preview'; preview.setAttribute('sandbox', ''); } else preview.alt = asset.filename;
                    content.querySelector('.media-preview').replaceChildren(preview);
                    if (asset.mimeType === 'application/pdf') {
                        const link = document.createElement('a'); link.href = url; link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'Open PDF preview in a new tab'; content.querySelector('.media-preview').insertAdjacentElement('afterend', link);
                    }
                } catch (error) { content.querySelector('.media-preview').textContent = error.message; }
            }
        } catch (error) { if (this.activeId === id && request === this.detailRequestId) content.textContent = error.message; }
    }
    async index() {
        const button = this.$('media-index'); button.disabled = true;
        let cursor = 0, phase = 'disk', count = 0, issues = 0;
        this.lastIndexReport = [];
        try {
            do {
                const result = await MediaApi.request('index', { method: 'POST', data: { cursor, phase } });
                count += result.items.length; issues += result.issues.length;
                this.lastIndexReport.push(...result.items.map((item) => ({ phase, ...item })), ...result.issues.map((item) => ({ phase, ...item })));
                if (this.$('media-index-report')) this.$('media-index-report').hidden = false;
                this.message('Checking existing files… ' + count + ' inspections · ' + issues + ' reference issues');
                cursor = result.nextCursor; phase = result.nextPhase;
            } while (phase !== null);
            await Promise.all([this.load(), this.folders()]);
            this.message('Existing-file check completed. ' + issues + ' reference issues; use Missing files or Needs integrity review to inspect them.');
        } catch (error) { this.message(error.message, true); }
        finally { button.disabled = false; }
    }
    downloadIndexReport() {
        const cell = (value) => {
            let text = String(value ?? ''); if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
            return '"' + text.replaceAll('"', '""') + '"';
        };
        const rows = [['stage','file_or_reference','status','media_id'], ...(this.lastIndexReport || []).map((row) => [row.phase,row.path || row.slot,row.status,row.mediaId])];
        const blob = new Blob(['\uFEFF' + rows.map((row) => row.map(cell).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob), link = document.createElement('a'); link.href = url; link.download = 'media-discovery-report.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    syncControls() {
        this.$('media-search').value = this.query.q || '';
        this.$('media-type').value = this.query.type || '';
        this.$('media-status-filter').value = this.query.status || '';
        this.$('media-sort').value = this.query.sort || 'uploadedAt';
    }
    openPanel(id, invoker = document.activeElement) {
        const panel = this.$(id);
        if (this.activePanel === panel) return;
        this.closePanel(false);
        panel.classList.add('is-open');
        if (getComputedStyle(panel).position !== 'fixed') { panel.classList.remove('is-open'); return; }
        this.panelInvoker = invoker; this.activePanel = panel; this.inertStates = [];
        panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-modal', 'true'); panel.tabIndex = -1;
        let child = panel;
        while (child.parentElement) {
            [...child.parentElement.children].filter((sibling) => sibling !== child).forEach((sibling) => {
                this.inertStates.push([sibling, sibling.inert]); sibling.inert = true;
            });
            if (child.parentElement === document.body) break;
            child = child.parentElement;
        }
        (panel.querySelector('button') || panel).focus();
    }
    closePanel(restoreFocus = true) {
        if (!this.activePanel) return;
        const panel = this.activePanel; this.activePanel = null;
        panel.classList.remove('is-open'); panel.removeAttribute('role'); panel.removeAttribute('aria-modal'); panel.removeAttribute('tabindex');
        (this.inertStates || []).forEach(([element, inert]) => { element.inert = inert; }); this.inertStates = [];
        if (restoreFocus && this.panelInvoker?.isConnected) this.panelInvoker.focus();
    }
    releaseUrls() {
        this.objectUrls.forEach((url) => URL.revokeObjectURL(url)); this.objectUrls.clear();
        this.cardUrls.forEach((url) => URL.revokeObjectURL(url)); this.cardUrls.clear();
        this.thumbnailObservers.forEach((observer) => observer.disconnect()); this.thumbnailObservers.clear();
    }
}
new MediaLibraryPage();
