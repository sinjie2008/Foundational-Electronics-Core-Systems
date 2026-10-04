<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use mysqli;
use CatalogSuite\Support\CatalogV1Cleanup;

/**
 * Persists category and series hierarchy data.
 */
final class HierarchyRepository
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Updates an existing category or series node.
     */
    public function updateNode(?int $parentId, string $name, string $type, int $displayOrder, int $nodeId): void
    {
        $stmt = $this->connection->prepare(
            'UPDATE category SET parent_id = ?, name = ?, type = ?, display_order = ? WHERE id = ?'
        );
        $stmt->bind_param('issii', $parentId, $name, $type, $displayOrder, $nodeId);
        $stmt->execute();
        $stmt->close();
        (new CatalogV1Cleanup($this->connection))->orphans();
    }

    /**
     * Counts direct children of a category node.
     */
    public function countChildren(int $nodeId): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) FROM category WHERE parent_id = ?');
        $stmt->bind_param('i', $nodeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        return $count;
    }

    /**
     * Deletes one category node.
     */
    public function deleteNode(int $nodeId): void
    {
        $stmt = $this->connection->prepare('DELETE FROM category WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $nodeId);
        $stmt->execute();
        $stmt->close();
        (new CatalogV1Cleanup($this->connection))->orphans();
    }

    /**
     * Returns category rows in their existing hierarchy display order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchHierarchyRows(bool $includeLegacy, bool $publicOnly = false): array
    {
        $where = $publicOnly && $this->hasCategoryColumn('is_published') ? ' WHERE is_published = 1' : '';
        $result = $this->connection->query(sprintf(
            'SELECT %s FROM category%s ORDER BY display_order, id',
            self::getCategorySelectColumns($includeLegacy), $where
        ));

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Returns series options in their existing name order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSeriesOptionRows(bool $publicOnly = false): array
    {
        $result = $this->connection->query(
            "SELECT id, name FROM category WHERE type = 'series' AND "
            . ($publicOnly ? (new \CatalogSuite\Support\CatalogPublicVisibility($this->connection))->nodes('id', 'series') : '1 = 1')
            . ' ORDER BY name, id'
        );

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Loads one category row by its ID.
     *
     * @return array<string, mixed>|null
     */
    public function findNode(int $nodeId, bool $includeLegacy): ?array
    {
        $stmt = $this->connection->prepare(
            sprintf(
                'SELECT %s FROM category WHERE id = ? LIMIT 1',
                self::getCategorySelectColumns($includeLegacy)
            )
        );
        $stmt->bind_param('i', $nodeId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc() ?: null;
        $stmt->close();

        return $row;
    }

    /**
     * Builds the category column list with the optional legacy templating flag.
     */
    public static function getCategorySelectColumns(bool $includeLegacy): string
    {
        $columns = [
            'id',
            'parent_id',
            'name',
            'type',
            'display_order',
            'typst_templating_enabled',
        ];
        if ($includeLegacy) {
            $columns[] = 'latex_templating_enabled';
        }

        return implode(', ', $columns);
    }

    /**
     * Counts products assigned to a series.
     */
    public function countProductsForSeries(int $seriesId): int
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) FROM product WHERE series_id = ?');
        $stmt->bind_param('i', $seriesId);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        return $count;
    }

    /**
     * Inserts a hierarchy node and returns its database ID.
     */
    public function insertNode(?int $parentId, string $name, string $type, int $displayOrder): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $parentId, $name, $type, $displayOrder);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        $slugs = new \CatalogSuite\Support\CatalogSlug($this->connection);
        if ($slugs->available()) {
            $slugs->assign($newId);
        }
        return $newId;
    }

    /**
     * Updates the templating flags for a series.
     */
    public function updateTemplatingEnabled(int $seriesId, int $flag, bool $hasLegacyColumn): void
    {
        if ($hasLegacyColumn) {
            $stmt = $this->connection->prepare(
                "UPDATE category SET typst_templating_enabled = ?, latex_templating_enabled = ? WHERE id = ? AND type = 'series'"
            );
            $stmt->bind_param('iii', $flag, $flag, $seriesId);
        } else {
            $stmt = $this->connection->prepare(
                "UPDATE category SET typst_templating_enabled = ? WHERE id = ? AND type = 'series'"
            );
            $stmt->bind_param('ii', $flag, $seriesId);
        }
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Adds the Typst templating column when it is missing.
     */
    public function addTypstTemplatingColumn(): void
    {
        $this->connection->query(
            'ALTER TABLE category ADD COLUMN typst_templating_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER type'
        );
    }

    /**
     * Copies the legacy Latex flag into the Typst flag where the latter is unset.
     */
    public function syncLegacyTemplatingFlags(): void
    {
        $this->connection->query(
            'UPDATE category SET typst_templating_enabled = latex_templating_enabled WHERE typst_templating_enabled IS NULL OR typst_templating_enabled = 0'
        );
    }

    /**
     * Checks whether a column exists on the category table.
     */
    public function hasCategoryColumn(string $column): bool
    {
        $stmt = $this->connection->prepare(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $table = 'category';
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        return $count > 0;
    }
}
