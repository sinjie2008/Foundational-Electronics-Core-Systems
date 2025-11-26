<?php
declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

use App\Catalog\CatalogService;
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
    'action' => 'catalog.search',
], $correlationId);

try {
    $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
    $service = new CatalogService();
    $matches = $service->search($query);
    // Legacy UI expects data to be the array of matches directly.
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

$durationMs = (int) round((microtime(true) - $startedAt) * 1000);
Logger::info('request_success', [
    'route' => $route,
    'method' => $method,
    'action' => 'catalog.search',
    'status' => 200,
    'durationMs' => $durationMs,
], $correlationId);
