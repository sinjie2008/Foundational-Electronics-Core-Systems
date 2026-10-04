# Public Product API V1 Plan

Branch: `plan/public-product-api-v1`

## Goal

Build and verify the dynamic public product API inside this repository first.

Do **not** integrate it into the Laravel website yet.

The API must support the four approved product-page structures:

1. `/products`
2. `/products/general`
3. `/products/general/emc`
4. `/products/general/emc/a4k`

The four routes define the required UI/data capabilities. They do **not** authorize hard-coded product/category/series data.

## Critical testing rule

No invented catalog data will be shipped for verification.

That means:

- no B9X test series
- no fake product families
- no fake part numbers
- no fake A4K specifications
- no fake specification values
- no fake product documents/images presented as real catalog data

After implementation, the user will import the actual catalog data and use that real imported data for acceptance testing.

Automated tests may create isolated temporary records inside the test database where technically required, but these records must be generic test records, must not resemble production catalog entries, and must never be included in production seed/import data.

---

## Non-negotiable architecture rules

- No `if ($series === 'A4K')` or equivalent series-specific branching.
- No `if ($category === 'EMC')` page logic.
- No fixed General/Automotive/EMC controller logic.
- No fixed specification-column list.
- No fixed category depth.
- No fixed section list for A4K.
- No fabricated seed catalog.
- No URL generated only from mutable display names.
- Existing legacy API behaviour must remain compatible.
- New schema changes should be additive where possible.
- Public visibility must respect `is_public_portal_hidden`.
- Backend visibility remains independent through `is_backend_portal_hidden`.
- The same field definitions must later be reusable by Filament/DataTable.
- All new public endpoints are versioned.

The design principle is:

```
Admin/import defines data
        ↓
Database stores hierarchy + schema + content + assets + values
        ↓
Catalog domain services resolve data dynamically
        ↓
Public API exposes generic resources
        ↓
Website renders those resources
```

---

# 1. Four-page capability mapping

## /products

Must support dynamically:

- breadcrumb
- hero content
- General/Automotive-style top-level groups
- category/family columns
- item rows
- View More targets
- Latest Release collection
- release cards
- release ordering
- previous/next UI data

Names such as General, Automotive, EMC, etc. come from imported data.

## /products/general

Must support dynamically:

- breadcrumb
- hero
- horizontal sub-navigation
- child sections
- child section title/description
- category/family thumbnails
- category/family labels
- ordered children
- target paths

The same category-detail API must support another root branch without new code.

## /products/general/emc

Must support dynamically:

- breadcrumb
- hero/title/description
- family navigation
- multiple family sections
- family descriptions
- dynamic series-summary table schemas
- dynamic series rows
- thumbnail/image
- download link when actual document exists
- display order

The API must not know which columns EMC uses.

Each imported family controls its own summary-table schema.

## /products/general/emc/a4k

Must support a generic series-detail page capable of rendering the approved A4K wireframe structure:

- breadcrumb
- title
- family/category context
- introduction
- features
- product status
- compliance
- image/gallery
- optional 3D asset
- dimensional summary
- section/sub-navigation
- Specifications
- search
- selectable part numbers
- dynamic specification columns
- filters
- sorting
- pagination
- row download where configured
- Environmental
- Performance Curves
- Physical Dimension
- PCB Layout
- Tape & Reel
- Soldering/Washing
- CTA
- enquiry action metadata
- brochure/document metadata

A4K is an actual catalog series to be provided by imported data.

The implementation must not contain A4K-specific code.

---

# 2. Existing model to preserve

The repository already contains the core dynamic structure:

```
category
product
series_custom_field
series_custom_field_value
product_custom_field_value
```

Continue using this foundation.

Conceptually:

```
category hierarchy
    ↓
series
    ↓
products / part numbers
    ↓
dynamic field definitions
    ↓
dynamic field values
```

Do not create tables or classes per product series.

---

# 3. Persistent slug/path support

Add persistent slug support to hierarchy nodes.

Recommended:

```
category.slug
```

with sibling uniqueness:

```
UNIQUE(parent_id, slug)
```

Rules:

- slug stored in DB
- display-name change does not silently change URL
- path built recursively from hierarchy
- unlimited category depth
- canonical path resolution
- optional aliases/flattening can be represented as data later
- no A4K path exception in source code

---

# 4. Dynamic field/schema support

Extend `series_custom_field` additively for API/DataTable rendering.

Recommended fields:

- `unit`
- `is_filterable`
- `is_sortable`
- `is_table_column`
- `filter_type`
- `config_json`
- optional `group_key`
- optional `group_label`

Existing properties remain important:

- `field_key`
- `label`
- `field_type`
- `field_scope`
- `default_value`
- `sort_order`
- `is_required`
- `is_public_portal_hidden`
- `is_backend_portal_hidden`

The public DataTable schema is generated from these records.

No column names are hard-coded in the controller.

---

# 5. Generic content blocks

The current scalar metadata model is not enough for all series-detail sections.

Add a generic content-block model, for example:

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

Owner can be:

- catalog root
- category
- series

Reusable block types may include:

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

Do not create product-specific block types such as `a4k_environmental`.

Sub-navigation is generated from available public content blocks and their order.

---

# 6. Generic asset model

Add a normalized asset model, for example:

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

Reusable roles can cover:

- primary image
- thumbnail
- gallery image
- hero visual
- 3D model
- performance curve
- dimension drawing
- PCB layout
- tape/reel drawing
- soldering/reflow curve
- datasheet
- brochure

Actual role records come from imported/admin-managed data.

---

# 7. Generic collections

The `/products` Latest Release area should be data-driven.

Use a generic collection concept, for example:

```
catalog_collection
catalog_collection_item
```

This allows imported/admin-managed ordering of:

- latest releases
- featured series
- featured categories
- future sliders/collections

Do not determine "latest release" only from database ID.

---

# 8. Public API V1

Base:

```
/api/v1/catalog
```

## Root

```
GET /api/v1/catalog
```

Supports the `/products` composition.

## Tree

```
GET /api/v1/catalog/tree
```

Public-safe hierarchy.

## Resolve

```
GET /api/v1/catalog/resolve/{path}
```

Examples after actual data is imported:

```
/api/v1/catalog/resolve/general
/api/v1/catalog/resolve/general/emc
/api/v1/catalog/resolve/general/emc/a4k
```

## Category detail

```
GET /api/v1/catalog/categories/{path}
```

Generic category resource for pages such as:

- `/products/general`
- `/products/general/emc`

## Series detail

```
GET /api/v1/catalog/series/{path}
```

Generic series resource for pages such as A4K.

## Series fields

```
GET /api/v1/catalog/series/{path}/fields
```

Returns field/schema definitions from imported data.

## Series parts

```
GET /api/v1/catalog/series/{path}/parts
```

Supports:

- `page`
- `per_page`
- `search`
- `sort`
- `direction`
- `filter[field_key]`

## Facets

```
GET /api/v1/catalog/series/{path}/facets
```

Generated only from imported fields marked filterable.

## Search

```
GET /api/v1/catalog/search?q=`
```

Searches public:

- category/family
- series
- product/part number

---

# 9. Dynamic EMC/family summary tables

Family tables must be schema-driven.

Generic configuration concept:

```json
{
  "view": "series_summary_table",
  "columns": [
    {
      "key": "some_field",
      "source": "series.metadata.some_field",
      "label": "Label supplied by data"
    }
  ]
}
```

The implementation understands generic sources/types.

It does not contain a fixed list such as:

- Dimension
- Impedance Range
- DCR Range

Those values must come from the imported schema/configuration.

---

# 10. Recursive hierarchy requirement

The existing SpecSearch logic includes shallow assumptions in some queries.

The V1 public API must support:

```
category
└── category
    └── category
        └── series
```

and deeper structures without controller changes.

Use recursive repository logic or recursive CTEs according to supported DB versions.

---

# 11. Public visibility

A public field where:

```
is_public_portal_hidden = 1
```

must not appear in:

- field schemas
- product values
- facets
- search
- summary tables

Likewise:

- non-public content blocks are excluded
- non-public assets are excluded
- unpublished entities are excluded if publication status is added

No private value may be leaked merely because the underlying product has a stored value.

---

# 12. Pagination/filter/sort

The parts endpoint must perform real server-side pagination.

Do not fetch a fixed large result and paginate in the browser.

Example shape:

```
GET /api/v1/catalog/series/{actual-path}/parts
?page=1
&per_page=25
&search={actual-part-number}
&sort={actual-sortable-field}
&direction=asc
&filter[{actual-filterable-field}][]={actual-value}
```

Allowed sort/filter fields are derived from imported field definitions.

Unknown/private/non-filterable/non-sortable keys must return validation errors.

---

# 13. Existing compatibility

Do not break:

- legacy `catalog.php?action=...`
- existing catalog API
- existing SpecSearch API
- CSV import/export
- Typst
- existing media behaviour
- existing Laravel bridge behaviour

The new V1 public API is isolated under:

```
/api/v1/catalog/*
```

---

# 14. Testing policy

## Before actual catalog import

Automated engineering tests verify behaviour using isolated temporary generic test records only.

Those tests verify:

- recursive hierarchy
- slug stability
- schema generation
- public visibility
- content-block ordering
- asset ordering
- collection ordering
- pagination
- search
- filtering
- sorting
- validation
- no product-specific code path

Temporary test records are created/destroyed by the test suite.

They are not production seed data.

## After implementation

The user imports actual catalog data.

Then acceptance testing uses:

```
docs/api-plan/public-product-api-v1-test-variables.json
```

The user fills in the actual imported values.

Examples:

- actual General path
- actual EMC path
- actual A4K path
- actual part number
- actual sortable field
- actual filterable field
- actual expected field count
- actual document/image expectations

No expected catalog values are invented by the implementation.

---

# 15. Real-data acceptance checks

After import, verify:

## /products

- imported top-level sections appear
- actual hierarchy/order matches data
- Latest Release comes from actual collection data
- no missing/extra fabricated records

## /products/general

- actual child sections appear
- actual images/descriptions/navigation appear
- ordering matches imported data

## /products/general/emc

- actual families appear
- each family uses its own actual summary schema
- actual series rows appear
- actual document links appear only where configured
- no fixed EMC column assumptions

## A4K

Using the actual imported A4K data:

- correct breadcrumb
- correct series identity
- correct actual status/compliance
- actual images/assets
- actual optional 3D asset
- actual public content sections
- actual specification columns
- actual part numbers
- actual filter values
- actual sorting
- actual pagination
- actual downloads
- hidden/private fields absent

---

# 16. No-hard-code proof

The strongest proof will come from real imported data.

After the first real series works:

1. import another real series with different fields
2. do not change API source code
3. call the same generic endpoints
4. confirm the field schema changes automatically
5. confirm its product rows use its own fields
6. confirm its content/assets render from its own records

Acceptance condition:

```
new imported series
+ different schema/content/assets
+ zero controller/service code changes
= PASS
```

---

# 17. Implementation sequence after plan approval

1. Add persistent slug/path support.
2. Add dynamic field presentation/filter/sort metadata.
3. Add generic content blocks.
4. Add generic assets.
5. Add generic collections.
6. Build recursive hierarchy/path resolver.
7. Build public catalog services/repositories.
8. Build `/api/v1/catalog/*` read endpoints.
9. Build server-side field-driven parts query.
10. Add public visibility enforcement.
11. Add automated generic behaviour tests.
12. Add API documentation.
13. User imports actual catalog data.
14. Fill actual verification variables.
15. Verify all four pages with real data.
16. Only after acceptance, integrate into the Laravel website and decide Passport/public authentication policy.

---

# Definition of done

The repository-first API is ready for Laravel integration only when:

- all four approved page structures are representable
- no invented catalog seed data is required
- no A4K-specific API code exists
- no EMC-specific API code exists
- no fixed product field list exists
- hierarchy depth is dynamic
- field schema is dynamic
- content sections are dynamic
- assets are dynamic
- family summary schemas are dynamic
- collection ordering is dynamic
- server-side pagination/filter/sort works
- public visibility is enforced
- actual imported A4K data passes
- at least one other actual imported series works without source-code changes
- legacy behaviour remains functional
