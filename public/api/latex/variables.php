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

    if ($method === 'GET') {
        $variables = $service->listGlobalVariables();
        Response::success($variables, 200, $correlationId);
    } elseif ($method === 'POST') {
        $data = Request::json();
        $key = trim((string) ($data['key'] ?? ''));
        $type = (string) ($data['type'] ?? 'text');
        $value = (string) ($data['value'] ?? '');
        $id = isset($data['id']) ? (int) $data['id'] : null;
        if ($key === '') {
            Response::error('validation_error', 'Key is required', 400, $correlationId);
            return;
        }
        $variable = $service->saveGlobalVariable($key, $type, $value, $id);
        if ($id !== null && $variable === null) {
            Response::error('not_found', 'Variable not found', 404, $correlationId);
            return;
        }
        Response::success($variable, 200, $correlationId);
    } elseif ($method === 'DELETE') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            Response::error('validation_error', 'ID is required', 400, $correlationId);
            return;
        }
        $service->deleteGlobalVariable($id);
        Response::success(['deleted' => true], 200, $correlationId);
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
