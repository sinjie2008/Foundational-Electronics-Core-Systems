<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\MediaAccess;
use CatalogSuite\Http\Request;
use CatalogSuite\Http\Transport;
use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Services\MediaAssignmentService;
use CatalogSuite\Services\MediaBatchService;
use CatalogSuite\Services\MediaIndexService;
use CatalogSuite\Services\MediaLibraryService;
use CatalogSuite\Services\MediaMappingService;
use CatalogSuite\Support\Config;
use CatalogSuite\Support\Db;
use CatalogSuite\Support\MediaSchema;
use CatalogSuite\Support\MediaStorageRegistry;
use mysqli;
use Throwable;

final class MediaLibraryController
{
    public function __construct(private ?mysqli $connection = null)
    {
    }

    /** Public delivery only for explicitly registered public aliases; no directory listing. */
    public function publicDownload(): void
    {
        try {
            \CatalogSuite\Http\MediaInput::scalars($_GET, ['path']);
            if (!in_array(Request::method(), ['GET','HEAD'], true)) {
                throw new CatalogApiException('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
            }
            $locator = (string) ($_GET['path'] ?? '');
            if (!str_starts_with($locator, '__library__/')) {
                throw new CatalogApiException('MEDIA_NOT_FOUND', 'Media file not found.', 404);
            }
            $db = $this->connection ?? Db::connection();
            $path = \CatalogSuite\Support\MediaRuntime::aliasPath($db, $locator);
            if ($path === null) {
                throw new CatalogApiException('MEDIA_NOT_FOUND', 'Media file not found.', 404);
            }
            Transport::header('Content-Type: ' . (mime_content_type($path) ?: 'application/octet-stream'));
            Transport::header('Content-Length: ' . filesize($path));
            Transport::header('X-Content-Type-Options: nosniff');
            Transport::header("Content-Security-Policy: sandbox; default-src 'none'");
            $mime = mime_content_type($path) ?: 'application/octet-stream';
            if (!in_array($mime, ['image/png','image/jpeg','image/gif','image/webp','image/avif','image/bmp','application/pdf','model/gltf-binary'], true)) {
                Transport::header('Content-Disposition: attachment');
            }
            if (Request::method() !== 'HEAD') {
                Transport::file($path);
            }
        } catch (Throwable) {
            Transport::status(404);
        }
    }

    public function run(?string $action = null): void
    {
        $correlationId = Request::correlationId();
        try {
            $action ??= pathinfo((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH), PATHINFO_FILENAME);
            $method = Request::method();
            $methods = [
                'capabilities' => ['GET'],'list' => ['GET'],'details' => ['GET'],'folders' => ['GET'],'targets' => ['GET'],'relationships' => ['GET'],
                'download' => ['GET','HEAD'],'preview' => ['GET','HEAD'],'batches' => ['GET','POST'],'upload' => ['POST'],'batch-cancel' => ['POST'],'batch-reuse' => ['POST'],
                'attach' => ['POST'],'detach' => ['POST'],'mapping-validate' => ['POST'],'mapping-apply' => ['POST'],'mapping-rollback' => ['POST'],
                'index' => ['POST'],'asset' => ['DELETE'],'restore' => ['POST'],'purge' => ['POST'],
            ];
            if (!isset($methods[$action])) {
                throw new CatalogApiException('NOT_FOUND', 'Media API route not found.', 404);
            }
            if (!in_array($method, $methods[$action], true)) {
                Transport::header('Allow: ' . implode(', ', $methods[$action]));
                throw new CatalogApiException('METHOD_NOT_ALLOWED', 'Method not allowed.', 405);
            }
            $permission = match ($action) {
                'upload','batch-cancel','batch-reuse' => 'upload', 'attach','detach','targets','mapping-validate','mapping-apply','mapping-rollback' => 'attach',
                'asset','restore' => 'trash','purge' => 'purge','index' => 'index',
                'batches' => $method === 'POST' ? 'upload' : 'view', default => 'view',
            };
            $identity = MediaAccess::requirePermission($permission);
            $actor = (string) $identity['actor'];
            $db = $this->connection ?? Db::connection();
            MediaSchema::requireInstalled($db);
            $repository = new MediaRepository($db);
            $storage = new MediaStorageRegistry();
            $library = new MediaLibraryService($repository, $storage);
            $assignment = new MediaAssignmentService($repository, $storage);
            $batches = new MediaBatchService($repository, $storage);
            $mapping = new MediaMappingService($repository, $storage);
            $input = in_array($method, ['POST','DELETE'], true) && empty($_FILES) && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data') === false ? \CatalogSuite\Http\MediaInput::json() : $_POST;
            \CatalogSuite\Http\MediaInput::scalars($_GET, ['id','mediaId','q','type','status','root','page','perPage','sort','direction','categoryId','seriesId','productId','fieldId','fieldScope','cursor']);
            \CatalogSuite\Http\MediaInput::scalars($input, ['id','mediaId','batchId','clientItemId','onExisting','expectedVersion','validationId','token','mode','reason','idempotencyKey','root','cursor','phase','keepDuplicate']);
            $id = (string) ($_GET['id'] ?? $input['id'] ?? '');
            if (in_array($action, ['download','preview'], true)) {
                $file = $library->readable($id, $action === 'preview');
                $this->file($file, $action === 'preview', $method === 'HEAD');
                return;
            }
            $data = match ($action) {
                'capabilities' => ['maxBytes' => MediaStorageRegistry::maxUploadBytes(),'extensions' => MediaStorageRegistry::extensions(),'types' => ['image','pdf','glb'],'permissions' => $identity['permissions'],
                    'roots' => array_keys($storage->roots()),'atomicMappingLimit' => Config::get('app')['media_library']['atomic_mapping_limit'],'purgeEnabled' => false,'csrfToken' => $identity['csrfToken'] ?? null],
                'list' => $library->list($_GET),
                'details' => $library->details($id, in_array('*', $identity['permissions'], true) || in_array('view_paths', $identity['permissions'], true)),
                'folders' => $library->folders(),
                'relationships' => $library->relationshipPage((string) ($_GET['mediaId'] ?? $id), (int) ($_GET['page'] ?? 1), (int) ($_GET['perPage'] ?? 100)),
                'targets' => $assignment->choices($_GET),
                'batches' => $method === 'GET' ? $batches->status($id, $actor, (int) ($_GET['cursor'] ?? 0)) : $batches->create($input, $actor),
                'upload' => $batches->upload((string) ($input['batchId'] ?? ''), (string) ($input['clientItemId'] ?? ''), $_FILES['file'] ?? [], $actor, ($input['keepDuplicate'] ?? '') === '1'),
                'batch-cancel' => $batches->cancel((string) ($input['batchId'] ?? ''), (array) ($input['itemIds'] ?? []), $actor),
                'batch-reuse' => $batches->reuse((string) ($input['batchId'] ?? ''), (string) ($input['clientItemId'] ?? ''), (string) ($input['mediaId'] ?? ''), $actor),
                'attach' => $assignment->apply((array) ($input['target'] ?? []), (string) ($input['mediaId'] ?? ''), (string) ($input['onExisting'] ?? 'skip'), (string) ($input['expectedVersion'] ?? ''), $actor),
                'detach' => $assignment->apply((array) ($input['target'] ?? []), null, 'replace', (string) ($input['expectedVersion'] ?? ''), $actor),
                'mapping-validate' => $mapping->validate(isset($_FILES['file']) ? $mapping->csv($_FILES['file']) : (array) ($input['rows'] ?? []), $actor, (string) ($input['idempotencyKey'] ?? $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')),
                'mapping-apply' => $mapping->apply((string) ($input['validationId'] ?? ''), (string) ($input['token'] ?? ''), (string) ($input['mode'] ?? 'atomic'), (int) ($input['cursor'] ?? 0), $actor),
                'mapping-rollback' => $mapping->rollback((string) ($input['batchId'] ?? ''), $actor),
                'index' => $this->index($repository, $storage, $input, $actor),
                'asset','restore','purge' => $library->lifecycle($id, (int) ($input['expectedVersion'] ?? 0), $action === 'asset' ? 'trash' : $action, $actor, (string) ($input['reason'] ?? '')),
                default => [],
            };
            $this->json(['success' => true,'data' => $data,'correlationId' => $correlationId], $action === 'upload' && ($data['status'] ?? '') === 'ready' ? 201 : 200);
        } catch (CatalogApiException $error) {
            $this->json(['error' => ['code' => $error->getErrorCode(),'message' => $error->getMessage(),'details' => $error->getDetails(),'correlationId' => $correlationId]], $error->getStatusCode());
        } catch (Throwable) {
            // Do not disclose database messages, tokens or absolute filesystem paths.
            $this->json(['error' => ['code' => 'MEDIA_INTERNAL_ERROR','message' => 'The media operation could not finish. Refresh its status before retrying.','correlationId' => $correlationId]], 500);
        }
    }

    private function index(MediaRepository $repository, MediaStorageRegistry $storage, array $input, string $actor): array
    {
        $result = (new MediaIndexService($repository, $storage))->scan(isset($input['root']) && $input['root'] !== '' ? (string) $input['root'] : null, max(0, (int) ($input['cursor'] ?? 0)), 100, (string) ($input['phase'] ?? 'disk'));
        $repository->audit($actor, 'index', null, ['root' => $input['root'] ?? null,'cursor' => $input['cursor'] ?? 0,'count' => count($result['items']),'issues' => $result['issues']]);
        return $result;
    }

    private function json(array $payload, int $status): void
    {
        Transport::status($status);
        Transport::header('Content-Type: application/json; charset=utf-8');
        Transport::header('Cache-Control: no-store');
        Transport::header('X-Content-Type-Options: nosniff');
        Transport::header('X-Correlation-ID: ' . ($payload['correlationId'] ?? $payload['error']['correlationId'] ?? ''));
        echo MediaRepository::json($payload);
    }

    private function file(array $file, bool $preview, bool $head): void
    {
        $asset = $file['asset'];
        Transport::header('Content-Type: ' . $asset['mime_type']);
        Transport::header('Content-Length: ' . filesize($file['path']));
        Transport::header('X-Content-Type-Options: nosniff');
        Transport::header('Cache-Control: private, no-store');
        Transport::header("Content-Security-Policy: sandbox; default-src 'none'; style-src 'unsafe-inline'");
        Transport::header('Content-Disposition: ' . ($preview ? 'inline' : 'attachment') . '; filename="' . MediaStorageRegistry::filename($asset['stored_filename']) . '"');
        if (!$head) {
            Transport::file($file['path']);
        }
    }
}
