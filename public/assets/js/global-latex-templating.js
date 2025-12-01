(function () {
    'use strict';

    const state = {
        table: null,
        variables: [],
        selectedVarId: null,
        selectedVarValue: '',
        templates: [],
        selectedTemplateId: null,
    };

    document.addEventListener('DOMContentLoaded', init);

    /**
     * Entry point that wires DataTables, events, and initial data loads.
     */
    function init() {
        initDataTable();
        bindEvents();
        loadVariables();
        loadTemplates();
    }

    /**
     * Render a status alert with optional correlation reference.
     */
    function showStatus(type, message, correlationId) {
        const alertHost = document.getElementById('statusAlert');
        if (!alertHost) {
            return;
        }
        const text =
            window.AppError && window.AppError.buildUserMessage
                ? window.AppError.buildUserMessage(message, correlationId || null)
                : message;
        alertHost.innerHTML = `<div class="alert alert-${type} mb-3" role="status">${text}</div>`;
    }

    /**
     * Wrap fetch + JSON parsing with error/correlation handling.
     */
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
        const correlationId =
            window.AppError && window.AppError.extractCorrelationId
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

    /**
     * Apply global loading overlay when available.
     */
    function withLoading(promiseFactory) {
        if (window.LoadingOverlay && typeof window.LoadingOverlay.wrapPromise === 'function') {
            return window.LoadingOverlay.wrapPromise(promiseFactory);
        }
        try {
            return Promise.resolve(
                typeof promiseFactory === 'function' ? promiseFactory() : promiseFactory
            );
        } catch (error) {
            return Promise.reject(error);
        }
    }

    /**
     * Initialize DataTables for saved templates.
     */
    function initDataTable() {
        state.table = $('#savedTemplatesTable').DataTable({
            data: [],
            columns: [
                { data: 'title' },
                { data: 'description' },
                {
                    data: null,
                    render: function (data, type, row) {
                        return `
                            <button class="btn btn-sm btn-outline-primary js-load-template" data-id="${row.id}">Load</button>
                            <button class="btn btn-sm btn-outline-danger js-delete-template" data-id="${row.id}">Delete</button>
                            <button class="btn btn-sm btn-outline-secondary js-download-pdf" data-id="${row.id}">PDF Download</button>
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

    /**
     * Wire DOM events for variables and templates.
     */
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
    }

    /**
     * Swap the variable input control based on selected type.
     */
    function handleVarTypeChange() {
        const type = $('#varType').val();
        const container = $('#varDataContainer');
        container.find('[data-file-hint="true"]').remove();
        let html = '';

        if (type === 'text' || type === 'textarea') {
            html =
                type === 'textarea'
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

    /**
     * Persist variable create/update then refresh list.
     */
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
                requestJson('api/latex/variables.php', {
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

    /**
     * Delete the selected variable.
     */
    async function handleDeleteVariable() {
        if (!state.selectedVarId) {
            return;
        }
        if (!window.confirm('Are you sure you want to delete this variable?')) {
            return;
        }
        try {
            const { correlationId } = await withLoading(() =>
                requestJson(`api/latex/variables.php?id=${state.selectedVarId}`, {
                    method: 'DELETE',
                })
            );
            showStatus('success', 'Variable deleted.', correlationId);
            resetVarForm();
            await loadVariables();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to delete variable.', error.correlationId);
        }
    }

    /**
     * Load variable list and render UI.
     */
    async function loadVariables() {
        try {
            const { data } = await withLoading(() => requestJson('api/latex/variables.php'));
            state.variables = Array.isArray(data) ? data : [];
            renderVariables();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to load variables.', error.correlationId);
        }
    }

    /**
     * Render variable badges list.
     */
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

    /**
     * Populate form with selected variable.
     */
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

    /**
     * Reset variable form and state.
     */
    function resetVarForm() {
        state.selectedVarId = null;
        state.selectedVarValue = '';
        $('#variableForm')[0].reset();
        $('#varType').trigger('change');
        $('.global-var-item').removeClass('active');
    }

    /**
     * Filter variables list client-side.
     */
    function filterVariables() {
        const term = ($(this).val() || '').toString().toLowerCase();
        $('.global-var-item').each(function () {
            const text = $(this).text().toLowerCase();
            $(this).toggle(text.includes(term));
        });
    }

    /**
     * Read value from the dynamic variable input.
     */
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

    /**
     * Compile the LaTeX preview using MathJax.
     */
    function handleCompile() {
        const latex = $('#latexSource').val();
        const previewEl = document.getElementById('latex-preview-render');

        if (!latex) {
            previewEl.innerHTML = '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
            return;
        }

        if (window.MathJax) {
            previewEl.innerHTML = 'Compiling...';
            window.MathJax.tex2chtmlPromise(latex, { display: true })
                .then((node) => {
                    previewEl.innerHTML = '';
                    previewEl.appendChild(node);
                    window.MathJax.startup.document.clear();
                    window.MathJax.startup.document.updateDocument();
                })
                .catch((err) => {
                    previewEl.innerHTML = `<div class="alert alert-danger">Error: ${err.message}</div>`;
                });
        }
    }

    /**
     * Save a template (create/update) to the API.
     */
    async function handleSaveTemplate() {
        const title = ($('#templateTitle').val() || '').toString().trim();
        const description = ($('#templateDescription').val() || '').toString().trim();
        const latex = ($('#latexSource').val() || '').toString();

        if (!title) {
            showStatus('warning', 'Please enter a title for the template.');
            return;
        }

        const payload = {
            id: state.selectedTemplateId,
            title,
            description,
            latex,
        };
        const method = state.selectedTemplateId ? 'PUT' : 'POST';

        try {
            const { data, correlationId } = await withLoading(() =>
                requestJson('api/latex/templates.php', {
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

    /**
     * Load template collection into the table.
     */
    async function loadTemplates() {
        try {
            const { data } = await withLoading(() => requestJson('api/latex/templates.php'));
            state.templates = Array.isArray(data) ? data : [];
            state.table.clear().rows.add(state.templates).draw();
        } catch (error) {
            showStatus('danger', error.message || 'Failed to load templates.', error.correlationId);
        }
    }

    /**
     * Populate editor with selected template.
     */
    function loadTemplate(id) {
        const template = state.templates.find((t) => t.id === id);
        if (template) {
            state.selectedTemplateId = template.id;
            $('#templateTitle').val(template.title || '');
            $('#templateDescription').val(template.description || '');
            $('#latexSource').val(template.latex || '');
            handleCompile();
        }
    }

    /**
     * Delete template by id.
     */
    async function deleteTemplate(id) {
        try {
            const { correlationId } = await withLoading(() =>
                requestJson(`api/latex/templates.php?id=${id}`, { method: 'DELETE' })
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

    /**
     * Clear template form fields and selection.
     */
    function resetTemplateForm() {
        state.selectedTemplateId = null;
        $('#templateTitle').val('');
        $('#templateDescription').val('');
        $('#latexSource').val('');
        document.getElementById('latex-preview-render').innerHTML =
            '<p class="text-muted text-center mt-5">Preview will appear here.</p>';
    }
})();
