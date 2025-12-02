# API Reference

## Conventions and Envelope
- Base path: `/api` (server root is `public/api`). JSON only; UTF-8.
- Success shape: `{ "success": true, "data": <payload>, "correlationId": "<cid>" }`
- Error shape: `{ "error": { "code": "string", "message": "string", "correlationId": "<cid>" } }`
- Correlation ID: clients may send `X-Correlation-ID`; responses always return one.
- Status codes: `200 OK`, `201 Created`, `400 Bad Request`, `404 Not Found`, `405 Method Not Allowed`, `500 Internal Error`.

## Catalog
- `GET /api/catalog/hierarchy.php` — return nested category → series → products tree.
- `GET /api/catalog/search.php?query=term` — flat matches across categories/series/products.
- `POST /api/catalog/csv-import.php` — upload/import catalog CSV; writes audit trail under `storage/csv`.
- `GET /api/catalog/csv-download.php?id=timestamp` — download stored CSV by identifier.
- `POST /api/catalog/csv-restore.php` — restore catalog from prior CSV.
- `GET /api/catalog/csv-history.php` — list CSV import history.
- `POST /api/catalog/truncate.php` — truncate catalog tables (guarded by token/lock in `config/app.php`).
- `GET /api/catalog/pdf.php?series_id=ID&template_id=ID` — generate LaTeX PDF for a series.

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
- `POST /api/spec-search/products.php` — body `{ "category_ids": [int], "filters": { "<key>": ["value"] } }`; returns `{ items, total }` limited to 500 rows.

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
- `GET /api/typst/templates.php[?seriesId=ID&id=ID]` — list global/series templates or fetch one.
- `POST /api/typst/templates.php` — create template (global or series).
- `PUT /api/typst/templates.php` — update template.
- `DELETE /api/typst/templates.php?id=ID` — delete template.
- `POST /api/typst/compile.php` — body `{ "typst": "code", "seriesId": ID? }`; returns `{ url, path }` on success.
- `GET /api/typst/variables.php` — list globals; `POST/PUT/DELETE` manage keys `{ key, type, value, id? }`.

## Operator UI Pages (Static)
- `spec-search.html` consumes the Spec Search endpoints above.
- `catalog_ui.html` drives catalog hierarchy/series editing with Catalog endpoints.
- `catalog-csv.html` wraps CSV import/export/truncate endpoints.
- `global_typst_template.html` calls Typst template + variable APIs for global scope.
- `series_typst_template.html` uses Typst template + variable APIs scoped to a series.
Navigation between these pages is standardized in the shared sidebar; the obsolete `global_latex_template.html` link was removed to avoid dead pages.

## Error Model and Validation
- Validation failures use `400` with `code` like `validation_error`; missing resources return `404 not_found`.
- Server exceptions return `500 internal_error`; logs recorded in `storage/logs/app.log` with correlation IDs.
- Inputs are expected to be sanitized/escaped before persistence; CSV imports and compile endpoints run server-side commands—invoke only from trusted contexts.
