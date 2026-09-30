export class LatexTemplatePage {
    constructor() {
        this.state = {
            table: null,
            templates: new Map(),
            selectedId: null,
            previewTimer: null,
            saving: false,
            building: false
        };
        for (const methodName of Object.getOwnPropertyNames(Object.getPrototypeOf(this))) {
            if (methodName !== 'constructor' && typeof this[methodName] === 'function') {
                this[methodName] = this[methodName].bind(this);
            }
        }
        document.addEventListener('DOMContentLoaded', this.init);
    }

    /** Tracks UI state for the LaTeX template workspace. */


    /** Wrap async work with the global loading overlay when available. */
    withLoading(promiseFactory) {
        const page = this;
        if (window.LoadingOverlay && typeof window.LoadingOverlay.wrapPromise === 'function') {
            return window.LoadingOverlay.wrapPromise(promiseFactory);
        }
        return promiseFactory();
    }


    /** Initializes DataTables, live preview defaults, and event handlers. */
    init() {
        const page = this;
        page.state.table = $('#latexTemplatesTable').DataTable({
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
                { data: 'createdAt', title: 'Created', render: page.renderDate },
                { data: 'updatedAt', title: 'Updated', render: page.renderDate },
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

        page.bindEvents();
        page.refreshTemplates();
        page.renderPreview('');
        page.updateActionButtons();
        page.resetBuildLog();
    }

    /** Wires DOM events for CRUD operations, preview, and DataTable actions. */
    bindEvents() {
        const page = this;
        $('#templateForm').on('submit', page.handleSaveTemplate);
        $('#latexSource').on('input', page.schedulePreviewRender);
        $('#newTemplateButton').on('click', function () {
            page.clearForm();
            page.showAlert('info', 'Ready to create a new template.');
        });
        $('#buildTemplateButton').on('click', function () {
            page.triggerBuild(page.state.selectedId);
        });
        $('#deleteTemplateButton').on('click', page.handleDeleteFromForm);
        $('#refreshTemplatesButton').on('click', page.refreshTemplates);

        const tbody = $('#latexTemplatesTable tbody');
        tbody.on('click', '.js-edit-template', page.handleRowEdit);
        tbody.on('click', '.js-build-template', function (event) {
            const templateId = page.readTemplateIdFromEvent(event);
            page.triggerBuild(templateId);
        });
        tbody.on('click', '.js-delete-template', function (event) {
            const templateId = page.readTemplateIdFromEvent(event);
            if (templateId) {
                page.deleteTemplate(templateId);
            }
        });
    }

    /** Handles template creation or update submissions. */
    async handleSaveTemplate(event) {
        const page = this;
        event.preventDefault();
        if (page.state.saving) {
            return;
        }

        const payload = page.serializeForm();
        const templateId = page.state.selectedId;
        const action = templateId ? 'v1.updateLatexTemplate' : 'v1.createLatexTemplate';
        const method = templateId ? 'PUT' : 'POST';

        try {
            page.setSaving(true);
            const data = await page.apiRequest(action, {
                method: method,
                body: payload,
                query: templateId ? { id: templateId } : undefined
            });
            if (data && typeof data === 'object') {
                page.populateForm(data);
            }
            await page.refreshTemplates();
            page.showAlert('success', templateId ? 'Template updated successfully.' : 'Template created successfully.');
        } catch (error) {
            page.showAlert('danger', page.buildErrorMessage(error));
        } finally {
            page.setSaving(false);
        }
    }

    /** Pulls the latest templates from the backend and refreshes the DataTable. */
    async refreshTemplates() {
        const page = this;
        try {
            const rows = await page.apiRequest('v1.listLatexTemplates');
            page.state.templates.clear();
            const templateRows = Array.isArray(rows) ? rows : [];
            templateRows.forEach(function (row) {
                if (row && typeof row.id === 'number') {
                    page.state.templates.set(row.id, row);
                }
            });
            if (page.state.table) {
                page.state.table.clear().rows.add(templateRows).draw();
            }
        } catch (error) {
            page.showAlert('danger', page.buildErrorMessage(error));
        }
    }

    /** Responds to Edit clicks originating from the DataTable actions column. */
    handleRowEdit(event) {
        const page = this;
        const templateId = page.readTemplateIdFromEvent(event);
        if (templateId) {
            page.loadTemplate(templateId);
        }
    }

    /** Extracts a numeric template id from a delegated event target. */
    readTemplateIdFromEvent(event) {
        const page = this;
        const target = event && event.currentTarget;
        const idValue = target ? Number(target.getAttribute('data-template-id')) : NaN;
        return Number.isInteger(idValue) && idValue > 0 ? idValue : null;
    }

    /** Loads a single template from the backend and hydrates the editor form. */
    async loadTemplate(templateId) {
        const page = this;
        try {
            const data = await page.apiRequest('v1.getLatexTemplate', { method: 'GET', query: { id: templateId } });
            page.populateForm(data);
            page.showAlert('info', 'Editing template #' + templateId + '.');
        } catch (error) {
            page.showAlert('danger', page.buildErrorMessage(error));
        }
    }

    /** Binds loaded template data to inputs and preview widgets. */
    populateForm(template) {
        const page = this;
        if (!template) {
            return;
        }
        page.state.selectedId = typeof template.id === 'number' ? template.id : null;
        $('#templateId').val(page.state.selectedId != null ? page.state.selectedId : '');
        $('#templateTitle').val(template.title || '');
        $('#templateDescription').val(template.description || '');
        $('#latexSource').val(template.latex || '');
        page.setFormModeBadge(page.state.selectedId);
        page.updateActionButtons();
        page.updatePdfSection(template);
        page.updateBuildLog('', '');
        page.renderPreview(template.latex || '');
    }

    /** Clears the form, preview, and PDF context. */
    clearForm() {
        const page = this;
        page.state.selectedId = null;
        $('#templateId').val('');
        $('#templateTitle').val('');
        $('#templateDescription').val('');
        $('#latexSource').val('');
        page.setFormModeBadge(null);
        page.updateActionButtons();
        page.updatePdfSection(null);
        page.resetBuildLog();
        page.renderPreview('');
    }

    /** Serializes form inputs into a payload object sent to the API. */
    serializeForm() {
        const page = this;
        return {
            title: ($('#templateTitle').val() || '').toString().trim(),
            description: ($('#templateDescription').val() || '').toString().trim(),
            latex: ($('#latexSource').val() || '').toString()
        };
    }

    /** Initiates the delete flow from the editor panel. */
    handleDeleteFromForm() {
        const page = this;
        if (!page.state.selectedId) {
            page.showAlert('warning', 'Select a template before deleting.');
            return;
        }
        page.deleteTemplate(page.state.selectedId);
    }

    /** Sends a delete request after confirming with the operator. */
    async deleteTemplate(templateId) {
        const page = this;
        if (!templateId) {
            return;
        }
        const template = page.state.templates.get(templateId);
        const label = template && template.title ? '"' + template.title + '"' : '#' + templateId;
        if (!window.confirm('Delete template ' + label + '? This cannot be undone.')) {
            return;
        }
        try {
            await page.apiRequest('v1.deleteLatexTemplate', { method: 'DELETE', query: { id: templateId } });
            if (page.state.selectedId === templateId) {
                page.clearForm();
            }
            await page.refreshTemplates();
            page.showAlert('success', 'Template ' + label + ' deleted.');
        } catch (error) {
            page.showAlert('danger', page.buildErrorMessage(error));
        }
    }

    /** Calls the PDF build endpoint and displays status/log output. */
    async triggerBuild(templateId) {
        const page = this;
        if (!templateId) {
            page.showAlert('warning', 'Save and select a template before building the PDF.');
            return;
        }
        if (page.state.building) {
            return;
        }
        try {
            page.setBuilding(true);
            const result = await page.apiRequest('v1.buildLatexTemplate', {
                method: 'POST',
                query: { id: templateId }
            });
            await page.refreshTemplates();
            if (page.state.selectedId === templateId) {
                page.updatePdfSection({
                    pdfPath: result && result.pdfPath ? result.pdfPath : null,
                    downloadUrl: result && result.downloadUrl ? result.downloadUrl : null,
                    updatedAt: result && result.updatedAt ? result.updatedAt : null
                });
                page.updateBuildLog(result && result.log ? result.log : '', result && result.correlationId ? result.correlationId : '');
            }
            const cached = page.state.templates.get(templateId);
            const name = cached && cached.title ? cached.title : 'template #' + templateId;
            page.showAlert('success', 'Build completed for ' + name + '.');
        } catch (error) {
            const details = error && error.details ? error.details : {};
            page.updateBuildLog(details.stderr || '', details.correlationId || '');
            page.showAlert('danger', page.buildErrorMessage(error));
        } finally {
            page.setBuilding(false);
        }
    }

    /** Debounces preview updates as the operator types. */
    schedulePreviewRender() {
        const page = this;
        if (page.state.previewTimer) {
            window.clearTimeout(page.state.previewTimer);
        }
        page.state.previewTimer = window.setTimeout(function () {
            page.renderPreview(($('#latexSource').val() || '').toString());
        }, 250);
    }

    /** Updates the MathJax preview along with the raw source snapshot. */
    renderPreview(latex) {
        const page = this;
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
    updatePdfSection(template) {
        const page = this;
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
            status.textContent = updatedAt ? 'Last built ' + page.formatDateTime(updatedAt) : 'No PDF generated yet.';
        }
    }

    /** Displays the last compilation log output with an optional correlation id. */
    updateBuildLog(logText, correlationId) {
        const page = this;
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
    resetBuildLog() {
        const page = this;
        page.updateBuildLog('', '');
    }

    /** Renders a dismissible Bootstrap alert in the status placeholder. */
    showAlert(variant, message) {
        const page = this;
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
    renderDate(value) {
        const page = this;
        if (!value) {
            return '<span class="text-muted">—</span>';
        }
        return page.formatDateTime(value);
    }

    /** Produces a localized timestamp string with graceful fallbacks. */
    formatDateTime(value) {
        const page = this;
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
    setSaving(isSaving) {
        const page = this;
        page.state.saving = isSaving;
        $('#saveTemplateButton').prop('disabled', isSaving);
    }

    /** Coordinates Build button state with ongoing MiKTeX invocations. */
    setBuilding(isBuilding) {
        const page = this;
        page.state.building = isBuilding;
        $('#buildTemplateButton').prop('disabled', isBuilding || !page.state.selectedId);
    }

    /** Refreshes action button availability and badge text based on selection. */
    updateActionButtons() {
        const page = this;
        const hasSelection = Boolean(page.state.selectedId);
        $('#deleteTemplateButton').prop('disabled', !hasSelection);
        $('#buildTemplateButton').prop('disabled', !hasSelection || page.state.building);
        page.setFormModeBadge(page.state.selectedId);
    }

    /** Updates the mode badge to signal whether a template is selected. */
    setFormModeBadge(templateId) {
        const page = this;
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
    buildErrorMessage(error) {
        const page = this;
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
    async apiRequest(action, options) {
        const page = this;
        return page.withLoading(async () => {
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
        });
    }
}

new LatexTemplatePage();
