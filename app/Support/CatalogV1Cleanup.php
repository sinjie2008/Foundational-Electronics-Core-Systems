<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use mysqli;

/** Removes presentation ownership left behind by legacy cascading deletes; never deletes files. */
final class CatalogV1Cleanup
{
    public function __construct(private mysqli $db)
    {
    }

    public function orphans(): void
    {
        foreach (['catalog_content_block', 'catalog_asset'] as $table) {
            if (!$this->exists($table)) {
                continue;
            }
            $this->db->query("DELETE a FROM `{$table}` a
                LEFT JOIN category n ON n.id = a.owner_id AND n.type = a.owner_type
                LEFT JOIN product p ON p.id = a.owner_id
                WHERE (a.owner_type IN ('category', 'series') AND n.id IS NULL)
                    OR (a.owner_type = 'product' AND p.id IS NULL)
                    OR (a.owner_type = 'catalog' AND a.owner_id <> 0)");
        }
        if ($this->exists('catalog_collection_item')) {
            $this->db->query('DELETE i FROM catalog_collection_item i LEFT JOIN category n ON n.id = i.node_id WHERE n.id IS NULL');
        }
        if ($this->exists('catalog_path_alias')) {
            $this->db->query('DELETE a FROM catalog_path_alias a LEFT JOIN category n ON n.id = a.node_id WHERE n.id IS NULL');
        }
    }

    private function exists(string $table): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
        return $exists;
    }
}
