<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../catalog.php';

use App\Support\CorrelationId;
use App\Support\Logger;
use App\Support\Response;

$correlationId = CorrelationId::generate();

try {
    $app = CatalogApplication::create(true);
    $csv = $app->getCsvService();
    $result = $csv->exportCatalog();
    Response::success($result, 200, $correlationId);
} catch (CatalogApiException $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $correlationId);
} catch (Throwable $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
}
