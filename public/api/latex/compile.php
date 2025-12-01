<?php
declare(strict_types=1);

require __DIR__ . '/../../../app/bootstrap.php';

use App\Latex\LatexService;
use App\Support\Logger;
use App\Support\Request;
use App\Support\Response;

$correlationId = Request::correlationId();
$route = Request::route();
$method = Request::method();

try {
    $service = new LatexService();

    if ($method === 'POST') {
        $data = Request::json();
        $latex = $data['latex'] ?? '';
        $seriesId = (int) ($data['series_id'] ?? 0);

        if (empty($latex) || $seriesId <= 0) {
            Response::error('validation_error', 'LaTeX content and Series ID are required', 400, $correlationId);
            return;
        }

        $result = $service->compileLatex($latex, $seriesId);
        Response::success($result, 200, $correlationId);
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
