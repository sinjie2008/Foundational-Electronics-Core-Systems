<?php
declare(strict_types=1);


$catalogSettings = \CatalogSuite\Support\Config::get('app');
foreach ([
    'CATALOG_SEED_NAME' => $catalogSettings['seed_name'],
    'CATALOG_CSV_STORAGE' => $catalogSettings['storage']['csv'],
    'CATALOG_TRUNCATE_AUDIT_LOG' => $catalogSettings['truncate']['audit_log'],
    'CATALOG_TRUNCATE_CONFIRM_TOKEN' => $catalogSettings['truncate']['token'],
    'CATALOG_TRUNCATE_LOCK_KEY' => $catalogSettings['truncate']['lock_key'],
    'CATALOG_TRUNCATE_REASON_MAX' => $catalogSettings['truncate']['reason_max'],
    'CATALOG_MEDIA_STORAGE' => $catalogSettings['storage']['media'],
    'CATALOG_MEDIA_MAX_BYTES' => $catalogSettings['media']['max_bytes'],
    'CATALOG_MEDIA_ALLOWED_MIME' => $catalogSettings['media']['allowed_mime'],
    'CATALOG_MEDIA_ALLOWED_EXT' => $catalogSettings['media']['allowed_extensions'],
    'LATEX_PDF_STORAGE' => $catalogSettings['storage']['latex_pdfs'],
    'LATEX_BUILD_WORKDIR' => $catalogSettings['storage']['latex_build'],
    'LATEX_PDF_URL_PREFIX' => $catalogSettings['latex']['pdf_url_prefix'],
    'LATEX_PDFLATEX_ENV' => $catalogSettings['latex']['pdflatex_env'],
    'LATEX_DEFAULT_PDFLATEX' => $catalogSettings['latex']['default_binary'],
    'SERIES_FIELD_SCOPE_PRODUCT' => 'product_attribute',
    'SERIES_FIELD_SCOPE_SERIES' => 'series_metadata',
] as $name => $value) {
    if (!defined($name)) {
        define($name, $value);
    }
}

$catalogAliases = [
    'App\Models\CatalogRepository' => \CatalogSuite\Repositories\CatalogRepository::class,
    'App\Models\SpecSearchRepository' => \CatalogSuite\Repositories\SpecSearchRepository::class,
    'App\Models\LatexRepository' => \CatalogSuite\Repositories\LatexRepository::class,
    'App\Models\TypstRepository' => \CatalogSuite\Repositories\TypstRepository::class,
    'App\Services\SpecSearchService' => \CatalogSuite\Services\LegacySpecSearchService::class,
    'App\Catalog\CatalogService' => \CatalogSuite\Services\CatalogService::class,
    'App\SpecSearch\SpecSearchService' => \CatalogSuite\Services\SpecSearchService::class,
    'App\Latex\LatexService' => \CatalogSuite\Services\LatexService::class,
    'App\Typst\TypstService' => \CatalogSuite\Services\TypstService::class,
    'App\Database\Seeder' => \CatalogSuite\Support\Seeder::class,
    'App\Exceptions\CatalogApiException' => \CatalogSuite\Http\CatalogApiException::class,
    'App\Support\Request' => \CatalogSuite\Http\Request::class,
    'App\Support\Response' => \CatalogSuite\Http\Response::class,
    'App\Support\CorrelationId' => \CatalogSuite\Http\CorrelationId::class,
    'App\Controllers\Api\CatalogController' => \CatalogSuite\Controllers\CatalogReadController::class,
    'App\Controllers\Api\CatalogOperationsController' => \CatalogSuite\Controllers\CatalogOperationsController::class,
    'App\Controllers\Api\SpecSearchController' => \CatalogSuite\Controllers\SpecSearchController::class,
    'App\Controllers\Api\LatexController' => \CatalogSuite\Controllers\LatexController::class,
    'App\Controllers\Api\TypstController' => \CatalogSuite\Controllers\TypstController::class,
    'App\Controllers\CatalogController' => \CatalogSuite\Controllers\CatalogController::class,
    'App\Controllers\StorageController' => \CatalogSuite\Controllers\StorageController::class,
    'App\Http\HttpRequestReader' => \CatalogSuite\Http\HttpRequestReader::class,
    'App\Http\HttpResponder' => \CatalogSuite\Http\HttpResponder::class,
    'App\Repositories\CatalogCsvRepository' => \CatalogSuite\Repositories\CatalogCsvRepository::class,
    'App\Repositories\CatalogTruncateRepository' => \CatalogSuite\Repositories\CatalogTruncateRepository::class,
    'App\Repositories\HierarchyRepository' => \CatalogSuite\Repositories\HierarchyRepository::class,
    'App\Repositories\LatexTemplateRepository' => \CatalogSuite\Repositories\LatexTemplateRepository::class,
    'App\Repositories\MediaStorageRepository' => \CatalogSuite\Repositories\MediaStorageRepository::class,
    'App\Repositories\ProductRepository' => \CatalogSuite\Repositories\ProductRepository::class,
    'App\Repositories\SeriesAttributeRepository' => \CatalogSuite\Repositories\SeriesAttributeRepository::class,
    'App\Repositories\SeriesFieldRepository' => \CatalogSuite\Repositories\SeriesFieldRepository::class,
    'App\Services\CatalogCsvService' => \CatalogSuite\Services\CatalogCsvService::class,
    'App\Services\CatalogTruncateService' => \CatalogSuite\Services\CatalogTruncateService::class,
    'App\Services\HierarchyService' => \CatalogSuite\Services\HierarchyService::class,
    'App\Services\LatexBuildService' => \CatalogSuite\Services\LatexBuildService::class,
    'App\Services\LatexTemplateService' => \CatalogSuite\Services\LatexTemplateService::class,
    'App\Services\MediaStorageService' => \CatalogSuite\Services\MediaStorageService::class,
    'App\Services\ProductService' => \CatalogSuite\Services\ProductService::class,
    'App\Services\PublicCatalogService' => \CatalogSuite\Services\PublicCatalogService::class,
    'App\Services\SeriesAttributeService' => \CatalogSuite\Services\SeriesAttributeService::class,
    'App\Services\SeriesFieldService' => \CatalogSuite\Services\SeriesFieldService::class,
    'App\Support\CatalogFactory' => \CatalogSuite\Support\CatalogFactory::class,
    'App\Support\Config' => \CatalogSuite\Support\Config::class,
    'App\Support\DatabaseFactory' => \CatalogSuite\Support\DatabaseFactory::class,
    'App\Support\Db' => \CatalogSuite\Support\Db::class,
    'App\Support\Logger' => \CatalogSuite\Support\Logger::class,
];
spl_autoload_register(static function (string $class) use ($catalogAliases): void {
    if (isset($catalogAliases[$class]) && !class_exists($class, false)) {
        class_alias($catalogAliases[$class], $class);
    }
});

/** Preserve historical class names for existing integrations. */
foreach ([
    'CatalogApiException' => \CatalogSuite\Http\CatalogApiException::class,
    'HttpResponder' => \CatalogSuite\Http\HttpResponder::class,
    'HttpRequestReader' => \CatalogSuite\Http\HttpRequestReader::class,
    'DatabaseFactory' => \CatalogSuite\Support\DatabaseFactory::class,
    'Seeder' => \CatalogSuite\Support\Seeder::class,
    'CatalogApplication' => \CatalogSuite\Controllers\CatalogController::class,
    'MediaStorageService' => \CatalogSuite\Services\MediaStorageService::class,
    'HierarchyService' => \CatalogSuite\Services\HierarchyService::class,
    'SeriesFieldService' => \CatalogSuite\Services\SeriesFieldService::class,
    'SeriesAttributeService' => \CatalogSuite\Services\SeriesAttributeService::class,
    'ProductService' => \CatalogSuite\Services\ProductService::class,
    'PublicCatalogService' => \CatalogSuite\Services\PublicCatalogService::class,
    'SpecSearchService' => \CatalogSuite\Services\LegacySpecSearchService::class,
    'LatexTemplateService' => \CatalogSuite\Services\LatexTemplateService::class,
    'LatexBuildService' => \CatalogSuite\Services\LatexBuildService::class,
    'CatalogCsvService' => \CatalogSuite\Services\CatalogCsvService::class,
    'CatalogTruncateService' => \CatalogSuite\Services\CatalogTruncateService::class,
] as $legacyName => $className) {
    if (!class_exists($legacyName, false)) {
        class_alias($className, $legacyName);
    }
}
