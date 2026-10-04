# Public Product API V1: implementation and actual-data verification

This implements the approved [plan](PUBLIC_PRODUCT_API_V1_PLAN.md) on `plan/public-product-api-v1`. It adds a standalone read-only API, explicit migrations, a transactional JSON merge importer and an HTTP acceptance verifier. The Laravel website, Laravel bridge route registry and Passport are outside this phase.

No catalog records, specifications, part numbers or media are included in the import template. Engineering fixtures exist only in disposable test databases and temporary storage. Existing demonstration seeding is now opt-in with `CATALOG_SEED_DEMO=true`; keep it unset for actual catalog work.

## Wireframe and architecture review

The four requested live URLs returned an authentication wall in this environment. The corresponding existing wireframe source was inspected directly (`/workspace/sites/lo-wireframe/src/app.js`, including the product renderers and augmenters). This verifies structure, not the final authenticated live rendering. Its placeholder specifications were not imported or treated as catalog facts.

| Required page | Data contract |
| --- | --- |
| `/products` | Root breadcrumb/hero/blocks/assets; ordered recursive `groups` with child labels, descriptions, anchors, targets and media; ordered `collections` with membership, card image/title/subtitle/URL and browsing config. |
| `/products/general` | Generic category breadcrumb/resource/hero, child `sections`, horizontal `navigation`, block `section_navigation`, section images and item thumbnails/targets/order. Another branch uses the same endpoint. |
| `/products/general/emc` | Category/family sections and anchors; each family stores its own `series_table` block with dynamic columns, descendant-series rows, media and documents. Different families need not share any field keys. |
| `/products/general/emc/a4k` | Generic series context, public metadata/schema, ordered blocks and navigation, asset roles/gallery/model/documents, paginated part values, selectable identities, filters/facets/search/sort, and configured CTA/enquiry/download actions. Section names and presence are imported data. |

The existing adjacency-list hierarchy, product records and scoped custom-field/value tables remain authoritative. New presentation records compose those resources; no page or series controller exists. Hierarchy traversal is recursive, not a fixed category-to-series join. Part counts, filters, search, sorting and pagination execute in SQL before rows are returned; the legacy SpecSearch 500-row contract is not used by V1.

## Database additions

Run the migration explicitly; V1 GET requests never create tables, alter schema, seed or generate PDFs. A SELECT-only database account can serve V1 after provisioning.

| Existing table | Additions |
| --- | --- |
| `category` | Persistent `slug`, root-safe sibling-uniqueness scope, `is_published`, description/subtitle, persistent anchor and optional target URL. Existing nodes are published by default for compatibility. |
| `product` | `is_published`, default published for existing/legacy records. |
| `series_custom_field` | Unit, filter/sort/table/search flags, select/range filter type, JSON presentation config and group key/label. Existing hide flags/scopes/types and value tables are retained. Filtering/sorting default off until configured. |

New tables are `catalog_content_block`, `catalog_asset`, `catalog_collection`, `catalog_collection_item` and `catalog_path_alias`. Blocks/assets use catalog/category/series/product ownership; collections use explicit ordered memberships. New blocks/assets/collections/memberships default private. Four insert/update triggers maintain sibling uniqueness and enforce one canonical alias per node. These are ordinary stored scope columns because MySQL disallows the required cascading foreign keys with the generated-column version of this design.

Slugs are allocated once from the initial name when legacy operations create a node or the migration backfills it. Renaming preserves the slug. Sibling/root duplicates receive deterministic suffixes; conflicting moves return 409. Canonical category aliases rebase descendants; explicit descendant canonical aliases take precedence. Natural hierarchy paths remain resolvable. Secondary aliases resolve their individual targets without rewriting descendants. Paths allow Unicode letters/numbers and dash-separated segments, with a 4096-byte request-path limit and no fixed depth. Stored aliases allow up to 700 characters and must also fit the request-path limit.

`--up` is repeatable and additive. MySQL DDL commits implicitly, so a interrupted upgrade should be rerun. `--down` is repeatable and retains legacy hierarchy, fields, parts and values, but removes V1 slugs/publication/presentation additions and all V1 presentation records. Back up those additions before rollback; reapplying cannot restore removed presentation or old slug identities automatically.

## Endpoints and envelopes

All endpoints accept GET only. JSON success is `{ "success": true, "data": ..., "correlationId": ... }`. Errors retain the existing `{ "success": false, "errorCode": ..., "message": ..., "details": ..., "correlationId": ... }` shape. Private/missing resources return 404, invalid query/path inputs 422, unsupported methods 405, invalid hierarchy/alias configuration 503 and unprovisioned/internal database errors a redacted 500. JSON responses use `Cache-Control: no-store` and `nosniff`.

| Endpoint | Result |
| --- | --- |
| `/api/v1/catalog` | Root page composition. |
| `/api/v1/catalog/tree` | Lightweight recursive resource tree without part values. |
| `/api/v1/catalog/resolve/{path}` | Resource, breadcrumb and canonical detail API URL. |
| `/api/v1/catalog/categories/{path}` | Category composition at any depth. |
| `/api/v1/catalog/series/{path}` | Series composition/context/schema and part endpoint URLs. |
| `/api/v1/catalog/series/{path}/fields` | Public part fields/columns, metadata definitions and row identity. |
| `/api/v1/catalog/series/{path}/parts` | Dynamic schema, part rows/values/assets/blocks/documents and pagination. |
| `/api/v1/catalog/series/{path}/facets` | Public filter definitions and value/count lists across the full filtered universe. |
| `/api/v1/catalog/search` | Paginated public node/part matches and destination paths/URLs. |
| `/api/v1/catalog/collections/{key}` | A configured public collection. Added to support independent carousel refresh. |
| `/api/v1/catalog/assets/{id}` | Visibility-checked local bytes or a 302 to a configured HTTPS external asset. Added so file paths are not disclosed. |

Paths are relative to the catalog, without `/products` or the API prefix. Encode each path segment when constructing requests. A series whose own slug is `parts`, `fields` or `facets` remains routable: an exact existing series path takes precedence over interpreting a suffix as a subresource. Aliases that shadow a series' field/part/facet URL are rejected as invalid configuration, including aliases below natural and canonical series paths; the merge importer rolls those changes back.

Part queries accept only `page`, `per_page`, `search`, `sort`, `direction` and `filter`. Defaults are page 1/per-page 25; per-page is 1–100 and page is 1–1,000,000. `direction` is exactly `asc` or `desc`. `sort` must be a public, sortable, non-file definition key. Default ordering is SKU/id ascending; configured numeric fields sort numerically with invalid/null values last and stable SKU/id ties. Field values remain stored strings. Numeric SQL comparisons use `DECIMAL(65,20)`: up to 45 integer and 20 fractional digits in decimal notation. Values outside that numeric precision/format remain visible as stored strings, sort as invalid/null and do not match numeric ranges. Range bounds accept that full precision (at most 67 characters including sign and decimal point); greater precision is rejected rather than rounded into a match.

Select filters accept `filter[FIELD][]=VALUE` (OR within one field, AND across fields), including a scalar shorthand. Numeric range definitions accept `filter[FIELD][min]=VALUE&filter[FIELD][max]=VALUE`, either bound optional. Unknown/private/non-filterable fields, invalid ranges and arbitrary SQL keys return 422. At most 50 filters and 100 select values per field are accepted. Search is literal, including `%`, `_` and `!`, up to 256 UTF-8 characters without control characters. Identity/name/description and public searchable non-file values participate; defaults participate consistently in output/query/facets.

Pagination includes `page`, `per_page`, `total`, `last_page`, `from`, `to` and `links.first/last/previous/next`. Facets accept only `search` and `filter`, and apply the same predicates without pagination. Global search accepts `q`, `page`, `per_page`; empty `q` returns zero matches.

## Presentation configuration

Block keys, types, titles, anchors, payloads, presence and order are stored. Renderer semantics such as hero, rich text, feature list, key/value, compliance, image/gallery, drawing, chart, document, CTA and parts table are generic conventions; the API passes configured payloads without a fixed section list. Clients must safely render rich text rather than execute it.

Nested or top-level `{ "asset_id": ID }` references resolve only to public assets with public owners. The importer accepts `{ "asset_key": ACTUAL_OWNER_ASSET_KEY }` in block payloads and replaces it with the registered ID. `{ "field_key": ACTUAL_KEY, "scope": "series_metadata" }` resolves a public definition and series value; `product_attribute` references in a product block resolve that part's value. In a series block a product-attribute reference supplies its definition with a null value; part values come from the parts endpoint. Unknown/private references are omitted. Field/asset presentation config is sanitized and cannot recursively expose references or storage paths.

For each family, a `series_table` payload contains `recursive` (default true) and ordered `columns`. Every column supplies its own `key` and `source`:

| Source | Stored configuration | Returned cells |
| --- | --- | --- |
| `identity` | `property`: title, subtitle, description or URL; optional label | Series resource property. |
| `metadata` | Actual public series-metadata `field_key`; optional label override | Value from that series' definition; absent/private values are null. Type/unit/default label come from the stored definition. A column with no public matching definition is omitted. |
| `asset` | Actual asset `role`; optional label | Public matching asset resources, allowing thumbnails or downloads. |

No default specification-column list is injected. Each family imports its own columns. Rows follow hierarchy display order, with resource links, public assets and available document metadata.

Part selection uses `schema.row_identity.key` (`id`) and `part_number_key` (`sku`). A configured CTA/parts-table payload may carry `actions`, with an imported action key/label/URL/method and `selection: { "source": "parts", "value_key": "sku", "query_key": ACTUAL_QUERY_PARAMETER, "multiple": true }`. The future renderer uses this metadata to construct an enquiry URL from selected identities. The API does not send enquiries. Brochure/download actions use asset references; enquiry destinations and parameter names are catalog content/configuration.

Assets store arbitrary roles, keys, titles/alt text, MIME type, display order, download/public flags and optional metadata. `media`, `typst` and `public` disks use a relative path beneath their configured roots; `external` uses HTTPS without embedded credentials. Images, models, drawings, curves and PDFs share this model. A local resource reports `available: false` if the file is missing/unsafe; it is excluded from document actions and its byte endpoint returns 404. External availability is null until actually fetched. File fields resolve only through a public registered asset, never an exposed raw path. Local byte responses use a sandbox CSP, escaped UTF-8 filenames and inline/attachment disposition from `is_download`.

Collections store title/description/target/config and ordered items with actual node references, optional public asset/card overrides and stable item keys. Renderer browsing settings (visible count/step/autoplay, for example) come from that config. There is no hard-coded latest-release name or date-based membership query.

## Visibility and compatibility

`is_public_portal_hidden=1` excludes the definition, defaults, values, file references, search matches, facet/filter metadata and summary-table data. `is_backend_portal_hidden` remains independent. Unpublished ancestors suppress their descendants, parts, assets and collection references. Private blocks/assets/collections/memberships and orphan owners are excluded. Invalid asset traversal, symlink escapes, executable/dot paths and unsafe URLs cannot be streamed through V1. Public database exceptions disclose no SQL, credentials or paths.

Legacy action/file API envelopes and valid public behavior are retained. PublicCatalogService, legacy catalog reads and SpecSearch now honor hidden fields and additive publication controls; guessing a private legacy filter cannot reveal matching parts. Admin field/value operations, CSV export and internal Typst/LaTeX generation retain internal access. The old SpecSearch shallow-selection/500-row contract remains unchanged; V1 provides the recursive/paginated replacement.

Legacy hierarchy/product deletion and CSV pruning remove orphaned V1 presentation ownership without deleting media files. Existing full-catalog truncate also clears V1 presentation/alias/collection rows, retaining its audit/envelope/count keys. The dev router preserves the historical `catalog.php` and file API entrypoints when serving `public/`. Real compiler testing found and fixed a pre-existing Typst singleton-array serialization bug: single-part series now produce a Typst array rather than a grouped dictionary.

Existing CSV import/export columns and snapshot-replacement/pruning behavior are preserved. CSV does not carry V1 presentation, publication or query flags. Use the merge manifest to add those settings after CSV import; do not use a partial CSV as a supplement because the established CSV importer prunes omitted records. Existing legacy storage URLs remain their existing contract; V1 uses its guarded asset endpoint rather than those raw paths. No changes are made to the Laravel route registry or Passport.

## Import your actual data

Requirements: PHP 8.3+ with mysqli/mbstring/fileinfo and MySQL 8.x (JSON and triggers). Engineering verification ran on PHP 8.4.1/MySQL 8.0.46. MariaDB is not an automatically verified target for this migration. Start from the existing planning branch, configure `CATALOG_DB_HOST`, `CATALOG_DB_PORT`, `CATALOG_DB_DATABASE`, `CATALOG_DB_USERNAME` and `CATALOG_DB_PASSWORD` for your database, and take the normal database backup before migration.

```bash
git switch plan/public-product-api-v1
php scripts/migrate_public_catalog_v1.php --up
cp docs/api-plan/public-product-api-v1-import-template.json /path/to/actual-catalog.json
```

Fill the copy using actual catalog records. Keep `version` unchanged. Its empty `nodes`, `catalog.assets/blocks`, `aliases` and `collections` deliberately contain no sample products. The manifest merges supplied records in one transaction; omitted records/properties are retained, and it does not copy, generate or delete media files.

| Manifest object | Identity and required new-record attributes | Optional actual data/settings |
| --- | --- | --- |
| `nodes[]` | Hierarchical `path`, `name`, `type` (category/series). Parent categories must exist or be supplied. | Description/subtitle/anchor/target/order/publication, fields/metadata/parts for series, assets/blocks for any owner. Use the existing persistent hierarchy path when enriching CSV-imported records. Renaming the name at that path retains its slug. |
| `nodes[].fields[]` | Actual `field_key`, label; identity also includes series and field scope. | Scope product_attribute/series_metadata, type text/number/file, default, unit/order/required, both hide flags, filter/sort/table/search flags, filter_type select/range, config/group key/label. |
| `nodes[].metadata` | Object keyed by actual series-metadata definition keys. | Actual scalar values/null. |
| `nodes[].parts[]` | Actual SKU; identity includes series. | Name/description/publication, values keyed by actual product-attribute fields, assets/blocks. New part name defaults to the supplied SKU. |
| `assets[]` | Owner-scoped asset_key, arbitrary role, relative file_path (or external HTTPS), MIME type. | Disk, title/alt, metadata/order/download/public flags. Place your actual files under the corresponding configured root yourself. |
| `blocks[]` | Owner-scoped block_key and generic block_type. | Title/anchor/payload/order/navigation/public flags, actual field/asset references. |
| `aliases[]` | Path and natural `node_path`. | `is_canonical`; collisions abort the transaction. A flattening alias can match an approved page URL while keeping its actual family ancestry. |
| `collections[]` | collection_key. | Title/description/target/config/order/public flag and items. |
| `collections[].items[]` | collection-scoped item_key plus natural node_path. | Owner asset_key, card title/subtitle/order/public flag. |

New manifest nodes/parts default unpublished; new fields default public-hidden; new presentation/collection records default private. Explicitly set `is_published: true`, `is_public_portal_hidden: false` and `is_public: true` only for intended public data. Flags accept booleans or 0/1; order accepts integers; unknown properties and non-scalar field values fail validation. Existing omitted flags are preserved. Asset references in a block must refer to an asset supplied or already registered for that owner.

`file_path` is relative to the selected disk root. Place the actual files there before checking availability:

| Asset disk | Default root / value |
| --- | --- |
| `media` | `$CATALOG_STORAGE_ROOT/media`, or `<repository>/storage/media` when unset |
| `typst` | `<repository>/public/storage/typst-pdfs` |
| `public` | `<repository>/public` |
| `external` | Actual absolute HTTPS URL; no local file is copied |

The roots come from `config/app.php` and can be overridden by the existing host configuration. Public local URLs use the guarded V1 asset endpoint. Register intended public files explicitly; a legacy file field does not automatically publish an upload.

```bash
php scripts/import_public_catalog_v1.php --file=/path/to/actual-catalog.json --dry-run
php scripts/import_public_catalog_v1.php --file=/path/to/actual-catalog.json
php -S 127.0.0.1:8080 -t public scripts/serve.php
```

For production, route `/api/v1/catalog` and `/api/v1/catalog/*` to `public/api/v1/catalog/index.php` while preserving the original `REQUEST_URI`; do not enable schema bootstrap on these requests. Preserve existing legacy aliases separately. `CATALOG_PUBLIC_PAGE_BASE` changes generated page links (default `/products`); it does not change the API namespace.

## Fill and run actual-data verification

Copy `public-product-api-v1-test-variables.json` to a private actual-data working file. Keep the checked-in template blank. Fill paths relative to the catalog using actual persistent slugs or configured canonical aliases.

For the primary actual series, supply its path/slug, an actual known part, ordered public product-attribute keys/count, optional exact table-column keys, total part count, actual sortable key and first ascending SKU (ties use SKU/id), actual filterable key/value and expected matching count. A select value may be scalar or a list; a range value is an object with actual min/max bounds. Fill ordered content block keys and expected asset/download roles from actual intended public content. Optional expected title checks the display title. Supply a known actual hidden key if one exists; otherwise that acceptance check is explicitly skipped.

For the second actual series, supply its path/slug, known part, public field keys/count, total part count, block keys and asset/download roles. Its schema must differ from the primary schema. No source change is needed.

`page_composition_verification.expected_group_paths` and `expected_collection_keys` check root order when filled. Populate `family_tables` with actual objects containing `category_path`, `block_key`, `expected_column_keys` and `expected_series_paths`, in the actual expected order. This checks each configured family table without assuming its columns. The `verification_bindings` section maps the existing approved-template labels to generic verifier inputs; leave it unchanged.

Fill `page_composition_verification.responses` to assert the remaining actual page composition. Each entry contains an optional descriptive `name`, a `path` relative to `/api/v1/catalog` (empty for the root), an optional `query` object, and a nonempty `expected` object matching selected properties of the response's `data`. Objects compare only the supplied properties; lists compare complete length and order while allowing selected properties in each item. Values and nulls compare exactly. Supply independently known actual expectations rather than copying a response without checking it against the imported catalog and wireframe.

| Page data to verify | Properties to supply inside `expected` |
| --- | --- |
| Root title, hero, breadcrumb and group layout | `hero.title`, `hero.payload`, `breadcrumb`, ordered `groups` with nested `children`, titles, descriptions, URLs, anchors and assets |
| Category hero and sections | `resource.title/description`, `hero`, ordered `navigation`, `section_navigation`, `blocks` and `sections` with child paths/labels/URLs, assets and item thumbnails |
| Family sections and navigation | Ordered category `sections`, their anchors/descriptions/assets/blocks, plus `family_tables` for each configured table schema and series order |
| Release cards and browsing | `/collections/{actual-key}` with `title`, `target_url`, `config`, ordered `items` including resource path, title, subtitle, URL and image role/availability |
| Series section navigation and actions | Series `breadcrumb`, `parent_context`, ordered `navigation` and `blocks` including actual title, anchor, payload/CTA selection metadata, assets and document metadata |

These are nested JSON properties, not dotted property names. Empty `responses` leaves these extra checks unsupplied; it does not certify their contents. Query parameters can exercise any JSON endpoint using the same expected-property comparisons. Malformed assertion definitions, invalid catalog paths, dot segments, encoded traversal and backslashes fail before HTTP access; composition request paths cannot escape the catalog API namespace.

```bash
cp docs/api-plan/public-product-api-v1-test-variables.json /path/to/actual-variables.json
# Edit that copy with your actual imported values, then run:
php scripts/verify_public_catalog_v1.php \
  --base-url=http://127.0.0.1:8080 \
  --variables=/path/to/actual-variables.json
```

Exit 0 means the supplied actual expectations passed; exit 1 means an HTTP/assertion failure; exit 2 means missing/invalid inputs. The blank template exits 2 before any network request. The verifier checks both category pages, root/tree, both series, schema/columns/order/media presence, counts/pagination, known part searches, filters/facets/sort, global search, supplied family schemas and supplied composition assertions. It checks local file availability metadata; external URLs, image appearance and PDF correctness require actual-file inspection. It does not certify facts that were not supplied as expectations.

Individual requests can also be run using your actual values:

```bash
api=http://127.0.0.1:8080/api/v1/catalog
curl -fsS "$api"
curl -fsS "$api/tree"
curl -fsS "$api/resolve/$actual_category_path"
curl -fsS "$api/categories/$actual_category_path"
curl -fsS "$api/series/$actual_series_path"
curl -fsS "$api/series/$actual_series_path/fields"
curl -fsS "$api/series/$actual_series_path/facets"
curl -fsS --get "$api/series/$actual_series_path/parts" \
  --data-urlencode 'page=1' --data-urlencode 'per_page=25' \
  --data-urlencode "search=$actual_part_number" \
  --data-urlencode "sort=$actual_sortable_key" --data-urlencode 'direction=asc' \
  --data-urlencode "filter[$actual_filterable_key][]=$actual_filter_value"
curl -fsS --get "$api/search" --data-urlencode "q=$actual_part_number"
```

Use encoded paths from `resolve.api_url`/returned endpoint URLs for Unicode paths. For local asset verification, GET each actual returned `asset.url`, inspect the bytes/Content-Type/disposition and compare with the original file. Follow external redirects only when testing your actual configured destination.

## Engineering verification and remaining acceptance

The repository originally had no committed automated suite, static-analyzer configuration or formatter configuration. Before major changes, all 91 PHP files linted and 8 new compatibility groups ran against the original behavior with security expectations gated. Baseline: 8 passed/0 failed/0 skipped. PHP 8.4 CSV escape deprecations were observed and fixed by explicitly preserving the historical escape character.

The new dependency-free suite creates a fresh random database per test file, uses generic runtime fixtures and removes databases/storage on exit. It treats unsuppressed PHP warnings/deprecations as failures. The HTTP suite starts/stops native PHP servers and creates/drops an isolated SELECT-only account; its test database account requires CREATE/DROP DATABASE and CREATE USER/GRANT/DROP USER rights. Do not use this test runner as a production acceptance runner.

```bash
composer lint
CATALOG_TEST_TYPST_BIN=/absolute/path/to/native/typst \
CATALOG_TEST_PDFLATEX_BIN=/absolute/path/to/pdflatex composer test
npm ci
npm run build:assets
git diff --check
```

Without native compiler executables, compiler checks report explicit skips; no fake compiler is substituted. Compiled PDF text is also checked against stored runtime metadata and part identity with `/usr/bin/pdftotext`, or `CATALOG_TEST_PDFTOTEXT_BIN` when set; unavailable text extraction is reported as a skip. Targeted suites can be selected with `php tests/run.php catalog_test.php migration_test.php public_security_test.php`. Full results and file inventory are recorded in [PUBLIC_PRODUCT_API_V1_VALIDATION.md](PUBLIC_PRODUCT_API_V1_VALIDATION.md).

Actual Superworld data import/acceptance has not been performed. Visual comparison behind the live wireframe sign-in wall, your native production web-server rewrite, your actual imported files and the future Laravel host require their own acceptance stage. Engineering testing verifies the reusable API capabilities; it does not invent the final catalog content or claim a bug-free system.
