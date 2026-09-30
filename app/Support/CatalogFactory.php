<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Http\HttpRequestReader;
use CatalogSuite\Support\DatabaseFactory;
use CatalogSuite\Support\Seeder;
use CatalogSuite\Services\MediaStorageService;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Services\SeriesFieldService;
use CatalogSuite\Services\SeriesAttributeService;
use CatalogSuite\Services\ProductService;
use CatalogSuite\Services\PublicCatalogService;
use CatalogSuite\Services\LegacySpecSearchService as SpecSearchService;
use CatalogSuite\Services\LatexTemplateService;
use CatalogSuite\Services\LatexBuildService;
use CatalogSuite\Services\CatalogCsvService;
use CatalogSuite\Services\CatalogTruncateService;

use CatalogSuite\Controllers\CatalogController;

/** Wires catalog dependencies; replace with Laravel bindings when migrating. */
final class CatalogFactory
{
    /** Create the catalog controller with its existing bootstrap behaviour. */
    public static function create(
        bool $performBootstrap = true,
        ?\mysqli $connection = null,
        bool $bootstrapOnRequest = true
    ): CatalogController
    {
        if ($connection === null) {
            $factory = new DatabaseFactory(dirname(__DIR__, 2) . '/db_config.php');
            $connection = $factory->createConnection();
        }
        $responder = new HttpResponder();
        $requestReader = new HttpRequestReader();
        $mediaStorageService = new MediaStorageService($connection);
        $seeder = new Seeder($connection);
        $hierarchyService = new HierarchyService($connection);
        $seriesFieldService = new SeriesFieldService($connection);
        $seriesAttributeService = new SeriesAttributeService($connection, $seriesFieldService, $mediaStorageService);
        $productService = new ProductService($connection, $seriesFieldService, $mediaStorageService);
        $csvService = new CatalogCsvService($connection, $hierarchyService, $seriesFieldService);
        $truncateService = new CatalogTruncateService($connection);
        $publicCatalogService = new PublicCatalogService(
            $hierarchyService,
            $seriesFieldService,
            $seriesAttributeService,
            $productService
        );
        $specSearchService = new SpecSearchService($connection);
        $latexTemplateService = new LatexTemplateService($connection);
        $pdflatexBinary = getenv(Config::get('app')['latex']['pdflatex_env']);
        $pdflatexPath = is_string($pdflatexBinary) && $pdflatexBinary !== ''
            ? $pdflatexBinary
            : Config::get('app')['latex']['default_binary'];
        $latexBuildService = new LatexBuildService($pdflatexPath, Config::get('app')['storage']['latex_pdfs'], Config::get('app')['storage']['latex_build']);

        $app = new CatalogController(
            $connection,
            $responder,
            $requestReader,
            $mediaStorageService,
            $seeder,
            $hierarchyService,
            $seriesFieldService,
            $seriesAttributeService,
            $productService,
            $csvService,
            $truncateService,
            $publicCatalogService,
            $specSearchService,
            $latexTemplateService,
            $latexBuildService,
            $bootstrapOnRequest
        );

        if ($performBootstrap) {
            $app->bootstrap();
        }

        return $app;
    }

}
