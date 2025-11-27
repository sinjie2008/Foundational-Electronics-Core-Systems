Todolist
========

Legend: Pending | In Progress | Blocked | Done

| Task | Status | Test Approach | Notes/Dependencies |
| --- | --- | --- | --- |
| Draft/Review docs (spec.md with Mermaid diagrams, api.md, decisions) | Done | Manual review; optional `Select-String` check for Mermaid blocks | Required before any code moves |
| Backend OO refactor (controllers in public/, services/repositories in app/, config centralization) | In Progress | Unit tests for services/validation; integration tests hitting DB and endpoints | Depends on docs finalization |
| Frontend update to Bootstrap 5 + DataTables; jQuery via ES6 classes; wire assets to public/assets | In Progress | Browser smoke/E2E for DataTables init and interactions | Depends on backend endpoints stability |
| Series field types (text/number/file) + media storage backend | In Progress | Unit: field type validation, file type/size enforcement, media path builder; Integration: save product + series metadata via JSON and multipart, media download preserves filename | Depends on docs updates (spec/api/decisions); storage/media layout agreed |
| Portal visibility flags on series field definitions (Public Portal Hidden, Backend Portal Hidden) | Done | Manual: create/edit product attribute + metadata fields toggling both flags; reload to confirm persistence; `php -l catalog.php` smoke | Implemented + `php -l catalog.php`; manual UI check recommended |
| Frontend field-type UI + file upload handling | Pending | Browser/E2E: create fields of each type, upload/replace/clear files, verify download link works; Contract: correlation ID surfaced on errors | Depends on backend media endpoints |
| Add Bootstrap 5 sidebar navigation (catalog_ui.html, spec-search.html, latex-templating.html) | Done | Manual browser check: sidebar renders desktop/mobile, links route correctly | AdminLTE-style left rail implemented (sticky desktop, slide-over mobile) |
| Fix sidebar collapse spacing/gap (AdminLTE behavior) | Done | Manual browser check: desktop collapse shrinks to ~80px icon rail, main content expands; mobile slide-over/backdrop unaffected | Uses shared sidebar CSS/JS |
| Remove sidebar brand icon and collapse text; apply global Meyer reset | Done | Browser smoke across catalog/spec/latex pages to confirm layout unaffected; ensure reset loads before Bootstrap | Requires shared CSS + HTML updates |
| Remove sidebar white margin/gutters | Done | Visual check on catalog/spec/latex pages: sidebar flush to viewport, content retains intended padding | Update layout gutters + body padding |
| Remove sidebar top offset and forced min-height | Done | Visual check: sidebar aligns naturally without forced 100vh; sticky + mobile slide-over unchanged | Update shared sidebar CSS |
| CSV/LaTeX service wrap (no behavior change) with logging and correlation IDs | Pending | Unit tests for import validation; integration test for PDF URL generation | Depends on backend refactor |
| Backend structured logging + global error handler (correlation ID propagation) | In Progress | Unit tests for logger/envelope; integration tests hitting representative endpoints to assert logs + correlation IDs | Requires docs/spec/api/decisions updates (ready) |
| Frontend error handling utility (shared toast/banner, dev console structured log) | In Progress | Browser/dev-mode tests; verify DataTables AJAX and custom calls surface correlation IDs and friendly messages | Depends on backend error envelope |
| Logging enable/disable flag wiring (backend config + frontend awareness) | In Progress | Unit test logger no-op when disabled; integration test still returns correlation IDs; dev browser check that console logging is suppressed when off | Depends on logging tasks above |
| Build/test scripts (PowerShell) and test consolidation (unit/integration/contract) | Pending | Run `scripts/run-tests.ps1` (to be updated) | Depends on refactor tasks |
| Clean-up and verify paths (public/, storage/, assets/) and update docs/todolist statuses | Pending | Regression smoke + contract tests | Final step |
| Document spec-search → catalog edit deep link (query params + prefill behaviors) | Done | Manual review; ensure spec/decisions capture assumptions | Pre-req for implementation |
| Implement spec-search Edit button deep link to catalog UI (prefill hierarchy search + auto-select series + product table filter) | Done | Manual browser test: click Edit redirects with query string, catalog UI selects series, fills search box, and DataTables product filter reflects product param | Depends on document updates |
| Document CSV tools page split (spec/api/decisions updates, nav order) | Done | Manual doc review; ensure Mermaid blocks intact and nav ordering captured | Adds dedicated CSV Import/Export page description before coding |
| Implement CSV Import/Export dedicated page + nav updates | In Progress | Browser manual test: CSV history table, export/import/restore/delete flows, truncate modal; confirm correlation IDs surfaced | New catalog-csv.html + navigation updates; tests pending |
