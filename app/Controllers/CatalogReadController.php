<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use Closure;
use CatalogSuite\Services\CatalogService;
use CatalogSuite\Support\Logger;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Response;
use Throwable;

/**
 * Handles catalog read API endpoints while preserving their existing envelopes.
 */
final class CatalogReadController
{
    /** @var (Closure(): CatalogService)|null */
    private ?Closure $catalogServiceFactory;

    /**
     * Accept an existing service or a lazy factory for host application bindings.
     *
     * The default keeps constructing the service inside each endpoint's existing
     * error boundary, preserving standalone database-connection timing.
     *
     * @param (callable(): CatalogService)|null $catalogServiceFactory
     */
    public function __construct(
        private ?CatalogService $catalogService = null,
        ?callable $catalogServiceFactory = null
    ) {
        $this->catalogServiceFactory = $catalogServiceFactory === null
            ? null
            : Closure::fromCallable($catalogServiceFactory);
    }

    /**
     * Return the catalog hierarchy.
     */
    public function hierarchy(): void
    {
        $correlationId = Request::correlationId();
        $route = Request::route();
        $method = Request::method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => 'catalog.hierarchy'], $correlationId);

        try {
            $tree = $this->newService()->getHierarchy();
            Response::success($tree, 200, $correlationId);
        } catch (Throwable $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => 'catalog.hierarchy',
                'status' => 500,
                'errorCode' => 'internal_error',
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);
            Response::error('internal_error', 'Unexpected error', 500, $correlationId);
            return;
        }

        Logger::info('request_success', [
            'route' => $route,
            'method' => $method,
            'action' => 'catalog.hierarchy',
            'status' => 200,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ], $correlationId);
    }

    /**
     * Search categories, series, and products by query string.
     */
    public function search(): void
    {
        $correlationId = Request::correlationId();
        $route = Request::route();
        $method = Request::method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => 'catalog.search'], $correlationId);

        try {
            $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
            $matches = $this->newService()->search($query);
            Response::success($matches, 200, $correlationId);
        } catch (Throwable $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => 'catalog.search',
                'status' => 500,
                'errorCode' => 'internal_error',
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);
            Response::error('internal_error', 'Unexpected error', 500, $correlationId);
            return;
        }

        Logger::info('request_success', [
            'route' => $route,
            'method' => $method,
            'action' => 'catalog.search',
            'status' => 200,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ], $correlationId);
    }

    /**
     * Return details for one series.
     */
    public function seriesDetails(): void
    {
        $correlationId = Request::correlationId();
        $route = Request::route();
        $method = Request::method();

        try {
            if ($method === 'GET') {
                $seriesId = (int) ($_GET['id'] ?? 0);
                if (!$seriesId) {
                    Response::error('validation_error', 'Series ID is required', 400, $correlationId);
                    return;
                }

                $details = $this->newService()->getSeriesDetails($seriesId);
                if (!$details) {
                    Response::error('not_found', 'Series not found', 404, $correlationId);
                    return;
                }
                Response::success($details, 200, $correlationId);
            } else {
                Response::error('method_not_allowed', 'Method not allowed', 405, $correlationId);
            }
        } catch (Throwable $e) {
            Logger::error('request_failed', ['route' => $route, 'method' => $method, 'exception' => $e], $correlationId);
            Response::error('internal_error', 'Unexpected error: ' . $e->getMessage(), 500, $correlationId);
        }
    }

    /** Create or resolve the service only when an endpoint needs it. */
    private function newService(): CatalogService
    {
        if ($this->catalogService !== null) {
            return $this->catalogService;
        }

        if ($this->catalogServiceFactory !== null) {
            return ($this->catalogServiceFactory)();
        }

        return new CatalogService();
    }
}
