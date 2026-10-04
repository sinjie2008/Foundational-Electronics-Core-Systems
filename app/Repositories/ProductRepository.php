<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;
use CatalogSuite\Support\CatalogV1Cleanup;

/**
 * Reads and persists products and their custom field values.
 */
final class ProductRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Returns products for a set of series in their existing order.
     *
     * @param array<int> $seriesIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductsForSeriesIds(array $seriesIds, bool $publicOnly = false): array
    {
        $placeholders = implode(',', array_fill(0, count($seriesIds), '?'));
        $types = str_repeat('i', count($seriesIds));
        $where = $publicOnly && $this->connection->query("SHOW COLUMNS FROM product LIKE 'is_published'")->num_rows > 0 ? ' AND is_published = 1' : '';
        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT id, series_id, sku, name, description
                 FROM product
                 WHERE series_id IN (%s)%s
                 ORDER BY series_id, id',
                $placeholders, $where
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
     * Updates a product in its series.
     */
    public function updateProduct(string $sku, string $name, ?string $description, int $productId, int $seriesId): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE product SET sku = ?, name = ?, description = ? WHERE id = ? AND series_id = ?'
        );
        $stmt->bind_param('sssii', $sku, $name, $description, $productId, $seriesId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Inserts a product and returns its ID.
     */
    public function insertProduct(int $seriesId, string $sku, string $name, ?string $description): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('isss', $seriesId, $sku, $name, $description);
        $stmt->execute();
        $productId = (int) $stmt->insert_id;
        $stmt->close();

        return $productId;
    }

    /**
     * Replaces all custom values for a product by removing its current rows.
     */
    public function deleteCustomValues(int $productId): void
    {
        $stmt = $this->connection->prepare(
            'DELETE FROM product_custom_field_value WHERE product_id = ?'
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Inserts one product custom value.
     */
    public function insertCustomValue(int $productId, int $fieldId, string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $productId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Deletes one product row.
     */
    public function deleteProduct(int $productId): void
    {
        $stmt = $this->connection->prepare('DELETE FROM product WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $stmt->close();
        (new CatalogV1Cleanup($this->connection))->orphans();
    }

    /**
     * Returns products for one series in their existing order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchProductsForSeries(int $seriesId): array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, sku, name, description FROM product WHERE series_id = ? ORDER BY name, id'
        );
        $stmt->bind_param('i', $seriesId);
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
     * Loads a product row by ID.
     *
     * @return array<string, mixed>|null
     */
    public function findProduct(int $productId): ?array
    {
        $stmt = $this->connection->prepare(
            'SELECT id, series_id, sku, name, description FROM product WHERE id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $productId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Returns custom value rows for products.
     *
     * @param array<int> $productIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchCustomValueRows(array $productIds): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $types = str_repeat('i', count($productIds));

        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT product_id, series_custom_field_id, value
                 FROM product_custom_field_value
                 WHERE product_id IN (%s)',
                $placeholders
            )
        );
        $stmt->bind_param($types, ...$productIds);
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
