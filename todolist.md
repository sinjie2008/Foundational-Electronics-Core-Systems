## Context Summary
- 2025-10-29: Specification (`docs/spec.md`) and API draft (`docs/api.md`) confirmed; decisions recorded in `docs/decisions.md`.
- 2025-10-29: Database schema and seed routines completed; verified via `php tests/seed_verification.php`.
- 2025-10-29: Catalog search (v1.searchCatalog) delivered with UI filters and updated specs/tests.
- 2025-10-29: Backend API routing/actions implemented and validated via `php tests/api_backend_test.php`.
- 2025-10-29: Developer requested relocation of frontend CSS/JS assets into `/assets`; spec and decisions updated accordingly.
- 2025-10-30: Backend refactored to PHP OOP structure with dedicated classes per service/controller (tests + docs updated).
- 2025-10-31: Documented separation of series metadata fields vs product attribute fields, added field scopes + new API endpoints to `docs/spec.md` and `docs/api.md`, and logged decisions.
- 2025-11-10: Bug reported that series metadata panes appeared locked to three seeded fields; documentation updated and decision logged to expose metadata field creation controls directly within the metadata UI.
- 2025-11-13: Stakeholder mandated CSV import/export must match the provided `storage/csv/products.csv` schema (category_path + product_name + `acf.*` attributes) and add a restore-from-history workflow; spec/api/docs refreshed to capture decisions before implementation.

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
- [ ] Expand automation (`scripts/run-tests.ps1`) and add targeted unit/integration tests covering SeriesAttributeService + search filters (tests: `.\scripts\run-tests.ps1`).
- [x] Document and implement series metadata field UI fix (tests: documentation review + manual UI smoke for metadata field create/update/delete).
- [x] Add dedicated metadata field form + wiring in UI to unlock unlimited metadata definitions (tests: manual UI verification + existing `php tests/api_backend_test.php` for backend coverage).
- [ ] Validate metadata value form + search filters with newly added metadata fields (tests: manual UI smoke + search regression via API calls).
- [x] Isolate series-specific metadata/product loads with request tokens so stale responses are ignored (tests: `php tests/api_backend_test.php`; manual UI follow-up recommended).
- [ ] Document public catalog snapshot API contract and confirm downstream needs (tests: documentation review).
- [ ] Implement `v1.publicCatalogSnapshot` aggregating categories/series/metadata/products (tests: `php tests/api_backend_test.php` + new assertions).
- [ ] Extend integration tests to cover snapshot payload (tests: `php tests/api_backend_test.php`).
- [ ] Rework CatalogCsvService import/export to consume/emit the stakeholder CSV schema (category_path + product_name + ordered `acf.*` attributes, no series metadata columns) while preserving pruning semantics (tests: targeted CSV round-trip test via `php tests/api_backend_test.php` + new CSV fixture using `storage/csv/products.csv`).
- [ ] Add CSV history restore endpoint/UI button that replays stored files via `v1.restoreCsv` and expose status counts in the grid (tests: `php tests/api_backend_test.php` restore case + manual UI verification of new Restore button + history refresh).
