<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../catalog.php';

use App\Support\Request;
use App\Support\Logger;
use App\Support\Response;

$correlationId = Request::correlationId();
$route = Request::route();
$method = Request::method();
$startedAt = microtime(true);
Logger::info('request_start', [
    'route' => $route,
    'method' => $method,
    'action' => 'catalog.pdf',
], $correlationId);

try {
    $templateId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($templateId <= 0) {
        throw new CatalogApiException('LATEX_VALIDATION_ERROR', 'Template ID is required.', 400);
    }

    $app = CatalogApplication::create(true);
    $template = $app->getLatexTemplateService()->getTemplate($templateId);
    $build = $app->getLatexBuildService()->build(
        $templateId,
        (string) ($template['latex'] ?? '')
    );
    $updated = $app->getLatexTemplateService()->updatePdfPath(
        $templateId,
        (string) $build['relativePath'],
        $template['pdfPath'] ?? null
    );

    $result = [
        'pdfPath' => $updated['pdfPath'],
        'downloadUrl' => $updated['downloadUrl'],
        'updatedAt' => $updated['updatedAt'],
        'stdout' => $build['stdout'],
        'stderr' => $build['stderr'],
        'exitCode' => $build['exitCode'],
        'log' => $build['log'],
        'correlationId' => $build['correlationId'],
    ];

    Response::success($result, 200, $correlationId);
} catch (CatalogApiException $e) {
    Logger::error('request_failed', [
        'route' => $route,
        'method' => $method,
        'action' => 'catalog.pdf',
        'status' => $e->getStatusCode(),
        'errorCode' => $e->getErrorCode(),
        'exception' => $e,
        'templateId' => $templateId ?? null,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
    ], $correlationId);
    Response::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $correlationId);
} catch (Throwable $e) {
    Logger::error('request_failed', [
        'route' => $route,
        'method' => $method,
        'action' => 'catalog.pdf',
        'status' => 500,
        'errorCode' => 'internal_error',
        'exception' => $e,
        'templateId' => $templateId ?? null,
        'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
    ], $correlationId);
    Response::error('internal_error', 'Unexpected error', 500, $correlationId);
    return;
}

$durationMs = (int) round((microtime(true) - $startedAt) * 1000);
Logger::info('request_success', [
    'route' => $route,
    'method' => $method,
    'action' => 'catalog.pdf',
    'status' => 200,
    'durationMs' => $durationMs,
    'templateId' => $templateId ?? null,
], $correlationId);
