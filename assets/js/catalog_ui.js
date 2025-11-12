/**
 * catalog_ui.js
 * ES6 module wrapper for the Product Catalog Manager UI.
 */
(() => {
    'use strict';

    const apiBase = 'catalog.php';
    const FIELD_SCOPE = {
        PRODUCT: 'product_attribute',
        SERIES: 'series_metadata',
    };
    const TRUNCATE_TOKEN = 'TRUNCATE';

    const selectors = {
        status: '#status-message',
        hierarchyContainer: '#hierarchy-container',
        selectedNodeDetails: '#selected-node-details',
        seriesManagement: '#series-management',
        nodeCreateForm: '#node-create-form',
        nodeUpdateForm: '#node-update-form',
        nodeDeleteButton: '#node-delete-button',
        createParentId: '#create-parent-id',
        createNodeName: '#create-node-name',
        createNodeType: '#create-node-type',
        createDisplayOrder: '#create-display-order',
        updateNodeId: '#update-node-id',
        updateNodeIdText: '#update-node-id-text',
        updateNodeParentId: '#update-node-parent-id',
        updateNodeTypeText: '#update-node-type-text',
        updateNodeTypeValue: '#update-node-type-value',
        updateNodeName: '#update-node-name',
        updateNodeDisplayOrder: '#update-node-display-order',
        seriesFieldsTable: '#series-fields-table',
        seriesFieldForm: '#series-field-form',
        seriesFieldId: '#series-field-id',
        seriesFieldKey: '#series-field-key',
        seriesFieldLabel: '#series-field-label',
        seriesFieldScope: '#series-field-scope',
        seriesFieldSortOrder: '#series-field-sort-order',
        seriesFieldRequired: '#series-field-required',
        seriesFieldSubmit: '#series-field-submit',
        seriesFieldClearButton: '#series-field-clear-button',
        seriesMetadataFieldsTable: '#series-metadata-fields-table',
        seriesMetadataFieldForm: '#series-metadata-field-form',
        seriesMetadataFieldId: '#series-metadata-field-id',
        seriesMetadataFieldKey: '#series-metadata-field-key',
        seriesMetadataFieldLabel: '#series-metadata-field-label',
        seriesMetadataFieldSortOrder: '#series-metadata-field-sort-order',
        seriesMetadataFieldRequired: '#series-metadata-field-required',
        seriesMetadataFieldSubmit: '#series-metadata-field-submit',
        seriesMetadataFieldClearButton: '#series-metadata-field-clear-button',
        seriesMetadataForm: '#series-metadata-form',
        seriesMetadataValues: '#series-metadata-values',
        seriesMetadataSaveButton: '#series-metadata-save-button',
        seriesMetadataResetButton: '#series-metadata-reset-button',
        productListTable: '#product-list-table',
        productForm: '#product-form',
        productId: '#product-id',
        productSku: '#product-sku',
        productName: '#product-name',
        productDescription: '#product-description',
        productCustomFields: '#product-custom-fields',
        productSubmit: '#product-submit',
        productClearButton: '#product-clear-button',
        productDeleteButton: '#product-delete-button',
        csvExportButton: '#csv-export-button',
        csvImportForm: '#csv-import-form',
        csvImportFile: '#csv-import-file',
        csvImportSubmit: '#csv-import-submit',
        csvHistoryTable: '#csv-history-table',
        truncateButton: '#truncate-button',
        truncateModal: '#truncate-modal',
        truncateBackdrop: '#truncate-modal-backdrop',
        truncateForm: '#truncate-form',
        truncateConfirmInput: '#truncate-confirm-input',
        truncateReasonInput: '#truncate-reason-input',
        truncateCancelButton: '#truncate-cancel-button',
        truncateConfirmButton: '#truncate-confirm-button',
        truncateModalError: '#truncate-modal-error',
        truncateAuditTable: '#truncate-audit-table',
    };

    const domCache = new Map();
    const $el = (key) => {
        if (!domCache.has(key)) {
            domCache.set(key, $(selectors[key]));
        }
        return domCache.get(key);
    };

    const state = {
        hierarchy: [],
        nodeIndex: new Map(),
        selectedNodeId: null,
        seriesRequestId: 0,
        seriesFields: [],
        seriesMetadataFields: [],
        seriesMetadataValues: {},
        products: [],
        selectedProductId: null,
        truncate: {
            submitting: false,
            serverLock: false,
        },
    };
    const escapeHtml = (value = '') =>
        String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

    const toInt = (value, fallback = 0) => {
        const parsed = parseInt(value, 10);
        return Number.isNaN(parsed) ? fallback : parsed;
    };

    const formatDateTime = (timestamp) => {
        if (!timestamp) {
            return '';
        }
        const date = new Date(timestamp);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
    };

    const createCorrelationId = () =>
        window.crypto?.randomUUID?.() ?? `truncate-${Date.now()}`;

    const toPromise = (jqXHR) =>
        new Promise((resolve, reject) => {
            jqXHR.done(resolve).fail(reject);
        });

    const requestJson = (params) => toPromise($.getJSON(apiBase, params));

    const postJson = (action, payload = {}) =>
        toPromise(
            $.ajax({
                url: `${apiBase}?action=${encodeURIComponent(action)}`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(payload),
            })
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
            })
        );

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

    const handleErrorResponse = (response) => {
        if (!response) {
            setStatus('Unexpected error occurred.', true);
            return;
        }
        let message = response.message || 'Request failed.';
        if (response.details) {
            const parts = Object.values(response.details)
                .filter(Boolean)
                .map((detail) => detail);
            if (parts.length) {
                message = `${message} (${parts.join('; ')})`;
            }
        }
        setStatus(message, true);
    };

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

    const buildNodeIndex = (nodes, parentId = null) => {
        nodes.forEach((node) => {
            const normalized = {
                id: Number(node.id),
                name: node.name ?? node.Name ?? '(unnamed)',
                type: node.type ?? node.Type ?? 'category',
                parentId: node.parentId ?? node.parent_id ?? parentId,
                displayOrder: node.displayOrder ?? node.display_order ?? 0,
                children: Array.isArray(node.children) ? node.children : [],
            };
            state.nodeIndex.set(normalized.id, normalized);
            buildNodeIndex(normalized.children, normalized.id);
        });
    };

    const buildHierarchyList = (nodes) => {
        if (!nodes || nodes.length === 0) {
            return '<div>No categories defined.</div>';
        }

        const renderList = (list, depth = 0) => {
            const items = list
                .map((node) => {
                    const label = `${escapeHtml(
                        node.name ?? node.Name ?? '(unnamed)'
                    )} [${escapeHtml(node.type ?? node.Type ?? 'unknown')}]`;
                    const children = Array.isArray(node.children)
                        ? node.children
                        : [];
                    const hasAccordion =
                        node.type === 'category' && children.length > 0;
                    const expanded = depth === 0;
                    const childMarkup = hasAccordion
                        ? `<div class="node-children${expanded ? ' is-open' : ''}" id="node-${node.id}">
                               ${renderList(children, depth + 1)}
                           </div>`
                        : '';
                    const toggle = hasAccordion
                        ? `<button type="button"
                                   class="node-toggle"
                                   aria-expanded="${expanded}"
                                   aria-label="Toggle children for ${escapeHtml(
                                       node.name ?? ''
                                   )}"
                                   data-node-toggle="node-${node.id}"></button>`
                        : '<span class="node-toggle-spacer"></span>';

                    return `<li class="hierarchy-node">
                                <div class="node-row">
                                    ${toggle}
                                    <a href="#" data-node-id="${node.id}">${label}</a>
                                </div>
                                ${childMarkup}
                            </li>`;
                })
                .join('');

            return `<ul class="hierarchy-list">${items}</ul>`;
        };

        return renderList(nodes);
    };

    const renderHierarchy = () => {
        $el('hierarchyContainer').html(buildHierarchyList(state.hierarchy));
    };

    const resetSeriesUI = () => {
        $el('seriesFieldsTable').empty();
        $el('seriesMetadataFieldsTable').empty();
        $el('seriesMetadataValues').empty();
        $el('productListTable').empty();
        resetSeriesFieldForm();
        resetSeriesMetadataFieldForm();
        resetSeriesMetadataForm();
        resetProductForm();
        $el('seriesManagement').prop('hidden', true);
    };

    const selectNode = (nodeId) => {
        if (!state.nodeIndex.has(nodeId)) {
            state.selectedNodeId = null;
        } else {
            state.selectedNodeId = nodeId;
        }
        updateSelectedNodePanel();
    };

    const updateSelectedNodePanel = () => {
        const $details = $el('selectedNodeDetails');
        const node = state.nodeIndex.get(state.selectedNodeId);
        if (!node) {
            $details.text('Select a category or series to view details.');
            $el('updateNodeId').val('');
            $el('updateNodeIdText').text('None');
            $el('updateNodeParentId').text('N/A');
            $el('updateNodeTypeText').text('N/A');
            $el('updateNodeTypeValue').val('');
            $el('updateNodeName').val('');
            $el('updateNodeDisplayOrder').val('0');
            $el('nodeDeleteButton').prop('disabled', true);
            resetSeriesUI();
            return;
        }

        const parentLabel =
            node.parentId === null || node.parentId === undefined
                ? '(root)'
                : node.parentId;

        const info = [
            `ID: ${node.id}`,
            `Parent ID: ${parentLabel}`,
            `Type: ${node.type}`,
            `Display Order: ${node.displayOrder}`,
        ];
        $details.html(info.map((line) => `<p>${escapeHtml(line)}</p>`).join(''));

        $el('updateNodeId').val(node.id);
        $el('updateNodeIdText').text(String(node.id));
        $el('updateNodeParentId').text(String(parentLabel));
        $el('updateNodeTypeText').text(node.type);
        $el('updateNodeTypeValue').val(node.type);
        $el('updateNodeName').val(node.name);
        $el('updateNodeDisplayOrder').val(node.displayOrder);
        $el('nodeDeleteButton').prop('disabled', false);

        if (node.type === 'series') {
            $el('seriesManagement').prop('hidden', false);
            loadSeriesContext(node.id);
        } else {
            resetSeriesUI();
        }
    };

    const loadHierarchy = async () => {
        try {
            setStatus('');
            const response = await requestJson({ action: 'v1.listHierarchy' });
            if (!response.success) {
                handleErrorResponse(response);
                return;
            }
            const payload = response.data || {};
            state.hierarchy = Array.isArray(payload.hierarchy)
                ? payload.hierarchy
                : [];
            state.nodeIndex = new Map();
            buildNodeIndex(state.hierarchy);
            if (!state.nodeIndex.has(state.selectedNodeId)) {
                state.selectedNodeId = null;
            }
            renderHierarchy();
            updateSelectedNodePanel();
        } catch (error) {
            console.error(error);
            setStatus('Unable to load hierarchy.', true);
        }
    };

    const loadSeriesContext = async (seriesId) => {
        if (!seriesId) {
            return;
        }
        state.seriesRequestId += 1;
        const requestId = state.seriesRequestId;
        state.selectedProductId = null;

        try {
            const [
                productFields,
                metadataFields,
                metadataValues,
                products,
            ] = await Promise.all([
                requestJson({ action: 'v1.listSeriesFields', seriesId }),
                requestJson({
                    action: 'v1.listSeriesFields',
                    seriesId,
                    scope: FIELD_SCOPE.SERIES,
                }),
                requestJson({ action: 'v1.getSeriesAttributes', seriesId }),
                requestJson({ action: 'v1.listProducts', seriesId }),
            ]);

            if (requestId !== state.seriesRequestId) {
                return;
            }

            if (!productFields.success) {
                handleErrorResponse(productFields);
                return;
            }
            if (!metadataFields.success) {
                handleErrorResponse(metadataFields);
                return;
            }
            if (!metadataValues.success) {
                handleErrorResponse(metadataValues);
                return;
            }
            if (!products.success) {
                handleErrorResponse(products);
                return;
            }

            state.seriesFields = productFields.data || [];
            state.seriesMetadataFields =
                metadataValues.data?.definitions ??
                metadataFields.data ??
                [];
            state.seriesMetadataValues = metadataValues.data?.values || {};
            state.products = Array.isArray(products.data) ? products.data : [];

            renderSeriesFieldsTable();
            renderSeriesMetadataFieldsTable();
            renderSeriesMetadataValues();
            renderProductList();
            renderProductFormFields();
        } catch (error) {
            console.error(error);
            setStatus('Unable to load series context.', true);
        }
    };
    const getProductFields = () => state.seriesFields || [];
    const getMetadataFields = () => state.seriesMetadataFields || [];

    const findFieldById = (fieldId, scope) => {
        const list =
            scope === FIELD_SCOPE.SERIES ? getMetadataFields() : getProductFields();
        return list.find((field) => Number(field.id) === Number(fieldId)) || null;
    };

    const renderSeriesFieldsTable = () => {
        const fields = getProductFields();
        const $table = $el('seriesFieldsTable');
        if (!fields.length) {
            $table.html('<tr><td>No product attribute fields defined for this series.</td></tr>');
            renderProductFormFields();
            return;
        }
        const rows = [
            `<tr><th>ID</th><th>Key</th><th>Label</th><th>Required</th><th>Sort</th><th>Actions</th></tr>`,
            ...fields.map((field) => {
                const required = field.isRequired ? 'yes' : 'no';
                return `<tr>
                    <td>${field.id}</td>
                    <td>${escapeHtml(field.fieldKey)}</td>
                    <td>${escapeHtml(field.label)}</td>
                    <td>${required}</td>
                    <td>${field.sortOrder ?? 0}</td>
                    <td>
                        <button type="button" class="series-field-action" data-field-action="edit" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.PRODUCT}">Edit</button>
                        <button type="button" class="series-field-action" data-field-action="delete" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.PRODUCT}">Delete</button>
                    </td>
                </tr>`;
            }),
        ];
        $table.html(rows.join(''));
        renderProductFormFields();
    };

    const renderSeriesMetadataFieldsTable = () => {
        const fields = getMetadataFields();
        const $table = $el('seriesMetadataFieldsTable');
        if (!fields.length) {
            $table.html('<tr><td>No series metadata fields defined.</td></tr>');
            renderSeriesMetadataValues();
            return;
        }
        const rows = [
            `<tr><th>ID</th><th>Key</th><th>Label</th><th>Required</th><th>Sort</th><th>Actions</th></tr>`,
            ...fields.map((field) => {
                const required = field.isRequired ? 'yes' : 'no';
                return `<tr>
                    <td>${field.id}</td>
                    <td>${escapeHtml(field.fieldKey)}</td>
                    <td>${escapeHtml(field.label)}</td>
                    <td>${required}</td>
                    <td>${field.sortOrder ?? 0}</td>
                    <td>
                        <button type="button" class="series-field-action" data-field-action="edit" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.SERIES}">Edit</button>
                        <button type="button" class="series-field-action" data-field-action="delete" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.SERIES}">Delete</button>
                    </td>
                </tr>`;
            }),
        ];
        $table.html(rows.join(''));
        renderSeriesMetadataValues();
    };

    const renderSeriesMetadataValues = () => {
        const fields = getMetadataFields();
        const values = state.seriesMetadataValues || {};
        const $container = $el('seriesMetadataValues');
        if (!fields.length) {
            $container.html('<div>No metadata fields to edit.</div>');
            $el('seriesMetadataSaveButton').prop('disabled', true);
            return;
        }
        const inputs = fields
            .map((field) => {
                const required = field.isRequired ? ' *' : '';
                return `<div class="metadata-field-row">
                    <label>${escapeHtml(field.label)} (${escapeHtml(field.fieldKey)})${required}: </label>
                    <input type="text" data-metadata-key="${field.fieldKey}" value="${escapeHtml(
                        values[field.fieldKey] ?? ''
                    )}">
                </div>`;
            })
            .join('');
        $container.html(inputs);
        $el('seriesMetadataSaveButton').prop('disabled', false);
    };

    const renderProductFormFields = (values = {}) => {
        const fields = getProductFields();
        const $container = $el('productCustomFields');
        if (!fields.length) {
            $container.html('<div>No custom fields for this series.</div>');
            return;
        }
        const controls = fields
            .map((field) => {
                const required = field.isRequired ? ' *' : '';
                return `<div>
                    <label>${escapeHtml(field.label)} (${escapeHtml(field.fieldKey)})${required}: </label>
                    <input type="text" data-field-key="${field.fieldKey}" value="${escapeHtml(
                        values[field.fieldKey] ?? ''
                    )}">
                </div>`;
            })
            .join('');
        $container.html(controls);
    };

    const renderProductList = () => {
        const $table = $el('productListTable');
        const products = state.products || [];
        if (!products.length) {
            $table.html('<tr><td>No products for this series.</td></tr>');
            return;
        }
        const fields = getProductFields();
        const header =
            '<tr><th>ID</th><th>SKU</th><th>Name</th>' +
            fields.map((field) => `<th>${escapeHtml(field.label)}</th>`).join('') +
            '<th>Actions</th></tr>';
        const rows = products
            .map((product) => {
                const customValues = product.customValues || {};
                const customCells = fields
                    .map(
                        (field) =>
                            `<td>${escapeHtml(customValues[field.fieldKey] ?? '')}</td>`
                    )
                    .join('');
                return `<tr>
                    <td>${product.id}</td>
                    <td>${escapeHtml(product.sku)}</td>
                    <td>${escapeHtml(product.name)}</td>
                    ${customCells}
                    <td>
                        <button type="button" data-product-action="edit" data-product-id="${product.id}">Edit</button>
                        <button type="button" data-product-action="delete" data-product-id="${product.id}">Delete</button>
                    </td>
                </tr>`;
            })
            .join('');
        $table.html(header + rows);
    };

    const resetSeriesFieldForm = () => {
        $el('seriesFieldForm')[0].reset();
        $el('seriesFieldId').val('');
        $el('seriesFieldSubmit').text('Save Field');
    };

    const resetSeriesMetadataFieldForm = () => {
        $el('seriesMetadataFieldForm')[0].reset();
        $el('seriesMetadataFieldId').val('');
        $el('seriesMetadataFieldSubmit').text('Save Metadata Field');
    };

    const resetSeriesMetadataForm = () => {
        $el('seriesMetadataForm')[0].reset();
        renderSeriesMetadataValues();
    };

    const resetProductForm = () => {
        $el('productForm')[0].reset();
        renderProductFormFields();
        $el('productSubmit').text('Save Product');
        $el('productDeleteButton').prop('disabled', true);
        state.selectedProductId = null;
    };

    const populateSeriesFieldForm = (field, scope) => {
        $el('seriesFieldId').val(field.id);
        $el('seriesFieldKey').val(field.fieldKey);
        $el('seriesFieldLabel').val(field.label);
        $el('seriesFieldScope').val(scope);
        $el('seriesFieldSortOrder').val(field.sortOrder ?? 0);
        $el('seriesFieldRequired').prop('checked', !!field.isRequired);
        $el('seriesFieldSubmit').text('Update Field');
    };

    const populateSeriesMetadataFieldForm = (field) => {
        $el('seriesMetadataFieldId').val(field.id);
        $el('seriesMetadataFieldKey').val(field.fieldKey);
        $el('seriesMetadataFieldLabel').val(field.label);
        $el('seriesMetadataFieldSortOrder').val(field.sortOrder ?? 0);
        $el('seriesMetadataFieldRequired').prop('checked', !!field.isRequired);
        $el('seriesMetadataFieldSubmit').text('Update Metadata Field');
    };

    const populateProductForm = (product) => {
        $el('productId').val(product.id);
        $el('productSku').val(product.sku);
        $el('productName').val(product.name);
        $el('productDescription').val(product.description || '');
        renderProductFormFields(product.customValues || {});
        $el('productSubmit').text('Update Product');
        $el('productDeleteButton').prop('disabled', false);
        state.selectedProductId = product.id;
    };

    const loadProductsOnly = async (seriesId) => {
        try {
            const response = await requestJson({
                action: 'v1.listProducts',
                seriesId,
            });
            if (!response.success) {
                handleErrorResponse(response);
                return;
            }
            state.products = Array.isArray(response.data) ? response.data : [];
            renderProductList();
        } catch (error) {
            console.error(error);
            setStatus('Unable to load products.', true);
        }
    };
    const renderCsvHistory = (files) => {
        const $tbody = $el('csvHistoryTable').find('tbody');
        if (!files.length) {
            $tbody.html('<tr><td colspan="5">No CSV files stored.</td></tr>');
            return;
        }
        const rows = files
            .map((file) => {
                const size = Number(file.size || 0);
                return `<tr>
                    <td>${escapeHtml((file.type || '').toString().toUpperCase())}</td>
                    <td>${escapeHtml(file.name || file.id)}</td>
                    <td>${formatDateTime(file.timestamp)}</td>
                    <td>${Number.isNaN(size) ? '0' : size.toLocaleString()}</td>
                    <td>
                        <button type="button" data-csv-download="${file.id}">Download</button>
                        <button type="button" data-csv-restore="${file.id}">Restore</button>
                        <button type="button" data-csv-delete="${file.id}">Delete</button>
                    </td>
                </tr>`;
            })
            .join('');
        $tbody.html(rows);
    };

    const formatDeletedSummary = (deleted = {}) => {
        const preferred = [
            'categories',
            'series',
            'products',
            'fieldDefinitions',
            'productValues',
            'seriesValues',
        ];
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

    const renderTruncateAudits = (audits) => {
        const $tbody = $el('truncateAuditTable').find('tbody');
        if (!audits.length) {
            $tbody.html('<tr><td colspan="4">No truncate actions logged.</td></tr>');
            return;
        }
        const rows = audits
            .map(
                (audit) => `<tr>
                <td>${formatDateTime(audit.timestamp)}</td>
                <td>${escapeHtml(audit.reason || '')}</td>
                <td>${escapeHtml(formatDeletedSummary(audit.deleted))}</td>
                <td>${escapeHtml(audit.id || '')}</td>
            </tr>`
            )
            .join('');
        $tbody.html(rows);
    };

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
            setStatus('Unable to load CSV history.', true);
        }
    };

    const triggerCsvDownload = (fileId) => {
        if (!fileId) {
            return;
        }
        window.location = `${apiBase}?action=${encodeURIComponent(
            'v1.downloadCsv'
        )}&id=${encodeURIComponent(fileId)}`;
    };

    const openTruncateModal = () => {
        $el('truncateForm')[0].reset();
        $el('truncateModalError').text('');
        $el('truncateConfirmButton').prop('disabled', true);
        $el('truncateModal').removeAttr('hidden');
        $el('truncateBackdrop').removeAttr('hidden');
        window.setTimeout(() => {
            $el('truncateConfirmInput').trigger('focus');
        }, 50);
    };

    const closeTruncateModal = () => {
        $el('truncateModal').attr('hidden', true);
        $el('truncateBackdrop').attr('hidden', true);
    };

    const updateTruncateConfirmState = () => {
        const token = $el('truncateConfirmInput')
            .val()
            .toString()
            .trim()
            .toUpperCase();
        const reason = $el('truncateReasonInput').val().toString().trim();
        $el('truncateConfirmButton').prop(
            'disabled',
            !(token === TRUNCATE_TOKEN && reason.length > 0)
        );
        $el('truncateModalError').text('');
    };
    const handleSeriesFieldAction = async (event) => {
        const $button = $(event.target);
        const action = $button.data('field-action');
        const fieldId = Number($button.data('field-id'));
        const scope = $button.data('field-scope');
        if (!fieldId || !scope) {
            return;
        }
        if (action === 'edit') {
            const field = findFieldById(fieldId, scope);
            if (!field) {
                setStatus('Field not found.', true);
                return;
            }
            if (scope === FIELD_SCOPE.SERIES) {
                populateSeriesMetadataFieldForm(field);
            } else {
                populateSeriesFieldForm(field, scope);
            }
            return;
        }
        if (action === 'delete') {
            if (!window.confirm('Delete this field?')) {
                return;
            }
            try {
                const response = await postJson('v1.deleteSeriesField', { id: fieldId });
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Series field deleted.', false);
                loadSeriesContext(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatus('Failed to delete series field.', true);
            }
        }
    };

    const handleProductListAction = async (event) => {
        const $button = $(event.target);
        const action = $button.data('product-action');
        const productId = Number($button.data('product-id'));
        if (!action || !productId) {
            return;
        }
        const product = state.products.find((item) => item.id === productId);
        if (!product) {
            setStatus('Product not found.', true);
            return;
        }
        if (action === 'edit') {
            populateProductForm(product);
            return;
        }
    if (action === 'delete') {
        if (!window.confirm('Delete this product?')) {
            return;
        }
        try {
            const response = await postJson('v1.deleteProduct', { id: productId });
            if (!response.success) {
                handleErrorResponse(response);
                return;
            }
            setStatus('Product deleted.', false);
            resetProductForm();
            await loadProductsOnly(state.selectedNodeId);
        } catch (error) {
            console.error(error);
            setStatus('Failed to delete product.', true);
        }
    }
};

    const bindHierarchyEvents = () => {
        $el('hierarchyContainer').on('click', 'a[data-node-id]', (event) => {
            event.preventDefault();
            const nodeId = toInt($(event.currentTarget).data('node-id'));
            if (nodeId) {
                selectNode(nodeId);
            }
        });

        $el('nodeCreateForm').on('submit', async (event) => {
            event.preventDefault();
            const parentValue = $el('createParentId').val();
            const payload = {
                parentId:
                    parentValue === '' ? null : toInt(parentValue, null),
                name: $el('createNodeName').val(),
                type: $el('createNodeType').val(),
                displayOrder: toInt($el('createDisplayOrder').val()),
            };
            try {
                const response = await postJson('v1.saveNode', payload);
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Node created.', false);
                $el('nodeCreateForm')[0].reset();
                await loadHierarchy();
            } catch (error) {
                console.error(error);
                setStatus('Failed to create node.', true);
            }
        });

        $el('nodeUpdateForm').on('submit', async (event) => {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a node to update.', true);
                return;
            }
            const payload = {
                id: state.selectedNodeId,
                name: $el('updateNodeName').val(),
                displayOrder: toInt($el('updateNodeDisplayOrder').val()),
                type: $el('updateNodeTypeValue').val(),
            };
            try {
                const response = await postJson('v1.saveNode', payload);
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Node updated.', false);
                await loadHierarchy();
            } catch (error) {
                console.error(error);
                setStatus('Failed to update node.', true);
            }
        });

        $el('nodeDeleteButton').on('click', async () => {
            if (!state.selectedNodeId) {
                return;
            }
            if (!window.confirm('Delete the selected node?')) {
                return;
            }
            try {
                const response = await postJson('v1.deleteNode', {
                    id: state.selectedNodeId,
                });
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Node deleted.', false);
                state.selectedNodeId = null;
                await loadHierarchy();
            } catch (error) {
                console.error(error);
                setStatus('Failed to delete node.', true);
            }
        });

        $el('hierarchyContainer').on('click', '.node-toggle', (event) => {
            const button = event.currentTarget;
            const targetId = button.getAttribute('data-node-toggle');
            const expanded = button.getAttribute('aria-expanded') === 'true';
            button.setAttribute('aria-expanded', (!expanded).toString());
            if (targetId) {
                const target = document.getElementById(targetId);
                if (target) {
                    target.classList.toggle('is-open', !expanded);
                }
            }
        });
    };

    const bindSeriesFieldEvents = () => {
        $el('seriesFieldsTable').on('click', '.series-field-action', handleSeriesFieldAction);
        $el('seriesMetadataFieldsTable').on(
            'click',
            '.series-field-action',
            handleSeriesFieldAction
        );

        $el('seriesFieldForm').on('submit', async (event) => {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            const payload = {
                seriesId: state.selectedNodeId,
                id: toInt($el('seriesFieldId').val(), null),
                fieldKey: $el('seriesFieldKey').val(),
                label: $el('seriesFieldLabel').val(),
                fieldScope: $el('seriesFieldScope').val(),
                sortOrder: toInt($el('seriesFieldSortOrder').val()),
                isRequired: $el('seriesFieldRequired').is(':checked'),
            };
            if (!payload.id) {
                delete payload.id;
            }
            try {
                const response = await postJson('v1.saveSeriesField', payload);
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Series field saved.', false);
                resetSeriesFieldForm();
                await loadSeriesContext(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatus('Failed to save series field.', true);
            }
        });

        $el('seriesFieldClearButton').on('click', () => {
            resetSeriesFieldForm();
        });

        $el('seriesMetadataFieldForm').on('submit', async (event) => {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            const payload = {
                seriesId: state.selectedNodeId,
                id: toInt($el('seriesMetadataFieldId').val(), null),
                fieldKey: $el('seriesMetadataFieldKey').val(),
                label: $el('seriesMetadataFieldLabel').val(),
                fieldScope: FIELD_SCOPE.SERIES,
                sortOrder: toInt($el('seriesMetadataFieldSortOrder').val()),
                isRequired: $el('seriesMetadataFieldRequired').is(':checked'),
            };
            if (!payload.id) {
                delete payload.id;
            }
            try {
                const response = await postJson('v1.saveSeriesField', payload);
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Metadata field saved.', false);
                resetSeriesMetadataFieldForm();
                await loadSeriesContext(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatus('Failed to save metadata field.', true);
            }
        });

        $el('seriesMetadataFieldClearButton').on('click', () => {
            resetSeriesMetadataFieldForm();
        });
    };
    const bindMetadataEvents = () => {
        $el('seriesMetadataForm').on('submit', async (event) => {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            const values = {};
            $el('seriesMetadataValues')
                .find('input[data-metadata-key]')
                .each((_, input) => {
                    const $input = $(input);
                    values[$input.data('metadata-key')] = $input.val();
                });
            try {
                const response = await postJson('v1.saveSeriesAttributes', {
                    seriesId: state.selectedNodeId,
                    values,
                });
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Metadata saved.', false);
                await loadSeriesContext(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatus('Failed to save metadata.', true);
            }
        });

        $el('seriesMetadataResetButton').on('click', () => {
            renderSeriesMetadataValues();
        });
    };

    const bindProductEvents = () => {
        $el('productForm').on('submit', async (event) => {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            const customValues = {};
            $el('productCustomFields')
                .find('input[data-field-key]')
                .each((_, input) => {
                    const $input = $(input);
                    customValues[$input.data('field-key')] = $input.val();
                });
            const payload = {
                id: toInt($el('productId').val(), null),
                seriesId: state.selectedNodeId,
                sku: $el('productSku').val(),
                name: $el('productName').val(),
                description: $el('productDescription').val(),
                customValues,
            };
            if (!payload.id) {
                delete payload.id;
            }
            try {
                const response = await postJson('v1.saveProduct', payload);
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Product saved.', false);
                resetProductForm();
                await loadProductsOnly(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatus('Failed to save product.', true);
            }
        });

        $el('productClearButton').on('click', () => {
            resetProductForm();
        });

        $el('productDeleteButton').on('click', async () => {
            if (!state.selectedProductId) {
                return;
            }
            if (!window.confirm('Delete the selected product?')) {
                return;
            }
            try {
                const response = await postJson('v1.deleteProduct', {
                    id: state.selectedProductId,
                });
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Product deleted.', false);
                resetProductForm();
                await loadProductsOnly(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatus('Failed to delete product.', true);
            }
        });

        $el('productListTable').on('click', 'button[data-product-action]', handleProductListAction);
    };
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
                setStatus('Failed to export catalog CSV.', true);
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
                await loadHierarchy();
                await loadCsvHistory();
            } catch (error) {
                console.error(error);
                setStatus('Failed to import CSV.', true);
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
                await loadHierarchy();
                await loadCsvHistory();
            } catch (error) {
                console.error(error);
                setStatus('Failed to restore CSV file.', true);
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
                setStatus('Failed to delete CSV file.', true);
            }
        });
    };
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
                correlationId: createCorrelationId(),
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
                state.selectedNodeId = null;
                resetSeriesUI();
                await loadHierarchy();
                await loadCsvHistory();
            } catch (error) {
                console.error(error);
                $el('truncateModalError').text('Unable to truncate catalog.');
                setStatus('Unable to truncate catalog.', true);
            } finally {
                state.truncate.submitting = false;
                applyCsvLockState();
            }
        });
    };

const bindGlobalEvents = () => {
    bindHierarchyEvents();
    bindSeriesFieldEvents();
    bindMetadataEvents();
    bindProductEvents();
    bindCsvEvents();
        bindTruncateEvents();
};

    const init = async () => {
        bindGlobalEvents();
        resetSeriesUI();
        await loadHierarchy();
        await loadCsvHistory();
    };

    $(init);
})();
