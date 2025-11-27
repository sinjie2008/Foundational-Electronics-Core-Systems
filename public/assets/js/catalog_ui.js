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
        seriesFieldType: '#series-field-type',
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
        seriesMetadataFieldType: '#series-metadata-field-type',
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
        deepLinkApplied: false,
        deepLinkProductFilter: '',
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
    const adjustAllTables = () => {
        dataTableRegistry.forEach(({ instance }) => {
            if (!instance) return;
            try {
                instance.columns.adjust().draw(false);
                const fc = instance.fixedColumns ? instance.fixedColumns() : null;
                if (fc && typeof fc.relayout === 'function') {
                    fc.relayout();
                }
                const container = instance.table().container();
                const scrollBody = container.querySelector('.dataTables_scrollBody');
                const scrollWrapper = container.querySelector('.dataTables_scroll');
                if (scrollBody) {
                    scrollBody.style.overflowX = 'auto';
                }
                if (scrollWrapper) {
                    scrollWrapper.style.overflowX = 'auto';
                }
            } catch (error) {
                console.warn('DataTable adjust failed', error);
            }
        });
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
        adjustAllTables();
        setTimeout(adjustAllTables, 200);
    };
    const escapeHtml = (value = '') =>
        String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    const debounce = (fn, delay = 150) => {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    };
    const handleLayoutChange = debounce(() => {
        adjustAllTables();
        setTimeout(adjustAllTables, 300);
    }, 50);

    const applyProductTableFilter = (filterValue) => {
        if (!filterValue) {
            return;
        }
        const entry = dataTableRegistry.get('productListTable');
        const instance = entry?.instance;
        if (!instance) {
            return;
        }
        instance.search(filterValue).draw(false);
        const input = document.querySelector('#product-list-table_filter input[type="search"]');
        if (input) {
            input.value = filterValue;
        }
    };

    const observeSidebarState = () => {
        const shell = document.querySelector('.app-shell');
        if (!shell || typeof MutationObserver === 'undefined') {
            return;
        }
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    handleLayoutChange();
                    break;
                }
            }
        });
        observer.observe(shell, { attributes: true, attributeFilter: ['class'] });
    };
    const bindLayoutReflowEvents = () => {
        const sidebarPanel = document.querySelector('.sidebar-panel');
        if (sidebarPanel) {
            ['click', 'transitionend'].forEach((evt) => {
                sidebarPanel.addEventListener(evt, handleLayoutChange);
            });
        }
        document.addEventListener('sidebar:state', handleLayoutChange);
        window.addEventListener('resize', handleLayoutChange);
    };

    const toInt = (value, fallback = 0) => {
        const parsed = parseInt(value, 10);
        return Number.isNaN(parsed) ? fallback : parsed;
    };

    const upsertField = (fields, savedField) => {
        const list = Array.isArray(fields) ? [...fields] : [];
        const idx = list.findIndex(
            (item) => Number(item.id) === Number(savedField.id)
        );
        if (idx >= 0) {
            list[idx] = { ...list[idx], ...savedField };
        } else {
            list.push(savedField);
        }
        list.sort((a, b) => {
            const orderDiff = (a.sortOrder ?? 0) - (b.sortOrder ?? 0);
            if (orderDiff !== 0) {
                return orderDiff;
            }
            return (a.id ?? 0) - (b.id ?? 0);
        });
        return list;
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

    // Normalize strings for case-insensitive comparisons.
    const normalize = (value = '') => value.toString().trim().toLowerCase();

    // Read URL query parameters into a plain object.
    const getQueryParams = () => {
        const params = new URLSearchParams(window.location.search || '');
        const result = {};
        params.forEach((value, key) => {
            result[key] = value;
        });
        return result;
    };

    // Locate a series node by its name, optionally matching parent category name.
    const findSeriesNodeByName = (seriesName, categoryName) => {
        if (!seriesName) {
            return null;
        }
        const targetSeries = normalize(seriesName);
        const targetCategory = normalize(categoryName || '');
        let candidate = null;
        state.nodeIndex.forEach((node) => {
            if (node.type !== 'series') {
                return;
            }
            if (normalize(node.name) !== targetSeries) {
                return;
            }
            const parent = node.parentId ? state.nodeIndex.get(node.parentId) : null;
            const parentName = normalize(parent?.name || '');
            if (targetCategory && parentName === targetCategory) {
                candidate = node;
            } else if (!candidate) {
                candidate = node;
            }
        });
        return candidate;
    };

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

    const setStatusWithError = (message, error) => {
        const correlationId = error?.correlationId ?? null;
        setStatus(AppError.buildUserMessage(message, correlationId), true);
    };

    const handleErrorResponse = (response, fallback = 'Request failed.') => {
        if (!response) {
            setStatus(AppError.buildUserMessage('Unexpected error occurred.', null), true);
            return;
        }
        const correlationId = AppError.extractCorrelationId(response);
        let message =
            response.message ||
            response.error?.message ||
            fallback;
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
        destroyDataTable('seriesFieldsTable');
        destroyDataTable('seriesMetadataFieldsTable');
        destroyDataTable('productListTable');
        $el('seriesFieldsTable').empty();
        $el('seriesMetadataFieldsTable').empty();
        $el('seriesMetadataValues').empty();
        $el('productListTable').empty();
        resetSeriesFieldForm();
        resetSeriesMetadataFieldForm();
        resetSeriesMetadataForm();
        resetProductForm();
        state.seriesFields = [];
        state.seriesMetadataFields = [];
        state.seriesMetadataValues = {};
        state.products = [];
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

    const loadHierarchy = async () => {
        try {
            setStatus('');
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
        } catch (error) {
            console.error(error);
            setStatusWithError('Unable to load hierarchy.', error);
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

    // Apply deep-link params to prefill search and select a series node.
    const applyDeepLinkFromQuery = async () => {
        if (state.deepLinkApplied) {
            return;
        }
        const params = getQueryParams();
        const categoryParam = params.category || '';
        const seriesParam = params.series || '';
        const productParam = params.product || '';

        if (productParam) {
            $el('hierarchySearch').val(productParam);
            await handleSearch(productParam);
            state.deepLinkProductFilter = productParam;
        }

        if (seriesParam) {
            const target = findSeriesNodeByName(seriesParam, categoryParam);
            if (target) {
                expandAncestors(target.parentId);
                expandAncestors(target.id);
                renderHierarchy();
                selectNode(target.id);
            }
        }
        state.deepLinkApplied = true;
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
            setStatusWithError('Unable to load series context.', error);
        }
    };
    const getProductFields = () => state.seriesFields || [];
    const getMetadataFields = () => state.seriesMetadataFields || [];

    const findFieldById = (fieldId, scope) => {
        const list =
            scope === FIELD_SCOPE.SERIES ? getMetadataFields() : getProductFields();
        return list.find((field) => Number(field.id) === Number(fieldId)) || null;
    };
    const formatMediaValue = (value) => {
        if (!value) {
            return '';
        }
        if (typeof value === 'string') {
            return escapeHtml(value);
        }
        const filename = escapeHtml(value.filename || 'file');
        const url = escapeHtml(value.url || '');
        if (!url) {
            return filename;
        }
        const lower = url.toLowerCase();
        const isImage =
            lower.endsWith('.png') ||
            lower.endsWith('.jpg') ||
            lower.endsWith('.jpeg') ||
            lower.endsWith('.gif') ||
            lower.endsWith('.webp') ||
            lower.endsWith('.bmp') ||
            lower.endsWith('.svg');
        if (isImage) {
            return `<div class="media-preview">
                <img src="${url}" alt="${filename} preview" loading="lazy">
                <a href="${url}" target="_blank" rel="noopener">${filename}</a>
            </div>`;
        }
        return `<a href="${url}" target="_blank" rel="noopener">${filename}</a>`;
    };

    const renderSeriesFieldsTable = () => {
        const fields = getProductFields();
        const columns = [
            { title: 'ID', data: 'id', width: '60px' },
            { title: 'Key', data: 'fieldKey' },
            { title: 'Label', data: 'label' },
            { title: 'Type', data: 'type', width: '90px' },
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
            type: escapeHtml(field.fieldType || 'text'),
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
            order: [[7, 'asc']],
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
            { title: 'Type', data: 'type', width: '90px' },
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
            type: escapeHtml(field.fieldType || 'text'),
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
            order: [[7, 'asc']],
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
                const key = field.fieldKey;
                const currentValue = values[key];
                const type = field.fieldType || 'text';
                if (type === 'file') {
                    const link = formatMediaValue(currentValue);
                    return `<div class="metadata-field-row">
                        <label>${escapeHtml(field.label)} (${escapeHtml(key)})${required}:</label>
                        <div class="file-controls">
                            <input type="file" data-metadata-file="${escapeHtml(key)}" accept="image/*,.pdf,.glb">
                            <label class="inline-checkbox file-clear"><input type="checkbox" data-metadata-clear="${escapeHtml(
                                key
                            )}"> Clear existing file</label>
                            <div class="text-muted small">Allowed: images, PDF, GLB. Max 10 MB.</div>
                            <div class="metadata-current-value">${link || 'No file uploaded.'}</div>
                        </div>
                    </div>`;
                }
                const inputType = type === 'number' ? 'number' : 'text';
                const value = currentValue ?? '';
                return `<div class="metadata-field-row">
                    <label>${escapeHtml(field.label)} (${escapeHtml(key)})${required}:</label>
                    <input type="${inputType}" data-metadata-key="${escapeHtml(key)}" value="${escapeHtml(
                        value
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
                const key = field.fieldKey;
                const type = field.fieldType || 'text';
                const currentValue = values[key];
                if (type === 'file') {
                    const link = formatMediaValue(currentValue);
                    return `<div>
                        <label>${escapeHtml(field.label)} (${escapeHtml(key)})${required}:</label>
                        <div class="file-controls">
                            <input type="file" data-field-file="${escapeHtml(key)}" accept="image/*,.pdf,.glb">
                            <label class="inline-checkbox file-clear"><input type="checkbox" data-field-clear="${escapeHtml(
                                key
                            )}"> Clear existing file</label>
                            <div class="text-muted small">Allowed: images, PDF, GLB. Max 10 MB.</div>
                            <div class="product-current-value">${link || 'No file uploaded.'}</div>
                        </div>
                    </div>`;
                }
                const inputType = type === 'number' ? 'number' : 'text';
                const value = currentValue ?? '';
                return `<div>
                    <label>${escapeHtml(field.label)} (${escapeHtml(key)})${required}: </label>
                    <input type="${inputType}" data-field-key="${escapeHtml(key)}" value="${escapeHtml(value)}">
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
                const raw = product.customValues?.[field.fieldKey];
                if (field.fieldType === 'file') {
                    customValues[field.fieldKey] = formatMediaValue(raw);
                } else {
                    customValues[field.fieldKey] = escapeHtml(raw ?? '');
                }
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
                scrollCollapse: false,
                fixedColumns: {
                    left: 3,
                },
            },
        });
        // Ensure the fixed columns relayout after render.
        adjustAllTables();
        setTimeout(adjustAllTables, 250);
        if (state.deepLinkProductFilter) {
            applyProductTableFilter(state.deepLinkProductFilter);
            state.deepLinkProductFilter = '';
        }
    };

    const resetSeriesFieldForm = () => {
        $el('seriesFieldForm')[0].reset();
        $el('seriesFieldId').val('');
        $el('seriesFieldType').val('text');
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
        $el('seriesMetadataFieldType').val('text');
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
        $el('seriesFieldType').val(field.fieldType || 'text');
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
        $el('seriesMetadataFieldType').val(field.fieldType || 'text');
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
            setStatusWithError('Unable to load products.', error);
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
            setStatusWithError('Unable to load CSV history.', error);
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
                setStatusWithError('Failed to delete series field.', error);
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
                setStatusWithError('Failed to delete product.', error);
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
                await loadHierarchy();
            } catch (error) {
                console.error(error);
                setStatusWithError('Failed to create node.', error);
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
                setStatusWithError('Failed to update node.', error);
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
                setStatusWithError('Failed to delete node.', error);
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
                fieldType: $el('seriesFieldType').val(),
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
                const savedField = response.data || payload;
                state.seriesFields = upsertField(state.seriesFields, savedField);
                renderSeriesFieldsTable();
                renderProductFormFields();
                setStatus('Series field saved.', false);
                resetSeriesFieldForm();
            } catch (error) {
                console.error(error);
                setStatusWithError('Failed to save series field.', error);
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
                fieldType: $el('seriesMetadataFieldType').val(),
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
                const savedField = response.data || payload;
                state.seriesMetadataFields = upsertField(
                    state.seriesMetadataFields,
                    savedField
                );
                state.seriesMetadataValues = {
                    ...state.seriesMetadataValues,
                    [savedField.fieldKey]:
                        state.seriesMetadataValues?.[savedField.fieldKey] ??
                        savedField.defaultValue ??
                        '',
                };
                renderSeriesMetadataFieldsTable();
                renderSeriesMetadataValues();
                setStatus('Metadata field saved.', false);
                resetSeriesMetadataFieldForm();
            } catch (error) {
                console.error(error);
                setStatusWithError('Failed to save metadata field.', error);
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
            const fields = getMetadataFields();
            const formData = new FormData();
            let hasFile = false;

            fields.forEach((field) => {
                const key = field.fieldKey;
                if (field.fieldType === 'file') {
                    const input = $el('seriesMetadataValues').find(`input[data-metadata-file="${key}"]`)[0];
                    const clearInput = $el('seriesMetadataValues').find(`input[data-metadata-clear="${key}"]`)[0];
                    const hasSelection = input?.files && input.files.length > 0;
                    if (hasSelection) {
                        formData.append(`files[${key}]`, input.files[0]);
                        hasFile = true;
                    } else if (clearInput && clearInput.checked) {
                        values[key] = '';
                    }
                } else {
                    const input = $el('seriesMetadataValues').find(`input[data-metadata-key="${key}"]`)[0];
                    if (input) {
                        values[key] = input.value;
                    }
                }
            });

            const metadataPayload = {
                seriesId: state.selectedNodeId,
                values,
            };
            try {
                let response;
                if (hasFile) {
                    formData.append('metadata', JSON.stringify(metadataPayload));
                    response = await postMultipart('v1.saveSeriesAttributes', formData);
                } else {
                    response = await postJson('v1.saveSeriesAttributes', metadataPayload);
                }
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Metadata saved.', false);
                await loadSeriesContext(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatusWithError('Failed to save metadata.', error);
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
            const fields = getProductFields();
            const formData = new FormData();
            let hasFile = false;

            fields.forEach((field) => {
                const key = field.fieldKey;
                if (field.fieldType === 'file') {
                    const input = $el('productCustomFields').find(`input[data-field-file="${key}"]`)[0];
                    const clearInput = $el('productCustomFields').find(`input[data-field-clear="${key}"]`)[0];
                    const hasSelection = input?.files && input.files.length > 0;
                    if (hasSelection) {
                        formData.append(`files[${key}]`, input.files[0]);
                        hasFile = true;
                    } else if (clearInput && clearInput.checked) {
                        customValues[key] = '';
                    }
                } else {
                    const input = $el('productCustomFields').find(`input[data-field-key="${key}"]`)[0];
                    if (input) {
                        customValues[key] = input.value;
                    }
                }
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
                let response;
                if (hasFile) {
                    formData.append('metadata', JSON.stringify(payload));
                    response = await postMultipart('v1.saveProduct', formData);
                } else {
                    response = await postJson('v1.saveProduct', payload);
                }
                if (!response.success) {
                    handleErrorResponse(response);
                    return;
                }
                setStatus('Product saved.', false);
                resetProductForm();
                await loadProductsOnly(state.selectedNodeId);
            } catch (error) {
                console.error(error);
                setStatusWithError('Failed to save product.', error);
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
                setStatusWithError('Failed to delete product.', error);
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
                await loadHierarchy();
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
                await loadHierarchy();
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
                const message = AppError.buildUserMessage('Unable to truncate catalog.', error?.correlationId);
                $el('truncateModalError').text(message);
                setStatusWithError('Unable to truncate catalog.', error);
            } finally {
                state.truncate.submitting = false;
                applyCsvLockState();
            }
        });
    };

    const bindGlobalEvents = (csvUiPresent) => {
        bindHierarchyEvents();
        bindSeriesFieldEvents();
        bindMetadataEvents();
        bindProductEvents();
        if (csvUiPresent) {
            bindCsvEvents();
            bindTruncateEvents();
        }
    };

    const init = async () => {
        const csvUiPresent = $el('csvHistoryTable').length > 0;
        bindGlobalEvents(csvUiPresent);
        bindLayoutReflowEvents();
        observeSidebarState();
        resetSeriesUI();
        await loadHierarchy();
        await applyDeepLinkFromQuery();
        if (csvUiPresent) {
            await loadCsvHistory();
        }
    };

    $(init);
    }
}

new CatalogUI();
