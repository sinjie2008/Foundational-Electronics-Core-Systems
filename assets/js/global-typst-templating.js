export class GlobalTypstTemplatePage {
    constructor() {
        this.state = {
            table: null,
            variables: [],
            selectedVarId: null,
            selectedVarValue: '',
            variablesTable: null,
            templates: [],
            selectedTemplateId: null,
            lineNumberUpdater: null,
            lastCaret: { start: 0, end: 0 },
        };
        for (const methodName of Object.getOwnPropertyNames(Object.getPrototypeOf(this))) {
            if (methodName !== 'constructor' && typeof this[methodName] === 'function') {
                this[methodName] = this[methodName].bind(this);
            }
        }
        document.addEventListener('DOMContentLoaded', this.init);
    }




    init() {
        const page = this;
        page.state.lineNumberUpdater = page.initLineNumberedEditor('latexSource');
        page.bindEditorCaretTracking();
        page.initVariablesTable();
        page.initDataTable();
        page.bindEvents();
        page.loadVariables();
        page.loadTemplates();
    }

    /**
     * Attach a simple line-number gutter to the Typst textarea.
     * Returns a callback to force-refresh numbers after programmatic value changes.
     */
    initLineNumberedEditor(textareaId) {
        const page = this;
        const textarea = document.getElementById(textareaId);
        if (!textarea || !textarea.parentElement) {
            return function () { };
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'line-numbered-editor';
        const parent = textarea.parentElement;
        parent.insertBefore(wrapper, textarea);

        const numbers = document.createElement('div');
        numbers.className = 'line-numbers';

        wrapper.appendChild(numbers);
        wrapper.appendChild(textarea);

        const render = () => {
            const lineCount = (textarea.value.match(/\n/g) || []).length + 1;
            const frag = document.createDocumentFragment();
            for (let i = 1; i <= lineCount; i += 1) {
                const line = document.createElement('div');
                line.textContent = i.toString();
                frag.appendChild(line);
            }
            numbers.innerHTML = '';
            numbers.appendChild(frag);
            numbers.scrollTop = textarea.scrollTop;
        };

        textarea.addEventListener('input', render);
        textarea.addEventListener('scroll', () => {
            numbers.scrollTop = textarea.scrollTop;
        });

        render();
        return render;
    }

    /**
     * Initialize the global variables DataTable (Field Key / Field Type / Field Data).
     */
    initVariablesTable() {
        const page = this;
        const tableEl = $('#globalVarsTable');
        if (!tableEl.length || !$.fn.DataTable) {
            return;
        }

        page.state.variablesTable = tableEl.DataTable({
            data: [],
            columns: [
                {
                    data: 'safeKey',
                    title: 'Field Key',
                    defaultContent: '',
                    render: function (data, type, row) {
                        const token = `{{${row.safeKey}}}`;
                        return `<span class="badge rounded-pill fw-semibold variable-badge text-bg-light border border-secondary-subtle text-dark js-insert-token" data-token="${token}" title="Insert ${token}">${data}</span>`;
                    },
                },
                { data: 'typeLabel', title: 'Field Type', defaultContent: '' },
                {
                    data: 'value',
                    title: 'Field Data',
                    defaultContent: '',
                    render: function (data, type, row) {
                        if (type !== 'display') {
                            return data || '';
                        }
                        const safeText = page.escapeHtml(data || '');
                        const previewSrc = row.previewUrl || row.previewDataUrl || data;
                        if (row.type === 'image' && previewSrc) {
                            const safeSrc = page.escapeHtml(previewSrc);
                            return `<div class="d-flex flex-column align-items-start gap-1">
                                <img src="${safeSrc}" alt="${safeText}" class="img-thumbnail" style="max-height:48px; max-width:80px;" loading="lazy">
                                <span class="small text-muted text-break">${safeText}</span>
                            </div>`;
                        }
                        return safeText;
                    },
                },
                {
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    className: 'text-end',
                    render: function () {
                        return '<button type="button" class="btn btn-sm btn-outline-primary js-edit-var">Edit</button>';
                    },
                },
            ],
            paging: false,
            searching: true,
            info: false,
            lengthChange: false,
            ordering: true,
            order: [[0, 'asc']],
            dom: 't',
            language: { emptyTable: 'No variables found.' },
        });

        $('#globalVarsTable tbody').on('click', '.js-insert-token', function (event) {
            event.stopPropagation();
            const token = $(this).data('token');
            const rowData = page.state.variablesTable.row($(this).closest('tr')).data();
            if (token) {
                page.insertTokenIntoEditor(String(token));
            }
            // Do not trigger form editing here per requirement.
            page.highlightVariableRow(rowData?.id || null);
        });

        $('#globalVarsTable tbody').on('click', '.js-edit-var', function (event) {
            event.stopPropagation();
            const rowData = page.state.variablesTable.row($(this).closest('tr')).data();
            if (rowData) {
                page.selectVariable(rowData);
            }
        });

        $('#variableSearch').on('keyup', (event) => page.filterVariables(event.currentTarget));
    }

    /**
     * Track caret position on the Typst editor so badge clicks insert at the last cursor location.
     */
    bindEditorCaretTracking() {
        const page = this;
        const textarea = document.getElementById('latexSource');
        if (!textarea) return;

        const remember = () => page.rememberCaretPosition(textarea);
        ['click', 'keyup', 'select', 'input', 'focus'].forEach((evt) => {
            textarea.addEventListener(evt, remember);
        });
        remember();
    }

    /**
     * Capture the current caret position for later insertion.
     */
    rememberCaretPosition(textarea) {
        const page = this;
        if (!textarea) {
            return;
        }
        const start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : 0;
        const end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : start;
        page.state.lastCaret = {
            start: Math.max(0, Math.min(start, textarea.value.length)),
            end: Math.max(0, Math.min(end, textarea.value.length)),
        };
    }

    /**
     * Insert a token into the Typst editor at the stored caret position.
     */
    insertTokenIntoEditor(token) {
        const page = this;
        const textarea = document.getElementById('latexSource');
        if (!textarea) {
            return;
        }
        const value = textarea.value || '';
        const start = Math.max(
            0,
            Math.min(
                typeof page.state.lastCaret.start === 'number' ? page.state.lastCaret.start : (textarea.selectionStart || 0),
                value.length
            )
        );
        const end = Math.max(
            start,
            Math.min(
                typeof page.state.lastCaret.end === 'number' ? page.state.lastCaret.end : (textarea.selectionEnd || start),
                value.length
            )
        );
        const nextValue = value.slice(0, start) + token + value.slice(end);
        textarea.value = nextValue;
        const newPos = start + token.length;
        textarea.selectionStart = newPos;
        textarea.selectionEnd = newPos;
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        page.state.lastCaret = { start: newPos, end: newPos };
        if (typeof page.state.lineNumberUpdater === 'function') {
            page.state.lineNumberUpdater();
        }
    }

    /**
     * Sanitize a raw variable key into a Typst-safe identifier (mirrors TypstService logic).
     */
    sanitizeTypstKey(rawKey) {
        const page = this;
        const normalized = (rawKey || '').toString().replace(/[^A-Za-z0-9_]/g, '_').replace(/^_+/, '');
        const base = normalized === '' ? 'key' : (/^[0-9]/.test(normalized) ? `_${normalized}` : normalized);
        return base;
    }

    /**
     * Escape a string for safe HTML insertion.
     */
    escapeHtml(value) {
        const page = this;
        return (value || '').toString().replace(/[&<>"']/g, function (char) {
            switch (char) {
                case '&': return '&amp;';
                case '<': return '&lt;';
                case '>': return '&gt;';
                case '"': return '&quot;';
                case "'": return '&#39;';
                default: return char;
            }
        });
    }

    /**
     * Ensure Typst-safe keys stay unique within the globals dictionary.
     */
    makeUniqueTypstKey(baseKey, usedKeys) {
        const page = this;
        let candidate = baseKey;
        let suffix = 1;
        while (usedKeys[candidate]) {
            candidate = `${baseKey}_${suffix}`;
            suffix += 1;
        }
        usedKeys[candidate] = true;
        return candidate;
    }

    /**
     * Attach Typst-safe keys to raw variables for badge rendering and placeholder insertion.
     */
    normalizeVariables(rawVariables) {
        const page = this;
        const usedKeys = {};
        return (rawVariables || []).map((v) => {
            const safeKey = page.makeUniqueTypstKey(page.sanitizeTypstKey(v.key), usedKeys);
            const typeLabel = v.type === 'image' ? 'File (Image)' : 'Text';
            const previewUrl = v.previewUrl || v.preview_url || null;
            const previewDataUrl = v.previewDataUrl || v.preview_data_url || null;
            return { ...v, safeKey, typeLabel, previewUrl, previewDataUrl };
        });
    }

    showStatus(type, message, correlationId) {
        const page = this;
        const alertHost = document.getElementById('statusAlert');
        if (!alertHost) return;
        const text = window.AppError && window.AppError.buildUserMessage
            ? window.AppError.buildUserMessage(message, correlationId || null)
            : message;
        alertHost.innerHTML = `<div class="alert alert-${type} mb-3" role="status">${text}</div>`;
    }

    async requestJson(url, options = {}) {
        const page = this;
        const requestInit = { ...options };
        requestInit.headers = {
            Accept: 'application/json',
            ...(requestInit.headers || {}),
        };
        const hasBody = typeof requestInit.body === 'string';
        if (hasBody && !requestInit.headers['Content-Type']) {
            requestInit.headers['Content-Type'] = 'application/json';
        }

        const response = await fetch(url, requestInit);
        const payload = await response.json().catch(() => ({}));
        const correlationId = window.AppError && window.AppError.extractCorrelationId
            ? window.AppError.extractCorrelationId(payload, response)
            : null;
        if (!response.ok || payload?.success === false || payload?.error) {
            const message = payload?.error?.message || `Request failed (${response.status})`;
            const error = new Error(
                window.AppError && window.AppError.buildUserMessage
                    ? window.AppError.buildUserMessage(message, correlationId)
                    : message
            );
            error.correlationId = correlationId;
            throw error;
        }
        return { data: payload?.data, correlationId };
    }

    withLoading(promiseFactory) {
        const page = this;
        if (window.LoadingOverlay && typeof window.LoadingOverlay.wrapPromise === 'function') {
            return window.LoadingOverlay.wrapPromise(promiseFactory);
        }
        try {
            return Promise.resolve(typeof promiseFactory === 'function' ? promiseFactory() : promiseFactory);
        } catch (error) {
            return Promise.reject(error);
        }
    }

    initDataTable() {
        const page = this;
        page.state.table = $('#savedTemplatesTable').DataTable({
            data: [],
            columns: [
                { data: 'title' },
                { data: 'description' },
                {
                    data: null,
                    render: function (data, type, row) {
                        const canDownload = !!row.typst;
                        return `
                            <button class="btn btn-sm btn-outline-primary js-load-template" data-id="${row.id}">Load</button>
                            <button class="btn btn-sm btn-outline-danger js-delete-template" data-id="${row.id}">Delete</button>
                            <button class="btn btn-sm btn-outline-secondary js-download-pdf" data-id="${row.id}" ${canDownload ? '' : 'disabled'}>PDF Download</button>
                        `;
                    },
                },
            ],
            dom: 't<"d-flex justify-content-between"ip>',
        });

        $('#templateSearch').on('keyup', function () {
            page.state.table.search(this.value).draw();
        });
    }

    bindEvents() {
        const page = this;
        $('#varType').on('change', page.handleVarTypeChange);
        $('#variableForm').on('submit', function (event) {
            event.preventDefault();
            page.handleSaveVariable();
        });
        $('#addVarBtn').on('click', page.resetVarForm);
        $('#deleteVarBtn').on('click', page.handleDeleteVariable);

        $('#compileBtn').on('click', page.handleCompile);
        $('#saveTemplateBtn').on('click', page.handleSaveTemplate);
        $('#savePdfBtn').on('click', page.handleSavePdf); // Note: Save PDF usually just compiles and saves, or saves current PDF?
        // In Latex version, savePdfBtn was separate. Here compile returns URL.
        // Maybe savePdfBtn just triggers compile and download?
        // Or maybe it saves the generated PDF to a permanent location?
        // TypstService compile already saves to storage/typst-pdfs.
        // So "Save PDF" might be redundant if compile already saves.
        // Let's assume compile is enough for preview, and "Save PDF" might be for "Finalize" or just download.

        $('#savedTemplatesTable tbody').on('click', '.js-load-template', function () {
            const id = Number($(this).data('id'));
            page.loadTemplate(id);
        });

        $('#savedTemplatesTable tbody').on('click', '.js-delete-template', function () {
            const id = Number($(this).data('id'));
            if (Number.isInteger(id) && id > 0 && window.confirm('Delete this template?')) {
                page.deleteTemplate(id);
            }
        });

        $('#savedTemplatesTable tbody').on('click', '.js-download-pdf', function () {
            const id = Number($(this).data('id'));
            page.handleDownloadTemplate(id);
        });
    }

    handleVarTypeChange() {
        const page = this;
        const type = $('#varType').val();
        const container = $('#varDataContainer');
        container.find('[data-file-hint="true"]').remove();
        let html = '';

        if (type === 'text' || type === 'textarea') {
            html = type === 'textarea'
                ? '<textarea id="varData" class="form-control form-control-sm" rows="3" placeholder="Value"></textarea>'
                : '<input type="text" id="varData" class="form-control form-control-sm" placeholder="Value">';
        } else {
            html = '<input type="file" id="varData" class="form-control form-control-sm">';
        }

        container.html(html);

        if (type === 'file') {
            const hint = document.createElement('div');
            hint.className = 'form-text small text-muted mt-1';
            hint.setAttribute('data-file-hint', 'true');
            hint.textContent = page.state.selectedVarValue
                ? `Current stored: ${page.state.selectedVarValue}`
                : 'No file stored yet. Upload an image to store it under typst-assets for Typst usage.';
            container.append(hint);
        }
    }

    async handleSaveVariable() {
        const page = this;
        const key = ($('#varKey').val() || '').toString().trim();
        const type = $('#varType').val();
        const value = page.getVarDataValue(type);
        const fileInput = document.getElementById('varData');
        const hasFile = type === 'file' && fileInput && fileInput.files && fileInput.files[0];

        if (!key) {
            page.showStatus('warning', 'Please enter a field key.');
            return;
        }
        if (type === 'file' && !hasFile && !value) {
            page.showStatus('warning', 'Please choose an image or provide an existing path.');
            return;
        }

        try {
            const requestOptions = { method: 'POST' };
            if (hasFile) {
                const formData = new FormData();
                formData.append('key', key);
                formData.append('type', 'file');
                formData.append('value', value);
                if (page.state.selectedVarId) {
                    formData.append('id', page.state.selectedVarId);
                }
                formData.append('file', fileInput.files[0]);
                requestOptions.body = formData;
            } else {
                requestOptions.body = JSON.stringify({
                    id: page.state.selectedVarId,
                    key,
                    type,
                    value,
                });
            }

            const { correlationId } = await page.withLoading(() =>
                page.requestJson('api/typst/variables.php', requestOptions)
            );
            page.showStatus('success', 'Variable saved.', correlationId);
            page.resetVarForm();
            await page.loadVariables();
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to save variable.', error.correlationId);
        }
    }

    async handleDeleteVariable() {
        const page = this;
        if (!page.state.selectedVarId) return;
        if (!window.confirm('Are you sure you want to delete this variable?')) return;
        try {
            const { correlationId } = await page.withLoading(() =>
                page.requestJson(`api/typst/variables.php?id=${page.state.selectedVarId}`, { method: 'DELETE' })
            );
            page.showStatus('success', 'Variable deleted.', correlationId);
            page.resetVarForm();
            await page.loadVariables();
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to delete variable.', error.correlationId);
        }
    }

    async loadVariables() {
        const page = this;
        try {
            const { data } = await page.withLoading(() => page.requestJson('api/typst/variables.php'));
            page.state.variables = page.normalizeVariables(Array.isArray(data) ? data : []);
            page.renderVariables();
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to load variables.', error.correlationId);
        }
    }

    renderVariables() {
        const page = this;
        if (!page.state.variablesTable) {
            return;
        }
        const rows = page.state.variables.map((v) => ({
            id: v.id,
            key: v.key,
            safeKey: v.safeKey,
            type: v.type,
            typeLabel: v.typeLabel,
            value: v.value || '',
            previewUrl: v.previewUrl || null,
            previewDataUrl: v.previewDataUrl || null,
        }));
        page.state.variablesTable.clear().rows.add(rows).draw();
        page.highlightVariableRow(page.state.selectedVarId);
    }

    /**
     * Visually mark the selected variable row in the DataTable.
     */
    highlightVariableRow(id) {
        const page = this;
        if (!page.state.variablesTable) return;
        const rows = page.state.variablesTable.rows();
        $(rows.nodes()).removeClass('table-primary');
        if (!id) return;
        rows.every(function () {
            const data = this.data();
            if (data && data.id === id) {
                $(this.node()).addClass('table-primary');
            }
        });
    }

    selectVariable(v) {
        const page = this;
        page.state.selectedVarId = v.id;
        page.state.selectedVarValue = v.value || '';
        $('#varId').val(v.id);
        $('#varKey').val(v.key);
        const inputType = v.type === 'image' ? 'file' : 'text';
        $('#varType').val(inputType).trigger('change');
        if (inputType !== 'file') {
            window.setTimeout(() => {
                $('#varData').val(v.value || '');
            }, 0);
        }
        page.highlightVariableRow(page.state.selectedVarId);
    }

    resetVarForm() {
        const page = this;
        page.state.selectedVarId = null;
        page.state.selectedVarValue = '';
        $('#variableForm')[0].reset();
        $('#varType').trigger('change');
        page.highlightVariableRow(null);
    }

    filterVariables(searchInput) {
        const page = this;
        const term = ($(searchInput).val() || '').toString();
        if (page.state.variablesTable) {
            page.state.variablesTable.search(term).draw();
        }
    }

    getVarDataValue(selectedType) {
        const page = this;
        const type = selectedType || $('#varType').val();
        if (type === 'file') {
            const fileInput = document.getElementById('varData');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                return fileInput.files[0].name;
            }
            if (page.state.selectedVarId && page.state.selectedVarValue) {
                return page.state.selectedVarValue;
            }
            return ($('#varData').val() || '').toString();
        }
        return ($('#varData').val() || '').toString();
    }

    async handleCompile() {
        const page = this;
        const typst = $('#latexSource').val();
        const previewEl = document.getElementById('latex-preview-render');

        if (!typst) {
            previewEl.innerHTML = '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
            return;
        }

        previewEl.innerHTML = '<div class="text-center mt-5"><div class="spinner-border text-primary" role="status"></div><p>Compiling...</p></div>';

        try {
            const { data } = await page.withLoading(() => page.requestJson('api/typst/compile.php', {
                method: 'POST',
                body: JSON.stringify({ typst })
            }));

            if (data && data.url) {
                previewEl.innerHTML = `<iframe id="pdfPreviewFrame" src="${data.url}" title="PDF Preview"></iframe>`;
            } else {
                previewEl.innerHTML = '<div class="alert alert-warning">Compilation succeeded but no PDF URL returned.</div>';
            }
        } catch (error) {
            previewEl.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
        }
    }

    handleSavePdf() {
        const page = this;
        // Just trigger compile for now as it saves PDF
        page.handleCompile();
    }

    async handleSaveTemplate() {
        const page = this;
        const title = ($('#templateTitle').val() || '').toString().trim();
        const description = ($('#templateDescription').val() || '').toString().trim();
        const typst = ($('#latexSource').val() || '').toString();

        if (!title) {
            page.showStatus('warning', 'Please enter a title for the template.');
            return;
        }

        const payload = {
            id: page.state.selectedTemplateId,
            title,
            description,
            typst,
        };
        const method = page.state.selectedTemplateId ? 'PUT' : 'POST';

        try {
            const { data, correlationId } = await page.withLoading(() =>
                page.requestJson('api/typst/templates.php', {
                    method,
                    body: JSON.stringify(payload),
                })
            );
            if (data && data.id) {
                page.state.selectedTemplateId = data.id;
            }
            page.showStatus('success', 'Template saved.', correlationId);
            await page.loadTemplates();
            if (!page.state.selectedTemplateId) {
                page.resetTemplateForm();
            }
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to save template.', error.correlationId);
        }
    }

    async loadTemplates() {
        const page = this;
        try {
            const { data } = await page.withLoading(() => page.requestJson('api/typst/templates.php'));
            page.state.templates = Array.isArray(data) ? data : [];
            page.state.table.clear().rows.add(page.state.templates).draw();
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to load templates.', error.correlationId);
        }
    }

    async handleDownloadTemplate(id) {
        const page = this;
        const template = page.state.templates.find((t) => t.id === id);
        if (!template) {
            page.showStatus('warning', 'Template not found.');
            return;
        }

        if (template.downloadUrl) {
            window.open(template.downloadUrl, '_blank');
            return;
        }

        if (!template.typst) {
            page.showStatus('warning', 'Template has no Typst content to compile.');
            return;
        }

        try {
            const { data, correlationId } = await page.withLoading(() => page.requestJson('api/typst/compile.php', {
                method: 'POST',
                body: JSON.stringify({ typst: template.typst }),
            }));

            if (data && data.url) {
                template.downloadUrl = data.url;
                page.refreshTemplatesTable();
                window.open(data.url, '_blank');
                page.showStatus('success', 'PDF generated.', correlationId);
            } else {
                page.showStatus('warning', 'Compilation succeeded but no PDF URL returned.', correlationId);
            }
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to compile template.', error.correlationId);
        }
    }

    refreshTemplatesTable() {
        const page = this;
        if (!page.state.table) return;
        page.state.table.clear().rows.add(page.state.templates).draw();
    }

    loadTemplate(id) {
        const page = this;
        const template = page.state.templates.find((t) => t.id === id);
        if (template) {
            page.state.selectedTemplateId = template.id;
            $('#templateTitle').val(template.title || '');
            $('#templateDescription').val(template.description || '');
            $('#latexSource').val(template.typst || '');
            const textarea = document.getElementById('latexSource');
            if (textarea) {
                const endPos = textarea.value.length;
                textarea.setSelectionRange(endPos, endPos);
                page.rememberCaretPosition(textarea);
            }
            if (typeof page.state.lineNumberUpdater === 'function') {
                page.state.lineNumberUpdater();
            }
            page.handleCompile();
        }
    }

    async deleteTemplate(id) {
        const page = this;
        try {
            const { correlationId } = await page.withLoading(() =>
                page.requestJson(`api/typst/templates.php?id=${id}`, { method: 'DELETE' })
            );
            page.showStatus('success', 'Template deleted.', correlationId);
            if (page.state.selectedTemplateId === id) {
                page.resetTemplateForm();
            }
            await page.loadTemplates();
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to delete template.', error.correlationId);
        }
    }

    resetTemplateForm() {
        const page = this;
        page.state.selectedTemplateId = null;
        $('#templateTitle').val('');
        $('#templateDescription').val('');
        $('#latexSource').val('');
        const textarea = document.getElementById('latexSource');
        if (textarea) {
            textarea.setSelectionRange(0, 0);
            page.rememberCaretPosition(textarea);
        }
        if (typeof page.state.lineNumberUpdater === 'function') {
            page.state.lineNumberUpdater();
        }
        document.getElementById('latex-preview-render').innerHTML =
            '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
    }
}

new GlobalTypstTemplatePage();
