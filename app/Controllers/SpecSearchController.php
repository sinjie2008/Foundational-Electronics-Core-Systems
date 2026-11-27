<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use Closure;
use CatalogSuite\Services\SpecSearchService;
use CatalogSuite\Support\Logger;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Response;
use InvalidArgumentException;
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
            if (array_key_exists('pagination', $payload)) {
                try {
                    $request = $this->readProductPageRequest($payload);
                } catch (InvalidArgumentException $error) {
                    Response::error('validation_error', $error->getMessage(), 400, $correlationId);
                    return ['status' => 400, 'errorCode' => 'validation_error'];
                }

                $result = $this->newService()->getProductPage(
                    $request['root_id'],
                    $request['category_ids'],
                    $request['filters'],
                    $request['options']
                );
                Response::success($result, 200, $correlationId);
                return ['itemCount' => count($result['items'])];
            }

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

    /** @param array<string, mixed> $payload */
    private function readProductPageRequest(array $payload): array
    {
        $pagination = $payload['pagination'];
        if (!is_array($pagination)) {
            throw new InvalidArgumentException('pagination must contain page and per_page.');
        }
        $page = $pagination['page'] ?? null;
        $perPage = $pagination['per_page'] ?? null;
        if (!is_int($page) || $page < 1 || !is_int($perPage) || $perPage < 1 || $perPage > 100) {
            throw new InvalidArgumentException('page must be a positive integer and per_page must be between 1 and 100.');
        }

        $rootId = $payload['root_id'] ?? null;
        if ($rootId !== null && (!is_int($rootId) || $rootId < 1)) {
            throw new InvalidArgumentException('root_id must be a positive integer or null.');
        }

        $search = $payload['search'] ?? '';
        $key = $payload['sort_key'] ?? null;
        $direction = $payload['sort_direction'] ?? 'asc';
        if (!is_string($search) || ($key !== null && (!is_string($key) || $key === '')) || !in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('search and sort_key must be strings and sort_direction must be asc or desc.');
        }

        $filters = $payload['filters'] ?? [];
        if (!is_array($filters)) {
            throw new InvalidArgumentException('filters must map specification keys to lists of strings.');
        }
        foreach ($filters as $field => $values) {
            if (!is_string($field) || $field === '' || !is_array($values) || !array_is_list($values)) {
                throw new InvalidArgumentException('filters must map specification keys to lists of strings.');
            }
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('filters must map specification keys to lists of strings.');
                }
            }
        }

        return [
            'root_id' => $rootId,
            'category_ids' => $this->readPositiveIds($payload['category_ids'] ?? [], 'category_ids'),
            'filters' => $filters,
            'options' => [
                'page' => $page,
                'per_page' => $perPage,
                'search' => trim($search),
                'sort_key' => $key,
                'sort_direction' => $direction,
                'inquiry_ids' => $this->readPositiveIds($payload['inquiry_ids'] ?? [], 'inquiry_ids'),
            ],
        ];
    }

    /** @return list<int> */
    private function readPositiveIds(mixed $ids, string $field): array
    {
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new InvalidArgumentException($field . ' must be a list of positive integers.');
        }
        foreach ($ids as $id) {
            if (!is_int($id) || $id < 1) {
                throw new InvalidArgumentException($field . ' must be a list of positive integers.');
            }
        }
        return array_values(array_unique($ids));
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
        Logger::info($context['status'] < 400 ? 'request_success' : 'request_failed', $context, $correlationId);
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
