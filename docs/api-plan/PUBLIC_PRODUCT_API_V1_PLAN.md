# Public Product API V1 Plan

Branch: `plan/public-product-api-v1`

## Goal

Design and verify a dynamic public product API inside this repository first, before integrating it into the Laravel website.

The API must support these four wireframe routes without page-specific hard-coding:

1. `/products`
2. `/products/general`
3. `/products/general/emc`
4. `/products/general/emc/a4k`

The API contract must be driven by catalog data, hierarchy, field definitions, content blocks, assets, and product values. Adding a new category, family, series, field, image, document, section, or part number must not require new API code for that specific item.

## Non-negotiable rules

- No `if ($series === 'A4K')` or equivalent product-specific branches.
- No fixed EMC-only or General-only API controllers.
- No fixed specification column list in code.
- No fixed A4K section list in code.
- No fixed category depth assumption.
- No URL logic derived only from mutable display names.
- Existing catalog tables and legacy endpoints must remain compatible.
- New schema changes must be additive wherever possible.
- Existing Filament/DataTable mapping must be able to read the same field definitions later.
- Public data visibility must respect `is_public_portal_hidden`.
- Backend visibility must remain independent via `is_backend_portal_hidden`.
- API responses must be versioned.
- API behaviour must be testable using deterministic fixture data.

---

# 1. What the four pages require

## Page A — /products

Wireframe requirements:

- Breadcrumb
- Hero banner
- General Components section
- Automotive Components section
- Product category columns
- Dynamic product/family item rows
- View More links
- Latest Release section
- Latest-release cards
- Previous/next browsing controls

The API therefore needs to expose:

- page identity
- breadcrumb path
- hero content
- top-level catalog groups
- category/family children
- ordered item rows
- navigation targets
- latest-release entries
- release card image/title/subtitle/link
- display order

Nothing in this page should require the API to know the words "General", "Automotive", "EMC", etc. Those are data.

## Page B — /products/general

Wireframe requirements:

- Breadcrumb
- Hero banner
- Horizontal sub-navigation
- EMC Components section
- Magnetic Components section
- Transformer section
- product-family labels
- product-family thumbnails/visuals
- section descriptions
- ordered product/family rows

The API therefore needs:

- resolved node for `general`
- node metadata/content
- child category/family navigation
- ordered category sections
- section image/visual
- section description
- ordered child items
- item image/thumbnail
- item label
- target path

The same contract must also be able to render Automotive or another branch later.

## Page C — /products/general/emc

Wireframe requirements:

- Breadcrumb
- hero/title + description
- product-family sub-navigation
- Chip Array Ferrite Bead section
- Chip Inductor section
- Ferrite Bead Assembly section
- Ferrite Chip Bead section
- additional family tabs when configured
- dynamic table columns
- ordered series rows
- series/product thumbnail
- Download action
- per-family description
- multiple rows per family

The API must NOT hard-code the columns visible in these tables.

For each family, the API should return:

- family definition
- family description
- table schema
- series rows
- series thumbnail
- dynamic summary values
- download document where available
- destination URL for each series
- display order

This page is a key reason the field-definition model must be reusable at both:
- family/series-summary level
- series/part-number level

## Page D — /products/general/emc/a4k

The saved wireframe scan contains these sections:

- Breadcrumb
- Product title
- Product category/family
- Introduction
- Feature list
- Product status
- Compliance information
- Product image
- 3D view
- dimension summary
- sub-navigation
- Specifications
- specification summary values
- search
- inquiry
- selectable part-number rows
- dynamic specification columns
- pagination
- row download action
- Environmental
- Performance Curves
- Physical Dimension
- PCB Layout
- Tape & Reel
- Soldering / Washing
- CTA
- Send Enquiry
- Download Brochure

This page must be rendered from a generic series-detail resource.

A4K is test data, not API logic.

---

# 2. Domain model direction

The repository already has a strong base:

```
category
product
series_custom_field
series_custom_field_value
product_custom_field_value
typst_templates
```

Keep this foundation.

## 2.1 Category / series hierarchy

Current:
- `category.type = category | series`
- recursive `parent_id`

Add:

- `slug`
- optional publication fields
- optional navigation visibility flags if needed

Recommended unique constraint:

```
UNIQUE(parent_id, slug)
```

Path resolution must be recursive and depth-independent.

Example test hierarchy:

```
Products (virtual API root)
└── General
    └── EMC
        └── Chip Array Ferrite Bead
            └── A4K
```

The database does not need a literal "Products" category if the API root represents the catalog itself.

## 2.2 Product / part number

Keep:

```
product
- id
- series_id
- sku
- name
- description
```

A4K part numbers remain product records.

Do not create a new table per series.

## 2.3 Dynamic field definitions

Extend the existing `series_custom_field` definition rather than hard-coding specification columns.

Recommended additive fields:

- `unit` nullable string
- `is_filterable` boolean
- `is_sortable` boolean
- `is_table_column` boolean
- `filter_type` nullable enum/string
- `data_type` or continue using `field_type`
- `config_json` nullable JSON
- optional `group_key`
- optional `group_label`

Existing fields remain authoritative:

- `field_key`
- `label`
- `field_type`
- `field_scope`
- `default_value`
- `sort_order`
- `is_required`
- `is_public_portal_hidden`
- `is_backend_portal_hidden`

A DataTable later reads the same field definitions.

## 2.4 Series metadata

Keep the current `series_metadata` scope for scalar metadata.

Examples:

- category label
- product status
- compliance flags
- package dimensions
- summary specification values
- brochure/document IDs when suitable

Do not put large structured page layouts into scalar metadata.

## 2.5 Generic content blocks

Add a generic content-block model to support the non-tabular A4K sections and higher-level page content.

Recommended table:

```
catalog_content_block
- id
- owner_type
- owner_id
- block_key
- block_type
- title
- payload_json
- display_order
- is_public
- created_at
- updated_at
```

Possible `owner_type`:
- catalog_root
- category
- series

Possible controlled `block_type` values:

- hero
- rich_text
- feature_list
- key_value
- status
- compliance
- image
- image_gallery
- technical_drawing
- chart
- document
- download
- cta
- navigation
- collection

These block types are renderer semantics, not product names.

Do not add block types named:
- a4k_environmental
- emc_table
- general_banner

## 2.6 Generic media/assets

Add a normalized asset model rather than relying only on specific field keys.

Recommended:

```
catalog_asset
- id
- owner_type
- owner_id
- role
- file_path
- mime_type
- title
- alt_text
- metadata_json
- display_order
- is_public
- created_at
- updated_at
```

Example roles:

- primary_image
- thumbnail
- gallery
- hero_visual
- 3d_model
- performance_curve
- dimension_drawing
- pcb_layout
- tape_reel
- soldering_curve
- datasheet
- brochure

Roles remain data-driven and reusable.

---

# 3. Public API shape

Proposed base:

```
/api/v1/catalog
```

This repository should build and verify the contract first. Laravel Passport comes later when this contract is accepted.

## 3.1 Catalog root

```
GET /api/v1/catalog
```

Purpose:
- support `/products`

Returns:
- root page metadata
- breadcrumb
- hero/content blocks
- top-level group collections
- latest releases
- navigation paths

## 3.2 Tree

```
GET /api/v1/catalog/tree
```

Returns a public-safe hierarchy.

Use for:
- navigation
- sitemap
- route discovery
- menu construction

Must filter hidden/private data.

## 3.3 Resolve a path

```
GET /api/v1/catalog/resolve/{path}
```

Examples:

```
GET /api/v1/catalog/resolve/general
GET /api/v1/catalog/resolve/general/emc
GET /api/v1/catalog/resolve/general/emc/a4k
```

Returns:
- resolved entity type
- entity ID
- canonical slug/path
- breadcrumb chain
- parent/ancestor information

No frontend database IDs required.

## 3.4 Category detail

```
GET /api/v1/catalog/categories/{path}
```

Examples:

```
GET /api/v1/catalog/categories/general
GET /api/v1/catalog/categories/general/emc
```

Returns dynamically:

- category data
- content blocks
- assets
- child categories
- child series
- collections/sections
- summary-table schemas when configured

This one generic resource supports both:
- `/products/general`
- `/products/general/emc`

## 3.5 Series detail

```
GET /api/v1/catalog/series/{path}
```

Example:

```
GET /api/v1/catalog/series/general/emc/a4k
```

Returns:

- series identity
- breadcrumbs
- family/category context
- metadata
- content blocks
- assets
- compliance/status
- part schema
- summary specifications
- downloads
- sub-navigation generated from public blocks

No A4K-specific response code.

## 3.6 Series field schema

```
GET /api/v1/catalog/series/{path}/fields
```

Returns public product-attribute field definitions.

Example shape:

```json
{
  "data": [
    {
      "key": "impedance",
      "label": "Impedance",
      "type": "number",
      "unit": "ohm",
      "sortable": true,
      "filterable": true,
      "tableColumn": true,
      "sortOrder": 10
    }
  ]
}
```

Frontend/DataTable must build columns from this.

## 3.7 Series parts

```
GET /api/v1/catalog/series/{path}/parts
```

Supported query parameters:

- `page`
- `per_page`
- `search`
- `sort`
- `direction`
- `filter[field_key]`

Example:

```
GET /api/v1/catalog/series/general/emc/a4k/parts
  ?page=1
  &per_page=25
  &search=A4K300
  &sort=dcr
  &direction=asc
  &filter[impedance][]=30
```

Response:

```json
{
  "schema": [],
  "data": [],
  "meta": {
    "currentPage": 1,
    "perPage": 25,
    "total": 0,
    "lastPage": 0
  }
}
```

Schema may be embedded here to minimize requests, but it must come from field definitions.

## 3.8 Facets

```
GET /api/v1/catalog/series/{path}/facets
```

or generic category search facets where needed.

Facet definitions come from `is_filterable` fields.

## 3.9 Search

```
GET /api/v1/catalog/search?q=
```

Search:
- categories
- families
- series
- products/part numbers

Return canonical paths.

## 3.10 Downloads

Downloads should be represented as resource links in API responses.

The API should not infer file role from a hard-coded field name if the new asset model exists.

Existing legacy media/PDF behaviour must remain compatible.

---

# 4. Dynamic page composition

The API should not contain controllers named after the four pages.

Instead, the frontend composition is driven by generic resources.

## /products

Uses:

- catalog root
- content blocks
- top-level collections
- latest releases

## /products/general

Uses:

- category detail for `general`
- child category collections
- category content blocks
- category assets

## /products/general/emc

Uses:

- category detail for `general/emc`
- family collections
- dynamic family summary-table schemas
- ordered series rows
- asset/document links

## /products/general/emc/a4k

Uses:

- series detail for `general/emc/a4k`
- content blocks
- assets
- field schema
- parts endpoint
- facet endpoint

This is the central verification condition:
the same endpoints must be able to render another category/series fixture without code changes.

---

# 5. Family summary tables on the EMC page

The EMC wireframe has family sections with tables such as:

- Product
- Series
- Dimension
- Impedance Range
- DCR Range
- other family-specific columns
- Download

These must also be data-driven.

Recommended approach:

Add a reusable "view schema" or summary-field configuration attached to the family/category.

Example conceptual configuration:

```json
{
  "view": "series_summary_table",
  "columns": [
    {
      "key": "series",
      "source": "series.name",
      "label": "Series"
    },
    {
      "key": "dimension",
      "source": "series.metadata.dimension",
      "label": "Dimension"
    },
    {
      "key": "impedance_range",
      "source": "series.metadata.impedance_range",
      "label": "Impedance Range",
      "unit": "ohm"
    }
  ]
}
```

The API interprets generic source descriptors/configuration.

It does not know that EMC must contain "Impedance Range".

A different family can configure different summary columns.

---

# 6. Latest release model

The `/products` page requires a latest-release row.

Do not query "latest" only by newest database ID.

Use an explicit publication/release model.

Options:

A. add fields to series/product:
- `published_at`
- `is_featured`

or

B. use a generic collection:

```
catalog_collection
catalog_collection_item
```

Recommended for flexibility:

```
catalog_collection
- id
- key
- title
- owner_type
- owner_id
- display_order
- is_public

catalog_collection_item
- collection_id
- item_type
- item_id
- display_order
- metadata_json
```

Then `latest_release` is a data collection, not controller logic.

This can also support homepage sliders later.

---

# 7. Slug and canonical path behaviour

Add persistent slug storage.

Rules:

- slug is stored, not recalculated on every request
- sibling slugs must be unique
- slug can differ from display name
- canonical path is built from ancestor slugs
- path traversal supports unlimited category depth
- series are terminal catalog nodes for part-number ownership
- path changes should be deliberate

Test example:

```
General Components -> general
EMC Components -> emc
Chip Array Ferrite Bead -> chip-array-ferrite-bead
A4K Series -> a4k
```

Canonical detail URL:

```
/products/general/emc/a4k
```

The family may exist in the ancestry/model even if the public route intentionally omits a family slug. If route flattening is required, represent that with route configuration/alias data, not a hard-coded A4K exception.

---

# 8. Recursive hierarchy

The current SpecSearch implementation assumes a relatively shallow structure in important places.

New public API queries must:

- recursively resolve descendants
- not assume series are direct children of the selected category
- support category -> category -> category -> series
- support future hierarchy expansion

MySQL 8/MariaDB-compatible recursive CTEs or repository recursion may be used depending on supported deployment versions.

---

# 9. Compatibility strategy

Do not replace these existing contracts during V1 planning:

- legacy `catalog.php?action=...`
- current `/api/catalog/*`
- current `/api/spec-search/*`
- CSV import/export
- Typst
- LaTeX compatibility
- existing media URLs
- existing Laravel bridge behaviour

New API lives independently under:

```
/api/v1/catalog/*
```

Reuse services/repositories where correct, but do not force old response envelopes into the new public contract.

---

# 10. Response envelope

Recommended:

```json
{
  "data": {},
  "meta": {},
  "links": {},
  "correlationId": "..."
}
```

Errors:

```json
{
  "error": {
    "code": "not_found",
    "message": "Catalog resource not found"
  },
  "correlationId": "..."
}
```

Keep correlation-ID support.

---

# 11. Public visibility rules

Every public API query must exclude:

- hidden fields
- non-public blocks
- non-public assets
- unpublished entities if publication state is introduced

For existing fields:

```
is_public_portal_hidden = 1
```

must never leak through:
- field schema
- product values
- facets
- search
- summary tables

---

# 12. Test fixture

A deterministic fixture is stored beside this plan:

```
docs/api-plan/public-product-api-v1-test-variables.json
```

The fixture deliberately contains:

- the four page paths
- a realistic General -> EMC -> family -> A4K hierarchy
- A4K metadata
- A4K dynamic part fields
- multiple A4K part numbers
- an extra "B9X" test series with different fields

The B9X series is essential.

If B9X renders correctly through the same API without adding B9X code, the design passes the "not hard-coded" test.

---

# 13. Verification tests to implement

## Hierarchy tests

1. root catalog returns General and Automotive fixture groups.
2. `resolve/general` resolves dynamically.
3. `resolve/general/emc` resolves dynamically.
4. `resolve/general/emc/a4k` resolves a series.
5. recursive descendants work beyond two category levels.
6. changing a display name does not change the stored slug.
7. sibling duplicate slug is rejected.

## /products tests

1. hero block is returned from fixture data.
2. General and Automotive sections are ordered by data.
3. category columns/rows come from the hierarchy.
4. latest releases come from a collection.
5. changing collection order changes response order without code change.

## /products/general tests

1. hero is data-driven.
2. sub-navigation is data-driven.
3. EMC/Magnetic/Transformer sections come from children/content configuration.
4. product-family labels and thumbnails come from fixture records.
5. adding another family shows it without controller changes.

## /products/general/emc tests

1. all family sections are returned dynamically.
2. each family can have a different table schema.
3. table headers are generated from configuration.
4. series rows contain only configured public summary values.
5. download resource appears only when an asset exists.
6. a family with no download does not generate a fake URL.
7. family/series display order follows data.

## A4K detail tests

1. detail endpoint resolves A4K by path.
2. breadcrumb is built from hierarchy.
3. title/category/status/compliance come from data.
4. image gallery comes from assets.
5. 3D model is optional and asset-driven.
6. sub-navigation is generated from available public content blocks.
7. hidden block does not appear in nav or content.
8. Environmental block comes from fixture.
9. Performance Curves support multiple assets.
10. Physical Dimension supports multiple drawings.
11. Tape & Reel comes from block/asset data.
12. Soldering/Washing comes from block/asset data.
13. CTA actions come from data.
14. brochure appears only when configured.

## Dynamic DataTable tests

1. field definitions determine column order.
2. hidden public field is excluded.
3. filterable field appears in facets.
4. non-filterable field does not.
5. sortable field can be used in `sort`.
6. unsupported sort key returns validation error.
7. product values are returned by field key.
8. pagination returns correct totals.
9. search matches part number/SKU.
10. multiple filters combine predictably.
11. null/missing optional values remain valid.
12. one series can define fields not present in another.

## No-hard-code proof test

Fixture series:
- A4K
- B9X

A4K fields:
- impedance
- test_frequency
- dcr
- rated_current
- length
- width
- height

B9X fields:
- inductance
- saturation_current
- temperature_rise_current

Acceptance:

- both series use the same controller/service/resource classes
- both return different schemas
- neither series name appears in API branching logic
- adding B9X does not require source-code changes outside fixture/seed data

---

# 14. Suggested implementation phases after plan approval

## Phase 1 — schema additions

- persistent slugs
- field-display/filter/sort metadata
- generic content blocks
- generic assets
- generic collections

No public endpoint release yet.

## Phase 2 — repositories/services

- recursive path resolver
- public hierarchy service
- public content service
- public asset service
- dynamic schema service
- dynamic part query service
- collection service

## Phase 3 — API V1 read endpoints

- root
- tree
- resolve
- category
- series
- fields
- parts
- facets
- search

## Phase 4 — fixture + automated verification

- load deterministic test variables
- execute all four page contract tests
- execute B9X no-hard-code proof tests
- validate hidden/public field behaviour
- validate pagination/filter/sort

## Phase 5 — API documentation

- endpoint reference
- request examples
- response examples
- error codes
- fixture verification guide

## Phase 6 — only after acceptance

Integrate this proven contract into the Laravel site and then apply Passport/authentication policy there or in the eventual host application.

---

# 15. Definition of done for this repository-first API

The API is ready for Laravel integration only when:

- all four wireframe page requirements can be represented
- no A4K-specific API code exists
- no EMC-specific API code exists
- no fixed specification table columns exist in application code
- hierarchy depth is dynamic
- slugs are persistent
- fields are schema-driven
- content sections are block-driven
- images/documents are asset-driven
- latest releases are collection-driven
- public visibility is enforced
- pagination/filter/sort are server-side
- A4K fixture passes
- B9X different-schema fixture passes
- existing legacy APIs remain functional
- automated tests prove all of the above
