# Task Tracker

- Context reset: reviewed spec.md/api.md for sidebar link updates; carrying no open TODOs from prior tasks.

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
