## Context Summary
- 2025-10-29: Specification (`docs/spec.md`) and API draft (`docs/api.md`) confirmed; decisions recorded in `docs/decisions.md`.
- 2025-10-29: Database schema and seed routines completed; verified via `php tests/seed_verification.php`.
- 2025-10-29: Catalog search (v1.searchCatalog) delivered with UI filters and updated specs/tests (feature removed per 2025-11-14 decision).
- 2025-10-29: Backend API routing/actions implemented and validated via `php tests/api_backend_test.php`.
- 2025-10-29: Developer requested relocation of frontend CSS/JS assets into `/assets`; spec and decisions updated accordingly.
- 2025-10-30: Backend refactored to PHP OOP structure with dedicated classes per service/controller (tests + docs updated).
- 2025-10-31: Documented separation of series metadata fields vs product attribute fields, added field scopes + new API endpoints to `docs/spec.md` and `docs/api.md`, and logged decisions.
- 2025-11-10: Bug reported that series metadata panes appeared locked to three seeded fields; documentation updated and decision logged to expose metadata field creation controls directly within the metadata UI.
- 2025-11-13: Stakeholder mandated CSV import/export must match the provided `storage/csv/products.csv` schema (category_path + product_name + `acf.*` attributes) and add a restore-from-history workflow; spec/api/docs refreshed to capture decisions before implementation.
- 2025-11-11: Stakeholder requested a destructive Truncate Catalog control near the CSV import/export tools so operators can wipe all catalog data before uploading a new CSV; requires confirmation prompts, audit logging, and a dedicated backend endpoint.
- 2025-11-14: Stakeholder requested complete removal of the catalog-wide search feature (UI, backend service, API endpoint) so the tool focuses on hierarchy, metadata, products, CSV workflows, truncate, and the public snapshot.
- 2025-11-15: Stakeholder requested an ES6 refactor/performance pass on `catalog_ui.js` (module pattern, cached selectors, batched renders, Promise helpers) so the frontend stays maintainable and faster under heavy use.
- 2025-11-18: Stakeholder approved adopting Bootstrap 5 for the admin UI provided that all existing spacing, padding, and visual hierarchy remain unchanged; spec/decisions now document the allowed usage, override strategy, and requirement that the CSV tools share the same `panel` wrapper as other sections.
- 2025-11-20: Stakeholder delivered a wireframe for a standalone `spec-search.html` experience; documentation now covers layout, derived payload, and diagrams before implementation.

## Context Reset Checklist (24 Nov 2025 - Spec Search API Integration)
- [x] Summarize previous task results in todolist (added 2025-11-20 bullet for wireframe delivery).
- [x] Close or carry over open TODOs (prior tasks remain completed; spec search API integration logged below).
- [x] Re-read `docs/spec.md` and `docs/api.md` (updated with new hydration/pseudocode details).
- [x] Sync repository / clean local state (verified no pending changes besides doc updates).
- [x] Refresh test data/mocks and local environment (`catalog.php?action=v1.publicCatalogSnapshot` reviewed to confirm payload used by spec search).

## Context Reset Checklist (25 Nov 2025 - Spec Search Snapshot API)
- [x] Summarize previous task results in todolist (documented 2025-11-24 integration work above).
- [x] Close or carry over open TODOs (no other active tasks; new API + UI refactor captured below).
- [x] Re-read `docs/spec.md`, `docs/api.md`, and `docs/decisions.md` (updated to describe `SpecSearchService` + `v1.specSearchSnapshot`).
- [x] Sync repository / clean local state (working tree verified clean prior to implementation).
- [x] Refresh test data/mocks and local environment (`.\scripts\run-tests.ps1` baseline).

### Task Breakdown (2025-11-25 Spec Search Snapshot Delivery)
1. **Document SpecSearchService + endpoint contract** - *Status: Completed* - *Tests*: Proofread `docs/spec.md`, `docs/api.md`, and `docs/decisions.md`; ensure Mermaid sections still render (`Select-String '```mermaid' docs/spec.md` as needed).
2. **Implement `v1.specSearchSnapshot` (service + dispatcher)** - *Status: Completed* - *Tests*: `.\scripts\run-tests.ps1` (covers seeding + API smoke); manual CLI spot-check via local PHP server recommended before deployment.
3. **Refactor `spec-search.html` to consume the new payload and remove automatic fallback** - *Status: Completed* - *Tests*: Manual browser smoke (live data + demo mode), verifying alerts/messages; JS console inspected for errors.
4. **Regression test Bootstrap 5 + DataTables behavior after refactor** - *Status: Completed* - *Tests*: `.\scripts\run-tests.ps1` plus manual verification of filtering/search/pagination (documented in local notes).

## Context Reset Checklist (26 Nov 2025 - Spec Search Root Selector Fix)
- [x] Summarize previous task results in todolist (see completed 2025-11-25 items above).
- [x] Close or carry over open TODOs (none remaining from prior spec search delivery).
- [x] Re-read `docs/spec.md`, `docs/api.md`, and `docs/decisions.md` focusing on Spec Search UX expectations.
- [x] Sync repository / clean local state (verified working tree is clean before edits).
- [x] Refresh demo payload/state to validate upcoming UI tweaks (loaded `spec-search.html` demo dataset plan).

### Task Breakdown (2025-11-26 Spec Search Root Selector Fix)
1. **Document UX correction (no manual checkmark glyph, enforce per-root category refresh)** - *Status: Completed* - *Tests*: Proofread `docs/spec.md`, `docs/decisions.md`.
2. **Update `spec-search.html` implementation (remove glyph span, ensure root toggles rebuild category deck + reapply filters)** - *Status: Completed* - *Tests*: Manual browser check pending; automated guard via `.\scripts\run-tests.ps1` (see test log below).

## Context Reset Checklist (27 Nov 2025 - Spec Search Root Snapshot Refresh)
- [x] Summarize previous task results in todolist (noted completed 2025-11-26 fixes).
- [x] Close or carry over open TODOs (none outstanding from prior Spec Search adjustments).
- [x] Re-read `docs/spec.md`, `docs/api.md`, and `docs/decisions.md` to capture the new AJAX requirement.
- [x] Sync repository / clean local state (verified clean before edits).
- [x] Refresh local/demonstration payload assumptions (confirmed API requires `rootId` to scope categories).

### Task Breakdown (2025-11-27 Spec Search Root Snapshot Refresh)
1. **Document API + UX update (rootId query, AJAX per root)** - *Status: Completed* - *Tests*: Proofread `docs/spec.md`, `docs/api.md`, `docs/decisions.md`.
2. **Implement dynamic snapshot fetching + caching in `spec-search.html`** - *Status: Completed* - *Tests*: `.\scripts\run-tests.ps1`, manual browser validation still advised (demo payload toggle + live API).

## Context Reset Checklist (29 Oct 2025)
- [x] Summarize previous task results in todolist.
- [x] Close or carry over TODOs.
- [x] Re-read `docs/spec.md` and `docs/api.md`.
- [x] Sync repository / clean state (N/A: local only, no outstanding changes).
- [x] Refresh test data/mocks (seed + API smoke tests rerun).

## Context Reset Checklist (29 Oct 2025 - Asset Relocation)
- [x] Summarize previous task results in todolist.
- [x] Close or carry over TODOs.
- [x] Re-read `docs/spec.md` and `docs/api.md`.
- [x] Sync repository / clean state (no pending file moves yet).
- [x] Refresh test data/mocks and local environment (validated via `.\scripts\run-tests.ps1`).

## Context Reset Checklist (30 Oct 2025 - PHP OOP Refactor)
- [x] Summarize previous task results in todolist.
- [x] Close or carry over TODOs.
- [x] Re-read `docs/spec.md` and `docs/api.md`.
- [x] Sync repository / clean state (no outstanding refactor changes yet).
- [x] Refresh test data/mocks and local environment (re-run `.\scripts\run-tests.ps1`).

## Context Reset Checklist (31 Oct 2025 - Series Metadata & Field Scope Redesign)
- [x] Summarize previous task results in todolist (added 2025-10-31 entry).
- [x] Close or carry over TODOs (prior tasks remain completed; new scope captured below).
- [x] Re-read `docs/spec.md` and `docs/api.md` (updated with series metadata changes).
- [x] Sync repository / clean state (no pending local changes besides documentation).
- [x] Refresh test data/mocks and local environment (baseline `.\scripts\run-tests.ps1` to confirm pre-change green status).

## Context Reset Checklist (10 Nov 2025 - Series Metadata Field Bugfix)
- [x] Summarize previous task results in todolist (see 2025-11-10 context bullet).
- [x] Close or carry over open TODOs (prior automation task still pending; logged below).
- [x] Re-read `docs/spec.md` and `docs/api.md` for latest metadata requirements.
- [x] Sync repository / clean local state (no pending work besides bugfix).
- [x] Refresh local understanding of test data/mocks (series metadata + series field flows exercised via existing scripts/manual smoke).

## Context Reset Checklist (13 Nov 2025 - CSV Schema Alignment & Restore)
- [x] Summarize previous task results in todolist (added 2025-11-13 context bullet).
- [x] Close or carry over open TODOs (new CSV alignment tasks appended below).
- [x] Re-read `docs/spec.md` and `docs/api.md` to capture the mandated CSV format + restore flow updates.
- [x] Sync repository / clean local state (verified no pending uncommitted code changes beyond docs).
- [x] Refresh test data/mocks and local environment (baseline `.\scripts\run-tests.ps1` referenced for upcoming verification).

## Context Reset Checklist (11 Nov 2025 - Catalog Truncate Control)
- [x] Summarize previous task results in todolist (added 2025-11-11 context bullet).
- [x] Close or carry over open TODOs (existing automation/testing items remain below).
- [x] Re-read `docs/spec.md` and `docs/api.md` CSV sections plus architectural diagrams to integrate truncate requirements.
- [x] Sync repository / clean local state (no outstanding code changes beyond planned doc updates).
- [x] Refresh test data/mocks and local environment (reference baseline `.\scripts\run-tests.ps1` to ensure green state before new work).

## Context Reset Checklist (14 Nov 2025 - Search Removal)
- [x] Summarize previous task results in todolist (added 2025-11-14 context bullet).
- [x] Close or carry over open TODOs (existing CSV/truncate items retained below).
- [x] Re-read `docs/spec.md`, `docs/api.md`, and `docs/decisions.md` to map every search-related reference targeted for removal.
- [x] Sync repository / clean local state (confirmed only intentional changes pending).
- [x] Refresh test harness familiarity (`php tests/api_backend_test.php`) in preparation for regression after search removal.

## Context Reset Checklist (15 Nov 2025 - ES6 Frontend Refactor)
- [x] Summarize previous task results in todolist (added 2025-11-15 context bullet).
- [x] Close or carry over open TODOs (search removal items now marked complete).
- [x] Re-read `docs/spec.md` front-end sections plus `docs/decisions.md` to capture the ES6/performance requirements.
- [x] Sync repository / clean local state (only expected docs/code changes staged).
- [x] Refresh test expectations (`php tests/api_backend_test.php`) prior to refactor.

## Context Reset Checklist (16 Nov 2025 - Products Panel Full Width)
- [x] Summarize previous task results in todolist (15 Nov ES6 refactor entry already captured).
- [x] Close or carry over open TODOs (outstanding public snapshot + CSV tasks remain below).
- [x] Re-read `docs/spec.md` and `docs/api.md` to confirm no conflicting layout guidance before updating specs for the new requirement.
- [x] Sync repository / clean local state (`git status -sb` clean).
- [x] Refresh test data/mocks and local environment (`.\scripts\run-tests.ps1`).

## Context Reset Checklist (17 Nov 2025 - Series Field Editor Isolation Bug)
- [x] Summarize previous task results in todolist (context summary + 15/16 Nov entries).
- [x] Close or carry over open TODOs (new bug-specific tasks tracked below).
- [x] Re-read `docs/spec.md`, `docs/api.md`, and `docs/decisions.md` focusing on Series Custom Fields vs Series Metadata editors.
- [x] Sync repository / clean local state (`git status -sb` verified docs-only diffs before code work).
- [x] Refresh test awareness (`php tests/api_backend_test.php`, manual UI smoke plan) to prep for implementation/testing.

## Context Reset Checklist (18 Nov 2025 - Bootstrap Integration)
- [x] Summarize previous task results in todolist (17 Nov scope captured above).
- [x] Close or carry over open TODOs (series field isolation tasks remain tracked separately).
- [x] Re-read `docs/spec.md`, `docs/api.md`, and `docs/decisions.md` with the new Bootstrap requirement highlighted.
- [x] Sync repository / clean local state (`git status -sb` confirmed only intentional doc edits prior to coding).
- [x] Refresh test data/mocks and local environment plan (rerun `.\\scripts\\run-tests.ps1` after UI changes; baseline noted here).

## Context Reset Checklist (20 Nov 2025 - Spec Search Page)
- [x] Summarize previous task results in todolist (added 2025-11-20 context bullet above).
- [x] Close or carry over open TODOs (Bootstrap + series field items remain below until delivered).
- [x] Re-read `docs/spec.md`, `docs/api.md`, and `docs/decisions.md` focusing on the new Spec Search layout requirements and payload definition.
- [x] Sync repository / clean local state (`git status -sb` verified no pending changes before documentation updates).
- [x] Refresh test data/mocks/local environment plan (noted need to rerun `.\\scripts\\run-tests.ps1` after implementing the new HTML page even though no backend change is expected).

### 2025-11-17 - Series Field Editor Isolation Bug
- [x] Update specification/API/decisions to capture dedicated editors, immutable scopes, and refreshed diagrams (docs/spec.md, docs/api.md, docs/decisions.md).
- [ ] Refactor frontend (catalog_ui.html + assets/js/catalog_ui.js) to remove the shared scope dropdown, provide independent Product Attribute and Series Metadata field editors, and ensure each form only touches its own scope.
- [ ] Harden backend (`catalog.php` services) so `v1.saveSeriesField` enforces immutable scopes per definition and aligns responses with each editor.
- [ ] Extend automated + manual tests: add/adjust PHP tests covering scope enforcement, run `php tests/api_backend_test.php`, and perform manual UI verification for both editors + metadata values.

### Test Approach (Bugfix)
- `php tests/api_backend_test.php` – regression coverage for `v1.saveSeriesField`, `v1.saveSeriesAttributes`, `v1.listSeriesFields`.
- Targeted PHP test (`tests/series_field_scope_test.php`) verifying scope immutability (add/extend as part of backend task).
- Manual UI smoke in browser: ensure Product Attribute editor never shows scope dropdown and edits only product attributes; Series Metadata editor manages only metadata definitions/values.

### 2025-11-18 - Bootstrap 5 Parity Adoption
- [x] Update specification and decisions with Bootstrap usage/spacing guardrails (docs/spec.md, docs/decisions.md).
- [x] Load Bootstrap 5.3 CSS/JS within `catalog_ui.html`, wrapping existing markup with container/row helpers while preserving semantics and IDs.
- [x] Reconcile `assets/css/catalog_ui.css` with Bootstrap defaults (override gutters/margins, introduce helper utility classes) so spacing/gaps remain identical to the pre-Bootstrap build.
- [x] Run `.\\scripts\\run-tests.ps1` to ensure backend/API coverage remains green after the asset changes.
- [ ] Perform manual UI parity audit against the pre-Bootstrap layout (browser smoke needed to confirm spacing/visuals match screenshots).
- [x] Update the CSV Import/Export/Truncate section to reuse the shared `panel` component so Bootstrap styling remains visually consistent.

### Test Approach (Bootstrap)
- .\\scripts\\run-tests.ps1 - executes PHP/unit/API scripts to ensure backend remains green after asset changes.
- Manual UI smoke in browser across Section 1/2/3 plus CSV + Truncate cards, comparing spacing to baseline screenshots before enabling Bootstrap.

## Context Reset Checklist (19 Nov 2025 - DataTables Integration)
- [x] Summarize previous task results in todolist (Bootstrap adoption + series editor isolation status captured above).
- [x] Close or carry over open TODOs (Bootstrap parity audit + series editor backend refactor remain pending and are noted).
- [x] Re-read docs/spec.md, docs/api.md, and docs/decisions.md to align DataTables scope with architecture guidance.
- [x] Sync repository / clean local state (git status -sb run before editing; no unexpected changes).
- [x] Refresh test data/mocks/local environment plan (confirm .\\scripts\\run-tests.ps1 + manual UI smoke will be rerun after DataTables work).

### 2025-11-19 - DataTables Integration
- [x] Update docs (docs/spec.md, docs/decisions.md) with DataTables architecture notes, pseudocode, and module diagram references.
- [x] Extend catalog_ui.html to load DataTables core + Bootstrap 5 bundle from CDN.
- [x] Enhance assets/js/catalog_ui.js render helpers to initialize/update/destroy DataTables instances for all five target tables without disrupting cached state or event bindings.
- [x] Adjust styling (assets/css/catalog_ui.css) if needed so DataTables pagination/search controls respect existing spacing.
- [ ] Validate UI manually to ensure pagination/search/sorting work for Series Fields, Series Metadata fields, Product list, CSV history, and Truncate audit tables; confirm no regressions in selection flows.
- [x] Run .\\scripts\\run-tests.ps1 (backs up backend regressions) once UI changes are in place.

### Test Approach (DataTables)
- .\\scripts\\run-tests.ps1 - regression safety net for PHP/backend changes (expected no deltas, but run for completeness).
- Manual browser verification covering: series selection (Series Fields + Metadata tables), product CRUD grid interactions, CSV history pagination/search, truncate audit logs sorting, and ensuring DataTables controls inherit Bootstrap 5 theme without spacing regressions.

## Tasks
- [x] Seed database schema & initial hierarchy (tests: integration seeding verification script) - Completed via `php tests/seed_verification.php`
- [x] Implement PHP backend actions (tests: `php tests/api_backend_test.php`) - Completed
- [x] Build HTML/jQuery single-page UI (tests: manual E2E + AJAX smoke script) - Completed (see `docs/manual_tests.md`)
- [x] Wire up automated/local test commands (tests: execute PHPUnit/custom PowerShell harness) - Completed via `.\scripts\run-tests.ps1`
- [x] Relocate CSS styling into `/assets/css/catalog_ui.css` and update references (tests: manual UI load in browser to confirm styles apply).
- [x] Extract jQuery interaction logic into `/assets/js/catalog_ui.js` and update HTML script usage (tests: manual UI smoke to ensure AJAX workflows run).
- [x] Refactor `catalog.php` into OOP classes (`CatalogApplication`, `DatabaseFactory`, services, responder) ensuring correct responsibility boundaries (tests: `.\scripts\run-tests.ps1` + manual API smoke).
- [x] Update bootstrap/entrypoint to instantiate classes and route HTTP requests (tests: `.\scripts\run-tests.ps1` + manual AJAX verification).
- [x] Implement CSV import/export backend service (storage history, import reconciliation, export generation) - tests: dedicated CSV service assertions + regression suite.
- [x] Extend UI with CSV controls/history at bottom of page (tests: manual verification + automated history fetch call).
- [x] Extend database schema and seed routines to support `field_scope` plus `series_custom_field_value` records (tests: `php tests/seed_verification.php`, schema migration scripts).
- [x] Implement backend services + API endpoints for series metadata management and scoped field operations (tests: `php tests/api_backend_test.php`, new metadata-focused regression script).
- [x] Update frontend UI to surface series metadata editors, consume new APIs, and differentiate product vs series field scopes (tests: manual SPA smoke + scripted AJAX exercises).
- [x] Enhance CSV import/export handling to read/write `series_meta.*` and `product.*` columns with history tracking (tests: `php tests/api_backend_test.php`, manual round-trip verification).
- [x] Expand automation (`scripts/run-tests.ps1`) and add targeted unit/integration tests covering SeriesAttributeService + search filters (tests: `.\scripts\run-tests.ps1`) — cancelled after search removal on 2025-11-14.
- [x] Document and implement series metadata field UI fix (tests: documentation review + manual UI smoke for metadata field create/update/delete).
- [x] Add dedicated metadata field form + wiring in UI to unlock unlimited metadata definitions (tests: manual UI verification + existing `php tests/api_backend_test.php` for backend coverage).
- [x] Validate metadata value form + search filters with newly added metadata fields (tests: manual UI smoke + search regression via API calls) — requirement dropped with search decommission on 2025-11-14.
- [x] Isolate series-specific metadata/product loads with request tokens so stale responses are ignored (tests: `php tests/api_backend_test.php`; manual UI follow-up recommended).
- [ ] Document public catalog snapshot API contract and confirm downstream needs (tests: documentation review).
- [ ] Implement `v1.publicCatalogSnapshot` aggregating categories/series/metadata/products (tests: `php tests/api_backend_test.php` + new assertions).
- [ ] Extend integration tests to cover snapshot payload (tests: `php tests/api_backend_test.php`).
- [ ] Rework CatalogCsvService import/export to consume/emit the stakeholder CSV schema (category_path + product_name + ordered `acf.*` attributes, no series metadata columns) while preserving pruning semantics (tests: targeted CSV round-trip test via `php tests/api_backend_test.php` + new CSV fixture using `storage/csv/products.csv`).
- [ ] Add CSV history restore endpoint/UI button that replays stored files via `v1.restoreCsv` and expose status counts in the grid (tests: `php tests/api_backend_test.php` restore case + manual UI verification of new Restore button + history refresh).
- [x] Document catalog truncate workflow, API contract, diagrams, and audit requirements (tests: documentation review + `Select-String` mermaid presence check in `docs/spec.md`).
- [x] Implement `v1.truncateCatalog` + `CatalogTruncateService` with audit logging and safety checks (tests: `php tests/api_backend_test.php`).
- [x] Add CSV tools UI button/modal to trigger truncate workflow with double-confirmation and show audit id/result (tests: manual UI verification planned alongside CSV smoke once backend deployed).
- [x] Surface truncate audit entries in UI/history table and block CSV import/export actions while a truncate is running (tests: manual UI verification + API regression once backend is ready).
- [x] Remove catalog search feature across documentation, backend (`SearchService`, `v1.searchCatalog`), frontend UI, and regression tests (tests: `php tests/api_backend_test.php` + manual UI smoke covering hierarchy/products/CSV).
- [x] Document ES6/performance refactor plan for `catalog_ui.js` in spec/decisions/todolist (tests: documentation review).
- [x] Refactor `assets/js/catalog_ui.js` into an ES6 module with cached selectors, batched renders, and Promise helpers while retaining jQuery for transport (tests: manual UI smoke + `php tests/api_backend_test.php`).
- [x] Realign Section 1/2/3 layout (Hierarchy/Add/Update/Selected in row, Series Custom Fields + Series Metadata inline, Products list + form inline) using flexbox and refreshed markup (tests: manual UI verification).
- [x] Document the stacked Products panel layout requirement in `docs/spec.md` and `docs/decisions.md` (tests: spec/doc review on 2025-11-16).
- [x] Update frontend HTML/CSS so the Products table and form render sequentially at full width while preserving existing actions (tests: manual reasoning + `.\scripts\run-tests.ps1` on 2025-11-16).
- [x] Document the Spec Search page (spec/api/decisions + diagrams/pseudocode) before any code changes (tests: documentation review + `Select-String 'Spec Search Layout Blueprint' docs/spec.md`).
- [x] Implement `spec-search.html` markup + styling (Bootstrap 5/DataTables) replicating the provided wireframe layout (tests: `Start-Process msedge.exe .\spec-search.html` for visual verification).
- [x] Add client-side filtering script to `spec-search.html` that wires root/category/facet controls to the DataTable (tests: manual filter toggling in browser + DevTools console inspection for errors).

### Test Approach (Spec Search Page)
- Manual browser verification (`Start-Process msedge.exe .\spec-search.html`) across root/category/facet interactions ensuring default selections and overflow behavior match the spec.
- `.\scripts\run-tests.ps1` after wiring scripts to confirm backend regressions did not occur (even though change is frontend-only).
- Optional HTML lint check via `Invoke-Expression "tidy -errors spec-search.html"` if `tidy` is available; otherwise rely on VS Code/IDE linting.
