/**
 * catalog_ui.js
 * ES6 class wrapper for the Product Catalog Manager UI (jQuery-powered).
 */
class CatalogUI {
    constructor() {
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
        seriesFieldSortOrder: '#series-field-sort-order',
        seriesFieldRequired: '#series-field-required',
        seriesFieldPublicHidden: '#series-field-public-hidden',
        seriesFieldBackendHidden: '#series-field-backend-hidden',
        seriesFieldSubmit: '#series-field-submit',
        seriesFieldClearButton: '#series-field-clear-button',
        seriesMetadataFieldsTable: '#series-metadata-fields-table',
        seriesMetadataFieldForm: '#series-metadata-field-form',
        seriesMetadataFieldId: '#series-metadata-field-id',
        seriesMetadataFieldKey: '#series-metadata-field-key',
        seriesMetadataFieldLabel: '#series-metadata-field-label',
        seriesMetadataFieldSortOrder: '#series-metadata-field-sort-order',
        seriesMetadataFieldRequired: '#series-metadata-field-required',
        seriesMetadataFieldPublicHidden: '#series-metadata-field-public-hidden',
        seriesMetadataFieldBackendHidden: '#series-metadata-field-backend-hidden',
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
        hierarchySearch: '#hierarchy-search',
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
        matchedNodes: new Set(),
        matchedProducts: new Set(),
        expandedNodes: new Set(),
        searchQuery: '',
        // Deep-link values from spec-search Edit button.
        deepLink: {
            category: null,
            series: null,
            product: null,
            applied: false,
        },
    };
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
    const setEmptyTableState = (tableKey, columns = [], message = 'No records found.') => {
        const $table = buildTableHeader(tableKey, columns);
        const $tbody = $table.find('tbody');
        const colspan = Math.max(columns.length, 1);
        $tbody.html(
            `<tr><td colspan="${colspan}" class="datatable-empty">${escapeHtml(message)}</td></tr>`
        );
    };
    const destroyDataTable = (tableKey) => {
        const entry = dataTableRegistry.get(tableKey);
        if (entry?.instance) {
            entry.instance.destroy();
        }
        dataTableRegistry.delete(tableKey);
    };
    // Remove any existing rows so DataTables can rebuild cleanly when column sets change.
    const clearTableBody = (tableKey) => {
        const $table = $el(tableKey);
        if ($table?.length) {
            $table.find('tbody').empty();
        }
    };
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
        if (entry && entry.signature === signature) {
            entry.instance.clear();
            entry.instance.rows.add(rows);
            entry.instance.draw(false);
            return;
        }
        destroyDataTable(tableKey);
        clearTableBody(tableKey);
        const instance = $table.DataTable({
            ...DATA_TABLE_DEFAULTS,
            ...extraOptions,
            data: rows,
            columns,
            pageLength:
                options.pageLength ??
                extraOptions.pageLength ??
                DATA_TABLE_DEFAULTS.pageLength,
            order:
                options.order ??
                extraOptions.order ??
                DATA_TABLE_DEFAULTS.order,
        });
        dataTableRegistry.set(tableKey, { instance, signature });
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

    /**
     * Read deep-link query params (category, series, product) from the URL.
     */
    const readDeepLinkParams = () => {
        const params = new URLSearchParams(window.location.search);
        const normalize = (value) => {
            if (value === null || value === undefined) {
                return null;
            }
            const trimmed = value.toString().trim();
            return trimmed.length ? trimmed : null;
        };
        state.deepLink.category = normalize(params.get('category'));
        state.deepLink.series = normalize(params.get('series'));
        state.deepLink.product = normalize(params.get('product'));
    };

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
            return list.map((node) => {
                const isCategory = node.type === 'category';
                const isSeries = node.type === 'series';

                let label = escapeHtml(node.name ?? node.Name ?? '(unnamed)');
                let countLabel = '';

                if (isCategory) {
                    countLabel = ` [category] (${node.category_count || 0})`;
                } else if (isSeries) {
                    countLabel = ` [series] (${node.product_count || 0})`;
                }

                const children = Array.isArray(node.children) ? node.children : [];
                const products = Array.isArray(node.products) ? node.products : [];
                const hasChildren = children.length > 0 || products.length > 0;

                const isMatched = state.matchedNodes.has(node.id);
                const isExpanded = state.expandedNodes.has(node.id) || (state.searchQuery === '' && depth === 0);
                const matchClass = isMatched ? ' search-match' : '';

                let childMarkup = '';
                if (hasChildren) {
                    childMarkup = `<div class="node-children${isExpanded ? ' is-open' : ''}" id="node-children-${node.id}">
                        <ul class="hierarchy-list">
                            ${renderList(children, depth + 1)}
                            ${renderProducts(products)}
                        </ul>
                    </div>`;
                }

                const toggle = hasChildren
                    ? `<button type="button" 
                               class="node-toggle" 
                               aria-expanded="${isExpanded}"
                               data-node-toggle="${node.id}"></button>`
                    : '<span class="node-toggle-spacer"></span>';

                return `<li class="hierarchy-node${matchClass}">
                            <div class="node-row">
                                ${toggle}
                                <a href="#" data-node-id="${node.id}" data-node-type="${node.type}">
                                    ${label}${countLabel}
                                </a>
                            </div>
                            ${childMarkup}
                        </li>`;
            }).join('');
        };

        const renderProducts = (products) => {
            return products.map(p => {
                const isMatched = state.matchedProducts.has(p.id);
                const matchClass = isMatched ? ' search-match' : '';
                return `<li class="hierarchy-node product-node${matchClass}">
                    <div class="node-row">
                        <span class="node-toggle-spacer"></span>
                        <span class="product-name">${escapeHtml(p.name)}</span>
                    </div>
                 </li>`;
            }).join('');
        };

        return `<ul class="hierarchy-list">${renderList(nodes)}</ul>`;
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
        const node = state.nodeIndex.get(state.selectedNodeId);
        const $details = $el('selectedNodeDetails');
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

    const loadHierarchy = async (options = {}) => {
        const { clearStatus = true } = options;
        try {
            if (clearStatus) {
                setStatus('');
            }
            const response = await toPromise($.getJSON('api/catalog/hierarchy.php'));
            if (!response.success) {
                handleErrorResponse(response);
                return;
            }
            const payload = response.data || [];
            state.hierarchy = Array.isArray(payload) ? payload : [];
            state.nodeIndex = new Map();
            buildNodeIndex(state.hierarchy);
            if (!state.nodeIndex.has(state.selectedNodeId)) {
                state.selectedNodeId = null;
            }
            renderHierarchy();
            updateSelectedNodePanel();
            await applyDeepLinkSelection();
        } catch (error) {
            console.error(error);
            setStatus('Unable to load hierarchy.', true);
        }
    };

    const handleSearch = async (query) => {
        state.searchQuery = query;
        state.matchedNodes.clear();
        state.matchedProducts.clear();
        state.expandedNodes.clear();

        if (!query) {
            renderHierarchy();
            return;
        }

        try {
            const response = await toPromise($.getJSON('api/catalog/search.php', { q: query }));
            if (response.success && Array.isArray(response.data)) {
                response.data.forEach(match => {
                    if (match.type === 'product') {
                        state.matchedProducts.add(match.id);
                        // Expand parent series
                        if (match.parent_id) {
                            expandAncestors(match.parent_id);
                        }
                    } else {
                        state.matchedNodes.add(match.id);
                        // Expand ancestors
                        if (match.parent_id) {
                            expandAncestors(match.parent_id);
                        }
                        // Also expand the node itself if it matches? Maybe not, usually we expand *to* the node.
                        // But if it's a category matching, maybe we want to see its children?
                        // Requirement: "auto-expand the tree to show matched results"
                        // If I match a category, showing it is enough.
                        // If I match a product, I must expand the series.
                    }
                });
            }
            renderHierarchy();
        } catch (error) {
            console.error('Search failed', error);
        }
    };

    const expandAncestors = (nodeId) => {
        let currentId = nodeId;
        while (currentId) {
            state.expandedNodes.add(currentId);
            const node = state.nodeIndex.get(currentId);
            if (!node) break;
            currentId = node.parentId;
        }
    };

    /**
     * Locate a series node by name (and optional parent category name).
     */
    const findSeriesNodeByName = (seriesName, categoryName) => {
        if (!seriesName) {
            return null;
        }
        const targetSeries = seriesName.toString().trim().toLowerCase();
        const targetCategory = categoryName
            ? categoryName.toString().trim().toLowerCase()
            : null;

        for (const node of state.nodeIndex.values()) {
            if (node.type !== 'series') {
                continue;
            }
            const nodeSeries = (node.name ?? '').toString().trim().toLowerCase();
            if (nodeSeries !== targetSeries) {
                continue;
            }

            if (targetCategory) {
                const parent = state.nodeIndex.get(node.parentId);
                const parentName = (parent?.name ?? '').toString().trim().toLowerCase();
                if (parentName !== targetCategory) {
                    continue;
                }
            }

            return node;
        }

        return null;
    };

    /**
     * Apply deep-link selection from spec-search Edit flow (prefill search + auto-select series).
     */
    const applyDeepLinkSelection = async () => {
        if (state.deepLink.applied) {
            return;
        }

        const hasParams = Boolean(state.deepLink.series || state.deepLink.product);
        if (!hasParams) {
            state.deepLink.applied = true;
            return;
        }

        if (state.deepLink.product) {
            $el('hierarchySearch').val(state.deepLink.product);
            await handleSearch(state.deepLink.product);
        }

        const targetNode = findSeriesNodeByName(
            state.deepLink.series,
            state.deepLink.category
        );
        if (targetNode) {
            expandAncestors(targetNode.id);
            state.expandedNodes.add(targetNode.id);
            renderHierarchy();
            selectNode(targetNode.id);
        }

        state.deepLink.applied = true;
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
        const columns = [
            { title: 'ID', data: 'id', width: '60px' },
            { title: 'Key', data: 'fieldKey' },
            { title: 'Label', data: 'label' },
            { title: 'Public Hidden', data: 'publicHidden', width: '140px' },
            { title: 'Backend Hidden', data: 'backendHidden', width: '150px' },
            { title: 'Required', data: 'required', width: '90px' },
            { title: 'Sort', data: 'sortOrder', width: '80px' },
            {
                title: 'Actions',
                data: 'actions',
                orderable: false,
                searchable: false,
                className: 'text-nowrap',
            },
        ];
        if (!fields.length) {
            destroyDataTable('seriesFieldsTable');
            setEmptyTableState(
                'seriesFieldsTable',
                columns,
                'No product attribute fields defined for this series.'
            );
            renderProductFormFields();
            return;
        }
        const rows = fields.map((field) => ({
            id: Number(field.id),
            fieldKey: escapeHtml(field.fieldKey),
            label: escapeHtml(field.label),
            publicHidden: field.publicPortalHidden ? 'Yes' : 'No',
            backendHidden: field.backendPortalHidden ? 'Yes' : 'No',
            required: field.isRequired ? 'Yes' : 'No',
            sortOrder: field.sortOrder ?? 0,
            actions: `<div class="datatable-actions">
                <button type="button" class="series-field-action" data-field-action="edit" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.PRODUCT}">Edit</button>
                <button type="button" class="series-field-action" data-field-action="delete" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.PRODUCT}">Delete</button>
            </div>`,
        }));
        syncDataTable('seriesFieldsTable', columns, rows, {
            order: [[6, 'asc']],
            pageLength: 5,
            emptyMessage: 'No product attribute fields defined for this series.',
        });
        renderProductFormFields();
    };

    const renderSeriesMetadataFieldsTable = () => {
        const fields = getMetadataFields();
        const columns = [
            { title: 'ID', data: 'id', width: '60px' },
            { title: 'Key', data: 'fieldKey' },
            { title: 'Label', data: 'label' },
            { title: 'Public Hidden', data: 'publicHidden', width: '140px' },
            { title: 'Backend Hidden', data: 'backendHidden', width: '150px' },
            { title: 'Required', data: 'required', width: '90px' },
            { title: 'Sort', data: 'sortOrder', width: '80px' },
            {
                title: 'Actions',
                data: 'actions',
                orderable: false,
                searchable: false,
                className: 'text-nowrap',
            },
        ];
        if (!fields.length) {
            destroyDataTable('seriesMetadataFieldsTable');
            setEmptyTableState(
                'seriesMetadataFieldsTable',
                columns,
                'No series metadata fields defined.'
            );
            renderSeriesMetadataValues();
            return;
        }
        const rows = fields.map((field) => ({
            id: Number(field.id),
            fieldKey: escapeHtml(field.fieldKey),
            label: escapeHtml(field.label),
            publicHidden: field.publicPortalHidden ? 'Yes' : 'No',
            backendHidden: field.backendPortalHidden ? 'Yes' : 'No',
            required: field.isRequired ? 'Yes' : 'No',
            sortOrder: field.sortOrder ?? 0,
            actions: `<div class="datatable-actions">
                <button type="button" class="series-field-action" data-field-action="edit" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.SERIES}">Edit</button>
                <button type="button" class="series-field-action" data-field-action="delete" data-field-id="${field.id}" data-field-scope="${FIELD_SCOPE.SERIES}">Delete</button>
            </div>`,
        }));
        syncDataTable('seriesMetadataFieldsTable', columns, rows, {
            order: [[6, 'asc']],
            pageLength: 5,
            emptyMessage: 'No series metadata fields defined.',
        });
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
        const products = state.products || [];
        const fields = getProductFields();
        const baseColumns = [
            { title: 'ID', data: 'id', width: '60px' },
            { title: 'SKU', data: 'sku' },
            { title: 'Name', data: 'name' },
        ];
        const customColumns = fields.map((field) => ({
            title: escapeHtml(field.label),
            data: null,
            render: (data, type, row) => {
                const value = row.custom[field.fieldKey] ?? '';
                return type === 'display' ? value : value;
            },
        }));
        const columns = [
            ...baseColumns,
            ...customColumns,
            {
                title: 'Actions',
                data: 'actions',
                orderable: false,
                searchable: false,
                className: 'text-nowrap',
            },
        ];
        if (!products.length) {
            destroyDataTable('productListTable');
            setEmptyTableState('productListTable', columns, 'No products for this series.');
            return;
        }
        const rows = products.map((product) => {
            const customValues = {};
            fields.forEach((field) => {
                const value = product.customValues?.[field.fieldKey] ?? '';
                customValues[field.fieldKey] = escapeHtml(value);
            });
            return {
                id: Number(product.id),
                sku: escapeHtml(product.sku),
                name: escapeHtml(product.name),
                custom: customValues,
                actions: `<div class="datatable-actions">
                    <button type="button" data-product-action="edit" data-product-id="${product.id}">Edit</button>
                    <button type="button" data-product-action="delete" data-product-id="${product.id}">Delete</button>
                </div>`,
            };
        });
        syncDataTable('productListTable', columns, rows, {
            pageLength: 10,
            emptyMessage: 'No products for this series.',
            signatureKey: 'product-fixed-columns',
            extraOptions: {
                scrollX: true,
                scrollCollapse: true,
                fixedColumns: {
                    left: 3,
                },
            },
        });
    };

    const resetSeriesFieldForm = () => {
        $el('seriesFieldForm')[0].reset();
        $el('seriesFieldId').val('');
        $el('seriesFieldPublicHidden').prop('checked', false);
        $el('seriesFieldBackendHidden').prop('checked', false);
        $el('seriesFieldRequired').prop('checked', false);
        $el('seriesFieldSubmit').text('Save Field');
    };

    const resetSeriesMetadataFieldForm = () => {
        $el('seriesMetadataFieldForm')[0].reset();
        $el('seriesMetadataFieldId').val('');
        $el('seriesMetadataFieldPublicHidden').prop('checked', false);
        $el('seriesMetadataFieldBackendHidden').prop('checked', false);
        $el('seriesMetadataFieldRequired').prop('checked', false);
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
        if (scope !== FIELD_SCOPE.PRODUCT) {
            setStatus('Select a product attribute field to edit.', true);
            return;
        }
        $el('seriesFieldId').val(field.id);
        $el('seriesFieldKey').val(field.fieldKey);
        $el('seriesFieldLabel').val(field.label);
        $el('seriesFieldSortOrder').val(field.sortOrder ?? 0);
        $el('seriesFieldPublicHidden').prop('checked', !!field.publicPortalHidden);
        $el('seriesFieldBackendHidden').prop('checked', !!field.backendPortalHidden);
        $el('seriesFieldRequired').prop('checked', !!field.isRequired);
        $el('seriesFieldSubmit').text('Update Field');
    };

    const populateSeriesMetadataFieldForm = (field) => {
        $el('seriesMetadataFieldId').val(field.id);
        $el('seriesMetadataFieldKey').val(field.fieldKey);
        $el('seriesMetadataFieldLabel').val(field.label);
        $el('seriesMetadataFieldSortOrder').val(field.sortOrder ?? 0);
        $el('seriesMetadataFieldPublicHidden').prop('checked', !!field.publicPortalHidden);
        $el('seriesMetadataFieldBackendHidden').prop('checked', !!field.backendPortalHidden);
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
                render: (data, type, row) =>
                    type === 'display' ? row.sizeDisplay : data ?? 0,
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
            setEmptyTableState(
                'truncateAuditTable',
                columns,
                'No truncate actions logged.'
            );
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
        $el('hierarchyContainer').on('click', '.node-toggle', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const $btn = $(event.currentTarget);
            const nodeId = Number($btn.data('node-toggle'));

            if (state.expandedNodes.has(nodeId)) {
                state.expandedNodes.delete(nodeId);
            } else {
                state.expandedNodes.add(nodeId);
            }
            renderHierarchy();
        });

        $el('hierarchyContainer').on('click', 'a[data-node-id]', (event) => {
            event.preventDefault();
            const nodeId = Number($(event.currentTarget).data('node-id'));
            selectNode(nodeId);
        });

        let searchTimeout;
        $el('hierarchySearch').on('input', (event) => {
            const query = $(event.target).val().trim();
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                handleSearch(query);
            }, 300);
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
                await loadHierarchy({ clearStatus: false });
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
            const selectedNode = state.nodeIndex.get(state.selectedNodeId);
            const displayOrderInput = toInt(
                $el('updateNodeDisplayOrder').val(),
                selectedNode?.displayOrder ?? 0
            );
            const payload = {
                id: state.selectedNodeId,
                // Preserve parent linkage (series updates fail validation without parentId).
                parentId: selectedNode?.parentId ?? null,
                name: $el('updateNodeName').val(),
                // Keep existing order when the input is blank to avoid zeroing the value.
                displayOrder: displayOrderInput,
                type: $el('updateNodeTypeValue').val(),
            };
            try {
                const response = await postJson('v1.saveNode', payload);
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Node updated.', false);
                await loadHierarchy({ clearStatus: false });
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
                await loadHierarchy({ clearStatus: false });
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
                fieldScope: FIELD_SCOPE.PRODUCT,
                sortOrder: toInt($el('seriesFieldSortOrder').val()),
                publicPortalHidden: $el('seriesFieldPublicHidden').is(':checked'),
                backendPortalHidden: $el('seriesFieldBackendHidden').is(':checked'),
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
                publicPortalHidden: $el('seriesMetadataFieldPublicHidden').is(':checked'),
                backendPortalHidden: $el('seriesMetadataFieldBackendHidden').is(':checked'),
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
                await loadHierarchy({ clearStatus: false });
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
                await loadHierarchy({ clearStatus: false });
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
                await loadHierarchy({ clearStatus: false });
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
        readDeepLinkParams();
        await loadHierarchy();
        await loadCsvHistory();
    };

    $(init);
    }
}

new CatalogUI();
