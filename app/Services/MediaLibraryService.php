<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Support\Config;
use CatalogSuite\Support\MediaStorageRegistry;
use Throwable;

final class MediaLibraryService
{
    public function __construct(private MediaRepository $repository, private MediaStorageRegistry $storage)
    {
    }

    public function list(array $query): array
    {
        $where = ['a.status <> ?'];
        $parameters = ['purged'];
        $status = (string) ($query['status'] ?? '');
        if ($status === '') {
            $where[] = "a.status <> 'trashed'";
        } elseif ($status === 'unlinked') {
            $where[] = "NOT EXISTS (SELECT 1 FROM media_relationship r WHERE r.asset_id=a.id AND r.relationship_status='linked')";
        } elseif ($status === 'duplicates') {
            $where[] = "a.sha256 IS NOT NULL AND EXISTS (SELECT 1 FROM media_asset b WHERE b.sha256=a.sha256 AND b.size_bytes=a.size_bytes AND b.id<>a.id AND b.status<>'purged')";
        } else {
            $where[] = 'a.status=?';
            $parameters[] = $status;
        }
        $type = (string) ($query['type'] ?? '');
        if ($type === 'image') {
            $where[] = "a.mime_type LIKE 'image/%'";
        } elseif (in_array($type, ['pdf','glb'], true)) {
            $where[] = 'a.mime_type=?';
            $parameters[] = $type === 'pdf' ? 'application/pdf' : 'model/gltf-binary';
        } elseif ($type === 'other') {
            $where[] = "a.mime_type NOT LIKE 'image/%' AND a.mime_type NOT IN ('application/pdf','model/gltf-binary')";
        }
        if (!empty($query['root'])) {
            $where[] = 'a.storage_root=?';
            $parameters[] = (string) $query['root'];
        }
        $q = trim((string) ($query['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = "(a.stored_filename LIKE ? OR a.original_filename LIKE ? OR EXISTS
                (SELECT 1 FROM media_relationship r LEFT JOIN product p ON r.owner_type='product' AND p.id=r.owner_id
                 LEFT JOIN category c ON r.owner_type IN ('series','field_default') AND c.id=r.owner_id
                 WHERE r.asset_id=a.id AND (p.sku LIKE ? OR p.name LIKE ? OR c.name LIKE ?)))";
            array_push($parameters, $like,$like,$like,$like,$like);
        }
        $seriesIds = [];
        $ownerPredicate = null;
        if ((int) ($query['seriesId'] ?? 0) > 0) {
            $seriesIds = [(int) $query['seriesId']];
        } elseif ((int) ($query['categoryId'] ?? 0) > 0) {
            $nodes = $this->repository->rows('SELECT id,parent_id,type FROM category');
            $seen = [(int) $query['categoryId'] => true];
            do {
                $changed = false;
                foreach ($nodes as $node) {
                    if (isset($seen[(int) $node['parent_id']]) && !isset($seen[(int) $node['id']])) {
                        $seen[(int) $node['id']] = true;
                        $changed = true;
                    }
                }
            } while ($changed);
            foreach ($nodes as $node) {
                if (isset($seen[(int) $node['id']])) {
                    $seriesIds[] = (int) $node['id'];
                }
            }
        }
        if ($seriesIds !== []) {
            $ids = implode(',', array_map('intval', $seriesIds));
            $schema = new \CatalogSuite\Repositories\MediaReferenceRepository($this->repository);
            $typst = $schema->has('typst_variables')
                ? " OR (r.owner_type='typst_variable' AND r.owner_id IN (SELECT id FROM typst_variables WHERE series_id IN ($ids)))" : '';
            foreach (['typst_templates' => 'typst_template','latex_templates' => 'latex_template'] as $table => $ownerType) {
                if ($schema->hasColumn($table, 'series_id')) {
                    $typst .= " OR (r.owner_type='$ownerType' AND r.owner_id IN (SELECT id FROM $table WHERE series_id IN ($ids)))";
                }
            }
            $ownerPredicate = "(
                (r.owner_type IN ('series','field_default') AND r.owner_id IN ($ids))
                OR (r.owner_type='product' AND r.owner_id IN (SELECT id FROM product WHERE series_id IN ($ids)))
                $typst)";
            $where[] = "(EXISTS (SELECT 1 FROM media_relationship r WHERE r.asset_id=a.id AND $ownerPredicate)
                OR (a.source_kind='generated' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(a.provenance,'$.seriesId')) AS UNSIGNED) IN ($ids)))";
        }
        foreach (['productId' => ["r.owner_type='product' AND r.owner_id=?"], 'fieldId' => ['r.field_id=?']] as $key => [$condition]) {
            if ((int) ($query[$key] ?? 0) > 0) {
                if ($key === 'fieldId') {
                    if (!empty($query['fieldScope'])) {
                        $condition .= ' AND r.field_scope=?';
                    }
                    if ((int) ($query['productId'] ?? 0) > 0) {
                        $condition .= " AND r.owner_type='product' AND r.owner_id=" . (int) $query['productId'];
                    } elseif ($ownerPredicate !== null) {
                        $condition .= ' AND ' . $ownerPredicate;
                    }
                }
                $where[] = 'EXISTS (SELECT 1 FROM media_relationship r WHERE r.asset_id=a.id AND ' . $condition . ')';
                $parameters[] = (int) $query[$key];
                if ($key === 'fieldId' && !empty($query['fieldScope'])) {
                    $parameters[] = (string) $query['fieldScope'];
                }
            }
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) $this->repository->one('SELECT COUNT(*) AS n FROM media_asset a WHERE ' . $sqlWhere, $parameters)['n'];
        $perPage = max(1, min(100, (int) ($query['perPage'] ?? 50)));
        $last = max(1, (int) ceil($total / $perPage));
        $page = min($last, max(1, (int) ($query['page'] ?? 1)));
        $sort = ['filename' => 'a.stored_filename', 'size' => 'a.size_bytes', 'uploadedAt' => 'COALESCE(a.uploaded_at,a.first_seen_at)'][(string) ($query['sort'] ?? 'uploadedAt')] ?? 'a.first_seen_at';
        $direction = strtolower((string) ($query['direction'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
        $rows = $this->repository->rows('SELECT a.*,(SELECT COUNT(*) FROM media_relationship r WHERE r.asset_id=a.id) AS reference_count FROM media_asset a WHERE ' . $sqlWhere . ' ORDER BY ' . $sort . ' ' . $direction . ',a.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $parameters);
        return ['items' => array_map(fn (array $row): array => $this->present($row), $rows),
            'pagination' => ['page' => $page,'perPage' => $perPage,'total' => $total,'lastPage' => $last]];
    }

    public function present(array $asset): array
    {
        $id = $asset['id'];
        return [
            'id' => $id, 'filename' => $asset['original_filename'] ?: $asset['stored_filename'],
            'storedFilename' => $asset['stored_filename'], 'mimeType' => $asset['mime_type'],
            'sizeBytes' => (int) $asset['size_bytes'], 'status' => $asset['status'], 'sourceKind' => $asset['source_kind'],
            'storageRoot' => $asset['storage_root'], 'relativePath' => $asset['relative_path'],
            'uploadedAt' => $asset['uploaded_at'], 'firstSeenAt' => $asset['first_seen_at'], 'observedMtime' => $asset['observed_mtime'] === null ? null : (int) $asset['observed_mtime'],
            'referenceCount' => (int) ($asset['reference_count'] ?? $this->repository->one('SELECT COUNT(*) AS n FROM media_relationship WHERE asset_id=?', [$id])['n']),
            'confirmedReferenceCount' => (int) $this->repository->one("SELECT COUNT(*) AS n FROM media_relationship WHERE asset_id=? AND relationship_status='linked'", [$id])['n'],
            'version' => (int) $asset['version'], 'publicHold' => (bool) $asset['public_hold'],
            'downloadUrl' => $this->url('download', $id), 'previewUrl' => $asset['status'] === 'ready' ? $this->url('preview', $id) : null,
            'publicUrl' => Config::get('app')['media']['download_url'] . '?action=v1.downloadMedia&id=' . rawurlencode($this->legacyValue($asset)),
        ];
    }

    public function details(string $id, bool $includeAbsolute = false): array
    {
        $asset = $this->repository->asset($id);
        $data = $this->present($asset);
        $data['sha256'] = $asset['sha256'];
        $data['visibility'] = $asset['visibility'];
        $data['provenance'] = json_decode($asset['provenance'] ?? '{}', true);
        $data['generationContext'] = null;
        if ($asset['source_kind'] === 'generated' && !empty($data['provenance']['seriesId'])) {
            $series = $this->repository->one("SELECT id,parent_id,name FROM category WHERE id=? AND type='series'", [$data['provenance']['seriesId']]);
            if ($series !== null) {
                $data['generationContext'] = ['series' => ['id' => (int) $series['id'],'name' => $series['name']],
                    'category' => $this->repository->one('SELECT id,name FROM category WHERE id=?', [$series['parent_id']]),
                    'catalogUrl' => 'catalog_ui.html?seriesId=' . $series['id']];
            }
        }
        $data['aliases'] = array_column($this->repository->rows('SELECT locator FROM media_alias WHERE asset_id=? ORDER BY id', [$id]), 'locator');
        $data['relationships'] = $this->relationships($id);
        $data['relationshipPagination'] = ['page' => 1,'perPage' => 100,'total' => $data['referenceCount'],'lastPage' => max(1, (int) ceil($data['referenceCount'] / 100))];
        $configuredRoot = $this->storage->roots()[$asset['storage_root']] ?? '';
        $projectRoot = rtrim(str_replace('\\', '/', Config::get('app')['project_root']), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $configuredRoot), '/');
        $displayRoot = MediaStorageRegistry::within($normalizedRoot, $projectRoot)
            ? substr($normalizedRoot, strlen($projectRoot) + 1) : $asset['storage_root'];
        $data['storageLocation'] = $displayRoot . '/' . $asset['relative_path'];
        if ($includeAbsolute) {
            try {
                $data['absolutePath'] = $this->storage->absolute($asset['storage_root'], $asset['relative_path']);
            } catch (CatalogApiException) {
                $data['absolutePath'] = null;
            }
        }
        return $data;
    }

    public function relationships(string $id, int $page = 1, int $perPage = 100): array
    {
        $perPage = max(1, min(100, $perPage));
        $page = max(1, $page);
        $rows = $this->repository->rows('SELECT * FROM media_relationship WHERE asset_id=? ORDER BY id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), [$id]);
        foreach ($rows as &$row) {
            $node = null;
            $row['productSku'] = null;
            $row['field'] = null;
            if ($row['owner_type'] === 'product') {
                $product = $this->repository->one('SELECT id,sku,series_id FROM product WHERE id=?', [$row['owner_id']]);
                $node = $product['series_id'] ?? null;
                $row['productSku'] = $product['sku'] ?? null;
                $row['productId'] = $product === null ? null : (int) $product['id'];
            } elseif (in_array($row['owner_type'], ['series','field_default'], true)) {
                $node = $row['owner_id'];
            } elseif ($row['owner_type'] === 'typst_variable') {
                $variable = $this->repository->one('SELECT id,field_key,series_id FROM typst_variables WHERE id=?', [$row['owner_id']]);
                $node = $variable['series_id'] ?? null;
                $row['field'] = $variable['field_key'] ?? null;
            } elseif (in_array($row['owner_type'], ['typst_template','latex_template'], true)) {
                $table = $row['owner_type'] === 'typst_template' ? 'typst_templates' : 'latex_templates';
                $template = $this->repository->one("SELECT title,series_id FROM $table WHERE id=?", [$row['owner_id']]);
                $node = $template['series_id'] ?? null;
                $row['field'] = 'Template PDF: ' . ($template['title'] ?? '');
            }
            if (in_array($row['owner_type'], ['series','product','field_default'], true)) {
                $row['field'] = $this->repository->one('SELECT id,field_key,label FROM series_custom_field WHERE id=?', [$row['field_id']]);
            }
            if ($row['owner_type'] === 'field_default') {
                if ($row['field_scope'] === 'product_attribute') {
                    $row['effectiveRecordCount'] = (int) $this->repository->one(
                        'SELECT COUNT(*) AS n FROM product p WHERE series_id=? AND NOT EXISTS (SELECT 1 FROM product_custom_field_value v WHERE v.product_id=p.id AND v.series_custom_field_id=?)',
                        [$row['owner_id'],$row['field_id']]
                    )['n'];
                } else {
                    $override = $this->repository->one('SELECT value FROM series_custom_field_value WHERE series_id=? AND series_custom_field_id=?', [$row['owner_id'],$row['field_id']]);
                    $row['effectiveRecordCount'] = $override === null || $override['value'] === null ? 1 : 0;
                }
            }
            $series = $node === null ? null : $this->repository->one('SELECT id,name,type,parent_id FROM category WHERE id=?', [$node]);
            $category = $series === null ? null : ($series['type'] === 'category' ? $series : $this->repository->one('SELECT id,name FROM category WHERE id=?', [$series['parent_id']]));
            $row['series'] = $series !== null && $series['type'] === 'series' ? ['id' => (int) $series['id'],'name' => $series['name']] : null;
            $row['category'] = $category === null ? null : ['id' => (int) $category['id'],'name' => $category['name']];
            $params = ['fieldId' => $row['field_id'], 'fieldScope' => $row['field_scope']];
            if ($row['series'] !== null) {
                $params['seriesId'] = $row['series']['id'];
            } elseif ($category !== null) {
                $params['categoryId'] = $category['id'];
            }
            if (!empty($row['productId'])) {
                $params['productId'] = $row['productId'];
            }
            $row['catalogUrl'] = $row['relationship_status'] === 'linked' && in_array($row['owner_type'], ['series','product','field_default','typst_variable'], true) ? 'catalog_ui.html?' . http_build_query($params) : null;
            if ($row['relationship_status'] === 'linked' && $row['owner_type'] === 'typst_template') {
                $row['catalogUrl'] = $node ? 'series_typst_template.html?series_id=' . (int) $node : 'global_typst_template.html';
            }
        }
        unset($row);
        return $rows;
    }

    public function relationshipPage(string $id, int $page = 1, int $perPage = 100): array
    {
        $this->repository->asset($id);
        $perPage = max(1, min(100, $perPage));
        $total = (int) $this->repository->one('SELECT COUNT(*) AS n FROM media_relationship WHERE asset_id=?', [$id])['n'];
        $last = max(1, (int) ceil($total / $perPage));
        $page = min($last, max(1, $page));
        return ['items' => $this->relationships($id, $page, $perPage),'pagination' => ['page' => $page,'perPage' => $perPage,'total' => $total,'lastPage' => $last]];
    }

    public function folders(): array
    {
        return [
            'nodes' => $this->repository->rows('SELECT id,parent_id AS parentId,name,type FROM category ORDER BY display_order,name,id'),
            'fields' => $this->repository->rows("SELECT id,series_id AS seriesId,field_key AS fieldKey,label,field_scope AS fieldScope FROM series_custom_field WHERE field_type='file' ORDER BY sort_order,id"),
            'counts' => $this->repository->rows('SELECT status,COUNT(*) AS count FROM media_asset GROUP BY status'),
        ];
    }

    public function legacyValue(array $asset, bool $typst = false): string
    {
        if ($typst && $asset['storage_root'] === 'typst_assets') {
            return 'typst-assets/' . $asset['relative_path'];
        }
        return $asset['storage_root'] === 'catalog_media' ? $asset['relative_path'] : '__library__/' . $asset['id'] . '/' . $asset['stored_filename'];
    }

    public function readable(string $id, bool $preview = false): array
    {
        $asset = $this->repository->asset($id);
        return $this->readableAsset($asset, $preview);
    }

    /** Validate the current locked record without replacing it with a transaction snapshot. */
    public function readableAsset(array $asset, bool $preview = false): array
    {
        if (!in_array($asset['status'], $preview ? ['ready'] : ['ready','unsupported'], true)) {
            throw new CatalogApiException('MEDIA_NOT_READY', 'This file is unavailable or needs an integrity review.', 409);
        }
        $path = $this->storage->absolute($asset['storage_root'], $asset['relative_path']);
        if (!hash_equals((string) $asset['sha256'], (string) hash_file('sha256', $path))) {
            throw new CatalogApiException('MEDIA_INTEGRITY_CHANGED', 'The file has changed since it was indexed. Reconcile it before use.', 409);
        }
        $safeImages = ['image/png','image/jpeg','image/gif','image/webp','image/avif','image/bmp','image/x-ms-bmp'];
        if ($preview && !in_array($asset['mime_type'], [...$safeImages,'application/pdf'], true)) {
            throw new CatalogApiException('PREVIEW_UNSUPPORTED', 'Preview is unavailable. Use Download instead.', 415);
        }
        return ['asset' => $asset,'path' => $path];
    }

    public function lifecycle(string $id, int $version, string $action, string $actor, string $reason): array
    {
        $db = $this->repository->db;
        $db->begin_transaction();
        try {
            $asset = $this->repository->asset($id, true);
            if ((int) $asset['version'] !== $version) {
                throw new CatalogApiException('STALE_ASSET', 'This asset changed. Refresh its details.', 409);
            }
            (new MediaIndexService($this->repository, $this->storage))->syncReferences();
            $count = (int) $this->repository->one('SELECT COUNT(*) AS n FROM media_relationship WHERE asset_id=?', [$id])['n'];
            if ($action !== 'restore' && $count > 0) {
                throw new CatalogApiException('MEDIA_IN_USE', 'Detach this file from its Catalog records before deleting it.', 409, ['referenceCount' => $count]);
            }
            if (trim($reason) === '' && $action !== 'restore') {
                throw new CatalogApiException('REASON_REQUIRED', 'Enter a reason for this operation.', 422);
            }
            if ($action === 'purge') {
                // Public URLs and template/dynamic external references cannot be proved unused.
                // Byte deletion remains disabled even if an operator changes an environment flag.
                throw new CatalogApiException('PURGE_PROTECTED', 'Permanent deletion is protected. Existing public URLs and external references require a separate reviewed cleanup.', 409);
            }
            if ($action === 'restore' && $asset['status'] !== 'trashed') {
                throw new CatalogApiException('MEDIA_STATE_INVALID', 'Only a file in Trash can be restored.', 409);
            }
            $status = 'trashed';
            if ($action === 'restore') {
                try {
                    $path = $this->storage->absolute($asset['storage_root'], $asset['relative_path']);
                    if ($asset['sha256'] === null) {
                        $asset = (new MediaIndexService($this->repository, $this->storage))->register($asset['storage_root'], $asset['relative_path']);
                    }
                    $description = MediaStorageRegistry::describe($path);
                    $status = $asset['sha256'] !== null && !hash_equals($asset['sha256'], (string) hash_file('sha256', $path))
                        ? 'integrity_changed' : ($description['supported'] ? 'ready' : 'unsupported');
                } catch (CatalogApiException $error) {
                    if (!in_array($error->getErrorCode(), ['MEDIA_NOT_FOUND','MEDIA_ROOT_MISSING'], true)) throw $error;
                    $status = 'missing_file';
                }
            }
            $this->repository->execute('UPDATE media_asset SET status=?,version=version+1,trashed_at=IF(?=1,UTC_TIMESTAMP(),NULL) WHERE id=?', [$status,$action === 'trash' ? 1 : 0,$id]);
            $this->repository->audit($actor, $action, $id, ['before' => $asset['status'],'after' => $status,'reason' => substr($reason, 0, 1000)]);
            $db->commit();
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
        return $this->details($id);
    }

    private function url(string $action, string $id): string
    {
        $base = rtrim((string) (Config::get('app')['base_url'] ?? ''), '/');
        return ($base === '' ? '' : $base . '/') . 'api/media/' . $action . '.php?id=' . rawurlencode($id);
    }
}
