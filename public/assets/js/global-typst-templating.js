(function () {
    'use strict';

    const state = {
        table: null,
        variables: [],
        selectedVarId: null,
        selectedVarValue: '',
        templates: [],
        selectedTemplateId: null,
        lineNumberUpdater: null,
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        state.lineNumberUpdater = initLineNumberedEditor('latexSource');
        initDataTable();
        bindEvents();
        loadVariables();
        loadTemplates();
    }

    /**
     * Attach a simple line-number gutter to the Typst textarea.
     * Returns a callback to force-refresh numbers after programmatic value changes.
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

    function initDataTable() {
        state.table = $('#savedTemplatesTable').DataTable({
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
            state.table.search(this.value).draw();
        });
    }

    function bindEvents() {
        $('#varType').on('change', handleVarTypeChange);
        $('#variableForm').on('submit', function (event) {
            event.preventDefault();
            handleSaveVariable();
        });
        $('#deleteVarBtn').on('click', handleDeleteVariable);
        $('#variableSearch').on('keyup', filterVariables);

        $('#compileBtn').on('click', handleCompile);
        $('#saveTemplateBtn').on('click', handleSaveTemplate);
        $('#savePdfBtn').on('click', handleSavePdf); // Note: Save PDF usually just compiles and saves, or saves current PDF?
        // In Latex version, savePdfBtn was separate. Here compile returns URL.
        // Maybe savePdfBtn just triggers compile and download?
        // Or maybe it saves the generated PDF to a permanent location?
        // TypstService compile already saves to storage/typst-pdfs.
        // So "Save PDF" might be redundant if compile already saves.
        // Let's assume compile is enough for preview, and "Save PDF" might be for "Finalize" or just download.

        $('#savedTemplatesTable tbody').on('click', '.js-load-template', function () {
            const id = Number($(this).data('id'));
            loadTemplate(id);
        });

        $('#savedTemplatesTable tbody').on('click', '.js-delete-template', function () {
            const id = Number($(this).data('id'));
            if (Number.isInteger(id) && id > 0 && window.confirm('Delete this template?')) {
                deleteTemplate(id);
            }
        });

        $('#savedTemplatesTable tbody').on('click', '.js-download-pdf', function () {
            const id = Number($(this).data('id'));
            handleDownloadTemplate(id);
        });
    }

    function handleVarTypeChange() {
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
            hint.textContent = state.selectedVarValue
                ? `Current stored: ${state.selectedVarValue}`
                : 'No file stored yet. Selecting a file saves its name as the placeholder value.';
            container.append(hint);
        }
    }

    async function handleSaveVariable() {
        const key = ($('#varKey').val() || '').toString().trim();
        const type = $('#varType').val();
        const value = getVarDataValue(type);

        if (!key) {
            showStatus('warning', 'Please enter a field key.');
            return;
        }

        try {
            const { correlationId } = await withLoading(() =>
                requestJson('api/typst/variables.php', {
                    method: 'POST',
                    body: JSON.stringify({
                        id: state.selectedVarId,
                        key,
                        type,
                        value,
                    }),
                })
            );
            showStatus('success', 'Variable saved.', correlationId);
            resetVarForm();
            await loadVariables();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to save variable.', error.correlationId);
        }
    }

    async function handleDeleteVariable() {
        if (!state.selectedVarId) return;
        if (!window.confirm('Are you sure you want to delete this variable?')) return;
        try {
            const { correlationId } = await withLoading(() =>
                requestJson(`api/typst/variables.php?id=${state.selectedVarId}`, { method: 'DELETE' })
            );
            showStatus('success', 'Variable deleted.', correlationId);
            resetVarForm();
            await loadVariables();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to delete variable.', error.correlationId);
        }
    }

    async function loadVariables() {
        try {
            const { data } = await withLoading(() => requestJson('api/typst/variables.php'));
            state.variables = Array.isArray(data) ? data : [];
            renderVariables();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to load variables.', error.correlationId);
        }
    }

    function renderVariables() {
        const list = $('#globalVarsList');
        list.empty();
        if (!state.variables.length) {
            list.html('<div class="text-center text-muted p-3">No variables found.</div>');
            return;
        }
        state.variables.forEach((v) => {
            const item = $(`
                <div class="global-var-item" data-id="${v.id}">
                    <div class="fw-bold">${v.key}</div>
                    <div class="small text-muted">${v.value || ''}</div>
                </div>
            `);
            item.on('click', () => selectVariable(v));
            list.append(item);
        });
    }

    function selectVariable(v) {
        state.selectedVarId = v.id;
        state.selectedVarValue = v.value || '';
        $('#varId').val(v.id);
        $('#varKey').val(v.key);
        const inputType = v.type === 'image' ? 'file' : 'text';
        $('#varType').val(inputType).trigger('change');
        if (inputType !== 'file') {
            window.setTimeout(() => {
                $('#varData').val(v.value || '');
            }, 0);
        }
        $('.global-var-item').removeClass('active');
        $(`.global-var-item[data-id="${v.id}"]`).addClass('active');
    }

    function resetVarForm() {
        state.selectedVarId = null;
        state.selectedVarValue = '';
        $('#variableForm')[0].reset();
        $('#varType').trigger('change');
        $('.global-var-item').removeClass('active');
    }

    function filterVariables() {
        const term = ($(this).val() || '').toString().toLowerCase();
        $('.global-var-item').each(function () {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(term));
        });
    }

    function getVarDataValue(selectedType) {
        const type = selectedType || $('#varType').val();
        if (type === 'file') {
            const fileInput = document.getElementById('varData');
            if (fileInput && fileInput.files && fileInput.files[0]) {
                return fileInput.files[0].name;
            }
            if (state.selectedVarId && state.selectedVarValue) {
                return state.selectedVarValue;
            }
            return ($('#varData').val() || '').toString();
        }
        return ($('#varData').val() || '').toString();
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

    function handleSavePdf() {
        // Just trigger compile for now as it saves PDF
        handleCompile();
    }

    async function handleSaveTemplate() {
        const title = ($('#templateTitle').val() || '').toString().trim();
        const description = ($('#templateDescription').val() || '').toString().trim();
        const typst = ($('#latexSource').val() || '').toString();

        if (!title) {
            showStatus('warning', 'Please enter a title for the template.');
            return;
        }

        const payload = {
            id: state.selectedTemplateId,
            title,
            description,
            typst,
        };
        const method = state.selectedTemplateId ? 'PUT' : 'POST';

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
            if (!state.selectedTemplateId) {
                resetTemplateForm();
            }
        } catch (error) {
            showStatus('danger', error.message || 'Failed to save template.', error.correlationId);
        }
    }

    async function loadTemplates() {
        try {
            const { data } = await withLoading(() => requestJson('api/typst/templates.php'));
            state.templates = Array.isArray(data) ? data : [];
            state.table.clear().rows.add(state.templates).draw();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to load templates.', error.correlationId);
        }
    }

    async function handleDownloadTemplate(id) {
        const template = state.templates.find((t) => t.id === id);
        if (!template) {
            showStatus('warning', 'Template not found.');
            return;
        }

        if (template.downloadUrl) {
            window.open(template.downloadUrl, '_blank');
            return;
        }

        if (!template.typst) {
            showStatus('warning', 'Template has no Typst content to compile.');
            return;
        }

        try {
            const { data, correlationId } = await withLoading(() => requestJson('api/typst/compile.php', {
                method: 'POST',
                body: JSON.stringify({ typst: template.typst }),
            }));

            if (data && data.url) {
                template.downloadUrl = data.url;
                refreshTemplatesTable();
                window.open(data.url, '_blank');
                showStatus('success', 'PDF generated.', correlationId);
            } else {
                showStatus('warning', 'Compilation succeeded but no PDF URL returned.', correlationId);
            }
        } catch (error) {
            showStatus('danger', error.message || 'Failed to compile template.', error.correlationId);
        }
    }

    function refreshTemplatesTable() {
        if (!state.table) return;
        state.table.clear().rows.add(state.templates).draw();
    }

    function loadTemplate(id) {
        const template = state.templates.find((t) => t.id === id);
        if (template) {
            state.selectedTemplateId = template.id;
            $('#templateTitle').val(template.title || '');
            $('#templateDescription').val(template.description || '');
            $('#latexSource').val(template.typst || '');
            if (typeof state.lineNumberUpdater === 'function') {
                state.lineNumberUpdater();
            }
            handleCompile();
        }
    }

    async function deleteTemplate(id) {
        try {
            const { correlationId } = await withLoading(() =>
                requestJson(`api/typst/templates.php?id=${id}`, { method: 'DELETE' })
            );
            showStatus('success', 'Template deleted.', correlationId);
            if (state.selectedTemplateId === id) {
                resetTemplateForm();
            }
            await loadTemplates();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to delete template.', error.correlationId);
        }
    }

    function resetTemplateForm() {
        state.selectedTemplateId = null;
        $('#templateTitle').val('');
        $('#templateDescription').val('');
        $('#latexSource').val('');
        if (typeof state.lineNumberUpdater === 'function') {
            state.lineNumberUpdater();
        }
        document.getElementById('latex-preview-render').innerHTML =
            '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
    }
})();
