/**
 * catalog_csv.js
 * Dedicated CSV import/export + truncate management page bindings.
 */
class CatalogCsvPage {
    constructor() {
        'use strict';

        const apiBase = 'catalog.php';
        const TRUNCATE_TOKEN = 'TRUNCATE';

        // Selector map for caching DOM nodes across handlers.
        const selectors = {
            status: '#status-message',
            csvExportButton: '#csv-export-button',
            csvImportForm: '#csv-import-form',
            csvImportFile: '#csv-import-file',
            csvImportSubmit: '#csv-import-submit',
            csvHistoryTable: '#csv-history-table',
            truncateButton: '#truncate-button',
            truncateAuditTable: '#truncate-audit-table',
            truncateModal: '#truncate-modal',
            truncateBackdrop: '#truncate-modal-backdrop',
            truncateForm: '#truncate-form',
            truncateConfirmInput: '#truncate-confirm-input',
            truncateReasonInput: '#truncate-reason-input',
            truncateCancelButton: '#truncate-cancel-button',
            truncateConfirmButton: '#truncate-confirm-button',
            truncateModalError: '#truncate-modal-error',
        };

        // Local UI state for truncate lock/submission flags.
        const state = {
            truncate: {
                submitting: false,
                serverLock: false,
            },
        };

        // Cache jQuery lookups for re-use.
        const domCache = new Map();
        const $el = (key) => {
            if (!domCache.has(key)) {
                domCache.set(key, $(selectors[key]));
            }
            return domCache.get(key);
        };

        // DataTables registry for cleanup/re-init.
        const dataTableRegistry = new Map();
        const DATA_TABLE_DOM =
            '<"row g-2 align-items-center mb-2"<"col-12 col-md-6"l><"col-12 col-md-6 text-md-end"f>>' +
            't' +
            '<"row g-2 align-items-center mt-2"<"col-12 col-md-6"i><"col-12 col-md-6 text-md-end"p>>';
        const DATA_TABLE_LANGUAGE = {
            search: 'Search:',
            searchPlaceholder: 'Search...',
            zeroRecords: 'No matching records found.',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'Showing 0 entries',
            lengthMenu: 'Show _MENU_ entries',
            paginate: {
                previous: 'Prev',
                next: 'Next',
            },
        };
        const DATA_TABLE_DEFAULTS = {
            paging: true,
            searching: true,
            ordering: true,
            lengthChange: true,
            pageLength: 10,
            lengthMenu: [
                [5, 10, 25, 50, -1],
                [5, 10, 25, 50, 'All'],
            ],
            autoWidth: false,
            info: true,
            dom: DATA_TABLE_DOM,
            language: DATA_TABLE_LANGUAGE,
            order: [[0, 'asc']],
        };

        // Encode text safely for HTML insertion.
        const escapeHtml = (value = '') =>
            String(value ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

        // Convert timestamps into a readable local date/time string.
        const formatDateTime = (timestamp) => {
            if (!timestamp) {
                return '';
            }
            const date = new Date(timestamp);
            if (Number.isNaN(date.getTime())) {
                return '';
            }
            return date.toLocaleString();
        };

        // Wrap jQuery AJAX in a promise with correlation-aware logging.
        const toPromise = (jqXHR, action = 'ajax') =>
            new Promise((resolve, reject) => {
                jqXHR
                    .done((data, textStatus, xhr) => {
                        const correlationId = AppError.extractCorrelationId(data, xhr);
                        AppError.logDev({
                            level: 'info',
                            endpoint: action,
                            status: xhr?.status,
                            correlationId,
                            message: 'ok',
                        });
                        resolve(data);
                    })
                    .fail((xhr) => {
                        const error = AppError.handleAjaxFailure(xhr, action, 'Request failed.');
                        reject(error);
                    });
            });

        // Convenience helpers for common request types.
        const requestJson = (params) => toPromise($.getJSON(apiBase, params), params?.action ?? 'ajax:get');

        const postJson = (action, payload = {}) =>
            toPromise(
                $.ajax({
                    url: `${apiBase}?action=${encodeURIComponent(action)}`,
                    method: 'POST',
                    contentType: 'application/json',
                    dataType: 'json',
                    data: JSON.stringify(payload),
                }),
                action
            );

        const postMultipart = (action, formData) =>
            toPromise(
                $.ajax({
                    url: `${apiBase}?action=${encodeURIComponent(action)}`,
                    method: 'POST',
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    data: formData,
                }),
                action
            );

        // Render a status message with consistent styling.
        const setStatus = (message = '', isError = false) => {
            const $status = $el('status');
            $status.removeClass('status-info status-error');
            if (!message) {
                $status.text('');
                return;
            }
            $status
                .text(`${isError ? 'Error' : 'Info'}: ${message}`)
                .addClass(isError ? 'status-error' : 'status-info');
        };

        // Render an error message while preserving correlation IDs.
        const setStatusWithError = (message, error) => {
            const correlationId = error?.correlationId ?? null;
            setStatus(AppError.buildUserMessage(message, correlationId), true);
        };

        // Normalize server errors that use the standard { success:false, message } envelope.
        const handleErrorResponse = (response, fallback = 'Request failed.') => {
            if (!response) {
                setStatus(AppError.buildUserMessage('Unexpected error occurred.', null), true);
                return;
            }
            const correlationId = AppError.extractCorrelationId(response);
            let message = response.message || response.error?.message || fallback;
            if (response.details) {
                const parts = Object.values(response.details)
                    .filter(Boolean)
                    .map((detail) => detail);
                if (parts.length) {
                    message = `${message} (${parts.join('; ')})`;
                }
            }
            setStatus(AppError.buildUserMessage(message, correlationId), true);
        };

        // Create and/or reuse a table header before DataTables init.
        const buildTableHeader = (tableKey, columns = []) => {
            const $table = $el(tableKey);
            if (!$table.length) {
                return $table;
            }
            const headerHtml = columns.length
                ? `<tr>${columns.map((col) => `<th>${col.title}</th>`).join('')}</tr>`
                : '';
            let $thead = $table.find('thead');
            if (!$thead.length) {
                $thead = $('<thead></thead>').appendTo($table);
            }
            $thead.html(headerHtml);
            if (!$table.find('tbody').length) {
                $('<tbody></tbody>').appendTo($table);
            }
            return $table;
        };

        // Show a default empty state row in a table.
        const setEmptyTableState = (tableKey, columns = [], message = 'No records found.') => {
            const $table = buildTableHeader(tableKey, columns);
            const $tbody = $table.find('tbody');
            const colspan = Math.max(columns.length, 1);
            $tbody.html(
                `<tr><td colspan="${colspan}" class="datatable-empty">${escapeHtml(message)}</td></tr>`
            );
        };

        // Destroy an existing DataTable instance when data is replaced.
        const destroyDataTable = (tableKey) => {
            const entry = dataTableRegistry.get(tableKey);
            if (entry?.instance) {
                entry.instance.destroy();
            }
            dataTableRegistry.delete(tableKey);
        };

        // Sync a DataTable with new columns/rows and fall back to empty state if needed.
        const syncDataTable = (tableKey, columns, rows, options = {}) => {
            if (!Array.isArray(rows) || !rows.length) {
                destroyDataTable(tableKey);
                setEmptyTableState(tableKey, columns, options.emptyMessage);
                return;
            }
            const $table = buildTableHeader(tableKey, columns);
            const extraOptions = options.extraOptions || {};
            let signature = '';
            try {
                signature = JSON.stringify({
                    columns: columns.map((col) => col.title),
                    extra: options.signatureKey || extraOptions,
                });
            } catch (error) {
                signature = columns.map((col) => col.title).join('|');
            }
            const entry = dataTableRegistry.get(tableKey);
            if (entry?.instance && entry.signature === signature) {
                entry.instance.clear();
                entry.instance.rows.add(rows);
                entry.instance.draw(false);
                return;
            }
            destroyDataTable(tableKey);
            const instance = $table.DataTable({
                ...DATA_TABLE_DEFAULTS,
                ...extraOptions,
                data: rows,
                columns,
            });
            dataTableRegistry.set(tableKey, { instance, signature });
        };

        // Toggle CSV UI controls when truncate is running or pending.
        const applyCsvLockState = () => {
            const locked = state.truncate.submitting || state.truncate.serverLock;
            [
                'csvExportButton',
                'csvImportSubmit',
                'csvImportFile',
                'truncateButton',
                'truncateConfirmButton',
            ].forEach((key) => {
                $el(key).prop('disabled', locked);
            });
            if (locked) {
                $el('csvHistoryTable').find('button').prop('disabled', true);
            }
        };

        // Populate the CSV file history DataTable.
        const renderCsvHistory = (files) => {
            const columns = [
                { title: 'Type', data: 'type', width: '90px' },
                { title: 'Name', data: 'name' },
                {
                    title: 'Timestamp',
                    data: 'timestampRaw',
                    render: (data) => formatDateTime(data),
                },
                {
                    title: 'Size (bytes)',
                    data: 'sizeRaw',
                    className: 'text-end',
                    render: (data, type, row) => (type === 'display' ? row.sizeDisplay : data ?? 0),
                },
                {
                    title: 'Actions',
                    data: 'actions',
                    orderable: false,
                    searchable: false,
                    className: 'text-nowrap',
                },
            ];
            if (!files.length) {
                destroyDataTable('csvHistoryTable');
                setEmptyTableState('csvHistoryTable', columns, 'No CSV files stored.');
                return;
            }
            const rows = files.map((file) => {
                const size = Number(file.size || 0);
                return {
                    type: escapeHtml((file.type || '').toString().toUpperCase()),
                    name: escapeHtml(file.name || file.id),
                    timestampRaw: file.timestamp,
                    sizeRaw: Number.isNaN(size) ? 0 : size,
                    sizeDisplay: Number.isNaN(size) ? '0' : size.toLocaleString(),
                    actions: `<div class="datatable-actions">
                        <button type="button" data-csv-download="${file.id}">Download</button>
                        <button type="button" data-csv-restore="${file.id}">Restore</button>
                        <button type="button" data-csv-delete="${file.id}">Delete</button>
                    </div>`,
                };
            });
            syncDataTable('csvHistoryTable', columns, rows, {
                order: [[2, 'desc']],
                pageLength: 5,
                emptyMessage: 'No CSV files stored.',
            });
        };

        // Summarize deleted row counts for audit display.
        const formatDeletedSummary = (deleted = {}) => {
            const preferred = ['categories', 'series', 'products', 'fieldDefinitions', 'productValues', 'seriesValues'];
            const seen = new Set();
            const parts = [];
            preferred.forEach((key) => {
                if (deleted[key] !== undefined) {
                    parts.push(`${key}: ${deleted[key]}`);
                    seen.add(key);
                }
            });
            Object.keys(deleted).forEach((key) => {
                if (!seen.has(key)) {
                    parts.push(`${key}: ${deleted[key]}`);
                }
            });
            return parts.length ? parts.join(', ') : 'n/a';
        };

        // Populate truncate audit history with latest destructive runs.
        const renderTruncateAudits = (audits) => {
            const columns = [
                {
                    title: 'Timestamp',
                    data: 'timestampRaw',
                    render: (data) => formatDateTime(data),
                },
                { title: 'Reason', data: 'reason' },
                { title: 'Deleted', data: 'deleted' },
                { title: 'Audit ID', data: 'auditId' },
            ];
            if (!audits.length) {
                destroyDataTable('truncateAuditTable');
                setEmptyTableState('truncateAuditTable', columns, 'No truncate actions logged.');
                return;
            }
            const rows = audits.map((audit) => ({
                timestampRaw: audit.timestamp,
                reason: escapeHtml(audit.reason || ''),
                deleted: escapeHtml(formatDeletedSummary(audit.deleted)),
                auditId: escapeHtml(audit.id || ''),
            }));
            syncDataTable('truncateAuditTable', columns, rows, {
                order: [[0, 'desc']],
                pageLength: 5,
                emptyMessage: 'No truncate actions logged.',
            });
        };

        // Load CSV history + audit log from the backend and sync UI.
        const loadCsvHistory = async () => {
            try {
                const response = await requestJson({ action: 'v1.listCsvHistory' });
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                const payload = response.data || {};
                renderCsvHistory(payload.files || []);
                renderTruncateAudits(payload.audits || []);
                state.truncate.serverLock = payload.truncateInProgress === true;
                applyCsvLockState();
            } catch (error) {
                console.error(error);
                setStatusWithError('Unable to load CSV history.', error);
            }
        };

        // Kick off a CSV download for the selected file.
        const triggerCsvDownload = (fileId) => {
            if (!fileId) {
                return;
            }
            window.location = `${apiBase}?action=${encodeURIComponent('v1.downloadCsv')}&id=${encodeURIComponent(fileId)}`;
        };

        // Open the truncate modal and reset its state.
        const openTruncateModal = () => {
            $el('truncateForm')[0].reset();
            $el('truncateModalError').text('');
            $el('truncateConfirmButton').prop('disabled', true);
            $el('truncateModal').removeAttr('hidden');
            $el('truncateBackdrop').removeAttr('hidden');
            window.setTimeout(() => {
                $el('truncateConfirmInput').trigger('focus');
            }, 0);
        };

        // Hide the truncate modal/backdrop.
        const closeTruncateModal = () => {
            $el('truncateModal').attr('hidden', true);
            $el('truncateBackdrop').attr('hidden', true);
        };

        // Enable or disable the truncate confirm button based on user input.
        const updateTruncateConfirmState = () => {
            const token = $el('truncateConfirmInput')
                .val()
                .toString()
                .trim()
                .toUpperCase();
            const reason = $el('truncateReasonInput').val().toString().trim();
            $el('truncateConfirmButton').prop('disabled', !(token === TRUNCATE_TOKEN && reason.length > 0));
            $el('truncateModalError').text('');
        };

        // Bind export/import/restore/delete button events.
        const bindCsvEvents = () => {
            $el('csvExportButton').on('click', async () => {
                if (state.truncate.submitting || state.truncate.serverLock) {
                    setStatus('Catalog truncate in progress. Try again after it completes.', true);
                    return;
                }
                const $button = $el('csvExportButton');
                $button.prop('disabled', true);
                try {
                    const response = await postJson('v1.exportCsv', {});
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    const file = response.data || {};
                    setStatus('Catalog CSV exported.', false);
                    await loadCsvHistory();
                    if (file.id) {
                        triggerCsvDownload(file.id);
                    }
                } catch (error) {
                    console.error(error);
                    setStatusWithError('Failed to export catalog CSV.', error);
                } finally {
                    $button.prop('disabled', false);
                }
            });

            $el('csvImportForm').on('submit', async (event) => {
                event.preventDefault();
                if (state.truncate.submitting || state.truncate.serverLock) {
                    setStatus('Catalog truncate in progress. CSV import disabled until it completes.', true);
                    return;
                }
                const fileInput = $el('csvImportFile')[0];
                if (!fileInput.files || !fileInput.files.length) {
                    setStatus('Select a CSV file to import.', true);
                    return;
                }
                const formData = new FormData();
                formData.append('file', fileInput.files[0]);
                const $submit = $el('csvImportSubmit');
                $submit.prop('disabled', true);
                try {
                    const response = await postMultipart('v1.importCsv', formData);
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    const data = response.data || {};
                    const message = `CSV import completed (${data.importedProducts ?? 0} products, ${data.createdSeries ?? 0} new series, ${data.createdCategories ?? 0} new categories).`;
                    setStatus(message, false);
                    fileInput.value = '';
                    await loadCsvHistory();
                } catch (error) {
                    console.error(error);
                    setStatusWithError('Failed to import CSV.', error);
                } finally {
                    $submit.prop('disabled', false);
                }
            });

            $el('csvHistoryTable').on('click', 'button[data-csv-download]', (event) => {
                const fileId = $(event.currentTarget).data('csv-download');
                triggerCsvDownload(fileId);
            });

            $el('csvHistoryTable').on('click', 'button[data-csv-restore]', async (event) => {
                const fileId = $(event.currentTarget).data('csv-restore');
                if (!fileId) {
                    return;
                }
                const $button = $(event.currentTarget);
                $button.prop('disabled', true);
                try {
                    const response = await postJson('v1.restoreCsv', { id: fileId });
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    const data = response.data || {};
                    const message = `CSV restore completed (${data.importedProducts ?? 0} products, ${data.createdSeries ?? 0} new series, ${data.createdCategories ?? 0} new categories).`;
                    setStatus(message, false);
                    await loadCsvHistory();
                } catch (error) {
                    console.error(error);
                    setStatusWithError('Failed to restore CSV file.', error);
                } finally {
                    $button.prop('disabled', false);
                }
            });

            $el('csvHistoryTable').on('click', 'button[data-csv-delete]', async (event) => {
                const fileId = $(event.currentTarget).data('csv-delete');
                if (!fileId) {
                    return;
                }
                if (!window.confirm('Delete this CSV file?')) {
                    return;
                }
                try {
                    const response = await postJson('v1.deleteCsv', { id: fileId });
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('CSV file deleted.', false);
                    await loadCsvHistory();
                } catch (error) {
                    console.error(error);
                    setStatusWithError('Failed to delete CSV file.', error);
                }
            });
        };

        // Bind truncate modal interactions and submit handler.
        const bindTruncateEvents = () => {
            $el('truncateButton').on('click', () => {
                if (state.truncate.submitting || state.truncate.serverLock) {
                    setStatus('Catalog truncate already in progress. Please wait for it to finish.', true);
                    return;
                }
                openTruncateModal();
            });

            $el('truncateConfirmInput').on('input', updateTruncateConfirmState);
            $el('truncateReasonInput').on('input', updateTruncateConfirmState);

            $el('truncateCancelButton').on('click', () => {
                closeTruncateModal();
            });

            $el('truncateForm').on('submit', async (event) => {
                event.preventDefault();
                if (state.truncate.submitting || state.truncate.serverLock) {
                    $el('truncateModalError').text('Another truncate is running. Try again once it completes.');
                    return;
                }
                const confirmToken = $el('truncateConfirmInput')
                    .val()
                    .toString()
                    .trim()
                    .toUpperCase();
                const reason = $el('truncateReasonInput').val().toString().trim();
                if (confirmToken !== TRUNCATE_TOKEN || !reason) {
                    $el('truncateModalError').text('Type TRUNCATE and provide a reason to continue.');
                    return;
                }
                const payload = {
                    reason,
                    confirmToken,
                    correlationId: window.crypto?.randomUUID?.() || `truncate-${Date.now()}`,
                };
                state.truncate.submitting = true;
                applyCsvLockState();
                try {
                    const response = await postJson('v1.truncateCatalog', payload);
                    if (!response.success) {
                        handleErrorResponse(response);
                        $el('truncateModalError').text(response.message || 'Truncate failed.');
                        return;
                    }
                    const auditId = response.data?.auditId || payload.correlationId;
                    setStatus(`Catalog truncated (audit ${auditId}).`, false);
                    closeTruncateModal();
                    state.truncate.submitting = false;
                    await loadCsvHistory();
                } catch (error) {
                    console.error(error);
                    const message = AppError.buildUserMessage('Unable to truncate catalog.', error?.correlationId);
                    $el('truncateModalError').text(message);
                    setStatusWithError('Unable to truncate catalog.', error);
                    state.truncate.submitting = false;
                } finally {
                    applyCsvLockState();
                }
            });
        };

        // Initialize the page bindings and initial data fetch.
        const init = async () => {
            bindCsvEvents();
            bindTruncateEvents();
            updateTruncateConfirmState();
            await loadCsvHistory();
        };

        $(init);
    }
}

new CatalogCsvPage();
