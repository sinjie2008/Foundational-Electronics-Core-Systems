# API Reference

## Conventions and Envelope
- Base path: `/api` (server root is `public/api`). JSON only; UTF-8.
- Success shape: `{ "success": true, "data": <payload>, "correlationId": "<cid>" }`
- Error shape: `{ "error": { "code": "string", "message": "string", "correlationId": "<cid>" } }`
- Correlation ID: clients may send `X-Correlation-ID`; responses always return one.
- Status codes: `200 OK`, `201 Created`, `400 Bad Request`, `404 Not Found`, `405 Method Not Allowed`, `500 Internal Error`.

## Catalog
- `GET /api/catalog/hierarchy.php` - return nested category + series + products tree (series nodes include `typst_templating_enabled` for the Catalog UI toggle; legacy `latex_templating_enabled` is optional and treated as disabled when absent).
- `GET /api/catalog/search.php?query=term` - flat matches across categories/series/products.
- `POST /api/catalog/csv-import.php` - upload/import catalog CSV; writes audit trail under `storage/csv`.
- `GET /api/catalog/csv-download.php?id=timestamp` - download stored CSV by identifier.
- `POST /api/catalog/csv-restore.php` - restore catalog from prior CSV.
- `GET /api/catalog/csv-history.php` - list CSV import history.
- `POST /api/catalog/truncate.php` - truncate catalog tables (guarded by token/lock in `config/app.php`).
- `GET /api/catalog/pdf.php?series_id=ID&template_id=ID` - generate LaTeX PDF for a series.
- `PUT /catalog.php?action=v1.setSeriesTypstTemplating` - body `{ "seriesId": ID, "enabled": true|false }`; persists `category.typst_templating_enabled` for that series (migrating from legacy `latex_templating_enabled` when present) and returns `{ "seriesId": ID, "typstTemplatingEnabled": bool }` (404 if series missing or not a series).

Example:
```http
GET /api/catalog/search.php?query=resistor
200 OK
{"success":true,"data":[{"id":12,"parent_id":5,"name":"Resistors","type":"category"}],"correlationId":"..."}
```

## Spec Search
- `GET /api/spec-search/root-categories.php` — top-level product roots.
- `GET /api/spec-search/product-categories.php?root_id=ID` — grouped categories under a root.
- `POST /api/spec-search/facets.php` — body `{ "category_ids": [int] }`; returns facet definitions.
- `POST /api/spec-search/products.php` - body `{ "category_ids": [int], "filters": { "<key>": ["value"] } }`; returns `{ items, total }` limited to 500 rows. Each item includes `seriesImage` (from `series_product_image` metadata) and `pdfDownload`: when `category.typst_templating_enabled = 1` it uses the latest Typst series template PDF (`typst_templates.last_pdf_path`), otherwise it uses the `series_product_spec` metadata file. UI renders "Series Image" as the first column and "PDF Download" as the final column with a download link when available.

## Series Metadata
- `GET /api/series/details.php?series_id=ID` — series metadata + custom field definitions.

## LaTeX Templates
- `GET /api/latex/templates.php[?series_id=ID]` — list global or series templates.
- `POST /api/latex/templates.php` — create template; accepts JSON `{title, description, latex, seriesId?}`.
- `PUT /api/latex/templates.php` — update template by `id`.
- `DELETE /api/latex/templates.php?id=ID` — delete global template.
- `POST /api/latex/compile.php` — body `{ "seriesId": ID, "templateId": ID? , "latex": "..." }`; compiles and returns `{ url, path }`.
- `GET /api/latex/variables.php` — list globals; `POST/PUT/DELETE` manage keys `{key,type,value,id?}`.

## Typst Templates
- Typst endpoints will auto-create missing Typst tables on first use to avoid failures if migrations were skipped.
- `GET /api/typst/templates.php[?seriesId=ID&id=ID]` – list global/series templates or fetch one.
- `POST /api/typst/templates.php` – create template (global or series); accepts optional `lastPdfPath` to persist compiled PDF location + set `last_pdf_generated_at`.
- `PUT /api/typst/templates.php` - update template; accepts optional `lastPdfPath` to refresh stored PDF metadata after compile/save flows.
- `DELETE /api/typst/templates.php?id=ID` - delete template.
- `POST /api/typst/compile.php` - body `{ "typst": "code", "seriesId": ID? }`; returns `{ url, path }` on success (the `path` should be echoed back as `lastPdfPath` when saving templates).
- `GET /api/typst/variables.php[?seriesId=ID]` - list globals (no `seriesId`) or scoped variables for a specific category/series (`series_id = ID`, `is_global = 0`); `POST/PUT` manage keys `{ key, type, value, id?, seriesId? }` and `DELETE /api/typst/variables.php?id=ID[&seriesId=ID]` removes the matching global/scoped record.
  - For file/image variables, `POST` accepts either JSON `{ key, type: "file", value, id?, seriesId? }` (uses the provided path) or `multipart/form-data` with fields `key`, `type=file`, optional `id`, optional `seriesId`, and `file` upload; uploads are stored under `public/storage/typst-assets/` with a unique filename and the saved `value` is the relative path `typst-assets/<file>`.
- Category Fields Set on `catalog_ui.html` calls `variables.php` with `seriesId=<categoryId>` to load/save per-category fields and hides the panel entirely when the selected node is not a category so scoped fields never fall back to globals. The list/editor clear DataTable search and pagination state on every category switch so non-root category fields are visible immediately after save.
- Global Typst Variables List renders as a Bootstrap 5 DataTable with columns Field Key / Field Type / Field Data (+ Actions); the Field Key cell is a clickable badge that inserts `{{typst_safe_key}}` into the editor, the Edit button opens Variable Setup without inserting, and the Add button clears the form for a new variable. File/Image variables show a small thumbnail preview in the Field Data column, resolving stored paths under `public/` / `public/storage/` or using a data URI when only a physical path is available. Compile replaces those placeholders with the stored `globals` values (with file/image paths staged into the Typst build directory).
- Series Typst templating UI exposes metadata badges as `{{key}}`, and custom fields are grouped inside a `products` wrapper: clicking the wrapper inserts a products loop scaffold, while inner field badges paste `product.attributes.<key>` tokens alongside `product.sku` and `product.name` badges; compile replaces `{{key}}` placeholders with real values and still injects the full `data` object (including `products`).
- `GET /api/typst/series-preferences.php?seriesId=ID` - return `{ "seriesId": ID, "lastGlobalTemplateId": ID? }` representing the last global Typst template imported on the Series Typst page for that series. If no preference is stored, `lastGlobalTemplateId` is `null`.
- `PUT /api/typst/series-preferences.php` - body `{ "seriesId": ID, "lastGlobalTemplateId": ID? }`; persists the series-level preference. `lastGlobalTemplateId` must reference an existing global Typst template or be null to clear. Returns the stored payload.

## Operator UI Pages (Static)
- `spec-search.html` consumes the Spec Search endpoints above.
- `catalog_ui.html` drives catalog hierarchy/series editing with Catalog endpoints; Category Fields Set panel calls `api/typst/variables.php` with the selected category id (`seriesId`) and stays hidden when the selected node is not a category.
- Category Fields Editor layout: the Add button sits on the left, and the Save/Delete inline group is right-aligned to separate create from update actions.
- `catalog-csv.html` wraps CSV import/export/truncate endpoints.
- `global_typst_template.html` calls Typst template + variable APIs for global scope.
- `series_typst_template.html` uses Typst template + variable APIs scoped to a series.
Navigation between these pages is standardized in the shared sidebar; the obsolete `global_latex_template.html` link was removed to avoid dead pages.

## Error Model and Validation
- Validation failures use `400` with `code` like `validation_error`; missing resources return `404 not_found`.
- Server exceptions return `500 internal_error`; logs recorded in `storage/logs/app.log` with correlation IDs.
- Inputs are expected to be sanitized/escaped before persistence; CSV imports and compile endpoints run server-side commands—invoke only from trusted contexts.
