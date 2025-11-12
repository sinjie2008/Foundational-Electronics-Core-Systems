# Product Catalog API (Draft)

## Overview

- **Base URL**: `/catalog.php` (single entry point with `action` query parameter).
- **Versioning**: `v1` namespace maintained via `action` values (e.g., `v1.saveProduct`).
- **Content Types**: `application/json` for requests/responses (AJAX).
- **Required Headers**: `Content-Type: application/json` for every `POST`; `Accept: application/json` recommended.
- **Authentication**: None (per requirements).
- **Error Model**: JSON object with `success=false`, `errorCode`, `message`, `details`.
- **UI Delivery**: open `catalog_ui.html` (static asset) which calls the API; `/catalog.php` always returns JSON.

## Field Scopes

- `series_metadata`: Custom fields that describe the series itself. Values are stored once per series and surfaced via `SeriesAttributeService`.
- `product_attribute`: Custom fields that shape the dynamic schema for product data entry. Values are stored per product.
- Unless noted, endpoints default to `product_attribute` scope; pass `scope=series_metadata` or `fieldScope: "series_metadata"` when managing series-level metadata definitions.

### Health Check
- **Action**: `v1.ping`
- **Response**:
```json
{
  "success": true,
  "message": "Catalog backend ready."
}
```

## Common Response Envelope

```json
{
  "success": true,
  "data": { }
}
```

```json
{
  "success": false,
  "errorCode": "VALIDATION_ERROR",
  "message": "Field validation failed.",
  "details": {
    "sku": "SKU is required."
  }
}
```

## Endpoints

### 1. List Hierarchy
- **Action**: `v1.listHierarchy`
- **Method**: `GET`
- **Query Params**: none
- **Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "General Products",
      "type": "category",
      "children": [
        {
          "id": 2,
          "name": "EMC Components",
          "type": "category",
          "children": [
            {
              "id": 5,
              "name": "C0 SERIES",
              "type": "series"
            }
          ]
        }
      ]
    }
  ]
}
```

### 2. Create/Update Category or Series
- **Action**: `v1.saveNode`
- **Method**: `POST`
- **Body**:
```json
{
  "id": 12,              // optional for update
  "parentId": 2,
  "name": "Ferrite Chip Bead",
  "type": "category",    // or "series"
  "displayOrder": 3
}
```
- **Responses**:
  - `200 OK` with `data` containing saved node.
  - Errors:
    - `VALIDATION_ERROR`
    - `PARENT_NOT_FOUND`
    - `NODE_NOT_FOUND`

### 3. Delete Node
- **Action**: `v1.deleteNode`
- **Method**: `POST`
- **Body**:
```json
{
  "id": 10
}
```
- **Responses**:
  - `200 OK`
  - Errors:
    - `CHILDREN_EXIST`
    - `NODE_NOT_FOUND`
    - `VALIDATION_ERROR`

### 4. List Series Fields
- **Action**: `v1.listSeriesFields`
- **Method**: `GET`
- **Query Params**:
  - `seriesId` (required)
  - `scope` (optional, defaults to `product_attribute`; accepts `series_metadata`)
- **Response**:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "fieldKey": "series_voltage",
      "label": "Voltage Range",
      "fieldType": "text",
      "fieldScope": "series_metadata",
      "isRequired": true,
      "defaultValue": ""
    }
  ]
}
```

### 5. Save Series Field
- **Action**: `v1.saveSeriesField`
- **Method**: `POST`
- **Body**:
```json
{
  "id": 4,                 // optional
  "seriesId": 5,
  "fieldKey": "current_rating",
  "label": "Current Rating (A)",
  "fieldType": "text",
  "fieldScope": "product_attribute",
  "isRequired": false,
  "sortOrder": 2,
  "defaultValue": null
}
```
- **Errors**:
    - `SERIES_NOT_FOUND`
    - `FIELD_SCOPE_INVALID`
    - `FIELD_KEY_CONFLICT`
    - `VALIDATION_ERROR`

Metadata field creation/updates use the same request with `fieldScope` fixed to `series_metadata` by the UI:

```json
{
  "seriesId": 5,
  "fieldKey": "compliance_grade",
  "label": "Compliance Grade",
  "fieldType": "text",
  "fieldScope": "series_metadata",
  "isRequired": false,
  "sortOrder": 4
}
```

### 6. Delete Series Field
- **Action**: `v1.deleteSeriesField`
- **Method**: `POST`
- **Body**:
```json
{
  "id": 7,
  "fieldScope": "series_metadata"
}
```
- **Errors**:
    - `FIELD_IN_USE`
    - `FIELD_NOT_FOUND`
    - `VALIDATION_ERROR`

### 7. Get Series Attributes
- **Action**: `v1.getSeriesAttributes`
- **Method**: `GET`
- **Query Params**: `seriesId`
- **Response**:
```json
{
  "success": true,
  "data": {
    "definitions": [
      {
        "id": 12,
        "fieldKey": "series_voltage",
        "label": "Voltage Range",
        "fieldType": "text",
        "fieldScope": "series_metadata",
        "isRequired": true,
        "sortOrder": 1
      }
    ],
    "values": {
      "series_voltage": "3.3V - 5V",
      "series_notes": "Optimized for automotive"
    }
  }
}
```

### 8. Save Series Attributes
- **Action**: `v1.saveSeriesAttributes`
- **Method**: `POST`
- **Body**:
```json
{
  "seriesId": 5,
  "values": {
    "series_voltage": "3.3V - 5V",
    "series_notes": "Optimized for automotive"
  }
}
```
- **Response**:
```json
{
  "success": true,
  "data": {
    "seriesId": 5,
    "values": {
      "series_voltage": "3.3V - 5V",
      "series_notes": "Optimized for automotive"
    }
  }
}
```
- **Errors**:
    - `SERIES_NOT_FOUND`
    - `VALIDATION_ERROR`

### 9. List Products
- **Action**: `v1.listProducts`
- **Method**: `GET`
- **Query Params**: `seriesId`
- **Response**:
```json
{
  "success": true,
  "data": {
    "fields": [
      {
        "id": 1,
        "fieldKey": "impedance",
        "label": "Impedance (Ohm)",
        "fieldType": "text",
        "fieldScope": "product_attribute",
        "isRequired": true
      }
    ],
    "products": [
      {
        "id": 20,
        "sku": "C0-1N0S-E-10",
        "name": "C0-1N0S-E-10",
        "description": "",
        "customValues": {
          "impedance": "100",
          "tolerance": "10%"
        }
      }
    ]
  }
}
```

### 10. Save Product
- **Action**: `v1.saveProduct`
- **Method**: `POST`
- **Body**:
```json
{
  "id": 20,           // optional
  "seriesId": 5,
  "sku": "C0-1N0S-E-10",
  "name": "C0-1N0S-E-10",
  "description": "High impedance bead",
  "customValues": {
    "impedance": "100",
    "current_rating": "1A"
  }
}
```
- **Errors**:
    - `SERIES_NOT_FOUND`
    - `VALIDATION_ERROR`
    - `PRODUCT_NOT_FOUND`
    - `SERVER_ERROR`

### 11. Delete Product
- **Action**: `v1.deleteProduct`
- **Method**: `POST`
- **Body**:
```json
{
  "id": 20
}
```
- **Errors**:
    - `PRODUCT_NOT_FOUND`
    - `VALIDATION_ERROR`

### 12. Export Catalog CSV
- **Action**: `v1.exportCsv`
- **Method**: `POST`
- **Body**: *(optional)* `{ }`
- **Response**:
```json
{
  "success": true,
  "data": {
    "id": "20251030T153000_export.csv",
    "name": "catalog_20251030T153000.csv",
    "type": "export",
    "timestamp": "2025-10-30T15:30:00Z",
    "size": 4096
  }
}
```
- **Notes**:
  - Use the returned `id` with `v1.downloadCsv` to retrieve the file; entry is appended to history automatically.
  - Exported rows contain exactly the stakeholder sample schema: `category_path`, `product_name`, and the ordered list of product attribute headers (e.g., `acf.length`, `acf.measure_result_0_frequency`, ...). The single `product_name` column doubles as SKU + label on import.

### 13. Import Catalog CSV
- **Action**: `v1.importCsv`
- **Method**: `POST`
- **Content-Type**: `multipart/form-data`
- **Form Fields**:
  - `file` (required) — CSV file in canonical format.
- **Response**:
```json
{
  "success": true,
  "data": {
    "importedProducts": 125,
    "createdSeries": 4,
    "createdCategories": 6,
    "fileId": "20251030T153500_import_catalog.csv"
  }
}
```
- **Behaviour**:
  - Hierarchy is rebuilt/merged: categories are created from every path segment except the last, the final segment becomes/updates the series, and nodes not referenced are pruned.
  - Attribute definitions are generated for every attribute header (column index ≥ 2) if the series lacks them; headers are stored verbatim as `field_key` and scoped to `product_attribute`.
  - Products are updated/created by using the `product_name` value as both SKU and name; custom field values are synchronized column-by-column using the preserved header order.
  - Uploaded files are archived with timestamped filenames.
- **Errors**:
    - `CSV_REQUIRED`
    - `CSV_PARSE_ERROR`
    - `VALIDATION_ERROR`

### 14. List CSV History
- **Action**: `v1.listCsvHistory`
- **Method**: `GET`
- **Response**:
```json
{
  "success": true,
  "data": {
    "files": [
      {
        "id": "20251030T153000_export.csv",
        "name": "catalog_20251030T153000.csv",
        "type": "export",
        "timestamp": "2025-10-30T15:30:00Z",
        "size": 4096
      },
      {
        "id": "20251030T153500_import_catalog.csv",
        "name": "import_catalog.csv",
        "type": "import",
        "timestamp": "2025-10-30T15:35:00Z",
        "size": 32768
      }
    ],
    "audits": [
      {
        "id": "0f9a6a32-1d9d-4f79-9d92-5d0526f8f2c7",
        "reason": "Preparing for FY25 import",
        "timestamp": "2025-11-11T16:35:12Z",
        "deleted": {
          "categories": 42,
          "series": 12,
          "products": 380,
          "fieldDefinitions": 55,
          "productValues": 2150,
          "seriesValues": 84
        }
      }
    ],
    "truncateInProgress": false
  }
}
```
- **Notes**:
  - `audits` surfaces the most recent truncate entries written to `storage/csv/truncate_audit.jsonl`.
  - `truncateInProgress` signals when the advisory lock is held so clients can disable import/export during destructive operations.

### 15. Download CSV File
- **Action**: `v1.downloadCsv`
- **Method**: `GET`
- **Query Params**: `id` (required) — identifier returned from history/export APIs.
- **Response**: `text/csv` file download. Missing files return `404` with JSON error payload.

### 16. Delete CSV File
- **Action**: `v1.deleteCsv`
- **Method**: `POST`
- **Body**:
```json
{
  "id": "20251030T153000_export.csv"
}
```
- **Response**:
```json
{
  "success": true
}
```
- **Errors**:
    - `CSV_NOT_FOUND`
    - `CSV_DELETE_ERROR`

### 17. Restore CSV File
- **Action**: `v1.restoreCsv`
- **Method**: `POST`
- **Body**:
```json
{
  "id": "20251113094500_import_products.csv"
}
```
- **Response**:
```json
{
  "success": true,
  "data": {
    "importedProducts": 125,
    "createdSeries": 4,
    "createdCategories": 6,
    "fileId": "20251113094500_import_products.csv"
  }
}
```
- **Behaviour**:
  - Validates the `id`, reuses the stored CSV bytes under `storage/csv`, and runs the same pipeline as `v1.importCsv` (counts reflect this re-import).
  - Useful for "Restore" buttons in the UI so stakeholders can quickly replay a previous snapshot without uploading.
- **Errors**:
  - `CSV_NOT_FOUND`
  - `CSV_PARSE_ERROR`
  - `VALIDATION_ERROR`

### 18. Truncate Catalog
- **Action**: `v1.truncateCatalog`
- **Method**: `POST`
- **Body**:
```json
{
  "reason": "Preparing for FY25 import",
  "confirmToken": "TRUNCATE",
  "correlationId": "0f9a6a32-1d9d-4f79-9d92-5d0526f8f2c7"
}
```
- **Response**:
```json
{
  "success": true,
  "data": {
    "auditId": "0f9a6a32-1d9d-4f79-9d92-5d0526f8f2c7",
    "deleted": {
      "categories": 42,
      "series": 12,
      "products": 380,
      "fieldDefinitions": 55,
      "productValues": 2150,
      "seriesValues": 84
    },
    "timestamp": "2025-11-11T16:35:12Z"
  }
}
```
- **Behaviour**:
  - Requires `confirmToken = "TRUNCATE"`; missing/incorrect tokens return `TRUNCATE_CONFIRMATION_REQUIRED`.
  - `reason` is mandatory (<= 256 chars) and is persisted inside `storage/csv/truncate_audit.jsonl` along with the provided `correlationId`.
  - While truncate is running, CSV import/export/restore endpoints return `409 TRUNCATE_IN_PROGRESS` to prevent concurrent writes.
  - The service disables foreign key checks, truncates every catalog-related table (`product_custom_field_value`, `series_custom_field_value`, `product`, `series_custom_field`, `category`, `seed_migration`), reenables checks, and returns counts without reseeding any defaults. The next CSV import is expected to rebuild the hierarchy/series/data from scratch.
- **Errors**:
  - `TRUNCATE_CONFIRMATION_REQUIRED`
  - `TRUNCATE_IN_PROGRESS`
  - `TRUNCATE_ERROR`

### 19. Public Catalog Snapshot
- **Action**: `v1.publicCatalogSnapshot`
- **Method**: `GET`
- **Description**: Returns a read-only JSON snapshot containing every category, series, series-level metadata (definitions + values), product field labels, and product rows for downstream publication.
- **Response**:
```json
{
  "success": true,
  "data": {
    "generatedAt": "2025-11-10T16:05:22Z",
    "hierarchy": [
      {
        "id": 1,
        "name": "General Products",
        "type": "category",
        "displayOrder": 1,
        "parentId": null,
        "children": [
          {
            "id": 5,
            "name": "C0 SERIES",
            "type": "series",
            "displayOrder": 1,
            "parentId": 2,
            "metadata": {
              "definitions": [
                { "fieldKey": "series_voltage", "label": "Voltage Range", "isRequired": false }
              ],
              "values": {
                "series_voltage": "3.3V - 5V"
              }
            },
            "productFields": [
              { "fieldKey": "voltage_rating", "label": "Voltage Rating", "isRequired": false }
            ],
            "products": [
              {
                "id": 2001,
                "sku": "C0-1N0S-E-10",
                "name": "Chip Array Ferrite Bead",
                "description": "EMC suppression",
                "customValues": {
                  "voltage_rating": "35V"
                }
              }
            ],
            "children": []
          }
        ]
      }
    ]
  }
}
```
- **Notes**:
    - Snapshot always reflects the latest database state at request time and does not support filtering.
    - Categories without series still appear with empty `children` arrays.
    - Product `customValues` dictionaries are keyed by field key; missing values are omitted.

## Status Codes

- `200 OK`: Successful operations.
- `400 Bad Request`: Validation or client errors.
- `404 Not Found`: Node, series, or product missing.
- `409 Conflict`: Duplicates, child dependencies.
- `500 Internal Server Error`: Unexpected failures.

## Error Codes

| Code                | Description                           |
|---------------------|---------------------------------------|
| VALIDATION_ERROR    | One or more inputs invalid            |
| NODE_NOT_FOUND      | Category/series node not found        |
| PARENT_NOT_FOUND    | Parent node missing                   |
| CHILDREN_EXIST      | Node has children, deletion blocked   |
| SERIES_NOT_FOUND    | Series missing                        |
| FIELD_KEY_CONFLICT  | Duplicate field key within series     |
| FIELD_SCOPE_INVALID | Field scope must be series/product    |
| FIELD_IN_USE        | Field has values; confirm before delete |
| PRODUCT_NOT_FOUND   | Product missing                       |
| DB_ERROR            | Generic database failure              |
| ACTION_NOT_FOUND    | Unsupported action parameter          |
| METHOD_NOT_ALLOWED  | Incorrect HTTP method                 |
| INVALID_JSON        | Request body could not be parsed      |
| SERVER_ERROR        | Unexpected server failure             |
| ENCODING_ERROR      | Response payload encoding failed      |

## Testing Guidance

- Use `Invoke-WebRequest` or `Invoke-RestMethod` in PowerShell.
- Provide sample PowerShell command:
```powershell
Invoke-RestMethod -Uri "http://localhost/catalog.php?action=v1.listHierarchy" -Method Get
Invoke-RestMethod -Uri "http://localhost/catalog.php?action=v1.saveSeriesAttributes" -Method Post -ContentType 'application/json' -Body (@{ seriesId = 5; values = @{ series_voltage = '3.3V - 5V'; series_notes = 'Automotive' } } | ConvertTo-Json)
```
