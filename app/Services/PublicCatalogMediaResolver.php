<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Repositories\MediaRepository;
use CatalogSuite\Support\MediaSchema;
use CatalogSuite\Support\MediaStorageRegistry;
use finfo;
use mysqli;
use Throwable;

/** Resolves public catalog media references without indexing or mutating the Media Library. */
final class PublicCatalogMediaResolver
{
    private const IMAGE_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp'];
    private ?bool $mediaInstalled = null;
    private array $readableAssets = [];
    private array $validatedMedia = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $assetsByAlias = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $assetsById = [];

    private MediaRepository $repository;
    private ?MediaStorageRegistry $storage = null;
    private ?finfo $fileTypes = null;

    public function __construct(private mysqli $db)
    {
        $this->repository = new MediaRepository($db);
    }

    /**
     * Resolve legacy values exactly as the public product API does, with one asset query per batch.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    public function legacyUrls(array $values, bool $byId = false): array
    {
        $lookups = [];
        foreach ($values as $key => $raw) {
            $value = trim((string) $raw);
            if ($value !== '') {
                $lookups[(string) $key] = $value;
            }
        }

        if ($lookups === [] || !$this->isMediaInstalled()) {
            return [];
        }

        $assets = $byId
            ? $this->assetsByIds(array_values(array_unique(array_values($lookups))))
            : $this->assetsByAliases(array_values(array_unique(array_values($lookups))));

        $media = [];
        foreach ($lookups as $key => $value) {
            $asset = $assets[$value] ?? null;
            if ($this->isPublicReadyAsset($asset)) {
                $media[$key] = $this->publicUrl($asset);
            }
        }

        return $media;
    }

    /**
     * Describe only public, registered files whose stored bytes match an allowed media type.
     *
     * @param array<string, mixed> $values field key to legacy alias
     * @return array<string, array{filename: string, mimeType: string, width?: int, height?: int}>
     */
    public function registeredMediaDetails(array $values): array
    {
        $aliases = [];
        foreach ($values as $key => $raw) {
            $alias = trim((string) $raw);
            if ($alias !== '') {
                $aliases[(string) $key] = $alias;
            }
        }
        if ($aliases === [] || !$this->isMediaInstalled()) {
            return [];
        }

        $assets = $this->assetsByAliases(array_values(array_unique(array_values($aliases))));
        $details = [];
        foreach ($aliases as $key => $alias) {
            $asset = $assets[$alias] ?? null;
            if (!$this->isPublicReadyAsset($asset)
                || !$this->safeStoredFilename((string) $asset['stored_filename'])
                || $asset['delivery_id'] === null) {
                continue;
            }

            $media = $this->validatedMedia($asset);
            if ($media === null) {
                continue;
            }
            $filename = $this->safeDownloadFilename((string) ($asset['original_filename'] ?? ''))
                ?? (string) $asset['stored_filename'];
            $details[$key] = ['filename' => $filename] + $media;
        }
        return $details;
    }

    /**
     * Resolve file details and valid-PDF counts for the supplied whole-Series part rows.
     *
     * @param list<array{id: int, values: array<string, mixed>}> $parts
     * @param list<string> $fileKeys
     * @return array{details: array<int, array<string, array{filename: string, mimeType: string, width?: int, height?: int}>>, validPdfCounts: array<string, int>}
     */
    public function productMediaDetails(array $parts, array $fileKeys): array
    {
        $references = [];
        foreach ($parts as $part) {
            $partId = (int) $part['id'];
            foreach ($fileKeys as $fieldKey) {
                $alias = trim((string) ($part['values'][$fieldKey] ?? ''));
                if ($alias !== '') {
                    $references[$partId . ':' . $fieldKey] = $alias;
                }
            }
        }

        $resolved = $this->registeredMediaDetails($references);
        $details = [];
        $pdfCounts = array_fill_keys($fileKeys, 0);
        foreach ($parts as $part) {
            $partId = (int) $part['id'];
            foreach ($fileKeys as $fieldKey) {
                $detail = $resolved[$partId . ':' . $fieldKey] ?? null;
                if ($detail === null) {
                    continue;
                }
                $details[$partId][$fieldKey] = $detail;
                if ($detail['mimeType'] === 'application/pdf') {
                    $pdfCounts[$fieldKey]++;
                }
            }
        }

        return ['details' => $details, 'validPdfCounts' => $pdfCounts];
    }

    /**
     * Resolve the two canonical Series media slots for a batch of Series IDs.
     *
     * @param array<int, int|string> $seriesIds
     * @return array<int, array{
     *     media: array{series_product_image: ?string, series_product_spec: ?string},
     *     mediaStatus: array{series_product_image: string, series_product_spec: string},
     *     mediaDetails: array{series_product_image: ?array, series_product_spec: ?array}
     * }>
     */
    public function seriesSummaries(array $seriesIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $seriesIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $summaries = [];
        foreach ($ids as $id) {
            $summaries[$id] = $this->emptySummary();
        }

        if (!$this->isMediaInstalled()) {
            return $summaries;
        }

        $metadata = $this->seriesMediaMetadata($ids);
        $rawValues = [];
        foreach ($metadata as $seriesId => $fields) {
            foreach (['series_product_image', 'series_product_spec'] as $key) {
                $field = $fields[$key] ?? null;
                if ($field !== null && $field['field_type'] === 'file' && $field['value'] !== '') {
                    $rawValues[$seriesId][$key] = $field['value'];
                }
            }
        }

        $aliases = [];
        foreach ($rawValues as $fields) {
            foreach ($fields as $value) {
                $aliases[] = $value;
            }
        }
        $assets = $this->assetsByAliases(array_values(array_unique($aliases)));

        foreach ($ids as $seriesId) {
            foreach (['series_product_image', 'series_product_spec'] as $key) {
                $field = $metadata[$seriesId][$key] ?? null;
                if ($field === null) {
                    continue;
                }
                if ($field['field_type'] !== 'file') {
                    $summaries[$seriesId]['mediaStatus'][$key] = 'invalid';
                    continue;
                }

                $value = $field['value'];
                if ($value === '') {
                    continue;
                }

                $asset = $assets[$value] ?? null;
                if (!$this->isPublicReadyAsset($asset) || !$this->safeStoredFilename($asset['stored_filename'])
                    || $asset['delivery_id'] === null || !$this->isExpectedMediaType($asset, $key)
                    || !$this->isReadableAsset($asset, $key)) {
                    $summaries[$seriesId]['mediaStatus'][$key] = 'invalid';
                    continue;
                }

                $summaries[$seriesId]['media'][$key] = $this->publicUrl($asset);
                $summaries[$seriesId]['mediaStatus'][$key] = 'available';
                $summaries[$seriesId]['mediaDetails'][$key] = $this->mediaDetails($asset, $key, $metadata[$seriesId]);
            }
        }

        return $summaries;
    }

    private function isMediaInstalled(): bool
    {
        return $this->mediaInstalled ??= MediaSchema::isInstalled($this->db);
    }

    /** @param list<string> $ids @return array<string, array<string, mixed>|null> */
    private function assetsByIds(array $ids): array
    {
        $pending = array_values(array_filter($ids, fn (string $id): bool => !array_key_exists($id, $this->assetsById)));
        if ($pending !== []) {
            foreach (array_chunk($pending, 400) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $rows = $this->repository->rows(
                    "SELECT id, stored_filename, original_filename, mime_type, storage_root, relative_path, status, visibility
                     FROM media_asset WHERE id IN ($placeholders)",
                    $chunk
                );
                $found = [];
                foreach ($rows as $row) {
                    $found[strtolower((string) $row['id'])] = $row;
                }
                foreach ($chunk as $id) {
                    $this->assetsById[$id] = $found[strtolower($id)] ?? null;
                }
            }
        }

        $assets = [];
        foreach ($ids as $id) {
            $assets[$id] = $this->assetsById[$id] ?? null;
        }
        return $assets;
    }

    /** @param list<string> $aliases @return array<string, array<string, mixed>|null> */
    private function assetsByAliases(array $aliases): array
    {
        $pending = array_values(array_filter($aliases, fn (string $alias): bool => !array_key_exists($alias, $this->assetsByAlias)));
        if ($pending !== []) {
            foreach (array_chunk($pending, 400) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                $hashes = array_map(static fn (string $alias): string => hash('sha256', $alias), $chunk);
                $rows = $this->repository->rows(
                    "SELECT l.locator, a.id, a.stored_filename, a.original_filename, a.mime_type,
                            a.storage_root, a.relative_path, a.status, a.visibility, delivery.asset_id AS delivery_id
                     FROM media_alias l JOIN media_asset a ON a.id=l.asset_id
                     LEFT JOIN media_alias delivery ON delivery.namespace='legacy'
                       AND delivery.locator_hash=SHA2(CONCAT('__library__/', a.id, '/', a.stored_filename), 256)
                       AND BINARY delivery.locator=BINARY CONCAT('__library__/', a.id, '/', a.stored_filename)
                       AND delivery.asset_id=a.id
                     WHERE l.namespace='legacy' AND l.locator_hash IN ($placeholders)",
                    $hashes
                );
                $found = [];
                foreach ($rows as $row) {
                    $locator = (string) $row['locator'];
                    unset($row['locator']);
                    $found[$locator] = $row;
                }
                foreach ($chunk as $alias) {
                    $this->assetsByAlias[$alias] = $found[$alias] ?? null;
                }
            }
        }

        $assets = [];
        foreach ($aliases as $alias) {
            $assets[$alias] = $this->assetsByAlias[$alias] ?? null;
        }
        return $assets;
    }

    /** @param list<int> $seriesIds @return array<int, array<string, array{field_type: string, value: string}>> */
    private function seriesMediaMetadata(array $seriesIds): array
    {
        $metadata = [];
        foreach (array_chunk($seriesIds, 400) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = $this->repository->rows(
                "SELECT f.series_id, f.field_key, f.field_type, f.default_value, v.value
                 FROM series_custom_field f
                 LEFT JOIN series_custom_field_value v
                   ON v.series_custom_field_id=f.id AND v.series_id=f.series_id
                 WHERE f.series_id IN ($placeholders)
                   AND f.field_scope='series_metadata'
                   AND COALESCE(f.is_public_portal_hidden,0)=0
                   AND f.field_key IN ('series_product_image','series_product_spec', 'series_product_image_alt', 'image_width_px', 'image_height_px')",
                $chunk
            );
            foreach ($rows as $row) {
                $seriesId = (int) $row['series_id'];
                $metadata[$seriesId][(string) $row['field_key']] = [
                    'field_type' => (string) $row['field_type'],
                    'value' => trim((string) ($row['value'] ?? $row['default_value'] ?? '')),
                ];
            }
        }
        return $metadata;
    }

    private function isPublicReadyAsset(?array $asset): bool
    {
        return $asset !== null
            && $asset['visibility'] === 'public_catalog'
            && in_array($asset['status'], ['ready', 'trashed'], true);
    }

    private function isExpectedMediaType(array $asset, string $key): bool
    {
        $mime = strtolower((string) $asset['mime_type']);
        return $key === 'series_product_spec'
            ? $mime === 'application/pdf'
            : in_array($mime, self::IMAGE_MIMES, true);
    }

    private function isReadableAsset(array $asset, string $key): bool
    {
        return $this->readableAssets[$asset['id']][$key] ??= $this->inspectAsset($asset, $key);
    }

    private function inspectAsset(array $asset, string $key): bool
    {
        try {
            $this->storage ??= new MediaStorageRegistry();
            $path = $this->storage->absolute((string) $asset['storage_root'], (string) $asset['relative_path']);
            if (!is_readable($path) || !is_file($path)) {
                return false;
            }
            $this->fileTypes ??= new finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower((string) ($this->fileTypes->file($path) ?: ''));
            return $key === 'series_product_spec'
                ? $mime === 'application/pdf'
                : in_array($mime, self::IMAGE_MIMES, true);
        } catch (Throwable) {
            return false;
        }
    }

    private function validatedMedia(array $asset): ?array
    {
        $assetId = (string) $asset['id'];
        if (array_key_exists($assetId, $this->validatedMedia)) {
            return $this->validatedMedia[$assetId];
        }
        return $this->validatedMedia[$assetId] = $this->inspectValidatedMedia($asset);
    }

    private function inspectValidatedMedia(array $asset): ?array
    {
        try {
            $this->storage ??= new MediaStorageRegistry();
            $path = $this->storage->absolute((string) $asset['storage_root'], (string) $asset['relative_path']);
            if (!is_readable($path) || !is_file($path)) {
                return null;
            }

            $description = MediaStorageRegistry::describe($path);
            $mime = strtolower((string) ($description['mime'] ?? ''));
            if (empty($description['supported']) || $mime !== strtolower((string) $asset['mime_type'])) {
                return null;
            }
            if (!in_array($mime, [...self::IMAGE_MIMES, 'application/pdf', 'model/gltf-binary'], true)) {
                return null;
            }
            $media = ['mimeType' => $mime];
            if (in_array($mime, self::IMAGE_MIMES, true)) {
                $dimensions = @getimagesize($path);
                if ($dimensions === false || (int) $dimensions[0] < 1 || (int) $dimensions[1] < 1) {
                    return null;
                }
                $media['width'] = (int) $dimensions[0];
                $media['height'] = (int) $dimensions[1];
            }
            return $media;
        } catch (Throwable) {
            return null;
        }
    }

    private function safeStoredFilename(string $filename): bool
    {
        return $filename !== ''
            && basename($filename) === $filename
            && !str_contains($filename, '\\')
            && !preg_match('/[\x00-\x1f\x7f]/', $filename);
    }

    private function publicUrl(array $asset): string
    {
        return '/api/media/public.php?path=' . rawurlencode('__library__/' . $asset['id'] . '/' . $asset['stored_filename']);
    }

    /** @param array<string, array{field_type: string, value: string}> $fields */
    private function mediaDetails(array $asset, string $key, array $fields): array
    {
        $details = [];
        $filename = trim((string) ($asset['original_filename'] ?? ''));
        $filename = $this->safeDownloadFilename($filename);
        if ($key === 'series_product_spec' && !str_ends_with(strtolower((string) $filename), '.pdf')) {
            $filename = MediaStorageRegistry::filename(pathinfo($asset['stored_filename'], PATHINFO_FILENAME) . '.pdf');
        }
        $details['filename'] = $filename ?? $asset['stored_filename'];

        if ($key === 'series_product_image') {
            $alt = trim((string) ($fields['series_product_image_alt']['value'] ?? ''));
            if ($alt !== '') {
                $details['alt'] = $alt;
            }
            foreach (['image_width_px' => 'width', 'image_height_px' => 'height'] as $fieldKey => $dimensionKey) {
                $dimension = $fields[$fieldKey]['value'] ?? '';
                if ($dimension !== '' && ctype_digit($dimension) && (int) $dimension > 0) {
                    $details[$dimensionKey] = (int) $dimension;
                }
            }
        }

        return $details;
    }

    private function safeDownloadFilename(string $filename): ?string
    {
        $filename = basename(str_replace('\\', '/', $filename));
        if ($filename === '' || preg_match('/[\x00-\x1f\x7f]/', $filename)) {
            return null;
        }
        return $filename;
    }

    private function emptySummary(): array
    {
        return [
            'media' => ['series_product_image' => null, 'series_product_spec' => null],
            'mediaStatus' => ['series_product_image' => 'missing', 'series_product_spec' => 'missing'],
            'mediaDetails' => ['series_product_image' => null, 'series_product_spec' => null],
        ];
    }
}
