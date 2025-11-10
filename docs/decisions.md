# Decisions & Q&A Log

## 2025-10-29
- Seed series-specific fields directly via initial database seed (confirmed with stakeholder).
- Single PHP page must handle hierarchy and product management; no authentication required.
- Product fields are plain text for now; create/edit operations supported.
- UI HTML/jQuery split into standalone `catalog_ui.html` to reduce complexity in PHP backend.
- Added `catalog_ui.css` for presentation after stakeholder requested visual separation.
- Added catalog-wide search endpoint with dynamic series field filters to support advanced queries.
- Developer requested relocation of static resources into `/assets`; confirmed CSS (`catalog_ui.css`) and jQuery logic will reside under `/assets/css` and `/assets/js` respectively, with HTML updated to reference local copies.
- Refactor approved to convert procedural PHP implementation into OOP classes: introduce `CatalogApplication`, service classes (Hierarchy, SeriesField, Product, Search), `DatabaseFactory`, `Seeder`, and `HttpResponder`; all request handlers will be mapped to class methods with clear ownership.
- Stakeholder requested CSV import/export (single canonical format); imports must rebuild hierarchy while updating/merging products by SKU and pruning stale records, exports must mirror same columns, and all CSV files are timestamped under `storage/csv` with UI support for download/delete history entries.

## 2025-10-31
- Stakeholder clarified that each series has its own metadata fields that describe the series entity, distinct from product-level fields used during product data entry.
- Products must continue to rely on dynamic custom attributes (no fixed schema), so field management now requires scope awareness (`series_metadata` vs `product_attribute`).
- Series metadata should be editable via dedicated UI/API flows; specification and API are updated to introduce `SeriesAttributeService`, new actions (`v1.getSeriesAttributes`, `v1.saveSeriesAttributes`), and scope parameters on existing field endpoints.
- CSV import/export must round-trip both series metadata (`series_meta.<key>`) and product attributes (`product.<key>`).

## 2025-11-10
- Developer reported that the series metadata editor behaved as if only the three seeded fields were available; clarified that the UI must expose creation/editing controls directly within the metadata pane.
- Decision: add a metadata-only field-definition form under the metadata section, wired to `v1.saveSeriesField` with `fieldScope = series_metadata`, and update documentation to reflect unlimited metadata fields per series.
- Follow-up: a race allowed metadata responses from the previously selected series to overwrite the active selection; UI/backend must scope lookups by `seriesId` and discard stale results to guarantee each series only surfaces its own metadata.

## 2025-11-12
- Stakeholder requested a public JSON API that aggregates categories, series, metadata, product labels, and product data in one response for publishing.
- Decision: introduce `PublicCatalogService` plus `v1.publicCatalogSnapshot` (GET) to pull a synchronized snapshot by composing hierarchy, field definitions, series metadata values, and products; response remains read-only and unauthenticated.
