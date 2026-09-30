<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

use CatalogSuite\Controllers\CatalogController;
use CatalogSuite\Controllers\CatalogOperationsController;
use CatalogSuite\Controllers\CatalogReadController;
use CatalogSuite\Controllers\LatexController;
use CatalogSuite\Controllers\SpecSearchController;
use CatalogSuite\Controllers\StorageController;
use CatalogSuite\Controllers\TypstController;
use CatalogSuite\Support\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/** Optional Laravel adapter; standalone endpoints use the same controllers directly. */
final class ModuleBridge
{
    /** Existing public API contracts, including the distinct root adapter variants. */
    public const ENDPOINTS = [
        'catalog.php' => [CatalogController::class, 'run'],
        'api/catalog/hierarchy.php' => [CatalogReadController::class, 'hierarchy'],
        'api/catalog/search.php' => [CatalogReadController::class, 'search'],
        'api/catalog/csv-download.php' => [CatalogOperationsController::class, 'csvDownload'],
        'api/catalog/csv-export.php' => [CatalogOperationsController::class, 'csvExport'],
        'api/catalog/csv-history.php' => [CatalogOperationsController::class, 'csvHistory'],
        'api/catalog/csv-import.php' => [CatalogOperationsController::class, 'csvImport'],
        'api/catalog/csv-restore.php' => [CatalogOperationsController::class, 'csvRestore'],
        'api/catalog/truncate.php' => [CatalogOperationsController::class, 'truncate'],
        'api/catalog/pdf.php' => [CatalogOperationsController::class, 'pdf'],
        'api/series/details.php' => [CatalogReadController::class, 'seriesDetails'],
        'api/spec-search/root-categories.php' => [SpecSearchController::class, 'rootCategories'],
        'api/spec-search/product-categories.php' => [SpecSearchController::class, 'productCategories'],
        'api/spec-search/facets.php' => [SpecSearchController::class, 'facets'],
        'api/spec-search/products.php' => [SpecSearchController::class, 'products'],
        'api/latex/compile.php' => [LatexController::class, 'compile'],
        'api/latex/templates.php' => [LatexController::class, 'templates'],
        'api/latex/variables.php' => [LatexController::class, 'variables'],
        'api/typst/compile.php' => [TypstController::class, 'compile'],
        'api/typst/templates.php' => [TypstController::class, 'templates'],
        'api/typst/variables.php' => [TypstController::class, 'variables'],
        'api/typst/series-preferences.php' => [TypstController::class, 'seriesPreferences'],
        'legacy/api/catalog/hierarchy.php' => [CatalogReadController::class, 'hierarchy'],
        'legacy/api/catalog/search.php' => [CatalogReadController::class, 'search'],
        'legacy/api/spec-search/root-categories' => [SpecSearchController::class, 'rootCategories'],
        'legacy/api/spec-search/product-categories' => [SpecSearchController::class, 'productCategories'],
        'legacy/api/spec-search/facets' => [SpecSearchController::class, 'facets'],
        'legacy/api/spec-search/products' => [SpecSearchController::class, 'products'],
        'legacy/api/typst/compile.php' => [TypstController::class, 'compile'],
        'legacy/api/typst/templates.php' => [TypstController::class, 'legacyTemplates'],
        'legacy/api/typst/variables.php' => [TypstController::class, 'legacyVariables'],
    ];

    public const PAGES = [
        'catalog_ui.html', 'catalog-csv.html', 'spec-search.html',
        'latex-templating.html', 'global_typst_template.html', 'series_typst_template.html',
    ];

    /** Resolve module controllers from the host container. */
    public function __construct(private Container $container)
    {
    }

    /** Adapt a Laravel request and return its original JSON or file response. */
    public function __invoke(Request $request): Response
    {
        $handler = $request->route('catalog_handler');
        if (is_string($handler)) {
            return $this->serveFile($handler, (string) $request->route('path', ''));
        }
        if (!is_array($handler) || count($handler) !== 2) {
            return new Response('', 404);
        }
        $server = $request->server->all();
        $server['REQUEST_METHOD'] = $request->getRealMethod();
        $server['REQUEST_URI'] = $request->getRequestUri();
        foreach ($request->headers->all() as $name => $values) {
            $name = strtoupper(str_replace('-', '_', $name));
            $server[in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $name : 'HTTP_' . $name] = implode(', ', $values);
        }
        $result = Transport::capture(
            $request->getContent(),
            $request->query->all(),
            $request->request->all(),
            $this->normalizeUploads($request->files->all()),
            $server,
            function () use ($handler): void {
                $this->container->make($handler[0])->{$handler[1]}();
            }
        );
        if ($result['file'] !== null) {
            return new BinaryFileResponse($result['file'], $result['status'], $result['headers']);
        }
        return new Response($result['body'], $result['status'], $result['headers']);
    }

    /** Serve only declared pages, compiled assets and public storage categories. */
    private function serveFile(string $handler, string $path): Response
    {
        $packageRoot = dirname(__DIR__, 2);
        if (in_array($handler, self::PAGES, true)) {
            return new BinaryFileResponse($packageRoot . '/public/' . $handler, 200, ['Content-Type' => 'text/html; charset=utf-8']);
        }
        $settings = Config::get('app');
        $directory = match ($handler) {
            'assets' => $packageRoot . '/public/assets',
            'media' => $settings['storage']['media'],
            'latex-pdfs' => $settings['storage']['latex_pdfs'],
            'typst-pdfs' => $settings['storage']['typst_pdfs'],
            'typst-assets' => $settings['storage']['typst_assets'],
            default => null,
        };
        if ($directory === null || str_contains($path, "\0")) {
            return new Response('', 404);
        }
        $result = Transport::capture('', [], [], [], [], function () use ($directory, $path): void {
            (new StorageController())->download($directory, $path);
        });
        if ($result['file'] === null) {
            return new Response('', $result['status']);
        }
        if ($handler === 'assets') {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $result['headers']['Content-Type'] = match ($extension) {
                'js' => 'text/javascript; charset=utf-8',
                'css' => 'text/css; charset=utf-8',
                default => 'application/octet-stream',
            };
        } else {
            $result['headers']['Content-Type'] = mime_content_type($result['file']) ?: $result['headers']['Content-Type'];
        }
        return new BinaryFileResponse($result['file'], $result['status'], $result['headers']);
    }

    /** Convert Symfony upload trees to the original PHP upload metadata shape. */
    private function normalizeUploads(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $file) {
            if ($file instanceof UploadedFile) {
                $normalized[$key] = [
                    'name' => $file->getClientOriginalName(),
                    'type' => $file->getClientMimeType(),
                    'tmp_name' => $file->getPathname(),
                    'error' => $file->getError(),
                    'size' => $file->getError() === UPLOAD_ERR_OK ? $file->getSize() : 0,
                ];
            } elseif (is_array($file)) {
                $children = $this->normalizeUploads($file);
                foreach ($children as $childKey => $metadata) {
                    foreach ($metadata as $attribute => $value) {
                        $normalized[$key][$attribute][$childKey] = $value;
                    }
                }
            }
        }
        return $normalized;
    }
}
