Decisions Log
=============

- 2025-11-24: Do not adopt Composer/PSR-4; stay with manual autoload/require.
- 2025-11-24: No special deployment constraints provided (treat as standard PHP hosting with writable `storage/`).
- 2025-11-24: Keep existing CSV/LaTeX flows; wrap them in services without altering behavior.
- 2025-11-24: Frontend must use Bootstrap 5 + DataTables; jQuery usage must be encapsulated in ES6 classes.
- 2025-11-25: Add Bootstrap 5 sidebar navigation panel linking Catalog UI, Spec Search, and LaTeX templating pages for quick cross-page access (AdminLTE-inspired left rail; sticky desktop, slide-over mobile).
- 2025-11-26: Sidebar desktop collapse must mirror AdminLTE: icon-only ~80px rail with no reserved gutter; main content should reclaim space while mobile slide-over stays unchanged.
- 2025-11-26: Sidebar brand icon removed (text-only brand) and collapse button text removed; adopt Meyer reset v2 globally (load before Bootstrap) to normalize spacing.
- 2025-11-27: Remove outer white gutters around sidebar by zeroing row gutters and body padding; main content retains spacing via its own padding.
- 2025-11-27: Sidebar panel anchored top-to-bottom (sticky 100vh on desktop) while preserving mobile slide-over behavior.
- 2025-11-27: Sidebar navigation order standardized to Spec Search, Catalog UI, then LaTeX Templating across all pages.
- 2025-11-28: Spec search results include Edit links that deep-link to catalog UI with category/series/product query params; catalog UI must pre-fill search and select the series accordingly.
- 2025-11-29: Catalog UI must propagate deep-link product query param into the DataTables search input for the product list and trigger filtering automatically after table load.
- 2025-11-28: Spec-search results require an Edit button that deep-links to `catalog_ui.html` with category/series/product query params; catalog UI must parse them, prefill the hierarchy search with the product code, and auto-select the matching series node.
- 2025-11-30: Implement structured JSONL logging in backend (`storage/logs/app.log`) with correlation IDs; honor inbound `X-Correlation-ID`, generate if missing, and include in all responses.
- 2025-11-30: Frontend error handling will surface API errors via shared utility, display user-friendly copy plus correlation ID, and avoid console logging in production (dev-only structured console logs).
- 2025-11-30: Introduce `logging.enabled` flag (backend config, frontend-aware) to disable log writes while still generating/returning correlation IDs for troubleshooting scenarios.
- 2025-11-30: Split CSV import/export + truncate UI into dedicated `catalog-csv.html`; sidebar nav order expands to Spec Search, Catalog UI, CSV Import/Export, LaTeX Templating across all pages.
- 2025-11-26: Series custom fields (product attributes and series metadata) support selectable types text/number/file; file uploads restricted to image/pdf/glb, stored under `storage/media/<category>/<series>/<field-key>/<entity-id>/<original-filename>`, single file per field with replace/clear semantics, original filename preserved; number fields accept decimals but remain stored as text.
- 2025-12-01: Series field definitions capture two new booleans—`Public Portal Hidden` and `Backend Portal Hidden`—alongside `Required`, exposed in both product attribute and series metadata editors and persisted with field definitions for downstream portal visibility control.
