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
    'action' => 'catalog.csv.download',
], $correlationId);

try {
    $fileId = isset($_GET['id']) ? (string) $_GET['id'] : '';
    if ($fileId === '') {
        throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
    }

    header('X-Correlation-ID: ' . $correlationId);
    $app = CatalogApplication::create(true);
    $csv = $app->getCsvService();
    $responder = new HttpResponder();
    $csv->streamFile($fileId, $responder);
    Logger::info('request_success', [
        'route' => $route,
        'method' => $method,
        'action' => 'catalog.csv.download',
        'status' => 200,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        'fileId' => $fileId,
    ], $correlationId);
} catch (CatalogApiException $e) {
    Logger::error('request_failed', [
        'route' => $route,
        'method' => $method,
        'action' => 'catalog.csv.download',
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
        'action' => 'catalog.csv.download',
        'status' => 500,
        'errorCode' => 'CSV_DOWNLOAD_ERROR',
        'exception' => $e,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
    ], $correlationId);
    Response::error('CSV_DOWNLOAD_ERROR', $e->getMessage(), 500, $correlationId);
}
