<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Support\Config;
use CatalogSuite\Support\MediaRuntime;
use CatalogSuite\Support\MediaStorageRegistry;
use CatalogSuite\Http\MediaAccess;

use mysqli;
use CatalogSuite\Repositories\MediaStorageRepository;
use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\HttpResponder;

final class MediaStorageService
{
    private string $rootDir;
    private int $maxBytes;
    private array $allowedMime;
    private array $allowedExtensions;
    private MediaStorageRepository $repository;

    public function __construct(
        private mysqli $connection,
        ?string $rootDir = null,
        ?int $maxBytes = null,
        ?array $allowedMime = null,
        ?array $allowedExtensions = null
    ) {
        if (\CatalogSuite\Http\MediaAccess::enabled() && \CatalogSuite\Http\MediaAccess::isLegacyMutation()) {
            \CatalogSuite\Support\MediaSchema::requireInstalled($connection);
        }
        $settings = Config::get('app');
        $this->maxBytes = $maxBytes ?? $settings['media']['max_bytes'];
        $this->allowedMime = $allowedMime ?? $settings['media']['allowed_mime'];
        $this->allowedExtensions = $allowedExtensions ?? $settings['media']['allowed_extensions'];
        $this->rootDir = rtrim($rootDir ?? $settings['storage']['media'], DIRECTORY_SEPARATOR);
        $this->repository = new MediaStorageRepository($connection);
    }

    /**
     * Stores an uploaded file for a series-scoped field and returns path info.
     *
     * @param array<string, mixed> $file
     *
     * @return array{relativePath:string, absolutePath:string}
     */
    public function saveSeriesFile(int $seriesId, int $entityId, string $fieldKey, array $file): array
    {
        if (\CatalogSuite\Http\MediaAccess::enabled()) {
            \CatalogSuite\Http\MediaAccess::requirePermission('upload');
        }
        $this->validateUpload($file);
        $context = $this->fetchSeriesContext($seriesId);
        $safeFilename = $this->sanitizeFilename($file['name'] ?? 'file');
        $relativePath = $this->buildRelativePath($context, $fieldKey, $entityId, $safeFilename);
        $absolutePath = $this->rootDir . DIRECTORY_SEPARATOR . $relativePath;
        // A replacement is a new immutable file, including same-name replacements.
        if (\CatalogSuite\Http\MediaAccess::enabled() || file_exists($absolutePath)) {
            $extension = pathinfo($safeFilename, PATHINFO_EXTENSION);
            $base = pathinfo($safeFilename, PATHINFO_FILENAME);
            $safeFilename = $base . '__' . str_replace('-', '', MediaStorageRegistry::uuid()) . ($extension === '' ? '' : '.' . $extension);
            $relativePath = $this->buildRelativePath($context, $fieldKey, $entityId, $safeFilename);
            $absolutePath = $this->rootDir . DIRECTORY_SEPARATOR . $relativePath;
        }
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new CatalogApiException('MEDIA_WRITE_FAILED', 'Unable to create media directory.', 500);
        }

        $moved = move_uploaded_file($file['tmp_name'], $absolutePath);
        if ($moved === false && !rename($file['tmp_name'], $absolutePath)) {
            throw new CatalogApiException('MEDIA_WRITE_FAILED', 'Unable to store uploaded file.', 500);
        }
        MediaRuntime::register($this->connection, 'catalog_media', $relativePath, 'upload', (string) ($file['name'] ?? $safeFilename));

        return [
            'relativePath' => $relativePath,
            'absolutePath' => $absolutePath,
        ];
    }

    /**
     * Retires a reference while retaining bytes for shared records and historical URLs.
     */
    public function deleteFile(?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }
        MediaRuntime::retainInsteadOfDelete($this->connection, $relativePath);
    }

    /**
     * Builds a media value payload for responses.
     *
     * @return array<string, mixed>|null
     */
    public function buildMediaValue(?string $relativePath): ?array
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }
        $absolutePath = $this->resolveAbsolutePath($relativePath);
        if (!is_file($absolutePath)) {
            return null;
        }

        $size = filesize($absolutePath);
        $mtime = filemtime($absolutePath);

        return [
            'filename' => basename($relativePath),
            'url' => Config::get('app')['media']['download_url'] . '?action=v1.downloadMedia&id=' . rawurlencode($relativePath),
            'sizeBytes' => $size === false ? null : $size,
            'storedAt' => $mtime === false ? null : gmdate(DATE_ATOM, $mtime),
        ];
    }

    /**
     * Streams a stored media file to the client.
     */
    public function streamMedia(string $relativePath, HttpResponder $responder): void
    {
        $absolutePath = $this->resolveAbsolutePath($relativePath);
        if (!is_file($absolutePath)) {
            throw new CatalogApiException('MEDIA_NOT_FOUND', 'Media file not found.', 404);
        }
        $mime = mime_content_type($absolutePath) ?: 'application/octet-stream';
        $responder->sendFile($absolutePath, basename($absolutePath), $mime);
    }

    /**
     * @param array<string, mixed> $file
     */
    private function validateUpload(array $file): void
    {
        if (\CatalogSuite\Http\MediaAccess::enabled()) {
            MediaStorageRegistry::inspectUpload($file);
            return;
        }
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new CatalogApiException('MEDIA_UPLOAD_ERROR', 'File upload failed.', 400, ['error' => $error]);
        }
        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            throw new CatalogApiException('MEDIA_EMPTY', 'Uploaded file is empty.', 400);
        }
        if ($size > $this->maxBytes) {
            throw new CatalogApiException('MEDIA_TOO_LARGE', 'Uploaded file exceeds size limit.', 400);
        }

        $tmpPath = (string) ($file['tmp_name'] ?? '');
        $mime = $this->detectMime($tmpPath);
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        $isImage = str_starts_with($mime, 'image/');
        $isAllowedMime = in_array($mime, $this->allowedMime, true);
        $isAllowedExt = in_array($extension, $this->allowedExtensions, true);

        if (!$isImage && !$isAllowedMime && !$isAllowedExt) {
            throw new CatalogApiException('MEDIA_TYPE_INVALID', 'Unsupported file type (images, PDF, GLB only).', 400);
        }
    }

    /**
     * @return array<string, string>
     */
    private function fetchSeriesContext(int $seriesId): array
    {
        $row = $this->repository->findSeriesContext($seriesId);

        if ($row === null) {
            throw new CatalogApiException('SERIES_NOT_FOUND', 'Series not found for media storage.', 404);
        }

        return [
            'category' => (string) ($row['category_name'] ?? 'root'),
            'series' => (string) $row['series_name'],
        ];
    }

    /**
     * @param array<string, string> $context
     */
    private function buildRelativePath(array $context, string $fieldKey, int $entityId, string $filename): string
    {
        $categorySlug = $this->slug($context['category']);
        $seriesSlug = $this->slug($context['series']);
        $fieldSlug = $this->slug($fieldKey);

        return implode(
            '/',
            [$categorySlug, $seriesSlug, $fieldSlug, (string) $entityId, $filename]
        );
    }

    private function slug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/i', '-', $normalized);
        $normalized = trim((string) $normalized, '-');

        return $normalized !== '' ? $normalized : 'item';
    }

    private function sanitizeFilename(string $filename): string
    {
        if (MediaAccess::enabled()) {
            return MediaStorageRegistry::filename($filename);
        }
        $basename = basename(str_replace('\\', '/', $filename));
        $clean = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);

        return $clean !== '' ? $clean : 'file';
    }

    private function detectMime(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return 'application/octet-stream';
        }
        $info = finfo_open(FILEINFO_MIME_TYPE);
        if ($info === false) {
            return 'application/octet-stream';
        }
        $mime = finfo_file($info, $path);
        finfo_close($info);

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    private function resolveAbsolutePath(string $relativePath): string
    {
        $alias = MediaRuntime::aliasPath($this->connection, $relativePath);
        if ($alias !== null) {
            return $alias;
        }
        $clean = MediaStorageRegistry::cleanRelative($relativePath);
        $absolute = $this->rootDir . DIRECTORY_SEPARATOR . $clean;
        $realRoot = realpath($this->rootDir);
        $realTarget = realpath($absolute) ?: $absolute;
        if ($realRoot !== false && !MediaStorageRegistry::within($realTarget, $realRoot)) {
            throw new CatalogApiException('MEDIA_PATH_INVALID', 'Invalid media path.', 400);
        }

        return $absolute;
    }

}
