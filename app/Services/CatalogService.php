<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Repositories\CatalogRepository;
use CatalogSuite\Support\Db;
use mysqli;

/**
 * Catalog application queries and response-shape mapping for hierarchy/search APIs.
 */
final class CatalogService
{
    private CatalogRepository $catalog;

    /**
     * Create the catalog service with injectable persistence.
     */
    public function __construct(?mysqli $db = null, ?CatalogRepository $catalog = null)
    {
        $this->catalog = $catalog ?? new CatalogRepository($db ?? Db::connection());
    }

    /**
     * Build the category/tree hierarchy with product counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHierarchy(): array
    {
        $categories = [];
        foreach ($this->catalog->getHierarchyCategories() as $row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] ? (int) $row['parent_id'] : null;
            $row['display_order'] = (int) $row['display_order'];
            $legacyFlag = (int) ($row['latex_templating_enabled'] ?? 0);
            $row['typst_templating_enabled'] = ((int) ($row['typst_templating_enabled'] ?? $legacyFlag)) === 1;
            $row['children'] = [];
            $row['products'] = [];
            $row['product_count'] = 0;
            $row['category_count'] = 0;
            $categories[$row['id']] = $row;
        }

        foreach ($this->catalog->getHierarchyProducts() as $row) {
            $seriesId = (int) $row['series_id'];
            if (isset($categories[$seriesId])) {
                $categories[$seriesId]['products'][] = [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'sku' => $row['sku'],
                    'type' => 'product',
                ];
                $categories[$seriesId]['product_count']++;
            }
        }

        $tree = [];
        foreach ($categories as $id => &$node) {
            if ($node['parent_id'] === null) {
                $tree[] = &$node;
                continue;
            }
            if (isset($categories[$node['parent_id']])) {
                $categories[$node['parent_id']]['children'][] = &$node;
                $categories[$node['parent_id']]['category_count']++;
            }
        }

        return $tree;
    }

    /**
     * Search categories/series/products by name (and SKU for products).
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query): array
    {
        $term = trim($query);
        if ($term === '') {
            return [];
        }

        $matches = [];
        foreach ($this->catalog->searchCategories($term) as $row) {
            $matches[] = [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] ? (int) $row['parent_id'] : null,
                'name' => $row['name'],
                'type' => $row['type'],
            ];
        }

        foreach ($this->catalog->searchProducts($term) as $row) {
            $matches[] = [
                'id' => (int) $row['id'],
                'parent_id' => (int) $row['series_id'],
                'name' => $row['name'],
                'type' => 'product',
            ];
        }

        return $matches;
    }

    /**
     * Get detailed information for a specific series, including metadata and field definitions.
     *
     * @return array<string, mixed>|null
     */
    public function getSeriesDetails(int $seriesId): ?array
    {
        $series = $this->catalog->findSeries($seriesId);
        if ($series === null) {
            return null;
        }

        $metadata = [];
        foreach ($this->catalog->getSeriesMetadata($seriesId) as $row) {
            $metadata[] = [
                'key' => $row['field_key'],
                'label' => $row['label'],
                'value' => $row['value'] ?? '',
            ];
        }

        $customFields = [];
        foreach ($this->catalog->getProductAttributeFields($seriesId) as $row) {
            $customFields[] = [
                'key' => $row['field_key'],
                'label' => $row['label'],
                'type' => $row['field_type'],
            ];
        }

        return [
            'id' => (int) $series['id'],
            'name' => $series['name'],
            'parentId' => $series['parent_id'],
            'type' => $series['type'],
            'metadata' => $metadata,
            'customFields' => $customFields,
        ];
    }
}
