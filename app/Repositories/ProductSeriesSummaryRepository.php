<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/** Read-only bulk queries for public product-series summaries. */
final class ProductSeriesSummaryRepository
{
    public function __construct(private mysqli $db)
    {
    }

    /** @return array<int, int> */
    public function partCounts(array $seriesIds): array
    {
        $counts = array_fill_keys(array_map('intval', $seriesIds), 0);
        if ($counts === []) {
            return $counts;
        }

        [$placeholders, $parameters] = $this->inParameters(array_keys($counts));
        $statement = $this->db->prepare(
            "SELECT series_id, COUNT(*) AS part_count FROM product WHERE series_id IN ($placeholders) GROUP BY series_id"
        );
        $statement->execute($parameters);
        $result = $statement->get_result();
        while ($row = $result->fetch_assoc()) {
            $counts[(int) $row['series_id']] = (int) $row['part_count'];
        }
        $statement->close();

        return $counts;
    }

    /** @return array<int, list<array<string, mixed>>> */
    public function publicFields(array $seriesIds, array $fieldKeys): array
    {
        if ($seriesIds === []) {
            return [];
        }

        [$seriesPlaceholders, $parameters] = $this->inParameters($seriesIds);
        $fieldPlaceholders = implode(',', array_fill(0, count($fieldKeys), '?'));
        $statement = $this->db->prepare(
            "SELECT series_id, field_key, label, default_value, is_required
             FROM series_custom_field
             WHERE series_id IN ($seriesPlaceholders)
               AND field_scope = 'product_attribute'
               AND COALESCE(is_public_portal_hidden, 0) = 0
               AND field_type IN ('text', 'number')
               AND field_key IN ($fieldPlaceholders)
             ORDER BY series_id, sort_order, id"
        );
        $statement->execute([...$parameters, ...$fieldKeys]);
        $result = $statement->get_result();
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $row['series_id'] = (int) $row['series_id'];
            $row['is_required'] = (int) $row['is_required'] === 1;
            $fields[$row['series_id']][] = $row;
        }
        $statement->close();

        return $fields;
    }

    /** @return iterable<array<string, mixed>> */
    public function productFieldValues(array $seriesIds, array $fieldKeys, bool $hasDefaults): iterable
    {
        if ($seriesIds === []) {
            return;
        }

        [$placeholders, $seriesParameters] = $this->inParameters($seriesIds);
        $fieldPlaceholders = implode(',', array_fill(0, count($fieldKeys), '?'));
        // Most imported definitions have no default. Those groups can use the sparse values table directly.
        $valueJoin = $hasDefaults ? 'LEFT JOIN' : 'JOIN';
        $effectiveValue = $hasDefaults ? 'CASE WHEN v.product_id IS NULL THEN f.default_value ELSE v.value END' : 'v.value';
        $statement = $this->db->prepare(
            "SELECT p.series_id, f.field_key, $effectiveValue AS effective_value
             FROM product p
             JOIN series_custom_field f
               ON f.series_id = p.series_id
              AND f.field_scope = 'product_attribute'
              AND COALESCE(f.is_public_portal_hidden, 0) = 0
              AND f.field_type IN ('text', 'number')
              AND f.field_key IN ($fieldPlaceholders)
             $valueJoin product_custom_field_value v
               ON v.product_id = p.id
              AND v.series_custom_field_id = f.id
             WHERE p.series_id IN ($placeholders)
               AND TRIM($effectiveValue) <> ''"
        );
        $statement->execute([...$fieldKeys, ...$seriesParameters]);
        $result = $statement->get_result();
        try {
            while ($row = $result->fetch_assoc()) {
                yield $row;
            }
        } finally {
            $result->free();
            $statement->close();
        }
    }

    /** @return array{0: string, 1: list<int>} */
    private function inParameters(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        return [implode(',', array_fill(0, count($values), '?')), $values];
    }
}
