<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use Closure;
use CatalogSuite\Services\SpecSearchService;
use CatalogSuite\Support\Logger;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Response;
use Throwable;

/**
 * Handles specification-search API endpoints.
 */
final class SpecSearchController
{
    /** @var (Closure(): SpecSearchService)|null */
    private ?Closure $specSearchServiceFactory;

    /**
     * Accept an existing service or a lazy factory for host application bindings.
     *
     * The default leaves service creation in the existing request error boundary.
     *
     * @param (callable(): SpecSearchService)|null $specSearchServiceFactory
     */
    public function __construct(
        private ?SpecSearchService $specSearchService = null,
        ?callable $specSearchServiceFactory = null
    ) {
        $this->specSearchServiceFactory = $specSearchServiceFactory === null
            ? null
            : Closure::fromCallable($specSearchServiceFactory);
    }

    /**
     * Return root categories.
     */
    public function rootCategories(): void
    {
        $this->run('spec-search.roots', function (string $correlationId): void {
            $categories = $this->newService()->getRootCategories();
            Response::success(['categories' => $categories], 200, $correlationId);
        });
    }

    /**
     * Return categories under a root category.
     */
    public function productCategories(): void
    {
        $this->run('spec-search.product-categories', function (string $correlationId): void {
            $rootId = isset($_GET['root_id']) ? (int) $_GET['root_id'] : 0;
            $data = $this->newService()->getProductCategories($rootId);
            Response::success(['groups' => $data], 200, $correlationId);
        });
    }

    /**
     * Return available filter facets.
     */
    public function facets(): void
    {
        $this->run('spec-search.facets', function (string $correlationId): void {
            $payload = Request::json();
            $categoryIds = isset($payload['category_ids']) && is_array($payload['category_ids'])
                ? $payload['category_ids']
                : [];
            $facets = $this->newService()->getFacets($categoryIds);
            Response::success(['facets' => $facets], 200, $correlationId);
        });
    }

    /**
     * Search products using selected categories and facet filters.
     */
    public function products(): void
    {
        $this->run('spec-search.products', function (string $correlationId): array {
            $payload = Request::json();
            $categoryIds = isset($payload['category_ids']) && is_array($payload['category_ids'])
                ? $payload['category_ids']
                : [];
            $filters = isset($payload['filters']) && is_array($payload['filters'])
                ? $payload['filters']
                : [];

            $products = $this->newService()->getProducts($categoryIds, $filters);
            Response::success(['items' => $products, 'total' => count($products)], 200, $correlationId);
            return ['itemCount' => count($products)];
        });
    }

    /**
     * Apply the existing request logging and error envelope around one search action.
     *
     * @param callable(string): mixed $action
     */
    private function run(string $name, callable $action): void
    {
        $correlationId = Request::correlationId();
        $route = Request::route();
        $method = Request::method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => $name], $correlationId);

        try {
            $successContext = $action($correlationId);
        } catch (Throwable $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $name,
                'status' => 500,
                'errorCode' => 'internal_error',
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);
            Response::error('internal_error', 'Unexpected error', 500, $correlationId);
            return;
        }

        $context = [
            'route' => $route,
            'method' => $method,
            'action' => $name,
            'status' => 200,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
        if (is_array($successContext)) {
            $context = array_merge($context, $successContext);
        }
        Logger::info('request_success', $context, $correlationId);
    }

    /** Create or resolve the service only when an endpoint needs it. */
    private function newService(): SpecSearchService
    {
        if ($this->specSearchService !== null) {
            return $this->specSearchService;
        }

        if ($this->specSearchServiceFactory !== null) {
            return ($this->specSearchServiceFactory)();
        }

        return new SpecSearchService();
    }
}
