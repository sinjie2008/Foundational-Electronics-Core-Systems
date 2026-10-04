<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use mysqli;

/** Legacy public reads honor additive publication controls without changing their response shapes. */
final class CatalogPublicVisibility
{
    public function __construct(private mysqli $db)
    {
    }

    private function migrated(): bool
    {
        return $this->db->query("SHOW COLUMNS FROM category LIKE 'is_published'")->num_rows > 0;
    }

    public function nodes(string $column, ?string $type = null): string
    {
        if (!$this->migrated()) {
            return '1 = 1';
        }
        $rows = $this->db->query('SELECT id, parent_id, type FROM category WHERE is_published = 1')->fetch_all(MYSQLI_ASSOC);
        $nodes = [];
        foreach ($rows as $row) {
            $nodes[(int) $row['id']] = $row;
        }
        $resolved = [];
        $visiting = [];
        $visible = static function (int $id) use (&$visible, &$resolved, &$visiting, $nodes): bool {
            if (isset($resolved[$id])) {
                return $resolved[$id];
            }
            if (!isset($nodes[$id]) || isset($visiting[$id])) {
                return false;
            }
            $visiting[$id] = true;
            $parentId = $nodes[$id]['parent_id'];
            $result = $parentId === null || (isset($nodes[(int) $parentId]) && $nodes[(int) $parentId]['type'] === 'category' && $visible((int) $parentId));
            unset($visiting[$id]);
            return $resolved[$id] = $result;
        };
        $ids = [];
        foreach ($nodes as $id => $node) {
            if (($type === null || $node['type'] === $type) && $visible($id)) {
                $ids[] = $id;
            }
        }
        return $ids === [] ? '1 = 0' : $column . ' IN (' . implode(',', $ids) . ')';
    }

    public function products(string $alias = 'p'): string
    {
        if (!$this->migrated()) {
            return '1 = 1';
        }
        return $alias . '.is_published = 1 AND ' . $this->nodes($alias . '.series_id', 'series');
    }
}
