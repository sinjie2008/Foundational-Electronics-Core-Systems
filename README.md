# Catalog Suite

Standalone PHP catalog, specification search, CSV tools and Typst/LaTeX PDF generation. The MVC structure is prepared for later integration into Laravel while retaining existing page and API URLs.

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

## Assets and Views

```powershell
npm ci
npm run build:assets
```

Edit JavaScript in `assets/js/`, SCSS in `assets/scss/` and HTML in `views/`. The build writes browser assets to `public/assets/` and copies pages to their existing `public/*.html` URLs. Generated files are included so the PHP application can run without Node.js on the deployment host. `node_modules/` is only needed while building and can be recreated with `npm ci`.

PHP implementation and HTML templates are separate. Page controllers use native JavaScript modules and named class exports; shared browser helpers retain their existing integrations.

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

The six pages are available at `/catalog/<existing-page-name>`, with APIs at `/catalog/api/...` and the action endpoint at `/catalog/catalog.php`. The distinct root adapter variants mount at `/catalog/legacy/api/...`. Preserve existing root URLs with host route aliases or proxying when WordPress still uses them.

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
