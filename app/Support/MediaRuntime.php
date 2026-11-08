<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\MediaAccess;
use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Services\MediaAssignmentService;
use CatalogSuite\Services\MediaIndexService;
use CatalogSuite\Services\MediaLibraryService;
use mysqli;

/** Optional integration facade; disabled installations retain their original request contracts. */
final class MediaRuntime
{
    public static function repository(mysqli $db): MediaRepository
    {
        MediaSchema::requireInstalled($db);
        return new MediaRepository($db);
    }

    public static function sync(mysqli $db, ?string $ownerType = null, ?int $ownerId = null): void
    {
        if (MediaAccess::enabled()) {
            (new MediaIndexService(self::repository($db), new MediaStorageRegistry()))->syncReferences(0, null, $ownerType, $ownerId);
        }
    }

    public static function register(mysqli $db, string $root, string $path, string $source = 'upload', ?string $original = null, array $provenance = []): void
    {
        if (MediaAccess::enabled()) {
            if (!isset((new MediaStorageRegistry())->roots()[$root])) {
                self::repository($db)->audit(MediaAccess::actor(), 'output_root_not_enabled', null, ['root' => $root,'path' => $path]);
                return;
            }
            (new MediaIndexService(self::repository($db), new MediaStorageRegistry()))->register($root, $path, $source, $original, MediaAccess::actor(), $provenance);
        }
    }

    public static function selection(mysqli $db, array $selection, bool $imageOnly = false, bool $lock = false): string
    {
        MediaAccess::requirePermission('attach');
        if (!isset($selection['mediaId']) || !is_string($selection['mediaId']) || !preg_match('/^[a-f0-9-]{36}$/i', $selection['mediaId'])) {
            throw new CatalogApiException('MEDIA_ID_INVALID', 'Choose a valid Media Library asset.', 422);
        }
        $repository = self::repository($db);
        $storage = new MediaStorageRegistry();
        $assetId = (string) $selection['mediaId'];
        $asset = (new MediaAssignmentService($repository, $storage))->selection($assetId, $imageOnly, $lock ? $repository->asset($assetId, true) : null);
        return (new MediaLibraryService($repository, $storage))->legacyValue($asset, $imageOnly);
    }

    public static function aliasPath(mysqli $db, string $value): ?string
    {
        if (!str_starts_with($value, '__library__/')) {
            return null;
        }
        $repository = self::repository($db);
        $asset = $repository->byAlias($value);
        if ($asset === null || $asset['visibility'] !== 'public_catalog' || !in_array($asset['status'], ['ready','trashed'], true)) {
            throw new CatalogApiException('MEDIA_NOT_FOUND', 'Media file not found.', 404);
        }
        return (new MediaStorageRegistry())->absolute($asset['storage_root'], $asset['relative_path']);
    }

    /** Retire-on-detach. Original files, generated files and old public URLs are preserved. */
    public static function retainInsteadOfDelete(mysqli $db, string $relativePath): bool
    {
        if (MediaAccess::enabled()) {
            $repository = self::repository($db);
            try {
                $asset = (new MediaIndexService($repository, new MediaStorageRegistry()))->resolve($relativePath);
            } catch (CatalogApiException) {
                $asset = null;
            }
            $repository->audit(MediaAccess::actor(), 'legacy_file_retained', $asset['id'] ?? null, ['locator' => $relativePath]);
            return true;
        }
        // Legacy paths may have shared or external references even before the first index.
        return true;
    }
}
