<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use CatalogSuite\Http\Transport;

use mysqli;
use Throwable;
use CatalogSuite\Support\Logger;
use CatalogSuite\Http\Request as SupportRequest;
use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Http\HttpRequestReader;
use CatalogSuite\Support\Seeder;
use CatalogSuite\Services\MediaStorageService;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Services\SeriesFieldService;
use CatalogSuite\Services\SeriesAttributeService;
use CatalogSuite\Services\ProductService;
use CatalogSuite\Services\PublicCatalogService;
use CatalogSuite\Services\LegacySpecSearchService as SpecSearchService;
use CatalogSuite\Services\LatexTemplateService;
use CatalogSuite\Services\LatexBuildService;
use CatalogSuite\Services\CatalogCsvService;
use CatalogSuite\Services\CatalogTruncateService;

final class CatalogController
{
    private const RESPONSE_STREAMED = '__STREAMED__';

    public function __construct(
        private mysqli $connection,
        private HttpResponder $responder,
        private HttpRequestReader $requestReader,
        private MediaStorageService $mediaStorageService,
        private Seeder $seeder,
        private HierarchyService $hierarchyService,
        private SeriesFieldService $seriesFieldService,
        private SeriesAttributeService $seriesAttributeService,
        private ProductService $productService,
        private CatalogCsvService $csvService,
        private CatalogTruncateService $truncateService,
        private PublicCatalogService $publicCatalogService,
        private SpecSearchService $specSearchService,
        private LatexTemplateService $latexTemplateService,
        private LatexBuildService $latexBuildService,
        private bool $bootstrapOnRequest = true
    ) {
    }

    /**
     * Creates an application with default configuration.
     */
    public static function create(bool $performBootstrap = true): self
    {
        return \CatalogSuite\Support\CatalogFactory::create($performBootstrap);
    }

    /**
     * Ensures schema and seed are in place.
     */
    public function bootstrap(): void
    {
        $demo = (bool) (\CatalogSuite\Support\Config::get('app')['seed_demo'] ?? false);
        $this->seeder->ensureSchema($demo);
        if ($demo) {
            $this->seeder->seedInitialData();
        }
    }

    /**
     * Handles the current HTTP request.
     */
    public function run(): void
    {
        if ($this->bootstrapOnRequest) {
            $this->bootstrap();
        }

        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
        if ($action === '') {
            $this->responder->sendError('ACTION_REQUIRED', 'The action query parameter is required.', 400);
            return;
        }

        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $correlationId = SupportRequest::correlationId();
        $this->responder->setCorrelationId($correlationId);
        $route = SupportRequest::route();
        $startedAt = microtime(true);
        Logger::info('request_start', [
            'route' => $route,
            'method' => $method,
            'action' => $action,
        ], $correlationId);

        try {
            $data = $this->dispatch($action, $method);
            if ($data === self::RESPONSE_STREAMED) {
                Logger::info('request_success', [
                    'route' => $route,
                    'method' => $method,
                    'action' => $action,
                    'status' => 200,
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                ], $correlationId);
                return;
            }
            $payload = ['success' => true];
            if ($data !== null) {
                $payload['data'] = $data;
            }
            $this->responder->sendJson($payload);
        } catch (CatalogApiException $exception) {
            $details = $exception->getDetails();
            if (
                $exception->getErrorCode() === 'METHOD_NOT_ALLOWED'
                && isset($details['expected'])
                && !Transport::headersSent()
            ) {
                Transport::header('Allow: ' . $details['expected']);
            }

            $this->responder->sendError(
                $exception->getErrorCode(),
                $exception->getMessage(),
                $exception->getStatusCode(),
                $details
            );
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $action,
                'status' => $exception->getStatusCode(),
                'errorCode' => $exception->getErrorCode(),
                'exception' => $exception,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);
        } catch (Throwable $exception) {
            error_log('Catalog API failure: ' . $exception->getMessage());
            $this->responder->sendError(
                'SERVER_ERROR',
                'Unexpected server error occurred.',
                500,
                ['error' => $exception->getMessage()]
            );
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $action,
                'status' => 500,
                'errorCode' => 'SERVER_ERROR',
                'exception' => $exception,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);
            return;
        }

        Logger::info('request_success', [
            'route' => $route,
            'method' => $method,
            'action' => $action,
            'status' => 200,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ], $correlationId);

    }

    public function getConnection(): mysqli
    {
        return $this->connection;
    }

    public function getSeeder(): Seeder
    {
        return $this->seeder;
    }

    public function getHierarchyService(): HierarchyService
    {
        return $this->hierarchyService;
    }

    public function getSeriesFieldService(): SeriesFieldService
    {
        return $this->seriesFieldService;
    }

    public function getSeriesAttributeService(): SeriesAttributeService
    {
        return $this->seriesAttributeService;
    }

    public function getProductService(): ProductService
    {
        return $this->productService;
    }

    public function getCsvService(): CatalogCsvService
    {
        return $this->csvService;
    }

    public function getTruncateService(): CatalogTruncateService
    {
        return $this->truncateService;
    }

    public function getLatexTemplateService(): LatexTemplateService
    {
        return $this->latexTemplateService;
    }

    public function getLatexBuildService(): LatexBuildService
    {
        return $this->latexBuildService;
    }

    public function getPublicCatalogService(): PublicCatalogService
    {
        return $this->publicCatalogService;
    }

    /**
     * Dispatches the action to the correct service.
     *
     * @return array<string, mixed>|null
     */
    private function dispatch(string $action, string $method): array|string|null
    {
        switch ($action) {
            case 'v1.ping':
                $this->requestReader->requireMethod('GET', $method);
                return ['message' => 'Catalog backend ready.'];
            case 'v1.listHierarchy':
                $this->requestReader->requireMethod('GET', $method);
                return $this->hierarchyService->listHierarchy();
            case 'v1.saveNode':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->hierarchyService->saveNode($payload);
            case 'v1.deleteNode':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $nodeId = isset($payload['id']) ? (int) $payload['id'] : 0;
                if ($nodeId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Node ID is required.',
                        400,
                        ['id' => 'Node ID is required.']
                    );
                }
                $this->hierarchyService->deleteNode($nodeId);
                return null;
            case 'v1.setSeriesTypstTemplating':
                $this->requestReader->requireMethod('PUT', $method);
                $payload = $this->requestReader->readJsonBody();
                $seriesId = isset($payload['seriesId']) ? (int) $payload['seriesId'] : 0;
                $enabledRaw = $payload['enabled'] ?? null;
                $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $errors = [];
                if ($seriesId <= 0) {
                    $errors['seriesId'] = 'Series ID is required.';
                }
                if ($enabled === null) {
                    $errors['enabled'] = 'Enabled must be boolean.';
                }
                if ($errors !== []) {
                    throw new CatalogApiException('VALIDATION_ERROR', 'Field validation failed.', 400, $errors);
                }
                return $this->hierarchyService->setTypstTemplatingEnabled($seriesId, $enabled);
            case 'v1.listSeriesFields':
                $this->requestReader->requireMethod('GET', $method);
                $seriesId = isset($_GET['seriesId']) ? (int) $_GET['seriesId'] : 0;
                if ($seriesId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Series ID is required.',
                        400,
                        ['seriesId' => 'Series ID is required.']
                    );
                }
                $scope = isset($_GET['scope']) ? (string) $_GET['scope'] : SeriesFieldService::SCOPE_PRODUCT;
                $fields = $this->seriesFieldService->listFields($seriesId, $scope);
                return $fields;
            case 'v1.publicCatalogSnapshot':
                $this->requestReader->requireMethod('GET', $method);
                return $this->publicCatalogService->buildSnapshot();
            case 'v1.specSearchRootCategories':
                $this->requestReader->requireMethod('GET', $method);
                return $this->specSearchService->listRootCategories();
            case 'v1.specSearchProductCategories':
                $this->requestReader->requireMethod('GET', $method);
                $rootId = isset($_GET['root_id']) ? (string) $_GET['root_id'] : '';
                if ($rootId === '') {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Root category is required.',
                        400,
                        ['root_id' => 'Root category is required.']
                    );
                }
                return $this->specSearchService->listProductCategoryGroups($rootId);
            case 'v1.specSearchFacets':
                $this->requestReader->requireMethod('POST', $method);
                $facetsPayload = $this->requestReader->readJsonBody();
                $facetsRootId = isset($facetsPayload['root_id']) ? (string) $facetsPayload['root_id'] : '';
                if ($facetsRootId === '') {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Root category is required.',
                        400,
                        ['root_id' => 'Root category is required.']
                    );
                }
                $facetCategoryIds = $this->normalizeSelectedCategoryIds($facetsPayload['category_ids'] ?? []);
                return $this->specSearchService->listFacets($facetsRootId, $facetCategoryIds);
            case 'v1.specSearchProducts':
                $this->requestReader->requireMethod('POST', $method);
                $productPayload = $this->requestReader->readJsonBody();
                $productRootId = isset($productPayload['root_id']) ? (string) $productPayload['root_id'] : '';
                if ($productRootId === '') {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Root category is required.',
                        400,
                        ['root_id' => 'Root category is required.']
                    );
                }
                $productCategoryIds = $this->normalizeSelectedCategoryIds($productPayload['category_ids'] ?? []);
                $filters = isset($productPayload['filters']) && is_array($productPayload['filters'])
                    ? $productPayload['filters']
                    : [];
                return $this->specSearchService->searchProducts($productRootId, $productCategoryIds, $filters);
            case 'v1.listLatexTemplates':
                $this->requestReader->requireMethod('GET', $method);
                return $this->latexTemplateService->listTemplates();
            case 'v1.getLatexTemplate':
                $this->requestReader->requireMethod('GET', $method);
                $templateId = $this->requireLatexTemplateId();
                return $this->latexTemplateService->getTemplate($templateId);
            case 'v1.createLatexTemplate':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->latexTemplateService->createTemplate($payload);
            case 'v1.updateLatexTemplate':
                $this->requestReader->requireMethod('PUT', $method);
                $templateId = $this->requireLatexTemplateId();
                $payload = $this->requestReader->readJsonBody();
                return $this->latexTemplateService->updateTemplate($templateId, $payload);
            case 'v1.deleteLatexTemplate':
                $this->requestReader->requireMethod('DELETE', $method);
                $templateId = $this->requireLatexTemplateId();
                $this->latexTemplateService->deleteTemplate($templateId);
                return ['deleted' => true];
            case 'v1.buildLatexTemplate':
                $this->requestReader->requireMethod('POST', $method);
                $templateId = $this->requireLatexTemplateId();
                $template = $this->latexTemplateService->getTemplate($templateId);
                $buildResult = $this->latexBuildService->build(
                    $templateId,
                    (string) ($template['latex'] ?? '')
                );
                $updated = $this->latexTemplateService->updatePdfPath(
                    $templateId,
                    (string) $buildResult['relativePath'],
                    $template['pdfPath'] ?? null
                );
                return [
                    'pdfPath' => $updated['pdfPath'],
                    'downloadUrl' => $updated['downloadUrl'],
                    'updatedAt' => $updated['updatedAt'],
                    'stdout' => $buildResult['stdout'],
                    'stderr' => $buildResult['stderr'],
                    'exitCode' => $buildResult['exitCode'],
                    'log' => $buildResult['log'],
                    'correlationId' => $buildResult['correlationId'],
                ];
            case 'v1.getSeriesAttributes':
                $this->requestReader->requireMethod('GET', $method);
                $seriesId = isset($_GET['seriesId']) ? (int) $_GET['seriesId'] : 0;
                if ($seriesId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Series ID is required.',
                        400,
                        ['seriesId' => 'Series ID is required.']
                    );
                }
                return $this->seriesAttributeService->getAttributes($seriesId);
            case 'v1.saveSeriesField':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->seriesFieldService->saveField($payload);
            case 'v1.saveSeriesAttributes':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBodyOrMultipart();
                $files = $this->requestReader->getUploadedFiles('files');
                return $this->seriesAttributeService->saveAttributes($payload, $files);
            case 'v1.deleteSeriesField':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $fieldId = isset($payload['id']) ? (int) $payload['id'] : 0;
                if ($fieldId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Field ID is required.',
                        400,
                        ['id' => 'Field ID is required.']
                    );
                }
                $this->seriesFieldService->deleteField($fieldId);
                return null;
            case 'v1.listProducts':
                $this->requestReader->requireMethod('GET', $method);
                $seriesId = isset($_GET['seriesId']) ? (int) $_GET['seriesId'] : 0;
                if ($seriesId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Series ID is required.',
                        400,
                        ['seriesId' => 'Series ID is required.']
                    );
                }
                $result = $this->productService->listProducts($seriesId);
                return $result['products'];
            case 'v1.saveProduct':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBodyOrMultipart();
                $files = $this->requestReader->getUploadedFiles('files');
                return $this->productService->saveProduct($payload, $files);
            case 'v1.deleteProduct':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $productId = isset($payload['id']) ? (int) $payload['id'] : 0;
                if ($productId <= 0) {
                    throw new CatalogApiException(
                        'VALIDATION_ERROR',
                        'Product ID is required.',
                        400,
                        ['id' => 'Product ID is required.']
                    );
                }
                $this->productService->deleteProduct($productId);
                return null;
            case 'v1.truncateCatalog':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                return $this->truncateService->truncateCatalog($payload);
            case 'v1.listCsvHistory':
                $this->requestReader->requireMethod('GET', $method);
                return $this->csvService->listHistory();
            case 'v1.exportCsv':
                $this->requestReader->requireMethod('POST', $method);
                return $this->csvService->exportCatalog();
            case 'v1.importCsv':
                $this->requestReader->requireMethod('POST', $method);
                if (!isset($_FILES['file'])) {
                    throw new CatalogApiException('CSV_REQUIRED', 'CSV file upload is required.', 400);
                }
                return $this->csvService->importFromUploadedFile($_FILES['file']);
            case 'v1.restoreCsv':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $fileId = isset($payload['id']) ? (string) $payload['id'] : '';
                if ($fileId === '') {
                    throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
                }
                return $this->csvService->restoreCatalog($fileId);
            case 'v1.downloadCsv':
                $this->requestReader->requireMethod('GET', $method);
                $fileId = isset($_GET['id']) ? (string) $_GET['id'] : '';
                if ($fileId === '') {
                    throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
                }
                $this->csvService->streamFile($fileId, $this->responder);
                return self::RESPONSE_STREAMED;
            case 'v1.downloadMedia':
                $this->requestReader->requireMethod('GET', $method);
                $mediaId = isset($_GET['id']) ? (string) $_GET['id'] : '';
                if ($mediaId === '') {
                    throw new CatalogApiException('MEDIA_NOT_FOUND', 'Media id is required.', 404);
                }
                $this->mediaStorageService->streamMedia($mediaId, $this->responder);
                return self::RESPONSE_STREAMED;
            case 'v1.deleteCsv':
                $this->requestReader->requireMethod('POST', $method);
                $payload = $this->requestReader->readJsonBody();
                $fileId = isset($payload['id']) ? (string) $payload['id'] : '';
                if ($fileId === '') {
                    throw new CatalogApiException('CSV_NOT_FOUND', 'CSV id is required.', 404);
                }
                $this->csvService->deleteFile($fileId);
                return ['deleted' => true];
            default:
                throw new CatalogApiException('ACTION_NOT_FOUND', 'Unknown action requested.', 404);
        }
    }

    /**
     * Normalizes category identifiers from client payloads.
     *
     * @param mixed $raw
     *
     * @return array<int, string>
     */
    private function normalizeSelectedCategoryIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $normalized = [];
        foreach ($raw as $value) {
            $string = trim((string) $value);
            if ($string === '') {
                continue;
            }
            $normalized[$string] = true;
        }

        return array_keys($normalized);
    }

    private function requireLatexTemplateId(): int
    {
        $templateId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($templateId <= 0) {
            throw new CatalogApiException(
                'LATEX_VALIDATION_ERROR',
                'Template ID is required.',
                400,
                ['id' => 'Template ID is required.']
            );
        }

        return $templateId;
    }
}
