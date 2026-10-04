<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use mysqli;
use CatalogSuite\Repositories\ProductRepository;
use Throwable;
use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Services\MediaStorageService;
use CatalogSuite\Services\SeriesFieldService;

final class ProductService
{
    private ProductRepository $repository;

    public function __construct(
        private mysqli $connection,
        private SeriesFieldService $seriesFieldService,
        private MediaStorageService $mediaStorageService
    ) {
        $this->repository = new ProductRepository($connection);
    }

    /**
     * Returns products and series field metadata for a series.
     *
     * @return array<string, mixed>
     */
    public function listProducts(int $seriesId): array
    {
        $fields = $this->seriesFieldService->listFields($seriesId, SeriesFieldService::SCOPE_PRODUCT);
        $products = $this->fetchProductsForSeries($seriesId, $fields);

        return [
            'fields' => $fields,
            'products' => $products,
        ];
    }

    /**
     * Returns products grouped by series for the provided series IDs.
     *
     * @param array<int> $seriesIds
     * @param array<int, array<int, array<string, mixed>>>|null $productFieldDefinitions
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function fetchProductsForSeriesIds(
        array $seriesIds,
        ?array $productFieldDefinitions = null,
        bool $publicOnly = false
    ): array {
        if ($seriesIds === []) {
            return [];
        }

        $productsBySeries = [];
        $productIndexMap = [];
        $productIds = [];
        foreach ($this->repository->fetchProductsForSeriesIds($seriesIds, $publicOnly) as $row) {
            $productId = (int) $row['id'];
            $seriesId = (int) $row['series_id'];
            $productsBySeries[$seriesId] ??= [];
            $productsBySeries[$seriesId][] = [
                'id' => $productId,
                'series_id' => $seriesId,
                'seriesId' => $seriesId,
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'description' => $row['description'],
                'customValues' => [],
            ];
            $productIndexMap[$productId] = [
                'seriesId' => $seriesId,
                'index' => count($productsBySeries[$seriesId]) - 1,
            ];
            $productIds[] = $productId;
        }
        $fieldDefinitions = $productFieldDefinitions
            ?? $this->seriesFieldService->fetchFieldsForSeriesIds($seriesIds, SeriesFieldService::SCOPE_PRODUCT);

        $fieldKeyLookup = [];
        $fieldTypeLookup = [];
        foreach ($fieldDefinitions as $seriesId => $fields) {
            foreach ($fields as $field) {
                $fieldKeyLookup[$seriesId][$field['id']] = $field['fieldKey'];
                $fieldTypeLookup[$seriesId][$field['id']] = $field['fieldType'] ?? 'text';
            }
        }

        if ($productIds !== []) {
            $customValueMap = $this->fetchProductCustomValuesMap($productIds);
            foreach ($customValueMap as $productId => $values) {
                $indexInfo = $productIndexMap[$productId] ?? null;
                if ($indexInfo === null) {
                    continue;
                }
                $seriesId = $indexInfo['seriesId'];
                $fieldLookup = $fieldKeyLookup[$seriesId] ?? [];
                $fieldTypes = $fieldTypeLookup[$seriesId] ?? [];
                $normalized = [];
                foreach ($values as $fieldId => $value) {
                    $fieldKey = $fieldLookup[$fieldId] ?? null;
                    if ($fieldKey !== null) {
                        $fieldType = $fieldTypes[$fieldId] ?? 'text';
                        if ($fieldType === 'file') {
                            $normalized[$fieldKey] = $this->mediaStorageService->buildMediaValue($value);
                        } else {
                            $normalized[$fieldKey] = $value;
                        }
                    }
                }
                $seriesIndex = $indexInfo['index'];
                if (isset($productsBySeries[$seriesId][$seriesIndex])) {
                    $productsBySeries[$seriesId][$seriesIndex]['customValues'] = $normalized;
                }
            }
        }

        foreach ($seriesIds as $seriesId) {
            $productsBySeries[$seriesId] ??= [];
        }

        return $productsBySeries;
    }

    /**
     * Creates or updates a product.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveProduct(array $payload, array $files = []): array
    {
        $productId = isset($payload['id']) ? (int) $payload['id'] : null;
        $seriesIdRaw = $payload['series_id'] ?? $payload['seriesId'] ?? null;
        $seriesId = $seriesIdRaw !== null ? (int) $seriesIdRaw : null;
        $sku = isset($payload['sku']) ? trim((string) $payload['sku']) : '';
        $name = isset($payload['name']) ? trim((string) $payload['name']) : '';
        $description = isset($payload['description']) ? (string) $payload['description'] : null;
        $customValuesPayload = $payload['custom_field_values'] ?? $payload['customValues'] ?? [];
        $customValues = is_array($customValuesPayload) ? $customValuesPayload : [];

        $errors = [];
        if ($seriesId === null || $seriesId <= 0) {
            $errors['series_id'] = 'Series ID is required.';
        }
        if ($sku === '') {
            $errors['sku'] = 'SKU is required.';
        }
        if ($name === '') {
            $errors['name'] = 'Product name is required.';
        }
        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Field validation failed.', 400, $errors);
        }

        $this->seriesFieldService->assertSeriesExists($seriesId);
        $fieldMap = $this->seriesFieldService->getSeriesFieldMap($seriesId, SeriesFieldService::SCOPE_PRODUCT);

        $existing = null;
        $existingRawValues = [];
        if ($productId !== null) {
            $existing = $this->fetchProductById($productId);
            if ($existing === null) {
                throw new CatalogApiException('PRODUCT_NOT_FOUND', 'Product not found.', 404);
            }
            $rawMap = $this->fetchProductCustomValuesMap([$productId]);
            foreach ($fieldMap as $field) {
                $existingRawValues[$field['fieldKey']] = $rawMap[$productId][$field['id']] ?? null;
            }
        }

        $plans = $this->buildCustomValuePlans(
            $fieldMap,
            $customValues,
            $files,
            $existingRawValues,
            $seriesId
        );

        $newFiles = [];
        $deleteAfterCommit = [];

        $this->connection->begin_transaction();
        try {
            if ($productId !== null) {
                $this->repository->updateProduct($sku, $name, $description, $productId, $seriesId);
            } else {
                $productId = $this->repository->insertProduct($seriesId, $sku, $name, $description);
            }

            foreach ($plans as $index => $plan) {
                if ($plan['action'] === 'upload' && isset($plan['file'])) {
                    $result = $this->mediaStorageService->saveSeriesFile(
                        $seriesId,
                        $productId,
                        $plan['field']['fieldKey'],
                        $plan['file']
                    );
                    $plans[$index]['value'] = $result['relativePath'];
                    $newFiles[] = $result['relativePath'];
                    if ($plan['oldPath'] !== null && $plan['oldPath'] !== $result['relativePath']) {
                        $deleteAfterCommit[] = $plan['oldPath'];
                    }
                } elseif ($plan['action'] === 'clear' && $plan['oldPath'] !== null) {
                    $deleteAfterCommit[] = $plan['oldPath'];
                }
            }

            $this->persistProductCustomValuesFromPlans($productId, $plans);

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollback();
            foreach ($newFiles as $path) {
                $this->mediaStorageService->deleteFile($path);
            }
            throw $exception;
        }

        foreach ($deleteAfterCommit as $path) {
            $this->mediaStorageService->deleteFile($path);
        }

        $product = $this->fetchProductById($productId);
        if ($product === null) {
            throw new CatalogApiException('PRODUCT_NOT_FOUND', 'Product not found after save.', 404);
        }

        return $product;
    }

    /**
     * @param array<int, array<string, mixed>> $fieldMap
     * @param array<string, mixed> $customValues
     * @param array<string, array<string, mixed>> $files
     * @param array<string, mixed> $existingValues
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomValuePlans(
        array $fieldMap,
        array $customValues,
        array $files,
        array $existingValues,
        int $seriesId
    ): array {
        $plans = [];
        foreach ($fieldMap as $field) {
            $fieldKey = $field['fieldKey'];
            $fieldType = $field['fieldType'];
            $existingValue = $existingValues[$fieldKey] ?? null;
            $incomingProvided = array_key_exists($fieldKey, $customValues);
            $incomingValue = $incomingProvided ? $customValues[$fieldKey] : null;
            $fileUpload = $files[$fieldKey] ?? null;

            $plan = [
                'field' => $field,
                'value' => $existingValue,
                'action' => 'keep',
                'file' => $fileUpload,
                'oldPath' => ($fieldType === 'file' && $existingValue !== null && $existingValue !== '')
                    ? (string) $existingValue
                    : null,
            ];

            if ($fieldType === 'file') {
                if (is_array($fileUpload)) {
                    $plan['action'] = 'upload';
                } elseif ($incomingProvided && ($incomingValue === '' || $incomingValue === null)) {
                    $plan['action'] = 'clear';
                    $plan['value'] = null;
                }
            } else {
                if ($incomingProvided) {
                    $normalized = $this->normalizeFieldValue($incomingValue);
                    if ($fieldType === 'number' && $normalized !== null && !is_numeric($normalized)) {
                        throw new CatalogApiException(
                            'VALIDATION_ERROR',
                            sprintf('Field %s must be numeric.', $fieldKey),
                            400,
                            ['custom_field_values' => sprintf('%s must be numeric.', $field['label'])]
                        );
                    }
                    $plan['value'] = $normalized;
                }
            }

            $isRequired = $field['isRequired'];
            $hasIncomingFile = $fieldType === 'file' && $plan['action'] === 'upload';
            $finalValue = $plan['value'];
            if ($plan['action'] === 'clear') {
                $finalValue = null;
            }
            if ($isRequired && !$hasIncomingFile && ($finalValue === null || trim((string) $finalValue) === '')) {
                throw new CatalogApiException(
                    'VALIDATION_ERROR',
                    'Custom field validation failed.',
                    400,
                    ['custom_field_values' => sprintf('%s is required.', $field['label'])]
                );
            }

            $plans[] = $plan;
        }

        return $plans;
    }

    /**
     * @param array<int, array<string, mixed>> $plans
     */
    private function persistProductCustomValuesFromPlans(int $productId, array $plans): void
    {
        $this->repository->deleteCustomValues($productId);

        foreach ($plans as $plan) {
            $fieldId = $plan['field']['id'];
            $value = $plan['value'];
            if ($value === null || trim((string) $value) === '') {
                continue;
            }

            $this->repository->insertCustomValue($productId, (int) $fieldId, (string) $value);
        }
    }

    private function normalizeFieldValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    public function deleteProduct(int $productId): void
    {
        $product = $this->fetchProductById($productId);
        if ($product === null) {
            throw new CatalogApiException('PRODUCT_NOT_FOUND', 'Product not found.', 404);
        }

        $seriesId = (int) $product['seriesId'];
        $fieldMap = $this->seriesFieldService->getSeriesFieldMap($seriesId, SeriesFieldService::SCOPE_PRODUCT);
        $valueMap = $this->fetchProductCustomValuesMap([$productId]);
        $pathsToDelete = [];
        foreach ($valueMap[$productId] ?? [] as $fieldId => $value) {
            $field = $fieldMap[$fieldId] ?? null;
            if ($field !== null && ($field['fieldType'] ?? 'text') === 'file') {
                $pathsToDelete[] = $value;
            }
        }

        $this->repository->deleteProduct($productId);

        foreach ($pathsToDelete as $path) {
            $this->mediaStorageService->deleteFile($path);
        }
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, array<string, mixed>>
     */
    private function fetchProductsForSeries(int $seriesId, array $fields): array
    {
        $fieldKeyById = [];
        $fieldTypeById = [];
        foreach ($fields as $field) {
            $fieldKeyById[$field['id']] = $field['fieldKey'];
            $fieldTypeById[$field['id']] = $field['fieldType'] ?? 'text';
        }

        $products = [];
        $productIds = [];
        foreach ($this->repository->fetchProductsForSeries($seriesId) as $row) {
            $productId = (int) $row['id'];
            $products[$productId] = [
                'id' => $productId,
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'description' => $row['description'],
                'customValues' => [],
            ];
            $productIds[] = $productId;
        }
        if ($productIds === []) {
            return array_values($products);
        }

        $valuesMap = $this->fetchProductCustomValuesMap($productIds);
        foreach ($valuesMap as $productId => $customValues) {
            if (!isset($products[$productId])) {
                continue;
            }
            $normalized = [];
            foreach ($customValues as $fieldId => $value) {
                $fieldKey = $fieldKeyById[$fieldId] ?? null;
                $fieldType = $fieldTypeById[$fieldId] ?? 'text';
                if ($fieldKey !== null) {
                    if ($fieldType === 'file') {
                        $normalized[$fieldKey] = $this->mediaStorageService->buildMediaValue($value);
                    } else {
                        $normalized[$fieldKey] = $value;
                    }
                }
            }
            $products[$productId]['customValues'] = $normalized;
        }

        return array_values($products);
    }

    /**
     * Fetches product by ID with custom values.
     *
     * @return array<string, mixed>|null
     */
    private function fetchProductById(int $productId): ?array
    {
        $row = $this->repository->findProduct($productId);

        if ($row === null) {
            return null;
        }

        $seriesId = (int) $row['series_id'];
        $fieldMap = $this->seriesFieldService->getSeriesFieldMap($seriesId, SeriesFieldService::SCOPE_PRODUCT);
        $customValueMap = $this->fetchProductCustomValuesMap([$productId]);
        $rawValues = $customValueMap[$productId] ?? [];
        $normalized = [];
        foreach ($rawValues as $fieldId => $value) {
            $field = $fieldMap[$fieldId] ?? null;
            if ($field === null) {
                continue;
            }
            $fieldKey = $field['fieldKey'];
            if ($field['fieldType'] === 'file') {
                $normalized[$fieldKey] = $this->mediaStorageService->buildMediaValue($value);
            } else {
                $normalized[$fieldKey] = $value;
            }
        }

        return [
            'id' => (int) $row['id'],
            'series_id' => $seriesId,
            'seriesId' => $seriesId,
            'sku' => (string) $row['sku'],
            'name' => (string) $row['name'],
            'description' => $row['description'],
            'customValues' => $normalized,
        ];
    }

    /**
     * @param array<int> $productIds
     *
     * @return array<int, array<int, string>>
     */
    private function fetchProductCustomValuesMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var array<int, array<int, string>> $map */
        $map = [];
        foreach ($this->repository->fetchCustomValueRows($productIds) as $row) {
            $productId = (int) $row['product_id'];
            $fieldId = (int) $row['series_custom_field_id'];
            $map[$productId][$fieldId] = (string) $row['value'];
        }
        return $map;
    }
}
