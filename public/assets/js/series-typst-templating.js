(function () {
    'use strict';

    const state = {
        seriesId: null,
        templates: [],
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

        if (!data || !data.definitions || data.definitions.length === 0) {
            container.html('<span class="text-muted">No metadata fields defined.</span>');
            return;
        }

        const wrapper = $('<div class="d-flex flex-wrap gap-2"></div>');
        data.definitions.forEach(def => {
            const value = data.values[def.fieldKey] || '-';
            // Chip style badge
            wrapper.append(
                `<span class="badge rounded-pill text-bg-light border border-secondary-subtle text-dark fw-normal" title="${def.label}">
                    <span class="fw-bold text-secondary">${def.label}:</span> ${value}
                </span>`
            );
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

        const wrapper = $('<div class="d-flex flex-wrap gap-2"></div>');
        fields.forEach(field => {
            // Chip style badge for custom fields
            wrapper.append(
                `<span class="badge rounded-pill text-bg-secondary fw-normal" title="Type: ${field.fieldType}">
                    ${field.label} <small class="opacity-75">(${field.fieldKey})</small>
                </span>`
            );
        });
        container.append(wrapper);
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
        $('#saveCompileBtn').on('click', handleSaveCompile); // Save as template? Or save compile result?
        // In UI it says "Save Compile" and "Save PDF".
        // "Save Compile" might mean save the template changes?
        // Let's assume it means Save Template.

        $('#savePdfBtn').on('click', handleSavePdf);
        $('#downloadPdfBtn').on('click', handleDownloadPdf);

        $('#loadTemplateBtn').on('click', function () {
            const id = $('#templateSelect').val();
            if (id) loadTemplate(Number(id));
        });
    }

    async function loadTemplates() {
        try {
            // Load templates for this series
            const { data } = await withLoading(() => requestJson(`api/typst/templates.php?seriesId=${state.seriesId}`));
            state.templates = Array.isArray(data) ? data : [];
            renderTemplateSelect();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to load templates.', error.correlationId);
        }
    }

    function renderTemplateSelect() {
        const select = $('#templateSelect');
        select.empty();
        select.append('<option value="">Select a template...</option>');
        state.templates.forEach(t => {
            select.append(`<option value="${t.id}">${t.title} ${t.isGlobal ? '(Global)' : ''}</option>`);
        });
    }

    function loadTemplate(id) {
        const template = state.templates.find((t) => t.id === id);
        if (template) {
            state.selectedTemplateId = template.id;
            $('#latexSource').val(template.typst || '');
            if (typeof state.lineNumberUpdater === 'function') {
                state.lineNumberUpdater();
            }
            handleCompile();
        }
    }

    async function handleCompile() {
        const typst = $('#latexSource').val();
        const previewEl = document.getElementById('latex-preview-render');

        if (!typst) {
            previewEl.innerHTML = '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
            return;
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
            } else {
                previewEl.innerHTML = '<div class="alert alert-warning">Compilation succeeded but no PDF URL returned.</div>';
            }
        } catch (error) {
            previewEl.innerHTML = `<div class="alert alert-danger">Error: ${error.message}</div>`;
        }
    }

    function handleSavePdf() {
        handleCompile();
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
        // This likely means "Save Template" for this series
        // We need a title/description. The UI doesn't have inputs for them in Series view?
        // Series view has "Load Template" and "Code Editor".
        // It seems Series view is for *using* templates, not necessarily creating them?
        // But LatexService has `createSeriesTemplate`.
        // If the UI doesn't have Title/Desc inputs, maybe we prompt?
        // Or maybe we update the *loaded* template?

        if (!state.selectedTemplateId) {
            // Create new? We need title.
            const title = prompt("Enter template title:");
            if (!title) return;
            const description = prompt("Enter description (optional):");
            saveTemplate(title, description);
        } else {
            // Update existing
            const tmpl = state.templates.find(t => t.id === state.selectedTemplateId);
            if (tmpl && tmpl.isGlobal) {
                if (!confirm("This is a global template. Do you want to save a copy as a Series template?")) {
                    return; // Cancel
                }
                // Save as new series template
                const title = prompt("Enter new title:", tmpl.title + " (Copy)");
                if (!title) return;
                saveTemplate(title, tmpl.description);
            } else {
                // Update series template
                saveTemplate(tmpl.title, tmpl.description, state.selectedTemplateId);
            }
        }
    }

    async function saveTemplate(title, description, id = null) {
        const typst = $('#latexSource').val();
        const payload = {
            id: id,
            title,
            description,
            typst,
            seriesId: state.seriesId
        };
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
            showStatus('success', 'Template saved.', correlationId);
            await loadTemplates();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to save template.', error.correlationId);
        }
    }

})();
