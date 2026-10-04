<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use CatalogSuite\Support\Config;

use mysqli;
use CatalogSuite\Support\CatalogV1Cleanup;

/**
 * Reads and persists catalog data used by CSV import and export workflows.
 */
final class CatalogCsvRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Finds an existing category with the given parent and exact name.
     *
     * @return array<string, mixed>|null
     */
    public function findCategory(?int $parentId, string $name): ?array
    {
        $stmt = $this->connection->prepare(
            "SELECT id FROM category WHERE parent_id <=> ? AND name = ? AND type = 'category' LIMIT 1"
        );
        $stmt->bind_param('is', $parentId, $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Inserts a category node and returns its ID.
     */
    public function insertCategory(?int $parentId, string $name): int
    {
        return (new HierarchyRepository($this->connection))->insertNode($parentId, $name, 'category', 0);
    }

    /**
     * Finds an existing series with the given parent and exact name.
     *
     * @return array<string, mixed>|null
     */
    public function findSeries(?int $parentId, string $name): ?array
    {
        $stmt = $this->connection->prepare(
            "SELECT id, display_order FROM category WHERE parent_id <=> ? AND name = ? AND type = 'series' LIMIT 1"
        );
        $stmt->bind_param('is', $parentId, $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Updates a series display order.
     */
    public function updateSeriesDisplayOrder(int $seriesId, int $displayOrder): void
    {
        $update = $this->connection->prepare('UPDATE category SET display_order = ? WHERE id = ?');
        $update->bind_param('ii', $displayOrder, $seriesId);
        $update->execute();
        $update->close();
    }

    /**
     * Inserts a series node and returns its ID.
     */
    public function insertSeries(?int $parentId, string $name, int $displayOrder): int
    {
        return (new HierarchyRepository($this->connection))->insertNode($parentId, $name, 'series', $displayOrder);
    }

    /**
     * Finds a product by its series and SKU.
     *
     * @return array<string, mixed>|null
     */
    public function findProductBySku(int $seriesId, string $sku): ?array
    {
        $select = $this->connection->prepare(
            'SELECT id FROM product WHERE series_id = ? AND sku = ? LIMIT 1'
        );
        $select->bind_param('is', $seriesId, $sku);
        $select->execute();
        $result = $select->get_result();
        $row = $result->fetch_assoc();
        $select->close();

        return $row;
    }

    /**
     * Updates imported product data.
     */
    public function updateProductFromImport(string $name, ?string $description, int $productId): void
    {
        $update = $this->connection->prepare(
            'UPDATE product SET name = ?, description = ? WHERE id = ?'
        );
        $update->bind_param('ssi', $name, $description, $productId);
        $update->execute();
        $update->close();
    }

    /**
     * Inserts an imported product and returns its ID.
     */
    public function insertProduct(int $seriesId, string $sku, string $name, ?string $description): int
    {
        $insert = $this->connection->prepare(
            'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)'
        );
        $insert->bind_param('isss', $seriesId, $sku, $name, $description);
        $insert->execute();
        $productId = (int) $insert->insert_id;
        $insert->close();

        return $productId;
    }

    /**
     * Replaces custom values from a CSV row while preserving field iteration and statement reuse.
     *
     * @param array<string, mixed> $customValues
     * @param array<string, array<string, mixed>> $seriesFieldMap
     */
    public function replaceProductCustomValues(
        int $productId,
        array $customValues,
        array $seriesFieldMap
    ): void
    {
        $delete = $this->connection->prepare('DELETE FROM product_custom_field_value WHERE product_id = ?');
        $delete->bind_param('i', $productId);
        $delete->execute();
        $delete->close();

        if ($customValues === []) {
            return;
        }

        $insert = $this->connection->prepare(
            'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value) VALUES (?, ?, ?)'
        );
        foreach ($customValues as $fieldKey => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            if (!isset($seriesFieldMap[$fieldKey])) {
                continue;
            }
            $fieldId = (int) $seriesFieldMap[$fieldKey]['id'];
            $insert->bind_param('iis', $productId, $fieldId, $value);
            $insert->execute();
        }
        $insert->close();
    }

    /**
     * Returns all product IDs.
     *
     * @return array<int, int>
     */
    public function fetchProductIds(): array
    {
        $result = $this->connection->query('SELECT id FROM product');
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();

        return $ids;
    }

    /**
     * Returns all series IDs.
     *
     * @return array<int, int>
     */
    public function fetchSeriesIds(): array
    {
        $result = $this->connection->query("SELECT id FROM category WHERE type = 'series'");
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();

        return $ids;
    }

    /**
     * Returns all category IDs.
     *
     * @return array<int, int>
     */
    public function fetchCategoryIds(): array
    {
        $result = $this->connection->query("SELECT id FROM category WHERE type = 'category'");
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int) $row['id'];
        }
        $result->free();

        return $ids;
    }

    /**
     * Deletes products with the given IDs.
     *
     * @param array<int> $ids
     */
    public function deleteProducts(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->prepare("DELETE FROM product WHERE id IN ($placeholders)");
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $stmt->close();
        (new CatalogV1Cleanup($this->connection))->orphans();
    }

    /**
     * Deletes series nodes with the given IDs.
     *
     * @param array<int> $ids
     */
    public function deleteSeries(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->prepare(
            "DELETE FROM category WHERE type = 'series' AND id IN ($placeholders)"
        );
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $stmt->close();
        (new CatalogV1Cleanup($this->connection))->orphans();
    }

    /**
     * Deletes category nodes with the given IDs.
     *
     * @param array<int> $ids
     */
    public function deleteCategories(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->prepare(
            "DELETE FROM category WHERE type = 'category' AND id IN ($placeholders)"
        );
        $types = str_repeat('i', count($ids));
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $stmt->close();
        (new CatalogV1Cleanup($this->connection))->orphans();
    }

    /**
     * Returns raw category rows for CSV path construction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCategoryRows(): array
    {
        $result = $this->connection->query(
            'SELECT id, parent_id, name, type, display_order FROM category'
        );
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    /**
     * Returns custom field keys ordered by their configured order.
     *
     * @return array<int, string>
     */
    public function fetchCustomFieldKeys(string $scope): array
    {
        $stmt = $this->connection->prepare(
            'SELECT field_key, MIN(sort_order) AS sort_order
             FROM series_custom_field
             WHERE field_scope = ?
             GROUP BY field_key
             ORDER BY sort_order, field_key'
        );
        $stmt->bind_param('s', $scope);
        $stmt->execute();
        $result = $stmt->get_result();
        $keys = [];
        while ($row = $result->fetch_assoc()) {
            $keys[] = (string) $row['field_key'];
        }
        $stmt->close();

        return $keys;
    }

    /**
     * Returns rows for the CSV product export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductsWithSeriesRows(): array
    {
        $sql = 'SELECT p.id, p.series_id, p.sku, p.name, p.description, s.name AS series_name, s.display_order AS series_display_order
                FROM product p
                INNER JOIN category s ON s.id = p.series_id
                ORDER BY s.name, p.sku';
        $result = $this->connection->query($sql);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    /**
     * Returns custom value rows for the CSV product export.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductCustomValueRows(): array
    {
        $sql = 'SELECT pcv.product_id, scf.field_key, pcv.value
                FROM product_custom_field_value pcv
                INNER JOIN series_custom_field scf ON scf.id = pcv.series_custom_field_id';
        $result = $this->connection->query($sql);
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }

    /**
     * Checks whether the catalog truncate advisory lock is held.
     */
    public function isTruncateInProgress(): bool
    {
        $stmt = $this->connection->prepare('SELECT IS_USED_LOCK(?) AS lock_owner');
        $lockKey = Config::get('app')['truncate']['lock_key'];
        $stmt->bind_param('s', $lockKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return ($row['lock_owner'] ?? null) !== null;
    }
}
