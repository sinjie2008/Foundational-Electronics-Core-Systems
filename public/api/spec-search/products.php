<?php
declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

use App\SpecSearch\SpecSearchService;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;

$correlationId = Request::correlationId();
$route = Request::route();
$method = Request::method();
$startedAt = microtime(true);
Logger::info('request_start', [
    'route' => $route,
    'method' => $method,
    'action' => 'spec-search.products',
], $correlationId);

try {
    $payload = Request::json();
    $categoryIds = isset($payload['category_ids']) && is_array($payload['category_ids'])
        ? $payload['category_ids']
        : [];
    $filters = isset($payload['filters']) && is_array($payload['filters'])
        ? $payload['filters']
        : [];

    $service = new SpecSearchService();
    $products = $service->getProducts($categoryIds, $filters);
    Response::success(['items' => $products, 'total' => count($products)], 200, $correlationId);
} catch (Throwable $e) {
    Logger::error('request_failed', [
        'route' => $route,
        'method' => $method,
        'action' => 'spec-search.products',
        'status' => 500,
        'errorCode' => 'internal_error',
        'exception' => $e,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
    ], $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
    return;
}

$durationMs = (int) round((microtime(true) - $startedAt) * 1000);
Logger::info('request_success', [
    'route' => $route,
    'method' => $method,
    'action' => 'spec-search.products',
    'status' => 200,
    'durationMs' => $durationMs,
    'itemCount' => isset($products) ? count($products) : 0,
], $correlationId);
