Catalog & Spec Search API
=========================

Conventions
-----------
- Base path: `/api` under `public/`.
- Format: JSON only; `Content-Type: application/json; charset=utf-8`.
- Versioning: path-based (`/api/v1/...`) when introduced; current endpoints are unversioned but should be aliased to `/api/v1`.
- Errors: structured as `{ "error": { "code": "string", "message": "string", "correlationId": "uuid" } }`.
- Success envelope: `{ "data": <payload>, "correlationId": "uuid" }` for consistency with front-end DataTables consumption.
- Auth: none yet (assumes trusted environment); add token/header later if required.

Resources
---------

### Catalog
- `GET /api/catalog/hierarchy`
  - Purpose: return category tree with products for UI tree/table hybrid.
  - Query params: none.
  - Response `200`: `data: { categories: CategoryNode[] }` where each node has `id`, `name`, `type`, `displayOrder`, `products[]`, `children[]`.

- `GET /api/catalog/search`
  - Purpose: keyword search across products/categories.
  - Query params: `q` (string, required).
  - Response `200`: `data: { items: ProductSummary[], total: int }`.

- `POST /api/catalog/truncate`
  - Purpose: truncate catalog data with audit logging.
  - Body: `{ "reason": "string", "token": "string" }` (`token` must match configured confirmation token).
  - Response `200`: `data: { auditId, deleted: {...}, timestamp }`.
  - Errors: `400 invalid_token`, `403 forbidden`, `500 internal_error`.

- `POST /api/catalog/import`
  - Purpose: import products/series data from uploaded CSV (path or file upload depending on hosting).
  - Body: multipart form-data with `file` field (CSV).
  - Response `202`: `data: { imported: int, fileId: string }`.
  - Errors: `400 validation_error`, `500 internal_error`.

- `GET /api/catalog/pdf`
  - Purpose: build LaTeX template and return PDF path.
  - Query params: `id` (template id).
  - Response `200`: `data: { pdfPath, downloadUrl, updatedAt, stdout, stderr, exitCode, log, correlationId }`.

- `GET /api/catalog/csv/history`
  - Purpose: list CSV exports/imports and truncate audits.
  - Response `200`: `data: { files: CsvFile[], audits: AuditEntry[], truncateInProgress: bool }`.

- `POST /api/catalog/csv/export`
  - Purpose: export catalog to CSV.
  - Response `200`: `data: { fileId, path }`.

- `POST /api/catalog/csv/restore`
  - Body: `{ "id": "string" }`.
  - Response `200`: `data: { restored: true, id }`.

- `GET /api/catalog/csv/download?id={fileId}`
  - Purpose: download a previously exported/imported CSV.
  - Response: file stream.

### Spec Search
- `GET /api/spec-search/root-categories`
  - Response `200`: `data: { categories: CategorySummary[] }`.

- `GET /api/spec-search/product-categories?root_id={id}`
  - Response `200`: `data: { groups: { group: string, categories: CategorySummary[] }[] }`.

- `POST /api/spec-search/products`
  - Body: `{ "category_ids": int[], "filters": object }`.
  - Response `200`: `data: { items: ProductSummary[], total: int }` where each item includes `sku`, `series` (series name), `category` (parent category name), `seriesId`, `categoryId`, and any custom fields for that product.

- `POST /api/spec-search/facets`
  - Body: `{ "category_ids": int[] }`.
  - Response `200`: `data: { facets: Facet[] }`.

Data Shapes
-----------
- `CategoryNode`: `{ id, parentId, name, type, displayOrder, productCount, categoryCount, products: ProductSummary[], children: CategoryNode[] }`.
- `CategorySummary`: `{ id, name, type }`.
- `ProductSummary`: `{ id, sku, name, description, status, categoryId, series, category, seriesId }`.
- `Facet`: `{ name, label, type, options: { value, count }[] }`.

Status Codes
------------
- `200 OK`: successful read.
- `201 Created`: resource created (future endpoints).
- `202 Accepted`: async/long-running import started.
- `400 Bad Request`: validation errors.
- `401 Unauthorized` / `403 Forbidden`: reserved for future auth.
- `404 Not Found`: resource missing.
- `409 Conflict`: conflicting update (future).
- `500 Internal Server Error`: unexpected failure.

Error Model
-----------
```json
{
  "error": {
    "code": "validation_error",
    "message": "Reason for failure",
    "correlationId": "uuid"
  }
}
```

Success Envelope Example
------------------------
```json
{
  "data": {
    "items": [
      { "id": 1, "sku": "ABC123", "name": "Widget", "status": "active" }
    ],
    "total": 1
  },
  "correlationId": "0f9b2a72-1234-4d9e-9c6e-aaaa5555bbbb"
}
```

Testing Notes
-------------
- Contract tests should assert envelopes, status codes, pagination defaults, and error codes.
- Integration tests should seed MySQL with fixture data and hit endpoints via HTTP to confirm shapes expected by DataTables.
