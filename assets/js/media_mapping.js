import { MediaApi, escapeHtml as e, operationKey } from './media_api.js';

/** Manual and CSV mapping share the server's preview, concurrency and execution rules. */
export class MediaMapping {
    constructor(assets, capabilities, onComplete, csvMode = false) {
        this.assets = assets; this.capabilities = capabilities; this.onComplete = onComplete; this.editGeneration = 0; this.previewGeneration = 0;
        this.rows = assets.map((asset, index) => ({ rowId: 'row_' + (index + 1), mediaId: asset.id, filename: asset.filename, type: 'series', id: '', fieldId: '', onExisting: 'skip' }));
        this.dialog = document.createElement('dialog'); this.dialog.className = 'media-workflow-dialog media-library-page';
        this.dialog.innerHTML = '<div class="media-dialog-heading"><h2>Map files to Catalog records</h2><button class="btn btn-outline-secondary" data-close>Close</button></div>' +
            '<p>Choose the record and its existing file field. Preview every change before applying it. Replacing an attachment retains its old file.</p>' +
            '<div class="media-toolbar"><button class="btn btn-outline-secondary" data-template>Download CSV template</button><label>Map with CSV<input type="file" accept=".csv,text/csv" class="form-control" data-csv></label><button class="btn btn-outline-primary" data-validate-csv>Preview CSV</button></div>' +
            '<section class="media-panel" data-common ' + (csvMode ? 'hidden' : '') + '><h2>Set the same target for several files</h2><div class="media-toolbar">' +
            '<label>Target type<select class="form-select" data-common-type><option value="series">Series</option><option value="product">Product SKU</option><option value="category">Category image</option></select></label>' +
            '<label>Find a record<input class="form-control" data-common-search placeholder="Name or SKU"></label><button class="btn btn-outline-secondary" data-search>Search</button>' +
            '<label>Record<select class="form-select" data-common-target><option value="">Choose a record</option></select></label><label>Field<select class="form-select" data-common-field><option value="">Choose a field</option></select></label><button class="btn btn-outline-primary" data-set-common>Set for all rows</button></div></section>' +
            '<div class="media-mapping-scroll" data-manual ' + (csvMode ? 'hidden' : '') + '><table class="media-mapping-table"><thead><tr><th>File</th><th>Record</th><th>Field</th><th>If already attached</th></tr></thead><tbody data-rows></tbody></table></div>' +
            '<div class="media-toolbar"><button class="btn btn-primary" data-validate ' + (csvMode ? 'hidden' : '') + '>Preview mapping</button></div>' +
            '<p role="status" aria-live="polite"></p><div class="media-mapping-scroll" data-preview></div>' +
            '<div class="media-toolbar"><label>Apply mode<select class="form-select" data-mode><option value="atomic">All rows together</option><option value="valid_rows">Only valid rows, in groups of 25</option></select></label><button class="btn btn-primary" data-apply disabled>Apply reviewed mapping</button><button class="btn btn-outline-secondary" data-report disabled>Download row report</button><button class="btn btn-outline-danger" data-rollback hidden>Undo this batch</button></div>';
        document.body.append(this.dialog); this.previousFocus = document.activeElement;
        this.$ = (selector) => this.dialog.querySelector(selector);
        this.$('[data-close]').addEventListener('click', () => { if (!this.executing) this.dialog.close(); });
        this.dialog.addEventListener('cancel', (event) => { if (this.executing) event.preventDefault(); });
        this.dialog.addEventListener('close', () => { this.dialog.remove(); this.previousFocus?.focus(); this.onComplete(); });
        this.$('[data-template]').addEventListener('click', () => this.template());
        this.$('[data-search]').addEventListener('click', () => this.commonTargets());
        this.$('[data-common-type]').addEventListener('change', () => this.commonTargets());
        this.$('[data-common-target]').addEventListener('change', () => this.commonFields());
        this.$('[data-set-common]').addEventListener('click', () => this.setCommon());
        this.$('[data-validate]').addEventListener('click', () => this.validate());
        this.$('[data-validate-csv]').addEventListener('click', () => this.validateCsv());
        this.$('[data-csv]').addEventListener('change', () => this.invalidate());
        this.$('[data-apply]').addEventListener('click', () => this.apply());
        this.$('[data-mode]').addEventListener('change', () => this.updateApply());
        this.$('[data-report]').addEventListener('click', () => this.report());
        this.$('[data-rollback]').addEventListener('click', () => this.rollback());
        this.dialog.showModal(); this.renderRows(); this.commonTargets();
    }
    status(text) { this.$('[role=status]').textContent = text; }
    invalidate() { this.editGeneration++; this.plan = null; this.$('[data-apply]').disabled = true; this.$('[data-preview]').replaceChildren(); }
    options(select, rows, placeholder, kind = 'target') {
        select.replaceChildren(); const empty = document.createElement('option'); empty.value = ''; empty.textContent = placeholder; select.append(empty);
        rows.forEach((row) => {
            const option = document.createElement('option'); option.value = row.id;
            option.textContent = kind === 'field' ? (row.label || row.fieldKey) : (row.sku ? row.sku + ' — ' : '') + row.name;
            select.append(option);
        });
    }
    async commonTargets() {
        const generation = this.commonTargetGeneration = (this.commonTargetGeneration || 0) + 1;
        try {
            const result = await MediaApi.request('targets', { query: { type: this.$('[data-common-type]').value, q: this.$('[data-common-search]').value } });
            if (generation !== this.commonTargetGeneration || !this.dialog.open) return;
            this.commonChoices = result.targets; this.options(this.$('[data-common-target]'), result.targets, 'Choose a record');
            this.options(this.$('[data-common-field]'), [], 'Choose a field');
        } catch (error) { this.status(error.message); }
    }
    async commonFields() {
        const id = this.$('[data-common-target]').value; if (!id) return;
        const generation = this.commonFieldGeneration = (this.commonFieldGeneration || 0) + 1;
        const type = this.$('[data-common-type]').value;
        try {
            const result = await MediaApi.request('targets', { query: { type, id } });
            if (generation !== this.commonFieldGeneration || id !== this.$('[data-common-target]').value || type !== this.$('[data-common-type]').value || !this.dialog.open) return;
            this.commonFieldChoices = result.fields; this.options(this.$('[data-common-field]'), result.fields, result.fields.length ? 'Choose a field' : 'No compatible file fields', 'field');
            if (!result.fields.length) this.status('This record has no compatible file field. Configure its field in Catalog Manager first.');
        } catch (error) { this.status(error.message); }
    }
    setCommon() {
        const type = this.$('[data-common-type]').value, id = this.$('[data-common-target]').value, fieldId = this.$('[data-common-field]').value;
        if (!id || !fieldId) { this.status('Choose both a record and an existing file field.'); return; }
        this.rows.forEach((row) => Object.assign(row, { type, id, fieldId, targets: this.commonChoices, fields: this.commonFieldChoices }));
        this.invalidate(); this.renderRows(); this.status('Target set for all rows. You can still adjust individual rows.');
    }
    renderRows() {
        const body = this.$('[data-rows]'); body.replaceChildren();
        this.rows.forEach((row) => {
            const tr = document.createElement('tr');
            tr.innerHTML = '<td data-label="File">' + e(row.filename) + '</td><td data-label="Record"><select class="form-select form-select-sm" data-type><option value="series">Series</option><option value="product">Product SKU</option><option value="category">Category image</option></select><div class="input-group input-group-sm mt-1"><input class="form-control" placeholder="Find name or SKU" aria-label="Find target for ' + e(row.filename) + '"><button class="btn btn-outline-secondary" data-find>Find</button></div><select class="form-select form-select-sm mt-1" data-target aria-label="Record for ' + e(row.filename) + '"></select></td>' +
                '<td data-label="Field"><select class="form-select form-select-sm" data-field aria-label="Field for ' + e(row.filename) + '"></select></td><td data-label="If already attached"><select class="form-select form-select-sm" data-policy><option value="skip">Keep current attachment</option><option value="replace">Replace attachment</option><option value="error">Report an error</option></select></td>';
            const type = tr.querySelector('[data-type]'), target = tr.querySelector('[data-target]'), field = tr.querySelector('[data-field]'), policy = tr.querySelector('[data-policy]');
            const add = document.createElement('button'); add.type = 'button'; add.className = 'btn btn-sm btn-link'; add.textContent = 'Add another target'; add.setAttribute('aria-label', 'Add another target for ' + row.filename);
            add.addEventListener('click', () => { this.rows.push({ ...row, rowId: 'row_' + operationKey(), id: '', fieldId: '' }); this.invalidate(); this.renderRows(); });
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-sm btn-link'; remove.textContent = 'Remove row'; remove.setAttribute('aria-label', 'Remove mapping row for ' + row.filename);
            remove.addEventListener('click', () => { this.rows = this.rows.filter((candidate) => candidate !== row); this.invalidate(); this.renderRows(); });
            tr.querySelector('td').append(add, remove);
            type.setAttribute('aria-label', 'Target type for ' + row.filename);
            policy.setAttribute('aria-label', 'Existing attachment policy for ' + row.filename);
            type.value = row.type; policy.value = row.onExisting;
            this.options(target, row.targets || [], 'Choose a record'); target.value = row.id;
            this.options(field, row.fields || [], 'Choose a field', 'field'); field.value = row.fieldId;
            const find = async () => {
                row.type = type.value; row.id = ''; row.fieldId = ''; this.invalidate();
                const generation = row.targetGeneration = (row.targetGeneration || 0) + 1;
                try {
                    const result = await MediaApi.request('targets', { query: { type: row.type, q: tr.querySelector('input').value } });
                    if (generation !== row.targetGeneration || !tr.isConnected) return;
                    row.targets = result.targets; this.options(target, result.targets, 'Choose a record'); this.options(field, [], 'Choose a field');
                } catch (error) { this.status(error.message); }
            };
            type.addEventListener('change', find); tr.querySelector('[data-find]').addEventListener('click', find);
            target.addEventListener('change', async () => {
                row.id = target.value; row.fieldId = ''; this.invalidate();
                if (!row.id) return;
                const ownerId = row.id, ownerType = row.type;
                try { const result = await MediaApi.request('targets', { query: { type: ownerType, id: ownerId } }); if (ownerId !== row.id || ownerType !== row.type || !tr.isConnected) return; row.fields = result.fields; this.options(field, result.fields, result.fields.length ? 'Choose a field' : 'No file fields', 'field'); }
                catch (error) { this.status(error.message); }
            });
            field.addEventListener('change', () => { row.fieldId = field.value; this.invalidate(); });
            policy.addEventListener('change', () => { row.onExisting = policy.value; this.invalidate(); });
            body.append(tr);
        });
    }
    async validate() {
        if (this.executing) return;
        this.invalidate();
        const edit = this.editGeneration, request = ++this.previewGeneration;
        try {
            this.status('Checking each mapping row…');
            const rows = this.rows.map((row) => ({ rowId: row.rowId, mediaId: row.mediaId, target: { type: row.type, id: Number(row.id), fieldId: Number(row.fieldId) }, onExisting: row.onExisting }));
            const plan = await MediaApi.request('mapping-validate', { method: 'POST', data: { rows, idempotencyKey: operationKey() } });
            if (edit === this.editGeneration && request === this.previewGeneration && this.dialog.open) this.showPlan(plan);
        } catch (error) { this.status(error.message); }
    }
    async validateCsv() {
        if (this.executing) return;
        const file = this.$('[data-csv]').files[0]; if (!file) { this.status('Choose a CSV file first.'); return; }
        this.invalidate();
        const edit = this.editGeneration, request = ++this.previewGeneration;
        try {
            this.status('Checking CSV targets, fields and current attachments…');
            const data = new FormData(); data.append('file', file); data.append('idempotencyKey', operationKey());
            const plan = await MediaApi.request('mapping-validate', { method: 'POST', data });
            if (edit === this.editGeneration && request === this.previewGeneration && this.dialog.open) this.showPlan(plan);
        } catch (error) { this.status(error.message); }
    }
    showPlan(plan) {
        this.reviewOnly = false;
        this.plan = plan; this.results = plan.rows;
        this.$('[data-apply]').textContent = 'Apply reviewed mapping';
        this.$('[data-rollback]').hidden = true;
        this.status(plan.summary.valid + ' valid rows · ' + plan.summary.invalid + ' invalid rows. No Catalog attachment has changed.');
        this.renderReport(plan.rows); this.$('[data-report]').disabled = false; this.updateApply();
    }
    renderReport(rows) {
        const original = new Map((this.plan?.rows || []).map((row) => [row.rowId, row]));
        rows = rows.map((row) => {
            const source = original.get(row.rowId) || {};
            return { ...source, ...row, ...(row.status === 'rolled_back' ? { before: source.after, after: source.before } : {}) };
        });
        this.results = rows;
        this.$('[data-preview]').innerHTML = '<table class="media-mapping-table"><thead><tr><th>Row / File</th><th>Target</th><th>Current → Proposed</th><th>Result</th></tr></thead><tbody>' + rows.map((row) =>
            '<tr><td data-label="Row / File">' + e(row.rowId) + '<br>' + e(row.filename || '') + '</td><td data-label="Target">' + e(row.targetLabel || row.target?.type || '') + '<br>' + e(row.fieldLabel || '') + '</td><td data-label="Current → Proposed">' + e(row.before ?? 'None') + '<br>→ ' + e(row.after ?? '') + '</td><td data-label="Result" class="' + (['invalid','failed','conflict'].includes(row.status) ? 'media-result-invalid' : '') + '">' + e(row.status) + '<br>' + e(row.code || '') + '<br>' + e(row.message || (row.warnings || []).join(' ')) + '</td></tr>'
        ).join('') + '</tbody></table>';
    }
    updateApply() {
        const atomic = this.$('[data-mode]').value === 'atomic';
        this.$('[data-apply]').disabled = this.reviewOnly || !this.plan || this.executing || (atomic && (this.plan.summary.invalid > 0 || this.plan.rows.length > this.capabilities.atomicMappingLimit)) || this.plan.summary.valid === 0;
    }
    async apply() {
        if (!this.plan || this.executing) return;
        const plan = this.plan;
        const mode = this.$('[data-mode]').value;
        if (!window.confirm(mode === 'atomic' ? 'Apply all reviewed attachment changes together?' : 'Apply only valid rows in separate groups? Invalid rows will remain unchanged.')) return;
        this.executing = true; this.previewGeneration++; this.setBusy(true); this.results = [];
        let cursor = Number(plan.resumeCursor || 0);
        const saveResume = () => {
            sessionStorage.setItem('catalog-media-last-mapping', plan.validationId);
            sessionStorage.setItem('catalog-media-mapping-' + plan.validationId, JSON.stringify({ validationId: plan.validationId, token: plan.token, mode, cursor }));
        };
        saveResume();
        try {
            do {
                const result = await MediaApi.request('mapping-apply', { method: 'POST', data: { validationId: plan.validationId, token: plan.token, mode, cursor } });
                this.results.push(...result.items); cursor = result.nextCursor; this.renderReport(this.results);
                saveResume();
                this.status(this.results.length + ' row results received. ' + (cursor === null ? 'Batch completed.' : 'Applying the next group…'));
                sessionStorage.setItem('catalog-media-last-mapping', result.batchId);
            } while (cursor !== null);
            this.$('[data-rollback]').hidden = !this.results.some((row) => row.status === 'applied');
            this.onComplete();
        } catch (error) { this.status(error.message + ' Completed groups remain recorded. Review this batch before retrying.'); }
        finally { this.executing = false; this.setBusy(false); this.$('[data-apply]').disabled = true; }
    }
    async rollback() {
        if (!this.plan || !window.confirm('Restore this batch’s previous attachments where they have not since changed? Files will be retained.')) return;
        this.executing = true;
        this.setBusy(true);
        try {
            const result = await MediaApi.request('mapping-rollback', { method: 'POST', data: { batchId: this.plan.validationId } });
            this.results = result.items; this.renderReport(this.results); this.status('Undo results are shown for each row. Conflicting later edits were retained.'); this.onComplete();
        } catch (error) { this.status(error.message); }
        finally { this.executing = false; this.setBusy(false); }
    }
    setBusy(busy) {
        if (busy) {
            this.disabledStates = [...this.dialog.querySelectorAll('input,select,button')].map((el) => [el, el.disabled]);
            this.disabledStates.forEach(([el]) => { el.disabled = true; });
        } else {
            (this.disabledStates || []).forEach(([el, disabled]) => { if (el.isConnected) el.disabled = disabled; });
            this.disabledStates = [];
        }
    }
    static async review(id, capabilities, onComplete) {
        const view = new MediaMapping([], capabilities, onComplete, true);
        view.$('h2').textContent = 'Review previous mapping batch';
        view.$('[data-validate-csv]').hidden = true; view.$('[data-csv]').closest('label').hidden = true;
        view.status('Loading recorded row results…');
        try {
            let cursor = 0, batch; const items = [];
            do { batch = await MediaApi.request('batches', { query: { id, cursor } }); items.push(...batch.items); cursor = batch.nextCursor; } while (cursor !== null);
            if (batch.kind !== 'mapping') throw new Error('This is not a mapping batch.');
            const results = new Map(items.map((item) => [item.clientItemId, item.result]));
            view.results = (batch.mappingRows || []).map((row) => ({ ...row, ...(results.get(row.rowId) || {}), status: results.get(row.rowId)?.status || (row.status === 'valid' ? 'pending' : row.status) }));
            view.renderReport(view.results); view.$('[data-report]').disabled = false;
            const saved = JSON.parse(sessionStorage.getItem('catalog-media-mapping-' + id) || 'null');
            view.plan = { validationId: id, token: saved?.token, rows: batch.mappingRows || [], summary: { valid: (batch.mappingRows || []).filter((row) => row.status === 'valid').length, invalid: (batch.mappingRows || []).filter((row) => row.status !== 'valid').length } };
            view.reviewOnly = true;
            view.$('[data-rollback]').hidden = !items.some((item) => item.status === 'applied');
            view.status('Recorded results are shown. Completed changes are retained. Later edits are protected during Undo.');
            if (saved?.token && saved.cursor !== null && batch.validationExpiresAt > Date.now() / 1000 && batch.status !== 'invalidated') {
                view.plan.resumeCursor = saved.cursor; view.$('[data-mode]').value = saved.mode; view.$('[data-apply]').disabled = false; view.$('[data-apply]').textContent = 'Resume reviewed batch';
                view.reviewOnly = false;
            }
            const unfinished = view.results.filter((row) => ['pending','failed'].includes(row.status));
            if (unfinished.length) {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline-primary'; button.textContent = 'Preview unfinished rows again';
                button.addEventListener('click', async () => {
                    if (view.executing) return;
                    try {
                        const rows = unfinished.map((row) => ({ rowId: row.rowId, mediaId: row.mediaId, target: row.target, onExisting: row.onExisting || 'skip' }));
                        const plan = await MediaApi.request('mapping-validate', { method: 'POST', data: { rows, idempotencyKey: operationKey() } });
                        view.showPlan(plan);
                    } catch (error) { view.status(error.message); }
                }); view.$('[data-apply]').insertAdjacentElement('afterend', button);
            }
        } catch (error) { view.status(error.message); }
        return view;
    }
    csvCell(value) {
        let text = String(value ?? '');
        if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
        return '"' + text.replaceAll('"', '""') + '"';
    }
    saveCsv(name, rows) {
        const blob = new Blob(['\uFEFF' + rows.map((row) => row.map((value) => this.csvCell(value)).join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob), link = document.createElement('a'); link.href = url; link.download = name; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    template() {
        const rows = [['row_id','media_id','filename','target_type','category_id','category_name','series_id','series_name','product_id','product_sku','field_scope','field_id','field_key','on_existing']];
        this.assets.forEach((asset, index) => rows.push(['row_' + (index + 1),asset.id,asset.filename,'series','','','','','','','series_metadata','','','skip']));
        if (rows.length === 1) rows.push(['row_1','','','series','','','','','','','series_metadata','','','skip']);
        this.saveCsv('media-mapping-template.csv', rows);
    }
    report() {
        const rows = [['row_id','filename','status','code','message','media_id','target','target_label','field_label','before','after']];
        (this.results || []).forEach((row) => rows.push([row.rowId,row.filename,row.status,row.code,row.message || (row.warnings || []).join(' '),row.mediaId,JSON.stringify(row.target || {}),row.targetLabel,row.fieldLabel,row.before,row.after]));
        this.saveCsv('media-mapping-report.csv', rows);
    }
}
