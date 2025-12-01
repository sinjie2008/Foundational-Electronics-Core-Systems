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

try {
    $service = new CatalogService();

    if ($method === 'GET') {
        $seriesId = (int) ($_GET['id'] ?? 0);
        if (!$seriesId) {
            Response::error('validation_error', 'Series ID is required', 400, $correlationId);
            return;
        }

        $details = $service->getSeriesDetails($seriesId);
        if (!$details) {
            Response::error('not_found', 'Series not found', 404, $correlationId);
            return;
        }

        Response::success($details, 200, $correlationId);
    } else {
        Response::error('method_not_allowed', 'Method not allowed', 405, $correlationId);
    }

} catch (Throwable $e) {
    Logger::error('request_failed', [
        'route' => $route,
        'method' => $method,
        'exception' => $e,
    ], $correlationId);
    Response::error('internal_error', 'Unexpected error: ' . $e->getMessage(), 500, $correlationId);
}
