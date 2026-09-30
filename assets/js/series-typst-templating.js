export class SeriesTypstTemplatePage {
    constructor() {
        this.state = {
            seriesId: null,
            templates: [],
            globalTemplates: [],
            selectedTemplateId: null,
            lastGlobalTemplateId: null,
            lineNumberUpdater: null,
        };
        for (const methodName of Object.getOwnPropertyNames(Object.getPrototypeOf(this))) {
            if (methodName !== 'constructor' && typeof this[methodName] === 'function') {
                this[methodName] = this[methodName].bind(this);
            }
        }
        document.addEventListener('DOMContentLoaded', () => {
            this.init().catch((error) => {
                console.error('Failed to initialize page', error);
                this.showStatus('danger', 'Initialization failed: ' + (error.message || 'Unknown error'));
            });
        });
    }



    async init() {
        const page = this;
        const urlParams = new URLSearchParams(window.location.search);
        page.state.seriesId = urlParams.get('series_id');

        if (!page.state.seriesId) {
            page.showStatus('danger', 'No series ID provided in URL.');
            return;
        }

        $('#seriesId').text(page.state.seriesId);

        // Try to fetch series details if API exists
        page.fetchSeriesDetails(page.state.seriesId);

        page.state.lineNumberUpdater = page.initLineNumberedEditor('latexSource');
        page.bindEvents();
        await page.loadTemplates();
        await page.fetchSeriesPreference();
    }

    /**
     * Attach a line-number gutter to the Typst textarea and return a manual refresh callback.
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
     * Convert a raw key into a Typst-safe identifier (mirrors TypstService logic).
     */
    sanitizeTypstKey(rawKey) {
        const page = this;
        const normalized = (rawKey || '').toString().replace(/[^A-Za-z0-9_]/g, '_').replace(/^_+/, '');
        const base = normalized === '' ? 'key' : (/^[0-9]/.test(normalized) ? `_${normalized}` : normalized);
        return base;
    }

    /**
     * Ensure Typst-safe keys stay unique within a collection.
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
     * Insert a token into the Typst editor at the current caret position.
     */
    insertTokenIntoEditor(token) {
        const page = this;
        const textarea = document.getElementById('latexSource');
        if (!textarea) {
            return;
        }
        const start = textarea.selectionStart || 0;
        const end = textarea.selectionEnd || 0;
        const value = textarea.value || '';
        textarea.value = value.slice(0, start) + token + value.slice(end);
        const newPos = start + token.length;
        textarea.selectionStart = newPos;
        textarea.selectionEnd = newPos;
        textarea.focus();
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        if (typeof page.state.lineNumberUpdater === 'function') {
            page.state.lineNumberUpdater();
        }
    }

    /**
     * Build a clickable badge element that inserts a Typst token.
     */
    buildVariableBadge(displayText, insertToken, tooltip, toneClass) {
        const page = this;
        const badge = $(`<span class="badge rounded-pill fw-semibold variable-badge ${toneClass || 'text-bg-light border border-secondary-subtle text-dark'}"></span>`);
        badge.text(displayText);
        badge.attr('title', tooltip || insertToken);
        badge.attr('data-insert', insertToken);
        badge.attr('aria-label', tooltip || insertToken);
        badge.on('click', () => page.insertTokenIntoEditor(insertToken));
        return badge;
    }

    async fetchSeriesDetails(seriesId) {
        const page = this;
        try {
            // Fetch hierarchy to find the series node
            const { data } = await page.withLoading(() => page.requestJson('catalog.php?action=v1.listHierarchy'));
            const hierarchy = data.hierarchy || [];
            const node = page.findNodeById(hierarchy, Number(seriesId));

            if (node) {
                $('#seriesName').text(node.name);
                $('#seriesId').text(node.id);
                $('#seriesParentId').text(node.parentId || 'Root');
                $('#seriesType').text(node.type);
            } else {
                page.showStatus('warning', 'Series not found in hierarchy.');
            }

            // Also fetch metadata and custom fields
            page.fetchSeriesMetadata(seriesId);
            page.fetchSeriesCustomFields(seriesId);

        } catch (error) {
            page.showStatus('danger', 'Failed to load series details: ' + error.message);
        }
    }

    findNodeById(nodes, id) {
        const page = this;
        for (const node of nodes) {
            if (node.id === id) return node;
            if (node.children) {
                const found = page.findNodeById(node.children, id);
                if (found) return found;
            }
        }
        return null;
    }

    async fetchSeriesMetadata(seriesId) {
        const page = this;
        try {
            const { data } = await page.withLoading(() => page.requestJson(`catalog.php?action=v1.getSeriesAttributes&seriesId=${seriesId}`));
            page.renderMetadata(data);
        } catch (error) {
            console.error('Failed to load metadata', error);
            $('#seriesMetadataContainer').html('<span class="text-danger">Failed to load metadata.</span>');
        }
    }

    renderMetadata(data) {
        const page = this;
        const container = $('#seriesMetadataContainer');
        container.empty();

        if (!data || !Array.isArray(data.definitions) || data.definitions.length === 0) {
            container.html('<span class="text-muted">No metadata fields defined.</span>');
            return;
        }

        const wrapper = $('<div class="d-flex flex-wrap gap-2"></div>');
        const usedKeys = {};
        data.definitions.forEach(def => {
            const rawKey = def.fieldKey || def.field_key || def.key || '';
            if (!rawKey) {
                return;
            }
            const safeKey = page.makeUniqueTypstKey(page.sanitizeTypstKey(rawKey), usedKeys);
            const insertToken = `{{${safeKey}}}`;
            const tooltip = `${def.label || rawKey} • ${insertToken}`;
            const badge = page.buildVariableBadge(safeKey, insertToken, tooltip, 'text-bg-light border border-secondary-subtle text-dark');
            wrapper.append(badge);
        });
        container.append(wrapper);
    }

    async fetchSeriesCustomFields(seriesId) {
        const page = this;
        try {
            const { data } = await page.withLoading(() => page.requestJson(`catalog.php?action=v1.listSeriesFields&seriesId=${seriesId}&scope=product_attribute`));
            page.renderCustomFields(data);
        } catch (error) {
            console.error('Failed to load custom fields', error);
            $('#seriesCustomFieldsContainer').html('<span class="text-danger">Failed to load custom fields.</span>');
        }
    }

    renderCustomFields(fields) {
        const page = this;
        const container = $('#seriesCustomFieldsContainer');
        container.empty();

        if (!fields || fields.length === 0) {
            container.html('<span class="text-muted">No custom fields defined.</span>');
            return;
        }

        const usedKeys = {};

        const outer = $('<div class="d-flex flex-column gap-2"></div>');
        const productsRow = $('<div class="d-flex align-items-start gap-2 flex-wrap"></div>');

        const loopSnippet = [
            '#for product in products {',
            '  // Example: product.name, product.sku, product.attributes.<key>',
            '}',
            ''
        ].join('\n');
        const productsBadge = page.buildVariableBadge(
            'products',
            loopSnippet,
            'Insert products loop scaffold',
            'text-bg-primary'
        );
        productsRow.append(productsBadge);

        const fieldsWrap = $('<div class="d-flex flex-wrap gap-2 ms-2"></div>');
        // Core product badges
        const coreBadges = [
            { label: 'sku', token: 'product.sku', tone: 'text-bg-danger' },
            { label: 'name', token: 'product.name', tone: 'text-bg-warning text-dark' },
        ];
        coreBadges.forEach((b) => {
            const badge = page.buildVariableBadge(b.label, b.token, `Insert ${b.token}`, b.tone);
            fieldsWrap.append(badge);
        });

        fields.forEach(field => {
            const rawKey = field.fieldKey || field.field_key || '';
            if (!rawKey) {
                return;
            }
            const safeKey = page.makeUniqueTypstKey(page.sanitizeTypstKey(rawKey), usedKeys);
            const insertToken = `product.attributes.${safeKey}`;
            const tooltip = `${field.label || rawKey} (${rawKey}) • ${insertToken}` + (field.fieldType ? ` • Type: ${field.fieldType}` : '');
            const badge = page.buildVariableBadge(safeKey, insertToken, tooltip, 'text-bg-secondary');
            fieldsWrap.append(badge);
        });

        productsRow.append(fieldsWrap);
        outer.append(productsRow);
        container.append(outer);
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

    bindEvents() {
        const page = this;
        $('#compileBtn').on('click', page.handleCompile);
        $('#saveCompileBtn').on('click', page.handleSaveCompile);
        $('#savePdfBtn').on('click', page.handleSavePdf);
        $('#downloadPdfBtn').on('click', page.handleDownloadPdf);

        $('#loadTemplateBtn').on('click', async function () {
            const id = $('#templateSelect').val();
            if (!id) {
                page.showStatus('warning', 'Please select a global template to import.');
                return;
            }
            const parsedId = Number(id);
            const loaded = page.loadTemplate(parsedId);
            if (loaded) {
                await page.persistSeriesPreference(parsedId);
            }
        });
    }

    async loadTemplates() {
        const page = this;
        try {
            // Load templates for this series (returns both Series and Global)
            const { data } = await page.withLoading(() => page.requestJson(`api/typst/templates.php?seriesId=${page.state.seriesId}`));
            const allTemplates = Array.isArray(data) ? data : [];

            // Filter Global Templates for the dropdown
            page.state.globalTemplates = allTemplates.filter(t => t.isGlobal);

            // Find if there is an existing Series Template
            // Assuming one series template per series for now, or we pick the latest updated one?
            // The query orders by updated_at DESC.
            const seriesTemplate = allTemplates.find(t => !t.isGlobal && t.seriesId == page.state.seriesId);

            page.renderTemplateSelect();

            if (seriesTemplate) {
                // Populate fields
                page.state.selectedTemplateId = seriesTemplate.id;
                $('#seriesTemplateTitle').val(seriesTemplate.title);
                $('#seriesTemplateDesc').val(seriesTemplate.description);
                $('#latexSource').val(seriesTemplate.typst || '');

                if (typeof page.state.lineNumberUpdater === 'function') {
                    page.state.lineNumberUpdater();
                }

                // If there is a last PDF, maybe we can show it?
                if (seriesTemplate.downloadUrl) {
                    const previewEl = document.getElementById('latex-preview-render');
                    previewEl.innerHTML = `<iframe id="pdfPreviewFrame" src="${seriesTemplate.downloadUrl}" title="PDF Preview"></iframe>`;
                    $('#downloadPdfBtn').data('url', seriesTemplate.downloadUrl);
                }
            }

            page.applyStoredPreferenceToSelect();
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to load templates.', error.correlationId);
        }
    }

    /**
     * Load the stored global template preference for this series from the server.
     */
    async fetchSeriesPreference() {
        const page = this;
        try {
            const { data } = await page.withLoading(() => page.requestJson(`api/typst/series-preferences.php?seriesId=${page.state.seriesId}`));
            const prefId = data && Object.prototype.hasOwnProperty.call(data, 'lastGlobalTemplateId')
                ? data.lastGlobalTemplateId
                : null;
            page.state.lastGlobalTemplateId = prefId === null ? null : Number(prefId);
            page.applyStoredPreferenceToSelect(true);
        } catch (error) {
            console.error('Failed to load series preference', error);
        }
    }

    /**
     * Persist the last imported global template id for this series on the server.
     */
    async persistSeriesPreference(templateId) {
        const page = this;
        page.state.lastGlobalTemplateId = templateId || null;
        try {
            await page.withLoading(() => page.requestJson('api/typst/series-preferences.php', {
                method: 'PUT',
                body: JSON.stringify({
                    seriesId: page.state.seriesId,
                    lastGlobalTemplateId: templateId || null,
                })
            }));
        } catch (error) {
            page.showStatus('warning', 'Failed to remember selected global template: ' + error.message, error.correlationId);
        }
    }

    /**
     * Apply the stored global template preference to the dropdown, clearing it when missing.
     */
    applyStoredPreferenceToSelect(clearMissing = false) {
        const page = this;
        const select = $('#templateSelect');
        if (!select.length) {
            return;
        }
        const prefId = page.state.lastGlobalTemplateId;
        if (!prefId) {
            select.val('');
            return;
        }
        const exists = page.state.globalTemplates.some((t) => t.id === prefId);
        if (exists) {
            select.val(String(prefId));
        } else {
            select.val('');
            if (clearMissing) {
                page.state.lastGlobalTemplateId = null;
                page.persistSeriesPreference(null);
            }
        }
    }

    renderTemplateSelect() {
        const page = this;
        const select = $('#templateSelect');
        select.empty();
        select.append('<option value="">Select a global template...</option>');
        page.state.globalTemplates.forEach(t => {
            select.append(`<option value="${t.id}">${t.title}</option>`);
        });
    }

    loadTemplate(id) {
        const page = this;
        const template = page.state.globalTemplates.find((t) => t.id === id);
        if (!template) {
            page.showStatus('warning', 'Selected global template could not be found.');
            return false;
        }
        // Only load code into editor
        $('#latexSource').val(template.typst || '');
        if (typeof page.state.lineNumberUpdater === 'function') {
            page.state.lineNumberUpdater();
        }
        // Do not overwrite Title/Desc as this is "Import"
        return true;
    }

    async handleCompile() {
        const page = this;
        const typst = $('#latexSource').val();
        const previewEl = document.getElementById('latex-preview-render');

        if (!typst) {
            previewEl.innerHTML = '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
            return null;
        }

        previewEl.innerHTML = '<div class="text-center mt-5"><div class="spinner-border text-primary" role="status"></div><p>Compiling...</p></div>';

        try {
            const { data } = await page.withLoading(() => page.requestJson('api/typst/compile.php', {
                method: 'POST',
                body: JSON.stringify({
                    typst,
                    seriesId: page.state.seriesId
                })
            }));

            if (data && data.url) {
                previewEl.innerHTML = `<iframe id="pdfPreviewFrame" src="${data.url}" title="PDF Preview"></iframe>`;
                // Enable download button
                $('#downloadPdfBtn').data('url', data.url);
                return data; // Return data including path
            } else {
                previewEl.innerHTML = '<div class="alert alert-warning">Compilation succeeded but no PDF URL returned.</div>';
                return null;
            }
        } catch (error) {
            previewEl.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
            throw error;
        }
    }

    handleSavePdf() {
        const page = this;
        return page.handleSaveCompile();
    }

    handleDownloadPdf() {
        const page = this;
        const url = $('#downloadPdfBtn').data('url');
        if (url) {
            window.open(url, '_blank');
        } else {
            page.showStatus('warning', 'Please compile first.');
        }
    }

    async handleSaveCompile() {
        const page = this;
        // Validate required fields
        const title = $('#seriesTemplateTitle').val().trim();
        const description = $('#seriesTemplateDesc').val().trim();
        const typst = $('#latexSource').val();

        if (!title) {
            page.showStatus('warning', 'Series Template Title is required.');
            $('#seriesTemplateTitle').focus();
            return;
        }
        if (!description) {
            page.showStatus('warning', 'Description is required.');
            $('#seriesTemplateDesc').focus();
            return;
        }
        if (!typst) {
            page.showStatus('warning', 'Template code is empty.');
            return;
        }

        try {
            // 1. Compile to generate PDF and get path
            const compileResult = await page.handleCompile();

            if (!compileResult || !compileResult.path) {
                throw new Error("Compilation failed or did not return a PDF path.");
            }

            // 2. Save Template with PDF path
            await page.saveTemplate(title, description, compileResult.path, page.state.selectedTemplateId);

        } catch (error) {
            // Error is already handled/displayed in handleCompile or saveTemplate
            if (!error.message.includes("Compilation failed")) {
                page.showStatus('danger', 'Save failed: ' + error.message);
            }
        }
    }

    async saveTemplate(title, description, pdfPath, id = null) {
        const page = this;
        const typst = $('#latexSource').val();
        const payload = {
            id: id,
            title,
            description,
            typst,
            seriesId: page.state.seriesId,
            lastPdfPath: pdfPath
        };
        const method = id ? 'PUT' : 'POST';

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
            page.showStatus('success', 'Template and PDF saved successfully.', correlationId);
            await page.loadTemplates();
        } catch (error) {
            page.showStatus('danger', error.message || 'Failed to save template.', error.correlationId);
            throw error;
        }
    }
}

new SeriesTypstTemplatePage();
