# Public Product API V1 engineering validation

Branch: `plan/public-product-api-v1`. Starting revision: `aa081bd7ba570239cac511ae31d9a21f3516dd2d`. Validation date: 2026-10-04. This report covers the reusable API engine; actual catalog import and real-data acceptance remain for the user.

## Final results

The full engineering suite passed **115 tests, 0 failed, 0 skipped** after the final implementation fixes. Counts below are named test cases, not individual assertions; some cases exercise multiple requests and edge conditions. Runtime fixtures use clearly generic identifiers inside disposable databases/storage and are removed automatically. No production catalog records, invented specifications or production-style seed data were added.

| Suite | Passed | Failed | Skipped | Coverage |
| --- | ---: | ---: | ---: | --- |
| `catalog_test.php` | 36 | 0 | 0 | Required routes, root/category/series composition, unrelated dynamic schemas, different family columns, values/defaults, SQL search/filter/sort/pagination/facets, exact decimal precision, aliases, reserved-word slugs and technical renderer semantics. |
| `cleanup_test.php` | 4 | 0 | 0 | Cascading hierarchy/product deletion, CSV pruning, stale ownership/ID reuse, unrelated owners/files retained, full truncate compatibility. |
| `http_test.php` | 9 | 0 | 0 | Native PHP HTTP server, real query-array parsing, byte streaming/disposition, legacy entrypoints/envelopes, malformed requests, migration/import/verifier CLI, SELECT-only public database account. |
| `import_test.php` | 7 | 0 | 0 | Empty/dry-run non-seeding, transaction rollback, stable merge identities/retention, asset-key resolution, privacy defaults, route-shadowing aliases and malformed manifests. |
| `legacy_public_test.php` | 3 | 0 | 0 | Legacy hidden-field/facet/search privacy, unpublished ancestor/part suppression, internal document access. |
| `legacy_test.php` | 8 | 0 | 0 | Original hierarchy/fields/products, PublicCatalogService envelope, catalog/SpecSearch API contracts, Typst templates/preferences/variables, media, CSV round trip/pruning, sequential Transport isolation. |
| `migration_test.php` | 14 | 0 | 0 | Persistent/sibling/root/Unicode/long-name slugs, rename/move conflicts, cycle/type prevention, forty-level recursion, idempotent backfill, canonical uniqueness, seed-free bootstrap, repeated rollback/reapply preserving legacy records. |
| `pdf_test.php` | 3 | 0 | 0 | Native Typst compilation, singleton part array, registered generated PDF, internal draft/hidden metadata, native LaTeX compilation; extracted PDF text matches runtime metadata and part identity. |
| `presentation_test.php` | 10 | 0 | 0 | Stored field config, section images/thumbnails, gallery/model/drawing/curve/documents, family asset columns, release card/config/order, CTA selection, public part references, canonical category rebasing, safe filenames and inline media. |
| `public_security_test.php` | 11 | 0 | 0 | Hidden schema/default/value/search/facet/table leakage, publication/private/orphan gates, cross-series joins, unsafe references/paths/symlinks/executables/URLs, invalid queries/methods/paths and redacted database errors. |
| `verification_test.php` | 10 | 0 | 0 | Blank/missing inputs before HTTP, second unrelated schema, real-data count/order/schema/media/filter/sort assertions, optional private-key check, generic composition/card/navigation expectations, catalog/API traversal prevention and malformed family/assertion preflight. |
| **Total** | **115** | **0** | **0** | All required engineering gates passed. |

A final targeted catalog/verifier run passed **46/0/0**; the earlier alias/import-focused run passed **42/0/0**. Earlier targeted hierarchy, privacy and compatibility runs passed and are included in the final full run.

## Baseline and checks

There was no existing committed automated suite, static-analyzer configuration or formatting configuration. Before major changes, compatibility tests were run against the original behavior: **8 passed, 0 failed, 0 skipped**; all **91 original PHP files** passed syntax checks. No pre-existing automated test failure was recorded. PHP 8.4 emitted CSV escape deprecations; this implementation specifies the historical escape character explicitly. Subsequent native PDF verification exposed the pre-existing Typst singleton-array bug, which was fixed and covered by real compilation.

| Check | Result |
| --- | --- |
| `composer lint` / `scripts/lint.php` | **122 PHP files passed, 0 failed**. |
| `composer validate --no-check-publish` | Exit 0; existing missing-license warning remains. No license was invented. |
| `npm ci --ignore-scripts --no-audit --no-fund` | Passed; no dependency manifest changes. |
| `npm run build:assets` | Passed: 6 CSS files, JavaScript assets and HTML pages. An unrelated pre-existing generated HTML cache-hash difference was restored; no frontend files are part of this change. |
| `git diff --check` | Passed. |
| JSON parsing | Verification template, empty import template, Composer and npm manifests passed. |
| Actual-data template audit | All actual-value slots remain null/empty; composition expectations and import record lists are empty. |
| Approved-plan audit | `PUBLIC_PRODUCT_API_V1_PLAN.md` is unchanged. |
| Migration up/down | Real MySQL repeatable up, repeated down, reapply and legacy-data preservation passed; no catalog seeding. |
| Public database permissions | Required V1 reads passed using a temporary account granted SELECT only. |
| Additional static analysis / formatter | None configured in this repository; no analyzer or formatter result is claimed. |

Verified runtime: PHP **8.4.1**, MySQL **8.0.46**, Typst **0.14.0**, native `pdflatex` and `pdftotext`. Composer was run with plugins disabled in the root-owned execution environment. MySQL servers, databases, HTTP servers, temporary accounts and files were isolated and cleaned up. No fake PDF compiler was used.

Reproduce using a test-capable MySQL account:

```bash
composer lint
CATALOG_TEST_TYPST_BIN=/absolute/path/to/typst \
CATALOG_TEST_PDFLATEX_BIN=/absolute/path/to/pdflatex \
CATALOG_TEST_PDFTOTEXT_BIN=/absolute/path/to/pdftotext composer test
php tests/run.php catalog_test.php migration_test.php public_security_test.php
npm ci
npm run build:assets
git diff --check
```

The engineering account needs CREATE/DROP DATABASE and CREATE USER/GRANT/DROP USER privileges for disposable fixtures and the read-only-account check. It is separate from the deployed V1 read account. Compiler/text-extractor checks explicitly report skips when their native executable is unavailable.

## Manual and structural review

The approved plan and verification variables were read completely before modification. Existing schema, hierarchy operations, repositories/services, SpecSearch, PublicCatalogService, action/file APIs, media, CSV import/export, Typst/LaTeX and Laravel bridge boundaries were inspected. Bounded Luna findings/tests were reviewed by the main agent before acceptance; architecture, schema/contracts, implementation/integration, final fixes and final testing remained with the main agent.

The four live wireframes presented an authentication wall. The corresponding existing wireframe source at `/workspace/sites/lo-wireframe/src/app.js` was inspected for all four product renderers and their augmenters. The [implementation guide](PUBLIC_PRODUCT_API_V1_IMPLEMENTATION.md) maps each page requirement to returned data. Engineering tests verify group/category/family/series structure, navigation/anchors/order, thumbnails, dynamic tables, technical blocks/assets, collections and CTA/selection metadata. Wireframe placeholder values were not imported.

Schema decisions remain additive: original field scopes/value ownership are preserved; five reusable presentation/collection/alias tables and four uniqueness-maintenance triggers supplement existing tables. Public GETs perform no DDL, catalog seeding, PDF generation or writes. Legacy public contracts retain their envelopes; hidden-field/publication leaks are corrected, while admin/CSV/internal PDF access remains available. Hierarchy/CSV/truncate cleanup prevents deleted ownership from attaching presentation to reused IDs without removing media files.

The new/changed implementation was searched for `A4K`, `EMC`, `General`, `Automotive`, the named product families and `B9X`: **zero catalog-specific identifiers in added implementation logic**. V1 also has no fixed specification keys/column list, product filenames, section list or category depth. Full-tree reconnaissance still finds historical static examples in unchanged `LegacySpecSearchService` and the original sample seeder body; V1 does not use that service or seed body, and legacy demo seeding now requires explicit opt-in. No new fake catalog data was added.

Privacy tests found **no leakage** of public-hidden fields/defaults/values/files through V1 schema, rows, search, facets, filtering, summary tables or references. Private blocks/assets/collections/memberships, unpublished branches/parts and orphan references are excluded. Invalid client fields fail safely rather than becoming SQL identifiers.

## File inventory

**57 files: 23 modified, 34 added.** This inventory includes this report. No separate Laravel website files, Passport integration, production catalog/media, frontend source/generated assets or dependency lockfiles changed.

```text
API.md
README.md
app/Controllers/CatalogController.php
app/Controllers/PublicCatalogV1Controller.php
app/Http/CatalogPartsQuery.php
app/Http/HttpResponder.php
app/Repositories/CatalogCsvRepository.php
app/Repositories/CatalogRepository.php
app/Repositories/CatalogTruncateRepository.php
app/Repositories/CatalogV1ImportRepository.php
app/Repositories/CatalogV1Repository.php
app/Repositories/HierarchyRepository.php
app/Repositories/ProductRepository.php
app/Repositories/SpecSearchRepository.php
app/Services/CatalogCsvService.php
app/Services/CatalogService.php
app/Services/CatalogV1ImportService.php
app/Services/CatalogV1Service.php
app/Services/HierarchyService.php
app/Services/LatexService.php
app/Services/MediaStorageService.php
app/Services/ProductService.php
app/Services/PublicCatalogService.php
app/Services/TypstService.php
app/Support/CatalogPublicVisibility.php
app/Support/CatalogSlug.php
app/Support/CatalogV1Cleanup.php
app/Support/CatalogV1Migration.php
app/Support/CatalogV1Verification.php
app/Support/Seeder.php
composer.json
config/app.php
config/catalog-v1.php
docs/api-plan/PUBLIC_PRODUCT_API_V1_IMPLEMENTATION.md
docs/api-plan/PUBLIC_PRODUCT_API_V1_VALIDATION.md
docs/api-plan/public-product-api-v1-import-template.json
docs/api-plan/public-product-api-v1-test-variables.json
public/api/v1/catalog/index.php
scripts/import_public_catalog_v1.php
scripts/lint.php
scripts/migrate_public_catalog_v1.php
scripts/serve.php
scripts/verify_public_catalog_v1.php
tests/Fixtures.php
tests/TestSuite.php
tests/catalog_test.php
tests/cleanup_test.php
tests/http_test.php
tests/import_test.php
tests/legacy_public_test.php
tests/legacy_test.php
tests/migration_test.php
tests/pdf_test.php
tests/presentation_test.php
tests/public_security_test.php
tests/run.php
tests/verification_test.php
```

Implementation commits are grouped as schema, domain/compatibility, API/import/verification tooling, tests, and documentation. Inspect their exact IDs with `git log --oneline aa081bd..HEAD` on the planning branch; nothing is merged to `main`.

## Actual-data acceptance and limits

Use the [implementation/import guide](PUBLIC_PRODUCT_API_V1_IMPLEMENTATION.md) for exact schema provisioning, actual-data manifest/media placement, publication/visibility flags, template filling, verifier commands and individual requests. Existing CSV remains a snapshot importer that prunes omitted records; use the new JSON merge importer to enrich presentation/configuration and preserve omitted records.

The verification template remains a real-data worksheet. It requires actual category paths, primary series/part/schema/counts/filter/sort expectations and another actual series with a different schema. Fill composition assertions with intended actual root/category/collection/navigation/asset properties and configured family table columns/rows. The verifier rejects the untouched template before HTTP access and certifies only supplied expectations.

Actual catalog import and acceptance have **not** been performed. Remaining acceptance work is: authenticated live visual comparison; actual values/media/documents/external destinations; production Apache/Nginx routing and deployment; actual-scale performance profiling; and any future Laravel/Passport integration. MariaDB and other PHP/MySQL versions were not automatically tested. Existing legacy raw storage URLs remain compatible and retain their separate hosting/access configuration; V1 enforces visibility on its own asset endpoint.

API engine: implemented and tested. New fake production data: none. Actual catalog data: waiting for user import. Engineering status: **READY FOR ACTUAL DATA IMPORT TESTING**.
