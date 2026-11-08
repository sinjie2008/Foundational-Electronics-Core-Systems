<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\MediaAccess;
use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Support\MediaStorageRegistry;
use Throwable;

/** Shared target validation and narrow attachment writes, preserving unrelated Catalog values. */
final class MediaAssignmentService
{
    public function __construct(private MediaRepository $repository, private MediaStorageRegistry $storage)
    {
    }

    public function target(array $input, bool $lock = false): array
    {
        \CatalogSuite\Http\MediaInput::scalars($input, ['type','target_type','id','target_id','category_id','series_id','product_id','product_sku','fieldId','field_id','fieldKey','field_key','fieldScope','field_scope','series_name','category_name']);
        foreach (['id','target_id','category_id','series_id','product_id','fieldId','field_id'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '' && (!preg_match('/^[0-9]+$/', trim((string) $input[$key])) || (float) $input[$key] > 2147483647)) {
                throw new CatalogApiException('TARGET_ID_INVALID', 'Catalog IDs must be whole positive record numbers.', 422);
            }
        }
        $type = (string) ($input['type'] ?? $input['target_type'] ?? '');
        $id = (int) ($input['id'] ?? $input['target_id'] ?? 0);
        if ($id <= 0) {
            $id = (int) ($input[$type . '_id'] ?? 0);
        }
        $fieldId = (int) ($input['fieldId'] ?? $input['field_id'] ?? 0);
        $fieldKey = trim((string) ($input['fieldKey'] ?? $input['field_key'] ?? ''));
        $suffix = $lock ? ' FOR UPDATE' : '';
        $scope = (string) ($input['fieldScope'] ?? $input['field_scope'] ?? '');
        if ($id <= 0 && in_array($type, ['series','category'], true) && !empty($input[$type . '_name'])) {
            $id = $this->uniqueNode($type, (string) $input[$type . '_name'], $type === 'series' ? (int) ($input['category_id'] ?? 0) : 0);
        }
        if ($type === 'product') {
            if (empty($input['series_id']) && !empty($input['series_name'])) {
                $input['series_id'] = $this->uniqueNode('series', (string) $input['series_name'], (int) ($input['category_id'] ?? 0));
            }
            if ($id <= 0 && !empty($input['product_sku']) && (int) ($input['series_id'] ?? 0) > 0) {
                $matches = $this->repository->rows('SELECT id FROM product WHERE series_id=? AND sku=?', [(int) $input['series_id'],(string) $input['product_sku']]);
                if (count($matches) !== 1) {
                    throw new CatalogApiException('TARGET_AMBIGUOUS', 'Product SKU must resolve uniquely inside the selected Series.', 422);
                }
                $id = (int) $matches[0]['id'];
            }
            $owner = $this->repository->one('SELECT id,series_id,sku,name FROM product WHERE id=?' . $suffix, [$id]);
            if ($owner !== null && !empty($input['product_sku']) && strcasecmp(trim((string) $input['product_sku']), $owner['sku']) !== 0) {
                throw new CatalogApiException('INVALID_RELATIONSHIP', 'Product ID and Product SKU identify different records.', 422);
            }
            $seriesId = (int) ($owner['series_id'] ?? 0);
            $requiredScope = 'product_attribute';
        } elseif ($type === 'series') {
            $owner = $this->repository->one("SELECT id,name,parent_id FROM category WHERE id=? AND type='series'" . $suffix, [$id]);
            $seriesId = $id;
            $requiredScope = 'series_metadata';
        } elseif ($type === 'category' || $type === 'typst_variable') {
            if (!(new \CatalogSuite\Repositories\MediaReferenceRepository($this->repository))->has('typst_variables')) {
                throw new CatalogApiException('FIELD_NOT_FOUND', 'No Typst image variables are configured for this target.', 422);
            }
            $node = null;
            if ($type === 'category') {
                $node = $this->repository->one("SELECT id,name FROM category WHERE id=? AND type='category'" . $suffix, [$id]);
                if ($node === null) {
                    throw new CatalogApiException('TARGET_NOT_FOUND', 'Category not found.', 422);
                }
                if (!empty($input['category_name']) && mb_strtolower(trim((string) $input['category_name']), 'UTF-8') !== mb_strtolower($node['name'], 'UTF-8')) {
                    throw new CatalogApiException('INVALID_RELATIONSHIP', 'Category ID and Category name disagree.', 422);
                }
                $variable = $fieldId > 0
                    ? $this->repository->rows('SELECT * FROM typst_variables WHERE id=? AND series_id=? AND is_global=0' . $suffix, [$fieldId,$id])
                    : $this->repository->rows('SELECT * FROM typst_variables WHERE field_key=? AND series_id=? AND is_global=0' . $suffix, [$fieldKey,$id]);
            } else {
                $variable = $this->repository->rows('SELECT * FROM typst_variables WHERE id=?' . $suffix, [$id]);
            }
            if (count($variable) !== 1) {
                throw new CatalogApiException('FIELD_NOT_FOUND', 'The image variable must exist and match this scope uniquely.', 422);
            }
            $variable = $variable[0];
            if ($fieldKey !== '' && $variable['field_key'] !== $fieldKey) {
                throw new CatalogApiException('INVALID_RELATIONSHIP', 'Variable ID and Field Key disagree.', 422);
            }
            if (!(bool) $variable['is_global']) {
                if ((int) $variable['series_id'] <= 0 || $this->repository->one('SELECT id FROM category WHERE id=?', [$variable['series_id']]) === null) {
                    throw new CatalogApiException('TARGET_NOT_FOUND', 'This scoped image variable has no valid Catalog owner.', 422);
                }
            }
            if (!in_array($variable['field_type'], ['file','image'], true)) {
                throw new CatalogApiException('FIELD_TYPE_INVALID', 'This variable does not accept images.', 422);
            }
            $result = ['type' => 'typst_variable','ownerId' => (int) $variable['id'],'fieldId' => (int) $variable['id'],
                'scope' => 'image','fieldKey' => $variable['field_key'],'label' => $variable['field_key'],'value' => $variable['field_value'],
                'required' => false,'imageOnly' => true,'rowExists' => true,'seriesId' => $variable['series_id'],'ownerLabel' => $node['name'] ?? 'Typst variable'];
            return $this->finishTarget($result, $variable);
        } else {
            throw new CatalogApiException('TARGET_TYPE_INVALID', 'Choose a Category, Series or Product target.', 422);
        }
        if ($owner === null) {
            throw new CatalogApiException('TARGET_NOT_FOUND', 'The Catalog target was not found.', 422);
        }
        if ($scope !== '' && $scope !== $requiredScope) {
            throw new CatalogApiException('INVALID_RELATIONSHIP', 'The field scope does not match the target.', 422);
        }
        $fields = $fieldId > 0
            ? $this->repository->rows('SELECT * FROM series_custom_field WHERE id=? AND series_id=? AND field_scope=?' . $suffix, [$fieldId,$seriesId,$requiredScope])
            : $this->repository->rows('SELECT * FROM series_custom_field WHERE field_key=? AND series_id=? AND field_scope=?' . $suffix, [$fieldKey,$seriesId,$requiredScope]);
        if (count($fields) !== 1) {
            throw new CatalogApiException('FIELD_NOT_FOUND', 'The field does not exist in this Catalog target and scope.', 422);
        }
        $field = $fields[0];
        if ($fieldKey !== '' && $field['field_key'] !== $fieldKey) {
            throw new CatalogApiException('INVALID_RELATIONSHIP', 'Field ID and Field Key disagree.', 422);
        }
        if ($field['field_type'] !== 'file') {
            throw new CatalogApiException('FIELD_TYPE_INVALID', 'Only file fields can receive media.', 422);
        }
        $series = $this->repository->one('SELECT id,parent_id,name FROM category WHERE id=?', [$seriesId]);
        if (!empty($input['series_name']) && mb_strtolower(trim((string) $input['series_name']), 'UTF-8') !== mb_strtolower((string) ($series['name'] ?? ''), 'UTF-8')) {
            throw new CatalogApiException('INVALID_RELATIONSHIP', 'Series ID and Series name disagree.', 422);
        }
        if (!empty($input['category_name'])) {
            $category = $this->repository->one('SELECT name FROM category WHERE id=?', [$series['parent_id'] ?? 0]);
            if (mb_strtolower(trim((string) $input['category_name']), 'UTF-8') !== mb_strtolower((string) ($category['name'] ?? ''), 'UTF-8')) {
                throw new CatalogApiException('INVALID_RELATIONSHIP', 'Category name does not match this Series.', 422);
            }
        }
        if (!empty($input['series_id']) && (int) $input['series_id'] !== $seriesId) {
            throw new CatalogApiException('INVALID_RELATIONSHIP', 'Product and Series do not match.', 422);
        }
        if (!empty($input['category_id']) && (int) $input['category_id'] !== (int) ($series['parent_id'] ?? 0)) {
            throw new CatalogApiException('INVALID_RELATIONSHIP', 'Category and Series do not match.', 422);
        }
        $table = $type === 'product' ? 'product_custom_field_value' : 'series_custom_field_value';
        $column = $type === 'product' ? 'product_id' : 'series_id';
        $value = $this->repository->one("SELECT value FROM $table WHERE $column=? AND series_custom_field_id=?" . $suffix, [$id,$field['id']]);
        return $this->finishTarget([
            'type' => $type,'ownerId' => $id,'fieldId' => (int) $field['id'],'scope' => $requiredScope,
            'fieldKey' => $field['field_key'],'label' => $field['label'],'value' => $value['value'] ?? null,
            'rowExists' => $value !== null,
            'defaultValue' => $field['default_value'],'required' => (bool) $field['is_required'],'imageOnly' => false,
            'seriesId' => $seriesId,'ownerLabel' => $owner['name'],'productSku' => $owner['sku'] ?? null,
        ], [$owner,$field]);
    }

    private function finishTarget(array $target, array $definition): array
    {
        $target['slot'] = $target['type'] . ':' . $target['ownerId'] . ':' . $target['scope'] . ':' . $target['fieldId'];
        $state = array_intersect_key($target, array_flip(['type','ownerId','fieldId','scope','value','rowExists','defaultValue','required']));
        $target['version'] = hash('sha256', MediaRepository::json([$state,$definition]));
        return $target;
    }

    private function uniqueNode(string $type, string $name, int $parentId): int
    {
        $rows = $parentId > 0
            ? $this->repository->rows('SELECT id FROM category WHERE type=? AND name=? AND parent_id=? LIMIT 2', [$type,trim($name),$parentId])
            : $this->repository->rows('SELECT id FROM category WHERE type=? AND name=? LIMIT 2', [$type,trim($name)]);
        if (count($rows) !== 1) {
            throw new CatalogApiException(count($rows) > 1 ? 'TARGET_AMBIGUOUS' : 'TARGET_NOT_FOUND', 'The supplied Catalog name must match one record. Use its stable ID to disambiguate.', 422);
        }
        return (int) $rows[0]['id'];
    }

    public function selection(string $id, bool $imageOnly = false, ?array $lockedAsset = null): array
    {
        $asset = $lockedAsset ?? $this->repository->asset($id);
        if ($asset['status'] !== 'ready') {
            throw new CatalogApiException('MEDIA_NOT_READY', 'Choose a file that is ready and has passed integrity checks.', 422);
        }
        if ($imageOnly && !str_starts_with($asset['mime_type'], 'image/')) {
            throw new CatalogApiException('MEDIA_TYPE_INCOMPATIBLE', 'This field accepts images only.', 422);
        }
        if (!str_starts_with($asset['mime_type'], 'image/') && !in_array($asset['mime_type'], ['application/pdf','model/gltf-binary'], true)) {
            throw new CatalogApiException('MEDIA_TYPE_INCOMPATIBLE', 'This file type cannot be attached.', 422);
        }
        // Active formats are download-only and cannot become Typst/image-field inputs.
        if (in_array($asset['mime_type'], ['image/svg+xml','text/html'], true)) {
            throw new CatalogApiException('MEDIA_TYPE_INCOMPATIBLE', 'This file requires a separate content review.', 422);
        }
        (new MediaLibraryService($this->repository, $this->storage))->readableAsset($asset);
        return $asset;
    }

    public function apply(array $input, ?string $mediaId, string $policy, string $expectedVersion, string $actor, bool $transaction = true, ?string $batchId = null): array
    {
        if (!in_array($policy, ['skip','replace','error'], true)) {
            throw new CatalogApiException('POLICY_INVALID', 'Choose skip, replace or error for existing attachments.', 422);
        }
        $db = $this->repository->db;
        if ($transaction) {
            $db->begin_transaction();
        }
        try {
            $asset = $mediaId === null ? null : $this->repository->asset($mediaId, true);
            $target = $this->target($input, true);
            if ($expectedVersion === '' || !hash_equals($target['version'], $expectedVersion)) {
                throw new CatalogApiException('STALE_TARGET', 'The target changed. Preview this mapping again.', 409);
            }
            $before = $target['value'];
            $beforeRowExists = $target['rowExists'];
            if ($asset !== null) {
                $asset = $this->selection($asset['id'], $target['imageOnly'], $asset);
                $value = (new MediaLibraryService($this->repository, $this->storage))->legacyValue($asset, $target['imageOnly']);
                if ($before === $value) {
                    $status = 'no_change';
                } elseif ($before !== null && $before !== '' && $policy === 'skip') {
                    $status = 'skipped';
                    $value = $before;
                } elseif ($before !== null && $before !== '' && $policy === 'error') {
                    throw new CatalogApiException('TARGET_OCCUPIED', 'The target already has an attachment.', 409);
                } else {
                    if ($before !== null && $before !== '') {
                        MediaAccess::requirePermission('replace');
                    }
                    $status = 'applied';
                }
            } else {
                if ($target['required']) {
                    throw new CatalogApiException('FIELD_REQUIRED', 'This required file field cannot be cleared.', 422);
                }
                $value = null;
                $status = $before === null || $before === '' ? 'no_change' : 'applied';
            }
            if ($status === 'applied') {
                $this->write($target, $value);
                if ($asset === null) {
                    $this->repository->execute('DELETE FROM media_relationship WHERE slot_key=?', [$target['slot']]);
                } else {
                    $target['value'] = $value;
                    $this->repository->relation($target, $asset['id']);
                }
                $this->repository->audit($actor, $mediaId === null ? 'detach' : 'attach', $mediaId, [
                    'target' => $input,'before' => $before,'after' => $value,
                ], $batchId);
            }
            $after = $this->target($input);
            $result = ['status' => $status,'mediaId' => $mediaId,'target' => $input,'before' => $before,'beforeRowExists' => $beforeRowExists,'after' => $value,'version' => $after['version']];
            if ($transaction) {
                $db->commit();
            }
            return $result;
        } catch (Throwable $error) {
            if ($transaction) {
                $db->rollback();
            }
            throw $error;
        }
    }

    /** Used by compensating rollback, under the same validated target lock. */
    public function restore(array $input, mixed $before, mixed $expectedAfter, string $actor, bool $beforeRowExists = true): void
    {
        $target = $this->target($input, true);
        if ($target['value'] !== $expectedAfter) {
            throw new CatalogApiException('ROLLBACK_CONFLICT', 'Another edit changed this target. It was not overwritten.', 409);
        }
        if ($target['required'] && ($before === null || $before === '') && ($beforeRowExists || empty($target['defaultValue']))) {
            throw new CatalogApiException('ROLLBACK_CONFLICT', 'This field is now required. Its current attachment was retained.', 409);
        }
        $this->write($target, $before, $beforeRowExists);
        $this->repository->audit($actor, 'mapping_rollback', null, ['target' => $input,'before' => $expectedAfter,'after' => $before]);
    }

    private function write(array $target, mixed $value, bool $rowExists = true): void
    {
        if ($target['type'] === 'typst_variable') {
            $this->repository->execute('UPDATE typst_variables SET field_value=? WHERE id=?', [$value ?? '',$target['ownerId']]);
            return;
        }
        $table = $target['type'] === 'product' ? 'product_custom_field_value' : 'series_custom_field_value';
        $column = $target['type'] === 'product' ? 'product_id' : 'series_id';
        if (!$rowExists) {
            $this->repository->execute("DELETE FROM $table WHERE $column=? AND series_custom_field_id=?", [$target['ownerId'],$target['fieldId']]);
            return;
        }
        $this->repository->execute("INSERT INTO $table ($column,series_custom_field_id,value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)", [$target['ownerId'],$target['fieldId'],$value]);
    }

    public function choices(array $query): array
    {
        $type = (string) ($query['type'] ?? 'series');
        $search = '%' . trim((string) ($query['q'] ?? '')) . '%';
        $id = (int) ($query['id'] ?? 0);
        if ($id > 0 && !empty($query['fieldId'])) {
            return ['target' => $this->target($query)];
        }
        if ($type === 'product') {
            $targets = $this->repository->rows('SELECT id,sku,name,series_id AS seriesId FROM product WHERE (sku LIKE ? OR name LIKE ?) ORDER BY sku,id LIMIT 100', [$search,$search]);
        } else {
            $targets = $this->repository->rows('SELECT id,name,parent_id AS parentId FROM category WHERE type=? AND name LIKE ? ORDER BY name,id LIMIT 100', [$type === 'category' ? 'category' : 'series',$search]);
        }
        $fields = [];
        if ($id > 0 && $type === 'category') {
            if ((new \CatalogSuite\Repositories\MediaReferenceRepository($this->repository))->has('typst_variables')) {
                $fields = $this->repository->rows("SELECT id,field_key AS fieldKey,field_key AS label,'typst_variable' AS fieldScope FROM typst_variables WHERE series_id=? AND is_global=0 AND field_type IN ('image','file') ORDER BY field_key,id", [$id]);
            }
        } elseif ($id > 0) {
            $seriesId = $type === 'product' ? (int) ($this->repository->one('SELECT series_id FROM product WHERE id=?', [$id])['series_id'] ?? 0) : $id;
            $fields = array_values(array_filter((new SeriesFieldService($this->repository->db))->listFields($seriesId, $type === 'product' ? 'product_attribute' : 'series_metadata'), fn (array $f): bool => $f['fieldType'] === 'file'));
        }
        return ['targets' => $targets,'fields' => $fields];
    }
}
