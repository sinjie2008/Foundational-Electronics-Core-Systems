<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Typst\TypstService;
use App\Support\Response;
use App\Support\Request;

$service = new TypstService();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = Request::query('id');
        $seriesId = Request::query('seriesId');
        
        if ($id) {
            $data = $service->getTemplate((int)$id);
        } elseif ($seriesId) {
            $data = $service->listSeriesTemplates((int)$seriesId);
        } else {
            $data = $service->listGlobalTemplates();
        }
        Response::success($data);
    } elseif ($method === 'POST') {
        $input = Request::json();
        $title = (string)($input['title'] ?? '');
        $desc = (string)($input['description'] ?? '');
        $code = (string)($input['typst'] ?? '');
        $seriesId = isset($input['seriesId']) ? (int)$input['seriesId'] : null;
        
        if ($seriesId) {
            $data = $service->createSeriesTemplate($seriesId, $title, $desc, $code);
        } else {
            $data = $service->createGlobalTemplate($title, $desc, $code);
        }
        Response::success($data);
    } elseif ($method === 'PUT') {
        $input = Request::json();
        $id = (int)($input['id'] ?? 0);
        $title = (string)($input['title'] ?? '');
        $desc = (string)($input['description'] ?? '');
        $code = (string)($input['typst'] ?? '');
        $seriesId = isset($input['seriesId']) ? (int)$input['seriesId'] : null;
        
        if ($seriesId) {
            $data = $service->updateSeriesTemplate($id, $seriesId, $title, $desc, $code);
        } else {
            $data = $service->updateGlobalTemplate($id, $title, $desc, $code);
        }
        Response::success($data);
    } elseif ($method === 'DELETE') {
        $id = Request::query('id');
        if (!$id) {
            throw new InvalidArgumentException("Missing ID");
        }
        $service->deleteTemplate((int)$id);
        Response::success(null);
    } else {
        Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
} catch (Exception $e) {
    Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
}
