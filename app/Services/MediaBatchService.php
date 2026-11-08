<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Support\MediaStorageRegistry;
use Throwable;

final class MediaBatchService
{
    public function __construct(private MediaRepository $repository, private MediaStorageRegistry $storage)
    {
    }

    public function create(array $input, string $actor): array
    {
        $items = $input['items'] ?? [];
        if (!is_array($items) || count($items) > 1000) {
            throw new CatalogApiException('BATCH_INVALID', 'Choose at most 1000 files per batch.', 422);
        }
        $seen = [];
        foreach ($items as $item) {
            $id = is_array($item) ? (string) ($item['clientItemId'] ?? '') : '';
            if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id) || isset($seen[$id])) {
                throw new CatalogApiException('BATCH_ITEM_INVALID', 'Each upload file needs a unique queue identifier.', 422);
            }
            $seen[$id] = true;
        }
        $batch = $this->repository->createBatch('upload', $actor, (string) ($input['idempotencyKey'] ?? ''), ['items' => $items]);
        foreach ($items as $item) {
            $id = (string) ($item['clientItemId'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id)) {
                throw new CatalogApiException('BATCH_ITEM_INVALID', 'Invalid file queue identifier.', 422);
            }
            if ($this->repository->item($batch['id'], $id) === null) {
                $this->repository->saveItem($batch['id'], $id, 'queued', ['filename' => (string) ($item['filename'] ?? '')]);
            }
        }
        return $this->status($batch['id'], $actor);
    }

    public function status(string $id, string $actor, int $offset = 0): array
    {
        $batch = $this->repository->batch($id, $actor);
        $decodedPlan = json_decode($batch['plan'] ?? '{}', true);
        $manifest = [];
        foreach ($decodedPlan['items'] ?? [] as $entry) {
            $manifest[$entry['clientItemId']] = $entry;
        }
        $items = $this->repository->rows('SELECT client_item_id AS clientItemId,status,asset_id AS mediaId,result,before_state,after_state FROM media_batch_item WHERE batch_id=? ORDER BY id LIMIT 100 OFFSET ' . max(0, $offset), [$id]);
        foreach ($items as &$item) {
            $item['result'] = json_decode($item['result'] ?? '{}', true);
            $item['filename'] = $manifest[$item['clientItemId']]['filename'] ?? $item['result']['asset']['filename'] ?? '';
            $item['sizeBytes'] = (int) ($manifest[$item['clientItemId']]['sizeBytes'] ?? $item['result']['asset']['sizeBytes'] ?? 0);
            unset($item['before_state'], $item['after_state']);
        }
        unset($item);
        $count = (int) $this->repository->one('SELECT COUNT(*) AS n FROM media_batch_item WHERE batch_id=?', [$id])['n'];
        $summary = $this->repository->rows('SELECT status,COUNT(*) AS count FROM media_batch_item WHERE batch_id=? GROUP BY status', [$id]);
        $status = $batch['status'];
        if ($batch['kind'] === 'upload') {
            $states = array_column($summary, 'status');
            $status = array_diff($states, ['ready','cancelled']) === []
                ? ($states === ['cancelled'] ? 'cancelled' : 'completed')
                : (array_intersect($states, ['failed','duplicate_candidate']) !== [] ? 'needs_attention' : 'pending');
        }
        return ['id' => $id,'kind' => $batch['kind'],'status' => $status,'items' => $items,'total' => $count,
            'mappingRows' => $batch['kind'] === 'mapping' ? ($decodedPlan['rows'] ?? []) : null,
            'validationExpiresAt' => $batch['kind'] === 'mapping' ? ($decodedPlan['expiresAt'] ?? null) : null,
            'nextCursor' => $offset + count($items) < $count ? $offset + count($items) : null,
            'summary' => $summary];
    }

    public function upload(string $batchId, string $clientId, array $file, string $actor, bool $keepDuplicate = false): array
    {
        try {
            return $this->performUpload($batchId, $clientId, $file, $actor, $keepDuplicate);
        } catch (Throwable $error) {
            try {
                $this->repository->db->begin_transaction();
                $this->repository->batch($batchId, $actor, true);
                $item = $this->repository->item($batchId, $clientId, true);
                if ($item !== null && !in_array($item['status'], ['ready','cancelled'], true)) {
                    $this->repository->saveItem($batchId, $clientId, 'failed', [
                        'status' => 'failed','code' => $error instanceof CatalogApiException ? $error->getErrorCode() : 'UPLOAD_FAILED',
                        'message' => $error instanceof CatalogApiException ? $error->getMessage() : 'Upload did not finish. Check status before retrying.',
                    ]);
                }
                $this->repository->db->commit();
            } catch (Throwable) {
                $this->repository->db->rollback();
                // Preserve the original error if storage/database recovery is unavailable.
            }
            throw $error;
        }
    }

    private function performUpload(string $batchId, string $clientId, array $file, string $actor, bool $keepDuplicate): array
    {
        $this->repository->batch($batchId, $actor);
        $inspection = MediaStorageRegistry::inspectUpload($file);
        $db = $this->repository->db;
        $db->begin_transaction();
        $destination = null;
        try {
            $batch = $this->repository->batch($batchId, $actor, true);
            $item = $this->repository->item($batchId, $clientId, true);
            if ($batch['kind'] !== 'upload' || $item === null) {
                throw new CatalogApiException('BATCH_ITEM_INVALID', 'This file is not in the upload queue.', 422);
            }
            if ($item['status'] === 'cancelled') {
                throw new CatalogApiException('UPLOAD_CANCELLED', 'This pending file was cancelled.', 409);
            }
            if ($item['status'] === 'ready') {
                $asset = $this->repository->asset($item['asset_id']);
                if ($asset['sha256'] !== $inspection['sha256']) {
                    throw new CatalogApiException('IDEMPOTENCY_CONFLICT', 'Different content was submitted for this completed file.', 409);
                }
                $db->commit();
                return json_decode($item['result'], true);
            }
            $duplicates = $this->repository->rows("SELECT id,stored_filename FROM media_asset WHERE sha256=? AND size_bytes=? AND status='ready' LIMIT 20", [$inspection['sha256'],$inspection['size']]);
            if ($duplicates !== [] && !$keepDuplicate) {
                $result = ['status' => 'duplicate_candidate','duplicates' => $duplicates,'sha256' => $inspection['sha256']];
                $this->repository->saveItem($batchId, $clientId, 'duplicate_candidate', $result);
                $db->commit();
                return $result;
            }
            $root = $this->storage->roots()['catalog_media'];
            if (!is_dir($root) && !mkdir($root, 0775, true) && !is_dir($root)) {
                throw new CatalogApiException('MEDIA_WRITE_FAILED', 'The media storage location is unavailable.', 503);
            }
            $directoryId = MediaStorageRegistry::uuid();
            $path = 'library/' . $directoryId . '/' . MediaStorageRegistry::filename((string) ($file['name'] ?? 'file'));
            $destination = $this->storage->absolute('catalog_media', $path, false);
            if (!mkdir(dirname($destination), 0775, true) || !move_uploaded_file($file['tmp_name'], $destination)) {
                throw new CatalogApiException('MEDIA_WRITE_FAILED', 'Unable to store this file. Please retry.', 503);
            }
            $asset = (new MediaIndexService($this->repository, $this->storage))->register('catalog_media', $path, 'upload', (string) $file['name'], $actor);
            $result = ['status' => 'ready','asset' => (new MediaLibraryService($this->repository, $this->storage))->present($asset)];
            $this->repository->saveItem($batchId, $clientId, 'ready', $result, $asset['id']);
            $this->repository->audit($actor, 'upload', $asset['id'], ['path' => $path,'sha256' => $inspection['sha256']], $batchId);
            $db->commit();
            return $result;
        } catch (Throwable $error) {
            $db->rollback();
            // Only a newly allocated UUID location may be removed here; never an existing asset.
            if ($destination !== null && is_file($destination) && !@unlink($destination)) {
                $this->repository->audit($actor, 'cleanup_pending', null, ['path' => $destination], $batchId);
            }
            throw $error;
        }
    }

    public function cancel(string $id, array $clientIds, string $actor): array
    {
        $this->repository->db->begin_transaction();
        try {
            $this->repository->batch($id, $actor, true);
            foreach ($clientIds as $clientId) {
                $this->repository->execute("UPDATE media_batch_item SET status='cancelled' WHERE batch_id=? AND client_item_id=? AND status IN ('queued','failed','duplicate_candidate')", [$id,(string) $clientId]);
            }
            $this->repository->db->commit();
        } catch (Throwable $error) {
            $this->repository->db->rollback();
            throw $error;
        }
        return $this->status($id, $actor);
    }

    public function reuse(string $batchId, string $clientId, string $assetId, string $actor): array
    {
        $this->repository->db->begin_transaction();
        try {
            $this->repository->batch($batchId, $actor, true);
            $item = $this->repository->item($batchId, $clientId, true);
            if ($item === null || !in_array($item['status'], ['duplicate_candidate','ready'], true)) {
                throw new CatalogApiException('DUPLICATE_STATE_INVALID', 'Check this upload for duplicate candidates first.', 409);
            }
            if ($item['status'] === 'ready') {
                if ($item['asset_id'] !== $assetId) {
                    throw new CatalogApiException('IDEMPOTENCY_CONFLICT', 'A different asset was already selected for this file.', 409);
                }
                $this->repository->db->commit();
                return json_decode($item['result'], true);
            }
            $candidates = json_decode($item['result'], true);
            $asset = (new MediaAssignmentService($this->repository, $this->storage))->selection($assetId);
            if (!in_array($assetId, array_column($candidates['duplicates'] ?? [], 'id'), true) || !hash_equals((string) $candidates['sha256'], (string) $asset['sha256'])) {
                throw new CatalogApiException('DUPLICATE_MISMATCH', 'Choose one of the matching file candidates.', 422);
            }
            $result = ['status' => 'ready','asset' => (new MediaLibraryService($this->repository, $this->storage))->present($asset),'reused' => true];
            $this->repository->saveItem($batchId, $clientId, 'ready', $result, $assetId);
            $this->repository->audit($actor, 'upload_reuse', $assetId, ['clientItemId' => $clientId], $batchId);
            $this->repository->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->repository->db->rollback();
            throw $error;
        }
    }
}
