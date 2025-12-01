(function () {
    'use strict';

    const state = {
        seriesId: null,
        seriesData: null,
        templates: [],
        currentTemplateId: null,
        lastPdfUrl: null
    };

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const params = new URLSearchParams(window.location.search);
        state.seriesId = params.get('series_id');

        if (!state.seriesId) {
            alert('No Series ID provided!');
            return;
        }

        bindEvents();
        loadSeriesData(state.seriesId);
        loadTemplates();
    }

    function bindEvents() {
        $('#loadTemplateBtn').on('click', handleLoadTemplate);
        $('#compileBtn').on('click', handleCompile);
        $('#saveCompileBtn').on('click', handleSaveTemplate);
        $('#savePdfBtn').on('click', () => {
            if (state.lastPdfUrl) {
                alert('PDF is already saved at: ' + state.lastPdfUrl);
            } else {
                alert('Please compile first.');
            }
        });
        $('#downloadPdfBtn').on('click', handleDownloadPdf);

        // Allow clicking on variable badges to insert them into editor
        $(document).on('click', '.variable-badge', function () {
            const key = $(this).text();
            insertAtCursor(document.getElementById('latexSource'), key);
        });
    }

    async function loadSeriesData(id) {
        try {
            const response = await fetch(`api/series/details.php?id=${id}`);
            const result = await response.json();

            if (result.success) {
                state.seriesData = result.data;
                renderSeriesDetails();
            } else {
                console.error('Failed to load series data', result);
                $('#seriesName').text('Error loading data');
            }
        } catch (e) {
            console.error('Error loading series data', e);
            $('#seriesName').text('Error loading data');
        }
    }

    function renderSeriesDetails() {
        if (!state.seriesData) return;

        $('#seriesName').text(state.seriesData.name);
        $('#seriesId').text(state.seriesData.id);
        $('#seriesParentId').text(state.seriesData.parentId);
        $('#seriesType').text(state.seriesData.type);

        // Render Metadata
        const metaContainer = $('#seriesMetadataContainer');
        metaContainer.empty();
        if (state.seriesData.metadata && state.seriesData.metadata.length > 0) {
            state.seriesData.metadata.forEach(item => {
                metaContainer.append(`<span class="badge bg-secondary variable-badge" title="Click to insert">${item.key}</span>`);
            });
        } else {
            metaContainer.html('<span class="text-muted">No metadata found.</span>');
        }

        // Render Custom Fields
        const customContainer = $('#seriesCustomFieldsContainer');
        customContainer.empty();
        if (state.seriesData.customFields && state.seriesData.customFields.length > 0) {
            state.seriesData.customFields.forEach(item => {
                customContainer.append(`<span class="badge bg-info text-dark variable-badge" title="Click to insert">${item.key}</span>`);
            });
        } else {
            customContainer.html('<span class="text-muted">No custom fields found.</span>');
        }
    }

    async function loadTemplates() {
        try {
            const response = await fetch(`api/latex/templates.php?series_id=${state.seriesId}`);
            const result = await response.json();

            if (result.success) {
                state.templates = result.data || [];
                const select = $('#templateSelect');
                select.empty();
                select.append('<option value="">Select a template...</option>');
                state.templates.forEach(t => {
                    const typeLabel = t.isGlobal ? '(Global)' : '(Series)';
                    select.append(`<option value="${t.id}">${t.title} ${typeLabel}</option>`);
                });
            }
        } catch (e) {
            console.error('Error loading templates', e);
        }
    }

    function handleLoadTemplate() {
        const id = $('#templateSelect').val();
        if (!id) return;

        const template = state.templates.find(t => t.id == id);
        if (template) {
            $('#latexSource').val(template.latex);
            state.currentTemplateId = template.id;
        }
    }

    async function handleCompile() {
        const latex = $('#latexSource').val();
        const previewEl = document.getElementById('latex-preview-render');

        if (!latex) {
            alert('Please enter LaTeX code.');
            return;
        }

        previewEl.innerHTML = '<p class="text-center mt-5">Compiling...</p>';

        try {
            const response = await fetch('api/latex/compile.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    series_id: state.seriesId,
                    latex: latex
                })
            });
            const result = await response.json();

            if (result.success) {
                const pdfUrl = result.data.url;
                state.lastPdfUrl = pdfUrl;
                // Use iframe to show PDF
                previewEl.innerHTML = `<iframe src="${pdfUrl}" width="100%" height="100%" style="border:none; min-height:500px;"></iframe>`;
            } else {
                previewEl.innerHTML = `<div class="alert alert-danger">Compilation Failed: ${result.message}</div>`;
            }
        } catch (e) {
            previewEl.innerHTML = `<div class="alert alert-danger">Error: ${e.message}</div>`;
        }
    }

    async function handleSaveTemplate() {
        const latex = $('#latexSource').val();
        if (!latex) {
            alert('No LaTeX code to save.');
            return;
        }

        const title = prompt("Enter template title:", "Series Template");
        if (!title) return;

        const payload = {
            title: title,
            latex: latex,
            description: "Saved from editor",
            seriesId: state.seriesId
        };

        try {
            const response = await fetch('api/latex/templates.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const result = await response.json();
            if (result.success) {
                alert('Template saved!');
                loadTemplates();
            } else {
                alert('Failed to save: ' + result.message);
            }
        } catch (e) {
            alert('Error saving template: ' + e.message);
        }
    }

    function handleDownloadPdf() {
        if (state.lastPdfUrl) {
            const link = document.createElement('a');
            link.href = state.lastPdfUrl;
            link.download = state.lastPdfUrl.split('/').pop();
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } else {
            alert('Please compile first to generate a PDF.');
        }
    }

    function insertAtCursor(myField, myValue) {
        //IE support
        if (document.selection) {
            myField.focus();
            sel = document.selection.createRange();
            sel.text = myValue;
        }
        //MOZILLA and others
        else if (myField.selectionStart || myField.selectionStart == '0') {
            var startPos = myField.selectionStart;
            var endPos = myField.selectionEnd;
            myField.value = myField.value.substring(0, startPos)
                + myValue
                + myField.value.substring(endPos, myField.value.length);
            myField.selectionStart = startPos + myValue.length;
            myField.selectionEnd = startPos + myValue.length;
        } else {
            myField.value += myValue;
        }
    }

})();
