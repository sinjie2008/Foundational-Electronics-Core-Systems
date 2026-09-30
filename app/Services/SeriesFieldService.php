<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use mysqli;
use CatalogSuite\Repositories\SeriesFieldRepository;
use CatalogSuite\Http\CatalogApiException;

final class SeriesFieldService
{
    public const SCOPE_PRODUCT = 'product_attribute';
    public const SCOPE_SERIES = 'series_metadata';
    private SeriesFieldRepository $repository;

    public function __construct(private mysqli $connection)
    {
        $this->repository = new SeriesFieldRepository($connection);
    }

    /**
     * Lists fields for a series.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFields(
        int $seriesId,
        string $fieldScope = SeriesFieldService::SCOPE_PRODUCT
    ): array {
        $normalizedScope = $this->normalizeScope($fieldScope);
        $fields = [];
        foreach ($this->repository->fetchFields($seriesId, $normalizedScope) as $row) {
            $fields[] = [
                'id' => (int) $row['id'],
                'fieldKey' => (string) $row['field_key'],
                'label' => (string) $row['label'],
                'fieldType' => (string) $row['field_type'],
                'fieldScope' => (string) $row['field_scope'],
                'defaultValue' => $row['default_value'],
                'sortOrder' => (int) $row['sort_order'],
                'isRequired' => ((int) $row['is_required']) === 1,
                'publicPortalHidden' => ((int) $row['is_public_portal_hidden']) === 1,
                'backendPortalHidden' => ((int) $row['is_backend_portal_hidden']) === 1,
            ];
        }
        return $fields;
    }

    /**
     * Creates or updates a series field.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveField(array $payload): array
    {
        $fieldId = isset($payload['id']) ? (int) $payload['id'] : null;
        $seriesId = isset($payload['seriesId']) ? (int) $payload['seriesId'] : null;
        $label = isset($payload['label']) ? trim((string) $payload['label']) : '';
        $fieldKey = isset($payload['fieldKey']) ? trim((string) $payload['fieldKey']) : '';
        $fieldType = isset($payload['fieldType']) ? strtolower((string) $payload['fieldType']) : 'text';
        $fieldScopeRaw = isset($payload['fieldScope']) ? (string) $payload['fieldScope'] : SeriesFieldService::SCOPE_PRODUCT;
        $sortOrder = isset($payload['sortOrder']) ? (int) $payload['sortOrder'] : 0;
        $isRequired = isset($payload['isRequired']) ? (bool) $payload['isRequired'] : false;
        $publicPortalHidden = isset($payload['publicPortalHidden'])
            ? (bool) $payload['publicPortalHidden']
            : false;
        $backendPortalHidden = isset($payload['backendPortalHidden'])
            ? (bool) $payload['backendPortalHidden']
            : false;
        $defaultValue = array_key_exists('defaultValue', $payload) ? $payload['defaultValue'] : null;
        $normalizedDefaultValue = $defaultValue !== null ? (string) $defaultValue : null;
        $fieldScope = $this->normalizeScope($fieldScopeRaw);

        $errors = [];
        if ($seriesId === null || $seriesId <= 0) {
            $errors['seriesId'] = 'Series ID is required.';
        }
        if ($label === '') {
            $errors['label'] = 'Field label is required.';
        }
        if ($fieldKey === '') {
            $errors['fieldKey'] = 'Field key is required.';
        }
        if (!in_array($fieldType, ['text', 'number', 'file'], true)) {
            $errors['fieldType'] = 'Unsupported field type.';
        }
        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Field validation failed.', 400, $errors);
        }

        $this->assertSeriesExists($seriesId);
        $targetScope = $fieldScope;
        if ($fieldId !== null) {
            $existingField = $this->getFieldRow($fieldId);
            if ($existingField === null || (int) $existingField['series_id'] !== $seriesId) {
                throw new CatalogApiException('FIELD_NOT_FOUND', 'Series field not found.', 404);
            }
            $existingScope = (string) $existingField['field_scope'];
            if ($existingScope !== $fieldScope) {
                throw new CatalogApiException(
                    'FIELD_SCOPE_IMMUTABLE',
                    'Field scope cannot be changed after creation.',
                    400
                );
            }
            $targetScope = $existingScope;
        }

        $this->ensureUniqueFieldKey($seriesId, $fieldKey, $targetScope, $fieldId);

        $requiredValue = $isRequired ? 1 : 0;
        $publicPortalHiddenValue = $publicPortalHidden ? 1 : 0;
        $backendPortalHiddenValue = $backendPortalHidden ? 1 : 0;

        if ($fieldId !== null) {
            $this->repository->updateField(
                $label,
                $fieldKey,
                $fieldType,
                $normalizedDefaultValue,
                $sortOrder,
                $requiredValue,
                $publicPortalHiddenValue,
                $backendPortalHiddenValue,
                $fieldId,
                $seriesId
            );
            $id = $fieldId;
        } else {
            $id = $this->repository->insertField(
                $seriesId,
                $fieldKey,
                $label,
                $fieldType,
                $targetScope,
                $normalizedDefaultValue,
                $sortOrder,
                $requiredValue,
                $publicPortalHiddenValue,
                $backendPortalHiddenValue
            );
        }

        $fields = $this->listFields($seriesId, $targetScope);
        $field = null;
        foreach ($fields as $item) {
            if ($item['id'] === $id) {
                $field = $item;
                break;
            }
        }
        if ($field === null) {
            throw new CatalogApiException('SERVER_ERROR', 'Unable to load field after save.', 500);
        }

        return [
            'id' => $field['id'],
            'seriesId' => $seriesId,
            'fieldKey' => $field['fieldKey'],
            'label' => $field['label'],
            'fieldType' => $field['fieldType'],
            'fieldScope' => $field['fieldScope'],
            'defaultValue' => $field['defaultValue'],
            'sortOrder' => $field['sortOrder'],
            'isRequired' => $field['isRequired'],
            'publicPortalHidden' => $field['publicPortalHidden'],
            'backendPortalHidden' => $field['backendPortalHidden'],
        ];
    }

    public function deleteField(int $fieldId): void
    {
        if (!$this->repository->fieldExists($fieldId)) {
            throw new CatalogApiException('FIELD_NOT_FOUND', 'Series field not found.', 404);
        }

        $this->repository->deleteField($fieldId);
    }

    public function assertSeriesExists(int $seriesId): void
    {
        if (!$this->repository->seriesExists($seriesId)) {
            throw new CatalogApiException('SERIES_NOT_FOUND', 'Series not found.', 404);
        }
    }

    private function getFieldRow(int $fieldId): ?array
    {
        return $this->repository->findField($fieldId);
    }

    private function ensureUniqueFieldKey(
        int $seriesId,
        string $fieldKey,
        string $fieldScope,
        ?int $excludeId = null
    ): void {
        $count = $this->repository->countFieldKey($seriesId, $fieldScope, $fieldKey, $excludeId);
        if ($count > 0) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'Field key must be unique within the series and scope.',
                400,
                ['fieldKey' => 'Field key must be unique within the series and scope.']
            );
        }
    }

    private function normalizeScope(?string $scope): string
    {
        $value = $scope !== null ? (string) $scope : '';
        $allowed = [SeriesFieldService::SCOPE_PRODUCT, SeriesFieldService::SCOPE_SERIES];

        return in_array($value, $allowed, true) ? $value : SeriesFieldService::SCOPE_PRODUCT;
    }

    /**
     * Returns field definitions keyed by series ID.
     *
     * @param array<int> $seriesIds
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function fetchFieldsForSeriesIds(
        array $seriesIds,
        string $fieldScope = SeriesFieldService::SCOPE_PRODUCT
    ): array {
        if ($seriesIds === []) {
            return [];
        }

        $normalizedScope = $this->normalizeScope($fieldScope);

        /** @var array<int, array<int, array<string, mixed>>> $map */
        $map = [];
        foreach ($this->repository->fetchFieldsForSeriesIds($seriesIds, $normalizedScope) as $row) {
            $seriesId = (int) $row['series_id'];
            $map[$seriesId][] = [
                'id' => (int) $row['id'],
                'fieldKey' => (string) $row['field_key'],
                'label' => (string) $row['label'],
                'fieldType' => (string) $row['field_type'],
                'fieldScope' => (string) $row['field_scope'],
                'defaultValue' => $row['default_value'],
                'sortOrder' => (int) $row['sort_order'],
                'isRequired' => ((int) $row['is_required']) === 1,
                'publicPortalHidden' => ((int) $row['is_public_portal_hidden']) === 1,
                'backendPortalHidden' => ((int) $row['is_backend_portal_hidden']) === 1,
            ];
        }
        return $map;
    }

    /**
     * Returns a field map for a single series keyed by field ID.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSeriesFieldMap(
        int $seriesId,
        string $fieldScope = SeriesFieldService::SCOPE_PRODUCT
    ): array {
        $fields = $this->listFields($seriesId, $fieldScope);
        $map = [];
        foreach ($fields as $field) {
            $map[$field['id']] = $field;
        }

        return $map;
    }
}
