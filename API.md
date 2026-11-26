# API Compatibility Reference

With `public/` as the web root, endpoint paths start with `/api/`. Historical root-level `api/` adapters remain available where they existed for installations serving the project directory. API field names are case-sensitive and are intentionally unchanged.

## JSON responses

File endpoints using `CatalogSuite\Http\Response` return success as `{"success":true,"data":...,"correlationId":"..."}` and errors as `{"error":{"code":"...","message":"...","correlationId":"..."}}`. Errors and status codes vary by endpoint; preserve their original casing.

Legacy `/catalog.php?action=...` actions use their own response helper. Successful actions with no result omit `data`; error details and correlation handling follow the legacy implementation. These two API families are intentionally compatible with their existing clients rather than unified.

Clients can send `X-Correlation-ID`. Download endpoints stream files. Do not interpret a file response as JSON.

## Public Product API

The read-only path API queries the existing catalog tables directly. It requires no import, synchronization job or additional schema. Its JSON responses use the legacy `success`, `data`, `correlationId` envelope; errors use `success: false`, `errorCode`, `message` and `details`.

| Endpoint | Behaviour |
|---|---|
| `GET /api/` | API information, implemented endpoint list and catalog tree. |
| `GET /api/health` | Database connectivity and read-only status. |
| `GET /api/tree` | Recursive categories and series, including series part counts. |
| `GET /api/resolve/{path}` | Resource and breadcrumb for a category or series path. |
| `GET /api/categories/{path}` | Category resource, breadcrumb, immediate children, CSV-backed `content` and public image URLs in `media`. |
| `GET /api/series/{path}` | Series resource, breadcrumb, public metadata, fields, part count and registered public file URLs in `media`. |
| `GET /api/series/{path}/fields` | Public product field definitions. |
| `GET /api/series/{path}/parts` | Parts, public custom values, fields and pagination. Parts with registered public file values include a `media` map. |
| `GET /api/series/{path}/facets` | Value counts for public product fields. |
| `GET /api/search?q={keyword}` | Matching category/series names and product SKU, name or description. |
| `GET /api/series/{path}/summary` | Part count, engineering summaries and public media for one Series. |
| `GET /api/categories/{path}/summaries` | The same summaries for a category and its descendants, grouped by direct parent category. |

Use the `path` returned by the tree or resolve response. Paths follow the category hierarchy and current names; duplicate sibling names receive ID suffixes that avoid other sibling slugs. Renaming or reparenting nodes can change their paths. The parts route is `/parts`; there is no separate public `/products` route.

Parts accept `search`, `filter[fieldKey]` (one value or an array of alternatives), `sort` (`id`, `sku`, `name` or a public field key), `direction` (`asc` or `desc`), `page` and `per_page` (1–100, default 25). Field filters match values case-insensitively. Public-hidden fields and metadata are excluded. Other HTTP methods return 405.

Category media URLs use the CSV image column as their key. Series and part media URLs use the existing metadata or part field key. Each URL targets the registered public-alias route `/api/media/public.php`.

Series responses add `mediaDetails`, keyed by file field and containing `{filename,mimeType}` only when the public alias is registered and its readable file bytes match a supported image, PDF or GLB type. Valid raster image details also include their actual `width` and `height`. Parts responses add the same `mediaDetails` map to each part with validated files. Existing `media` URL maps and product values are unchanged; invalid or missing files are omitted from `mediaDetails`. File entries in Series `facets` include `validPdfCount`, the full-Series number of products whose registered alias for that field validates as a readable PDF. This count is independent of the current parts page. Media validation is local and read-only; it does not make HTTP requests to public URLs.

The development router and Apache `api/.htaccess` rules expose these routes for both supported standalone document roots while retaining existing file endpoints. Apache requires `mod_rewrite` and permission to use these rules; Nginx needs equivalent routing. The optional Laravel bridge continues to mount its existing file/action endpoints; mounting this path API in a Laravel host requires equivalent host routing.

### Series summaries

Request one Series using its canonical tree path, for example
`GET /api/series/general-products/emc-components/chip-array-ferrite-bead/a4k-series/summary`.
Request a family using the canonical category path, for example
`GET /api/categories/general-products/emc-components/summaries`.
Both requests use the Public Product API response and error envelopes described above.

The single endpoint returns a summary row in `data`. The category endpoint returns
`data.category`, `data.groups` and `data.pagination`. Each group has the direct parent
`category`, semantic `columns`, and `series` containing summary rows. A row's `series`
includes `id`, `parentId`, `parentPath`, `type`, `path`, `slug`, `name` and
`displayOrder`. Use the ID to associate rows and the current path for navigation.
Groups follow category tree order, and rows follow their Series display order
with the same name and ID tie breaks as the catalog tree.

Category pagination is over Series rows across groups. The default and maximum
`per_page` is 300. Optional `page` defaults to 1. Both must be positive integers.
Values above the maximum page size or last page are clamped. Read `page`,
`per_page`, `total`, and `last_page` from `pagination`. When a group spans pages,
merge it by category ID or path and merge its rows by Series ID. Its column
metadata describes the whole group. An empty category returns an empty
`groups` list with `total: 0`.

`columns` entries have a semantic `key`, `label`, `type` and, where applicable,
`unit`. They describe columns for a compact family table. Additional applicable
specifications are in each row's `specifications` map. A table consumer can map
`series` to the Series name and `partCount`, `dimensions` to `dimensions.display`,
each technical field key to its `display`, and `media.series_product_image` and
`media.series_product_spec` to the corresponding image and download.

#### Aggregation and availability

- `partCount` counts every Product row assigned to the Series, as in Tree and Parts.
- Dimensions list separate `length`, `width` and `height` ranges with display text in
  millimetres. A variable dimension displays component-wise ranges. The endpoints
  of different axes can come from different parts.
- Numeric fields return `min`, `max`, `unit`, `display` and `status`. Categorical values
  such as Qi Standard have a distinct value list and display text. Consumers can
  render the provided display strings directly.
- Summary fields derive from supported engineering definitions and public data in
  the direct category group. Public, stored or effectively defaulted values and
  required definitions are evidence of applicability. Imported optional blank
  definitions alone do not establish applicability.
- The importer can store engineering values as text. The summary recognises finite
  decimals and scientific notation, retains zero, and excludes unrecognised or
  incompatible values from numeric bounds. The public definition's default only
  applies when the product value row is absent. An explicit blank or null row stays
  missing. Hidden fields and private metadata stay outside the response.
- Canonical display units include mm for dimensions, Ω for impedance and DCR,
  mA for current, µH for inductance, W for power, and dB for insertion loss.
  Bare values in `acf.dcr` follow the original catalog's mΩ import convention.
  FECS divides those values by 1,000 to produce Ω. The legacy
  `acf.dcr_typ` values use mixed units, so the summary requires an explicit
  unit in the value or field label when interpreting Typical DCR.
- The imported `acf.irms` field is shared by source sheets with different current
  definitions. The summary keeps it separate from saturation current (`acf.isat`)
  and uses a specific current label where the source semantics are established.
- `available` means valid data contributes to the summary. `missing` means the
  field applies but no value is present. `not_applicable` means the field has no
  applicable public definition. `invalid` means supplied values were unusable
  and no valid value contributes. `invalidCount` records rejected values,
  including when valid values still contribute to a range. A specification
  outside the group's supported field set is omitted.

#### Media and downloads

`media` uses the existing `series_product_image` and `series_product_spec`
keys with a public alias URL or `null`. `mediaStatus` reports `available`,
`missing` or `invalid` for each key. `mediaDetails` provides available image alt
text or a safe PDF filename. The specification is the mapped Series datasheet.
Product-level PDFs and generated product documents continue to come from their
existing APIs.

Use the aliased URLs with the public Media Library route. If the API base is
`https://catalog.example.com/fecs/api/`, resolve an alias beginning `/api/` by
appending the part after `/api/` to that base. With a same-origin proxy like
Superworld's, the returned `/api/media/public.php?path=…` URL works directly.
Fetch the PDF through that URL. Media delivery supports HEAD and checks public
visibility and eligibility before serving the asset. Superworld uses a same-origin
download link with the provided filename.

Summary paths share the existing `GET`-only routing. Existing canonical paths
take precedence over a suffix match. Unknown paths return 404, invalid paths
or pagination inputs return 422, and other HTTP methods return 405. The endpoints
read the live Catalog and Media Library on each request with `Cache-Control: no-store`.
Part and media updates are visible without an aggregate refresh job.

For example, this is an excerpt from the local A4K summary response. The series
also appears in the same shape under the Chip Array Ferrite Bead group in the
General EMC batch.

```json
{
  "series": {
    "id": 160,
    "name": "A4K SERIES",
    "slug": "a4k-series",
    "path": "general-products/emc-components/chip-array-ferrite-bead/a4k-series",
    "parentId": 159,
    "parentPath": "general-products/emc-components/chip-array-ferrite-bead",
    "displayOrder": 0
  },
  "partCount": 6,
  "dimensions": { "display": "3.2 × 1.6 × 0.9 mm", "unit": "mm", "status": "available" },
  "specifications": {
    "acf.impedance": { "min": 30, "max": 1000, "unit": "Ω", "display": "30–1000 Ω", "status": "available" },
    "acf.dcr": { "min": 0.2, "max": 0.75, "unit": "Ω", "display": "0.2–0.75 Ω", "status": "available" },
    "acf.irms": { "label": "Rated Current", "min": 150, "max": 500, "unit": "mA", "display": "150–500 mA", "status": "available" }
  },
  "media": {
    "series_product_image": "/api/media/public.php?path=__library__%2Fd45fa1e8-9475-4ec7-be13-3bad09c74831%2FA4K.jpg",
    "series_product_spec": "/api/media/public.php?path=__library__%2F721059d1-630f-413c-b68d-4b2f6979401b%2FA4K-SERIES.pdf"
  },
  "mediaStatus": { "series_product_image": "available", "series_product_spec": "available" },
  "mediaDetails": { "series_product_image": { "filename": "A4K.jpg" }, "series_product_spec": { "filename": "A4K-SERIES.pdf" } }
}
```

## File endpoints

| Endpoint | Inputs / behaviour |
|---|---|
| `/api/catalog/hierarchy.php` | Category, series and product tree; Typst enablement flag retained. |
| `/api/catalog/search.php` | Query `q`; flat category/product matches. |
| `/api/catalog/csv-import.php` | Multipart `file`. Media columns accept a Media Library UUID or an existing `storage/media/` path. Success HTTP 202. |
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

Product items include `spq` as a string when the Series has a stored `series_spq_per_reel` value. The `pdfDownload` URL uses the Series datasheet when a locally generated Typst PDF is unavailable.
| `/api/series/details.php` | GET query `id`; series details. |
| `/api/typst/templates.php` | GET query `id`/`seriesId`; POST/PUT JSON `title`, `description`, `typst`, optional `seriesId`, `lastPdfPath`; PUT also `id`; DELETE query `id`. |
| `/api/typst/variables.php` | GET query `id`/`seriesId`; POST creates or updates with `key`, `type`, `value`, optional `id`, `seriesId`; supports multipart file uploads; DELETE query `id`, optional `seriesId`. |
| `/api/typst/compile.php` | POST JSON `typst`, optional `seriesId`; returns PDF `url`, `path`. |
| `/api/typst/series-preferences.php` | GET query `seriesId`; PUT JSON `seriesId`, `lastGlobalTemplateId` (nullable). |
| `/api/latex/templates.php` | GET query `series_id`; POST/PUT JSON `title`, `description`, `latex`, optional `seriesId`; PUT also `id`; DELETE query `id`. POST success HTTP 201. |
| `/api/latex/variables.php` | GET globals; POST/PUT JSON `key`, `type`, `value`, optional `id`; DELETE query `id`. |
| `/api/latex/compile.php` | POST JSON `latex`, `series_id`; returns PDF `url`, `path`. |

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

## Media Library API

The standalone module is enabled by default without a login. Follow README installation steps to provision its six media tables and index existing files; an explicit CATALOG_MEDIA_LIBRARY_ENABLED=false can disable it. Both /api/media/*.php entrypoint families and the Laravel bridge use the same controller/services. Existing APIs keep their fields and envelopes; new endpoints use:

    {"success":true,"data":{},"correlationId":"..."}
    {"error":{"code":"STALE_TARGET","message":"...","details":{},"correlationId":"..."}}

The standalone concept requires no login, access key or Authorization header. Once media integration and its schema are initialized, requests use a shared prototype actor with all permissions. Future Laravel hosts can add login middleware and the optional authorization callback. Cookie/session hosts returning csrfToken require X-CSRF-Token for mutations. Permission names below are enforced when that host policy is configured; public.php is a narrow public-alias delivery exception.

| Endpoint | Method | Permission / inputs |
|---|---|---|
| /api/media/capabilities.php | GET | view; effective maxBytes, extensions, permissions, csrfToken, roots, atomicMappingLimit, purgeEnabled=false. |
| /api/media/list.php | GET | view; q, type=image/pdf/glb/other, status, root, categoryId, seriesId, productId, fieldId, fieldScope, page, perPage, sort, direction. Search is part of this route. |
| /api/media/details.php | GET | view; id. Absolute paths additionally require view_paths or "*". |
| /api/media/folders.php | GET | view; current Catalog nodes/file definitions and status counts. Folders are virtual. |
| /api/media/relationships.php | GET | view; mediaId (or id), page, perPage. |
| /api/media/targets.php | GET | attach; type=category/series/product, q; optional id returns compatible fields; id plus fieldId returns target state/version. |
| /api/media/download.php | GET, HEAD | view; id; attachment bytes. |
| /api/media/preview.php | GET, HEAD | view; id; only safe raster images/PDF. GLB uses download. |
| /api/media/batches.php | POST | upload; idempotencyKey, items manifest. |
| /api/media/batches.php | GET | view; id, cursor; operator-owned upload/mapping batch and 100-item result pages. |
| /api/media/upload.php | POST | upload; multipart batchId, clientItemId, file; keepDuplicate="1" explicitly stores another copy. |
| /api/media/batch-reuse.php | POST | upload; batchId, clientItemId, mediaId from a matching duplicate candidate. |
| /api/media/batch-cancel.php | POST | upload; batchId, itemIds array; cancels pending/failed/duplicate decisions, never deletes successful files. |
| /api/media/attach.php | POST | attach; mediaId, target, expectedVersion, onExisting. Occupied-target replacement additionally requires replace. |
| /api/media/detach.php | POST | attach; target, expectedVersion; required fields cannot be cleared. |
| /api/media/mapping-validate.php | POST | attach; JSON rows/idempotencyKey or multipart CSV file/idempotencyKey. |
| /api/media/mapping-apply.php | POST | attach; validationId, token, mode=atomic/valid_rows, cursor. Replacement rows additionally require replace. |
| /api/media/mapping-rollback.php | POST | attach; batchId. Compensation reports conflicts and preserves later changes. |
| /api/media/index.php | POST | index; optional root, phase=disk/references/indexed and cursor. Follow nextPhase/nextCursor until nextPhase is null. |
| /api/media/asset.php | DELETE | trash; id, integer expectedVersion, reason. Moves an unused asset to Trash without deleting bytes. |
| /api/media/restore.php | POST | trash; id, expectedVersion. |
| /api/media/purge.php | POST | purge; id, expectedVersion, reason; permanently protected, returns PURGE_PROTECTED. |
| /api/media/public.php | GET, HEAD | Public delivery; exact path=__library__/UUID/filename alias for a registered public Catalog asset only. No listing or arbitrary filesystem path. |

List returns items plus pagination {page,perPage,total,lastPage}; perPage is 1–100, default 50. sort accepts filename, size or uploadedAt; direction is asc/desc. Status filters include unlinked, duplicates, missing_file, integrity_changed, unsupported and trashed. Asset records include id, filename, storedFilename, mimeType, sizeBytes, status, sourceKind, storageRoot, relativePath, uploadedAt, firstSeenAt, observedMtime, referenceCount, confirmedReferenceCount, version, downloadUrl, previewUrl and publicUrl. Historical upload time remains null when unknown; filesystem mtime is not presented as an upload date.

Details add sha256, visibility, provenance, generationContext, aliases, physical storageLocation, optional absolutePath, relationships and relationshipPagination. Relationships expose owner/field IDs, field scope, legacy value, relationship_status, Category/Series/Product SKU and a Catalog navigation URL where valid. referenceCount includes invalid historical links for protection; confirmedReferenceCount counts validated links. Field-default relationships also report effectiveRecordCount.

### Attach and existing form saves

Fetch the target version, then submit:

    POST /api/media/attach.php
    {
      "mediaId":"<asset UUID>",
      "target":{"type":"product","id":123,"fieldId":456},
      "expectedVersion":"<hash returned by targets.php>",
      "onExisting":"replace"
    }

Product targets require a product_attribute file field belonging to their Series; Series targets require series_metadata. Category targets use existing image variables in typst_variables and accept images only. Internal canonical type typst_variable is also accepted for a known variable ID. Missing fields are never created by mapping.

onExisting is skip (default), replace or error. Results include status=applied/skipped/no_change, before, beforeRowExists, after and the new target version. Detach takes the same target/version and clears one attachment without removing bytes. Changed target state yields STALE_TARGET (409).

Existing v1.saveProduct and v1.saveSeriesAttributes accept an additive mediaSelections object keyed by the existing Field Key:

    {"mediaSelections":{"series_product_spec":{"mediaId":"<asset UUID>"}}}

Existing value/upload payloads remain valid. Selecting a Library asset and uploading/clearing that same field in one save is rejected. The server writes its compatible legacy path, never a client-supplied arbitrary path. Category/global/scoped Typst variable saves accept additive mediaId alongside their existing key/type/scope fields; only image variables accept it. Old Product/Series form-save concurrency semantics are retained; use the versioned attachment routes for reviewed bulk edits.

### Upload batches

Create one manifest (up to 1000 unique queue IDs), then upload one multipart file per request:

    {
      "idempotencyKey":"<unique operation key>",
      "items":[{"clientItemId":"file_1","filename":"AQ4K_SERIES.pdf","sizeBytes":443569}]
    }

Upload returns status=ready and asset (HTTP 201), or duplicate_candidate with matching asset IDs/hash (HTTP 200). Choose batch-reuse or upload again with keepDuplicate="1"; the server never overwrites an existing file. Each item durably reports queued, ready, failed, duplicate_candidate or cancelled. Repeated completed content returns the same asset; different content for the same completed item yields IDEMPOTENCY_CONFLICT.

Batch status includes items, summary, total and nextCursor; upload aggregate status is pending, needs_attention, completed or cancelled. Resume by reading all result pages, checking uncertain requests and reselecting local files only for unfinished items. Cancelling an in-flight browser request does not undo a server commit.

### Mapping and CSV

JSON preview accepts rows containing rowId, mediaId, target and onExisting. Source identity may instead be storage_root plus relative_path, or upload_batch_id plus batch_item_id for a completed operator-owned upload. Filename alone never identifies an asset. Validation returns validationId, token, expiresAt, row-by-row rows and summary {valid,invalid}. Each valid row freezes the canonical target, before/after values and expectedVersion. Keep the token in the current operator session; it expires after 30 minutes.

CSV is UTF-8 (optional BOM), comma-delimited with normal quoting, up to 2 MiB and 1000 rows. Use these columns as needed:

| Columns | Requirement |
|---|---|
| row_id | Optional unique letters/numbers/underscore/hyphen; generated if omitted. |
| media_id | Preferred source identity; alternatively use either exact location or completed upload columns above. |
| filename | Optional human-readable label, never used alone for matching. |
| target_type | Required: category, series or product. |
| category_id, series_id, product_id | Supply the stable ID of the target; optional parent IDs must agree. |
| category_name, series_name | Optional exact unique-name lookup or cross-check; ambiguity rejects the row. |
| product_sku | May locate a Product only with Series context; IDs/SKU must agree when both provided. |
| field_id, field_key | At least one required, naming an existing compatible field; both must agree when supplied. |
| field_scope | Optional cross-check: series_metadata or product_attribute; Category fields use their existing image-variable scope. |
| on_existing | Optional skip (default), replace or error. |

Example (replace placeholders with IDs from this installation):

    row_id,media_id,filename,target_type,series_id,field_scope,field_key,on_existing
    image_1,<image UUID>,AQ4K.png,series,<Series ID>,series_metadata,series_product_image,skip
    spec_1,<PDF UUID>,AQ4K_SERIES.pdf,series,<Series ID>,series_metadata,series_product_spec,replace

Apply only after review:

    {"validationId":"<preview ID>","token":"<preview token>","mode":"atomic","cursor":0}

Atomic mode requires every row valid and at most 100 rows; any failure rolls back all field writes/journals in that request. Explicit valid_rows mode processes 25 rows per call, reports invalid/failed rows and returns nextCursor. Continue with the same ID/token until nextCursor is null. HTTP 200 does not mean every row succeeded: inspect item status. Requests are idempotent for completed rows. Failed/stale rows should receive a fresh preview after correction.

Rollback compensates applied rows in reverse order, restores values or absence of original overrides, and reports conflict if a subsequent edit would be overwritten. It retains all assets. Catalog reset invalidates both old previews and rollback operations so reused IDs cannot receive old attachments.

### Errors and compatibility

Typical statuses: 400 malformed JSON/path; 403 host permission/CSRF; 404 missing asset/batch; 405 method; 409 stale state, occupied/in-use media or protected deletion; 413 request/file size; 415 unsupported upload/preview; 422 invalid relationships/mapping/scalars; 429 rate limit; 503 integration not initialized or missing schema. Unexpected errors return a generic message and correlation ID. Never assume failure means a network-interrupted mutation did not commit: inspect batch/target state before retrying.

All assets in this release are public_catalog assets. Protected listing/preview APIs do not make existing public product downloads private. Non-Catalog backend files are excluded from discovery. Legacy relative paths remain in business tables/public responses; cross-root reuse writes a reserved __library__/UUID/filename locator understood by the legacy download and Spec Search services. Disabling the UI retains installed alias delivery and historical URLs.

## Storage and integration

Keep all existing media and PDF URLs, including `/storage/*` and `catalog.php?action=v1.downloadMedia&id=...`. With Media Library disabled, Typst tables and Catalog schema retain their existing automatic setup behavior. When enabled, authorization and explicit media provisioning are checked before legacy setup; anonymous public reads never trigger it. Existing migrations remain available in `scripts/`.

WordPress consumers should keep their existing URLs, request methods and payload fields. Hosting authentication, cross-origin access and reverse-proxy configuration remain deployment-specific.
