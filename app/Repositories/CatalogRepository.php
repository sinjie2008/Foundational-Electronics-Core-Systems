<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;

/**
 * Persists and retrieves catalog categories, products, and series fields.
 */
final class CatalogRepository
{
    private mysqli $db;
    private ?bool $hasLegacyTemplatingColumn = null;

    /**
     * Create the repository with the application's database connection.
     */
    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Retrieve categories for the catalog hierarchy, including the legacy flag when available.
     *
     * @return list<array<string, mixed>>
     */
    public function getHierarchyCategories(): array
    {
        $this->ensureTypstTemplatingColumn();
        $columns = ['id', 'parent_id', 'name', 'type', 'display_order', 'typst_templating_enabled'];
        if ($this->hasLegacyTemplatingColumn()) {
            $columns[] = 'latex_templating_enabled';
        }

        $result = $this->db->query(
            'SELECT ' . implode(', ', $columns) . ' FROM category ORDER BY display_order ASC, name ASC'
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();

        return $rows;
    }

    /**
     * Retrieve products used to attach product nodes to their series.
     *
     * @return list<array<string, mixed>>
     */
    public function getHierarchyProducts(): array
    {
        $result = $this->db->query('SELECT id, series_id, name, sku FROM product ORDER BY name ASC');
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->close();

        return $rows;
    }

    /**
     * Search catalog categories by their name.
     *
     * @return list<array<string, mixed>>
     */
    public function searchCategories(string $term): array
    {
        $searchTerm = '%' . $this->db->real_escape_string($term) . '%';
        $stmt = $this->db->prepare('SELECT id, parent_id, name, type FROM category WHERE name LIKE ?');
        $stmt->bind_param('s', $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Search products by their name or SKU.
     *
     * @return list<array<string, mixed>>
     */
    public function searchProducts(string $term): array
    {
        $searchTerm = '%' . $this->db->real_escape_string($term) . '%';
        $stmt = $this->db->prepare('SELECT id, series_id, name FROM product WHERE name LIKE ? OR sku LIKE ?');
        $stmt->bind_param('ss', $searchTerm, $searchTerm);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Find a series category by its id.
     *
     * @return array<string, mixed>|null
     */
    public function findSeries(int $seriesId): ?array
    {
        $stmt = $this->db->prepare("SELECT id, parent_id, name, type FROM category WHERE id = ? AND type = 'series'");
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $series = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();

        return $series;
    }

    /**
     * Retrieve series metadata values and labels.
     *
     * @return list<array<string, mixed>>
     */
    public function getSeriesMetadata(int $seriesId): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.field_key, f.label, v.value
             FROM series_custom_field f
             LEFT JOIN series_custom_field_value v ON f.id = v.series_custom_field_id AND v.series_id = ?
             WHERE f.series_id = ? AND f.field_scope = 'series_metadata'"
        );
        $stmt->bind_param('ii', $seriesId, $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Retrieve product attribute field definitions for a series.
     *
     * @return list<array<string, mixed>>
     */
    public function getProductAttributeFields(int $seriesId): array
    {
        $stmt = $this->db->prepare(
            "SELECT field_key, label, field_type
             FROM series_custom_field
             WHERE series_id = ? AND field_scope = 'product_attribute'
             ORDER BY sort_order ASC"
        );
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    /**
     * Add the Typst compatibility column when an older database lacks it.
     */
    private function ensureTypstTemplatingColumn(): void
    {
        if (!$this->hasCategoryColumn('typst_templating_enabled')) {
            $this->db->query(
                'ALTER TABLE category ADD COLUMN typst_templating_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER type'
            );
        }

        $this->hasLegacyTemplatingColumn = $this->hasCategoryColumn('latex_templating_enabled');
        if ($this->hasLegacyTemplatingColumn) {
            $this->db->query(
                'UPDATE category SET typst_templating_enabled = latex_templating_enabled WHERE typst_templating_enabled IS NULL OR typst_templating_enabled = 0'
            );
        }
    }

    /**
     * Return whether the legacy LaTeX flag exists.
     */
    private function hasLegacyTemplatingColumn(): bool
    {
        if ($this->hasLegacyTemplatingColumn === null) {
            $this->hasLegacyTemplatingColumn = $this->hasCategoryColumn('latex_templating_enabled');
        }

        return $this->hasLegacyTemplatingColumn;
    }

    /**
     * Check whether a category table column exists.
     */
    private function hasCategoryColumn(string $column): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $table = 'category';
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $count = (int) ($stmt->get_result()->fetch_row()[0] ?? 0);
        $stmt->close();

        return $count > 0;
    }
}
