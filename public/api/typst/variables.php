<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Support\Request;
use App\Support\Response;
use App\Typst\TypstService;

$service = new TypstService();
$method = $_SERVER['REQUEST_METHOD'];
$correlationId = Request::correlationId();

try {
    if ($method === 'GET') {
        $id = Request::query('id');
        $seriesId = (int) (Request::query('seriesId') ?? 0);
        $data = null;
        if ($seriesId > 0) {
            $data = $id ? $service->getScopedVariable((int) $id, $seriesId) : $service->listScopedVariables($seriesId);
        } else {
            $data = $id ? $service->getGlobalVariable((int) $id) : $service->listGlobalVariables();
        }
        Response::success($data, 200, $correlationId);
    } elseif ($method === 'POST') {
        $isMultipart = (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) || !empty($_FILES);
        $seriesId = 0;
        if ($isMultipart) {
            $key = trim((string) ($_POST['key'] ?? ''));
            $type = (string) ($_POST['type'] ?? 'text');
            $value = (string) ($_POST['value'] ?? '');
            $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
            $seriesId = isset($_POST['seriesId']) ? (int) $_POST['seriesId'] : 0;
            if ($key === '') {
                Response::error('VALIDATION_ERROR', 'Key is required', 400, $correlationId);
                return;
            }
            if ($seriesId < 0) {
                Response::error('VALIDATION_ERROR', 'seriesId must be positive when provided.', 400, $correlationId);
                return;
            }
            $fileUpload = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;
            $data = $seriesId > 0
                ? $service->saveScopedVariable($seriesId, $key, $type, $value, $id, $fileUpload)
                : $service->saveGlobalVariable($key, $type, $value, $id, $fileUpload);
        } else {
            $input = Request::json();
            $key = trim((string) ($input['key'] ?? ''));
            $seriesId = isset($input['seriesId']) ? (int) $input['seriesId'] : 0;
            if ($key === '') {
                Response::error('VALIDATION_ERROR', 'Key is required', 400, $correlationId);
                return;
            }
            if ($seriesId < 0) {
                Response::error('VALIDATION_ERROR', 'seriesId must be positive when provided.', 400, $correlationId);
                return;
            }
            $type = (string) ($input['type'] ?? 'text');
            $value = (string) ($input['value'] ?? '');
            $id = isset($input['id']) ? (int) $input['id'] : null;
            $data = $seriesId > 0
                ? $service->saveScopedVariable($seriesId, $key, $type, $value, $id)
                : $service->saveGlobalVariable($key, $type, $value, $id);
        }
        Response::success($data, 200, $correlationId);
    } elseif ($method === 'DELETE') {
        $id = Request::query('id');
        $seriesId = (int) (Request::query('seriesId') ?? 0);
        if (!$id || (int) $id <= 0) {
            Response::error('VALIDATION_ERROR', 'ID is required', 400, $correlationId);
            return;
        }
        if ($seriesId > 0) {
            $service->deleteScopedVariable((int) $id, $seriesId);
        } else {
            $service->deleteGlobalVariable((int) $id);
        }
        Response::success(null, 200, $correlationId);
    } else {
        Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405, $correlationId);
    }
} catch (Exception $e) {
    Response::error('INTERNAL_ERROR', $e->getMessage(), 500, $correlationId);
}
