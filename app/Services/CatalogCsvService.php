<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Support\Config;

use mysqli;
use CatalogSuite\Repositories\CatalogCsvRepository;
use RuntimeException;
use Throwable;
use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Services\SeriesFieldService;

final class CatalogCsvService
{
    private CatalogCsvRepository $repository;
    private string $storageDir;

    public function __construct(
        private mysqli $connection,
        private HierarchyService $hierarchyService,
        private SeriesFieldService $seriesFieldService
    ) {
        $this->repository = new CatalogCsvRepository($connection);
        $this->storageDir = Config::get('app')['storage']['csv'];
        $this->ensureStorageDirectory();
    }

    private function ensureStorageDirectory(): void
    {
        if (!is_dir($this->storageDir)) {
            $directory = $this->storageDir;
            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException('Unable to create CSV storage directory.');
            }
        }
    }

    private function sanitizeOriginalName(?string $name): string
    {
        $name = $name !== null ? basename((string) $name) : 'import.csv';
        $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        if ($sanitized === null || $sanitized === '' || $sanitized === '.' || $sanitized === '..') {
            return 'import.csv';
        }

        return $sanitized;
    }

    private function currentTimestamp(): string
    {
        return (new \DateTimeImmutable('now'))->format('YmdHis');
    }

    private function buildFileId(string $type, string $timestamp, ?string $originalName = null): string
    {
        if ($type === 'export') {
            return $timestamp . '_export.csv';
        }

        $suffix = $originalName !== null ? '_' . $originalName : '';

        return $timestamp . '_import' . $suffix;
    }

    private function buildFilePath(string $fileId): string
    {
        return $this->storageDir . '/' . $fileId;
    }

    private function buildDownloadName(string $fileId): string
    {
        if (preg_match('/^(\\d{14})_export\\.csv$/', $fileId, $matches) === 1) {
            return 'catalog_' . $matches[1] . '.csv';
        }
        if (preg_match('/^(\\d{14})_import_(.+)$/', $fileId, $matches) === 1) {
            return $matches[2];
        }

        return $fileId;
    }

    private function assertValidFileId(string $fileId): void
    {
        if (
            preg_match('/^\\d{14}_(export|import)(?:_[A-Za-z0-9._-]+)?\\.csv$/', $fileId) !== 1
        ) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'Invalid CSV identifier.', 404);
        }
    }

    public function listHistory(): array
    {
        $this->ensureStorageDirectory();
        $entries = @scandir($this->storageDir);
        if ($entries === false) {
            return ['files' => []];
        }

        $files = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (preg_match('/^(\\d{14})_(export|import)(?:_(.+))?\\.csv$/', $entry, $matches) !== 1) {
                continue;
            }
            $filePath = $this->buildFilePath($entry);
            if (!is_file($filePath)) {
                continue;
            }
            $timestampRaw = $matches[1];
            $type = $matches[2];
            $dt = \DateTimeImmutable::createFromFormat('YmdHis', $timestampRaw) ?: new \DateTimeImmutable('@0');
            $files[] = [
                'id' => $entry,
                'name' => $type === 'export'
                    ? 'catalog_' . $timestampRaw . '.csv'
                    : ($matches[3] ?? $entry),
                'type' => $type,
                'timestamp' => $dt->format(\DateTimeInterface::ATOM),
                'size' => @filesize($filePath) ?: 0,
            ];
        }

        usort(
            $files,
            static function (array $a, array $b): int {
                return strcmp($b['id'], $a['id']);
            }
        );

        return [
            'files' => $files,
            'audits' => $this->readTruncateAudits(),
            'truncateInProgress' => $this->isTruncateInProgress(),
        ];
    }

    public function exportCatalog(): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        $timestamp = $this->currentTimestamp();
        $fileId = $this->buildFileId('export', $timestamp);
        $filePath = $this->buildFilePath($fileId);

        $categories = $this->fetchAllCategories();
        $productFieldKeys = $this->fetchAllCustomFieldKeys(SeriesFieldService::SCOPE_PRODUCT);
        $products = $this->fetchProductsWithSeries();
        $customValueMap = $this->fetchProductCustomValues();

        $handle = fopen($filePath, 'wb');
        if ($handle === false) {
            throw new CatalogApiException('CSV_WRITE_ERROR', 'Unable to create export file.', 500);
        }

        $header = ['category_path', 'product_name'];
        foreach ($productFieldKeys as $fieldKey) {
            $header[] = $fieldKey;
        }
        fputcsv($handle, $header);

        foreach ($products as $product) {
            $seriesId = (int) $product['series_id'];
            $categoryPath = $this->buildCategoryPath($categories, $seriesId);
            $productLabel = $product['sku'] !== '' ? $product['sku'] : ($product['name'] ?? '');
            $row = [
                $categoryPath,
                $productLabel ?? '',
            ];
            $productCustom = $customValueMap[$product['id']] ?? [];
            foreach ($productFieldKeys as $fieldKey) {
                $row[] = $productCustom[$fieldKey] ?? '';
            }
            fputcsv($handle, $row);
        }

        fclose($handle);

        return [
            'id' => $fileId,
            'name' => 'catalog_' . $timestamp . '.csv',
            'type' => 'export',
            'timestamp' => (\DateTimeImmutable::createFromFormat('YmdHis', $timestamp) ?: new \DateTimeImmutable('now'))->format(\DateTimeInterface::ATOM),
            'size' => @filesize($filePath) ?: 0,
        ];
    }

    public function importFromUploadedFile(array $file): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        if (!isset($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new CatalogApiException('CSV_REQUIRED', 'CSV file upload is required.', 400);
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new CatalogApiException('CSV_REQUIRED', 'Uploaded CSV is invalid.', 400);
        }

        $originalName = $this->sanitizeOriginalName($file['name'] ?? 'import.csv');
        $timestamp = $this->currentTimestamp();
        $fileId = $this->buildFileId('import', $timestamp, $originalName);
        $destination = $this->buildFilePath($fileId);

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new CatalogApiException('CSV_UPLOAD_ERROR', 'Failed to store uploaded CSV.', 500);
        }

        $result = $this->processImport($destination, $fileId, $originalName);
        $result['fileId'] = $fileId;

        return $result;
    }

    public function importFromPath(string $filePath, string $originalName = 'import.csv'): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'Import source CSV not found.', 404);
        }

        $originalName = $this->sanitizeOriginalName($originalName);
        $timestamp = $this->currentTimestamp();
        $fileId = $this->buildFileId('import', $timestamp, $originalName);
        $destination = $this->buildFilePath($fileId);

        if (!copy($filePath, $destination)) {
            throw new CatalogApiException('CSV_UPLOAD_ERROR', 'Failed to copy CSV for import.', 500);
        }

        $result = $this->processImport($destination, $fileId, $originalName);
        $result['fileId'] = $fileId;

        return $result;
    }

    public function restoreCatalog(string $fileId): array
    {
        $this->ensureStorageDirectory();
        $this->assertTruncateNotRunning();
        $fileId = trim($fileId);
        $this->assertValidFileId($fileId);
        $filePath = $this->buildFilePath($fileId);
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }

        $result = $this->processImport($filePath, $fileId, $this->buildDownloadName($fileId));
        $result['fileId'] = $fileId;

        return $result;
    }

    public function streamFile(string $fileId, HttpResponder $responder): void
    {
        $fileId = trim($fileId);
        $this->assertValidFileId($fileId);

        $filePath = $this->buildFilePath($fileId);
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }

        $responder->sendFile($filePath, $this->buildDownloadName($fileId));
    }

    public function deleteFile(string $fileId): void
    {
        $fileId = trim($fileId);
        $this->assertValidFileId($fileId);

        $filePath = $this->buildFilePath($fileId);
        if (!is_file($filePath)) {
            throw new CatalogApiException('CSV_NOT_FOUND', 'CSV file not found.', 404);
        }

        if (!unlink($filePath)) {
            throw new CatalogApiException('CSV_DELETE_ERROR', 'Unable to delete CSV file.', 500);
        }
    }

    /**
     * @param array<int, string> $header
     * @return array{columns: array<string, int>, attributes: array<int, string>}
     */
    private function analyseHeader(array $header): array
    {
        $columns = [];
        $attributeColumns = [];

        foreach ($header as $index => $rawHeader) {
            $trimmed = trim((string) $rawHeader);
            if ($trimmed === '') {
                continue;
            }
            $lower = strtolower($trimmed);
            switch ($lower) {
                case 'category_path':
                    $columns['category_path'] = $index;
                    break;
                case 'product_name':
                    $columns['product_name'] = $index;
                    break;
                default:
                    $attributeColumns[$index] = $trimmed;
                    break;
            }
        }

        $required = ['category_path', 'product_name'];
        foreach ($required as $column) {
            if (!array_key_exists($column, $columns)) {
                throw new CatalogApiException(
                    'CSV_PARSE_ERROR',
                    sprintf('Missing required column "%s" in CSV header.', $column),
                    400
                );
            }
        }

        return ['columns' => $columns, 'attributes' => $attributeColumns];
    }
    private function processImport(string $filePath, string $fileId, string $originalName): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new CatalogApiException('CSV_PARSE_ERROR', 'Unable to read CSV file.', 400);
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            throw new CatalogApiException('CSV_PARSE_ERROR', 'CSV file is empty.', 400);
        }

        $analysis = $this->analyseHeader($header);
        $columns = $analysis['columns'];
        /** @var array<int, string> $attributeColumns */
        $attributeColumns = $analysis['attributes'];
        $attributeOrder = [];
        $order = 0;
        foreach ($attributeColumns as $index => $fieldKey) {
            $normalizedKey = trim((string) $fieldKey);
            if ($normalizedKey === '') {
                throw new CatalogApiException(
                    'CSV_PARSE_ERROR',
                    sprintf('Header column at index %d is blank.', $index),
                    400
                );
            }
            $attributeColumns[$index] = $normalizedKey;
            if (!array_key_exists($normalizedKey, $attributeOrder)) {
                $attributeOrder[$normalizedKey] = $order++;
            }
        }

        $existingProducts = $this->fetchExistingProductIds();
        $existingSeries = $this->fetchExistingSeriesIds();
        $existingCategories = $this->fetchExistingCategoryIds();

        $touchedProducts = [];
        $touchedSeries = [];
        $touchedCategories = [];

        $categoryCache = [];
        $seriesCache = [];
        $seriesFieldCache = [];
        $fieldSortSynchronized = [];

        $createdCategories = 0;
        $createdSeries = 0;

        $lineNumber = 1;

        $this->connection->begin_transaction();
        try {
            while (($row = fgetcsv($handle)) !== false) {
                $lineNumber++;
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                $categoryPath = trim((string) ($row[$columns['category_path']] ?? ''));
                $this->assertCsvValue($categoryPath !== '', 'category_path', $lineNumber);

                $segments = array_values(
                    array_filter(
                        array_map(
                            static fn (string $segment): string => trim($segment),
                            explode('>', $categoryPath)
                        ),
                        static fn (string $segment): bool => $segment !== ''
                    )
                );
                $this->assertCsvValue($segments !== [], 'category_path', $lineNumber);

                $seriesName = array_pop($segments);
                $this->assertCsvValue($seriesName !== null && $seriesName !== '', 'series_name', $lineNumber);

                $parentId = null;
                foreach ($segments as $segment) {
                    $parentId = $this->upsertCategory(
                        $parentId,
                        $segment,
                        $categoryCache,
                        $touchedCategories,
                        $createdCategories
                    );
                }

                $seriesId = $this->upsertSeries(
                    $parentId,
                    $seriesName,
                    0,
                    $seriesCache,
                    $touchedSeries,
                    $createdSeries
                );

                if (!isset($seriesFieldCache[$seriesId])) {
                    $seriesFieldCache[$seriesId] = [
                        SeriesFieldService::SCOPE_PRODUCT => null,
                    ];
                }
                if ($seriesFieldCache[$seriesId][SeriesFieldService::SCOPE_PRODUCT] === null) {
                    $seriesFieldCache[$seriesId][SeriesFieldService::SCOPE_PRODUCT] = $this->buildSeriesFieldMap(
                        $seriesId,
                        SeriesFieldService::SCOPE_PRODUCT
                    );
                }

                $productFieldMap =& $seriesFieldCache[$seriesId][SeriesFieldService::SCOPE_PRODUCT];

                $customValues = [];
                foreach ($attributeColumns as $index => $fieldKey) {
                    $value = isset($row[$index]) ? trim((string) $row[$index]) : '';
                    $customValues[$fieldKey] = $value;
                    $desiredOrder = $attributeOrder[$fieldKey] ?? 0;
                    if (!isset($productFieldMap[$fieldKey])) {
                        $label = $this->deriveCustomFieldLabel($fieldKey);
                        $field = $this->seriesFieldService->saveField([
                            'seriesId' => $seriesId,
                            'fieldKey' => $fieldKey,
                            'label' => $label,
                            'fieldType' => 'text',
                            'fieldScope' => SeriesFieldService::SCOPE_PRODUCT,
                            'isRequired' => false,
                            'sortOrder' => $desiredOrder,
                        ]);
                        $productFieldMap[$fieldKey] = $field;
                    } else {
                        $existingOrder = (int) ($productFieldMap[$fieldKey]['sortOrder'] ?? 0);
                        if (
                            $existingOrder !== $desiredOrder
                            && !isset($fieldSortSynchronized[$seriesId][$fieldKey])
                        ) {
                            $field = $this->seriesFieldService->saveField([
                                'id' => (int) $productFieldMap[$fieldKey]['id'],
                                'seriesId' => $seriesId,
                                'fieldKey' => $fieldKey,
                                'label' => $productFieldMap[$fieldKey]['label'],
                                'fieldType' => $productFieldMap[$fieldKey]['fieldType'],
                                'fieldScope' => SeriesFieldService::SCOPE_PRODUCT,
                                'defaultValue' => $productFieldMap[$fieldKey]['defaultValue'] ?? null,
                                'isRequired' => (bool) ($productFieldMap[$fieldKey]['isRequired'] ?? false),
                                'sortOrder' => $desiredOrder,
                            ]);
                            $productFieldMap[$fieldKey] = $field;
                            $fieldSortSynchronized[$seriesId][$fieldKey] = true;
                        }
                    }
                }

                $productLabel = trim((string) ($row[$columns['product_name']] ?? ''));
                $this->assertCsvValue($productLabel !== '', 'product_name', $lineNumber);

                $productId = $this->upsertProduct(
                    $seriesId,
                    $productLabel,
                    $productLabel,
                    null,
                    $customValues,
                    $productFieldMap
                );

                $touchedProducts[$productId] = true;
                $touchedSeries[$seriesId] = true;
            }

            fclose($handle);

            $this->pruneMissingRecords(
                $existingProducts,
                $existingSeries,
                $existingCategories,
                $touchedProducts,
                $touchedSeries,
                $touchedCategories
            );

            $this->connection->commit();
        } catch (Throwable $exception) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->connection->rollback();
            throw $exception;
        }

        return [
            'importedProducts' => count($touchedProducts),
            'createdSeries' => $createdSeries,
            'createdCategories' => $createdCategories,
        ];
    }

    private function deriveCustomFieldLabel(string $fieldKey): string
    {
        $label = str_replace(['_', '.'], ' ', $fieldKey);
        $label = preg_replace('/\s+/', ' ', $label) ?? '';
        $label = trim($label);

        if ($label === '') {
            return 'Custom Field';
        }

        return ucwords($label);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildSeriesFieldMap(int $seriesId, string $scope): array
    {
        $fieldList = $this->seriesFieldService->listFields($seriesId, $scope);
        $fieldMap = [];
        foreach ($fieldList as $field) {
            $fieldMap[$field['fieldKey']] = $field;
        }

        return $fieldMap;
    }

    private function upsertCategory(
        ?int $parentId,
        string $name,
        array &$cache,
        array &$touchedCategories,
        int &$createdCategories
    ): int {
        $normalizedName = trim($name);
        $cacheKey = ($parentId === null ? 'root' : (string) $parentId) . '|' . strtolower($normalizedName);
        if (isset($cache[$cacheKey])) {
            $id = $cache[$cacheKey];
            $touchedCategories[$id] = true;
            return $id;
        }

        $row = $this->repository->findCategory($parentId, $normalizedName);

        if ($row) {
            $id = (int) $row['id'];
        } else {
            $id = $this->repository->insertCategory($parentId, $normalizedName);
            $createdCategories++;
        }

        $cache[$cacheKey] = $id;
        $touchedCategories[$id] = true;

        return $id;
    }

    private function upsertSeries(
        ?int $parentId,
        string $name,
        int $displayOrder,
        array &$cache,
        array &$touchedSeries,
        int &$createdSeries
    ): int {
        $normalizedName = trim($name);
        $cacheKey = ($parentId === null ? 'root' : (string) $parentId) . '|' . strtolower($normalizedName);
        if (isset($cache[$cacheKey])) {
            $seriesId = $cache[$cacheKey];
            $touchedSeries[$seriesId] = true;
            return $seriesId;
        }

        $row = $this->repository->findSeries($parentId, $normalizedName);

        if ($row) {
            $seriesId = (int) $row['id'];
            if ((int) $row['display_order'] !== $displayOrder) {
                $this->repository->updateSeriesDisplayOrder($seriesId, $displayOrder);
            }
        } else {
            $seriesId = $this->repository->insertSeries($parentId, $normalizedName, $displayOrder);
            $createdSeries++;
        }

        $cache[$cacheKey] = $seriesId;
        $touchedSeries[$seriesId] = true;

        return $seriesId;
    }

    /**
     * @param array<string, array<string, mixed>> $seriesFieldMap
     */
    private function upsertProduct(
        int $seriesId,
        string $sku,
        string $name,
        ?string $description,
        array $customValues,
        array $seriesFieldMap
    ): int {
        $row = $this->repository->findProductBySku($seriesId, $sku);

        if ($row) {
            $productId = (int) $row['id'];
            $this->repository->updateProductFromImport($name, $description, $productId);
        } else {
            $productId = $this->repository->insertProduct($seriesId, $sku, $name, $description);
        }

        $this->replaceProductCustomValues($productId, $seriesId, $customValues, $seriesFieldMap);

        return $productId;
    }

    /**
     * @param array<string, array<string, mixed>> $seriesFieldMap
     */
    private function replaceProductCustomValues(
        int $productId,
        int $seriesId,
        array $customValues,
        array $seriesFieldMap
    ): void {
        $this->repository->replaceProductCustomValues($productId, $customValues, $seriesFieldMap);
    }

    private function fetchExistingProductIds(): array
    {
        return $this->repository->fetchProductIds();
    }

    private function fetchExistingSeriesIds(): array
    {
        return $this->repository->fetchSeriesIds();
    }

    private function fetchExistingCategoryIds(): array
    {
        return $this->repository->fetchCategoryIds();
    }

    private function deleteProducts(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->chunkIds($ids) as $chunk) {
            $this->repository->deleteProducts($chunk);
        }
    }

    private function deleteSeries(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->chunkIds($ids) as $chunk) {
            $this->repository->deleteSeries($chunk);
        }
    }

    private function deleteCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        foreach ($this->chunkIds($ids) as $chunk) {
            $this->repository->deleteCategories($chunk);
        }
    }

    /**
     * @return array<int, array<int>>
     */
    private function chunkIds(array $ids, int $chunkSize = 500): array
    {
        $chunks = [];
        $buffer = [];
        foreach ($ids as $id) {
            $buffer[] = (int) $id;
            if (count($buffer) >= $chunkSize) {
                $chunks[] = $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    private function pruneMissingRecords(
        array $existingProducts,
        array $existingSeries,
        array $existingCategories,
        array $touchedProducts,
        array $touchedSeries,
        array $touchedCategories
    ): void {
        $productsToDelete = array_diff($existingProducts, array_keys($touchedProducts));
        $this->deleteProducts($productsToDelete);

        $seriesToDelete = array_diff($existingSeries, array_keys($touchedSeries));
        $this->deleteSeries($seriesToDelete);

        $categoriesToDelete = array_diff($existingCategories, array_keys($touchedCategories));
        $this->deleteCategories($categoriesToDelete);
    }

    private function assertCsvValue(bool $condition, string $field, int $lineNumber): void
    {
        if (!$condition) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                sprintf('Row %d: %s is required.', $lineNumber, $field),
                400,
                ['row' => $lineNumber, 'field' => $field]
            );
        }
    }

    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllCategories(): array
    {
        $categories = [];
        foreach ($this->repository->fetchCategoryRows() as $row) {
            $categories[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'display_order' => (int) $row['display_order'],
            ];
        }
        return $categories;
    }

    /**
     * @return array<int, string>
     */
    private function fetchAllCustomFieldKeys(string $scope = SeriesFieldService::SCOPE_PRODUCT): array
    {
        return $this->repository->fetchCustomFieldKeys($scope);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsWithSeries(): array
    {
        $products = [];
        foreach ($this->repository->fetchProductsWithSeriesRows() as $row) {
            $products[] = [
                'id' => (int) $row['id'],
                'series_id' => (int) $row['series_id'],
                'series_name' => (string) $row['series_name'],
                'series_display_order' => (int) $row['series_display_order'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'description' => $row['description'] !== null ? (string) $row['description'] : '',
            ];
        }
        return $products;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function fetchProductCustomValues(): array
    {
        $map = [];
        foreach ($this->repository->fetchProductCustomValueRows() as $row) {
            $productId = (int) $row['product_id'];
            $fieldKey = (string) $row['field_key'];
            $map[$productId][$fieldKey] = (string) $row['value'];
        }
        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     */
    private function buildCategoryPath(array $categories, int $seriesId): string
    {
        if (!isset($categories[$seriesId])) {
            return '';
        }

        $path = [$categories[$seriesId]['name']];
        $currentId = $categories[$seriesId]['parent_id'] ?? null;
        while ($currentId !== null && isset($categories[$currentId])) {
            array_unshift($path, $categories[$currentId]['name']);
            $currentId = $categories[$currentId]['parent_id'];
        }

        return implode(' > ', $path);
    }

    private function assertTruncateNotRunning(): void
    {
        if ($this->isTruncateInProgress()) {
            throw new CatalogApiException(
                'TRUNCATE_IN_PROGRESS',
                'Catalog truncate in progress. Try again after it completes.',
                409
            );
        }
    }

    private function isTruncateInProgress(): bool
    {
        return $this->repository->isTruncateInProgress();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readTruncateAudits(int $limit = 20): array
    {
        $logPath = Config::get('app')['truncate']['audit_log'];
        if (!is_file($logPath)) {
            return [];
        }

        $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }

        if ($limit <= 0) {
            $limit = 20;
        }
        $lines = array_slice($lines, -$limit);
        $entries = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $entries[] = $decoded;
            }
        }

        usort(
            $entries,
            static function (array $a, array $b): int {
                $left = (string) ($a['timestamp'] ?? '');
                $right = (string) ($b['timestamp'] ?? '');
                return strcmp($right, $left);
            }
        );

        return $entries;
    }
}
