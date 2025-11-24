<?php
declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

use App\SpecSearch\SpecSearchService;
use App\Support\CorrelationId;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;

$correlationId = CorrelationId::generate();

try {
    $payload = Request::json();
    $categoryIds = isset($payload['category_ids']) && is_array($payload['category_ids'])
        ? $payload['category_ids']
        : [];

    $service = new SpecSearchService();
    $facets = $service->getFacets($categoryIds);
    Response::success(['facets' => $facets], 200, $correlationId);
} catch (Throwable $e) {
    Logger::error($e->getMessage(), $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
}
