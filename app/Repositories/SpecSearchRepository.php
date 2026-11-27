<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use Collator;
use mysqli;

/**
 * Executes the catalog queries needed by specification search.
 */
final class SpecSearchRepository
{
    private const PRODUCT_COLUMNS = 'p.id, p.sku, p.name, s.name as series_name, s.id as series_id, c.name as category_name, c.id as category_id';
    private const PRODUCT_SOURCE = ' FROM product p
        JOIN category s ON p.series_id = s.id
        JOIN category c ON s.parent_id = c.id';

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

        return $this->fetchAll(
            'SELECT ' . self::PRODUCT_COLUMNS . self::PRODUCT_SOURCE
            . ' WHERE ' . $this->productConditions($categoryIds, $filters) . ' LIMIT 500'
        );
    }

    /**
     * Query the complete scope, returning raw rows for a single page.
     *
     * @param list<int> $categoryIds
     * @param array<string, list<string>> $filters
     * @param array{page: int, per_page: int, search: string, sort_key: ?string, sort_direction: string, inquiry_ids: list<int>} $options
     * @return array{items: list<array<string, mixed>>, total: int, pagination: array{page: int, per_page: int}, column_keys: list<string>, inquiry_items: list<array{id: int, sku: string}>}
     */
    public function searchProductPage(array $categoryIds, array $filters, array $options): array
    {
        $perPage = $options['per_page'];
        if ($categoryIds === []) {
            return [
                'items' => [],
                'total' => 0,
                'pagination' => ['page' => 1, 'per_page' => $perPage],
                'column_keys' => [],
                'inquiry_items' => [],
            ];
        }

        $conditions = $this->productConditions($categoryIds, $filters);
        $scope = self::PRODUCT_SOURCE . ' WHERE ' . $conditions;
        $columnKeys = array_column($this->fetchAll(
            'SELECT scf.field_key' . self::PRODUCT_SOURCE . "
            JOIN product_custom_field_value pcfv ON pcfv.product_id = p.id
            JOIN series_custom_field scf ON scf.id = pcfv.series_custom_field_id
            WHERE {$conditions} AND scf.field_scope = 'product_attribute'
            GROUP BY scf.field_key
            ORDER BY MIN(p.id), MIN(pcfv.id), scf.field_key"
        ), 'field_key');

        $inquiryItems = [];
        if ($options['inquiry_ids'] !== []) {
            $inquiryIds = $this->idList($options['inquiry_ids']);
            $inquiryRows = $this->fetchAll(
                "SELECT p.id, p.sku {$scope} AND p.id IN ({$inquiryIds}) ORDER BY FIELD(p.id, {$inquiryIds})"
            );
            foreach ($inquiryRows as $row) {
                $inquiryItems[] = ['id' => (int) $row['id'], 'sku' => $row['sku']];
            }
        }

        $searchParameters = [];
        if ($options['search'] !== '') {
            $scope .= $this->textSearchCondition();
            $searchParameters = array_fill(0, 6, $options['search']);
        }

        $sortedIds = $options['sort_key'] === null
            ? null
            : $this->sortedProductIds($scope, $searchParameters, $options['sort_key'], $options['sort_direction']);
        $total = $sortedIds === null
            ? (int) $this->fetchAll('SELECT COUNT(*) AS total' . $scope, $searchParameters)[0]['total']
            : count($sortedIds);
        $page = min($options['page'], max(1, (int) ceil($total / $perPage)));
        $offset = ($page - 1) * $perPage;

        $rows = [];
        if ($total > 0 && $sortedIds === null) {
            $rows = $this->fetchAll(
                'SELECT ' . self::PRODUCT_COLUMNS . $scope . " ORDER BY p.id LIMIT {$perPage} OFFSET {$offset}",
                $searchParameters
            );
        } elseif ($total > 0) {
            $ids = $this->idList(array_slice($sortedIds, $offset, $perPage));
            $rows = $this->fetchAll(
                'SELECT ' . self::PRODUCT_COLUMNS . self::PRODUCT_SOURCE
                . " WHERE p.id IN ({$ids}) ORDER BY FIELD(p.id, {$ids})"
            );
        }

        return [
            'items' => $rows,
            'total' => $total,
            'pagination' => ['page' => $page, 'per_page' => $perPage],
            'column_keys' => $columnKeys,
            'inquiry_items' => $inquiryItems,
        ];
    }

    /**
     * @param list<int> $categoryIds
     * @param array<string, list<string>> $filters
     */
    private function productConditions(array $categoryIds, array $filters): string
    {
        $ids = $this->idList($categoryIds);
        $sql = "s.parent_id IN ({$ids}) AND s.type = 'series'";

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

        return $sql;
    }

    /** Match the displayed product values, the SKU, and the Series name using a literal substring. */
    private function textSearchCondition(): string
    {
        $spq = $this->productSpqSql();
        $needle = 'CAST(LOWER(?) AS BINARY)';

        return " AND (
            LOCATE({$needle}, CAST(LOWER(p.sku) AS BINARY)) > 0
            OR LOCATE({$needle}, CAST(LOWER(p.name) AS BINARY)) > 0
            OR LOCATE({$needle}, CAST(LOWER(s.name) AS BINARY)) > 0
            OR LOCATE({$needle}, CAST(LOWER(c.name) AS BINARY)) > 0
            OR LOCATE({$needle}, CAST(LOWER({$spq}) AS BINARY)) > 0
            OR p.id IN (
                SELECT pcfv.product_id FROM product_custom_field_value pcfv
                JOIN series_custom_field scf ON scf.id = pcfv.series_custom_field_id
                WHERE scf.field_scope = 'product_attribute'
                AND scf.field_key NOT IN (
                    'id', 'sku', 'name', 'series', 'seriesId', 'category', 'categoryId',
                    'seriesImage', 'seriesUrl', 'pdfDownload', 'spq', 'acf.spq'
                )
                AND LOCATE({$needle}, CAST(LOWER(pcfv.value) AS BINARY)) > 0
            )
        )";
    }

    /**
     * Keep the table's natural string order and use ID to break ties across pages.
     * Only the ID and sort value are read for each match. Full rows are fetched for the requested page.
     *
     * @param list<string> $searchParameters
     * @return list<int>
     */
    private function sortedProductIds(string $scope, array $searchParameters, string $key, string $direction): array
    {
        $value = match ($key) {
            'name' => 'p.name',
            'sku' => 'p.sku',
            'series' => 's.name',
            'category' => 'c.name',
            'spq' => $this->productSpqSql(),
            default => null,
        };
        if ($value === null) {
            $value = $this->productAttributeSql('?');
            array_unshift($searchParameters, $key);
        }
        $matches = $this->fetchAll("SELECT p.id, {$value} AS sort_value" . $scope, $searchParameters);
        $collator = class_exists(Collator::class) ? new Collator('en') : null;
        $collator?->setStrength(Collator::PRIMARY);
        $collator?->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
        $multiplier = $direction === 'desc' ? -1 : 1;
        usort($matches, static function (array $left, array $right) use ($collator, $multiplier): int {
            $leftValue = (string) $left['sort_value'];
            $rightValue = (string) $right['sort_value'];
            $comparison = $collator?->compare($leftValue, $rightValue);
            if ($comparison === null || $comparison === false) {
                $comparison = strnatcasecmp($leftValue, $rightValue);
            }
            return ($comparison * $multiplier) ?: ($left['id'] <=> $right['id']);
        });

        return array_map('intval', array_column($matches, 'id'));
    }

    /** Look up a product attribute without adding rows to the product query. */
    private function productAttributeSql(string $fieldKey): string
    {
        return "(
            SELECT pcfv.value FROM product_custom_field_value pcfv
            JOIN series_custom_field scf ON scf.id = pcfv.series_custom_field_id
            WHERE pcfv.product_id = p.id AND scf.field_scope = 'product_attribute'
            AND scf.field_key = {$fieldKey}
            LIMIT 1
        )";
    }

    /** Use the same SPQ precedence as the product rows and the existing table. */
    private function productSpqSql(): string
    {
        $spqAttribute = $this->productAttributeSql("'spq'");
        $legacyAttribute = $this->productAttributeSql("'acf.spq'");
        return "COALESCE((
            SELECT scfv.value FROM series_custom_field scf
            JOIN series_custom_field_value scfv ON scfv.series_custom_field_id = scf.id AND scfv.series_id = s.id
            WHERE scf.series_id = s.id AND scf.field_scope = 'series_metadata'
            AND scf.field_key = 'series_spq_per_reel' AND TRIM(scfv.value) <> ''
        ), {$spqAttribute}, {$legacyAttribute}, '')";
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
    private function fetchAll(string $sql, array $parameters = []): array
    {
        $result = $parameters === [] ? $this->db->query($sql) : $this->db->execute_query($sql, $parameters);
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
