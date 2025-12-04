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
        $seriesId = (int) (Request::query('seriesId') ?? 0);
        if ($seriesId <= 0) {
            throw new \InvalidArgumentException('seriesId is required.');
        }
        $data = $service->getSeriesPreference($seriesId);
        Response::success($data);
        return;
    }

    if ($method === 'PUT') {
        $input = Request::json();
        $seriesId = (int) ($input['seriesId'] ?? 0);
        if ($seriesId <= 0) {
            throw new \InvalidArgumentException('seriesId is required.');
        }

        $lastGlobalTemplateId = $input['lastGlobalTemplateId'] ?? null;
        if ($lastGlobalTemplateId === '' || $lastGlobalTemplateId === false) {
            $lastGlobalTemplateId = null;
        }
        if ($lastGlobalTemplateId !== null) {
            if (!is_numeric($lastGlobalTemplateId)) {
                throw new \InvalidArgumentException('lastGlobalTemplateId must be numeric or null.');
            }
            $lastGlobalTemplateId = (int) $lastGlobalTemplateId;
            if ($lastGlobalTemplateId <= 0) {
                $lastGlobalTemplateId = null;
            }
        }

        $data = $service->saveSeriesPreference($seriesId, $lastGlobalTemplateId);
        Response::success($data);
        return;
    }

    Response::error('METHOD_NOT_ALLOWED', 'Method not allowed', 405);
} catch (\InvalidArgumentException $e) {
    Response::error('VALIDATION_ERROR', $e->getMessage(), 400);
} catch (Exception $e) {
    Response::error('INTERNAL_ERROR', $e->getMessage(), 500);
}
