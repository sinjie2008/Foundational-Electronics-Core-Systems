<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/**
 * Persists and reads custom field definitions for catalog series.
 */
final class SeriesFieldRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Returns field rows for a series and scope.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchFields(int $seriesId, string $fieldScope): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, field_key, label, field_type, field_scope, default_value, sort_order, is_required,
                    is_public_portal_hidden, is_backend_portal_hidden
             FROM series_custom_field
             WHERE series_id = ? AND field_scope = ?
             ORDER BY sort_order, id'
        );
        $stmt->bind_param('is', $seriesId, $fieldScope);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Updates a custom field definition.
     */
    public function updateField(
        string $label,
        string $fieldKey,
        string $fieldType,
        ?string $defaultValue,
        int $sortOrder,
        int $required,
        int $publicPortalHidden,
        int $backendPortalHidden,
        int $fieldId,
        int $seriesId
    ): void {
        $stmt = $this->connection->prepare(
            'UPDATE series_custom_field
             SET label = ?, field_key = ?, field_type = ?, default_value = ?, sort_order = ?, is_required = ?,
                 is_public_portal_hidden = ?, is_backend_portal_hidden = ?
             WHERE id = ? AND series_id = ?'
        );
        $stmt->bind_param(
            'ssssiiiiii',
            $label,
            $fieldKey,
            $fieldType,
            $defaultValue,
            $sortOrder,
            $required,
            $publicPortalHidden,
            $backendPortalHidden,
            $fieldId,
            $seriesId
        );
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Inserts a custom field definition and returns its ID.
     */
    public function insertField(
        int $seriesId,
        string $fieldKey,
        string $label,
        string $fieldType,
        string $fieldScope,
        ?string $defaultValue,
        int $sortOrder,
        int $required,
        int $publicPortalHidden,
        int $backendPortalHidden
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field (
                series_id,
                field_key,
                label,
                field_type,
                field_scope,
                default_value,
                sort_order,
                is_required,
                is_public_portal_hidden,
                is_backend_portal_hidden
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isssssiiii',
            $seriesId,
            $fieldKey,
            $label,
            $fieldType,
            $fieldScope,
            $defaultValue,
            $sortOrder,
            $required,
            $publicPortalHidden,
            $backendPortalHidden
        );
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }

    /**
     * Checks whether a custom field exists.
     */
    public function fieldExists(int $fieldId): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT id FROM series_custom_field WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $fieldId);
        $stmt->execute();
        $result = $stmt->get_result();
        $field = $result->fetch_assoc();
        $stmt->close();

        return $field !== null;
    }

    /**
     * Deletes a custom field definition.
     */
    public function deleteField(int $fieldId): void
    {
        $stmt = $this->connection->prepare('DELETE FROM series_custom_field WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $fieldId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Checks whether a row is a series node.
     */
    public function seriesExists(int $seriesId): bool
    {
        $stmt = $this->connection->prepare(
            "SELECT id FROM category WHERE id = ? AND type = 'series' LIMIT 1"
        );
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    /**
     * Loads the series and scope associated with a custom field.
     *
     * @return array<string, mixed>|null
     */
    public function findField(int $fieldId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, series_id, field_scope FROM series_custom_field WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $fieldId);
        $stmt->execute();
        $result = $stmt->get_result();
        $field = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $field;
    }

    /**
     * Counts matching field keys, optionally excluding an existing field.
     */
    public function countFieldKey(int $seriesId, string $fieldScope, string $fieldKey, ?int $excludeId): int
    {
        $sql = 'SELECT COUNT(1) FROM series_custom_field WHERE series_id = ? AND field_scope = ? AND field_key = ?';
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
        }

        $stmt = $this->connection->prepare($sql);
        if ($excludeId !== null) {
            $stmt->bind_param('issi', $seriesId, $fieldScope, $fieldKey, $excludeId);
        } else {
            $stmt->bind_param('iss', $seriesId, $fieldScope, $fieldKey);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        return $count;
    }

    /**
     * Returns field rows for multiple series and a scope.
     *
     * @param array<int> $seriesIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchFieldsForSeriesIds(array $seriesIds, string $fieldScope): array
    {
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $types = str_repeat('i', count($seriesIds)) . 's';

        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT id, series_id, field_key, label, field_type, field_scope, default_value, sort_order, is_required,
                        is_public_portal_hidden, is_backend_portal_hidden
                 FROM series_custom_field
                 WHERE series_id IN (%s) AND field_scope = ?
                 ORDER BY sort_order, id',
                $placeholders
            )
        );

        $params = $seriesIds;
        $params[] = $fieldScope;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }
}
