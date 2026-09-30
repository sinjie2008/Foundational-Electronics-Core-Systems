<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/**
 * Persists series custom field values.
 */
final class SeriesAttributeRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Returns value rows for the requested series IDs.
     *
     * @param array<int> $seriesIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchValueRows(array $seriesIds): array
    {
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $types = str_repeat('i', count($seriesIds));

        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT series_id, series_custom_field_id, value
                 FROM series_custom_field_value
                 WHERE series_id IN (%s)',
                $placeholders
            )
        );
        $stmt->bind_param($types, ...$seriesIds);
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
     * Inserts or updates one series custom value.
     */
    public function upsertValue(int $seriesId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field_value (series_id, series_custom_field_id, value)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->bind_param('iis', $seriesId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }
}
