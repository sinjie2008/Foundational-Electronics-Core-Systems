<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/**
 * Executes the catalog queries needed by specification search.
 */
final class SpecSearchRepository
{
    private mysqli $db;

    /**
     * Create the repository with the application's database connection.
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieve top-level catalog categories for spec search.
     *
     * @return list<array<string, mixed>>
     */
    public function getRootCategories(): array
    {
        return $this->fetchAll(
            "SELECT id, name FROM category WHERE parent_id IS NULL AND type = 'category' ORDER BY display_order ASC, name ASC"
        );
    }

    /**
     * Retrieve child categories used as product groups.
     *
     * @return list<array<string, mixed>>
     */
    public function getChildCategories(int $parentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name FROM category WHERE parent_id = ? AND type = 'category' ORDER BY display_order ASC"
        );
        $stmt->bind_param('i', $parentId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Retrieve series names nested directly under selected categories.
     *
     * @param list<int> $categoryIds
     * @return list<array<string, mixed>>
     */
    public function getSeriesNames(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return $this->fetchAll(
            "SELECT name FROM category WHERE parent_id IN (" . $this->idList($categoryIds) . ") AND type = 'series' ORDER BY name ASC"
        );
    }

    /**
     * Retrieve series identifiers nested under selected categories.
     *
     * @param list<int> $categoryIds
     * @return list<array<string, mixed>>
     */
    public function getSeriesIds(array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        return $this->fetchAll(
            "SELECT id FROM category WHERE parent_id IN (" . $this->idList($categoryIds) . ") AND type = 'series'"
        );
    }

    /**
     * Retrieve attribute definitions for selected series.
     *
     * @param list<int> $seriesIds
     * @return list<array<string, mixed>>
     */
    public function getProductAttributeFields(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        return $this->fetchAll(
            "SELECT field_key, label, id, sort_order FROM series_custom_field
             WHERE series_id IN (" . $this->idList($seriesIds) . ") AND field_scope = 'product_attribute'
             ORDER BY sort_order ASC"
        );
    }

    /**
     * Retrieve the distinct values used to populate a facet.
     *
     * @param list<int> $fieldIds
     * @return list<array<string, mixed>>
     */
    public function getFacetValues(array $fieldIds): array
    {
        if ($fieldIds === []) {
            return [];
        }

        return $this->fetchAll(
            "SELECT DISTINCT value FROM product_custom_field_value
             WHERE series_custom_field_id IN (" . $this->idList($fieldIds) . ")
               AND value IS NOT NULL AND value != ''
             ORDER BY value ASC"
        );
    }

    /**
     * Search products within selected categories and apply the existing dynamic filters.
     *
     * @param list<int> $categoryIds
     * @param array<string, array<int, string>> $filters
     * @return list<array<string, mixed>>
     */
    public function searchProducts(array $categoryIds, array $filters): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $ids = $this->idList($categoryIds);
        $sql = "SELECT p.id, p.sku, p.name, s.name as series_name, s.id as series_id, c.name as category_name, c.id as category_id
                FROM product p
                JOIN category s ON p.series_id = s.id
                JOIN category c ON s.parent_id = c.id
                WHERE s.parent_id IN ({$ids}) AND s.type = 'series'";

        if (isset($filters['series']) && !empty($filters['series'])) {
            $seriesNames = array_map(function (string $value): string {
                return "'" . $this->db->real_escape_string($value) . "'";
            }, $filters['series']);
            $sql .= ' AND s.name IN (' . implode(',', $seriesNames) . ')';
        }

        foreach ($filters as $key => $values) {
            if ($key === 'series' || empty($values)) {
                continue;
            }

            $escapedValues = array_map(function (string $value): string {
                return "'" . $this->db->real_escape_string($value) . "'";
            }, $values);
            $fieldKey = $this->db->real_escape_string($key);
            $sql .= " AND EXISTS (
                SELECT 1 FROM product_custom_field_value pcfv
                JOIN series_custom_field scf ON pcfv.series_custom_field_id = scf.id
                WHERE pcfv.product_id = p.id
                AND scf.field_key = '{$fieldKey}'
                AND pcfv.value IN (" . implode(',', $escapedValues) . '))';
        }

        $sql .= ' LIMIT 500';

        return $this->fetchAll($sql);
    }

    /**
     * Retrieve the product image or specification metadata for selected series.
     *
     * @param list<int> $seriesIds
     * @return list<array<string, mixed>>
     */
    public function getSeriesMetadataValues(array $seriesIds, string $fieldKey): array
    {
        if ($seriesIds === []) {
            return [];
        }

        $ids = $this->idList($seriesIds);
        $stmt = $this->db->prepare(
            "SELECT scf.series_id, scfv.value
             FROM series_custom_field scf
             LEFT JOIN series_custom_field_value scfv ON scf.id = scfv.series_custom_field_id AND scfv.series_id = scf.series_id
             WHERE scf.series_id IN ({$ids})
               AND scf.field_scope = 'series_metadata' AND scf.field_key = ?"
        );
        $stmt->bind_param('s', $fieldKey);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Retrieve Typst generation flags for selected series.
     *
     * @param list<int> $seriesIds
     * @return list<array<string, mixed>>
     */
    public function getTypstEnabledSeries(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        return $this->fetchAll(
            'SELECT id, typst_templating_enabled FROM category WHERE id IN (' . $this->idList($seriesIds) . ')'
        );
    }

    /**
     * Retrieve saved Typst PDF paths ordered newest first per series.
     *
     * @param list<int> $seriesIds
     * @return list<array<string, mixed>>
     */
    public function getTypstPdfPaths(array $seriesIds): array
    {
        if ($seriesIds === []) {
            return [];
        }

        return $this->fetchAll(
            'SELECT series_id, last_pdf_path, updated_at, id FROM typst_templates WHERE series_id IN (' . $this->idList($seriesIds) . ')
               AND last_pdf_path IS NOT NULL ORDER BY updated_at DESC, id DESC'
        );
    }

    /**
     * Retrieve custom attribute values for matched products.
     *
     * @param list<int> $productIds
     * @return list<array<string, mixed>>
     */
    public function getProductAttributeValues(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return $this->fetchAll(
            "SELECT pcfv.product_id, scf.field_key, pcfv.value
             FROM product_custom_field_value pcfv
             JOIN series_custom_field scf ON pcfv.series_custom_field_id = scf.id
             WHERE pcfv.product_id IN (" . $this->idList($productIds) . ") AND scf.field_scope = 'product_attribute'"
        );
    }

    /**
     * Fetch all rows for a repository-owned SQL query.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql): array
    {
        $result = $this->db->query($sql);
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->close();

        return $rows;
    }

    /**
     * Build a safe integer-only SQL list from already normalized identifiers.
     *
     * @param list<int> $ids
     */
    private function idList(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}
