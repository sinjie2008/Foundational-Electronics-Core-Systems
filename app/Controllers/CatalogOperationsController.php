<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use CatalogSuite\Http\Transport;

use Closure;
use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Support\Logger;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Response;
use CatalogSuite\Support\CatalogFactory;
use Throwable;

/**
 * Handles catalog CSV, PDF, and truncate APIs backed by the legacy catalog application.
 */
final class CatalogOperationsController
{
    /** @var (Closure(): CatalogController)|null */
    private ?Closure $catalogApplicationFactory;

    /**
     * Accept a host-provided application factory; the default stays lazy until
     * an operation reaches its existing application-creation point.
     *
     * @param (callable(): CatalogController)|null $catalogApplicationFactory
     */
    public function __construct(?callable $catalogApplicationFactory = null)
    {
        $this->catalogApplicationFactory = $catalogApplicationFactory === null
            ? null
            : Closure::fromCallable($catalogApplicationFactory);
    }

    /**
     * Stream a saved catalog CSV file.
     */
    public function csvDownload(): void
    {
        $this->run('catalog.csv.download', function (string $correlationId): array {
            $fileId = isset($_GET['id']) ? (string) $_GET['id'] : '';
            if ($fileId === '') {
                throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
            }
            Transport::header('X-Correlation-ID: ' . $correlationId);
            $app = $this->createCatalogApplication();
            $app->getCsvService()->streamFile($fileId, new HttpResponder());
            return ['streamed' => true, 'context' => ['fileId' => $fileId]];
        }, 'CSV_DOWNLOAD_ERROR', null);
    }

    /**
     * Export the catalog as CSV.
     */
    public function csvExport(): void
    {
        $this->run('catalog.csv.export', function (): array {
            $app = $this->createCatalogApplication();
            return ['data' => $app->getCsvService()->exportCatalog()];
        });
    }

    /**
     * List saved CSV import/export history.
     */
    public function csvHistory(): void
    {
        $this->run('catalog.csv.history', function (): array {
            $app = $this->createCatalogApplication();
            return ['data' => $app->getCsvService()->listHistory()];
        });
    }

    /**
     * Import an uploaded CSV file.
     */
    public function csvImport(): void
    {
        $this->run('catalog.csv.import', function (): array {
            if (!isset($_FILES['file'])) {
                throw new CatalogApiException('CSV_REQUIRED', 'CSV file upload is required.', 400);
            }
            $app = $this->createCatalogApplication();
            return [
                'data' => $app->getCsvService()->importFromUploadedFile($_FILES['file']),
                'status' => 202,
            ];
        });
    }

    /**
     * Restore catalog data from a saved CSV file.
     */
    public function csvRestore(): void
    {
        $this->run('catalog.csv.restore', function (): array {
            $payload = Request::json();
            $fileId = isset($payload['id']) ? (string) $payload['id'] : '';
            if ($fileId === '') {
                throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
            }
            $app = $this->createCatalogApplication();
            return [
                'data' => $app->getCsvService()->restoreCatalog($fileId),
                'context' => ['fileId' => $fileId],
            ];
        });
    }

    /**
     * Build and record the PDF for a saved LaTeX template.
     */
    public function pdf(): void
    {
        $this->run('catalog.pdf', function (): array {
            $templateId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
            if ($templateId <= 0) {
                throw new CatalogApiException('LATEX_VALIDATION_ERROR', 'Template ID is required.', 400);
            }

            $app = $this->createCatalogApplication();
            $template = $app->getLatexTemplateService()->getTemplate($templateId);
            $build = $app->getLatexBuildService()->build($templateId, (string) ($template['latex'] ?? ''));
            $updated = $app->getLatexTemplateService()->updatePdfPath(
                $templateId,
                (string) $build['relativePath'],
                $template['pdfPath'] ?? null
            );

            return [
                'data' => [
                    'pdfPath' => $updated['pdfPath'],
                    'downloadUrl' => $updated['downloadUrl'],
                    'updatedAt' => $updated['updatedAt'],
                    'stdout' => $build['stdout'],
                    'stderr' => $build['stderr'],
                    'exitCode' => $build['exitCode'],
                    'log' => $build['log'],
                    'correlationId' => $build['correlationId'],
                ],
                'context' => ['templateId' => $templateId],
            ];
        });
    }

    /**
     * Clear the catalog after the configured confirmation checks pass.
     */
    public function truncate(): void
    {
        $this->run('catalog.truncate', function (string $correlationId): array {
            $app = $this->createCatalogApplication();
            $payload = Request::json();
            if (!isset($payload['correlationId'])) {
                $payload['correlationId'] = $correlationId;
            }
            if (isset($payload['token']) && !isset($payload['confirmToken'])) {
                $payload['confirmToken'] = $payload['token'];
            }
            return ['data' => $app->getTruncateService()->truncateCatalog($payload)];
        });
    }

    /**
     * Run one catalog API operation with its existing logs and response envelope.
     *
     * @param callable(string): array<string, mixed> $operation
     */
    private function run(
        string $action,
        callable $operation,
        string $genericErrorCode = 'internal_error',
        ?string $genericErrorMessage = 'Unexpected error'
    ): void {
        $correlationId = Request::correlationId();
        $route = Request::route();
        $method = Request::method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => $action], $correlationId);

        try {
            $outcome = $operation($correlationId);
        } catch (CatalogApiException $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $action,
                'status' => $e->getStatusCode(),
                'errorCode' => $e->getErrorCode(),
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);
            Response::error($e->getErrorCode(), $e->getMessage(), $e->getStatusCode(), $correlationId);
            return;
        } catch (Throwable $e) {
            $errorMessage = $genericErrorMessage ?? $e->getMessage();
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $action,
                'status' => 500,
                'errorCode' => $genericErrorCode,
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);
            Response::error($genericErrorCode, $errorMessage, 500, $correlationId);
            return;
        }

        $status = (int) ($outcome['status'] ?? 200);
        if (empty($outcome['streamed'])) {
            Response::success($outcome['data'] ?? null, $status, $correlationId);
        }
        $context = [
            'route' => $route,
            'method' => $method,
            'action' => $action,
            'status' => $status,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
        if (isset($outcome['context']) && is_array($outcome['context'])) {
            $context = array_merge($context, $outcome['context']);
        }
        Logger::info('request_success', $context, $correlationId);
    }

    /** Create the application only when a catalog operation needs it. */
    private function createCatalogApplication(): CatalogController
    {
        if ($this->catalogApplicationFactory !== null) {
            return ($this->catalogApplicationFactory)();
        }

        return CatalogFactory::create(true);
    }
}
