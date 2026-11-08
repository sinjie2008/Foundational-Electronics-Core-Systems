<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Repositories\MediaReferenceRepository;
use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Support\MediaStorageRegistry;
use Throwable;

final class MediaIndexService
{
    public function __construct(private MediaRepository $repository, private MediaStorageRegistry $storage)
    {
    }

    /** Register in place. Existing bytes and their historical locators are never changed. */
    public function register(string $root, string $path, string $source = 'legacy', ?string $original = null, ?string $actor = null, array $provenance = []): array
    {
        $path = MediaStorageRegistry::cleanRelative($path);
        $existing = $this->repository->at($root, $path);
        $size = 0;
        $mtime = null;
        $sha = null;
        $mime = 'application/octet-stream';
        $status = 'missing_file';
        try {
            $absolute = $this->storage->absolute($root, $path);
            $before = stat($absolute);
            $size = (int) filesize($absolute);
            $mtime = (int) filemtime($absolute);
            $sha = hash_file('sha256', $absolute);
            $description = MediaStorageRegistry::describe($absolute);
            $mime = $description['mime'];
            clearstatcache(true, $absolute);
            $after = stat($absolute);
            if ($before === false || $after === false || $before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime']) {
                throw new CatalogApiException('MEDIA_CHANGED_DURING_SCAN', 'This file changed during inspection. Retry it.', 409);
            }
            $status = $description['supported'] ? 'ready' : 'unsupported';
        } catch (CatalogApiException $error) {
            if (!in_array($error->getErrorCode(), ['MEDIA_NOT_FOUND','MEDIA_ROOT_MISSING'], true)) {
                throw $error;
            }
        }
        if ($existing !== null) {
            if ($existing['sha256'] !== null && $sha !== null && !hash_equals($existing['sha256'], $sha)) {
                $status = 'integrity_changed';
            }
            if (in_array($existing['status'], ['trashed','purged','pending'], true)) {
                $status = $existing['status'];
            }
            $this->repository->execute(
                'UPDATE media_asset SET status=?,checked_at=UTC_TIMESTAMP(),observed_mtime=?,
                 size_bytes=IF(sha256 IS NULL,?,size_bytes),mime_type=IF(sha256 IS NULL,?,mime_type),sha256=COALESCE(sha256,?) WHERE id=?',
                [$status,$mtime,$size,$mime,$sha,$existing['id']]
            );
            return $this->repository->asset($existing['id']);
        }
        $id = MediaStorageRegistry::uuid();
        $this->repository->execute(
            'INSERT INTO media_asset (id,storage_root,relative_path,path_hash,original_filename,stored_filename,mime_type,size_bytes,sha256,source_kind,status,provenance,uploaded_by,uploaded_at,observed_mtime,checked_at,first_seen_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,IF(?=1,UTC_TIMESTAMP(),NULL),?,UTC_TIMESTAMP(),UTC_TIMESTAMP())',
            [$id,$root,$path,$this->repository->pathHash($path),$original,basename($path),$mime,$size,$sha,$source,$status,MediaRepository::json($provenance),$actor,$source === 'upload' ? 1 : 0,$mtime]
        );
        $alias = '__library__/' . $id . '/' . basename($path);
        $this->repository->alias('legacy', $alias, $id);
        if ($root === 'catalog_media') {
            $this->repository->alias('legacy', $path, $id);
            $this->repository->alias('legacy', 'storage/media/' . $path, $id);
        } elseif ($root === 'typst_assets') {
            $this->repository->alias('legacy', 'typst-assets/' . $path, $id);
        }
        return $this->repository->asset($id);
    }

    public function resolve(string $value, bool $register = true): ?array
    {
        $existing = $this->repository->byAlias($value);
        if ($existing !== null) {
            return $existing;
        }
        $locator = $this->storage->locate($value);
        if ($locator === null || str_starts_with($locator['path'], '__library__/')) {
            return null;
        }
        $asset = $this->repository->at($locator['root'], $locator['path']);
        if ($asset === null && $register) {
            $asset = $this->register($locator['root'], $locator['path']);
        }
        if ($asset !== null && $register) {
            $this->repository->alias('legacy', $value, $asset['id']);
        }
        return $asset;
    }

    /** May join an existing business transaction; no nested commits or filesystem deletions. */
    public function syncReferences(int $offset = 0, ?int $limit = null, ?string $ownerType = null, ?int $ownerId = null): array
    {
        $seen = [];
        $issues = [];
        $all = (new MediaReferenceRepository($this->repository))->all();
        if ($ownerType !== null) {
            $all = array_values(array_filter($all, fn (array $slot): bool => $slot['type'] === $ownerType && $slot['ownerId'] === $ownerId));
        }
        foreach ($all as $slot) {
            $seen[$slot['slot']] = true;
        }
        foreach (array_slice($all, $offset, $limit) as $slot) {
            try {
                $asset = $this->resolve((string) $slot['value']);
                if ($asset === null) {
                    $issues[] = ['slot' => $slot['slot'], 'status' => 'unmanaged_reference'];
                    $this->repository->execute('DELETE FROM media_relationship WHERE slot_key=?', [$slot['slot']]);
                    continue;
                }
                $slot['status'] = 'linked';
                if (in_array($slot['type'], ['series','product','typst_variable'], true)) {
                    try {
                        (new MediaAssignmentService($this->repository, $this->storage))->target([
                            'type' => $slot['type'],'id' => $slot['ownerId'],'fieldId' => $slot['fieldId'],
                        ]);
                    } catch (CatalogApiException $error) {
                        $slot['status'] = 'invalid_target';
                        $issues[] = ['slot' => $slot['slot'],'status' => $error->getErrorCode(),'mediaId' => $asset['id']];
                    }
                }
                $this->repository->relation($slot, $asset['id']);
                if ($asset['status'] !== 'ready') {
                    $issues[] = ['slot' => $slot['slot'], 'status' => $asset['status'], 'mediaId' => $asset['id']];
                }
            } catch (CatalogApiException $error) {
                $issues[] = ['slot' => $slot['slot'], 'status' => $error->getErrorCode()];
            }
        }
        foreach ($this->repository->references() as $relation) {
            if (($limit === null || $offset + $limit >= count($all))
                && ($ownerType === null || ($relation['owner_type'] === $ownerType && (int) $relation['owner_id'] === $ownerId))
                && !isset($seen[$relation['slot_key']])) {
                $this->repository->execute('DELETE FROM media_relationship WHERE id=?', [$relation['id']]);
            }
        }
        return $issues;
    }

    /** Bounded, resumable directory traversal. The cursor is a position, never a filesystem path. */
    public function scan(?string $onlyRoot = null, int $offset = 0, int $limit = 100, string $phase = 'disk'): array
    {
        $limit = max(1, min(100, $limit));
        $roots = $this->storage->scanRoots();
        if ($onlyRoot !== null && !isset($roots[$onlyRoot])) {
            throw new CatalogApiException('MEDIA_ROOT_INVALID', 'This storage root is not authorized.', 422);
        }
        if ($phase === 'references') {
            $all = (new MediaReferenceRepository($this->repository))->all();
            $issues = $this->syncReferences($offset, $limit);
            $more = $offset + $limit < count($all);
            return ['items' => [],'issues' => $issues,'phase' => $phase,'nextPhase' => $more ? 'references' : 'indexed','nextCursor' => $more ? $offset + $limit : 0];
        }
        if ($phase === 'indexed') {
            $assets = $this->repository->rows("SELECT storage_root,relative_path FROM media_asset WHERE status NOT IN ('purged','pending') ORDER BY id LIMIT " . ($limit + 1) . ' OFFSET ' . max(0, $offset));
            $more = count($assets) > $limit;
            $items = [];
            foreach (array_slice($assets, 0, $limit) as $asset) {
                if ($onlyRoot !== null && $asset['storage_root'] !== $onlyRoot) {
                    continue;
                }
                try {
                    $row = $this->register($asset['storage_root'], $asset['relative_path']);
                    $items[] = ['mediaId' => $row['id'],'path' => $asset['relative_path'],'status' => $row['status']];
                } catch (CatalogApiException $error) {
                    $items[] = ['path' => $asset['relative_path'],'status' => $error->getErrorCode()];
                }
            }
            return ['items' => $items,'issues' => [],'phase' => $phase,'nextPhase' => $more ? 'indexed' : null,'nextCursor' => $more ? $offset + $limit : null];
        }
        if ($phase !== 'disk') {
            throw new CatalogApiException('INDEX_PHASE_INVALID', 'Invalid indexing phase.', 422);
        }
        $position = 0;
        $items = [];
        $hasMore = false;
        foreach ($roots as $key => $directory) {
            if (($onlyRoot !== null && $onlyRoot !== $key) || !is_dir($directory)) {
                continue;
            }
            $root = realpath($directory);
            $filter = new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $file): bool => !$file->isLink()
                    && !str_starts_with($file->getFilename(), '.')
                    && MediaStorageRegistry::within($file->getRealPath() ?: '', $root ?: '')
            );
            $iterator = new \RecursiveIteratorIterator($filter);
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->isLink() || str_starts_with($file->getFilename(), '.') || !MediaStorageRegistry::within($file->getRealPath() ?: '', $root ?: '')) {
                    continue;
                }
                if ($position++ < $offset) {
                    continue;
                }
                if (count($items) >= $limit) {
                    $hasMore = true;
                    break 2;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($directory, '/\\')) + 1));
                try {
                    $asset = $this->register($key, $relative);
                    $items[] = ['mediaId' => $asset['id'], 'path' => $relative, 'status' => $asset['status']];
                } catch (Throwable $error) {
                    $items[] = ['path' => $relative, 'status' => $error instanceof CatalogApiException ? $error->getErrorCode() : 'INSPECTION_FAILED'];
                }
            }
        }
        return ['items' => $items,'issues' => [],'phase' => $phase,'nextPhase' => $hasMore ? 'disk' : 'references','nextCursor' => $hasMore ? $offset + count($items) : 0];
    }
}
