<?php
declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

use App\Catalog\CatalogService;
use App\Support\CorrelationId;
use App\Support\Logger;
use App\Support\Response;

$correlationId = CorrelationId::generate();

try {
    $query = isset($_GET['q']) ? (string) $_GET['q'] : '';
    $service = new CatalogService();
    $matches = $service->search($query);
    // Legacy UI expects data to be the array of matches directly.
    Response::success($matches, 200, $correlationId);
} catch (Throwable $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
}
