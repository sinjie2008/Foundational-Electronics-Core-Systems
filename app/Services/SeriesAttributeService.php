<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use mysqli;
use CatalogSuite\Repositories\SeriesAttributeRepository;
use Throwable;
use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Services\MediaStorageService;
use CatalogSuite\Services\SeriesFieldService;

final class SeriesAttributeService
{
    private SeriesAttributeRepository $repository;

    public function __construct(
        private mysqli $connection,
        private SeriesFieldService $seriesFieldService,
        private MediaStorageService $mediaStorageService
    ) {
        $this->repository = new SeriesAttributeRepository($connection);
    }

    public function getAttributes(int $seriesId): array
    {
        $this->seriesFieldService->assertSeriesExists($seriesId);
        $payloads = $this->fetchMetadataPayloads([$seriesId]);

        return $payloads[$seriesId] ?? [
            'seriesId' => $seriesId,
            'definitions' => $this->seriesFieldService->listFields($seriesId, SeriesFieldService::SCOPE_SERIES),
            'values' => [],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function saveAttributes(array $payload, array $files = [], bool $wrapInTransaction = true): array
    {
        $seriesId = isset($payload['seriesId']) ? (int) $payload['seriesId'] : null;
        $valuesPayload = isset($payload['values']) && is_array($payload['values'])
            ? $payload['values']
            : [];

        if ($seriesId === null || $seriesId <= 0) {
            throw new CatalogApiException(
                'VALIDATION_ERROR',
                'Series ID is required.',
                400,
                ['seriesId' => 'Series ID is required.']
            );
        }

        $this->seriesFieldService->assertSeriesExists($seriesId);
        $definitions = $this->seriesFieldService->listFields($seriesId, SeriesFieldService::SCOPE_SERIES);
        $definitionMap = [];
        foreach ($definitions as $definition) {
            $definitionMap[$definition['fieldKey']] = $definition;
        }

        $rawValueMap = $this->fetchValueMapForSeries([$seriesId]);
        $existingValues = [];
        foreach ($definitionMap as $fieldKey => $definition) {
            $fieldId = (int) $definition['id'];
            $existingValues[$fieldKey] = $rawValueMap[$seriesId][$fieldId] ?? null;
        }

        $errors = [];
        $plans = [];
        foreach ($definitionMap as $fieldKey => $definition) {
            $fieldType = $definition['fieldType'] ?? 'text';
            $existingValue = $existingValues[$fieldKey] ?? null;
            $incomingProvided = array_key_exists($fieldKey, $valuesPayload);
            $incomingValue = $incomingProvided ? $valuesPayload[$fieldKey] : null;
            $fileUpload = $files[$fieldKey] ?? null;

            $plan = [
                'definition' => $definition,
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
                    $normalized = $this->normalizeValue($incomingValue);
                    if ($fieldType === 'number' && $normalized !== null && !is_numeric($normalized)) {
                        $errors[$fieldKey] = 'Must be numeric.';
                    }
                    $plan['value'] = $normalized;
                }
            }

            $hasIncomingFile = $fieldType === 'file' && $plan['action'] === 'upload';
            $finalValue = $plan['action'] === 'clear' ? null : $plan['value'];
            if (($definition['isRequired'] ?? false) && !$hasIncomingFile && ($finalValue === null || trim((string) $finalValue) === '')) {
                $errors[$fieldKey] = 'Field is required.';
            }

            $plans[] = $plan;
        }

        if ($errors !== []) {
            throw new CatalogApiException('VALIDATION_ERROR', 'Series metadata validation failed.', 400, $errors);
        }

        $newFiles = [];
        $deleteAfterCommit = [];

        if ($wrapInTransaction) {
            $this->connection->begin_transaction();
        }
        try {
            foreach ($plans as $index => $plan) {
                $field = $plan['definition'];
                $fieldId = (int) $field['id'];
                $fieldKey = $field['fieldKey'];
                $fieldType = $field['fieldType'] ?? 'text';
                $value = $plan['value'];

                if ($fieldType === 'file') {
                    if ($plan['action'] === 'upload' && isset($plan['file'])) {
                        $result = $this->mediaStorageService->saveSeriesFile(
                            $seriesId,
                            $seriesId,
                            $fieldKey,
                            $plan['file']
                        );
                        $value = $result['relativePath'];
                        $plans[$index]['value'] = $value;
                        $newFiles[] = $value;
                        if ($plan['oldPath'] !== null && $plan['oldPath'] !== $value) {
                            $deleteAfterCommit[] = $plan['oldPath'];
                        }
                    } elseif ($plan['action'] === 'clear' && $plan['oldPath'] !== null) {
                        $deleteAfterCommit[] = $plan['oldPath'];
                        $value = null;
                    }
                }

                $this->upsertSeriesValue($seriesId, $fieldId, $this->normalizeValue($value));
            }

            if ($wrapInTransaction) {
                $this->connection->commit();
            }
        } catch (Throwable $exception) {
            if ($wrapInTransaction) {
                $this->connection->rollback();
            }
            foreach ($newFiles as $path) {
                $this->mediaStorageService->deleteFile($path);
            }
            throw $exception;
        }

        foreach ($deleteAfterCommit as $path) {
            $this->mediaStorageService->deleteFile($path);
        }

        return $this->getAttributes($seriesId);
    }

    /**
     * @param array<int> $seriesIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchMetadataPayloads(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $definitionsBySeries = $this->seriesFieldService->fetchFieldsForSeriesIds(
            $seriesIds,
            SeriesFieldService::SCOPE_SERIES
        );
        $valueMap = $this->fetchValueMapForSeries($seriesIds);

        $payloads = [];
        foreach ($seriesIds as $seriesId) {
            $definitions = $definitionsBySeries[$seriesId] ?? [];
            $values = [];
            $fieldValues = $valueMap[$seriesId] ?? [];
            foreach ($definitions as $definition) {
                $fieldId = (int) $definition['id'];
                $fieldKey = $definition['fieldKey'];
                $rawValue = $fieldValues[$fieldId] ?? ($definition['defaultValue'] ?? null);
                if (($definition['fieldType'] ?? 'text') === 'file') {
                    $values[$fieldKey] = $this->mediaStorageService->buildMediaValue($rawValue);
                } else {
                    $values[$fieldKey] = $rawValue;
                }
            }

            $payloads[$seriesId] = [
                'seriesId' => $seriesId,
                'definitions' => $definitions,
                'values' => $values,
            ];
        }

        return $payloads;
    }

    /**
     * @param array<int> $seriesIds
     *
     * @return array<int, array<int, string>>
     */
    private function fetchValueMapForSeries(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        /** @var array<int, array<int, string>> $map */
        $map = [];
        foreach ($this->repository->fetchValueRows($seriesIds) as $row) {
            $seriesId = (int) $row['series_id'];
            $fieldId = (int) $row['series_custom_field_id'];
            $map[$seriesId][$fieldId] = (string) ($row['value'] ?? '');
        }
        return $map;
    }

    private function upsertSeriesValue(int $seriesId, int $fieldId, ?string $value): void
    {
        $this->repository->upsertValue($seriesId, $fieldId, $value);
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
