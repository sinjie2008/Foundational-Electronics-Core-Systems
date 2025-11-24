<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../catalog.php';

use App\Support\CorrelationId;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;

$correlationId = CorrelationId::generate();

try {
    $app = CatalogApplication::create(true);
    $service = $app->getTruncateService();

    $payload = Request::json();
    if (!isset($payload['correlationId'])) {
        $payload['correlationId'] = $correlationId;
    }
    if (isset($payload['token']) && !isset($payload['confirmToken'])) {
        $payload['confirmToken'] = $payload['token'];
    }

    $result = $service->truncateCatalog($payload);
    Response::success($result, 200, $correlationId);
} catch (CatalogApiException $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $correlationId);
} catch (Throwable $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
}
