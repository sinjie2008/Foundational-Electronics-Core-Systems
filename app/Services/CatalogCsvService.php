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
use CatalogSuite\Http\MediaAccess;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Services\SeriesFieldService;
use CatalogSuite\Repositories\SeriesAttributeRepository;
use CatalogSuite\Support\MediaRuntime;
use CatalogSuite\Support\MediaStorageRegistry;

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
        // Media UUID columns are exported separately; never export their legacy paths as attributes.
        $reservedMediaKeys = array_column(CatalogCsvProductColumns::definitions(), 0);
        $productFieldKeys = array_values(array_diff($productFieldKeys, $reservedMediaKeys));
        $baselinePartColumns = [
            'acf.length', 'acf.width', 'acf.height', 'acf.impedance', 'acf.dcr',
            'acf.irms', 'acf.qi_standard', 'acf.inductance', 'acf.isat',
            'acf.insertion_loss', 'acf.power', 'acf.terminal_pins',
            'acf.module_length', 'acf.module_width', 'acf.measure_result',
            'acf.dcr_typ', 'acf.core_coper_loss_k', 'acf.core_coper_loss_x',
            'acf.core_coper_loss_y', 'acf.test_frequency', 'product_curve_notes',
        ];
        $productFieldKeys = array_values(array_unique(array_merge(
            $baselinePartColumns, $productFieldKeys
        )));
        $products = $this->fetchProductsWithSeries();
        $customValueMap = $this->fetchProductCustomValues();
        $seriesMetadata = $this->fetchSeriesMetadataForExport();
        $categoryContent = $this->fetchCategoryContentForExport();
        $mediaRepository = \CatalogSuite\Support\MediaSchema::isInstalled($this->connection)
            ? MediaRuntime::repository($this->connection) : null;

        $handle = fopen($filePath, 'wb');
        if ($handle === false) {
            throw new CatalogApiException('CSV_WRITE_ERROR', 'Unable to create export file.', 500);
        }

        // Export the same complete one-CSV template that the importer accepts.
        $seriesColumns = CatalogCsvSeriesColumns::definitions(false);
        $productMediaColumns = CatalogCsvProductColumns::definitions();
        $categoryColumns = CatalogCsvCategoryColumns::definitions();
        $header = array_merge(
            ['category_path', 'product_name', 'import_mode', 'record_type', 'product_description', 'series_display_order'],
            array_keys($categoryColumns),
            array_keys($seriesColumns),
            array_keys($productMediaColumns)
        );
        foreach ($productFieldKeys as $fieldKey) {
            $header[] = $fieldKey;
        }
        fputcsv($handle, $header, ',', '"', '\\');

        foreach ($categories as $categoryId => $category) {
            if ($category['type'] === 'category') {
                $row = [
                    $this->buildCategoryPath($categories, (int) $categoryId),
                    '', 'upsert', 'category', '', '',
                ];
                foreach ($categoryColumns as $key => $definition) {
                    $row[] = $categoryContent[$categoryId][$key] ?? '';
                }
                $row = array_merge(
                    $row,
                    array_fill(0, count($seriesColumns) + count($productMediaColumns) + count($productFieldKeys), '')
                );
                fputcsv($handle, $row, ',', '"', '\\');
                continue;
            }
            if ($category['type'] !== 'series') {
                continue;
            }
            $row = [$this->buildCategoryPath($categories, (int) $categoryId), '', 'upsert', 'series', '', $category['display_order']];
            $row = array_merge($row, array_fill(0, count($categoryColumns), ''));
            $metadata = $seriesMetadata[$categoryId] ?? [];
            if (trim((string) ($metadata['series_features'] ?? '')) === '') {
                $metadata['series_features'] = CatalogCsvSeriesColumns::legacyFeatureList($metadata);
            }
            foreach ($seriesColumns as $definition) {
                $value = $metadata[$definition[0]] ?? '';
                if ($definition[2] === 'file') {
                    $value = $this->resolveExportMediaUuid($mediaRepository, (string) $value, (string) ($definition[3] ?? ''));
                }
                $row[] = $value;
            }
            $row = array_merge($row, array_fill(0, count($productMediaColumns) + count($productFieldKeys), ''));
            fputcsv($handle, $row, ',', '"', '\\');
        }

        foreach ($products as $product) {
            $seriesId = (int) $product['series_id'];
            $categoryPath = $this->buildCategoryPath($categories, $seriesId);
            $productLabel = $product['sku'] !== '' ? $product['sku'] : ($product['name'] ?? '');
            $row = [
                $categoryPath,
                $productLabel ?? '',
                'upsert', // An exported subset is safe to reimport.
                'product',
                $product['description'] ?? '',
                '',
            ];
            $row = array_merge($row, array_fill(0, count($categoryColumns), ''));
            $row = array_merge($row, array_fill(0, count($seriesColumns), ''));
            foreach ($productMediaColumns as $definition) {
                $value = $customValueMap[$product['id']][$definition[0]] ?? '';
                $row[] = $this->resolveExportMediaUuid($mediaRepository, (string) $value, (string) $definition[2]);
            }
            $productCustom = $customValueMap[$product['id']] ?? [];
            foreach ($productFieldKeys as $fieldKey) {
                $row[] = $productCustom[$fieldKey] ?? '';
            }
            fputcsv($handle, $row, ',', '"', '\\');
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
        \CatalogSuite\Http\MediaAccess::guardLegacyWrite($this->connection);
        if (\CatalogSuite\Http\MediaAccess::enabled()) {
            \CatalogSuite\Http\MediaAccess::requirePermission('replace');
        }
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
        \CatalogSuite\Http\MediaAccess::guardLegacyWrite($this->connection);
        if (\CatalogSuite\Http\MediaAccess::enabled()) {
            \CatalogSuite\Http\MediaAccess::requirePermission('replace');
        }
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
        \CatalogSuite\Http\MediaAccess::guardLegacyWrite($this->connection);
        if (\CatalogSuite\Http\MediaAccess::enabled()) {
            \CatalogSuite\Http\MediaAccess::requirePermission('replace');
        }
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
            if ($index === 0) {
                $trimmed = preg_replace('/^\xEF\xBB\xBF/', '', $trimmed) ?? $trimmed;
            }
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
                case 'import_mode':
                case 'record_type':
                case 'product_description':
                case 'series_display_order':
                    if (isset($columns[$lower])) {
                        throw new CatalogApiException('CSV_PARSE_ERROR', 'Duplicate CSV header: ' . $lower, 400);
                    }
                    $columns[$lower] = $index;
                    break;
                default:
                    if (array_key_exists($lower, CatalogCsvSeriesColumns::definitions())
                        || array_key_exists($lower, CatalogCsvProductColumns::definitions())
                        || array_key_exists($lower, CatalogCsvCategoryColumns::definitions())) {
                        if (isset($columns[$lower])) {
                            throw new CatalogApiException('CSV_PARSE_ERROR', 'Duplicate CSV header: ' . $lower, 400);
                        }
                        $columns[$lower] = $index;
                    } else {
                        $attributeColumns[$index] = $trimmed;
                    }
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

        $header = fgetcsv($handle, 0, ',', '"', '\\');
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

        $createdSeries = 0;
        $createdCategories = 0;
        // The legacy format still imports a complete snapshot. Extended one-row
        // templates explicitly opt into non-destructive upsert.
        $importMode = isset($columns['import_mode']) ? null : 'snapshot';
        $seriesOptions = [];
        $categoryOptions = [];
        $mediaPathIds = [];
        $updatedSeriesMetadata = 0;
        $updatedCategoryContent = 0;

        $lineNumber = 1;

        $this->connection->begin_transaction();
        try {
            while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                $lineNumber++;
                if ($this->rowIsEmpty($row)) {
                    continue;
                }
                if (isset($columns['import_mode'])) {
                    $mode = strtolower(trim((string) ($row[$columns['import_mode']] ?? '')));
                    $mode = $mode === '' ? 'upsert' : $mode;
                    $this->assertCsvValue(in_array($mode, ['upsert', 'snapshot'], true), 'import_mode (upsert/snapshot)', $lineNumber);
                    if ($importMode !== null && $importMode !== $mode) {
                        throw new CatalogApiException('CSV_PARSE_ERROR', 'All rows must have the same import_mode.', 400);
                    }
                    $importMode = $mode;
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

                $recordType = isset($columns['record_type'])
                    ? strtolower(trim((string) ($row[$columns['record_type']] ?? 'product'))) : 'product';
                $recordType = $recordType === '' ? 'product' : $recordType;
                $this->assertCsvValue(in_array($recordType, ['product', 'category', 'series'], true), 'record_type (product/category/series)', $lineNumber);
                if ($recordType === 'category') {
                    $this->assertCsvValue(trim((string) ($row[$columns['product_name']] ?? '')) === '', 'blank product_name on category row', $lineNumber);
                    foreach (CatalogCsvSeriesColumns::definitions() as $column => $_definition) {
                        $this->assertCsvValue(trim((string) ($row[$columns[$column] ?? -1] ?? '')) === '', 'blank ' . $column . ' on category row', $lineNumber);
                    }
                    foreach (CatalogCsvProductColumns::definitions() as $column => $_definition) {
                        $this->assertCsvValue(trim((string) ($row[$columns[$column] ?? -1] ?? '')) === '', 'blank ' . $column . ' on category row', $lineNumber);
                    }
                    foreach ($attributeColumns as $index => $fieldKey) {
                        $this->assertCsvValue(trim((string) ($row[$index] ?? '')) === '', 'blank product attribute ' . $fieldKey . ' on category row', $lineNumber);
                    }
                    if ($importMode !== 'upsert') {
                        throw new CatalogApiException(
                            'CSV_MODE_INVALID',
                            'Category-content rows require import_mode=upsert to avoid snapshot deletions.',
                            400
                        );
                    }
                    $categoryId = null;
                    foreach ($segments as $segment) {
                        $categoryId = $this->upsertCategory(
                            $categoryId, $segment, $categoryCache, $touchedCategories, $createdCategories
                        );
                    }
                    foreach (CatalogCsvCategoryColumns::definitions() as $column => $definition) {
                        if (!isset($columns[$column])) {
                            continue;
                        }
                        $value = trim((string) ($row[$columns[$column]] ?? ''));
                        if ($value === '') {
                            continue;
                        }
                        if ($definition[1] === 'image') {
                            $value = $this->resolveCsvMediaPath($value, $column, $lineNumber, $mediaPathIds);
                            $this->assertCsvValue(
                                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1,
                                $column . ' (Media Library UUID or storage/media/ path)', $lineNumber
                            );
                        }
                        if (isset($categoryOptions[$categoryId][$column])
                            && $categoryOptions[$categoryId][$column] !== $value) {
                            throw new CatalogApiException(
                                'CSV_CONFLICT', 'Conflicting Category content for ' . $column,
                                400, ['row' => $lineNumber]
                            );
                        }
                        $categoryOptions[$categoryId][$column] = $value;
                    }
                    continue;
                }

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

                $seriesOrder = null;
                if (isset($columns['series_display_order'])) {
                    $rawOrder = trim((string) ($row[$columns['series_display_order']] ?? ''));
                    if ($rawOrder !== '') {
                        $this->assertCsvValue(ctype_digit($rawOrder) && (float) $rawOrder <= 2147483647, 'series_display_order (non-negative integer)', $lineNumber);
                        $seriesOrder = (int) $rawOrder;
                    }
                }
                $seriesId = $this->upsertSeries(
                    $parentId,
                    $seriesName,
                    $seriesOrder,
                    $seriesCache,
                    $touchedSeries,
                    $createdSeries
                );

                foreach (CatalogCsvSeriesColumns::definitions() as $optionKey => $definition) {
                    if (!isset($columns[$optionKey])) {
                        continue;
                    }
                    $optionValue = trim((string) ($row[$columns[$optionKey]] ?? ''));
                    if ($optionValue === '') {
                        continue; // A blank column does not replace existing content.
                    }
                    if ($optionKey === 'series_features') {
                        $this->assertCsvValue(
                            CatalogCsvSeriesColumns::isFeatureList($optionValue),
                            'series_features (one <ul> or <ol> with <li> items)',
                            $lineNumber
                        );
                    }
                    if ($definition[2] === 'number') {
                        $max = in_array($optionKey, ['image_width_px', 'image_height_px'], true) ? 10000 : 2147483647;
                        $this->assertCsvValue(
                            ctype_digit($optionValue) && (float) $optionValue <= $max
                                && ($max !== 10000 || (int) $optionValue >= 1),
                            $optionKey . ' (whole number, max ' . $max . ')',
                            $lineNumber
                        );
                    }
                    if ($definition[2] === 'file') {
                        $optionValue = $this->resolveCsvMediaPath($optionValue, $optionKey, $lineNumber, $mediaPathIds);
                        $this->assertCsvValue(
                            preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $optionValue) === 1,
                            $optionKey . ' (Media Library UUID or storage/media/ path)',
                            $lineNumber
                        );
                    }
                    if (isset($seriesOptions[$seriesId][$optionKey]) && $seriesOptions[$seriesId][$optionKey] !== $optionValue) {
                        throw new CatalogApiException(
                            'CSV_CONFLICT', 'Conflicting ' . $optionKey . ' within Series ' . $seriesName, 400,
                            ['row' => $lineNumber]
                        );
                    }
                    $seriesOptions[$seriesId][$optionKey] = $optionValue;
                }

                if ($recordType === 'series') {
                    if ($importMode !== 'upsert') {
                        throw new CatalogApiException(
                            'CSV_MODE_INVALID',
                            'Series metadata rows require import_mode=upsert to avoid snapshot deletions.',
                            400
                        );
                    }
                    $this->assertCsvValue(trim((string) ($row[$columns['product_name']] ?? '')) === '', 'blank product_name on series row', $lineNumber);
                    foreach (CatalogCsvProductColumns::definitions() as $mediaColumn => $_field) {
                        $this->assertCsvValue(trim((string) ($row[$columns[$mediaColumn] ?? -1] ?? '')) === '', 'blank ' . $mediaColumn . ' on series row', $lineNumber);
                    }
                    foreach ($attributeColumns as $index => $fieldKey) {
                        $this->assertCsvValue(trim((string) ($row[$index] ?? '')) === '', 'blank product attribute ' . $fieldKey . ' on series row', $lineNumber);
                    }
                    foreach (CatalogCsvCategoryColumns::definitions() as $column => $_definition) {
                        $this->assertCsvValue(trim((string) ($row[$columns[$column] ?? -1] ?? '')) === '', 'blank ' . $column . ' on series row', $lineNumber);
                    }
                    continue;
                }

                foreach (CatalogCsvCategoryColumns::definitions() as $column => $_definition) {
                    $this->assertCsvValue(trim((string) ($row[$columns[$column] ?? -1] ?? '')) === '', 'blank ' . $column . ' on product row', $lineNumber);
                }

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
                        // One-row upserts never manufacture empty custom fields.
                        if ($importMode === 'upsert' && $value === '') {
                            continue;
                        }
                        $label = $this->deriveCustomFieldLabel($fieldKey);
                        $field = $this->seriesFieldService->saveField([
                            'seriesId' => $seriesId,
                            'fieldKey' => $fieldKey,
                            'label' => $label,
                            'fieldType' => 'text',
                            'fieldScope' => SeriesFieldService::SCOPE_PRODUCT,
                            'isRequired' => false,
                            'sortOrder' => $desiredOrder,
                        ], syncMedia: false);
                        $productFieldMap[$fieldKey] = $field;
                    } else {
                        $existingOrder = (int) ($productFieldMap[$fieldKey]['sortOrder'] ?? 0);
                        if (
                            $existingOrder !== $desiredOrder
                            && $importMode !== 'upsert'
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
                            ], syncMedia: false);
                            $productFieldMap[$fieldKey] = $field;
                            $fieldSortSynchronized[$seriesId][$fieldKey] = true;
                        }
                    }
                }

                $productLabel = trim((string) ($row[$columns['product_name']] ?? ''));
                $this->assertCsvValue($productLabel !== '', 'product_name', $lineNumber);

                $description = isset($columns['product_description'])
                    ? trim((string) ($row[$columns['product_description']] ?? '')) : '';
                $productId = $this->upsertProduct(
                    $seriesId,
                    $productLabel,
                    $productLabel,
                    $description === '' ? null : $description,
                    $customValues,
                    $productFieldMap,
                    $importMode === 'upsert'
                );

                foreach (CatalogCsvProductColumns::definitions() as $fileColumn => $productField) {
                    if (!isset($columns[$fileColumn])) {
                        continue;
                    }
                    $mediaId = trim((string) ($row[$columns[$fileColumn]] ?? ''));
                    if ($mediaId === '') {
                        continue;
                    }
                    $mediaId = $this->resolveCsvMediaPath($mediaId, $fileColumn, $lineNumber, $mediaPathIds);
                    $this->saveCsvProductAsset($seriesId, $productId, $productField, $mediaId);
                }
                $touchedProducts[$productId] = true;
                $touchedSeries[$seriesId] = true;
            }

            fclose($handle);

            $updatedSeriesMetadata = $this->saveCsvSeriesOptions($seriesOptions);
            $updatedCategoryContent = $this->saveCsvCategoryOptions($categoryOptions);
            if ($importMode === 'snapshot') {
                $this->pruneMissingRecords(
                    $existingProducts,
                    $existingSeries,
                    $existingCategories,
                    $touchedProducts,
                    $touchedSeries,
                    $touchedCategories
                );
            }

            // Field saves defer relationship reconciliation until the entire CSV is applied.
            \CatalogSuite\Support\MediaRuntime::sync($this->connection);
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
            'importMode' => $importMode ?? 'upsert',
            'updatedSeriesMetadata' => $updatedSeriesMetadata,
            'updatedCategoryContent' => $updatedCategoryContent,
        ];
    }

    /** @param array<string, string> $mediaPathIds */
    private function resolveCsvMediaPath(string $value, string $column, int $lineNumber, array &$mediaPathIds): string
    {
        $path = str_replace('\\', '/', $value);
        $prefix = 'storage/media/';
        if (!str_starts_with($path, $prefix)) {
            return $value;
        }

        try {
            MediaAccess::requirePermission('attach');
            $relativePath = MediaStorageRegistry::cleanRelative(substr($path, strlen($prefix)));
            if (isset($mediaPathIds[$relativePath])) {
                return $mediaPathIds[$relativePath];
            }
            $storage = new MediaStorageRegistry();
            $storage->absolute('catalog_media', $relativePath);
            $repository = MediaRuntime::repository($this->connection);
            $asset = $repository->at('catalog_media', $relativePath);
            if ($asset === null) {
                $identity = MediaAccess::requirePermission('index');
                $asset = (new MediaIndexService($repository, $storage))->register(
                    'catalog_media', $relativePath, 'legacy', null, (string) $identity['actor']
                );
            }
            return $mediaPathIds[$relativePath] = (string) $asset['id'];
        } catch (CatalogApiException $error) {
            throw new CatalogApiException(
                $error->getErrorCode(),
                sprintf('Invalid %s on CSV row %d. %s', $column, $lineNumber, $error->getMessage()),
                $error->getStatusCode(),
                $error->getDetails() + ['row' => $lineNumber, 'column' => $column]
            );
        }
    }

    private function saveCsvCategoryOptions(array $options): int
    {
        $updated = 0;
        $definitions = CatalogCsvCategoryColumns::definitions();
        $stmt = $this->connection->prepare(
            'INSERT INTO category_content (category_id, field_key, value)
             VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        foreach ($options as $categoryId => $fields) {
            foreach ($fields as $key => $value) {
                if ($definitions[$key][1] === 'image') {
                    $asset = MediaRuntime::repository($this->connection)->asset($value);
                    if (!str_starts_with((string) ($asset['mime_type'] ?? ''), 'image/')) {
                        throw new CatalogApiException(
                            'MEDIA_TYPE_INCOMPATIBLE', $key . ' must reference an image.', 422
                        );
                    }
                    // Validate integrity and permissions; persist UUID so export can round-trip.
                    MediaRuntime::selection($this->connection, ['mediaId' => $value], true);
                }
                $stmt->bind_param('iss', $categoryId, $key, $value);
                $stmt->execute();
                $updated++;
            }
        }
        $stmt->close();
        return $updated;
    }

    private function saveCsvProductAsset(int $seriesId, int $productId, array $field, string $mediaId): void
    {
        [$key, $label, $mediaType] = $field;
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $mediaId) !== 1) {
            throw new CatalogApiException('MEDIA_ID_INVALID', $key . ' requires a Media Library UUID or storage/media/ path.', 422);
        }
        $asset = MediaRuntime::repository($this->connection)->asset($mediaId);
        $mime = (string) ($asset['mime_type'] ?? '');
        if (!(($mediaType === 'image' && str_starts_with($mime, 'image/'))
            || ($mediaType === 'pdf' && $mime === 'application/pdf'))) {
            throw new CatalogApiException('MEDIA_TYPE_INCOMPATIBLE', $key . ' requires a ' . $mediaType, 422);
        }
        $fields = $this->buildSeriesFieldMap($seriesId, SeriesFieldService::SCOPE_PRODUCT);
        if (!isset($fields[$key])) {
            $fields[$key] = $this->seriesFieldService->saveField([
                'seriesId' => $seriesId,
                'fieldKey' => $key,
                'label' => $label,
                'fieldType' => 'file',
                'fieldScope' => SeriesFieldService::SCOPE_PRODUCT,
                'sortOrder' => count($fields),
            ], syncMedia: false);
        } elseif ($fields[$key]['fieldType'] !== 'file') {
            throw new CatalogApiException('FIELD_TYPE_INVALID', $key . ' must be a file field', 422);
        }
        $relativePath = MediaRuntime::selection(
            $this->connection, ['mediaId' => $mediaId], $mediaType === 'image'
        );
        $this->repository->mergeProductCustomValues(
            $productId, [$key => $relativePath], [$key => $fields[$key]]
        );
    }

    private function saveCsvSeriesOptions(array $seriesOptions): int
    {
        if ($seriesOptions === []) {
            return 0;
        }
        $values = new SeriesAttributeRepository($this->connection);
        $updated = 0;
        $mapping = CatalogCsvSeriesColumns::definitions();
        foreach ($seriesOptions as $seriesId => $options) {
            $existing = $this->buildSeriesFieldMap((int) $seriesId, SeriesFieldService::SCOPE_SERIES);
            foreach ($options as $column => $raw) {
                [$key, $label, $type] = $mapping[$column];
                if (isset($existing[$key]) && $existing[$key]['fieldType'] !== $type) {
                    throw new CatalogApiException('FIELD_TYPE_INVALID', 'Existing Series field ' . $key . ' must be ' . $type, 422);
                }
                if (!isset($existing[$key])) {
                    $existing[$key] = $this->seriesFieldService->saveField([
                        'seriesId' => (int) $seriesId,
                        'fieldKey' => $key,
                        'label' => $label,
                        'fieldType' => $type,
                        'fieldScope' => SeriesFieldService::SCOPE_SERIES,
                        'sortOrder' => count($existing),
                    ], syncMedia: false);
                }
                $value = $raw;
                if ($type === 'file') {
                    $expectedMime = $mapping[$column][3] ?? '';
                    $asset = MediaRuntime::repository($this->connection)->asset($raw);
                    $mime = (string) ($asset['mime_type'] ?? '');
                    $valid = match ($expectedMime) {
                        'image' => str_starts_with($mime, 'image/'),
                        'pdf' => $mime === 'application/pdf',
                        'glb' => $mime === 'model/gltf-binary',
                        default => false,
                    };
                    if (!$valid) {
                        throw new CatalogApiException(
                            'MEDIA_TYPE_INCOMPATIBLE',
                            $column . ' requires a ' . $expectedMime . ' asset.',
                            422
                        );
                    }
                    $value = MediaRuntime::selection(
                        $this->connection, ['mediaId' => $raw], $expectedMime === 'image'
                    );
                }
                $values->upsertValue((int) $seriesId, (int) $existing[$key]['id'], $value);
                $updated++;
            }
        }
        return $updated;
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
        ?int $displayOrder,
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
            if ($displayOrder !== null && (int) $row['display_order'] !== $displayOrder) {
                $this->repository->updateSeriesDisplayOrder($seriesId, $displayOrder);
            }
        } else {
            $seriesId = $this->repository->insertSeries($parentId, $normalizedName, $displayOrder ?? 0);
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
        array $seriesFieldMap,
        bool $mergeValues = false
    ): int {
        $row = $this->repository->findProductBySku($seriesId, $sku);

        if ($row) {
            $productId = (int) $row['id'];
            $this->repository->updateProductFromImport($name, $description, $productId);
        } else {
            $productId = $this->repository->insertProduct($seriesId, $sku, $name, $description);
        }

        if ($mergeValues) {
            $this->repository->mergeProductCustomValues($productId, $customValues, $seriesFieldMap);
        } else {
            $this->replaceProductCustomValues($productId, $seriesId, $customValues, $seriesFieldMap);
        }

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
    /**
     * Historical legacy file paths are not the same as Media Library UUIDs.
     * Export textual metadata safely; media ID columns stay blank until mapped.
     */
    private function fetchCategoryContentForExport(): array
    {
        $rows = $this->connection->query('SELECT category_id,field_key,value FROM category_content');
        $result = [];
        while ($row = $rows->fetch_assoc()) {
            $result[(int) $row['category_id']][(string) $row['field_key']] = $row['value'];
        }
        $rows->free();
        return $result;
    }

    private function fetchSeriesMetadataForExport(): array
    {
        $result = $this->connection->query(
            "SELECT v.series_id, f.field_key, COALESCE(v.value, f.default_value) AS value
             FROM series_custom_field_value v
             JOIN series_custom_field f ON f.id = v.series_custom_field_id
             WHERE f.field_scope = 'series_metadata'"
        );
        $map = [];
        while ($row = $result->fetch_assoc()) {
            $map[(int) $row['series_id']][(string) $row['field_key']] = $row['value'] ?? '';
        }
        $result->free();
        return $map;
    }

    /** Return a media UUID only when the stored reference resolves to a ready catalog asset. */
    private function resolveExportMediaUuid(?\CatalogSuite\Repositories\MediaRepository $repository, string $value, string $expectedType): string
    {
        if ($repository === null || $value === '') {
            return '';
        }
        try {
            $asset = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1
                ? $repository->asset($value)
                : $repository->byAlias($value);
        } catch (Throwable) {
            return '';
        }
        if ($asset === null || ($asset['status'] ?? '') !== 'ready' || ($asset['visibility'] ?? '') !== 'public_catalog') {
            return '';
        }
        $mime = (string) ($asset['mime_type'] ?? '');
        $matches = match ($expectedType) {
            'image' => str_starts_with($mime, 'image/'),
            'pdf' => $mime === 'application/pdf',
            'glb' => $mime === 'model/gltf-binary',
            default => false,
        };
        return $matches ? (string) ($asset['id'] ?? '') : '';
    }

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
