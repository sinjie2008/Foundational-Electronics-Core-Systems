<?php
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Typst\TypstService;
use App\Support\Response;
use App\Support\Request;

$service = new TypstService();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = Request::query('id');
        if ($id) {
            $data = $service->getGlobalVariable((int)$id);
        } else {
            $data = $service->listGlobalVariables();
        }
        Response::success($data);
    } elseif ($method === 'POST') {
        $input = Request::json();
        $data = $service->saveGlobalVariable(
            (string)($input['key'] ?? ''),
            (string)($input['type'] ?? 'text'),
            (string)($input['value'] ?? ''),
            isset($input['id']) ? (int)$input['id'] : null
        );
        Response::success($data);
    } elseif ($method === 'DELETE') {
        $id = Request::query('id');
        if (!$id) {
            throw new InvalidArgumentException("Missing ID");
        }
        $service->deleteGlobalVariable((int)$id);
        Response::success(null);
    } else {
        Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
} catch (Exception $e) {
    Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
}
