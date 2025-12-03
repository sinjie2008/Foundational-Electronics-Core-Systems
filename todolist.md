# Task Tracker

- Context reset: re-read spec.md/api.md updates for Typst badge insertion; no open TODOs carried forward.

## Tasks
1) **Update spec.md with architecture + diagrams** - Status: Done. Test approach: manual review plus optional check `if (-not (Select-String -Path .\docs\spec.md -Pattern '```mermaid' -Quiet)) { throw "docs/spec.md must contain Mermaid diagrams." }`.
2) **Draft api.md for REST endpoints** - Status: Done. Test approach: manual verification against `public/api/*` handlers and ensure error envelope examples present.
3) **Write AGENTS.md contributor guide** - Status: Done. Test approach: manual proofreading for clarity and alignment with repository conventions once drafted.
4) **Add line-numbered editor wrapper to Global Typst template page** - Status: Done. Test approach: manual UI check in browser ensuring numbers sync with scroll/input.
5) **Add line-numbered editor wrapper to Series Typst template page** - Status: Done. Test approach: manual UI check in browser ensuring numbers sync with scroll/input.
6) **Fix PDF download for saved Typst templates when no stored URL** - Status: Done. Test approach: manual check on global_typst_template.html saved templates table ensuring PDF Download triggers compile+opens.
7) **Enable PDF download button even when no prior URL exists** - Status: Done. Test approach: ensure button stays enabled when Typst content exists and triggers compile-then-open flow.
8) **Sanitize Typst data header keys for series templates** - Status: Done. Test approach: `php -l app/Typst/TypstService.php`; manual compile on `series_typst_template.html?series_id=<id>` with custom fields containing dots/unsafe characters to confirm PDF renders.
9) **Document sidebar nav standardization in spec/api** - Status: Done. Test approach: manual doc review ensuring new navigation note present.
10) **Align sidebar links across operator UIs** - Status: Done. Test approach: manual HTML review plus ripgrep to confirm href targets (spec-search, catalog UI, catalog CSV, global Typst, series Typst) with no dead links.
11) **Persist Typst PDF path when saving series templates (Save PDF / Save & Compile)** - Status: Completed (manual UI verify recommended). Test approach: manual flow on `series_typst_template.html?series_id=<id>` to compile+save then verify `typst_templates.last_pdf_path` updates; `php -l` on touched PHP files and smoke compile to confirm URL+path returned.
12) **Document Typst badge insertion behavior (series)** - Status: Done. Test approach: manual doc review ensuring spec.md/api.md describe badge key-only rendering and insertion tokens.
13) **Series Typst badges show Typst-safe keys and paste tokens into editor** - Status: Completed. Test approach: `node --check public/assets/js/series-typst-templating.js`; manual UI check on `series_typst_template.html?series_id=<id>` still recommended to confirm badge insertion + compile preview.
14) **Group series custom-field badges under products wrapper** - Status: Done. Test approach: manual UI check on `series_typst_template.html?series_id=<id>` to confirm outer products badge inserts loop scaffold, inner badges insert `product.attributes.<key>` and compile still works.
15) **Add product name/sku badges to series custom fields** - Status: Done. Test approach: manual UI check on `series_typst_template.html?series_id=<id>` ensuring `product.sku` and `product.name` badges appear inside products wrapper and insert correct tokens.
