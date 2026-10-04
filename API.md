# API Compatibility Reference

With `public/` as the web root, endpoint paths start with `/api/`. Historical root-level `api/` adapters remain available where they existed for installations serving the project directory. API field names are case-sensitive and are intentionally unchanged.

## JSON responses

File endpoints using `CatalogSuite\Http\Response` return success as `{"success":true,"data":...,"correlationId":"..."}` and errors as `{"error":{"code":"...","message":"...","correlationId":"..."}}`. Errors and status codes vary by endpoint; preserve their original casing.

Legacy `/catalog.php?action=...` actions use their own response helper. Successful actions with no result omit `data`; error details and correlation handling follow the legacy implementation. These two API families are intentionally compatible with their existing clients rather than unified.

Clients can send `X-Correlation-ID`. Download endpoints stream files. Do not interpret a file response as JSON.

## Dynamic Public Product API V1

The additive GET namespace `/api/v1/catalog` provides root/tree/resolve/category/series composition, per-series fields, server-side parts/facets/search, collections and guarded assets. Its JSON responses use `success`, `data` or the legacy-style `errorCode/message/details`, and `correlationId`. Read the [complete V1 contract and actual-data import instructions](docs/api-plan/PUBLIC_PRODUCT_API_V1_IMPLEMENTATION.md) for query validation, schema/content configuration and acceptance commands.

Public V1 and existing catalog/SpecSearch reads exclude hidden public fields and honor publication controls after migration. Admin operations and internal document-generation access remain available. Legacy CSV replacement/pruning and endpoint envelopes are preserved. Demo seeding is now explicitly opt-in; V1 never seeds on GET. No Laravel or Passport integration is included in V1.

## File endpoints

| Endpoint | Inputs / behaviour |
|---|---|
| `/api/catalog/hierarchy.php` | Category, series and product tree; Typst enablement flag retained. |
| `/api/catalog/search.php` | Query `q`; flat category/product matches. |
| `/api/catalog/csv-import.php` | Multipart `file`; success HTTP 202. |
| `/api/catalog/csv-export.php` | Export catalog and return stored file metadata. |
| `/api/catalog/csv-download.php` | Query `id`; streams stored CSV. |
| `/api/catalog/csv-history.php` | Stored CSV history and truncate state. |
| `/api/catalog/csv-restore.php` | JSON `id`; restore selected stored CSV. |
| `/api/catalog/truncate.php` | JSON `reason`, `confirmToken` (or `token` alias), optional `correlationId`; destructive operation. |
| `/api/catalog/pdf.php` | Query `id` identifies legacy LaTeX template; compiles and returns PDF metadata. |
| `/api/spec-search/root-categories.php` | Returns `data.categories`. |
| `/api/spec-search/product-categories.php` | Query `root_id`; returns `data.groups`. |
| `/api/spec-search/facets.php` | JSON `category_ids`; returns `data.facets`. |
| `/api/spec-search/products.php` | JSON `category_ids`, `filters`; returns `data.items` and `data.total`; maximum 500 products. |
| `/api/series/details.php` | GET query `id`; series details. |
| `/api/typst/templates.php` | GET query `id`/`seriesId`; POST/PUT JSON `title`, `description`, `typst`, optional `seriesId`, `lastPdfPath`; PUT also `id`; DELETE query `id`. |
| `/api/typst/variables.php` | GET query `id`/`seriesId`; POST creates or updates with `key`, `type`, `value`, optional `id`, `seriesId`; supports multipart file uploads; DELETE query `id`, optional `seriesId`. |
| `/api/typst/compile.php` | POST JSON `typst`, optional `seriesId`; returns PDF `url`, `path`. |
| `/api/typst/series-preferences.php` | GET query `seriesId`; PUT JSON `seriesId`, `lastGlobalTemplateId` (nullable). |
| `/api/latex/templates.php` | GET query `series_id`; POST/PUT JSON `title`, `description`, `latex`, optional `seriesId`; PUT also `id`; DELETE query `id`. POST success HTTP 201. |
| `/api/latex/variables.php` | GET globals; POST/PUT JSON `key`, `type`, `value`, optional `id`; DELETE query `id`. |
| `/api/latex/compile.php` | POST JSON `latex`, `seriesId`; returns PDF `url`, `path`. |

Spec search keeps `seriesImage` and `pdfDownload`. The PDF source is the latest series Typst PDF when Typst is enabled, otherwise the `series_product_spec` metadata file.

## Legacy catalog actions

Call `/catalog.php?action=<action>` with the existing query/JSON/multipart fields. The controller preserves validation, payloads, transactions and file responses. Keep these addresses when integrating WordPress or migrating to Laravel.

| Action | Method |
|---|---|
| `v1.ping` | GET |
| `v1.listHierarchy` | GET |
| `v1.saveNode` | POST |
| `v1.deleteNode` | POST |
| `v1.setSeriesTypstTemplating` | PUT |
| `v1.listSeriesFields` | GET |
| `v1.publicCatalogSnapshot` | GET |
| `v1.specSearchRootCategories` | GET |
| `v1.specSearchProductCategories` | GET |
| `v1.specSearchFacets` | POST |
| `v1.specSearchProducts` | POST |
| `v1.listLatexTemplates` | GET |
| `v1.getLatexTemplate` | GET |
| `v1.createLatexTemplate` | POST |
| `v1.updateLatexTemplate` | PUT |
| `v1.deleteLatexTemplate` | DELETE |
| `v1.buildLatexTemplate` | POST |
| `v1.getSeriesAttributes` | GET |
| `v1.saveSeriesField` | POST |
| `v1.saveSeriesAttributes` | POST |
| `v1.deleteSeriesField` | POST |
| `v1.listProducts` | GET |
| `v1.saveProduct` | POST |
| `v1.deleteProduct` | POST |
| `v1.truncateCatalog` | POST |
| `v1.listCsvHistory` | GET |
| `v1.exportCsv` | POST |
| `v1.importCsv` | POST |
| `v1.restoreCsv` | POST |
| `v1.downloadCsv` | GET |
| `v1.downloadMedia` | GET |
| `v1.deleteCsv` | POST |

Node/product/field save actions retain their existing `id`, `parentId`, `seriesId`, `fieldKey`, `fieldType`, `fieldScope`, `sku`, `name`, custom values and upload fields. `listSeriesFields` accepts `seriesId` and optional `scope`. Attribute/product saves accept JSON or multipart `metadata` plus `files`. `setSeriesTypstTemplating` accepts `seriesId` and boolean `enabled`.

Legacy LaTeX get/update/delete/build actions use query `id`. CSV restore/download/delete actions use the selected stored file `id`. Media downloads use the stored relative media `id`.

The legacy Spec Search actions require `root_id` and retain their original category/filter formats. They are separate contracts from the file endpoints above.

## Storage and integration

Keep all existing media and PDF URLs, including `/storage/*` and `catalog.php?action=v1.downloadMedia&id=...`. Typst tables and catalog schema retain their existing automatic setup behaviour. Existing migrations remain available in `scripts/`.

WordPress consumers should keep their existing URLs, request methods and payload fields. Hosting authentication, cross-origin access and reverse-proxy configuration remain deployment-specific.
