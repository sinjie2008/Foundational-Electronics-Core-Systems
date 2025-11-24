<?php
declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

use App\Catalog\CatalogService;
use App\Support\CorrelationId;
use App\Support\Logger;
use App\Support\Response;

$correlationId = CorrelationId::generate();

try {
    $service = new CatalogService();
    $tree = $service->getHierarchy();
    // Legacy UI expects data to be the array of category nodes directly.
    Response::success($tree, 200, $correlationId);
} catch (Throwable $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
}
