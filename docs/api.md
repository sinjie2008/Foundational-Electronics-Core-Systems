Catalog & Spec Search API
=========================

Conventions
-----------
- Base path: `/api` under `public/`.
- Format: JSON for standard requests; `multipart/form-data` supported when uploading files (include `metadata` JSON part for text/number values alongside `files[fieldKey]` parts).
- Versioning: path-based (`/api/v1/...`) when introduced; current endpoints are unversioned but should be aliased to `/api/v1`.
- Routing note: current implementation uses `catalog.php?action=v1.someAction`; RESTful paths above describe the intended aliases and shapes.
- Errors: structured as `{ "error": { "code": "string", "message": "string", "correlationId": "uuid" } }`.
- Success envelope: `{ "data": <payload>, "correlationId": "uuid" }` for consistency with front-end DataTables consumption.
- Auth: none yet (assumes trusted environment); add token/header later if required.
- Correlation ID: inbound `X-Correlation-ID` is honored; if absent, the server generates one and returns it in the envelope. Logs always include the correlation ID.
- Logging: each request logs at start/end with route/status; errors log `errorCode`, `message`, sanitized `context`, and correlation ID. No PII/secrets in logs. `logging.enabled=false` disables log writes but responses still include correlation IDs.
- UI note: Spec Search consumes these endpoints without the global overlay/progress bar; request/response contracts stay unchanged.

Error Codes (current)
---------------------
- `validation_error` (400): request payload/params invalid.
- `invalid_token` (400): truncate/import token mismatch.
- `forbidden` (403): reserved for future auth.
- `not_found` (404): resource missing (future use).
- `internal_error` (500): unhandled exception or infrastructure issue.

Resources
---------

### Catalog
- CSV endpoints are consumed by the dedicated CSV tools page (`catalog-csv.html`) separate from the primary catalog UI.
- `GET /api/catalog/hierarchy`
  - Purpose: return category tree with products for UI tree/table hybrid.
  - Query params: none.
  - Response `200`: `data: { categories: CategoryNode[] }` where each node has `id`, `name`, `type`, `displayOrder`, `products[]`, `children[]`.

- `POST /api/catalog/nodes`
  - Purpose: create or update a category/series node in the hierarchy.
  - Body: `{ "id"?: int, "name": "string", "type": "category|series", "parentId": int|null, "displayOrder"?: int }`.
  - Notes: `parentId` is required for `series` on both create and update; parent must exist and be a `category`; parent cannot equal the node id; converting a populated series to a category returns conflict. UI resubmits the stored parent on update (no parent switching yet).
  - Response `200`: `data: { id, name, type, parentId, displayOrder }`.
  - Errors: `400 validation_error` for missing name/type/parentId or self-parent, `404` when node/parent not found, `409` when converting a series with products.

- `GET /api/catalog/search`
  - Purpose: keyword search across products/categories.
  - Query params: `q` (string, required).
  - Response `200`: `data: { items: ProductSummary[], total: int }`.

- `GET /api/catalog/series/{seriesId}/fields`
  - Purpose: list custom fields for a series by scope.
  - Query params: `scope` (`product_attribute` | `series_metadata`, optional, default `product_attribute`).
  - Response `200`: `data: SeriesCustomFieldDefinition[]`.

- `POST /api/catalog/series/{seriesId}/fields`
  - Purpose: create/update a series custom field definition.
  - Body (JSON): `{ "id"?: int, "fieldKey": "string", "label": "string", "fieldType": "text|number|file", "fieldScope": "product_attribute|series_metadata", "defaultValue"?: string, "sortOrder": int, "isRequired": bool, "publicPortalHidden"?: bool, "backendPortalHidden"?: bool }`.
  - Response `200`: `data: SeriesCustomFieldDefinition`.

- `DELETE /api/catalog/fields/{fieldId}`
  - Purpose: delete a series custom field definition (product attributes or series metadata).
  - Response `200`: `{ "data": { "deleted": true } }`.

- `GET /api/catalog/series/{seriesId}/metadata`
  - Purpose: fetch series metadata definitions and values.
  - Response `200`: `data: { seriesId, definitions: SeriesCustomFieldDefinition[], values: Record<fieldKey, string|MediaValue> }`.

- `POST /api/catalog/series/{seriesId}/metadata`
  - Purpose: save metadata values for a series (supports text/number/file types).
  - Body (JSON) for text/number-only: `{ "values": Record<string, string|null> }`.
  - Body (multipart) when files present: `metadata` (JSON as above) + `files[fieldKey]` (file upload). Only one file per field; sending a new file replaces the old one; omitting the field keeps the current value; sending empty/null clears it.
  - Response `200`: same shape as GET (definitions + values).

- `GET /api/catalog/series/{seriesId}/products`
  - Purpose: list products for a series including custom field values.
  - Response `200`: `data: ProductWithCustom[]`.
  - Notes: values for deleted field definitions are omitted; when the client changes the product-attribute field set (add/delete/rename), it must clear any existing product table rows before reinitializing DataTables so the header/column set matches the new schema and avoids “incorrect column count” warnings.

- `POST /api/catalog/series/{seriesId}/products`
  - Purpose: create or update a product and its custom field values.
  - Body (JSON) for text/number-only: `{ "id"?: int, "sku": "string", "name": "string", "description"?: "string", "customValues": Record<string, string|null> }`.
  - Body (multipart) when files present: `metadata` (JSON as above) + `files[fieldKey]` for file-type custom fields.
  - Response `200`: `data: ProductWithCustom`.

- `DELETE /api/catalog/products/{productId}`
  - Purpose: delete a product (and its custom field values).
  - Response `200`: `{ "data": { "deleted": true } }`.

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

- `GET /api/catalog/media?id={token}`
  - Purpose: stream a stored media file linked to a custom field value.
  - Query params: `id` (token/path reference returned in field values).
  - Response: file stream with `Content-Disposition` preserving the original filename. Errors: `404` if missing, `400 validation_error` if malformed token.

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
- `ProductWithCustom`: `ProductSummary` plus `customValues: Record<fieldKey, string|MediaValue>`.
- `SeriesCustomFieldDefinition`: `{ id, fieldKey, label, fieldType, fieldScope, defaultValue, sortOrder, isRequired, publicPortalHidden, backendPortalHidden }`.
- `MediaValue`: `{ filename, url, sizeBytes, storedAt }` (returned for file-type fields).
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
- UI-only change: DataTables pagination styling updated; no endpoint shapes or status codes are affected.
- UI-only change: DataTables pagination focus ring removed to prevent blink/second-click behavior; no API impact.
- Contract tests should assert envelopes, status codes, pagination defaults, and error codes; verify file-field responses return `MediaValue` objects with signed download URLs.
- Integration tests should seed MySQL with fixture data and hit endpoints via HTTP to confirm shapes expected by DataTables; cover series field CRUD, product/metadata saves via JSON and multipart, file upload type/size enforcement, and media download streaming the original filename.
- Manual client check: catalog UI displays status banners scoped to the section being updated (Product Catalog Manager vs Series Custom Fields vs Series Metadata vs Products) without leaking messages to other sections.
