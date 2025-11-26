(function () {
    'use strict';

    /** Tracks UI state for the LaTeX template workspace. */
    const state = {
        table: null,
        templates: new Map(),
        selectedId: null,
        previewTimer: null,
        saving: false,
        building: false
    };

    document.addEventListener('DOMContentLoaded', init);

    /** Initializes DataTables, live preview defaults, and event handlers. */
    function init() {
        state.table = $('#latexTemplatesTable').DataTable({
            data: [],
            columns: [
                { data: 'title', title: 'Title' },
                {
                    data: 'description',
                    title: 'Description',
                    render: function (data) {
                        if (!data) {
                            return '<span class="text-muted">No description</span>';
                        }
                        const text = String(data);
                        return text.length > 80 ? text.slice(0, 80) + '…' : text;
                    }
                },
                { data: 'createdAt', title: 'Created', render: renderDate },
                { data: 'updatedAt', title: 'Updated', render: renderDate },
                {
                    data: null,
                    title: 'Actions',
                    orderable: false,
                    searchable: false,
                    className: 'table-actions text-nowrap',
                    render: function (data, type, row) {
                        const templateId = row && typeof row.id === 'number' ? row.id : 0;
                        return '' +
                            '<button type="button" class="btn btn-sm btn-link text-primary js-edit-template" data-template-id="' + templateId + '">Edit</button>' +
                            '<button type="button" class="btn btn-sm btn-link text-success js-build-template" data-template-id="' + templateId + '">Build</button>' +
                            '<button type="button" class="btn btn-sm btn-link text-danger js-delete-template" data-template-id="' + templateId + '">Delete</button>';
                    }
                }
            ],
            language: {
                search: '',
                searchPlaceholder: 'Search templates…'
            },
            lengthMenu: [10, 25, 50]
        });

        bindEvents();
        refreshTemplates();
        renderPreview('');
        updateActionButtons();
        resetBuildLog();
    }

    /** Wires DOM events for CRUD operations, preview, and DataTable actions. */
    function bindEvents() {
        $('#templateForm').on('submit', handleSaveTemplate);
        $('#latexSource').on('input', schedulePreviewRender);
        $('#newTemplateButton').on('click', function () {
            clearForm();
            showAlert('info', 'Ready to create a new template.');
        });
        $('#buildTemplateButton').on('click', function () {
            triggerBuild(state.selectedId);
        });
        $('#deleteTemplateButton').on('click', handleDeleteFromForm);
        $('#refreshTemplatesButton').on('click', refreshTemplates);

        const tbody = $('#latexTemplatesTable tbody');
        tbody.on('click', '.js-edit-template', handleRowEdit);
        tbody.on('click', '.js-build-template', function (event) {
            const templateId = readTemplateIdFromEvent(event);
            triggerBuild(templateId);
        });
        tbody.on('click', '.js-delete-template', function (event) {
            const templateId = readTemplateIdFromEvent(event);
            if (templateId) {
                deleteTemplate(templateId);
            }
        });
    }

    /** Handles template creation or update submissions. */
    async function handleSaveTemplate(event) {
        event.preventDefault();
        if (state.saving) {
            return;
        }

        const payload = serializeForm();
        const templateId = state.selectedId;
        const action = templateId ? 'v1.updateLatexTemplate' : 'v1.createLatexTemplate';
        const method = templateId ? 'PUT' : 'POST';

        try {
            setSaving(true);
            const data = await apiRequest(action, {
                method: method,
                body: payload,
                query: templateId ? { id: templateId } : undefined
            });
            if (data && typeof data === 'object') {
                populateForm(data);
            }
            await refreshTemplates();
            showAlert('success', templateId ? 'Template updated successfully.' : 'Template created successfully.');
        } catch (error) {
            showAlert('danger', buildErrorMessage(error));
        } finally {
            setSaving(false);
        }
    }

    /** Pulls the latest templates from the backend and refreshes the DataTable. */
    async function refreshTemplates() {
        try {
            const rows = await apiRequest('v1.listLatexTemplates');
            state.templates.clear();
            const templateRows = Array.isArray(rows) ? rows : [];
            templateRows.forEach(function (row) {
                if (row && typeof row.id === 'number') {
                    state.templates.set(row.id, row);
                }
            });
            if (state.table) {
                state.table.clear().rows.add(templateRows).draw();
            }
        } catch (error) {
            showAlert('danger', buildErrorMessage(error));
        }
    }

    /** Responds to Edit clicks originating from the DataTable actions column. */
    function handleRowEdit(event) {
        const templateId = readTemplateIdFromEvent(event);
        if (templateId) {
            loadTemplate(templateId);
        }
    }

    /** Extracts a numeric template id from a delegated event target. */
    function readTemplateIdFromEvent(event) {
        const target = event && event.currentTarget;
        const idValue = target ? Number(target.getAttribute('data-template-id')) : NaN;
        return Number.isInteger(idValue) && idValue > 0 ? idValue : null;
    }

    /** Loads a single template from the backend and hydrates the editor form. */
    async function loadTemplate(templateId) {
        try {
            const data = await apiRequest('v1.getLatexTemplate', { method: 'GET', query: { id: templateId } });
            populateForm(data);
            showAlert('info', 'Editing template #' + templateId + '.');
        } catch (error) {
            showAlert('danger', buildErrorMessage(error));
        }
    }

    /** Binds loaded template data to inputs and preview widgets. */
    function populateForm(template) {
        if (!template) {
            return;
        }
        state.selectedId = typeof template.id === 'number' ? template.id : null;
        $('#templateId').val(state.selectedId != null ? state.selectedId : '');
        $('#templateTitle').val(template.title || '');
        $('#templateDescription').val(template.description || '');
        $('#latexSource').val(template.latex || '');
        setFormModeBadge(state.selectedId);
        updateActionButtons();
        updatePdfSection(template);
        updateBuildLog('', '');
        renderPreview(template.latex || '');
    }

    /** Clears the form, preview, and PDF context. */
    function clearForm() {
        state.selectedId = null;
        $('#templateId').val('');
        $('#templateTitle').val('');
        $('#templateDescription').val('');
        $('#latexSource').val('');
        setFormModeBadge(null);
        updateActionButtons();
        updatePdfSection(null);
        resetBuildLog();
        renderPreview('');
    }

    /** Serializes form inputs into a payload object sent to the API. */
    function serializeForm() {
        return {
            title: ($('#templateTitle').val() || '').toString().trim(),
            description: ($('#templateDescription').val() || '').toString().trim(),
            latex: ($('#latexSource').val() || '').toString()
        };
    }

    /** Initiates the delete flow from the editor panel. */
    function handleDeleteFromForm() {
        if (!state.selectedId) {
            showAlert('warning', 'Select a template before deleting.');
            return;
        }
        deleteTemplate(state.selectedId);
    }

    /** Sends a delete request after confirming with the operator. */
    async function deleteTemplate(templateId) {
        if (!templateId) {
            return;
        }
        const template = state.templates.get(templateId);
        const label = template && template.title ? '"' + template.title + '"' : '#' + templateId;
        if (!window.confirm('Delete template ' + label + '? This cannot be undone.')) {
            return;
        }
        try {
            await apiRequest('v1.deleteLatexTemplate', { method: 'DELETE', query: { id: templateId } });
            if (state.selectedId === templateId) {
                clearForm();
            }
            await refreshTemplates();
            showAlert('success', 'Template ' + label + ' deleted.');
        } catch (error) {
            showAlert('danger', buildErrorMessage(error));
        }
    }

    /** Calls the PDF build endpoint and displays status/log output. */
    async function triggerBuild(templateId) {
        if (!templateId) {
            showAlert('warning', 'Save and select a template before building the PDF.');
            return;
        }
        if (state.building) {
            return;
        }
        try {
            setBuilding(true);
            const result = await apiRequest('v1.buildLatexTemplate', {
                method: 'POST',
                query: { id: templateId }
            });
            await refreshTemplates();
            if (state.selectedId === templateId) {
                updatePdfSection({
                    pdfPath: result && result.pdfPath ? result.pdfPath : null,
                    downloadUrl: result && result.downloadUrl ? result.downloadUrl : null,
                    updatedAt: result && result.updatedAt ? result.updatedAt : null
                });
                updateBuildLog(result && result.log ? result.log : '', result && result.correlationId ? result.correlationId : '');
            }
            const cached = state.templates.get(templateId);
            const name = cached && cached.title ? cached.title : 'template #' + templateId;
            showAlert('success', 'Build completed for ' + name + '.');
        } catch (error) {
            const details = error && error.details ? error.details : {};
            updateBuildLog(details.stderr || '', details.correlationId || '');
            showAlert('danger', buildErrorMessage(error));
        } finally {
            setBuilding(false);
        }
    }

    /** Debounces preview updates as the operator types. */
    function schedulePreviewRender() {
        if (state.previewTimer) {
            window.clearTimeout(state.previewTimer);
        }
        state.previewTimer = window.setTimeout(function () {
            renderPreview(($('#latexSource').val() || '').toString());
        }, 250);
    }

    /** Updates the MathJax preview along with the raw source snapshot. */
    function renderPreview(latex) {
        const sourceEl = document.getElementById('latex-preview-source');
        const renderEl = document.getElementById('latex-preview-render');
        if (!sourceEl || !renderEl) {
            return;
        }
        const content = latex || '';
        sourceEl.textContent = content || 'Start typing to preview your template.';
        if (!content) {
            renderEl.innerHTML = '<p class="text-muted mb-0">Preview will appear here.</p>';
            return;
        }
        if (window.MathJax && typeof window.MathJax.tex2chtmlPromise === 'function') {
            window.MathJax.tex2chtmlPromise(content, { display: true })
                .then(function (node) {
                    renderEl.innerHTML = '';
                    renderEl.appendChild(node);
                    if (window.MathJax && window.MathJax.startup && window.MathJax.startup.document) {
                        window.MathJax.startup.document.clear();
                        window.MathJax.startup.document.updateDocument();
                    }
                })
                .catch(function () {
                    renderEl.innerHTML = '<div class="alert alert-warning mb-0">Preview limited to raw source for this template.</div>';
                });
        } else {
            renderEl.innerHTML = '<p class="text-muted mb-0">MathJax is loading…</p>';
        }
    }

    /** Toggles download/link widgets for the latest generated PDF. */
    function updatePdfSection(template) {
        const link = document.getElementById('pdfDownloadLink');
        const frame = document.getElementById('pdfPreviewFrame');
        const status = document.getElementById('pdfStatusText');
        const downloadUrl = template && template.downloadUrl ? template.downloadUrl : (template && template.pdfPath ? template.pdfPath : null);
        const updatedAt = template && template.updatedAt ? template.updatedAt : null;
        if (link) {
            if (downloadUrl) {
                link.classList.remove('d-none');
                link.setAttribute('href', downloadUrl);
            } else {
                link.classList.add('d-none');
                link.removeAttribute('href');
            }
        }
        if (frame) {
            if (downloadUrl) {
                frame.classList.remove('d-none');
                frame.setAttribute('src', downloadUrl + '?v=' + Date.now());
            } else {
                frame.classList.add('d-none');
                frame.removeAttribute('src');
            }
        }
        if (status) {
            status.textContent = updatedAt ? 'Last built ' + formatDateTime(updatedAt) : 'No PDF generated yet.';
        }
    }

    /** Displays the last compilation log output with an optional correlation id. */
    function updateBuildLog(logText, correlationId) {
        const logEl = document.getElementById('latex-build-log');
        const metaEl = document.getElementById('buildMetaDetails');
        if (logEl) {
            logEl.textContent = logText && logText.trim() !== ''
                ? logText.trim()
                : 'Build output will appear here once a compilation runs.';
        }
        if (metaEl) {
            metaEl.textContent = correlationId ? 'Correlation ID: ' + correlationId : '';
        }
    }

    /** Resets the build log console to its default placeholder. */
    function resetBuildLog() {
        updateBuildLog('', '');
    }

    /** Renders a dismissible Bootstrap alert in the status placeholder. */
    function showAlert(variant, message) {
        const container = document.getElementById('statusAlert');
        if (!container) {
            return;
        }
        const safeMessage = message || 'An unexpected response was returned.';
        container.innerHTML = '' +
            '<div class="alert alert-' + variant + ' alert-dismissible fade show" role="alert">' +
            '  <div>' + safeMessage + '</div>' +
            '  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>' +
            '</div>';
    }

    /** Formats ISO timestamps for DataTable cells. */
    function renderDate(value) {
        if (!value) {
            return '<span class="text-muted">—</span>';
        }
        return formatDateTime(value);
    }

    /** Produces a localized timestamp string with graceful fallbacks. */
    function formatDateTime(value) {
        try {
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return value;
            }
            return date.toLocaleString();
        } catch (error) {
            return value;
        }
    }

    /** Enables/disables the Save button to prevent duplicate clicks. */
    function setSaving(isSaving) {
        state.saving = isSaving;
        $('#saveTemplateButton').prop('disabled', isSaving);
    }

    /** Coordinates Build button state with ongoing MiKTeX invocations. */
    function setBuilding(isBuilding) {
        state.building = isBuilding;
        $('#buildTemplateButton').prop('disabled', isBuilding || !state.selectedId);
    }

    /** Refreshes action button availability and badge text based on selection. */
    function updateActionButtons() {
        const hasSelection = Boolean(state.selectedId);
        $('#deleteTemplateButton').prop('disabled', !hasSelection);
        $('#buildTemplateButton').prop('disabled', !hasSelection || state.building);
        setFormModeBadge(state.selectedId);
    }

    /** Updates the mode badge to signal whether a template is selected. */
    function setFormModeBadge(templateId) {
        const badge = document.getElementById('formModeBadge');
        if (!badge) {
            return;
        }
        if (templateId) {
            badge.textContent = 'Editing #' + templateId;
            badge.classList.remove('bg-info');
            badge.classList.add('bg-success');
        } else {
            badge.textContent = 'New';
            badge.classList.remove('bg-success');
            badge.classList.add('bg-info');
        }
    }

    /** Builds a user-visible error message from an API exception (with correlation ID). */
    function buildErrorMessage(error) {
        if (!error) {
            return 'Unable to complete the request.';
        }
        const correlationId = error.correlationId || null;
        const baseMessage = error.message
            ? error.message
            : 'Request failed. Check the server logs for details.';
        return AppError.buildUserMessage(baseMessage, correlationId);
    }

    /** Issues an AJAX request against catalog.php with JSON handling. */
    async function apiRequest(action, options) {
        const opts = options || {};
        const method = opts.method || 'GET';
        const headers = { Accept: 'application/json' };
        const params = new URLSearchParams({ action: action });
        if (opts.query) {
            Object.keys(opts.query).forEach(function (key) {
                if (opts.query[key] !== undefined && opts.query[key] !== null) {
                    params.set(key, String(opts.query[key]));
                }
            });
        }
        const fetchOptions = { method: method, headers: headers };
        if (opts.body) {
            headers['Content-Type'] = 'application/json';
            fetchOptions.body = JSON.stringify(opts.body);
        }
        const response = await fetch('catalog.php?' + params.toString(), fetchOptions);
        const text = await response.text();
        let payload = {};
        try {
            payload = text ? JSON.parse(text) : {};
        } catch (parseError) {
            payload = {};
        }

        const correlationId = AppError.extractCorrelationId(payload, response);

        if (!response.ok || !payload.success) {
            const message =
                payload?.message ||
                payload?.error?.message ||
                'Request failed.';
            const errorCode =
                payload?.errorCode ||
                payload?.error?.code ||
                'UNKNOWN_ERROR';
            AppError.logDev({
                level: 'error',
                endpoint: action,
                status: response.status,
                errorCode: errorCode,
                correlationId,
                message,
            });
            const error = new Error(AppError.buildUserMessage(message, correlationId));
            error.details = payload.details || payload.error?.details || {};
            error.errorCode = errorCode;
            error.correlationId = correlationId;
            throw error;
        }

        AppError.logDev({
            level: 'info',
            endpoint: action,
            status: response.status,
            correlationId,
            message: 'ok',
        });

        return payload.data || null;
    }
})();
