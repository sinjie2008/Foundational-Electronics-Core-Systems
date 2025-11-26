<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../catalog.php';

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
    'action' => 'catalog.csv.restore',
], $correlationId);

try {
    $payload = Request::json();
    $fileId = isset($payload['id']) ? (string) $payload['id'] : '';
    if ($fileId === '') {
        throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
    }

    $app = CatalogApplication::create(true);
    $csv = $app->getCsvService();
    $result = $csv->restoreCatalog($fileId);
    Response::success($result, 200, $correlationId);
} catch (CatalogApiException $e) {
    Logger::error('request_failed', [
        'route' => $route,
        'method' => $method,
        'action' => 'catalog.csv.restore',
        'status' => $e->getStatusCode(),
        'errorCode' => $e->getErrorCode(),
        'exception' => $e,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
    ], $correlationId);
    Response::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $correlationId);
} catch (Throwable $e) {
    Logger::error('request_failed', [
        'route' => $route,
        'method' => $method,
        'action' => 'catalog.csv.restore',
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
    'action' => 'catalog.csv.restore',
    'status' => 200,
    'durationMs' => $durationMs,
    'fileId' => $fileId ?? null,
], $correlationId);
