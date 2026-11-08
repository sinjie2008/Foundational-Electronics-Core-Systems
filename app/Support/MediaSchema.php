<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Http\CatalogApiException;
use mysqli;

/** Explicit installation only. HTTP requests never provision or alter this schema. */
final class MediaSchema
{
    public const VERSION = 1;

    public static function isInstalled(mysqli $db): bool
    {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('media_asset','media_alias','media_relationship','media_batch','media_batch_item','media_audit_event')");
        $stmt->execute();
        $count = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count === 6;
    }

    public static function requireInstalled(mysqli $db): void
    {
        if (!self::isInstalled($db)) {
            throw new CatalogApiException('MEDIA_NOT_INSTALLED', 'Media Library data has not been initialized yet.', 503);
        }
    }

    public static function install(mysqli $db): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS media_asset (
                id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                storage_root VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                relative_path TEXT NOT NULL,
                path_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                original_filename VARCHAR(255) NULL,
                stored_filename VARCHAR(255) NOT NULL,
                mime_type VARCHAR(127) NOT NULL,
                size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                source_kind VARCHAR(24) NOT NULL DEFAULT 'legacy',
                status VARCHAR(32) NOT NULL DEFAULT 'ready',
                visibility VARCHAR(24) NOT NULL DEFAULT 'public_catalog',
                public_hold TINYINT(1) NOT NULL DEFAULT 1,
                provenance LONGTEXT NULL,
                uploaded_by VARCHAR(255) NULL,
                uploaded_at DATETIME NULL,
                observed_mtime BIGINT NULL,
                first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                checked_at DATETIME NULL,
                trashed_at DATETIME NULL,
                version INT UNSIGNED NOT NULL DEFAULT 1,
                UNIQUE KEY media_location (storage_root, path_hash),
                KEY media_content (sha256, size_bytes),
                KEY media_browse (status, mime_type, first_seen_at, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS media_alias (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                namespace VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                locator TEXT NOT NULL,
                locator_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                UNIQUE KEY media_alias_locator (namespace, locator_hash),
                KEY media_alias_asset (asset_id),
                CONSTRAINT media_alias_asset_fk FOREIGN KEY (asset_id) REFERENCES media_asset(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS media_relationship (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                asset_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                slot_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                owner_type VARCHAR(32) NOT NULL,
                owner_id INT NOT NULL,
                field_scope VARCHAR(32) NOT NULL,
                field_id INT NOT NULL DEFAULT 0,
                legacy_value TEXT NOT NULL,
                relationship_status VARCHAR(24) NOT NULL DEFAULT 'linked',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY media_slot (slot_key),
                KEY media_usage (asset_id),
                KEY media_owner (owner_type, owner_id, field_id),
                CONSTRAINT media_relationship_asset_fk FOREIGN KEY (asset_id) REFERENCES media_asset(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS media_batch (
                id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                kind VARCHAR(24) NOT NULL,
                actor VARCHAR(255) NOT NULL,
                idempotency_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(24) NOT NULL DEFAULT 'pending',
                plan LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY media_batch_request (idempotency_key),
                KEY media_batch_actor (actor, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS media_batch_item (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                batch_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                client_item_id VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'queued',
                payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
                asset_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
                before_state LONGTEXT NULL,
                after_state LONGTEXT NULL,
                result LONGTEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY media_batch_client_item (batch_id, client_item_id),
                CONSTRAINT media_item_batch_fk FOREIGN KEY (batch_id) REFERENCES media_batch(id) ON DELETE RESTRICT,
                CONSTRAINT media_item_asset_fk FOREIGN KEY (asset_id) REFERENCES media_asset(id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "CREATE TABLE IF NOT EXISTS media_audit_event (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor VARCHAR(255) NOT NULL,
                action VARCHAR(64) NOT NULL,
                asset_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
                batch_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
                details LONGTEXT NOT NULL,
                correlation_id VARCHAR(100) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY media_audit_asset (asset_id, id),
                KEY media_audit_batch (batch_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        ];
        foreach ($statements as $sql) {
            $db->query($sql);
        }
    }
}
