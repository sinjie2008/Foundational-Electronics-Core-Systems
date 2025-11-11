/**
 * catalog_ui.js
 * Provides frontend behaviors for the product catalog manager UI.
 */
(function ($) {
        var apiBase = 'catalog.php';
        var FIELD_SCOPE_PRODUCT = 'product_attribute';
        var FIELD_SCOPE_SERIES = 'series_metadata';
        // state caches hierarchy data, current selections, and reusable collections.
        var state = {
            hierarchy: [],
            seriesOptions: [],
            nodeIndex: {},
            selectedNodeId: null,
            seriesDataRequestId: 0,
            seriesFields: [],
            seriesMetadataFields: [],
            seriesMetadataValues: {},
            products: [],
            selectedProductId: null,
            search: {
                fieldMeta: [],
                filters: {},
                seriesMetaDefinitions: [],
                seriesMetaFilters: {},
                seriesFieldRequestId: 0,
                currentSeriesId: null
            }
        };

        function isSeriesRequestCurrent(seriesId, requestId) {
            return state.selectedNodeId === seriesId && requestId === state.seriesDataRequestId;
        }


        function isSearchRequestCurrent(seriesId, requestId) {
            var normalizedSeries = seriesId ? parseInt(seriesId, 10) : null;
            return state.search.currentSeriesId === normalizedSeries && requestId === state.search.seriesFieldRequestId;
        }

        function setStatus(message, isError) {
            var $status = $('#status-message');
            $status.removeClass('status-info status-error');
            if (!message) {
                $status.text('');
                return;
            }
            var prefix = isError ? 'Error: ' : 'Info: ';
            $status.text(prefix + message);
            $status.addClass(isError ? 'status-error' : 'status-info');
        }

        function handleErrorResponse(response) {
            if (!response) {
                setStatus('Unexpected error occurred.', true);
                return;
            }
            var message = response.message || 'Request failed.';
            var detailParts = [];
            if (response.details) {
                $.each(response.details, function (key, value) {
                    if (value) {
                        detailParts.push(value);
                    }
                });
            }
            if (detailParts.length > 0) {
                message = message + ' (' + detailParts.join('; ') + ')';
            }
            setStatus(message, true);
        }

        function normalizeChildren(children) {
            if (!children) {
                return [];
            }
            if (Array.isArray(children)) {
                return children;
            }
            if (typeof children === 'object') {
                var list = [];
                $.each(children, function (_, child) {
                    list.push(child);
                });
                return list;
            }
            return [];
        }

        function rebuildNodeIndex() {
            state.nodeIndex = {};
            function walk(nodes) {
                $.each(nodes, function (_, node) {
                    state.nodeIndex[node.id] = node;
                    var children = normalizeChildren(node.children);
                    if (children.length > 0) {
                        walk(children);
                    }
                });
            }
            walk(state.hierarchy);
        }

        function buildNodeList(nodes) {
            var $ul = $('<ul></ul>');
            $.each(nodes, function (_, node) {
                var $li = $('<li></li>');
                var nodeName = node.name || node.Name || node.label || '(unnamed)';
                var nodeType = node.type || node.Type || 'unknown';
                var label = nodeName + ' [' + nodeType + ']';
                var $link = $('<a href="#"></a>').text(label);
                $link.attr('data-node-id', node.id);
                $li.append($link);
                var children = normalizeChildren(node.children);
                if (children.length > 0) {
                    $li.append(buildNodeList(children));
                }
                $ul.append($li);
            });
            return $ul;
        }

        function renderHierarchy() {
            var $container = $('#hierarchy-container');
            if (!state.hierarchy || state.hierarchy.length === 0) {
                $container.text('No categories defined.');
                return;
            }
            $container.empty().append(buildNodeList(state.hierarchy));
        }

        function rebuildSeriesOptions() {
            var $select = $('#search-series-select');
            var previous = $select.val();
            var options = [];
            if (state.seriesOptions && state.seriesOptions.length > 0) {
                options = state.seriesOptions.slice(0);
            } else {
                $.each(state.nodeIndex, function (id, node) {
                    var nodeType = node.type || node.Type;
                    if (nodeType === 'series') {
                        options.push({ id: node.id, name: node.name || node.Name || '(unnamed series)' });
                    }
                });
            }
            options.sort(function (a, b) {
                return a.name.localeCompare(b.name);
            });
            $select.empty();
            $select.append('<option value="">Any Series</option>');
            var hasPrevious = false;
            $.each(options, function (_, option) {
                var value = String(option.id);
                var text = option.name || option.Name || '(unnamed series)';
                var $opt = $('<option></option>').attr('value', value).text(text);
                if (value === String(previous)) {
                    hasPrevious = true;
                }
                $select.append($opt);
            });
            if (hasPrevious) {
                $select.val(previous);
            } else {
                $select.val('');
                renderSearchFieldFilters([], {});
            }
        }

        function selectNode(nodeId) {
            if (!state.nodeIndex[nodeId]) {
                state.selectedNodeId = null;
            } else {
                state.selectedNodeId = nodeId;
            }
            updateSelectedNodePanel();
        }

        function resetSeriesUI() {
            state.seriesFields = [];
            state.seriesMetadataFields = [];
            state.seriesMetadataValues = {};
            state.products = [];
            state.selectedProductId = null;
            $('#series-fields-table').empty();
            $('#series-metadata-fields-table').empty();
            $('#series-metadata-values').empty();
            resetSeriesFieldForm();
            resetSeriesMetadataFieldForm();
            resetSeriesMetadataForm();
            $('#product-list-table').empty();
            resetProductForm();
            $('#series-management').prop('hidden', true);
        }

        function updateSelectedNodePanel() {
            var $details = $('#selected-node-details');
            var node = state.selectedNodeId ? state.nodeIndex[state.selectedNodeId] : null;
            if (!node) {
                $details.text('Select a category or series to view details.');
                $('#update-node-id').val('');
                $('#update-node-id-text').text('None');
                $('#update-node-parent-id').text('N/A');
                $('#update-node-type-text').text('N/A');
                $('#update-node-type-value').val('');
                $('#update-node-name').val('');
                $('#update-node-display-order').val('0');
                $('#node-update-submit').prop('disabled', true);
                $('#node-delete-button').prop('disabled', true);
                $('#create-parent-id').val('');
                resetSeriesUI();
                return;
            }

            var parentLabel = node.parentId !== null ? node.parentId : '(root)';
            var info = [
                'ID: ' + node.id,
                'Parent ID: ' + parentLabel,
                'Type: ' + node.type,
                'Display Order: ' + node.displayOrder
            ];
            $details.html('<p>' + info.join('</p><p>') + '</p>');

            $('#update-node-id').val(node.id);
            $('#update-node-id-text').text(String(node.id));
            $('#update-node-parent-id').text(String(parentLabel));
            $('#update-node-type-text').text(node.type);
            $('#update-node-type-value').val(node.type);
            $('#update-node-name').val(node.name);
            $('#update-node-display-order').val(node.displayOrder);
            $('#node-update-submit').prop('disabled', false);
            $('#node-delete-button').prop('disabled', false);
            $('#create-parent-id').val(node.id);

            if (node.type === 'series') {
                $('#series-management').prop('hidden', false);
                loadSeriesFields(node.id);
                loadProducts(node.id);
            } else {
                resetSeriesUI();
            }
        }

        function loadHierarchy() {
            setStatus('', false);
            $.getJSON(apiBase, { action: 'v1.listHierarchy' })
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    var payload = response.data || {};
                    state.hierarchy = payload.hierarchy || [];
                    state.seriesOptions = payload.seriesOptions || [];
                    rebuildNodeIndex();
                    if (state.selectedNodeId && !state.nodeIndex[state.selectedNodeId]) {
                        state.selectedNodeId = null;
                    }
                    renderHierarchy();
                    rebuildSeriesOptions();
                    updateSelectedNodePanel();
                })
                .fail(function () {
                    setStatus('Unable to load hierarchy.', true);
                });
        }

        function postAction(action, payload) {
            return $.ajax({
                url: apiBase + '?action=' + encodeURIComponent(action),
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(payload || {})
            });
        }

        function postMultipart(action, formData) {
            return $.ajax({
                url: apiBase + '?action=' + encodeURIComponent(action),
                method: 'POST',
                data: formData,
                contentType: false,
                processData: false,
                dataType: 'json'
            });
        }

        function resetSeriesFieldForm() {
            $('#series-field-id').val('');
            $('#series-field-key').val('');
            $('#series-field-label').val('');
            $('#series-field-sort-order').val('0');
            $('#series-field-required').prop('checked', false);
            $('#series-field-scope').val(FIELD_SCOPE_PRODUCT);
            $('#series-field-submit').text('Save Field');
        }

        // Reset metadata field form inputs to their defaults.
        function resetSeriesMetadataFieldForm() {
            $('#series-metadata-field-id').val('');
            $('#series-metadata-field-key').val('');
            $('#series-metadata-field-label').val('');
            $('#series-metadata-field-sort-order').val('0');
            $('#series-metadata-field-required').prop('checked', false);
            $('#series-metadata-field-submit').text('Save Metadata Field');
        }

        function populateSeriesFieldForm(field) {
            $('#series-field-id').val(field.id);
            $('#series-field-key').val(field.fieldKey);
            $('#series-field-label').val(field.label);
            $('#series-field-sort-order').val(field.sortOrder);
            $('#series-field-required').prop('checked', field.isRequired);
            $('#series-field-scope').val(field.fieldScope || FIELD_SCOPE_PRODUCT);
            $('#series-field-submit').text('Update Field');
        }

        // Load metadata field details into the dedicated metadata field form.
        function populateSeriesMetadataFieldForm(field) {
            $('#series-metadata-field-id').val(field.id);
            $('#series-metadata-field-key').val(field.fieldKey);
            $('#series-metadata-field-label').val(field.label);
            $('#series-metadata-field-sort-order').val(field.sortOrder);
            $('#series-metadata-field-required').prop('checked', field.isRequired);
            $('#series-metadata-field-submit').text('Update Metadata Field');
            var formElement = document.getElementById('series-metadata-field-form');
            if (formElement && formElement.scrollIntoView) {
                formElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function resetSeriesMetadataForm() {
            $('#series-metadata-values').empty();
            $('#series-metadata-save-button').prop('disabled', true);
        }

        function getProductFields() {
            return state.seriesFields || [];
        }

        function getMetadataFields() {
            return state.seriesMetadataFields || [];
        }

        function findSeriesField(fieldId, scope) {
            var list = scope === FIELD_SCOPE_SERIES ? getMetadataFields() : getProductFields();
            var match = null;
            $.each(list, function (_, field) {
                if (field.id === fieldId) {
                    match = field;
                    return false;
                }
            });
            return match;
        }

        function renderSeriesFields() {
            var productFields = getProductFields();
            var $table = $('#series-fields-table');
            $table.empty();
            if (productFields.length === 0) {
                $table.append('<tr><td>No product attribute fields defined for this series.</td></tr>');
                renderProductFormFields({});
                return;
            }
            var header = $('<tr></tr>')
                .append('<th>ID</th>')
                .append('<th>Key</th>')
                .append('<th>Label</th>')
                .append('<th>Required</th>')
                .append('<th>Sort</th>')
                .append('<th>Actions</th>');
            $table.append(header);
            $.each(productFields, function (_, field) {
                var requiredText = field.isRequired ? 'yes' : 'no';
                var $row = $('<tr></tr>');
                $row.append('<td>' + field.id + '</td>');
                $row.append('<td>' + field.fieldKey + '</td>');
                $row.append('<td>' + field.label + '</td>');
                $row.append('<td>' + requiredText + '</td>');
                $row.append('<td>' + field.sortOrder + '</td>');
                var $actions = $('<td></td>');
                var $edit = $('<button type="button" class="series-field-action">Edit</button>');
                $edit.attr('data-field-action', 'edit')
                    .attr('data-field-id', field.id)
                    .attr('data-field-scope', FIELD_SCOPE_PRODUCT);
                var $delete = $('<button type="button" class="series-field-action">Delete</button>');
                $delete.attr('data-field-action', 'delete')
                    .attr('data-field-id', field.id)
                    .attr('data-field-scope', FIELD_SCOPE_PRODUCT);
                $actions.append($edit).append(' ').append($delete);
                $row.append($actions);
                $table.append($row);
            });
            renderProductFormFields({});
        }

        function renderSeriesMetadataFields() {
            var metadataFields = getMetadataFields();
            var $table = $('#series-metadata-fields-table');
            $table.empty();
            if (metadataFields.length === 0) {
                $table.append('<tr><td>No series metadata fields defined.</td></tr>');
                renderSeriesMetadataValues();
                return;
            }
            var header = $('<tr></tr>')
                .append('<th>ID</th>')
                .append('<th>Key</th>')
                .append('<th>Label</th>')
                .append('<th>Required</th>')
                .append('<th>Sort</th>')
                .append('<th>Actions</th>');
            $table.append(header);
            $.each(metadataFields, function (_, field) {
                var requiredText = field.isRequired ? 'yes' : 'no';
                var $row = $('<tr></tr>');
                $row.append('<td>' + field.id + '</td>');
                $row.append('<td>' + field.fieldKey + '</td>');
                $row.append('<td>' + field.label + '</td>');
                $row.append('<td>' + requiredText + '</td>');
                $row.append('<td>' + field.sortOrder + '</td>');
                var $actions = $('<td></td>');
                var $edit = $('<button type="button" class="series-field-action">Edit</button>');
                $edit.attr('data-field-action', 'edit')
                    .attr('data-field-id', field.id)
                    .attr('data-field-scope', FIELD_SCOPE_SERIES);
                var $delete = $('<button type="button" class="series-field-action">Delete</button>');
                $delete.attr('data-field-action', 'delete')
                    .attr('data-field-id', field.id)
                    .attr('data-field-scope', FIELD_SCOPE_SERIES);
                $actions.append($edit).append(' ').append($delete);
                $row.append($actions);
                $table.append($row);
            });
            renderSeriesMetadataValues();
        }

        function renderSeriesMetadataValues() {
            var metadataFields = getMetadataFields();
            var values = state.seriesMetadataValues || {};
            var $container = $('#series-metadata-values');
            $container.empty();
            if (metadataFields.length === 0) {
                $container.append('<div>No metadata fields to edit.</div>');
                $('#series-metadata-save-button').prop('disabled', true);
                return;
            }
            $.each(metadataFields, function (_, field) {
                var wrapper = $('<div></div>').addClass('metadata-field-row');
                var labelText = field.label + ' (' + field.fieldKey + ')';
                if (field.isRequired) {
                    labelText += ' *';
                }
                var $label = $('<label></label>').text(labelText + ': ');
                var $input = $('<input type="text">')
                    .attr('data-metadata-key', field.fieldKey)
                    .val(values[field.fieldKey] || '');
                wrapper.append($label).append($input);
                $container.append(wrapper);
            });
            $('#series-metadata-save-button').prop('disabled', false);
        }

        function loadSeriesFields(seriesId) {
            state.seriesMetadataValues = {};
            resetSeriesFieldForm();
            resetSeriesMetadataFieldForm();
            var requestId = ++state.seriesDataRequestId;
            var productRequest = $.getJSON(apiBase, { action: 'v1.listSeriesFields', seriesId: seriesId });
            var metadataRequest = $.getJSON(apiBase, {
                action: 'v1.listSeriesFields',
                seriesId: seriesId,
                scope: FIELD_SCOPE_SERIES
            });

            $.when(productRequest, metadataRequest)
                .done(function (productResponse, metadataResponse) {
                    if (!isSeriesRequestCurrent(seriesId, requestId)) {
                        return;
                    }
                    var productPayload = productResponse[0];
                    var metadataPayload = metadataResponse[0];
                    if (!productPayload.success) {
                        handleErrorResponse(productPayload);
                        return;
                    }
                    if (!metadataPayload.success) {
                        handleErrorResponse(metadataPayload);
                        return;
                    }
                    state.seriesFields = productPayload.data || [];
                    state.seriesMetadataFields = metadataPayload.data || [];
                    renderSeriesFields();
                    renderSeriesMetadataFields();
                    loadSeriesMetadataValues(seriesId, requestId);
                })
                .fail(function () {
                    if (!isSeriesRequestCurrent(seriesId, requestId)) {
                        return;
                    }
                    setStatus('Unable to load series fields.', true);
                });
        }

        function loadSeriesMetadataValues(seriesId, requestId) {
            var expectedRequestId = requestId || state.seriesDataRequestId;
            $.getJSON(apiBase, { action: 'v1.getSeriesAttributes', seriesId: seriesId })
                .done(function (response) {
                    if (!isSeriesRequestCurrent(seriesId, expectedRequestId)) {
                        return;
                    }
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    var data = response.data || {};
                    if (data.definitions) {
                        state.seriesMetadataFields = data.definitions;
                    }
                    state.seriesMetadataValues = data.values || {};
                    renderSeriesMetadataFields();
                })
                .fail(function () {
                    if (!isSeriesRequestCurrent(seriesId, expectedRequestId)) {
                        return;
                    }
                    setStatus('Unable to load series metadata.', true);
                });
        }

        function resetProductForm() {
            $('#product-id').val('');
            $('#product-sku').val('');
            $('#product-name').val('');
            $('#product-description').val('');
            renderProductFormFields({});
            $('#product-submit').text('Save Product');
            $('#product-delete-button').prop('disabled', true);
            state.selectedProductId = null;
        }

        function renderProductFormFields(values) {
            var $container = $('#product-custom-fields');
            $container.empty();
            var productFields = getProductFields();
            if (productFields.length === 0) {
                $container.append('<div>No custom fields for this series.</div>');
                return;
            }
            $.each(productFields, function (_, field) {
                var wrapper = $('<div></div>');
                var labelText = field.label + ' (' + field.fieldKey + ')';
                if (field.isRequired) {
                    labelText = labelText + ' *';
                }
                var $label = $('<label></label>').text(labelText + ': ');
                var $input = $('<input type="text">');
                $input.attr('id', 'product-field-' + field.fieldKey);
                $input.attr('data-field-key', field.fieldKey);
                if (values && values[field.fieldKey] !== undefined && values[field.fieldKey] !== null) {
                    $input.val(values[field.fieldKey]);
                }
                wrapper.append($label).append($input);
                $container.append(wrapper);
            });
        }

        function populateProductForm(product) {
            $('#product-id').val(product.id);
            $('#product-sku').val(product.sku);
            $('#product-name').val(product.name);
            $('#product-description').val(product.description || '');
            renderProductFormFields(product.customValues || {});
            $('#product-submit').text('Update Product');
            $('#product-delete-button').prop('disabled', false);
            state.selectedProductId = product.id;
        }

        function renderProductList() {
            var $table = $('#product-list-table');
            $table.empty();
            if (!state.products || state.products.length === 0) {
                $table.append('<tr><td>No products for this series.</td></tr>');
                return;
            }
            var fields = getProductFields();
            var header = $('<tr></tr>')
                .append('<th>ID</th>')
                .append('<th>SKU</th>')
                .append('<th>Name</th>');
            $.each(fields, function (_, field) {
                header.append('<th>' + field.label + '</th>');
            });
            header.append('<th>Actions</th>');
            $table.append(header);
            $.each(state.products, function (_, product) {
                var $row = $('<tr></tr>');
                $row.append('<td>' + product.id + '</td>');
                $row.append('<td>' + product.sku + '</td>');
                $row.append('<td>' + product.name + '</td>');
                var customValues = product.customValues || {};
                $.each(fields, function (_, field) {
                    var value = customValues[field.fieldKey] || '';
                    $row.append('<td>' + value + '</td>');
                });
                var $actions = $('<td></td>');
                var $edit = $('<button type="button">Edit</button>');
                $edit.attr('data-product-action', 'edit').attr('data-product-id', product.id);
                var $delete = $('<button type="button">Delete</button>');
                $delete.attr('data-product-action', 'delete').attr('data-product-id', product.id);
                $actions.append($edit).append(' ').append($delete);
                $row.append($actions);
                $table.append($row);
            });
        }

        function renderSearchFieldFilters(fields, initialValues) {
            var $container = $('#search-field-filters');
            var values = initialValues || {};
            $container.empty();
            state.search.fieldMeta = fields || [];
            state.search.filters = $.extend({}, values);
            if (!fields || fields.length === 0) {
                $container.append('<div class="search-field-placeholder">Select a series to enable custom field filters.</div>');
                return;
            }
            $.each(fields, function (_, field) {
                var value = values[field.fieldKey] || '';
                var $row = $('<div class="search-field-row"></div>');
                var labelText = field.label + ' (' + field.fieldKey + ')';
                var $label = $('<label></label>').text(labelText + ': ');
                var $input = $('<input type="text">')
                    .attr('data-field-key', field.fieldKey)
                    .val(value);
                $row.append($label).append($input);
                $container.append($row);
            });
        }

        function renderSearchSeriesMetaFilters(fields, initialValues) {
            var $container = $('#search-series-meta-filters');
            var values = initialValues || {};
            $container.empty();
            state.search.seriesMetaDefinitions = fields || [];
            state.search.seriesMetaFilters = $.extend({}, values);
            if (!fields || fields.length === 0) {
                $container.append('<div class="search-field-placeholder">Select a series to enable metadata filters.</div>');
                return;
            }
            $.each(fields, function (_, field) {
                var value = values[field.fieldKey] || '';
                var $row = $('<div class="search-field-row"></div>');
                var labelText = field.label + ' (' + field.fieldKey + ')';
                var $label = $('<label></label>').text(labelText + ': ');
                var $input = $('<input type="text">')
                    .attr('data-metadata-key', field.fieldKey)
                    .val(value);
                $row.append($label).append($input);
                $container.append($row);
            });
        }

        function loadSearchFieldMeta(seriesId, initialFieldValues, initialMetadataValues) {
            var normalizedSeriesId = seriesId ? parseInt(seriesId, 10) : null;
            state.search.currentSeriesId = normalizedSeriesId;
            var requestId = ++state.search.seriesFieldRequestId;
            if (!normalizedSeriesId) {
                renderSearchFieldFilters([], {});
                renderSearchSeriesMetaFilters([], {});
                return;
            }
            var productRequest = $.getJSON(apiBase, { action: 'v1.listSeriesFields', seriesId: seriesId });
            var metadataRequest = $.getJSON(apiBase, {
                action: 'v1.listSeriesFields',
                seriesId: seriesId,
                scope: FIELD_SCOPE_SERIES
            });
            $.when(productRequest, metadataRequest)
                .done(function (productResponse, metadataResponse) {
                    if (!isSearchRequestCurrent(seriesId, requestId)) {
                        return;
                    }
                    var productPayload = productResponse[0];
                    var metadataPayload = metadataResponse[0];
                    if (!productPayload.success) {
                        handleErrorResponse(productPayload);
                        return;
                    }
                    if (!metadataPayload.success) {
                        handleErrorResponse(metadataPayload);
                        return;
                    }
                    renderSearchFieldFilters(productPayload.data || [], initialFieldValues || {});
                    renderSearchSeriesMetaFilters(metadataPayload.data || [], initialMetadataValues || {});
                })
                .fail(function () {
                    if (!isSearchRequestCurrent(seriesId, requestId)) {
                        return;
                    }
                    setStatus('Unable to load series field filters.', true);
                });
        }

        function renderSearchResults(data) {
            var $results = $('#search-results');
            $results.empty();

            if (!data) {
                $results.append('<div class="search-field-placeholder">No results.</div>');
                return;
            }

            var hasAny = false;

            function addSection(title, items, builder) {
                if (!items || items.length === 0) {
                    return;
                }
                hasAny = true;
                var $section = $('<div class="search-results-section"></div>');
                $section.append($('<h4></h4>').text(title));
                var $list = $('<ul class="search-results-list"></ul>');
                $.each(items, function (_, item) {
                    $list.append(builder(item));
                });
                $section.append($list);
                $results.append($section);
            }

            addSection('Categories', data.categories || [], function (item) {
                var text = item.name + ' (ID ' + item.id + ')';
                if (item.parentName) {
                    text += ' — Parent: ' + item.parentName;
                }
                return $('<li></li>').text(text);
            });

            addSection('Series', data.series || [], function (item) {
                var text = item.name + ' (ID ' + item.id + ')';
                if (item.parentName) {
                    text += ' - Category: ' + item.parentName;
                }
                var $li = $('<li></li>').text(text);
                var fieldCount = item.fieldMeta ? item.fieldMeta.length : 0;
                var metaParts = [fieldCount + ' custom field(s)'];
                if (item.seriesMeta) {
                    var snippets = [];
                    $.each(item.seriesMeta, function (key, value) {
                        if (value) {
                            snippets.push(key + '=' + value);
                        }
                    });
                    if (snippets.length > 0) {
                        metaParts.push(snippets.join(', '));
                    }
                }
                var $meta = $('<span class="search-results-meta"></span>').text(metaParts.join(' | '));
                var $button = $('<button type="button" class="search-use-series">Use Filters</button>')
                    .attr('data-series-id', item.id);
                $li.append($meta).append($button);
                return $li;
            });

            addSection('Products', data.products || [], function (item) {
                var header = item.sku + ' - ' + item.name;
                var $li = $('<li></li>').text(header);
                var parts = [];
                parts.push('Series: ' + item.seriesName);
                $.each(item.customValues || {}, function (key, value) {
                    parts.push(key + '=' + value);
                });
                var $meta = $('<span class="search-results-meta"></span>').text(parts.join(' | '));
                $li.append($meta);
                return $li;
            });

            if (!hasAny) {
                $results.append('<div class="search-field-placeholder">No results found.</div>');
            }

            if (data.seriesMeta) {
                var metaItems = [];
                $.each(data.seriesMeta, function (key, value) {
                    metaItems.push(key + ': ' + (value || ''));
                });
                if (metaItems.length > 0) {
                    var $metaSection = $('<div class="search-results-section"></div>');
                    $metaSection.append('<h4>Selected Series Metadata</h4>');
                    var $list = $('<ul class="search-results-list"></ul>');
                    $.each(metaItems, function (_, item) {
                        $list.append($('<li></li>').text(item));
                    });
                    $metaSection.append($list);
                    $results.append($metaSection);
                }
            }
        }

        function loadCsvHistory() {
            $.getJSON(apiBase, { action: 'v1.listCsvHistory' })
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    var files = (response.data && response.data.files) ? response.data.files : [];
                    renderCsvHistory(files);
                })
                .fail(function () {
                    setStatus('Unable to load CSV history.', true);
                });
        }

        function renderCsvHistory(files) {
            var $tbody = $('#csv-history-table tbody');
            $tbody.empty();
            if (!files || files.length === 0) {
                $tbody.append('<tr><td colspan="5">No CSV files stored.</td></tr>');
                return;
            }
            $.each(files, function (_, file) {
                var $row = $('<tr></tr>');
                var typeLabel = (file.type || '').toString().toUpperCase();
                var nameLabel = file.name || file.id;
                var sizeValue = Number(file.size || 0);
                var sizeLabel = !isNaN(sizeValue) ? sizeValue.toLocaleString() : '0';
                var timestampLabel = '';
                if (file.timestamp) {
                    var parsed = new Date(file.timestamp);
                    if (!isNaN(parsed.getTime())) {
                        timestampLabel = parsed.toLocaleString();
                    }
                }

                $row.append($('<td></td>').text(typeLabel));
                $row.append($('<td></td>').text(nameLabel));
                $row.append($('<td></td>').text(timestampLabel));
                $row.append($('<td></td>').text(sizeLabel));

                var $actions = $('<td></td>');
                var $download = $('<button type="button">Download</button>').attr('data-csv-download', file.id);
                var $restore = $('<button type="button">Restore</button>').attr('data-csv-restore', file.id);
                var $delete = $('<button type="button">Delete</button>').attr('data-csv-delete', file.id);
                $actions.append($download).append(' ').append($restore).append(' ').append($delete);
                $row.append($actions);

                $tbody.append($row);
            });
        }

        function triggerCsvDownload(fileId) {
            if (!fileId) {
                return;
            }
            window.location = apiBase + '?action=' + encodeURIComponent('v1.downloadCsv') + '&id=' + encodeURIComponent(fileId);
        }

        function loadProducts(seriesId) {
            var requestId = state.seriesDataRequestId;
            $.getJSON(apiBase, { action: 'v1.listProducts', seriesId: seriesId })
                .done(function (response) {
                    if (!isSeriesRequestCurrent(seriesId, requestId)) {
                        return;
                    }
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    state.products = response.data || [];
                    renderProductList();
                })
                .fail(function () {
                    if (!isSeriesRequestCurrent(seriesId, requestId)) {
                        return;
                    }
                    setStatus('Unable to load products.', true);
                });
        }

        $('#hierarchy-container').on('click', 'a[data-node-id]', function (event) {
            event.preventDefault();
            var nodeId = parseInt($(this).attr('data-node-id'), 10);
            selectNode(nodeId);
        });

        $('#node-create-form').on('submit', function (event) {
            event.preventDefault();
            var parentInput = $('#create-parent-id').val();
            var parentId = parentInput === '' ? null : parseInt(parentInput, 10);
            if (parentInput !== '' && isNaN(parentId)) {
                setStatus('Parent ID must be numeric or empty.', true);
                return;
            }
            var payload = {
                parentId: parentId,
                name: $('#create-node-name').val(),
                type: $('#create-node-type').val(),
                displayOrder: parseInt($('#create-display-order').val(), 10) || 0
            };
            postAction('v1.saveNode', payload)
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('Node saved successfully.', false);
                    $('#create-node-name').val('');
                    $('#create-display-order').val('0');
                    if (payload.type === 'category') {
                        $('#create-node-type').val('category');
                    }
                    loadHierarchy();
                })
                .fail(function () {
                    setStatus('Failed to save node.', true);
                });
        });

        $('#node-update-form').on('submit', function (event) {
            event.preventDefault();
            var nodeId = $('#update-node-id').val();
            if (!nodeId) {
                setStatus('No node selected.', true);
                return;
            }
            var node = state.nodeIndex[parseInt(nodeId, 10)];
            if (!node) {
                setStatus('Selected node no longer exists.', true);
                return;
            }
            var payload = {
                id: node.id,
                parentId: node.parentId,
                name: $('#update-node-name').val(),
                type: $('#update-node-type-value').val(),
                displayOrder: parseInt($('#update-node-display-order').val(), 10) || 0
            };
            postAction('v1.saveNode', payload)
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('Node updated successfully.', false);
                    loadHierarchy();
                })
                .fail(function () {
                    setStatus('Failed to update node.', true);
                });
        });

        $('#node-delete-button').on('click', function () {
            if (!state.selectedNodeId) {
                setStatus('Select a node to delete.', true);
                return;
            }
            if (!window.confirm('Delete the selected node?')) {
                return;
            }
            postAction('v1.deleteNode', { id: state.selectedNodeId })
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('Node deleted.', false);
                    state.selectedNodeId = null;
                    loadHierarchy();
                })
                .fail(function () {
                    setStatus('Failed to delete node.', true);
                });
        });

        $('#search-series-select').on('change', function () {
            var seriesId = $(this).val();
            if (seriesId) {
                loadSearchFieldMeta(
                    parseInt(seriesId, 10),
                    state.search.filters || {},
                    state.search.seriesMetaFilters || {}
                );
            } else {
                renderSearchFieldFilters([], {});
                renderSearchSeriesMetaFilters([], {});
            }
        });

        $('#search-reset-button').on('click', function () {
            $('#search-query').val('');
            $('.search-scope').prop('checked', true);
            $('#search-series-select').val('');
            state.search.filters = {};
            state.search.fieldMeta = [];
            renderSearchFieldFilters([], {});
            state.search.seriesMetaFilters = {};
            state.search.seriesMetaDefinitions = [];
            renderSearchSeriesMetaFilters([], {});
            $('#search-results').empty();
            setStatus('', false);
        });

        $('#search-form').on('submit', function (event) {
            event.preventDefault();
            var query = $('#search-query').val();
            var scope = [];
            $('.search-scope:checked').each(function () {
                scope.push($(this).val());
            });
            if (scope.length === 0) {
                setStatus('Select at least one scope to search.', true);
                return;
            }
            var seriesValue = $('#search-series-select').val();
            var seriesId = seriesValue ? parseInt(seriesValue, 10) : null;
            var fieldFilters = {};
            $('#search-field-filters input[data-field-key]').each(function () {
                var key = $(this).attr('data-field-key');
                var value = $.trim($(this).val());
                if (value !== '') {
                    fieldFilters[key] = value;
                }
            });
            var seriesMetaFilters = {};
            $('#search-series-meta-filters input[data-metadata-key]').each(function () {
                var key = $(this).attr('data-metadata-key');
                var value = $.trim($(this).val());
                if (value !== '') {
                    seriesMetaFilters[key] = value;
                }
            });
            if ($.trim(query) === '' && !seriesId && Object.keys(fieldFilters).length === 0 && Object.keys(seriesMetaFilters).length === 0) {
                setStatus('Enter a keyword or select a series/filter to search.', true);
                return;
            }
            var payload = {
                query: query,
                scope: scope
            };
            if (seriesId) {
                payload.seriesId = seriesId;
            }
            if (Object.keys(fieldFilters).length > 0) {
                payload.fieldFilters = fieldFilters;
            }
            if (Object.keys(seriesMetaFilters).length > 0) {
                payload.seriesMetaFilters = seriesMetaFilters;
            }
            state.search.seriesMetaFilters = seriesMetaFilters;
            postAction('v1.searchCatalog', payload)
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    var data = response.data || {};
                    if (seriesId) {
                        renderSearchFieldFilters(data.fieldMeta || [], fieldFilters);
                        renderSearchSeriesMetaFilters(data.seriesFieldMeta || [], seriesMetaFilters);
                    } else if (data.fieldMeta && data.fieldMeta.length > 0) {
                        renderSearchFieldFilters(data.fieldMeta, fieldFilters);
                    } else if (!seriesId) {
                        state.search.filters = fieldFilters;
                    }
                    if (!seriesId) {
                        renderSearchSeriesMetaFilters([], {});
                    }
                    renderSearchResults(data);
                    var count = (data.categories ? data.categories.length : 0) +
                        (data.series ? data.series.length : 0) +
                        (data.products ? data.products.length : 0);
                    setStatus('Search complete: ' + count + ' result(s).', false);
                })
                .fail(function () {
                    setStatus('Search request failed.', true);
                });
        });

        $('#search-results').on('click', '.search-use-series', function () {
            var seriesId = $(this).attr('data-series-id');
            if (!seriesId) {
                return;
            }
            $('#search-series-select').val(String(seriesId));
            loadSearchFieldMeta(
                parseInt(seriesId, 10),
                state.search.filters || {},
                state.search.seriesMetaFilters || {}
            );
        });

        $('#series-field-form').on('submit', function (event) {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            var payload = {
                id: $('#series-field-id').val() ? parseInt($('#series-field-id').val(), 10) : null,
                seriesId: state.selectedNodeId,
                fieldKey: $('#series-field-key').val(),
                label: $('#series-field-label').val(),
                fieldType: 'text',
                fieldScope: $('#series-field-scope').val() || FIELD_SCOPE_PRODUCT,
                sortOrder: parseInt($('#series-field-sort-order').val(), 10) || 0,
                isRequired: $('#series-field-required').is(':checked')
            };
            postAction('v1.saveSeriesField', payload)
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('Series field saved.', false);
                    resetSeriesFieldForm();
                    loadSeriesFields(state.selectedNodeId);
                })
                .fail(function () {
                    setStatus('Failed to save series field.', true);
                });
        });

        $('#series-field-clear-button').on('click', function () {
            resetSeriesFieldForm();
        });

        $('#series-metadata-field-form').on('submit', function (event) {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            var payload = {
                id: $('#series-metadata-field-id').val() ? parseInt($('#series-metadata-field-id').val(), 10) : null,
                seriesId: state.selectedNodeId,
                fieldKey: $('#series-metadata-field-key').val(),
                label: $('#series-metadata-field-label').val(),
                fieldType: 'text',
                fieldScope: FIELD_SCOPE_SERIES,
                sortOrder: parseInt($('#series-metadata-field-sort-order').val(), 10) || 0,
                isRequired: $('#series-metadata-field-required').is(':checked')
            };
            postAction('v1.saveSeriesField', payload)
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('Series metadata field saved.', false);
                    resetSeriesMetadataFieldForm();
                    loadSeriesFields(state.selectedNodeId);
                })
                .fail(function () {
                    setStatus('Failed to save metadata field.', true);
                });
        });

        $('#series-metadata-field-clear-button').on('click', function () {
            resetSeriesMetadataFieldForm();
        });

        $('#series-metadata-form').on('submit', function (event) {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            var values = {};
            $('#series-metadata-values input[data-metadata-key]').each(function () {
                var key = $(this).attr('data-metadata-key');
                values[key] = $(this).val();
            });
            postAction('v1.saveSeriesAttributes', {
                seriesId: state.selectedNodeId,
                values: values
            })
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    var data = response.data || {};
                    state.seriesMetadataValues = data.values || values;
                    setStatus('Series metadata saved.', false);
                    renderSeriesMetadataValues();
                })
                .fail(function () {
                    setStatus('Failed to save series metadata.', true);
                });
        });

        $('#series-metadata-reset-button').on('click', function () {
            renderSeriesMetadataValues();
        });

        $('#series-management').on('click', '.series-field-action', function () {
            var action = $(this).attr('data-field-action');
            var fieldId = parseInt($(this).attr('data-field-id'), 10);
            var scope = $(this).attr('data-field-scope') || FIELD_SCOPE_PRODUCT;
            var field = findSeriesField(fieldId, scope);
            if (!field) {
                setStatus('Field not found.', true);
                return;
            }
            if (action === 'edit') {
                if (scope === FIELD_SCOPE_SERIES) {
                    populateSeriesMetadataFieldForm(field);
                } else {
                    populateSeriesFieldForm(field);
                }
            } else if (action === 'delete') {
                if (!window.confirm('Delete this field?')) {
                    return;
                }
                postAction('v1.deleteSeriesField', { id: fieldId })
                    .done(function (response) {
                        if (!response.success) {
                            handleErrorResponse(response);
                            return;
                        }
                        setStatus('Series field deleted.', false);
                        if (scope === FIELD_SCOPE_SERIES) {
                            resetSeriesMetadataFieldForm();
                        } else {
                            resetSeriesFieldForm();
                        }
                        loadSeriesFields(state.selectedNodeId);
                        if (scope === FIELD_SCOPE_PRODUCT) {
                            loadProducts(state.selectedNodeId);
                        } else {
                            loadSeriesMetadataValues(state.selectedNodeId);
                        }
                    })
                    .fail(function () {
                        setStatus('Failed to delete series field.', true);
                    });
            }
        });

        $('#product-form').on('submit', function (event) {
            event.preventDefault();
            if (!state.selectedNodeId) {
                setStatus('Select a series first.', true);
                return;
            }
            var payload = {
                id: $('#product-id').val() ? parseInt($('#product-id').val(), 10) : null,
                seriesId: state.selectedNodeId,
                sku: $('#product-sku').val(),
                name: $('#product-name').val(),
                description: $('#product-description').val(),
                customValues: {}
            };
            $('#product-custom-fields input[data-field-key]').each(function () {
                var key = $(this).attr('data-field-key');
                payload.customValues[key] = $(this).val();
            });

            postAction('v1.saveProduct', payload)
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('Product saved.', false);
                    resetProductForm();
                    loadProducts(state.selectedNodeId);
                })
                .fail(function () {
                    setStatus('Failed to save product.', true);
                });
        });

        $('#product-clear-button').on('click', function () {
            resetProductForm();
        });

        $('#product-delete-button').on('click', function () {
            if (!state.selectedProductId) {
                setStatus('Select a product to delete.', true);
                return;
            }
            if (!window.confirm('Delete the selected product?')) {
                return;
            }
            postAction('v1.deleteProduct', { id: state.selectedProductId })
                .done(function (response) {
                    if (!response.success) {
                        handleErrorResponse(response);
                        return;
                    }
                    setStatus('Product deleted.', false);
                    resetProductForm();
                    loadProducts(state.selectedNodeId);
                })
                .fail(function () {
                    setStatus('Failed to delete product.', true);
                });
        });

        $('#product-list-table').on('click', 'button[data-product-action]', function () {
            var action = $(this).attr('data-product-action');
            var productId = parseInt($(this).attr('data-product-id'), 10);
            var product = null;
            $.each(state.products, function (_, item) {
                if (item.id === productId) {
                    product = item;
                }
            });
            if (!product) {
                setStatus('Product not found.', true);
                return;
            }
            if (action === 'edit') {
                populateProductForm(product);
            } else if (action === 'delete') {
                if (!window.confirm('Delete this product?')) {
                    return;
                }
                postAction('v1.deleteProduct', { id: productId })
                    .done(function (response) {
                        if (!response.success) {
                            handleErrorResponse(response);
                            return;
                        }
                        setStatus('Product deleted.', false);
                        resetProductForm();
                        loadProducts(state.selectedNodeId);
                    })
                    .fail(function () {
                        setStatus('Failed to delete product.', true);
                    });
            }
        });

        $(function () {
            $('#node-update-submit').prop('disabled', true);
            $('#node-delete-button').prop('disabled', true);
            $('#product-delete-button').prop('disabled', true);

            $('#csv-export-button').on('click', function () {
                var $button = $(this);
                $button.prop('disabled', true);
                postAction('v1.exportCsv', {})
                    .done(function (response) {
                        if (!response.success) {
                            handleErrorResponse(response);
                            return;
                        }
                        var file = response.data || {};
                        setStatus('Catalog CSV exported.', false);
                        loadCsvHistory();
                        if (file.id) {
                            triggerCsvDownload(file.id);
                        }
                    })
                    .fail(function () {
                        setStatus('Failed to export catalog CSV.', true);
                    })
                    .always(function () {
                        $button.prop('disabled', false);
                    });
            });

            $('#csv-import-form').on('submit', function (event) {
                event.preventDefault();
                var $fileInput = $('#csv-import-file');
                if (!$fileInput[0].files || $fileInput[0].files.length === 0) {
                    setStatus('Select a CSV file to import.', true);
                    return;
                }
                var formData = new FormData();
                formData.append('file', $fileInput[0].files[0]);
                var $submit = $('#csv-import-submit');
                $submit.prop('disabled', true);
                postMultipart('v1.importCsv', formData)
                    .done(function (response) {
                        if (!response.success) {
                            handleErrorResponse(response);
                            return;
                        }
                        var data = response.data || {};
                        var message = 'CSV import completed.';
                        if (typeof data.importedProducts !== 'undefined') {
                            message = 'CSV import completed (' +
                                data.importedProducts + ' products, ' +
                                (data.createdSeries || 0) + ' new series, ' +
                                (data.createdCategories || 0) + ' new categories).';
                        }
                        setStatus(message, false);
                        $fileInput.val('');
                        loadHierarchy();
                        loadCsvHistory();
                    })
                    .fail(function () {
                        setStatus('Failed to import CSV.', true);
                    })
                    .always(function () {
                        $submit.prop('disabled', false);
                    });
            });

            $('#csv-history-table').on('click', 'button[data-csv-download]', function () {
                var fileId = $(this).attr('data-csv-download');
                triggerCsvDownload(fileId);
            });

            $('#csv-history-table').on('click', 'button[data-csv-restore]', function () {
                var fileId = $(this).attr('data-csv-restore');
                if (!fileId) {
                    return;
                }
                var $button = $(this);
                $button.prop('disabled', true);
                postAction('v1.restoreCsv', { id: fileId })
                    .done(function (response) {
                        if (!response.success) {
                            handleErrorResponse(response);
                            return;
                        }
                        var data = response.data || {};
                        var message = 'CSV restore completed.';
                        if (typeof data.importedProducts !== 'undefined') {
                            message = 'CSV restore completed (' +
                                data.importedProducts + ' products, ' +
                                (data.createdSeries || 0) + ' new series, ' +
                                (data.createdCategories || 0) + ' new categories).';
                        }
                        setStatus(message, false);
                        loadHierarchy();
                        loadCsvHistory();
                    })
                    .fail(function () {
                        setStatus('Failed to restore CSV file.', true);
                    })
                    .always(function () {
                        $button.prop('disabled', false);
                    });
            });

            $('#csv-history-table').on('click', 'button[data-csv-delete]', function () {
                var fileId = $(this).attr('data-csv-delete');
                if (!fileId) {
                    return;
                }
                if (!window.confirm('Delete this CSV file?')) {
                    return;
                }
                postAction('v1.deleteCsv', { id: fileId })
                    .done(function (response) {
                        if (!response.success) {
                            handleErrorResponse(response);
                            return;
                        }
                        setStatus('CSV file deleted.', false);
                        loadCsvHistory();
                    })
                    .fail(function () {
                        setStatus('Failed to delete CSV file.', true);
                    });
            });

            loadHierarchy();
            loadCsvHistory();
        });
    })(jQuery);




