/**
 * SpecSearchPage
 * jQuery-powered, class-based controller for the spec search UI.
 * Uses Bootstrap 5 + DataTables and talks to the public/api endpoints.
 */
class SpecSearchPage {
    constructor() {
        this.api = {
            roots: 'api/spec-search/root-categories.php',
            productCategories: 'api/spec-search/product-categories.php',
            products: 'api/spec-search/products.php',
            facets: 'api/spec-search/facets.php',
        };

        this.state = {
            rootId: null,
            categoryIds: [],
            filters: {},
        };

        this.$rootOptions = $('#root-category-options');
        this.$rootCount = $('#root-count');
        this.$categoryContainer = $('#product-categories');
        this.$categoryCount = $('#category-count');
        this.$facetContainer = $('#facet-container');
        this.$selectedFilters = $('#selected-filters');
        this.$status = $('#status-message');
        this.$resultCount = $('#result-count');
        this.$clearFilters = $('#clear-filters');
        this.$table = $('#results-table');

        this.tableInstance = null;
    }

    /**
     * Build a catalog UI deep-link with query parameters.
     */
    buildEditUrl(row) {
        const category =
            row.category ??
            row.category_name ??
            row.categoryId ??
            row.category_id ??
            '';
        const series =
            row.series ??
            row.series_name ??
            '';
        const product =
            row.sku ??
            row.product ??
            row.itemCode ??
            row.item_code ??
            '';
        const params = new URLSearchParams({
            category,
            series,
            product,
        });
        return `catalog_ui.html?${params.toString()}`;
    }

    /**
     * Initialize event bindings, DataTable, and initial data load.
     */
    init() {
        this.bindEvents();
        this.initTable([]);
        this.loadRoots();
    }

    /**
     * Bind DOM events to handlers.
     */
    bindEvents() {
        this.$clearFilters.on('click', () => {
            this.state.filters = {};
            this.renderSelectedFilters();
            this.renderFacets([]);
            this.loadProducts();
        });

        this.$table.on('click', 'button[data-edit-url]', (event) => {
            const url = event.currentTarget.getAttribute('data-edit-url');
            if (url) {
                window.location.href = url;
            }
        });
    }

    /**
     * Set transient status text.
     */
    setStatus(text) {
        this.$status.text(text);
    }

    /**
     * Fetch JSON with basic error handling.
     */
    async fetchJson(url, options = {}) {
        const response = await fetch(url, {
            headers: { 'Content-Type': 'application/json' },
            ...options,
        });

        const text = await response.text();
        let payload = {};
        try {
            payload = text ? JSON.parse(text) : {};
        } catch (parseError) {
            payload = {};
        }

        const correlationId = AppError.extractCorrelationId(payload, response);

        if (!response.ok) {
            const message =
                payload?.error?.message ??
                payload?.message ??
                `Request failed: ${response.status}`;
            AppError.logDev({
                level: 'error',
                endpoint: url,
                status: response.status,
                errorCode: payload?.error?.code ?? 'request_failed',
                correlationId,
                message,
            });
            const error = new Error(AppError.buildUserMessage(message, correlationId));
            error.correlationId = correlationId;
            error.errorCode = payload?.error?.code ?? 'request_failed';
            throw error;
        }

        AppError.logDev({
            level: 'info',
            endpoint: url,
            status: response.status,
            correlationId,
            message: 'ok',
        });

        return payload;
    }

    /**
     * Initialize DataTable with supplied rows and columns.
     */
    initTable(rows, columns = null) {
        const cols =
            columns ||
            [
                { data: 'sku', title: 'SKU' },
                { data: 'name', title: 'Name' },
                { data: 'series', title: 'Series' },
                {
                    title: 'Edit',
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: (_, __, row) => {
                        const url = this.buildEditUrl(row);
                        return `<button type="button" class="btn btn-sm btn-outline-primary" data-edit-url="${url}">Edit</button>`;
                    },
                },
            ];

        if (this.tableInstance) {
            this.tableInstance.clear().destroy();
        }

        this.$table.empty();
        const thead = $('<thead><tr></tr></thead>');
        cols.forEach((col) => thead.find('tr').append(`<th>${col.title}</th>`));
        this.$table.append(thead, $('<tbody></tbody>'));

        this.tableInstance = this.$table.DataTable({
            data: rows,
            columns: cols,
            paging: true,
            searching: true,
            info: true,
            order: [],
            language: {
                zeroRecords: 'No products found.',
            },
        });
    }

    /**
     * Load root categories and render radio inputs.
     */
    async loadRoots() {
        try {
            this.setStatus('Loading roots...');
            const res = await this.fetchJson(this.api.roots);
            const roots = res?.data?.categories || [];
            this.$rootOptions.empty();
            this.$rootCount.text(`${roots.length} roots`);

            if (roots.length === 0) {
                this.$rootOptions.html('<div class="text-muted small">No roots available.</div>');
                return;
            }

            roots.forEach((root, idx) => {
                const id = `root-${root.id}`;
                const radio = $(`
                    <label class="form-check">
                        <input class="form-check-input" type="radio" name="root_category" id="${id}" value="${root.id}" ${idx === 0 ? 'checked' : ''}>
                        <span class="form-check-label">${root.name}</span>
                    </label>
                `);
                radio.find('input').on('change', () => {
                    this.state.rootId = root.id;
                    this.loadCategories(root.id);
                });
                this.$rootOptions.append(radio);
            });

            this.state.rootId = roots[0].id;
            this.loadCategories(this.state.rootId);
        } catch (err) {
            this.setStatus(`Error loading roots: ${err.message}`);
        } finally {
            this.setStatus('');
        }
    }

    /**
     * Load product categories for a root and render checkboxes.
     */
    async loadCategories(rootId) {
        try {
            this.setStatus('Loading categories...');
            this.$categoryContainer.html('<div class="text-muted small">Loading...</div>');
            const res = await this.fetchJson(`${this.api.productCategories}?root_id=${rootId}`);
            const groups = res?.data?.groups || [];
            this.$categoryContainer.empty();
            this.state.categoryIds = [];
            this.$categoryCount.text('0 selected');
            this.renderFacets([]);
            this.renderSelectedFilters();
            this.initTable([]);
            this.$resultCount.text('0 items');

            if (groups.length === 0) {
                this.$categoryContainer.html('<div class="text-muted small">No categories found.</div>');
                return;
            }

            groups.forEach((group) => {
                const card = $(`
                    <div class="facet-card">
                        <h6 class="mb-2">${group.group}</h6>
                        <div class="vstack gap-1"></div>
                    </div>
                `);
                const list = card.find('.vstack');
                group.categories.forEach((cat) => {
                    const checkbox = $(`
                        <label class="form-check form-check-sm">
                            <input class="form-check-input" type="checkbox" value="${cat.id}">
                            <span class="form-check-label">${cat.name}</span>
                        </label>
                    `);
                    checkbox.find('input').on('change', () => this.handleCategoryChange());
                    list.append(checkbox);
                });
                this.$categoryContainer.append(card);
            });
        } catch (err) {
            this.setStatus(`Error loading categories: ${err.message}`);
        } finally {
            this.setStatus('');
        }
    }

    /**
     * Handle category checkbox changes and trigger data loads.
     */
    handleCategoryChange() {
        const ids = this.$categoryContainer
            .find('input[type="checkbox"]:checked')
            .map((_, el) => parseInt(el.value, 10))
            .get();
        this.state.categoryIds = ids;
        this.$categoryCount.text(`${ids.length} selected`);
        this.state.filters = {};
        this.renderSelectedFilters();
        this.renderFacets([]);

        if (ids.length === 0) {
            this.initTable([]);
            this.$resultCount.text('0 items');
            return;
        }
        this.loadFacets();
        this.loadProducts();
    }

    /**
     * Load facets for selected categories.
     */
    async loadFacets() {
        try {
            this.setStatus('Loading filters...');
            const res = await this.fetchJson(this.api.facets, {
                method: 'POST',
                body: JSON.stringify({ category_ids: this.state.categoryIds }),
            });
            const facets = res?.data?.facets || [];
            this.renderFacets(facets);
        } catch (err) {
            this.setStatus(`Error loading filters: ${err.message}`);
        } finally {
            this.setStatus('');
        }
    }

    /**
     * Render facet checkboxes and bind change handlers.
     */
    renderFacets(facets) {
        this.$facetContainer.empty();

        if (!facets || facets.length === 0) {
            this.$facetContainer.html('<div class="text-muted small">No filters yet. Select categories.</div>');
            return;
        }

        facets.forEach((facet) => {
            const card = $(`
                <div class="facet-card">
                    <h6 class="mb-2">${facet.label}</h6>
                    <input type="search" class="form-control form-control-sm facet-filter-input" placeholder="Search ${facet.label}..." />
                    <ul class="facet-list mt-2" data-key="${facet.key}"></ul>
                </div>
            `);
            const list = card.find('.facet-list');
            facet.values.forEach((val) => {
                const id = `${facet.key}-${val}`.replace(/[^a-zA-Z0-9_-]/g, '');
                const checked = this.state.filters[facet.key]?.includes(val);
                const li = $(`
                    <li>
                        <div class="form-check form-check-sm">
                            <input class="form-check-input" type="checkbox" id="${id}" value="${val}" ${checked ? 'checked' : ''}>
                            <label class="form-check-label" for="${id}">${val}</label>
                        </div>
                    </li>
                `);
                li.find('input').on('change', (event) => {
                    this.handleFilterChange(facet.key, val, event.target.checked);
                });
                list.append(li);
            });
            const $input = card.find('.facet-filter-input');
            const applyFilter = () => {
                const term = $input.val().toString().toLowerCase().trim();
                const hasTerm = term.length > 0;
                list.find('li').each((_, li) => {
                    const text = $(li).text().toLowerCase();
                    $(li).toggle(!hasTerm || text.includes(term));
                });
            };
            $input.on('input', applyFilter);
            this.$facetContainer.append(card);
        });
    }

    /**
     * Handle filter checkbox change and reload products.
     */
    handleFilterChange(key, value, isChecked) {
        const existing = this.state.filters[key] || [];
        this.state.filters[key] = isChecked
            ? Array.from(new Set([...existing, value]))
            : existing.filter((v) => v !== value);
        if (this.state.filters[key].length === 0) {
            delete this.state.filters[key];
        }
        this.renderSelectedFilters();
        this.loadProducts();
    }

    /**
     * Render selected filters as chips.
     */
    renderSelectedFilters() {
        this.$selectedFilters.empty();
        const entries = Object.entries(this.state.filters);
        if (entries.length === 0) {
            this.$selectedFilters.append('<span class="text-muted small">No filters applied.</span>');
            return;
        }

        entries.forEach(([key, values]) => {
            values.forEach((val) => {
                const chip = $(`
                    <span class="chip" data-key="${key}" data-value="${val}">
                        <span>${key}: ${val}</span>
                        <button type="button" aria-label="Remove ${key} ${val}">&times;</button>
                    </span>
                `);
                chip.find('button').on('click', () => {
                    this.handleFilterChange(key, val, false);
                    this.$facetContainer
                        .find(`ul[data-key="${key}"] input[value="${val}"]`)
                        .prop('checked', false);
                });
                this.$selectedFilters.append(chip);
            });
        });
    }

    /**
     * Load products and rebuild the DataTable with dynamic columns.
     */
    async loadProducts() {
        if (this.state.categoryIds.length === 0) {
            this.initTable([]);
            this.$resultCount.text('0 items');
            return;
        }

        try {
            this.setStatus('Loading products...');
            const res = await this.fetchJson(this.api.products, {
                method: 'POST',
                body: JSON.stringify({
                    category_ids: this.state.categoryIds,
                    filters: this.state.filters,
                }),
            });

            const items = res?.data?.items || [];
            this.$resultCount.text(`${items.length} items`);

            const dynamicKeys = new Set();
            items.forEach((item) => {
                Object.keys(item).forEach((k) => {
                    if (!['id', 'sku', 'name', 'series'].includes(k)) {
                        dynamicKeys.add(k);
                    }
                });
            });

        const columns = [
            { title: 'SKU', data: (row) => row.sku ?? '' },
            { title: 'Name', data: (row) => row.name ?? '' },
            { title: 'Series', data: (row) => row.series ?? '' },
            ...Array.from(dynamicKeys).map((key) => ({
                title: key,
                data: (row) => (row[key] !== undefined && row[key] !== null ? row[key] : ''),
            })),
            {
                title: 'Edit',
                data: null,
                orderable: false,
                searchable: false,
                render: (_, __, row) => {
                    const url = this.buildEditUrl(row);
                    return `<button type="button" class="btn btn-sm btn-outline-primary" data-edit-url="${url}">Edit</button>`;
                },
            },
        ];

        this.initTable(items, columns);
    } catch (err) {
        this.setStatus(`Error loading products: ${err.message}`);
        } finally {
            this.setStatus('');
        }
    }
}

// Bootstrap the page once DOM is ready.
$(document).ready(() => {
    const page = new SpecSearchPage();
    page.init();
});
