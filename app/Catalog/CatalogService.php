<?php
declare(strict_types=1);

namespace App\Catalog;

use App\Support\Db;
use mysqli;

/**
 * Catalog queries for hierarchy and search endpoints.
 */
final class CatalogService
{
    private mysqli $db;

    public function __construct(?mysqli $db = null)
    {
        $this->db = $db ?? Db::connection();
    }

    /**
     * Build the category/tree hierarchy with product counts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHierarchy(): array
    {
        $categories = [];

        $result = $this->db->query(
            'SELECT id, parent_id, name, type, display_order FROM category ORDER BY display_order ASC, name ASC'
        );
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] ? (int) $row['parent_id'] : null;
            $row['display_order'] = (int) $row['display_order'];
            $row['children'] = [];
            $row['products'] = [];
            $row['product_count'] = 0;
            $row['category_count'] = 0;
            $categories[$row['id']] = $row;
        }
        $result->close();

        $result = $this->db->query('SELECT id, series_id, name, sku FROM product ORDER BY name ASC');
        while ($row = $result->fetch_assoc()) {
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
        $result->close();

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

        $searchTerm = '%' . $this->db->real_escape_string($term) . '%';
        $matches = [];

        $stmt = $this->db->prepare('SELECT id, parent_id, name, type FROM category WHERE name LIKE ?');
        $stmt->bind_param('s', $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $matches[] = [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] ? (int) $row['parent_id'] : null,
                'name' => $row['name'],
                'type' => $row['type'],
            ];
        }
        $stmt->close();

        $stmt = $this->db->prepare('SELECT id, series_id, name FROM product WHERE name LIKE ? OR sku LIKE ?');
        $stmt->bind_param('ss', $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $matches[] = [
                'id' => (int) $row['id'],
                'parent_id' => (int) $row['series_id'],
                'name' => $row['name'],
                'type' => 'product',
            ];
        }
        $stmt->close();

        return $matches;
    }
}
