<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use mysqli;

/** Explicit, restartable additive migration. Public GET requests never run DDL or seed data. */
final class CatalogV1Migration
{
    private const COLUMNS = [
        'category' => [
            'slug' => 'VARCHAR(191) NULL',
            'catalog_slug_parent' => 'INT NOT NULL DEFAULT 0',
            'is_published' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'description' => 'TEXT NULL',
            'subtitle' => 'VARCHAR(255) NULL',
            'anchor_id' => 'VARCHAR(191) NULL',
            'target_url' => 'VARCHAR(1024) NULL',
        ],
        'product' => ['is_published' => 'TINYINT(1) NOT NULL DEFAULT 1'],
        'series_custom_field' => [
            'unit' => 'VARCHAR(64) NULL',
            'is_filterable' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_sortable' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'is_table_column' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'is_searchable' => 'TINYINT(1) NOT NULL DEFAULT 1',
            'filter_type' => "ENUM('select','range') NOT NULL DEFAULT 'select'",
            'config_json' => 'JSON NULL',
            'group_key' => 'VARCHAR(64) NULL',
            'group_label' => 'VARCHAR(255) NULL',
        ],
    ];

    public function __construct(private mysqli $db)
    {
    }

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $name => $definition) {
                $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
                $stmt->execute([$table, $name]);
                $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
                $stmt->close();
                if (!$exists) {
                    $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
                }
            }
        }
        $this->db->query('UPDATE category SET catalog_slug_parent = COALESCE(parent_id, 0)');
        $this->trigger('catalog_slug_parent_insert', 'category', 'INSERT', 'SET NEW.catalog_slug_parent = COALESCE(NEW.parent_id, 0)');
        $this->trigger('catalog_slug_parent_update', 'category', 'UPDATE', 'SET NEW.catalog_slug_parent = COALESCE(NEW.parent_id, 0)');
        $index = $this->db->query("SHOW INDEX FROM category WHERE Key_name = 'catalog_sibling_slug'");
        if ($index->num_rows === 0) {
            $this->db->query('ALTER TABLE category ADD UNIQUE KEY catalog_sibling_slug (catalog_slug_parent, slug)');
        }
        (new CatalogSlug($this->db))->backfill();

        foreach ([
            "CREATE TABLE IF NOT EXISTS catalog_content_block (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_type ENUM('catalog','category','series','product') NOT NULL,
                owner_id INT NOT NULL,
                block_key VARCHAR(191) NOT NULL,
                block_type VARCHAR(64) NOT NULL,
                title VARCHAR(255) NULL,
                anchor_id VARCHAR(191) NULL,
                payload_json JSON NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_navigation TINYINT(1) NOT NULL DEFAULT 1,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY catalog_block_owner_key (owner_type, owner_id, block_key),
                KEY catalog_block_owner (owner_type, owner_id, is_public, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS catalog_asset (
                id INT AUTO_INCREMENT PRIMARY KEY,
                owner_type ENUM('catalog','category','series','product') NOT NULL,
                owner_id INT NOT NULL,
                asset_key VARCHAR(191) NOT NULL,
                role VARCHAR(191) NOT NULL,
                storage_disk ENUM('media','typst','public','external') NOT NULL DEFAULT 'media',
                file_path VARCHAR(1024) NOT NULL,
                mime_type VARCHAR(128) NOT NULL,
                title VARCHAR(255) NULL,
                alt_text VARCHAR(255) NULL,
                metadata_json JSON NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_download TINYINT(1) NOT NULL DEFAULT 0,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY catalog_asset_owner_key (owner_type, owner_id, asset_key),
                KEY catalog_asset_owner (owner_type, owner_id, is_public, display_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS catalog_collection (
                id INT AUTO_INCREMENT PRIMARY KEY,
                collection_key VARCHAR(191) NOT NULL UNIQUE,
                title VARCHAR(255) NULL,
                description TEXT NULL,
                target_url VARCHAR(1024) NULL,
                config_json JSON NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_public TINYINT(1) NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS catalog_collection_item (
                id INT AUTO_INCREMENT PRIMARY KEY,
                collection_id INT NOT NULL,
                item_key VARCHAR(191) NULL,
                node_id INT NOT NULL,
                asset_id INT NULL,
                title VARCHAR(255) NULL,
                subtitle VARCHAR(255) NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_public TINYINT(1) NOT NULL DEFAULT 0,
                FOREIGN KEY (collection_id) REFERENCES catalog_collection(id) ON DELETE CASCADE,
                FOREIGN KEY (node_id) REFERENCES category(id) ON DELETE CASCADE,
                FOREIGN KEY (asset_id) REFERENCES catalog_asset(id) ON DELETE SET NULL,
                KEY catalog_collection_order (collection_id, is_public, display_order),
                UNIQUE KEY catalog_collection_item_key (collection_id, item_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS catalog_path_alias (
                path VARCHAR(700) NOT NULL PRIMARY KEY,
                node_id INT NOT NULL,
                is_canonical TINYINT(1) NOT NULL DEFAULT 0,
                canonical_node_id INT NULL,
                UNIQUE KEY catalog_one_canonical_alias (canonical_node_id),
                FOREIGN KEY (node_id) REFERENCES category(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ] as $sql) {
            $this->db->query($sql);
        }
        $this->trigger('catalog_alias_insert', 'catalog_path_alias', 'INSERT', 'SET NEW.canonical_node_id = CASE WHEN NEW.is_canonical = 1 THEN NEW.node_id ELSE NULL END');
        $this->trigger('catalog_alias_update', 'catalog_path_alias', 'UPDATE', 'SET NEW.canonical_node_id = CASE WHEN NEW.is_canonical = 1 THEN NEW.node_id ELSE NULL END');
    }

    private function trigger(string $name, string $table, string $event, string $body): void
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name = ?');
        $stmt->execute([$name]);
        $exists = (int) $stmt->get_result()->fetch_row()[0] > 0;
        $stmt->close();
        if (!$exists) {
            $this->db->query("CREATE TRIGGER `{$name}` BEFORE {$event} ON `{$table}` FOR EACH ROW {$body}");
        }
    }

    /** Removes only V1 additions. Legacy data and tables survive; V1 presentation data is removed. */
    public function down(): void
    {
        foreach (['catalog_alias_insert', 'catalog_alias_update', 'catalog_slug_parent_insert', 'catalog_slug_parent_update'] as $trigger) {
            $this->db->query("DROP TRIGGER IF EXISTS `{$trigger}`");
        }
        foreach (['catalog_path_alias', 'catalog_collection_item', 'catalog_collection', 'catalog_content_block', 'catalog_asset'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS `{$table}`");
        }
        if ($this->db->query("SHOW INDEX FROM category WHERE Key_name = 'catalog_sibling_slug'")->num_rows > 0) {
            $this->db->query('ALTER TABLE category DROP INDEX catalog_sibling_slug');
        }
        foreach (self::COLUMNS as $table => $columns) {
            foreach (array_reverse(array_keys($columns)) as $name) {
                if ($this->db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$name}'")->num_rows > 0) {
                    $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$name}`");
                }
            }
        }
    }
}
