<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Typst\TypstService;
use App\Support\Response;
use App\Support\Request;

$service = new TypstService();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'POST') {
        $input = Request::json();
        $code = (string)($input['typst'] ?? '');
        $seriesId = isset($input['seriesId']) ? (int)$input['seriesId'] : null;
        
        $result = $service->compileTypst($code, $seriesId);
        Response::success($result);
    } else {
        Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
    }
} catch (Exception $e) {
    Response::error('COMPILE_ERROR', $e->getMessage(), 500);
}
