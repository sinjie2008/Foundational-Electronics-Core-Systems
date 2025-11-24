<?php
declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

use App\SpecSearch\SpecSearchService;
use App\Support\CorrelationId;
use App\Support\Logger;
use App\Support\Response;

$correlationId = CorrelationId::generate();

try {
    $rootId = isset($_GET['root_id']) ? (int) $_GET['root_id'] : 0;
    $service = new SpecSearchService();
    $data = $service->getProductCategories($rootId);
    Response::success(['groups' => $data], 200, $correlationId);
} catch (Throwable $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
}
