<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Controllers\CatalogController;
use CatalogSuite\Controllers\CatalogOperationsController;
use CatalogSuite\Controllers\CatalogReadController;
use CatalogSuite\Controllers\LatexController;
use CatalogSuite\Controllers\SpecSearchController;
use CatalogSuite\Controllers\TypstController;
use CatalogSuite\Http\ModuleBridge;
use CatalogSuite\Services\CatalogService;
use CatalogSuite\Services\LatexService;
use CatalogSuite\Services\SpecSearchService;
use CatalogSuite\Services\TypstService;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Support\ServiceProvider;

/** Optional Laravel bindings; registering the package never creates or seeds tables. */
final class ModuleServiceProvider extends ServiceProvider
{
    /** Register lazy dependencies without overriding the host database binding. */
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/laravel.php', 'catalog-suite');
        $this->app->scoped('catalog-suite.connection', function () {
            $this->configureRuntime();
            if (!in_array(Config::get('db')['driver'] ?? '', ['mysql', 'mariadb'], true)) {
                throw new \RuntimeException('Catalog Suite requires a configured MySQL/MariaDB connection.');
            }
            return Db::connection();
        });
        $this->app->bind(CatalogController::class, fn ($app) => CatalogFactory::create(
            false, $app->make('catalog-suite.connection'), false
        ));
        foreach ([CatalogService::class, SpecSearchService::class, LatexService::class, TypstService::class] as $service) {
            $this->app->bind($service, fn ($app) => new $service($app->make('catalog-suite.connection')));
        }
        foreach ([
            CatalogReadController::class => CatalogService::class,
            SpecSearchController::class => SpecSearchService::class,
            LatexController::class => LatexService::class,
            TypstController::class => TypstService::class,
        ] as $controller => $service) {
            $this->app->bind($controller, fn ($app) => new $controller(null, fn () => $app->make($service)));
        }
        $this->app->bind(CatalogOperationsController::class, fn ($app) => new CatalogOperationsController(
            fn () => $app->make(CatalogController::class)
        ));
        $this->app->bind(ModuleBridge::class, fn ($app) => new ModuleBridge($app));
    }

    /** Publish assets and register optional prefixed routes using normal Laravel responses. */
    public function boot(): void
    {
        $this->configureRuntime();
        $prefix = trim((string) $this->app['config']->get('catalog-suite.prefix', 'catalog'), '/');
        $packageRoot = dirname(__DIR__, 2);
        $this->publishes([
            $packageRoot . '/public/assets' => $this->app->publicPath($prefix . '/assets'),
        ], 'catalog-suite-assets');
        $this->publishes([
            $packageRoot . '/config/laravel.php' => $this->app->configPath('catalog-suite.php'),
        ], 'catalog-suite-config');
        if (!$this->app['config']->get('catalog-suite.routes_enabled', false)) {
            return;
        }
        // Preserve original form/query values across Laravel's global normalization.
        $endpointPaths = array_map(
            static fn (string $path): string => trim($prefix . '/' . $path, '/'),
            array_keys(ModuleBridge::ENDPOINTS)
        );
        $skipNormalization = static fn ($request): bool => in_array(trim($request->path(), '/'), $endpointPaths, true);
        TrimStrings::skipWhen($skipNormalization);
        ConvertEmptyStringsToNull::skipWhen($skipNormalization);
        if ($this->app->routesAreCached()) {
            return;
        }
        $router = $this->app['router'];
        $router->group([
            'prefix' => $prefix,
            'middleware' => $this->app['config']->get('catalog-suite.middleware', []),
        ], function () use ($router): void {
            foreach (ModuleBridge::ENDPOINTS as $path => $handler) {
                $router->any($path, ModuleBridge::class)->defaults('catalog_handler', $handler);
            }
            foreach (ModuleBridge::PAGES as $page) {
                $router->match(['GET', 'HEAD'], $page, ModuleBridge::class)->defaults('catalog_handler', $page);
            }
            foreach (['assets', 'media', 'latex-pdfs', 'typst-pdfs', 'typst-assets'] as $handler) {
                $path = $handler === 'assets' ? 'assets/{path}' : 'storage/' . $handler . '/{path}';
                $router->match(['GET', 'HEAD'], $path, ModuleBridge::class)
                    ->where('path', '.+')
                    ->defaults('catalog_handler', $handler);
            }
        });
    }

    /** Map Laravel settings to the shared core while retaining standalone defaults. */
    private function configureRuntime(): void
    {
        $defaults = require dirname(__DIR__, 2) . '/config/app.php';
        $host = $this->app['config']->get('catalog-suite', []);
        $overrides = $host['settings'] ?? [];
        $settings = Config::combine($defaults, $overrides);
        $prefix = trim((string) ($host['prefix'] ?? 'catalog'), '/');
        $applicationPath = (string) (parse_url((string) $this->app['config']->get('app.url', ''), PHP_URL_PATH) ?: '');
        $baseUrl = rtrim((string) ($host['base_url'] ?? rtrim($applicationPath, '/') . ($prefix === '' ? '' : '/' . $prefix)), '/');
        $storageRoot = ($host['storage_root'] ?? null) ?: $this->app->storagePath('app/catalog-suite');
        $settings['bootstrap_schema'] = false;
        $settings['base_url'] = $baseUrl;
        $settings['public_root'] = $this->app->publicPath($prefix);
        foreach ([
            'csv' => 'csv', 'media' => 'media', 'latex_build' => 'latex-build', 'latex_pdfs' => 'latex-pdfs',
            'api_latex_build' => 'latex-build', 'api_latex_pdfs' => 'latex-pdfs',
            'typst_build' => 'typst-build', 'typst_pdfs' => 'typst-pdfs', 'typst_assets' => 'typst-assets',
        ] as $key => $directory) {
            // Host overrides for individual directories take precedence.
            $settings['storage'][$key] = $overrides['storage'][$key] ?? $storageRoot . '/' . $directory;
        }
        $settings['logging']['path'] = $overrides['logging']['path'] ?? $this->app->storagePath('logs/catalog-suite.log');
        $settings['truncate']['audit_log'] = $overrides['truncate']['audit_log'] ?? $storageRoot . '/csv/truncate_audit.jsonl';
        $settings['latex']['pdf_url_prefix'] = $overrides['latex']['pdf_url_prefix'] ?? $baseUrl . '/storage/latex-pdfs';
        $settings['typst']['pdf_url_prefix'] = $overrides['typst']['pdf_url_prefix'] ?? $baseUrl . '/storage/typst-pdfs';
        $settings['storage']['media_url_prefix'] = $overrides['storage']['media_url_prefix'] ?? $baseUrl . '/storage/media';
        $settings['media']['download_url'] = $overrides['media']['download_url'] ?? $baseUrl . '/catalog.php';
        Config::set('app', $settings);

        $connection = $host['connection'] ?? $this->app['config']->get('database.default');
        $database = $host['database'] ?? $this->app['config']->get('database.connections.' . $connection);
        if (is_array($database)) {
            Config::set('db', $database);
        } else {
            Config::set('db', ['driver' => 'unconfigured']);
        }
    }
}
