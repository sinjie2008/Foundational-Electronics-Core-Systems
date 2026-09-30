/**
 * catalog_ui.js
 * ES6 class wrapper for the Product Catalog Manager UI (jQuery-powered).
 */
export class CatalogUI {
    constructor() {
        'use strict';
        this.apiBase = 'catalog.php';

        this.FIELD_SCOPE = {
            PRODUCT: 'product_attribute',
            SERIES: 'series_metadata',
        };

        this.TRUNCATE_TOKEN = 'TRUNCATE';

        this.selectors = {
            statusCatalog: '#status-message-catalog',
            statusSeriesFields: '#status-message-series-fields',
            statusSeriesMetadata: '#status-message-series-metadata',
            statusProducts: '#status-message-products',
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
            enableTypstTemplating: '#enableTypstTemplating',
            typstTemplatingControl: '#typst-templating-control',
            typstTemplatingLinkContainer: '#typst-templating-link-container',
            typstTemplatingLink: '#typst-templating-link',
            statusCategoryFields: '#status-message-category-fields',
            categoryFieldsSection: '#category-fields-section',
            categoryFieldsTable: '#category-fields-table',
            categoryFieldForm: '#category-field-form',
            categoryFieldId: '#category-field-id',
            categoryFieldKey: '#category-field-key',
            categoryFieldType: '#category-field-type',
            categoryFieldDataContainer: '#category-field-data-container',
            categoryFieldAddButton: '#category-field-add-button',
            categoryFieldDeleteButton: '#category-field-delete-button',
        };

        this.STATUS_TARGETS = {
            catalog: 'statusCatalog',
            seriesFields: 'statusSeriesFields',
            seriesMetadata: 'statusSeriesMetadata',
            products: 'statusProducts',
            categoryFields: 'statusCategoryFields',
        };

        this.domCache = new Map();

        this.state = {
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
            categoryFields: [],
            selectedCategoryFieldId: null,
            categoryFieldCurrentValue: '',
            categoryFieldsCategoryId: null,
            categoryFieldsTableContext: null, // Track which category the Category Fields table is bound to.
            categoryFieldRequestId: 0,
            selectedCategoryId: null,
        };

        this.dataTableRegistry = new Map();

        this.DATA_TABLE_DOM = '<"row g-2 align-items-center mb-2"<"col-12 col-md-6"l><"col-12 col-md-6 text-md-end"f>>' +
        't' +
        '<"row g-2 align-items-center mt-2"<"col-12 col-md-6"i><"col-12 col-md-6 text-md-end"p>>';

        this.DATA_TABLE_LANGUAGE = {
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

        this.DATA_TABLE_DEFAULTS = {
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
            dom: this.DATA_TABLE_DOM,
            language: this.DATA_TABLE_LANGUAGE,
            order: [[0, 'asc']],
        };

        this.CATEGORY_FIELD_COLUMNS = [
            { title: 'Field Key', data: 'fieldKey' },
            { title: 'Field Type', data: 'fieldType', width: '140px' },
            { title: 'Field Data', data: 'fieldValue' },
            {
                title: 'Actions',
                data: 'actions',
                orderable: false,
                searchable: false,
                width: '160px',
                className: 'text-nowrap',
            },
        ];

        this.handleLayoutChange = this.debounce(() => {
            this.adjustAllTables();
            setTimeout(this.adjustAllTables, 300);
        }, 50);

        for (const methodName of Object.getOwnPropertyNames(Object.getPrototypeOf(this))) {
            if (methodName !== "constructor" && typeof this[methodName] === "function") {
                this[methodName] = this[methodName].bind(this);
            }
        }

        $(this.init);
    }

    $el(key) {
        const page = this;
        if (!page.domCache.has(key)) {
            page.domCache.set(key, $(page.selectors[key]));
        }
        return page.domCache.get(key);
    }

    buildTableHeader(tableKey, columns = []) {
        const page = this;
        const $table = page.$el(tableKey);
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
    }

    setEmptyTableState(tableKey, columns = [], message = 'No records found.') {
        const page = this;
        const $table = page.buildTableHeader(tableKey, columns);
        const $tbody = $table.find('tbody');
        const colspan = Math.max(columns.length, 1);
        $tbody.html(
            `<tr><td colspan="${colspan}" class="datatable-empty">${page.escapeHtml(message)}</td></tr>`
        );
    }

    destroyDataTable(tableKey) {
        const page = this;
        const entry = page.dataTableRegistry.get(tableKey);
        if (entry?.instance) {
            entry.instance.destroy();
        }
        page.dataTableRegistry.delete(tableKey);
    }

    clearTableBody(tableKey) {
        const page = this;
        const $table = page.$el(tableKey);
        if ($table?.length) {
            $table.find('tbody').empty();
        }
    }

    adjustAllTables() {
        const page = this;
        page.dataTableRegistry.forEach(({ instance }) => {
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
    }

    syncDataTable(tableKey, columns, rows, options = {}) {
        const page = this;
        if (!Array.isArray(rows) || !rows.length) {
            page.destroyDataTable(tableKey);
            page.setEmptyTableState(tableKey, columns, options.emptyMessage);
            return;
        }
        const $table = page.buildTableHeader(tableKey, columns);
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
        const entry = page.dataTableRegistry.get(tableKey);
        if (entry && entry.signature === signature) {
            entry.instance.clear();
            entry.instance.rows.add(rows);
            entry.instance.draw(false);
            return;
        }
        page.destroyDataTable(tableKey);
        page.clearTableBody(tableKey);
        const instance = $table.DataTable({
            ...page.DATA_TABLE_DEFAULTS,
            ...extraOptions,
            data: rows,
            columns,
            pageLength:
                options.pageLength ??
                extraOptions.pageLength ??
                page.DATA_TABLE_DEFAULTS.pageLength,
            order:
                options.order ??
                extraOptions.order ??
                page.DATA_TABLE_DEFAULTS.order,
        });
        page.dataTableRegistry.set(tableKey, { instance, signature });
        page.adjustAllTables();
        setTimeout(page.adjustAllTables, 200);
    }

    escapeHtml(value = '') {
        const page = this;

        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    debounce(fn, delay = 150) {
        const page = this;
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    }

    applyProductTableFilter(filterValue) {
        const page = this;
        if (!filterValue) {
            return;
        }
        const entry = page.dataTableRegistry.get('productListTable');
        const instance = entry?.instance;
        if (!instance) {
            return;
        }
        instance.search(filterValue).draw(false);
        const input = document.querySelector('#product-list-table_filter input[type="search"]');
        if (input) {
            input.value = filterValue;
        }
    }

    observeSidebarState() {
        const page = this;
        const shell = document.querySelector('.app-shell');
        if (!shell || typeof MutationObserver === 'undefined') {
            return;
        }
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    page.handleLayoutChange();
                    break;
                }
            }
        });
        observer.observe(shell, { attributes: true, attributeFilter: ['class'] });
    }

    bindLayoutReflowEvents() {
        const page = this;
        const sidebarPanel = document.querySelector('.sidebar-panel');
        if (sidebarPanel) {
            ['click', 'transitionend'].forEach((evt) => {
                sidebarPanel.addEventListener(evt, page.handleLayoutChange);
            });
        }
        document.addEventListener('sidebar:state', page.handleLayoutChange);
        window.addEventListener('resize', page.handleLayoutChange);
    }

    toInt(value, fallback = 0) {
        const page = this;
        const parsed = parseInt(value, 10);
        return Number.isNaN(parsed) ? fallback : parsed;
    }

    upsertField(fields, savedField) {
        const page = this;
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
    }

    formatDateTime(timestamp) {
        const page = this;
        if (!timestamp) {
            return '';
        }
        const date = new Date(timestamp);
        return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
    }

    createCorrelationId() {
        const page = this;
        return window.crypto?.randomUUID?.() ?? `truncate-${Date.now()}`;
    }

    normalize(value = '') {
        const page = this;
        return value.toString().trim().toLowerCase();
    }

    getQueryParams() {
        const page = this;
        const params = new URLSearchParams(window.location.search || '');
        const result = {};
        params.forEach((value, key) => {
            result[key] = value;
        });
        return result;
    }

    findSeriesNodeByName(seriesName, categoryName) {
        const page = this;
        if (!seriesName) {
            return null;
        }
        const targetSeries = page.normalize(seriesName);
        const targetCategory = page.normalize(categoryName || '');
        let candidate = null;
        page.state.nodeIndex.forEach((node) => {
            if (node.type !== 'series') {
                return;
            }
            if (page.normalize(node.name) !== targetSeries) {
                return;
            }
            const parent = node.parentId ? page.state.nodeIndex.get(node.parentId) : null;
            const parentName = page.normalize(parent?.name || '');
            if (targetCategory && parentName === targetCategory) {
                candidate = node;
            } else if (!candidate) {
                candidate = node;
            }
        });
        return candidate;
    }

    withLoading(factory) {
        const page = this;
        if (window.LoadingOverlay && typeof window.LoadingOverlay.wrapPromise === 'function') {
            return window.LoadingOverlay.wrapPromise(factory);
        }
        return factory();
    }

    toPromise(jqXHR, action = 'ajax') {
        const page = this;

        return page.withLoading(
            () =>
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
                })
        );
    }

    requestJson(params) {
        const page = this;
        return page.toPromise($.getJSON(page.apiBase, params), params?.action ?? 'ajax:get');
    }

    postJson(action, payload = {}) {
        const page = this;

        return page.toPromise(
            $.ajax({
                url: `${page.apiBase}?action=${encodeURIComponent(action)}`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(payload),
            }),
            action
        );
    }

    putJson(action, payload = {}) {
        const page = this;

        return page.toPromise(
            $.ajax({
                url: `${page.apiBase}?action=${encodeURIComponent(action)}`,
                method: 'PUT',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(payload),
            }),
            action
        );
    }

    postMultipart(action, formData) {
        const page = this;

        return page.toPromise(
            $.ajax({
                url: `${page.apiBase}?action=${encodeURIComponent(action)}`,
                method: 'POST',
                processData: false,
                contentType: false,
                dataType: 'json',
                data: formData,
            }),
            action
        );
    }

    setStatus(targetOrMessage, message = '', isError = false) {
        const page = this;
        let targetKey = 'catalog';
        let resolvedMessage = '';
        let resolvedIsError = false;

        if (arguments.length === 1) {
            // Legacy: setStatus('message')
            resolvedMessage = targetOrMessage;
        } else if (arguments.length === 2) {
            if (page.STATUS_TARGETS[targetOrMessage]) {
                // setStatus('catalog', 'message')
                targetKey = targetOrMessage;
                resolvedMessage = message;
            } else {
                // Legacy: setStatus('message', isError)
                resolvedMessage = targetOrMessage;
                resolvedIsError = message === true;
            }
        } else {
            // setStatus('catalog', 'message', true/false)
            targetKey = page.STATUS_TARGETS[targetOrMessage] ? targetOrMessage : 'catalog';
            resolvedMessage = message;
            resolvedIsError = isError === true;
        }

        const selectorKey = page.STATUS_TARGETS[targetKey] || page.STATUS_TARGETS.catalog;
        const $status = page.$el(selectorKey);
        if (!$status.length) {
            return;
        }
        $status.removeClass('status-info status-error').addClass('is-empty');
        if (!resolvedMessage) {
            $status.text('');
            return;
        }
        $status
            .text(`${resolvedIsError ? 'Error' : 'Info'}: ${resolvedMessage}`)
            .addClass(resolvedIsError ? 'status-error' : 'status-info')
            .removeClass('is-empty');
    }

    setStatusWithError(targetKey, message, error) {
        const page = this;
        const correlationId = AppError.extractCorrelationId(error);
        const userMessage = AppError.buildUserMessage(message, correlationId);
        page.setStatus(targetKey, userMessage, true);
    }

    handleErrorResponse(response, targetKey = 'catalog', fallback = 'Request failed.') {
        const page = this;
        if (!response) {
            page.setStatusWithError(targetKey, 'Unexpected error occurred.', null);
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
        const userMessage = AppError.buildUserMessage(message, correlationId);
        page.setStatus(targetKey, userMessage, true);
    }

    applyCsvLockState() {
        const page = this;
        const locked = page.state.truncate.submitting || page.state.truncate.serverLock;
        [
            'csvExportButton',
            'csvImportSubmit',
            'csvImportFile',
            'truncateButton',
            'truncateConfirmButton',
        ].forEach((key) => {
            page.$el(key).prop('disabled', locked);
        });
        if (locked) {
            page.$el('csvHistoryTable').find('button').prop('disabled', true);
        }
    }

    buildNodeIndex(nodes, parentId = null) {
        const page = this;
        nodes.forEach((node) => {
            const normalized = {
                id: Number(node.id),
                name: node.name ?? node.Name ?? '(unnamed)',
                type: node.type ?? node.Type ?? 'category',
                parentId: node.parentId ?? node.parent_id ?? parentId,
                displayOrder: node.displayOrder ?? node.display_order ?? 0,
                typstTemplatingEnabled: Boolean(
                    node.typstTemplatingEnabled ??
                    node.typst_templating_enabled ??
                    node.latex_templating_enabled ??
                    false
                ),
                children: Array.isArray(node.children) ? node.children : [],
            };
            page.state.nodeIndex.set(normalized.id, normalized);
            page.buildNodeIndex(normalized.children, normalized.id);
        });
    }

    buildHierarchyList(nodes) {
        const page = this;
        if (!nodes || nodes.length === 0) {
            return '<div>No categories defined.</div>';
        }

        const renderList = (list, depth = 0) => {
            return list.map((node) => {
                const isCategory = node.type === 'category';
                const isSeries = node.type === 'series';

                let label = page.escapeHtml(node.name ?? node.Name ?? '(unnamed)');
                let countLabel = '';

                if (isCategory) {
                    countLabel = ` [category] (${node.category_count || 0})`;
                } else if (isSeries) {
                    countLabel = ` [series] (${node.product_count || 0})`;
                }

                const children = Array.isArray(node.children) ? node.children : [];
                const products = Array.isArray(node.products) ? node.products : [];
                const hasChildren = children.length > 0 || products.length > 0;

                const isMatched = page.state.matchedNodes.has(node.id);
                const isExpanded = page.state.expandedNodes.has(node.id) || (page.state.searchQuery === '' && depth === 0);
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
                const isMatched = page.state.matchedProducts.has(p.id);
                const matchClass = isMatched ? ' search-match' : '';
                return `<li class="hierarchy-node product-node${matchClass}">
                <div class="node-row">
                    <span class="node-toggle-spacer"></span>
                    <span class="product-name">${page.escapeHtml(p.name)}</span>
                </div>
             </li>`;
            }).join('');
        };

        return `<ul class="hierarchy-list">${renderList(nodes)}</ul>`;
    }

    renderHierarchy() {
        const page = this;
        page.$el('hierarchyContainer').html(page.buildHierarchyList(page.state.hierarchy));
    }

    resetSeriesUI() {
        const page = this;
        page.destroyDataTable('seriesFieldsTable');
        page.destroyDataTable('seriesMetadataFieldsTable');
        page.destroyDataTable('productListTable');
        page.$el('seriesFieldsTable').empty();
        page.$el('seriesMetadataFieldsTable').empty();
        page.$el('seriesMetadataValues').empty();
        page.$el('productListTable').empty();
        page.resetSeriesFieldForm();
        page.resetSeriesMetadataFieldForm();
        page.resetSeriesMetadataForm();
        page.resetProductForm();
        page.state.seriesFields = [];
        page.state.seriesMetadataFields = [];
        page.state.seriesMetadataValues = {};
        page.state.products = [];
        page.$el('seriesManagement').prop('hidden', true);
    }

    formatCategoryFieldType(type) {
        const page = this;
        return (type === 'image' || type === 'file' ? 'File (Image)' : 'Text');
    }

    formatCategoryFieldValue(field = {}) {
        const page = this;
        const value = field.value ?? '';
        const previewUrl = field.previewUrl || '';
        const previewDataUrl = field.previewDataUrl || '';
        if ((field.type === 'image' || field.type === 'file') && (previewUrl || previewDataUrl)) {
            const src = page.escapeHtml(previewUrl || previewDataUrl);
            const label = page.escapeHtml(value);
            return `<div class="media-preview">
                <img src="${src}" alt="${label || 'Category field image'}" loading="lazy">
                ${label ? `<div class="text-muted small">${label}</div>` : ''}
            </div>`;
        }
        return page.escapeHtml(value);
    }

    renderCategoryFieldInput(type = 'text', value = '', previews = {}) {
        const page = this;
        const $container = page.$el('categoryFieldDataContainer');
        if (!$container.length) {
            return;
        }
        const safeValue = page.escapeHtml(value ?? '');
        if (type === 'file' || type === 'image') {
            const previewHtml = page.formatCategoryFieldValue({
                type: type,
                value,
                previewUrl: previews.previewUrl,
                previewDataUrl: previews.previewDataUrl,
            });
            const previewBlock = previewHtml
                ? `<div class="category-field-preview">${previewHtml}</div>`
                : '<div class="text-muted small">No file uploaded.</div>';
            $container.html(`<label>Field Data:
                    <input type="file" id="category-field-file" accept="image/*">
                </label>
                <label class="inline-checkbox file-clear"><input type="checkbox" id="category-field-clear-file"> Clear existing file</label>
                ${previewBlock}`);
            return;
        }
        if (type === 'textarea') {
            $container.html(
                `<label>Field Data:<textarea id="category-field-value" rows="4">${safeValue}</textarea></label>`
            );
            return;
        }
        $container.html(
            `<label>Field Data:<input type="text" id="category-field-value" value="${safeValue}"></label>`
        );
    }

    resetCategoryFieldForm() {
        const page = this;
        page.$el('categoryFieldForm')[0]?.reset?.();
        page.$el('categoryFieldId').val('');
        page.$el('categoryFieldKey').val('');
        page.$el('categoryFieldType').val('text');
        page.state.selectedCategoryFieldId = null;
        page.state.categoryFieldCurrentValue = '';
        page.renderCategoryFieldInput('text');
    }

    renderCategoryFieldsTable() {
        const page = this;
        const fields = Array.isArray(page.state.categoryFields) ? page.state.categoryFields : [];
        if (!fields.length) {
            page.destroyDataTable('categoryFieldsTable');
            page.setEmptyTableState(
                'categoryFieldsTable',
                page.CATEGORY_FIELD_COLUMNS,
                'No category fields defined for this category.'
            );
            return;
        }
        const rows = fields.map((field) => ({
            fieldKey: `<span class="badge bg-secondary">${page.escapeHtml(field.key)}</span>`,
            fieldType: page.formatCategoryFieldType(field.type),
            fieldValue: page.formatCategoryFieldValue(field),
            actions: `<div class="datatable-actions">
                <button type="button" class="category-field-action" data-category-field-action="edit" data-field-id="${field.id}">Edit</button>
                <button type="button" class="category-field-action" data-category-field-action="delete" data-field-id="${field.id}">Delete</button>
            </div>`,
        }));
        page.syncDataTable('categoryFieldsTable', page.CATEGORY_FIELD_COLUMNS, rows, {
            order: [[0, 'asc']],
            pageLength: 5,
            emptyMessage: 'No category fields defined for this category.',
            signatureKey: page.state.categoryFieldsTableContext ?? page.state.categoryFieldsCategoryId,
        });
    }

    resetCategoryFieldsUI() {
        const page = this;
        page.state.categoryFields = [];
        page.state.selectedCategoryFieldId = null;
        page.state.categoryFieldCurrentValue = '';
        page.state.categoryFieldsCategoryId = null;
        page.state.categoryFieldsTableContext = null;
        page.destroyDataTable('categoryFieldsTable');
        page.setEmptyTableState(
            'categoryFieldsTable',
            page.CATEGORY_FIELD_COLUMNS,
            'Select a category to view Category Fields.'
        );
        page.resetCategoryFieldForm();
        page.$el('categoryFieldsSection').prop('hidden', true);
        page.setStatus('categoryFields', '');
    }

    showCategoryFieldsSection() {
        const page = this;
        page.$el('categoryFieldsSection').prop('hidden', false);
    }

    async loadCategoryFields(categoryId) {
        const page = this;
        page.state.categoryFieldsCategoryId = categoryId;
        page.state.categoryFieldsTableContext = categoryId || null;
        page.state.categoryFieldRequestId += 1;
        const requestId = page.state.categoryFieldRequestId;
        if (!categoryId) {
            page.resetCategoryFieldsUI();
            return;
        }
        page.showCategoryFieldsSection();
        page.destroyDataTable('categoryFieldsTable');
        page.setEmptyTableState('categoryFieldsTable', page.CATEGORY_FIELD_COLUMNS, 'Loading fields...');
        try {
            const response = await page.toPromise(
                $.getJSON('api/typst/variables.php', { seriesId: categoryId }),
                'categoryFields:list'
            );
            if (requestId !== page.state.categoryFieldRequestId) {
                return;
            }
            if (!response.success) {
                page.handleErrorResponse(response, 'categoryFields', 'Failed to load category fields.');
                return;
            }
            page.state.categoryFields = Array.isArray(response.data) ? response.data : [];
            page.renderCategoryFieldsTable();
            page.resetCategoryFieldForm();
        } catch (error) {
            if (requestId !== page.state.categoryFieldRequestId) {
                return;
            }
            console.error(error);
            page.setStatusWithError('categoryFields', 'Unable to load category fields.', error);
        }
    }

    populateCategoryFieldForm(field) {
        const page = this;
        if (!field) {
            return;
        }
        const type = field.type === 'image' ? 'file' : field.type || 'text';
        page.state.selectedCategoryFieldId = field.id;
        page.state.categoryFieldCurrentValue = field.value ?? '';
        page.$el('categoryFieldId').val(field.id);
        page.$el('categoryFieldKey').val(field.key ?? '');
        page.$el('categoryFieldType').val(type);
        page.renderCategoryFieldInput(type, field.value ?? '', field);
    }

    saveCategoryField(payload, fileInput, hasFile) {
        const page = this;
        if (hasFile && fileInput?.files?.length) {
            const formData = new FormData();
            formData.append('key', payload.key);
            formData.append('type', 'file');
            formData.append('seriesId', payload.seriesId);
            if (payload.id) {
                formData.append('id', payload.id);
            }
            if (payload.value !== undefined && payload.value !== null) {
                formData.append('value', payload.value);
            }
            formData.append('file', fileInput.files[0]);
            return page.toPromise(
                $.ajax({
                    url: 'api/typst/variables.php',
                    method: 'POST',
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    data: formData,
                }),
                'categoryFields:save'
            );
        }
        return page.toPromise(
            $.ajax({
                url: 'api/typst/variables.php',
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(payload),
            }),
            'categoryFields:save'
        );
    }

    async deleteCategoryField(fieldId) {
        const page = this;
        if (!fieldId || !page.state.categoryFieldsCategoryId) {
            return;
        }
        const response = await page.toPromise(
            $.ajax({
                url: `api/typst/variables.php?id=${encodeURIComponent(fieldId)}&seriesId=${encodeURIComponent(
                    page.state.categoryFieldsCategoryId
                )}`,
                method: 'DELETE',
                dataType: 'json',
            }),
            'categoryFields:delete'
        );
        return response;
    }

    async handleCategoryFieldAction(event) {
        const page = this;
        const action = $(event.currentTarget).data('category-field-action');
        const fieldId = Number($(event.currentTarget).data('field-id'));
        const field = page.state.categoryFields.find((item) => Number(item.id) === Number(fieldId));
        if (!action || Number.isNaN(fieldId)) {
            return;
        }
        if (action === 'edit' && field) {
            page.populateCategoryFieldForm(field);
            page.setStatus('categoryFields', 'Editing category field.', false);
        }
        if (action === 'delete') {
            if (!window.confirm('Delete this category field?')) {
                return;
            }
            try {
                const response = await page.deleteCategoryField(fieldId);
                if (!response?.success) {
                    page.handleErrorResponse(response, 'categoryFields', 'Failed to delete category field.');
                    return;
                }
                page.setStatus('categoryFields', 'Category field deleted.', false);
                await page.loadCategoryFields(page.state.categoryFieldsCategoryId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('categoryFields', 'Failed to delete category field.', error);
            }
        }
    }

    applyTypstTemplatingState(enabled) {
        const page = this;
        const isEnabled = Boolean(enabled);
        page.$el('enableTypstTemplating').prop('checked', isEnabled);
        page.updateTypstTemplatingLink(isEnabled);
    }

    updateTypstTemplatingLink(enabled) {
        const page = this;
        const linkContainer = page.$el('typstTemplatingLinkContainer');
        const link = page.$el('typstTemplatingLink');
        if (!enabled) {
            linkContainer.addClass('d-none');
            return;
        }
        const node = page.state.nodeIndex.get(page.state.selectedNodeId);
        if (node && node.type === 'series') {
            const url = `series_typst_template.html?series_id=${node.id}`;
            link.attr('href', url);
            link.text('Open Series Typst Template');
            linkContainer.removeClass('d-none');
        } else {
            linkContainer.addClass('d-none');
        }
    }

    setTypstTemplatingFlagOnNode(nodeId, enabled) {
        const page = this;
        const node = page.state.nodeIndex.get(nodeId);
        if (node) {
            node.typstTemplatingEnabled = enabled;
            page.state.nodeIndex.set(nodeId, node);
        }
        const updateTree = (nodes) => {
            if (!Array.isArray(nodes)) {
                return;
            }
            nodes.forEach((child) => {
                if (Number(child.id) === Number(nodeId)) {
                    child.typstTemplatingEnabled = enabled;
                    child.typst_templating_enabled = enabled;
                    child.latex_templating_enabled = enabled;
                }
                if (Array.isArray(child.children)) {
                    updateTree(child.children);
                }
            });
        };
        updateTree(page.state.hierarchy);
    }

    selectNode(nodeId) {
        const page = this;
        if (!page.state.nodeIndex.has(nodeId)) {
            page.state.selectedNodeId = null;
        } else {
            page.state.selectedNodeId = nodeId;
        }
        page.updateSelectedNodePanel();
    }

    updateSelectedNodePanel() {
        const page = this;
        const node = page.state.nodeIndex.get(page.state.selectedNodeId);
        const $details = page.$el('selectedNodeDetails');
        if (!node) {
            $details.text('Select a category or series to view details.');
            page.$el('updateNodeId').val('');
            page.$el('updateNodeIdText').text('None');
            page.$el('updateNodeParentId').text('N/A');
            page.$el('updateNodeTypeText').text('N/A');
        page.$el('updateNodeTypeValue').val('');
        page.$el('updateNodeName').val('');
        page.$el('updateNodeDisplayOrder').val('0');
        page.$el('nodeDeleteButton').prop('disabled', true);
        page.applyTypstTemplatingState(false);
        page.$el('typstTemplatingControl').addClass('d-none');
        page.$el('typstTemplatingLinkContainer').addClass('d-none');
        page.resetSeriesUI();
        page.resetCategoryFieldsUI();
        page.state.selectedCategoryId = null;
        return;
    }

        const parentLabel =
            node.parentId === null || node.parentId === undefined
                ? '(root)'
                : node.parentId;

        const isCategory = node.type === 'category';
        const isSeries = node.type === 'series';
        const info = [
            `ID: ${node.id}`,
            `Parent ID: ${parentLabel}`,
            `Type: ${node.type}`,
            `Display Order: ${node.displayOrder}`,
        ];
        $details.html(info.map((line) => `<p>${page.escapeHtml(line)}</p>`).join(''));

        page.$el('updateNodeId').val(node.id);
        page.$el('updateNodeIdText').text(String(node.id));
        page.$el('updateNodeParentId').text(String(parentLabel));
        page.$el('updateNodeTypeText').text(node.type);
        page.$el('updateNodeTypeValue').val(node.type);
        page.$el('updateNodeName').val(node.name);
        page.$el('updateNodeDisplayOrder').val(node.displayOrder);
        page.$el('nodeDeleteButton').prop('disabled', false);

        if (isSeries) {
            page.state.selectedCategoryId = null;
            page.$el('seriesManagement').prop('hidden', false);
            page.$el('typstTemplatingControl').removeClass('d-none');
            page.applyTypstTemplatingState(Boolean(node.typstTemplatingEnabled));
            page.resetCategoryFieldsUI();
            page.loadSeriesContext(node.id);
        } else {
            page.resetSeriesUI();
            page.applyTypstTemplatingState(false);
            page.$el('typstTemplatingControl').addClass('d-none');
            if (isCategory) {
                page.state.selectedCategoryId = node.id;
                page.showCategoryFieldsSection();
                page.loadCategoryFields(node.id);
            } else {
                page.state.selectedCategoryId = null;
                page.resetCategoryFieldsUI();
            }
        }
    }

    async loadHierarchy(options = {}) {
        const page = this;
        const { clearStatus = true } = options;
        try {
            if (clearStatus) {
                page.setStatus('catalog', '');
            }
            const response = await page.toPromise($.getJSON('api/catalog/hierarchy.php'));
            if (!response.success) {
                page.handleErrorResponse(response, 'catalog');
                return;
            }
            const payload = response.data || [];
            page.state.hierarchy = Array.isArray(payload) ? payload : [];
            page.state.nodeIndex = new Map();
            page.buildNodeIndex(page.state.hierarchy);
            if (!page.state.nodeIndex.has(page.state.selectedNodeId)) {
                page.state.selectedNodeId = null;
            }
            page.renderHierarchy();
            page.updateSelectedNodePanel();
        } catch (error) {
            console.error(error);
            page.setStatusWithError('catalog', 'Unable to load hierarchy.', error);
        }
    }

    async handleSearch(query) {
        const page = this;
        page.state.searchQuery = query;
        page.state.matchedNodes.clear();
        page.state.matchedProducts.clear();
        page.state.expandedNodes.clear();

        if (!query) {
            page.renderHierarchy();
            return;
        }

        try {
            const response = await page.toPromise($.getJSON('api/catalog/search.php', { q: query }));
            if (response.success && Array.isArray(response.data)) {
                response.data.forEach(match => {
                    if (match.type === 'product') {
                        page.state.matchedProducts.add(match.id);
                        // Expand parent series
                        if (match.parent_id) {
                            page.expandAncestors(match.parent_id);
                        }
                    } else {
                        page.state.matchedNodes.add(match.id);
                        // Expand ancestors
                        if (match.parent_id) {
                            page.expandAncestors(match.parent_id);
                        }
                        // Also expand the node itself if it matches? Maybe not, usually we expand *to* the node.
                        // But if it's a category matching, maybe we want to see its children?
                        // Requirement: "auto-expand the tree to show matched results"
                        // If I match a category, showing it is enough.
                        // If I match a product, I must expand the series.
                    }
                });
            }
            page.renderHierarchy();
        } catch (error) {
            console.error('Search failed', error);
        }
    }

    expandAncestors(nodeId) {
        const page = this;
        let currentId = nodeId;
        while (currentId) {
            page.state.expandedNodes.add(currentId);
            const node = page.state.nodeIndex.get(currentId);
            if (!node) break;
            currentId = node.parentId;
        }
    }

    async applyDeepLinkFromQuery() {
        const page = this;
        if (page.state.deepLinkApplied) {
            return;
        }
        const params = page.getQueryParams();
        const categoryParam = params.category || '';
        const seriesParam = params.series || '';
        const productParam = params.product || '';

        if (productParam) {
            page.$el('hierarchySearch').val(productParam);
            await page.handleSearch(productParam);
            page.state.deepLinkProductFilter = productParam;
        }

        if (seriesParam) {
            const target = page.findSeriesNodeByName(seriesParam, categoryParam);
            if (target) {
                page.expandAncestors(target.parentId);
                page.expandAncestors(target.id);
                page.renderHierarchy();
                page.selectNode(target.id);
            }
        }
        page.state.deepLinkApplied = true;
    }

    async loadSeriesContext(seriesId) {
        const page = this;
        if (!seriesId) {
            return;
        }
        page.state.seriesRequestId += 1;
        const requestId = page.state.seriesRequestId;
        page.state.selectedProductId = null;

        try {
            const [
                productFields,
                metadataFields,
                metadataValues,
                products,
            ] = await Promise.all([
                page.requestJson({ action: 'v1.listSeriesFields', seriesId }),
                page.requestJson({
                    action: 'v1.listSeriesFields',
                    seriesId,
                    scope: page.FIELD_SCOPE.SERIES,
                }),
                page.requestJson({ action: 'v1.getSeriesAttributes', seriesId }),
                page.requestJson({ action: 'v1.listProducts', seriesId }),
            ]);

            if (requestId !== page.state.seriesRequestId) {
                return;
            }

            if (!productFields.success) {
                page.handleErrorResponse(productFields, 'seriesFields');
                return;
            }
            if (!metadataFields.success) {
                page.handleErrorResponse(metadataFields, 'seriesMetadata');
                return;
            }
            if (!metadataValues.success) {
                page.handleErrorResponse(metadataValues, 'seriesMetadata');
                return;
            }
            if (!products.success) {
                page.handleErrorResponse(products, 'products');
                return;
            }

            page.state.seriesFields = productFields.data || [];
            page.state.seriesMetadataFields =
                metadataValues.data?.definitions ??
                metadataFields.data ??
                [];
            page.state.seriesMetadataValues = metadataValues.data?.values || {};
            page.state.products = Array.isArray(products.data) ? products.data : [];

            page.renderSeriesFieldsTable();
            page.renderSeriesMetadataFieldsTable();
            page.renderSeriesMetadataValues();
            page.renderProductList();
            page.renderProductFormFields();
        } catch (error) {
            console.error(error);
            page.setStatusWithError('seriesFields', 'Unable to load series context.', error);
        }
    }

    getProductFields() {
        const page = this;
        return page.state.seriesFields || [];
    }

    getMetadataFields() {
        const page = this;
        return page.state.seriesMetadataFields || [];
    }

    findFieldById(fieldId, scope) {
        const page = this;
        const list =
            scope === page.FIELD_SCOPE.SERIES ? page.getMetadataFields() : page.getProductFields();
        return list.find((field) => Number(field.id) === Number(fieldId)) || null;
    }

    formatMediaValue(value) {
        const page = this;
        if (!value) {
            return '';
        }
        if (typeof value === 'string') {
            return page.escapeHtml(value);
        }
        const filename = page.escapeHtml(value.filename || 'file');
        const url = page.escapeHtml(value.url || '');
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
    }

    renderSeriesFieldsTable() {
        const page = this;
        const fields = page.getProductFields();
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
            page.destroyDataTable('seriesFieldsTable');
            page.setEmptyTableState(
                'seriesFieldsTable',
                columns,
                'No product attribute fields defined for this series.'
            );
            page.renderProductFormFields();
            return;
        }
        const rows = fields.map((field) => ({
            id: Number(field.id),
            fieldKey: page.escapeHtml(field.fieldKey),
            label: page.escapeHtml(field.label),
            type: page.escapeHtml(field.fieldType || 'text'),
            publicHidden: field.publicPortalHidden ? 'Yes' : 'No',
            backendHidden: field.backendPortalHidden ? 'Yes' : 'No',
            required: field.isRequired ? 'Yes' : 'No',
            sortOrder: field.sortOrder ?? 0,
            actions: `<div class="datatable-actions">
            <button type="button" class="series-field-action" data-field-action="edit" data-field-id="${field.id}" data-field-scope="${page.FIELD_SCOPE.PRODUCT}">Edit</button>
            <button type="button" class="series-field-action" data-field-action="delete" data-field-id="${field.id}" data-field-scope="${page.FIELD_SCOPE.PRODUCT}">Delete</button>
        </div>`,
        }));
        page.syncDataTable('seriesFieldsTable', columns, rows, {
            order: [[7, 'asc']],
            pageLength: 5,
            emptyMessage: 'No product attribute fields defined for this series.',
        });
        page.renderProductFormFields();
    }

    renderSeriesMetadataFieldsTable() {
        const page = this;
        const fields = page.getMetadataFields();
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
            page.destroyDataTable('seriesMetadataFieldsTable');
            page.setEmptyTableState(
                'seriesMetadataFieldsTable',
                columns,
                'No series metadata fields defined.'
            );
            page.renderSeriesMetadataValues();
            return;
        }
        const rows = fields.map((field) => ({
            id: Number(field.id),
            fieldKey: page.escapeHtml(field.fieldKey),
            label: page.escapeHtml(field.label),
            type: page.escapeHtml(field.fieldType || 'text'),
            publicHidden: field.publicPortalHidden ? 'Yes' : 'No',
            backendHidden: field.backendPortalHidden ? 'Yes' : 'No',
            required: field.isRequired ? 'Yes' : 'No',
            sortOrder: field.sortOrder ?? 0,
            actions: `<div class="datatable-actions">
            <button type="button" class="series-field-action" data-field-action="edit" data-field-id="${field.id}" data-field-scope="${page.FIELD_SCOPE.SERIES}">Edit</button>
            <button type="button" class="series-field-action" data-field-action="delete" data-field-id="${field.id}" data-field-scope="${page.FIELD_SCOPE.SERIES}">Delete</button>
        </div>`,
        }));
        page.syncDataTable('seriesMetadataFieldsTable', columns, rows, {
            order: [[7, 'asc']],
            pageLength: 5,
            emptyMessage: 'No series metadata fields defined.',
        });
        page.renderSeriesMetadataValues();
    }

    renderSeriesMetadataValues() {
        const page = this;
        const fields = page.getMetadataFields();
        const values = page.state.seriesMetadataValues || {};
        const $container = page.$el('seriesMetadataValues');
        if (!fields.length) {
            $container.html('<div>No metadata fields to edit.</div>');
            page.$el('seriesMetadataSaveButton').prop('disabled', true);
            return;
        }
        const inputs = fields
            .map((field) => {
                const required = field.isRequired ? ' *' : '';
                const key = field.fieldKey;
                const currentValue = values[key];
                const type = field.fieldType || 'text';
                if (type === 'file') {
                    const link = page.formatMediaValue(currentValue);
                    return `<div class="metadata-field-row">
                    <label>${page.escapeHtml(field.label)} (${page.escapeHtml(key)})${required}:</label>
                    <div class="file-controls">
                        <input type="file" data-metadata-file="${page.escapeHtml(key)}" accept="image/*,.pdf,.glb">
                        <label class="inline-checkbox file-clear"><input type="checkbox" data-metadata-clear="${page.escapeHtml(
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
                <label>${page.escapeHtml(field.label)} (${page.escapeHtml(key)})${required}:</label>
                <input type="${inputType}" data-metadata-key="${page.escapeHtml(key)}" value="${page.escapeHtml(
                    value
                )}">
            </div>`;
            })
            .join('');
        $container.html(inputs);
        page.$el('seriesMetadataSaveButton').prop('disabled', false);
    }

    renderProductFormFields(values = {}) {
        const page = this;
        const fields = page.getProductFields();
        const $container = page.$el('productCustomFields');
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
                    const link = page.formatMediaValue(currentValue);
                    return `<div>
                    <label>${page.escapeHtml(field.label)} (${page.escapeHtml(key)})${required}:</label>
                    <div class="file-controls">
                        <input type="file" data-field-file="${page.escapeHtml(key)}" accept="image/*,.pdf,.glb">
                        <label class="inline-checkbox file-clear"><input type="checkbox" data-field-clear="${page.escapeHtml(
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
                <label>${page.escapeHtml(field.label)} (${page.escapeHtml(key)})${required}: </label>
                <input type="${inputType}" data-field-key="${page.escapeHtml(key)}" value="${page.escapeHtml(value)}">
            </div>`;
            })
            .join('');
        $container.html(controls);
    }

    renderProductList() {
        const page = this;
        const products = page.state.products || [];
        const fields = page.getProductFields();
        const baseColumns = [
            { title: 'ID', data: 'id', width: '60px' },
            { title: 'SKU', data: 'sku' },
            { title: 'Name', data: 'name' },
        ];
        const customColumns = fields.map((field) => ({
            title: page.escapeHtml(field.label),
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
            page.destroyDataTable('productListTable');
            page.setEmptyTableState('productListTable', columns, 'No products for this series.');
            return;
        }
        const rows = products.map((product) => {
            const customValues = {};
            fields.forEach((field) => {
                const raw = product.customValues?.[field.fieldKey];
                if (field.fieldType === 'file') {
                    customValues[field.fieldKey] = page.formatMediaValue(raw);
                } else {
                    customValues[field.fieldKey] = page.escapeHtml(raw ?? '');
                }
            });
            return {
                id: Number(product.id),
                sku: page.escapeHtml(product.sku),
                name: page.escapeHtml(product.name),
                custom: customValues,
                actions: `<div class="datatable-actions">
                <button type="button" data-product-action="edit" data-product-id="${product.id}">Edit</button>
                <button type="button" data-product-action="delete" data-product-id="${product.id}">Delete</button>
            </div>`,
            };
        });
        page.syncDataTable('productListTable', columns, rows, {
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
        page.adjustAllTables();
        setTimeout(page.adjustAllTables, 250);
        if (page.state.deepLinkProductFilter) {
            page.applyProductTableFilter(page.state.deepLinkProductFilter);
            page.state.deepLinkProductFilter = '';
        }
    }

    resetSeriesFieldForm() {
        const page = this;
        page.$el('seriesFieldForm')[0].reset();
        page.$el('seriesFieldId').val('');
        page.$el('seriesFieldType').val('text');
        page.$el('seriesFieldPublicHidden').prop('checked', false);
        page.$el('seriesFieldBackendHidden').prop('checked', false);
        page.$el('seriesFieldRequired').prop('checked', false);
        page.$el('seriesFieldSubmit').text('Save Field');
    }

    resetSeriesMetadataFieldForm() {
        const page = this;
        page.$el('seriesMetadataFieldForm')[0].reset();
        page.$el('seriesMetadataFieldId').val('');
        page.$el('seriesMetadataFieldPublicHidden').prop('checked', false);
        page.$el('seriesMetadataFieldBackendHidden').prop('checked', false);
        page.$el('seriesMetadataFieldRequired').prop('checked', false);
        page.$el('seriesMetadataFieldType').val('text');
        page.$el('seriesMetadataFieldSubmit').text('Save Metadata Field');
    }

    resetSeriesMetadataForm() {
        const page = this;
        page.$el('seriesMetadataForm')[0].reset();
        page.renderSeriesMetadataValues();
    }

    resetProductForm() {
        const page = this;
        page.$el('productForm')[0].reset();
        page.renderProductFormFields();
        page.$el('productSubmit').text('Save Product');
        page.$el('productDeleteButton').prop('disabled', true);
        page.state.selectedProductId = null;
    }

    populateSeriesFieldForm(field, scope) {
        const page = this;
        if (scope !== page.FIELD_SCOPE.PRODUCT) {
            page.setStatus('seriesFields', 'Select a product attribute field to edit.', true);
            return;
        }
        page.$el('seriesFieldId').val(field.id);
        page.$el('seriesFieldKey').val(field.fieldKey);
        page.$el('seriesFieldLabel').val(field.label);
        page.$el('seriesFieldType').val(field.fieldType || 'text');
        page.$el('seriesFieldSortOrder').val(field.sortOrder ?? 0);
        page.$el('seriesFieldPublicHidden').prop('checked', !!field.publicPortalHidden);
        page.$el('seriesFieldBackendHidden').prop('checked', !!field.backendPortalHidden);
        page.$el('seriesFieldRequired').prop('checked', !!field.isRequired);
        page.$el('seriesFieldSubmit').text('Update Field');
    }

    populateSeriesMetadataFieldForm(field) {
        const page = this;
        page.$el('seriesMetadataFieldId').val(field.id);
        page.$el('seriesMetadataFieldKey').val(field.fieldKey);
        page.$el('seriesMetadataFieldLabel').val(field.label);
        page.$el('seriesMetadataFieldType').val(field.fieldType || 'text');
        page.$el('seriesMetadataFieldSortOrder').val(field.sortOrder ?? 0);
        page.$el('seriesMetadataFieldPublicHidden').prop('checked', !!field.publicPortalHidden);
        page.$el('seriesMetadataFieldBackendHidden').prop('checked', !!field.backendPortalHidden);
        page.$el('seriesMetadataFieldRequired').prop('checked', !!field.isRequired);
        page.$el('seriesMetadataFieldSubmit').text('Update Metadata Field');
    }

    populateProductForm(product) {
        const page = this;
        page.$el('productId').val(product.id);
        page.$el('productSku').val(product.sku);
        page.$el('productName').val(product.name);
        page.$el('productDescription').val(product.description || '');
        page.renderProductFormFields(product.customValues || {});
        page.$el('productSubmit').text('Update Product');
        page.$el('productDeleteButton').prop('disabled', false);
        page.state.selectedProductId = product.id;
    }

    async loadProductsOnly(seriesId) {
        const page = this;
        try {
            const response = await page.requestJson({
                action: 'v1.listProducts',
                seriesId,
            });
            if (!response.success) {
                page.handleErrorResponse(response, 'products');
                return;
            }
            page.state.products = Array.isArray(response.data) ? response.data : [];
            page.renderProductList();
        } catch (error) {
            console.error(error);
            page.setStatusWithError('products', 'Unable to load products.', error);
        }
    }

    renderCsvHistory(files) {
        const page = this;
        const columns = [
            { title: 'Type', data: 'type', width: '90px' },
            { title: 'Name', data: 'name' },
            {
                title: 'Timestamp',
                data: 'timestampRaw',
                render: (data) => page.formatDateTime(data),
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
            page.destroyDataTable('csvHistoryTable');
            page.setEmptyTableState('csvHistoryTable', columns, 'No CSV files stored.');
            return;
        }
        const rows = files.map((file) => {
            const size = Number(file.size || 0);
            return {
                type: page.escapeHtml((file.type || '').toString().toUpperCase()),
                name: page.escapeHtml(file.name || file.id),
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
        page.syncDataTable('csvHistoryTable', columns, rows, {
            order: [[2, 'desc']],
            pageLength: 5,
            emptyMessage: 'No CSV files stored.',
        });
    }

    formatDeletedSummary(deleted = {}) {
        const page = this;
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
    }

    renderTruncateAudits(audits) {
        const page = this;
        const columns = [
            {
                title: 'Timestamp',
                data: 'timestampRaw',
                render: (data) => page.formatDateTime(data),
            },
            { title: 'Reason', data: 'reason' },
            { title: 'Deleted', data: 'deleted' },
            { title: 'Audit ID', data: 'auditId' },
        ];
        if (!audits.length) {
            page.destroyDataTable('truncateAuditTable');
            page.setEmptyTableState(
                'truncateAuditTable',
                columns,
                'No truncate actions logged.'
            );
            return;
        }
        const rows = audits.map((audit) => ({
            timestampRaw: audit.timestamp,
            reason: page.escapeHtml(audit.reason || ''),
            deleted: page.escapeHtml(page.formatDeletedSummary(audit.deleted)),
            auditId: page.escapeHtml(audit.id || ''),
        }));
        page.syncDataTable('truncateAuditTable', columns, rows, {
            order: [[0, 'desc']],
            pageLength: 5,
            emptyMessage: 'No truncate actions logged.',
        });
    }

    async loadCsvHistory() {
        const page = this;
        try {
            const response = await page.requestJson({ action: 'v1.listCsvHistory' });
            if (!response.success) {
                page.handleErrorResponse(response, 'catalog');
                return;
            }
            const payload = response.data || {};
            page.renderCsvHistory(payload.files || []);
            page.renderTruncateAudits(payload.audits || []);
            page.state.truncate.serverLock = payload.truncateInProgress === true;
            page.applyCsvLockState();
        } catch (error) {
            console.error(error);
            page.setStatusWithError('catalog', 'Unable to load CSV history.', error);
        }
    }

    triggerCsvDownload(fileId) {
        const page = this;
        if (!fileId) {
            return;
        }
        window.location = `${page.apiBase}?action=${encodeURIComponent(
            'v1.downloadCsv'
        )}&id=${encodeURIComponent(fileId)}`;
    }

    openTruncateModal() {
        const page = this;
        page.$el('truncateForm')[0].reset();
        page.$el('truncateModalError').text('');
        page.$el('truncateConfirmButton').prop('disabled', true);
        page.$el('truncateModal').removeAttr('hidden');
        page.$el('truncateBackdrop').removeAttr('hidden');
        window.setTimeout(() => {
            page.$el('truncateConfirmInput').trigger('focus');
        }, 50);
    }

    closeTruncateModal() {
        const page = this;
        page.$el('truncateModal').attr('hidden', true);
        page.$el('truncateBackdrop').attr('hidden', true);
    }

    updateTruncateConfirmState() {
        const page = this;
        const token = page.$el('truncateConfirmInput')
            .val()
            .toString()
            .trim()
            .toUpperCase();
        const reason = page.$el('truncateReasonInput').val().toString().trim();
        page.$el('truncateConfirmButton').prop(
            'disabled',
            !(token === page.TRUNCATE_TOKEN && reason.length > 0)
        );
        page.$el('truncateModalError').text('');
    }

    async handleSeriesFieldAction(event) {
        const page = this;
        const $button = $(event.target);
        const action = $button.data('field-action');
        const fieldId = Number($button.data('field-id'));
        const scope = $button.data('field-scope');
        if (!fieldId || !scope) {
            return;
        }
        if (action === 'edit') {
            const field = page.findFieldById(fieldId, scope);
            if (!field) {
                page.setStatus('seriesFields', 'Field not found.', true);
                return;
            }
            if (scope === page.FIELD_SCOPE.SERIES) {
                page.populateSeriesMetadataFieldForm(field);
            } else {
                page.populateSeriesFieldForm(field, scope);
            }
            return;
        }
        if (action === 'delete') {
            if (!window.confirm('Delete this field?')) {
                return;
            }
            try {
                const response = await page.postJson('v1.deleteSeriesField', { id: fieldId });
                if (!response.success) {
                    page.handleErrorResponse(response, 'seriesFields');
                    return;
                }
                page.setStatus('seriesFields', 'Series field deleted.', false);
                page.loadSeriesContext(page.state.selectedNodeId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('seriesFields', 'Failed to delete series field.', error);
            }
        }
    }

    async handleProductListAction(event) {
        const page = this;
        const $button = $(event.target);
        const action = $button.data('product-action');
        const productId = Number($button.data('product-id'));
        if (!action || !productId) {
            return;
        }
        const product = page.state.products.find((item) => item.id === productId);
        if (!product) {
            page.setStatus('products', 'Product not found.', true);
            return;
        }
        if (action === 'edit') {
            page.populateProductForm(product);
            return;
        }
        if (action === 'delete') {
            if (!window.confirm('Delete this product?')) {
                return;
            }
            try {
                const response = await page.postJson('v1.deleteProduct', { id: productId });
                if (!response.success) {
                    page.handleErrorResponse(response, 'products');
                    return;
                }
                page.setStatus('products', 'Product deleted.', false);
                page.resetProductForm();
                await page.loadProductsOnly(page.state.selectedNodeId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('products', 'Failed to delete product.', error);
            }
        }
    }

    bindHierarchyEvents() {
        const page = this;
        page.$el('hierarchyContainer').on('click', '.node-toggle', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const $btn = $(event.currentTarget);
            const nodeId = Number($btn.data('node-toggle'));

            if (page.state.expandedNodes.has(nodeId)) {
                page.state.expandedNodes.delete(nodeId);
            } else {
                page.state.expandedNodes.add(nodeId);
            }
            page.renderHierarchy();
        });

        page.$el('hierarchyContainer').on('click', 'a[data-node-id]', (event) => {
            event.preventDefault();
            const nodeId = Number($(event.currentTarget).data('node-id'));
            page.selectNode(nodeId);
        });

        let searchTimeout;
        page.$el('hierarchySearch').on('input', (event) => {
            const query = $(event.target).val().trim();
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                page.handleSearch(query);
            }, 300);
        });

        page.$el('nodeCreateForm').on('submit', async (event) => {
            event.preventDefault();
            const parentValue = page.$el('createParentId').val();
            const payload = {
                parentId:
                    parentValue === '' ? null : page.toInt(parentValue, null),
                name: page.$el('createNodeName').val(),
                type: page.$el('createNodeType').val(),
                displayOrder: page.toInt(page.$el('createDisplayOrder').val()),
            };
            try {
                const response = await page.postJson('v1.saveNode', payload);
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    return;
                }
                page.setStatus('catalog', 'Node created.', false);
                page.$el('nodeCreateForm')[0].reset();
                await page.loadHierarchy({ clearStatus: false });
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to create node.', error);
            }
        });

        page.$el('nodeUpdateForm').on('submit', async (event) => {
            event.preventDefault();
            if (!page.state.selectedNodeId) {
                page.setStatus('catalog', 'Select a node to update.', true);
                return;
            }
            const selectedNode = page.state.nodeIndex.get(page.state.selectedNodeId);
            const displayOrderInput = page.toInt(
                page.$el('updateNodeDisplayOrder').val(),
                selectedNode?.displayOrder ?? 0
            );
            const payload = {
                id: page.state.selectedNodeId,
                // Preserve parent linkage (series updates fail validation without parentId).
                parentId: selectedNode?.parentId ?? null,
                name: page.$el('updateNodeName').val(),
                // Keep existing order when the input is blank to avoid zeroing the value.
                displayOrder: displayOrderInput,
                type: page.$el('updateNodeTypeValue').val(),
            };
            try {
                const response = await page.postJson('v1.saveNode', payload);
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    return;
                }
                page.setStatus('catalog', 'Node updated.', false);
                await page.loadHierarchy({ clearStatus: false });
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to update node.', error);
            }
        });

        page.$el('nodeDeleteButton').on('click', async () => {
            if (!page.state.selectedNodeId) {
                return;
            }
            if (!window.confirm('Delete the selected node?')) {
                return;
            }
            try {
                const response = await page.postJson('v1.deleteNode', {
                    id: page.state.selectedNodeId,
                });
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    return;
                }
                page.setStatus('catalog', 'Node deleted.', false);
                page.state.selectedNodeId = null;
                await page.loadHierarchy({ clearStatus: false });
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to delete node.', error);
            }
        });

        page.$el('enableTypstTemplating').on('change', async function () {
            if (!page.state.selectedNodeId) {
                page.setStatus('catalog', 'Select a series first.', true);
                page.applyTypstTemplatingState(false);
                return;
            }
            const node = page.state.nodeIndex.get(page.state.selectedNodeId);
            if (!node || node.type !== 'series') {
                page.setStatus('catalog', 'Select a series first.', true);
                page.applyTypstTemplatingState(false);
                return;
            }
            const desiredState = $(this).is(':checked');
            const previousState = Boolean(node.typstTemplatingEnabled);
            page.$el('enableTypstTemplating').prop('disabled', true);
            try {
                const response = await page.putJson('v1.setSeriesTypstTemplating', {
                    seriesId: page.state.selectedNodeId,
                    enabled: desiredState,
                });
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    page.setTypstTemplatingFlagOnNode(node.id, previousState);
                    page.applyTypstTemplatingState(previousState);
                    return;
                }
                const enabled = Boolean(
                    response.data?.typstTemplatingEnabled ?? desiredState
                );
                page.setTypstTemplatingFlagOnNode(node.id, enabled);
                page.applyTypstTemplatingState(enabled);
                page.setStatus(
                    'catalog',
                    enabled
                        ? 'Typst templating enabled for this series.'
                        : 'Typst templating disabled for this series.',
                    false
                );
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to update Typst templating.', error);
                page.setTypstTemplatingFlagOnNode(node.id, previousState);
                page.applyTypstTemplatingState(previousState);
            } finally {
                page.$el('enableTypstTemplating').prop('disabled', false);
            }
        });

        page.$el('hierarchyContainer').on('click', '.node-toggle', (event) => {
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
    }

    bindSeriesFieldEvents() {
        const page = this;
        page.$el('seriesFieldsTable').on('click', '.series-field-action', page.handleSeriesFieldAction);
        page.$el('seriesMetadataFieldsTable').on(
            'click',
            '.series-field-action',
            page.handleSeriesFieldAction
        );

        page.$el('seriesFieldForm').on('submit', async (event) => {
            event.preventDefault();
            if (!page.state.selectedNodeId) {
                page.setStatus('seriesFields', 'Select a series first.', true);
                return;
            }
            const payload = {
                seriesId: page.state.selectedNodeId,
                id: page.toInt(page.$el('seriesFieldId').val(), null),
                fieldKey: page.$el('seriesFieldKey').val(),
                label: page.$el('seriesFieldLabel').val(),
                fieldType: page.$el('seriesFieldType').val(),
                fieldScope: page.FIELD_SCOPE.PRODUCT,
                sortOrder: page.toInt(page.$el('seriesFieldSortOrder').val()),
                publicPortalHidden: page.$el('seriesFieldPublicHidden').is(':checked'),
                backendPortalHidden: page.$el('seriesFieldBackendHidden').is(':checked'),
                isRequired: page.$el('seriesFieldRequired').is(':checked'),
            };
            if (!payload.id) {
                delete payload.id;
            }
            try {
                const response = await page.postJson('v1.saveSeriesField', payload);
                if (!response.success) {
                    page.handleErrorResponse(response, 'seriesFields');
                    return;
                }
                const savedField = response.data || payload;
                page.state.seriesFields = page.upsertField(page.state.seriesFields, savedField);
                page.renderSeriesFieldsTable();
                page.renderProductFormFields();
                page.setStatus('seriesFields', 'Series field saved.', false);
                page.resetSeriesFieldForm();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('seriesFields', 'Failed to save series field.', error);
            }
        });

        page.$el('seriesFieldClearButton').on('click', () => {
            page.resetSeriesFieldForm();
        });

        page.$el('seriesMetadataFieldForm').on('submit', async (event) => {
            event.preventDefault();
            if (!page.state.selectedNodeId) {
                page.setStatus('seriesMetadata', 'Select a series first.', true);
                return;
            }
            const payload = {
                seriesId: page.state.selectedNodeId,
                id: page.toInt(page.$el('seriesMetadataFieldId').val(), null),
                fieldKey: page.$el('seriesMetadataFieldKey').val(),
                label: page.$el('seriesMetadataFieldLabel').val(),
                fieldType: page.$el('seriesMetadataFieldType').val(),
                fieldScope: page.FIELD_SCOPE.SERIES,
                sortOrder: page.toInt(page.$el('seriesMetadataFieldSortOrder').val()),
                publicPortalHidden: page.$el('seriesMetadataFieldPublicHidden').is(':checked'),
                backendPortalHidden: page.$el('seriesMetadataFieldBackendHidden').is(':checked'),
                isRequired: page.$el('seriesMetadataFieldRequired').is(':checked'),
            };
            if (!payload.id) {
                delete payload.id;
            }
            try {
                const response = await page.postJson('v1.saveSeriesField', payload);
                if (!response.success) {
                    page.handleErrorResponse(response, 'seriesMetadata');
                    return;
                }
                const savedField = response.data || payload;
                page.state.seriesMetadataFields = page.upsertField(
                    page.state.seriesMetadataFields,
                    savedField
                );
                page.state.seriesMetadataValues = {
                    ...page.state.seriesMetadataValues,
                    [savedField.fieldKey]:
                        page.state.seriesMetadataValues?.[savedField.fieldKey] ??
                        savedField.defaultValue ??
                        '',
                };
                page.renderSeriesMetadataFieldsTable();
                page.renderSeriesMetadataValues();
                page.setStatus('seriesMetadata', 'Metadata field saved.', false);
                page.resetSeriesMetadataFieldForm();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('seriesMetadata', 'Failed to save metadata field.', error);
            }
        });

        page.$el('seriesMetadataFieldClearButton').on('click', () => {
            page.resetSeriesMetadataFieldForm();
        });
    }

    bindMetadataEvents() {
        const page = this;
        page.$el('seriesMetadataForm').on('submit', async (event) => {
            event.preventDefault();
            if (!page.state.selectedNodeId) {
                page.setStatus('seriesMetadata', 'Select a series first.', true);
                return;
            }
            const values = {};
            const fields = page.getMetadataFields();
            const formData = new FormData();
            let hasFile = false;

            fields.forEach((field) => {
                const key = field.fieldKey;
                if (field.fieldType === 'file') {
                    const input = page.$el('seriesMetadataValues').find(`input[data-metadata-file="${key}"]`)[0];
                    const clearInput = page.$el('seriesMetadataValues').find(`input[data-metadata-clear="${key}"]`)[0];
                    const hasSelection = input?.files && input.files.length > 0;
                    if (hasSelection) {
                        formData.append(`files[${key}]`, input.files[0]);
                        hasFile = true;
                    } else if (clearInput && clearInput.checked) {
                        values[key] = '';
                    }
                } else {
                    const input = page.$el('seriesMetadataValues').find(`input[data-metadata-key="${key}"]`)[0];
                    if (input) {
                        values[key] = input.value;
                    }
                }
            });

            const metadataPayload = {
                seriesId: page.state.selectedNodeId,
                values,
            };
            try {
                let response;
                if (hasFile) {
                    formData.append('metadata', JSON.stringify(metadataPayload));
                    response = await page.postMultipart('v1.saveSeriesAttributes', formData);
                } else {
                    response = await page.postJson('v1.saveSeriesAttributes', metadataPayload);
                }
                if (!response.success) {
                    page.handleErrorResponse(response, 'seriesMetadata');
                    return;
                }
                page.setStatus('seriesMetadata', 'Metadata saved.', false);
                await page.loadSeriesContext(page.state.selectedNodeId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('seriesMetadata', 'Failed to save metadata.', error);
            }
        });

        page.$el('seriesMetadataResetButton').on('click', () => {
            page.renderSeriesMetadataValues();
        });
    }

    bindProductEvents() {
        const page = this;
        page.$el('productForm').on('submit', async (event) => {
            event.preventDefault();
            if (!page.state.selectedNodeId) {
                page.setStatus('products', 'Select a series first.', true);
                return;
            }
            const customValues = {};
            const fields = page.getProductFields();
            const formData = new FormData();
            let hasFile = false;

            fields.forEach((field) => {
                const key = field.fieldKey;
                if (field.fieldType === 'file') {
                    const input = page.$el('productCustomFields').find(`input[data-field-file="${key}"]`)[0];
                    const clearInput = page.$el('productCustomFields').find(`input[data-field-clear="${key}"]`)[0];
                    const hasSelection = input?.files && input.files.length > 0;
                    if (hasSelection) {
                        formData.append(`files[${key}]`, input.files[0]);
                        hasFile = true;
                    } else if (clearInput && clearInput.checked) {
                        customValues[key] = '';
                    }
                } else {
                    const input = page.$el('productCustomFields').find(`input[data-field-key="${key}"]`)[0];
                    if (input) {
                        customValues[key] = input.value;
                    }
                }
            });

            const payload = {
                id: page.toInt(page.$el('productId').val(), null),
                seriesId: page.state.selectedNodeId,
                sku: page.$el('productSku').val(),
                name: page.$el('productName').val(),
                description: page.$el('productDescription').val(),
                customValues,
            };
            if (!payload.id) {
                delete payload.id;
            }
            try {
                let response;
                if (hasFile) {
                    formData.append('metadata', JSON.stringify(payload));
                    response = await page.postMultipart('v1.saveProduct', formData);
                } else {
                    response = await page.postJson('v1.saveProduct', payload);
                }
                if (!response.success) {
                    page.handleErrorResponse(response, 'products');
                    return;
                }
                page.setStatus('products', 'Product saved.', false);
                page.resetProductForm();
                await page.loadProductsOnly(page.state.selectedNodeId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('products', 'Failed to save product.', error);
            }
        });

        page.$el('productClearButton').on('click', () => {
            page.resetProductForm();
        });

        page.$el('productDeleteButton').on('click', async () => {
            if (!page.state.selectedProductId) {
                return;
            }
            if (!window.confirm('Delete the selected product?')) {
                return;
            }
            try {
                const response = await page.postJson('v1.deleteProduct', {
                    id: page.state.selectedProductId,
                });
                if (!response.success) {
                    page.handleErrorResponse(response, 'products');
                    return;
                }
                page.setStatus('products', 'Product deleted.', false);
                page.resetProductForm();
                await page.loadProductsOnly(page.state.selectedNodeId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('products', 'Failed to delete product.', error);
            }
        });

        page.$el('productListTable').on('click', 'button[data-product-action]', page.handleProductListAction);
    }

    bindCategoryFieldEvents() {
        const page = this;
        page.$el('categoryFieldsTable').on('click', '.category-field-action', page.handleCategoryFieldAction);

        page.$el('categoryFieldForm').on('submit', async (event) => {
            event.preventDefault();
            const categoryId = page.state.selectedCategoryId || page.state.categoryFieldsCategoryId;
            if (!categoryId) {
                page.setStatus('categoryFields', 'Select a category first.', true);
                return;
            }
            const key = page.$el('categoryFieldKey').val().toString().trim();
            const typeSelection = (page.$el('categoryFieldType').val() || 'text').toString();
            if (!key) {
                page.setStatus('categoryFields', 'Field key is required.', true);
                return;
            }
            const payload = {
                seriesId: categoryId,
                key,
                type: typeSelection,
                value: '',
            };
            const inputField = page.$el('categoryFieldDataContainer').find('#category-field-value');
            const fileInput = document.getElementById('category-field-file');
            const clearFile = page.$el('categoryFieldDataContainer').find('#category-field-clear-file').is(':checked');
            let hasFile = false;
            if (typeSelection === 'file' || typeSelection === 'image') {
                if (fileInput?.files?.length) {
                    hasFile = true;
                } else if (clearFile) {
                    payload.value = '';
                } else {
                    payload.value = page.state.categoryFieldCurrentValue || '';
                }
                payload.type = 'file';
            } else {
                payload.value = inputField.val();
            }
            const idValue = page.toInt(page.$el('categoryFieldId').val(), null);
            if (idValue) {
                payload.id = idValue;
            }
            try {
                const response = await page.saveCategoryField(payload, fileInput, hasFile);
                if (!response.success) {
                    page.handleErrorResponse(response, 'categoryFields', 'Failed to save category field.');
                    return;
                }
                page.setStatus('categoryFields', 'Category field saved.', false);
                await page.loadCategoryFields(categoryId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('categoryFields', 'Failed to save category field.', error);
            }
        });

        page.$el('categoryFieldAddButton').on('click', () => {
            page.resetCategoryFieldForm();
            page.setStatus('categoryFields', '');
        });

        page.$el('categoryFieldDeleteButton').on('click', async () => {
            if (!page.state.selectedCategoryFieldId) {
                return;
            }
            if (!window.confirm('Delete this category field?')) {
                return;
            }
            try {
                const response = await page.deleteCategoryField(page.state.selectedCategoryFieldId);
                if (!response?.success) {
                    page.handleErrorResponse(response, 'categoryFields', 'Failed to delete category field.');
                    return;
                }
                page.setStatus('categoryFields', 'Category field deleted.', false);
                await page.loadCategoryFields(page.state.categoryFieldsCategoryId);
            } catch (error) {
                console.error(error);
                page.setStatusWithError('categoryFields', 'Failed to delete category field.', error);
            }
        });

        page.$el('categoryFieldType').on('change', () => {
            const selectedType = page.$el('categoryFieldType').val()?.toString() || 'text';
            const selectedField =
                page.state.categoryFields.find((item) => Number(item.id) === Number(page.state.selectedCategoryFieldId)) || {};
            const currentValue =
                page.$el('categoryFieldDataContainer').find('#category-field-value').val() ??
                page.state.categoryFieldCurrentValue ??
                '';
            page.renderCategoryFieldInput(selectedType, currentValue, selectedField);
        });
    }

    bindCsvEvents() {
        const page = this;
        page.$el('csvExportButton').on('click', async () => {
            if (page.state.truncate.submitting || page.state.truncate.serverLock) {
                page.setStatus('catalog', 'Catalog truncate in progress. Try again after it completes.', true);
                return;
            }
            const $button = page.$el('csvExportButton');
            $button.prop('disabled', true);
            try {
                const response = await page.postJson('v1.exportCsv', {});
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    return;
                }
                const file = response.data || {};
                page.setStatus('catalog', 'Catalog CSV exported.', false);
                await page.loadCsvHistory();
                if (file.id) {
                    page.triggerCsvDownload(file.id);
                }
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to export catalog CSV.', error);
            } finally {
                $button.prop('disabled', false);
            }
        });

        page.$el('csvImportForm').on('submit', async (event) => {
            event.preventDefault();
            if (page.state.truncate.submitting || page.state.truncate.serverLock) {
                page.setStatus('catalog', 'Catalog truncate in progress. CSV import disabled until it completes.', true);
                return;
            }
            const fileInput = page.$el('csvImportFile')[0];
            if (!fileInput.files || !fileInput.files.length) {
                page.setStatus('catalog', 'Select a CSV file to import.', true);
                return;
            }
            const formData = new FormData();
            formData.append('file', fileInput.files[0]);
            const $submit = page.$el('csvImportSubmit');
            $submit.prop('disabled', true);
            try {
                const response = await page.postMultipart('v1.importCsv', formData);
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    return;
                }
                const data = response.data || {};
                const message = `CSV import completed (${data.importedProducts ?? 0} products, ${data.createdSeries ?? 0} new series, ${data.createdCategories ?? 0} new categories).`;
                page.setStatus('catalog', message, false);
                fileInput.value = '';
                await page.loadHierarchy({ clearStatus: false });
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to import CSV.', error);
            } finally {
                $submit.prop('disabled', false);
            }
        });

        page.$el('csvHistoryTable').on('click', 'button[data-csv-download]', (event) => {
            const fileId = $(event.currentTarget).data('csv-download');
            page.triggerCsvDownload(fileId);
        });

        page.$el('csvHistoryTable').on('click', 'button[data-csv-restore]', async (event) => {
            const fileId = $(event.currentTarget).data('csv-restore');
            if (!fileId) {
                return;
            }
            const $button = $(event.currentTarget);
            $button.prop('disabled', true);
            try {
                const response = await page.postJson('v1.restoreCsv', { id: fileId });
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    return;
                }
                const data = response.data || {};
                const message = `CSV restore completed (${data.importedProducts ?? 0} products, ${data.createdSeries ?? 0} new series, ${data.createdCategories ?? 0} new categories).`;
                page.setStatus('catalog', message, false);
                await page.loadHierarchy({ clearStatus: false });
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to restore CSV file.', error);
            } finally {
                $button.prop('disabled', false);
            }
        });

        page.$el('csvHistoryTable').on('click', 'button[data-csv-delete]', async (event) => {
            const fileId = $(event.currentTarget).data('csv-delete');
            if (!fileId) {
                return;
            }
            if (!window.confirm('Delete this CSV file?')) {
                return;
            }
            try {
                const response = await page.postJson('v1.deleteCsv', { id: fileId });
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    return;
                }
                page.setStatus('catalog', 'CSV file deleted.', false);
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('catalog', 'Failed to delete CSV file.', error);
            }
        });
    }

    bindTruncateEvents() {
        const page = this;
        page.$el('truncateButton').on('click', () => {
            if (page.state.truncate.submitting || page.state.truncate.serverLock) {
                page.setStatus('catalog', 'Catalog truncate already in progress. Please wait for it to finish.', true);
                return;
            }
            page.openTruncateModal();
        });

        page.$el('truncateConfirmInput').on('input', page.updateTruncateConfirmState);
        page.$el('truncateReasonInput').on('input', page.updateTruncateConfirmState);

        page.$el('truncateCancelButton').on('click', () => {
            page.closeTruncateModal();
        });

        page.$el('truncateForm').on('submit', async (event) => {
            event.preventDefault();
            if (page.state.truncate.submitting || page.state.truncate.serverLock) {
                page.$el('truncateModalError').text('Another truncate is running. Try again once it completes.');
                return;
            }
            const confirmToken = page.$el('truncateConfirmInput')
                .val()
                .toString()
                .trim()
                .toUpperCase();
            const reason = page.$el('truncateReasonInput').val().toString().trim();
            if (confirmToken !== page.TRUNCATE_TOKEN || !reason) {
                page.$el('truncateModalError').text('Type TRUNCATE and provide a reason to continue.');
                return;
            }
            const payload = {
                reason,
                confirmToken,
                correlationId: page.createCorrelationId(),
            };
            page.state.truncate.submitting = true;
            page.applyCsvLockState();
            try {
                const response = await page.postJson('v1.truncateCatalog', payload);
                if (!response.success) {
                    page.handleErrorResponse(response, 'catalog');
                    page.$el('truncateModalError').text(response.message || 'Truncate failed.');
                    return;
                }
                const auditId = response.data?.auditId || payload.correlationId;
                page.setStatus('catalog', `Catalog truncated (audit ${auditId}).`, false);
                page.closeTruncateModal();
                page.state.selectedNodeId = null;
                page.resetSeriesUI();
                await page.loadHierarchy({ clearStatus: false });
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                const message = AppError.buildUserMessage('Unable to truncate catalog.', error?.correlationId);
                page.$el('truncateModalError').text(message);
                page.setStatusWithError('catalog', 'Unable to truncate catalog.', error);
            } finally {
                page.state.truncate.submitting = false;
                page.applyCsvLockState();
            }
        });
    }

    bindGlobalEvents(csvUiPresent) {
        const page = this;
        page.bindHierarchyEvents();
        page.bindSeriesFieldEvents();
        page.bindMetadataEvents();
        page.bindProductEvents();
        page.bindCategoryFieldEvents();
        if (csvUiPresent) {
            page.bindCsvEvents();
            page.bindTruncateEvents();
        }
    }

    async init() {
        const page = this;
        const csvUiPresent = page.$el('csvHistoryTable').length > 0;
        page.bindGlobalEvents(csvUiPresent);
        page.bindLayoutReflowEvents();
        page.observeSidebarState();
        page.resetSeriesUI();
        page.resetCategoryFieldsUI();
        await page.loadHierarchy();
        await page.applyDeepLinkFromQuery();
        if (csvUiPresent) {
            await page.loadCsvHistory();
        }
    }
}

new CatalogUI();
