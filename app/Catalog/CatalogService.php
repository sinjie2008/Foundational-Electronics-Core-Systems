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
        $this->ensureTypstTemplatingColumn();
        $categories = [];

        $result = $this->db->query(
            'SELECT id, parent_id, name, type, display_order, typst_templating_enabled, latex_templating_enabled FROM category ORDER BY display_order ASC, name ASC'
        );
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] ? (int) $row['parent_id'] : null;
            $row['display_order'] = (int) $row['display_order'];
            $row['typst_templating_enabled'] = ((int) ($row['typst_templating_enabled'] ?? $row['latex_templating_enabled'] ?? 0)) === 1;
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
     * Ensure the typst_templating_enabled column exists for category reads (migrating legacy latex flag when present).
     */
    private function ensureTypstTemplatingColumn(): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $table = 'category';
        $column = 'typst_templating_enabled';
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($count === 0) {
            $this->db->query(
                "ALTER TABLE category ADD COLUMN typst_templating_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER type"
            );
        }

        // Keep legacy latex flag in sync when present.
        $stmt = $this->db->prepare(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $legacyColumn = 'latex_templating_enabled';
        $stmt->bind_param('ss', $table, $legacyColumn);
        $stmt->execute();
        $legacyResult = $stmt->get_result();
        $legacyCount = (int) ($legacyResult->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($legacyCount > 0) {
            $this->db->query(
                'UPDATE category SET typst_templating_enabled = latex_templating_enabled WHERE typst_templating_enabled IS NULL OR typst_templating_enabled = 0'
            );
        }
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

    /**
     * Get detailed information for a specific series, including metadata and field definitions.
     *
     * @return array<string, mixed>|null
     */
    public function getSeriesDetails(int $seriesId): ?array
    {
        // 1. Get Series Info
        $stmt = $this->db->prepare("SELECT id, parent_id, name, type FROM category WHERE id = ? AND type = 'series'");
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $series = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$series) {
            return null;
        }

        // 2. Get Metadata (Values for this series)
        $metadata = [];
        $stmt = $this->db->prepare("
            SELECT f.field_key, f.label, v.value
            FROM series_custom_field f
            LEFT JOIN series_custom_field_value v ON f.id = v.series_custom_field_id AND v.series_id = ?
            WHERE f.series_id = ? AND f.field_scope = 'series_metadata'
        ");
        $stmt->bind_param('ii', $seriesId, $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $metadata[] = [
                'key' => $row['field_key'],
                'label' => $row['label'],
                'value' => $row['value'] ?? ''
            ];
        }
        $stmt->close();

        // 3. Get Product Attribute Definitions (Custom Fields)
        $customFields = [];
        $stmt = $this->db->prepare("
            SELECT field_key, label, field_type
            FROM series_custom_field
            WHERE series_id = ? AND field_scope = 'product_attribute'
            ORDER BY sort_order ASC
        ");
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $customFields[] = [
                'key' => $row['field_key'],
                'label' => $row['label'],
                'type' => $row['field_type']
            ];
        }
        $stmt->close();

        return [
            'id' => (int)$series['id'],
            'name' => $series['name'],
            'parentId' => $series['parent_id'],
            'type' => $series['type'],
            'metadata' => $metadata,
            'customFields' => $customFields
        ];
    }
}
