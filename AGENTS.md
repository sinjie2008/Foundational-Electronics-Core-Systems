# Repository Guidelines

## Project Structure & Module Organization
- `app/` service layer (Catalog, SpecSearch, Latex, Typst) plus Support utilities (Config, Db, Logger, Request/Response).
- `public/api/` PHP endpoints (catalog, spec-search, latex, typst, series); `public/` also hosts operator UIs and published PDFs under `public/storage`.
- `assets/` contains UI JS/CSS; `scripts/` holds SQL/bootstrap helpers; `storage/` contains CSV imports, build artifacts, and logs.
- Documentation lives in `docs/spec.md` (with Mermaid diagrams) and `docs/api.md`; task tracking in `todolist.md`.

## Build, Test, and Development Commands
- `.\scripts\run-tests.ps1 [-SkipSeed]` — runs seed verification and API smoke tests (PowerShell required).
- `php -S localhost:8000 -t public` — lightweight local server for the static UIs/API routing (align host/port with DB config).
- `php scripts/run_typst_migrations.php` / `php scripts/run_sql.php` — set up Typst/other tables after DB credentials are configured.
- Typst/LaTeX binaries: ensure `bin/typst.exe` or `typst` is on PATH; `pdflatex` location comes from env `CATALOG_PDFLATEX_BIN`.

## Coding Style & Naming Conventions
- PHP strict_types everywhere; 4-space indent; single-responsibility services with constructor-injected `mysqli`.
- Function-level docblocks mandatory; comment important variables/objects; avoid magic values by using `config/*.php`.
- Log at service boundaries with correlation IDs (`Request::correlationId()` / `Response::success/error`); never log secrets or PII.
- Prefer descriptive names by domain (`seriesId`, `field_key`, `pdfUrlPrefix`); keep JSON keys snake_case for payloads.

## Testing Guidelines
- Tests live in `tests/*.php` (plain PHP assertions). Add focused unit/integration tests per change and keep runtimes fast.
- Run `.\scripts\run-tests.ps1` before merging; seed validation should remain green unless intentionally skipped for docs-only edits.
- For new endpoints or flows, add minimal API contract tests mirroring the Response envelope (success/error + correlationId).

## Documentation & Task Flow
- Before coding, update `docs/spec.md` (ensure all Mermaid diagrams present) and `docs/api.md`; record open questions/decisions.
- Break work into independently shippable items in `todolist.md`, mark statuses, and note the test approach per task.
- Keep specs linked from code/PR descriptions so reviewers can trace rationale.

## Commit & Pull Request Guidelines
- Use short, imperative commit subjects with a scope when helpful (e.g., `catalog: tighten search bounds`, `docs: refresh api spec`); wrap at ~72 chars.
- PRs should describe intent, key changes, tests run, and reference related tasks/issues; include screenshots or sample payloads for UI/API changes.

## Security & Configuration Tips
- Set DB credentials in `config/db.php` (or `db_config.php` fallback). Rotate truncate token/lock in `config/app.php` for destructive operations.
- Generated PDFs are publicly reachable under `/storage/*`; avoid writing secrets there. Keep `storage/logs/app.log` trimmed via rotation settings.
