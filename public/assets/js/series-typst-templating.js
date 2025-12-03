(function () {
    'use strict';

    const state = {
        seriesId: null,
        templates: [],
        globalTemplates: [],
        selectedTemplateId: null,
        lineNumberUpdater: null,
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const urlParams = new URLSearchParams(window.location.search);
        state.seriesId = urlParams.get('series_id');

        if (!state.seriesId) {
            showStatus('danger', 'No series ID provided in URL.');
            return;
        }

        $('#seriesId').text(state.seriesId);

        // Try to fetch series details if API exists
        fetchSeriesDetails(state.seriesId);

        state.lineNumberUpdater = initLineNumberedEditor('latexSource');
        bindEvents();
        loadTemplates();
    }

    /**
     * Attach a line-number gutter to the Typst textarea and return a manual refresh callback.
     */
    function initLineNumberedEditor(textareaId) {
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
    function sanitizeTypstKey(rawKey) {
        const normalized = (rawKey || '').toString().replace(/[^A-Za-z0-9_]/g, '_').replace(/^_+/, '');
        const base = normalized === '' ? 'key' : (/^[0-9]/.test(normalized) ? `_${normalized}` : normalized);
        return base;
    }

    /**
     * Ensure Typst-safe keys stay unique within a collection.
     */
    function makeUniqueTypstKey(baseKey, usedKeys) {
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
    function insertTokenIntoEditor(token) {
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
        if (typeof state.lineNumberUpdater === 'function') {
            state.lineNumberUpdater();
        }
    }

    /**
     * Build a clickable badge element that inserts a Typst token.
     */
    function buildVariableBadge(displayText, insertToken, tooltip, toneClass) {
        const badge = $(`<span class="badge rounded-pill fw-semibold variable-badge ${toneClass || 'text-bg-light border border-secondary-subtle text-dark'}"></span>`);
        badge.text(displayText);
        badge.attr('title', tooltip || insertToken);
        badge.attr('data-insert', insertToken);
        badge.attr('aria-label', tooltip || insertToken);
        badge.on('click', () => insertTokenIntoEditor(insertToken));
        return badge;
    }

    async function fetchSeriesDetails(seriesId) {
        try {
            // Fetch hierarchy to find the series node
            const { data } = await withLoading(() => requestJson('catalog.php?action=v1.listHierarchy'));
            const hierarchy = data.hierarchy || [];
            const node = findNodeById(hierarchy, Number(seriesId));

            if (node) {
                $('#seriesName').text(node.name);
                $('#seriesId').text(node.id);
                $('#seriesParentId').text(node.parentId || 'Root');
                $('#seriesType').text(node.type);
            } else {
                showStatus('warning', 'Series not found in hierarchy.');
            }

            // Also fetch metadata and custom fields
            fetchSeriesMetadata(seriesId);
            fetchSeriesCustomFields(seriesId);

        } catch (error) {
            showStatus('danger', 'Failed to load series details: ' + error.message);
        }
    }

    function findNodeById(nodes, id) {
        for (const node of nodes) {
            if (node.id === id) return node;
            if (node.children) {
                const found = findNodeById(node.children, id);
                if (found) return found;
            }
        }
        return null;
    }

    async function fetchSeriesMetadata(seriesId) {
        try {
            const { data } = await withLoading(() => requestJson(`catalog.php?action=v1.getSeriesAttributes&seriesId=${seriesId}`));
            renderMetadata(data);
        } catch (error) {
            console.error('Failed to load metadata', error);
            $('#seriesMetadataContainer').html('<span class="text-danger">Failed to load metadata.</span>');
        }
    }

    function renderMetadata(data) {
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
            const safeKey = makeUniqueTypstKey(sanitizeTypstKey(rawKey), usedKeys);
            const insertToken = `{{${safeKey}}}`;
            const tooltip = `${def.label || rawKey} • ${insertToken}`;
            const badge = buildVariableBadge(safeKey, insertToken, tooltip, 'text-bg-light border border-secondary-subtle text-dark');
            wrapper.append(badge);
        });
        container.append(wrapper);
    }

    async function fetchSeriesCustomFields(seriesId) {
        try {
            const { data } = await withLoading(() => requestJson(`catalog.php?action=v1.listSeriesFields&seriesId=${seriesId}&scope=product_attribute`));
            renderCustomFields(data);
        } catch (error) {
            console.error('Failed to load custom fields', error);
            $('#seriesCustomFieldsContainer').html('<span class="text-danger">Failed to load custom fields.</span>');
        }
    }

    function renderCustomFields(fields) {
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
        const productsBadge = buildVariableBadge(
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
            const badge = buildVariableBadge(b.label, b.token, `Insert ${b.token}`, b.tone);
            fieldsWrap.append(badge);
        });

        fields.forEach(field => {
            const rawKey = field.fieldKey || field.field_key || '';
            if (!rawKey) {
                return;
            }
            const safeKey = makeUniqueTypstKey(sanitizeTypstKey(rawKey), usedKeys);
            const insertToken = `product.attributes.${safeKey}`;
            const tooltip = `${field.label || rawKey} (${rawKey}) • ${insertToken}` + (field.fieldType ? ` • Type: ${field.fieldType}` : '');
            const badge = buildVariableBadge(safeKey, insertToken, tooltip, 'text-bg-secondary');
            fieldsWrap.append(badge);
        });

        productsRow.append(fieldsWrap);
        outer.append(productsRow);
        container.append(outer);
    }

    function showStatus(type, message, correlationId) {
        const alertHost = document.getElementById('statusAlert');
        if (!alertHost) return;
        const text = window.AppError && window.AppError.buildUserMessage
            ? window.AppError.buildUserMessage(message, correlationId || null)
            : message;
        alertHost.innerHTML = `<div class="alert alert-${type} mb-3" role="status">${text}</div>`;
    }

    async function requestJson(url, options = {}) {
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

    function withLoading(promiseFactory) {
        if (window.LoadingOverlay && typeof window.LoadingOverlay.wrapPromise === 'function') {
            return window.LoadingOverlay.wrapPromise(promiseFactory);
        }
        try {
            return Promise.resolve(typeof promiseFactory === 'function' ? promiseFactory() : promiseFactory);
        } catch (error) {
            return Promise.reject(error);
        }
    }

    function bindEvents() {
        $('#compileBtn').on('click', handleCompile);
        $('#saveCompileBtn').on('click', handleSaveCompile);
        $('#savePdfBtn').on('click', handleSavePdf);
        $('#downloadPdfBtn').on('click', handleDownloadPdf);

        $('#loadTemplateBtn').on('click', function () {
            const id = $('#templateSelect').val();
            if (id) loadTemplate(Number(id));
        });
    }

    async function loadTemplates() {
        try {
            // Load templates for this series (returns both Series and Global)
            const { data } = await withLoading(() => requestJson(`api/typst/templates.php?seriesId=${state.seriesId}`));
            const allTemplates = Array.isArray(data) ? data : [];

            // Filter Global Templates for the dropdown
            state.globalTemplates = allTemplates.filter(t => t.isGlobal);

            // Find if there is an existing Series Template
            // Assuming one series template per series for now, or we pick the latest updated one?
            // The query orders by updated_at DESC.
            const seriesTemplate = allTemplates.find(t => !t.isGlobal && t.seriesId == state.seriesId);

            renderTemplateSelect();

            if (seriesTemplate) {
                // Populate fields
                state.selectedTemplateId = seriesTemplate.id;
                $('#seriesTemplateTitle').val(seriesTemplate.title);
                $('#seriesTemplateDesc').val(seriesTemplate.description);
                $('#latexSource').val(seriesTemplate.typst || '');

                if (typeof state.lineNumberUpdater === 'function') {
                    state.lineNumberUpdater();
                }

                // If there is a last PDF, maybe we can show it?
                if (seriesTemplate.downloadUrl) {
                    const previewEl = document.getElementById('latex-preview-render');
                    previewEl.innerHTML = `<iframe id="pdfPreviewFrame" src="${seriesTemplate.downloadUrl}" title="PDF Preview"></iframe>`;
                    $('#downloadPdfBtn').data('url', seriesTemplate.downloadUrl);
                }
            }
        } catch (error) {
            showStatus('danger', error.message || 'Failed to load templates.', error.correlationId);
        }
    }

    function renderTemplateSelect() {
        const select = $('#templateSelect');
        select.empty();
        select.append('<option value="">Select a global template...</option>');
        state.globalTemplates.forEach(t => {
            select.append(`<option value="${t.id}">${t.title}</option>`);
        });
    }

    function loadTemplate(id) {
        const template = state.globalTemplates.find((t) => t.id === id);
        if (template) {
            // Only load code into editor
            $('#latexSource').val(template.typst || '');
            if (typeof state.lineNumberUpdater === 'function') {
                state.lineNumberUpdater();
            }
            // Do not overwrite Title/Desc as this is "Import"
        }
    }

    async function handleCompile() {
        const typst = $('#latexSource').val();
        const previewEl = document.getElementById('latex-preview-render');

        if (!typst) {
            previewEl.innerHTML = '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
            return null;
        }

        previewEl.innerHTML = '<div class="text-center mt-5"><div class="spinner-border text-primary" role="status"></div><p>Compiling...</p></div>';

        try {
            const { data } = await withLoading(() => requestJson('api/typst/compile.php', {
                method: 'POST',
                body: JSON.stringify({
                    typst,
                    seriesId: state.seriesId
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

    function handleSavePdf() {
        return handleSaveCompile();
    }

    function handleDownloadPdf() {
        const url = $('#downloadPdfBtn').data('url');
        if (url) {
            window.open(url, '_blank');
        } else {
            showStatus('warning', 'Please compile first.');
        }
    }

    async function handleSaveCompile() {
        // Validate required fields
        const title = $('#seriesTemplateTitle').val().trim();
        const description = $('#seriesTemplateDesc').val().trim();
        const typst = $('#latexSource').val();

        if (!title) {
            showStatus('warning', 'Series Template Title is required.');
            $('#seriesTemplateTitle').focus();
            return;
        }
        if (!description) {
            showStatus('warning', 'Description is required.');
            $('#seriesTemplateDesc').focus();
            return;
        }
        if (!typst) {
            showStatus('warning', 'Template code is empty.');
            return;
        }

        try {
            // 1. Compile to generate PDF and get path
            const compileResult = await handleCompile();
            console.log("Compile Result:", compileResult);

            if (!compileResult || !compileResult.path) {
                throw new Error("Compilation failed or did not return a PDF path.");
            }

            // 2. Save Template with PDF path
            await saveTemplate(title, description, compileResult.path, state.selectedTemplateId);

        } catch (error) {
            // Error is already handled/displayed in handleCompile or saveTemplate
            if (!error.message.includes("Compilation failed")) {
                showStatus('danger', 'Save failed: ' + error.message);
            }
        }
    }

    async function saveTemplate(title, description, pdfPath, id = null) {
        const typst = $('#latexSource').val();
        const payload = {
            id: id,
            title,
            description,
            typst,
            seriesId: state.seriesId,
            lastPdfPath: pdfPath
        };
        console.log("Saving Template Payload:", payload);
        const method = id ? 'PUT' : 'POST';

        try {
            const { data, correlationId } = await withLoading(() =>
                requestJson('api/typst/templates.php', {
                    method,
                    body: JSON.stringify(payload),
                })
            );
            if (data && data.id) {
                state.selectedTemplateId = data.id;
            }
            showStatus('success', 'Template and PDF saved successfully.', correlationId);
            await loadTemplates();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to save template.', error.correlationId);
            throw error;
        }
    }

})();
