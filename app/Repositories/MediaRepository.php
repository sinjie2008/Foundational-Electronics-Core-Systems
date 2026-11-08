<?php
declare(strict_types=1);

namespace CatalogSuite\Repositories;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Support\MediaStorageRegistry;
use mysqli;

/** SQL persistence shared by the media workflows; it never creates application tables. */
final class MediaRepository
{
    public function __construct(public readonly mysqli $db)
    {
    }

    public function rows(string $sql, array $parameters = []): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parameters);
        $result = $stmt->get_result();
        $rows = $result === false ? [] : $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    public function one(string $sql, array $parameters = []): ?array
    {
        return $this->rows($sql, $parameters)[0] ?? null;
    }

    public function execute(string $sql, array $parameters = []): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($parameters);
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected;
    }

    public function asset(string $id, bool $lock = false): array
    {
        $row = $this->one('SELECT * FROM media_asset WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$id]);
        if ($row === null) {
            throw new CatalogApiException('MEDIA_NOT_FOUND', 'Media asset not found.', 404);
        }
        return $row;
    }

    public function pathHash(string $path): string
    {
        return hash('sha256', PHP_OS_FAMILY === 'Windows' ? mb_strtolower($path, 'UTF-8') : $path);
    }

    public function at(string $root, string $path): ?array
    {
        return $this->one('SELECT * FROM media_asset WHERE storage_root = ? AND path_hash = ?', [$root, $this->pathHash($path)]);
    }

    public function alias(string $namespace, string $locator, string $assetId): void
    {
        $hash = hash('sha256', $locator);
        $existing = $this->one('SELECT asset_id, locator FROM media_alias WHERE namespace = ? AND locator_hash = ?', [$namespace, $hash]);
        if ($existing !== null && ($existing['asset_id'] !== $assetId || $existing['locator'] !== $locator)) {
            throw new CatalogApiException('MEDIA_ALIAS_CONFLICT', 'A legacy media address resolves to more than one asset.', 409);
        }
        $this->execute('INSERT IGNORE INTO media_alias (asset_id,namespace,locator,locator_hash) VALUES (?,?,?,?)', [$assetId, $namespace, $locator, $hash]);
    }

    public function byAlias(string $locator): ?array
    {
        return $this->one("SELECT a.* FROM media_alias l JOIN media_asset a ON a.id=l.asset_id WHERE l.namespace='legacy' AND l.locator_hash=? AND BINARY l.locator=BINARY ?", [hash('sha256', $locator), $locator]);
    }

    public function audit(string $actor, string $action, ?string $assetId, array $details, ?string $batchId = null): void
    {
        $this->execute('INSERT INTO media_audit_event (actor,action,asset_id,batch_id,details,correlation_id) VALUES (?,?,?,?,?,?)', [
            $actor, $action, $assetId, $batchId, self::json($details),
            substr((string) ($_SERVER['HTTP_X_CORRELATION_ID'] ?? ''), 0, 100) ?: null,
        ]);
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function relation(array $slot, string $assetId): void
    {
        $this->execute(
            'INSERT INTO media_relationship (asset_id,slot_key,owner_type,owner_id,field_scope,field_id,legacy_value,relationship_status) VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE asset_id=VALUES(asset_id),owner_type=VALUES(owner_type),owner_id=VALUES(owner_id),field_scope=VALUES(field_scope),field_id=VALUES(field_id),legacy_value=VALUES(legacy_value),relationship_status=VALUES(relationship_status)',
            [$assetId, $slot['slot'], $slot['type'], $slot['ownerId'], $slot['scope'], $slot['fieldId'], $slot['value'], $slot['status'] ?? 'linked']
        );
    }

    public function references(): array
    {
        return $this->rows('SELECT * FROM media_relationship ORDER BY id');
    }

    public function createBatch(string $kind, string $actor, string $key, array $payload): array
    {
        if ($key === '' || strlen($key) > 150) {
            throw new CatalogApiException('IDEMPOTENCY_KEY_REQUIRED', 'A valid operation key is required.', 422);
        }
        $key = hash('sha256', $actor . "\0" . $key);
        $hash = hash('sha256', self::json($payload));
        $existing = $this->one('SELECT * FROM media_batch WHERE idempotency_key=?', [$key]);
        if ($existing !== null) {
            if ($existing['payload_hash'] !== $hash || $existing['kind'] !== $kind) {
                throw new CatalogApiException('IDEMPOTENCY_CONFLICT', 'This operation key was already used for different content.', 409);
            }
            return $existing;
        }
        $id = MediaStorageRegistry::uuid();
        $this->execute('INSERT INTO media_batch (id,kind,actor,idempotency_key,payload_hash,plan) VALUES (?,?,?,?,?,?)', [$id, $kind, $actor, $key, $hash, self::json($payload)]);
        return $this->batch($id, $actor);
    }

    public function batch(string $id, string $actor, bool $lock = false): array
    {
        $row = $this->one('SELECT * FROM media_batch WHERE id=? AND actor=?' . ($lock ? ' FOR UPDATE' : ''), [$id, $actor]);
        if ($row === null) {
            throw new CatalogApiException('BATCH_NOT_FOUND', 'This operation was not found.', 404);
        }
        return $row;
    }

    public function item(string $batchId, string $clientId, bool $lock = false): ?array
    {
        return $this->one('SELECT * FROM media_batch_item WHERE batch_id=? AND client_item_id=?' . ($lock ? ' FOR UPDATE' : ''), [$batchId, $clientId]);
    }

    public function saveItem(string $batchId, string $clientId, string $status, array $result, ?string $assetId = null, ?array $before = null, ?array $after = null): void
    {
        $this->execute(
            'INSERT INTO media_batch_item (batch_id,client_item_id,status,asset_id,before_state,after_state,result) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE status=VALUES(status),asset_id=VALUES(asset_id),before_state=COALESCE(VALUES(before_state),before_state),after_state=COALESCE(VALUES(after_state),after_state),result=VALUES(result)',
            [$batchId, $clientId, $status, $assetId, $before === null ? null : self::json($before), $after === null ? null : self::json($after), self::json($result)]
        );
    }
}
