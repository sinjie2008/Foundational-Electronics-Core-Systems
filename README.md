# Catalog Suite

Standalone PHP catalog, specification search, CSV tools and Typst/LaTeX PDF generation. The MVC structure is prepared for later integration into Laravel while retaining existing page and API URLs.

## Media Library

The optional /media_library.html page adds discovery, search, grid/list browsing, previews, physical paths, Catalog links, reusable attachments, batch uploads and reviewed manual/CSV mapping. It uses the existing PHP MVC structure and native JavaScript.

**Standalone concept: enabled without a login or access key.** The media schema still needs the one-time installation below; building assets does not create tables or index files. Existing Catalog values remain path strings. Discovery never moves, renames or deletes files.

### Install and enable

1. Back up the database and managed storage. Verify config/db.php or CATALOG_DB_DATABASE. Test against a disposable copy first.
2. Check the selected database, then explicitly install the six additive media tables:

       php scripts/media-library.php status
       php scripts/media-library.php install --confirm-additive-schema

3. Review the storage roots in config/app.php, then index existing files:

       php scripts/media-library.php index

   Indexing writes media metadata and relationships, prints a report and is repeatable. It reads actual Catalog references, records broken/invalid links and duplicates, and leaves unreferenced files unlinked. Directory names never establish ownership.
4. Media integration is enabled by default for this standalone concept. An explicit CATALOG_MEDIA_LIBRARY_ENABLED=false disables it. Restart the relevant PHP/Apache process after changing its environment; a PowerShell variable alone does not configure Laragon's running Apache.
5. Open Media Library directly and verify existing uploads/downloads, public URLs and compiler outputs. No access key, login form or standalone sign-in is required.

Requests never install the media schema. When enabled without it, mutations fail with MEDIA_NOT_INSTALLED before legacy setup or file writes. Catalog uploads and relationship changes update the index immediately. Files changed outside the application need **Check existing files** in the UI or the CLI command; no cron is required. The UI scan has resumable phases and a downloadable review report.

A future Laravel/session host can add its own login middleware and media_library.authorize in its module settings. The standalone concept grants its shared prototype actor all permissions. The optional callback receives a permission and returns an actor, permissions array and optional csrfToken, or null to deny. Permissions are view, view_paths, upload, attach, replace, trash, index, purge and catalog_write; "*" grants all. Cookie/session hosts should return csrfToken; writes then require X-CSRF-Token. Existing editor mutations require catalog_write, uploads additionally require upload, Library selection attach, and replacement replace.

When configured, the host policy also protects legacy backend writes, template editors and CSV history/downloads. Existing pages use same-origin host session cookies and send the host CSRF token. Public Product API reads, Spec Search (including legacy read-only POST actions) and public product media URLs retain their existing behavior. Standalone concept requests do not send bearer credentials.

### Operator behavior

- Existing forms retain Upload New and gain Select from Media Library. Selection remains a draft until Save. Product/Series file fields accept supported images, PDF and GLB; Category/Typst variables remain image-only.
- Batch uploads show per-file progress/results, duplicate reuse or an explicit separate copy, retry and pending cancellation. Successful files can proceed directly to mapping. Interrupted batches can be reviewed from the same session; retry requires reselecting local files.
- Manual/CSV mapping prefers stable IDs, requires Series context for SKU matching and rejects ambiguous names. Preview shows current values, invalid rows and replacement warnings. The default skips occupied fields.
- Atomic mapping permits up to 100 valid rows. Explicit valid-row mode uses 25-row chunks and durable results. Previews expire after 30 minutes and belong to their operator. Undo restores previous values and absence of override rows, while preserving later edits as conflicts. Catalog reset invalidates earlier mapping operations.
- The Type and Status dropdowns combine with Catalog folder navigation (for example PDF + Unlinked). The left panel searches Category/Series names and Field labels/keys instantly, keeping matching paths expanded. Clearing the search restores the previous folder expansion. Virtual Category/Series/Field folders follow current database relationships. Rename/reparent does not relocate files. New Library uploads use library/UUID/filename; Catalog uploads retain their hierarchy with collision-safe names.
- Detach clears one link. Trash requires zero current references and retains bytes; Restore is explicit. Historical public URLs remain available for compatible public assets in Trash. Permanent deletion is protected because external usage cannot be proved absent. There is no automatic garbage collection or ZIP extraction.
- Missing files and invalid targets remain visible for review. Restore original bytes and reconcile. Changed bytes are quarantined against the original checksum; upload corrected content as a new asset rather than accepting an external overwrite.

Only configured public Catalog media/Typst/PDF roots are eligible. Private CSV, logs, build workspaces and configuration are not library roots. The historical file-API LaTeX output root is outside this repository: exact new outputs are indexed, but broad discovery there requires reviewing the root and enabling media_library.external_roots_enabled. Never register the entire storage directory.

The six additive tables are media_asset, media_alias, media_relationship, media_batch, media_batch_item and media_audit_event. Assets use UUIDs, unique root/path identities and SHA-256; relationships use stable owner/field slots. Services validate business links instead of adding foreign keys to legacy Catalog tables, preserving import/reset behavior. Invalid old links still protect files from deletion. Keep audit/batch history.

Apache root/public .htaccess rules protect private paths, deny executable uploads and route reserved __library__ aliases. Confirm mod_rewrite/AllowOverride or configure equivalent server rules. Uploads validate extension, detected MIME, raster dimensions and GLB headers. Effective size is the minimum of the application limit (10 MiB default), PHP upload_max_filesize and available post_max_size. Previews/downloads use restrictive headers.

Rollback means disabling the feature while retaining its tables and files. Reserved public aliases continue resolving from the index. Detach/replacement retains old bytes even while disabled. Laravel login and host access rules are configured separately from this feature flag.

See [API.md](API.md) for contracts and CSV columns. Desktop uses three panels where practical; tablet/mobile use collapsible, focus-managed folders/details and dialogs.

Implementation verification used temporary databases and redirected media/compiler roots outside this repository. It covered native image/PDF/GLB uploads, shared reuse, stale/atomic/chunked mapping, compensation and default inheritance, missing/changed/unsupported files, upload retries, host authorization/CSRF, real Typst and both LaTeX engines, CSV import/restore/reset, and public API comparisons against the unchanged HEAD. Browser checks covered Library/Catalog at 360, 768, 1024 and 1440 pixels, form selection/save, upload duplicate reuse, reviewed mapping/undo and CSV download. Direct prototype access without a key is checked separately from host authorization. This does not certify the eventual WordPress or Laravel host, its proxy/CORS policy, or every browser's native PDF viewer; verify those before deployment.

## Run

Requirements: PHP 8.3 with mysqli, MySQL/MariaDB, Node.js for asset builds, `bin/typst.exe` or Typst on PATH, and pdflatex for LaTeX output.

Configure `config/db.php`, then serve `public/` as the document root:

```powershell
php -S localhost:8000 -t public scripts/serve.php
```

Open `/catalog_ui.html`, `/spec-search.html`, `/catalog-csv.html`, `/global_typst_template.html`, `/series_typst_template.html` or `/latex-templating.html`.

The series Typst editor requires `?series_id=ID`; open it from a selected series in Catalog UI.

Catalog endpoints retain their existing schema/seed setup; Typst retains its table setup. Operational SQL scripts remain in `scripts/`. Read them before running: existing migration SQL may drop tables. Configure `CATALOG_PDFLATEX_BIN` when pdflatex is not on PATH.

The development router preserves legacy `/storage/media/*` and `/storage/latex-pdfs/*` URLs while serving `public/` as the web root. Configure equivalent aliases on Apache/Nginx; existing files under `public/storage/` take priority. Do not expose the entire private `storage/` directory.

The read-only Public Product API starts at `/api/`, with `/api/health` and `/api/tree` available through the development router. Apache installations can use the included `api/.htaccess` rules with `mod_rewrite`; configure equivalent routing on Nginx. Existing file/action API addresses remain available. See the [API reference](API.md) for the ten implemented public routes, query parameters and Laravel host routing limitation.

## Assets and Views

```powershell
npm ci
npm run build:assets
```

Edit JavaScript in `assets/js/`, SCSS in `assets/scss/` and HTML in `views/`. The build writes browser assets to `public/assets/` and copies pages to their existing `public/*.html` URLs. Generated files are included so the PHP application can run without Node.js on the deployment host. `node_modules/` is only needed while building and can be recreated with `npm ci`.

PHP implementation and HTML templates are separate. Page controllers use native JavaScript modules and named class exports; shared browser helpers retain their existing integrations.

Asset builds give local CSS/JavaScript entrypoints and shared module imports the same content revision. Apache revalidates HTML pages so updates load a matching set of assets. Media Library also reports a recoverable startup error if its module cannot load, instead of leaving an empty page.

## Backend

- `app/Controllers/`: HTTP handling, including the legacy catalog action controller and file API controllers.
- `app/Services/`: feature workflows, validation and file/PDF handling.
- `app/Repositories/`: database persistence for both API families.
- `app/Http/`: request/response handling, exceptions and the optional Laravel bridge.
- `app/Support/`: dependency wiring, schema/seed setup, configuration and logging.
- `config/`: settings; `storage/` and `public/storage/`: user/generated files.

`catalog.php`, `public/catalog.php`, `db_config.php` and legacy `api/` scripts remain small compatibility entrypoints. `app/compatibility.php` retains historical global and `App\` class names only for standalone loading. [API reference](API.md) describes both API families; their envelopes and naming intentionally remain unchanged.

## Laravel Module

The core uses Composer PSR-4 loading under `CatalogSuite\`, so it can coexist with the host's `App\` classes. `ModuleServiceProvider` binds the existing services and `ModuleBridge` returns Laravel/Symfony responses. The standalone bootstrap is not loaded by Composer. The provider follows Laravel's [package integration conventions](https://laravel.com/docs/13.x/packages).

1. Place this project in the Laravel host's `modules/catalog-suite` directory and add a Composer path repository there:

```json
{
    "repositories": [
        { "type": "path", "url": "modules/catalog-suite" }
    ]
}
```

2. Run `composer require catalog-suite/module:@dev` in the host. Laravel discovers the provider. Publish the host settings with `php artisan vendor:publish --tag=catalog-suite-config`.
3. In the host's `config/catalog-suite.php`, set `routes_enabled` to `true`, choose the `prefix` (default `catalog`), the host `connection` or explicit MySQL/MariaDB `database` settings, and the host's required `middleware`. Routes are disabled until configured.
4. Provision the existing tables and transfer the existing uploads/PDFs to the configured storage locations. The module does not create a database, seed data or run schema setup during requests. The old SQL scripts are not Laravel migrations; review them before use. The LaTeX file API schema limitation below still applies.

The seven pages are available at `/catalog/<existing-page-name>`, with APIs at `/catalog/api/...` and the action endpoint at `/catalog/catalog.php`. The distinct root adapter variants mount at `/catalog/legacy/api/...`. Preserve existing root URLs with host route aliases or proxying when WordPress still uses them.

Storage defaults to the host's `storage/app/catalog-suite`; paths and executable settings can be overridden through `settings` in `config/catalog-suite.php`. Generated URLs include the path from the host's `APP_URL`; `base_url` can override this for a proxy or subdirectory deployment. Compiled assets are served by module routes, or can be published with `php artisan vendor:publish --tag=catalog-suite-assets`.

The provider preserves raw form/query values by exempting its exact API paths from Laravel's `TrimStrings` and `ConvertEmptyStringsToNull` middleware. Controller method checks use the actual HTTP method, matching the standalone endpoints.

Database access retains `mysqli` to preserve SQL behavior. It uses a separate connection configured from Laravel and does not share Laravel's PDO transactions. Existing `last_pdf_path` absolute values may need updating when files are moved to a different server. Native Eloquent persistence would be a separate migration.

Use ordinary sequential Laravel request handling, such as PHP-FPM. The compatibility bridge temporarily captures PHP request globals and response state; concurrent Octane/Swoole requests are not supported until that bridge is adapted. Verification covered a temporary Laravel 13.34 host, not the eventual deployment or its WordPress consumers.

## Verification and Local Configuration

Optional `CATALOG_DB_HOST`, `CATALOG_DB_PORT`, `CATALOG_DB_USERNAME`, `CATALOG_DB_PASSWORD` and `CATALOG_DB_DATABASE` override database settings. `CATALOG_STORAGE_ROOT` isolates legacy catalog storage for development checks. Defaults preserve the existing installation.

The default database host is `localhost` on Windows so Laragon can use IPv6 when WSL forwards `127.0.0.1:3306` to another database. Other platforms retain `127.0.0.1`; set `CATALOG_DB_HOST` explicitly to select a different server.

There is no permanent project test directory or runner. Use PHP syntax checks, the asset build and temporary functional/API checks outside the repository. CSV restore/truncate and other destructive checks require disposable data. Do not run them against production data.

Keep uploaded files, generated PDFs and credentials out of source-control cleanup. Do not deploy or update WordPress until API compatibility has been checked against the intended installation.

The separate LaTeX file API expects `latex_templates` and `latex_variables` tables. The inspected local database only had the legacy `latex_template` table; this existing setup difference was not changed by the refactor. Functional verification used disposable schemas, including these API tables. Live WordPress integration still requires checking against its actual installation.
