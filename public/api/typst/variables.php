<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Support\Request;
use App\Support\Response;
use App\Typst\TypstService;

$service = new TypstService();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $id = Request::query('id');
        if ($id) {
            $data = $service->getGlobalVariable((int) $id);
        } else {
            $data = $service->listGlobalVariables();
        }
        Response::success($data);
    } elseif ($method === 'POST') {
        $isMultipart = (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') !== false) || !empty($_FILES);
        if ($isMultipart) {
            $key = trim((string) ($_POST['key'] ?? ''));
            $type = (string) ($_POST['type'] ?? 'text');
            $value = (string) ($_POST['value'] ?? '');
            $id = isset($_POST['id']) ? (int) $_POST['id'] : null;
            if ($key === '') {
                Response::error('VALIDATION_ERROR', 'Key is required', 400);
                return;
            }
            $fileUpload = isset($_FILES['file']) && is_array($_FILES['file']) ? $_FILES['file'] : null;
            $data = $service->saveGlobalVariable($key, $type, $value, $id, $fileUpload);
        } else {
            $input = Request::json();
            $key = trim((string) ($input['key'] ?? ''));
            if ($key === '') {
                Response::error('VALIDATION_ERROR', 'Key is required', 400);
                return;
            }
            $data = $service->saveGlobalVariable(
                $key,
                (string) ($input['type'] ?? 'text'),
                (string) ($input['value'] ?? ''),
                isset($input['id']) ? (int) $input['id'] : null
            );
        }
        Response::success($data);
    } elseif ($method === 'DELETE') {
        $id = Request::query('id');
        if (!$id) {
            throw new InvalidArgumentException("Missing ID");
        }
        $service->deleteGlobalVariable((int) $id);
        Response::success(null);
    } else {
        Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
} catch (Exception $e) {
    Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
}
