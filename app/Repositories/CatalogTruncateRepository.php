<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use CatalogSuite\Support\Config;

use mysqli;

/**
 * Performs catalog row counts, truncation statements, and advisory lock queries.
 */
final class CatalogTruncateRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Counts category or series nodes by their type.
     */
    public function countCategoriesByType(string $type): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) AS total FROM category WHERE type = ?');
        $stmt->bind_param('s', $type);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $count;
    }

    /**
     * Counts product rows.
     */
    public function countProducts(): int
    {
        return $this->countTableRows('product');
    }

    /**
     * Counts custom field definitions.
     */
    public function countFieldDefinitions(): int
    {
        return $this->countTableRows('series_custom_field');
    }

    /**
     * Counts product custom values.
     */
    public function countProductValues(): int
    {
        return $this->countTableRows('product_custom_field_value');
    }

    /**
     * Counts series custom values.
     */
    public function countSeriesValues(): int
    {
        return $this->countTableRows('series_custom_field_value');
    }

    /**
     * Disables or enables foreign key checks on the current connection.
     */
    public function setForeignKeyChecks(bool $enabled): void
    {
        $value = $enabled ? 1 : 0;
        $this->connection->query(sprintf('SET FOREIGN_KEY_CHECKS = %d', $value));
    }

    /**
     * Truncates the catalog tables in their established order.
     */
    public function truncateCatalogTables(): void
    {
        foreach ([
            'product_custom_field_value',
            'series_custom_field_value',
            'product',
            'series_custom_field',
            'category',
            'seed_migration',
        ] as $table) {
            $this->connection->query(sprintf('TRUNCATE TABLE %s', $table));
        }
    }

    /**
     * Attempts to acquire the catalog truncate lock without waiting.
     */
    public function acquireTruncateLock(): bool
    {
        $stmt = $this->connection->prepare('SELECT GET_LOCK(?, 0) AS lock_obtained');
        $lockKey = Config::get('app')['truncate']['lock_key'];
        $stmt->bind_param('s', $lockKey);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int) ($row['lock_obtained'] ?? 0) === 1;
    }

    /**
     * Releases the catalog truncate lock.
     */
    public function releaseTruncateLock(): void
    {
        $stmt = $this->connection->prepare('SELECT RELEASE_LOCK(?) AS released');
        $lockKey = Config::get('app')['truncate']['lock_key'];
        $stmt->bind_param('s', $lockKey);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Counts rows in a table used by the fixed catalog audit count methods.
     */
    private function countTableRows(string $table): int
    {
        $stmt = $this->connection->prepare(sprintf('SELECT COUNT(1) AS total FROM %s', $table));
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $count;
    }
}
