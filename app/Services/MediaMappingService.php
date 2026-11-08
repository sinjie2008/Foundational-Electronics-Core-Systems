<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Support\Config;
use CatalogSuite\Support\MediaStorageRegistry;
use Throwable;

final class MediaMappingService
{
    public function __construct(private MediaRepository $repository, private MediaStorageRegistry $storage)
    {
    }

    public function csv(array $upload): array
    {
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')
            || filesize($upload['tmp_name']) > 2097152) {
            throw new CatalogApiException('CSV_INVALID', 'Choose a CSV file no larger than 2 MiB.', 422);
        }
        $handle = fopen($upload['tmp_name'], 'rb');
        if ($handle === false) {
            throw new CatalogApiException('CSV_INVALID', 'Unable to read this CSV.', 422);
        }
        try {
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false) {
                throw new CatalogApiException('CSV_INVALID', 'This CSV is empty.', 422);
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(static fn ($value): string => trim((string) $value), $header);
            if (count($header) !== count(array_unique($header)) || !in_array('target_type', $header, true)) {
                throw new CatalogApiException('CSV_HEADER_INVALID', 'Use the mapping template with unique column names and target_type.', 422);
            }
            $rows = [];
            $max = (int) (Config::get('app')['media_library']['mapping_row_limit'] ?? 1000);
            while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($rows) >= $max || count($values) !== count($header)) {
                    throw new CatalogApiException('CSV_ROW_INVALID', 'CSV row width is inconsistent or the row limit was exceeded.', 422, ['row' => count($rows) + 2]);
                }
                $row = array_combine($header, $values);
                foreach ($row as $value) {
                    if (!mb_check_encoding((string) $value, 'UTF-8')) {
                        throw new CatalogApiException('CSV_ENCODING_INVALID', 'Save this CSV as UTF-8.', 422);
                    }
                }
                $rows[] = $row;
            }
            return $rows;
        } finally {
            fclose($handle);
        }
    }

    public function validate(array $rows, string $actor, string $requestKey): array
    {
        $limit = (int) (Config::get('app')['media_library']['mapping_row_limit'] ?? 1000);
        if ($rows === [] || count($rows) > $limit) {
            throw new CatalogApiException('MAPPING_LIMIT', 'Choose between 1 and ' . $limit . ' mapping rows.', 422);
        }
        $assignment = new MediaAssignmentService($this->repository, $this->storage);
        $index = new MediaIndexService($this->repository, $this->storage);
        $validated = [];
        $rowIds = [];
        $slots = [];
        foreach ($rows as $number => $row) {
            if (!is_array($row)) {
                $validated[] = ['rowId' => 'row_' . ($number + 1),'rowNumber' => $number + 1,'status' => 'invalid','code' => 'ROW_INVALID','message' => 'Each mapping row must be an object.'];
                continue;
            }
            $rawRowId = $row['rowId'] ?? $row['row_id'] ?? 'row_' . ($number + 1);
            $rowId = is_scalar($rawRowId) ? (string) $rawRowId : 'row_' . ($number + 1);
            $result = ['rowId' => $rowId,'rowNumber' => $number + 1,'status' => 'valid','warnings' => []];
            try {
                \CatalogSuite\Http\MediaInput::scalars($row, ['rowId','row_id','mediaId','media_id','storage_root','relative_path','upload_batch_id','batch_item_id','onExisting','on_existing']);
                if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $rowId) || isset($rowIds[$rowId])) {
                    if (isset($rowIds[$rowId])) {
                        $validated[$rowIds[$rowId]]['status'] = 'invalid';
                        $validated[$rowIds[$rowId]]['code'] = 'ROW_ID_INVALID';
                        $validated[$rowIds[$rowId]]['message'] = 'Duplicate row_id. Give both rows unique identifiers.';
                    }
                    throw new CatalogApiException('ROW_ID_INVALID', 'Each row needs a unique row_id using letters, numbers, underscores or hyphens.', 422);
                }
                $rowIds[$rowId] = count($validated);
                $mediaId = (string) ($row['mediaId'] ?? $row['media_id'] ?? '');
                $locatorAsset = null;
                if (!empty($row['storage_root']) && !empty($row['relative_path'])) {
                    $locatorAsset = $this->repository->at((string) $row['storage_root'], MediaStorageRegistry::cleanRelative((string) $row['relative_path']));
                }
                if (!empty($row['upload_batch_id']) && !empty($row['batch_item_id'])) {
                    $this->repository->batch((string) $row['upload_batch_id'], $actor);
                    $item = $this->repository->item((string) $row['upload_batch_id'], (string) $row['batch_item_id']);
                    if ($item === null || $item['status'] !== 'ready') {
                        throw new CatalogApiException('MEDIA_NOT_READY', 'The referenced upload has not completed.', 422);
                    }
                    if ($mediaId !== '' && $mediaId !== $item['asset_id']) {
                        throw new CatalogApiException('MEDIA_LOCATOR_CONFLICT', 'The supplied media identifiers disagree.', 422);
                    }
                    $mediaId = $item['asset_id'];
                }
                if ($locatorAsset !== null) {
                    if ($mediaId !== '' && $mediaId !== $locatorAsset['id']) {
                        throw new CatalogApiException('MEDIA_LOCATOR_CONFLICT', 'The supplied media identifiers disagree.', 422);
                    }
                    $mediaId = $locatorAsset['id'];
                } elseif (!empty($row['relative_path'])) {
                    throw new CatalogApiException('MEDIA_NOT_FOUND', 'Index this exact storage location before mapping it.', 422);
                }
                if ($mediaId === '') {
                    throw new CatalogApiException('MEDIA_ID_REQUIRED', 'Use a media ID, exact indexed location or completed upload item.', 422);
                }
                $targetInput = $row['target'] ?? $row;
                if (!is_array($targetInput)) {
                    throw new CatalogApiException('ROW_INVALID', 'The mapping target must be an object.', 422);
                }
                $target = $assignment->target($targetInput);
                $asset = $assignment->selection($mediaId, $target['imageOnly']);
                if (isset($slots[$target['slot']])) {
                    $previousIndex = $slots[$target['slot']];
                    $validated[$previousIndex]['status'] = 'invalid';
                    $validated[$previousIndex]['code'] = 'DUPLICATE_TARGET';
                    $validated[$previousIndex]['message'] = 'More than one row writes to this target field. Resolve every conflicting row.';
                    throw new CatalogApiException('DUPLICATE_TARGET', 'More than one row writes to this target field.', 422);
                }
                $slots[$target['slot']] = count($validated);
                $policy = (string) ($row['onExisting'] ?? $row['on_existing'] ?? 'skip');
                if (!in_array($policy, ['skip','replace','error'], true)) {
                    throw new CatalogApiException('POLICY_INVALID', 'on_existing must be skip, replace or error.', 422);
                }
                $canonical = ['type' => $target['type'],'id' => $target['ownerId'],'fieldId' => $target['fieldId'],'fieldScope' => $target['scope']];
                $after = (new MediaLibraryService($this->repository, $this->storage))->legacyValue($asset, $target['imageOnly']);
                if (!empty($target['value']) && $target['value'] !== $after) {
                    $result['warnings'][] = $policy === 'replace' ? 'This replaces the current attachment; its file will be retained.' : 'This target already has an attachment.';
                    if ($policy === 'error') {
                        throw new CatalogApiException('TARGET_OCCUPIED', 'This target already has an attachment.', 422);
                    }
                }
                $result += ['mediaId' => $mediaId,'filename' => $asset['original_filename'] ?: $asset['stored_filename'],
                    'target' => $canonical,'targetLabel' => $target['ownerLabel'],'fieldLabel' => $target['label'],
                    'before' => $target['value'],'after' => $after,'expectedVersion' => $target['version'],'onExisting' => $policy];
            } catch (CatalogApiException $error) {
                $result['status'] = 'invalid';
                $result['code'] = $error->getErrorCode();
                $result['message'] = $error->getMessage();
            }
            $validated[] = $result;
        }
        $batch = $this->repository->createBatch('mapping', $actor, $requestKey, ['rows' => $rows]);
        if ($this->repository->one('SELECT id FROM media_batch_item WHERE batch_id=? LIMIT 1', [$batch['id']]) !== null) {
            throw new CatalogApiException('MAPPING_ALREADY_STARTED', 'This mapping has already started. Review its batch results.', 409);
        }
        $token = bin2hex(random_bytes(32));
        $expires = time() + 1800;
        $plan = ['rows' => $validated,'tokenHash' => hash('sha256', $token),'expiresAt' => $expires];
        $this->repository->execute('UPDATE media_batch SET plan=?,status=? WHERE id=?', [MediaRepository::json($plan),'validated',$batch['id']]);
        $this->repository->audit($actor, 'mapping_preview', null, ['rowCount' => count($rows)], $batch['id']);
        return ['validationId' => $batch['id'],'token' => $token,'expiresAt' => gmdate(DATE_ATOM, $expires),
            'rows' => $validated,'summary' => ['valid' => count(array_filter($validated, fn ($r): bool => $r['status'] === 'valid')),'invalid' => count(array_filter($validated, fn ($r): bool => $r['status'] !== 'valid'))]];
    }

    public function apply(string $id, string $token, string $mode, int $cursor, string $actor): array
    {
        if (!in_array($mode, ['atomic','valid_rows'], true)) {
            throw new CatalogApiException('MAPPING_MODE_INVALID', 'Choose atomic or explicitly apply valid rows.', 422);
        }
        $db = $this->repository->db;
        $db->begin_transaction();
        try {
            $batch = $this->repository->batch($id, $actor, true);
            if ($batch['status'] === 'invalidated') {
                throw new CatalogApiException('VALIDATION_EXPIRED', 'Catalog data was reset. Preview the remaining mapping again.', 409);
            }
            $plan = json_decode($batch['plan'], true, 512, JSON_THROW_ON_ERROR);
            if ($batch['kind'] !== 'mapping' || !hash_equals((string) ($plan['tokenHash'] ?? ''), hash('sha256', $token))) {
                throw new CatalogApiException('VALIDATION_TOKEN_INVALID', 'Preview this mapping again.', 409);
            }
            if ((int) ($plan['expiresAt'] ?? 0) < time() && $batch['status'] !== 'completed') {
                throw new CatalogApiException('VALIDATION_EXPIRED', 'This preview expired. Validate the remaining rows again.', 409);
            }
            $rows = $plan['rows'];
            $atomicLimit = (int) (Config::get('app')['media_library']['atomic_mapping_limit'] ?? 100);
            if ($mode === 'atomic' && (count($rows) > $atomicLimit || array_filter($rows, fn ($r): bool => $r['status'] !== 'valid'))) {
                throw new CatalogApiException('MAPPING_NOT_ATOMIC', 'Atomic mapping requires all rows to be valid and within the atomic row limit.', 422);
            }
            $cursor = max(0, $cursor);
            $slice = $mode === 'atomic' ? $rows : array_slice($rows, $cursor, 25);
            $results = [];
            $assignment = new MediaAssignmentService($this->repository, $this->storage);
            foreach ($slice as $row) {
                if ($row['status'] !== 'valid') {
                    $results[] = $row;
                    continue;
                }
                $previous = $this->repository->item($id, $row['rowId']);
                if ($previous !== null && in_array($previous['status'], ['applied','skipped','no_change','rolled_back'], true)) {
                    $results[] = json_decode($previous['result'], true);
                    continue;
                }
                try {
                    $result = $assignment->apply($row['target'], $row['mediaId'], $row['onExisting'], $row['expectedVersion'], $actor, false, $id);
                    $result['rowId'] = $row['rowId'];
                    $this->repository->saveItem($id, $row['rowId'], $result['status'], $result, $row['mediaId'], ['target' => $row['target'],'value' => $result['before'],'rowExists' => $result['beforeRowExists']], ['value' => $result['after']]);
                    $results[] = $result;
                } catch (CatalogApiException $error) {
                    if ($mode === 'atomic') {
                        throw $error;
                    }
                    $result = ['rowId' => $row['rowId'],'status' => 'failed','code' => $error->getErrorCode(),'message' => $error->getMessage()];
                    $this->repository->saveItem($id, $row['rowId'], 'failed', $result);
                    $results[] = $result;
                }
            }
            $next = $mode === 'atomic' || $cursor + count($slice) >= count($rows) ? null : $cursor + count($slice);
            $this->repository->execute('UPDATE media_batch SET status=? WHERE id=?', [$next === null ? 'completed' : 'partial',$id]);
            $db->commit();
            return ['batchId' => $id,'items' => $results,'nextCursor' => $next];
        } catch (Throwable $error) {
            $db->rollback();
            throw $error;
        }
    }

    public function rollback(string $id, string $actor): array
    {
        $batch = $this->repository->batch($id, $actor);
        if ($batch['status'] === 'invalidated') {
            throw new CatalogApiException('VALIDATION_EXPIRED', 'Catalog data was reset. This earlier mapping cannot be rolled back onto new records.', 409);
        }
        $results = [];
        foreach ($this->repository->rows("SELECT * FROM media_batch_item WHERE batch_id=? AND status='applied' ORDER BY id DESC", [$id]) as $item) {
            $this->repository->db->begin_transaction();
            try {
                $before = json_decode($item['before_state'], true);
                $after = json_decode($item['after_state'], true);
                (new MediaAssignmentService($this->repository, $this->storage))->restore($before['target'], $before['value'], $after['value'], $actor, $before['rowExists'] ?? true);
                (new MediaIndexService($this->repository, $this->storage))->syncReferences();
                $result = ['rowId' => $item['client_item_id'],'status' => 'rolled_back'];
                $this->repository->execute("UPDATE media_batch_item SET status='rolled_back',result=? WHERE id=?", [MediaRepository::json($result),$item['id']]);
                $this->repository->db->commit();
                $results[] = $result;
            } catch (Throwable $error) {
                $this->repository->db->rollback();
                $results[] = ['rowId' => $item['client_item_id'],'status' => 'conflict','message' => $error instanceof CatalogApiException ? $error->getMessage() : 'Rollback failed; the current attachment was retained.'];
            }
        }
        return ['batchId' => $id,'items' => $results];
    }
}
