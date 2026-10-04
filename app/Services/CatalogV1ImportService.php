<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\CatalogPartsQuery;
use CatalogSuite\Repositories\CatalogV1ImportRepository;
use mysqli;
use Throwable;

/** Imports only supplied records. Merge semantics retain omitted records and never delete files. */
final class CatalogV1ImportService
{
    private CatalogV1ImportRepository $repository;
    private array $counts = [];

    public function __construct(private mysqli $db)
    {
        $this->repository = new CatalogV1ImportRepository($db);
    }

    public function import(array $manifest, bool $dryRun = false): array
    {
        $this->keys($manifest, ['version', 'catalog', 'nodes', 'aliases', 'collections']);
        if (($manifest['version'] ?? null) !== 'public-product-api-v1') {
            $this->invalid('Unsupported manifest version.');
        }
        $this->counts = array_fill_keys(['nodes', 'fields', 'parts', 'blocks', 'assets', 'aliases', 'collections', 'collection_items'], 0);
        $this->db->begin_transaction();
        try {
            $nodes = $this->records($manifest['nodes'] ?? []);
            usort($nodes, static fn (array $a, array $b): int => substr_count((string) ($a['path'] ?? ''), '/') <=> substr_count((string) ($b['path'] ?? ''), '/'));
            foreach ($nodes as $node) {
                $this->importNode($node);
            }
            if (isset($manifest['catalog'])) {
                if (!is_array($manifest['catalog'])) {
                    $this->invalid('Catalog configuration must be an object.');
                }
                $this->keys($manifest['catalog'], ['assets', 'blocks']);
                $this->presentation('catalog', 0, $manifest['catalog']);
            }
            foreach ($this->records($manifest['aliases'] ?? []) as $alias) {
                $this->keys($alias, ['path', 'node_path', 'is_canonical']);
                $path = CatalogV1Service::normalizePath($this->string($alias['path'] ?? null, 700));
                $node = $this->findPath($this->string($alias['node_path'] ?? null, 4096));
                $this->repository->upsert('catalog_path_alias', ['path' => $path], ['node_id' => (int) $node['id'], 'is_canonical' => $this->flag($alias['is_canonical'] ?? false)]);
                $this->counts['aliases']++;
            }
            foreach ($this->records($manifest['collections'] ?? []) as $collection) {
                $this->keys($collection, ['collection_key', 'title', 'description', 'target_url', 'config', 'display_order', 'is_public', 'items']);
                $key = $this->string($collection['collection_key'] ?? null, 191);
                $values = $this->scalars($collection, ['title', 'description', 'target_url', 'display_order', 'is_public']);
                if (isset($values['target_url']) && CatalogV1Service::safeUrl($values['target_url']) === null) {
                    $this->invalid('Invalid collection target URL.');
                }
                if (array_key_exists('config', $collection)) {
                    $values['config_json'] = $this->json($collection['config']);
                }
                $row = $this->repository->upsert('catalog_collection', ['collection_key' => $key], $values);
                foreach ($this->records($collection['items'] ?? []) as $item) {
                    $this->keys($item, ['item_key', 'node_path', 'asset_key', 'title', 'subtitle', 'display_order', 'is_public']);
                    $node = $this->findPath($this->string($item['node_path'] ?? null, 4096));
                    $values = $this->scalars($item, ['title', 'subtitle', 'display_order', 'is_public']);
                    $values['node_id'] = (int) $node['id'];
                    if (array_key_exists('asset_key', $item)) {
                        $values['asset_id'] = $item['asset_key'] === null ? null : $this->assetId($node['type'], (int) $node['id'], $this->string($item['asset_key'], 191));
                    }
                    $this->repository->upsert('catalog_collection_item', ['collection_id' => (int) $row['id'], 'item_key' => $this->string($item['item_key'] ?? null, 191)], $values);
                    $this->counts['collection_items']++;
                }
                $this->counts['collections']++;
            }
            // Alias collisions and malformed public ancestry must fail before committing.
            (new CatalogV1Service($this->db))->tree();
            if ($dryRun) {
                $this->db->rollback();
            } else {
                $this->db->commit();
            }
            return ['dry_run' => $dryRun, 'records_processed' => $this->counts];
        } catch (Throwable $error) {
            $this->db->rollback();
            throw $error;
        }
    }

    private function findPath(string $path): array
    {
        $parentId = null;
        $node = null;
        foreach (explode('/', CatalogV1Service::normalizePath($path)) as $slug) {
            $node = $this->repository->find('category', ['parent_id' => $parentId, 'slug' => $slug]);
            if ($node === null) {
                $this->invalid('Referenced hierarchy path does not exist.');
            }
            $parentId = (int) $node['id'];
        }
        return $node;
    }

    private function importNode(array $record): void
    {
        $this->keys($record, ['path', 'name', 'type', 'description', 'subtitle', 'anchor_id', 'target_url', 'display_order', 'is_published', 'fields', 'metadata', 'parts', 'assets', 'blocks']);
        $segments = explode('/', CatalogV1Service::normalizePath($this->string($record['path'] ?? null, 4096)));
        $slug = array_pop($segments);
        $parent = $segments === [] ? null : $this->findPath(implode('/', $segments));
        if ($parent !== null && $parent['type'] !== 'category') {
            $this->invalid('A hierarchy parent must be a category.');
        }
        $identity = ['parent_id' => $parent === null ? null : (int) $parent['id'], 'slug' => $slug];
        $existing = $this->repository->find('category', $identity);
        $type = $record['type'] ?? $existing['type'] ?? null;
        if (!in_array($type, ['category', 'series'], true) || ($type === 'series' && $parent === null)
            || ($existing !== null && $type !== $existing['type'])) {
            $this->invalid('Invalid or incompatible hierarchy type.');
        }
        if ($existing === null) {
            $this->string($record['name'] ?? null, 255);
        }
        $values = $this->scalars($record, ['name', 'description', 'subtitle', 'anchor_id', 'target_url', 'display_order', 'is_published']);
        if (isset($values['target_url']) && CatalogV1Service::safeUrl($values['target_url']) === null) {
            $this->invalid('Invalid node target URL.');
        }
        $values['type'] = $type;
        if ($existing === null && !isset($values['is_published'])) {
            $values['is_published'] = 0;
        }
        $node = $this->repository->upsert('category', $identity, $values);
        $id = (int) $node['id'];
        if ($type !== 'series' && (isset($record['fields']) || isset($record['metadata']) || isset($record['parts']))) {
            $this->invalid('Fields, metadata and parts belong to series records.');
        }
        foreach ($this->records($record['fields'] ?? []) as $field) {
            $this->keys($field, ['field_key', 'label', 'field_type', 'field_scope', 'default_value', 'sort_order', 'is_required', 'is_public_portal_hidden', 'is_backend_portal_hidden',
                'unit', 'is_filterable', 'is_sortable', 'is_table_column', 'is_searchable', 'filter_type', 'config', 'group_key', 'group_label']);
            $fieldKey = $this->string($field['field_key'] ?? null, 64);
            $scope = $field['field_scope'] ?? 'product_attribute';
            if (!in_array($scope, ['series_metadata', 'product_attribute'], true)) {
                $this->invalid('Invalid field scope.');
            }
            $identity = ['series_id' => $id, 'field_key' => $fieldKey, 'field_scope' => $scope];
            $current = $this->repository->find('series_custom_field', $identity);
            $fieldType = $field['field_type'] ?? $current['field_type'] ?? 'text';
            if (!in_array($fieldType, ['text', 'number', 'file'], true) || !in_array($field['filter_type'] ?? $current['filter_type'] ?? 'select', ['select', 'range'], true)) {
                $this->invalid('Invalid field or filter type.');
            }
            if (($field['filter_type'] ?? $current['filter_type'] ?? 'select') === 'range' && $fieldType !== 'number') {
                $this->invalid('Range filters require a numeric field definition.');
            }
            if ($current === null) {
                $this->string($field['label'] ?? null, 255);
            }
            $values = $this->scalars($field, ['label', 'field_type', 'default_value', 'sort_order', 'is_required', 'is_public_portal_hidden', 'is_backend_portal_hidden',
                'unit', 'is_filterable', 'is_sortable', 'is_table_column', 'is_searchable', 'filter_type', 'group_key', 'group_label']);
            if ($current === null && !isset($values['is_public_portal_hidden'])) {
                $values['is_public_portal_hidden'] = 1;
            }
            if (array_key_exists('config', $field)) {
                $values['config_json'] = $this->json($field['config']);
            }
            $this->repository->upsert('series_custom_field', $identity, $values);
            $this->counts['fields']++;
        }
        $this->values($id, 'series_metadata', $record['metadata'] ?? [], null);
        foreach ($this->records($record['parts'] ?? []) as $part) {
            $this->keys($part, ['sku', 'name', 'description', 'is_published', 'values', 'assets', 'blocks']);
            $identity = ['series_id' => $id, 'sku' => $this->string($part['sku'] ?? null, 128)];
            $current = $this->repository->find('product', $identity);
            $values = $this->scalars($part, ['name', 'description', 'is_published']);
            if ($current === null) {
                $values['name'] ??= $identity['sku'];
                $values['is_published'] ??= 0;
            }
            $row = $this->repository->upsert('product', $identity, $values);
            $this->values($id, 'product_attribute', $part['values'] ?? [], (int) $row['id']);
            $this->presentation('product', (int) $row['id'], $part);
            $this->counts['parts']++;
        }
        $this->presentation($type, $id, $record);
        $this->counts['nodes']++;
    }

    private function values(int $seriesId, string $scope, mixed $values, ?int $productId): void
    {
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            $this->invalid('Field values must be an object keyed by actual field keys.');
        }
        foreach ($values as $key => $value) {
            $field = $this->repository->find('series_custom_field', ['series_id' => $seriesId, 'field_scope' => $scope, 'field_key' => $key]);
            if ($field === null || (!is_scalar($value) && $value !== null)) {
                $this->invalid('Unknown field key or non-scalar field value.');
            }
            $identity = ['series_custom_field_id' => (int) $field['id'], $productId === null ? 'series_id' : 'product_id' => $productId ?? $seriesId];
            $this->repository->upsert($productId === null ? 'series_custom_field_value' : 'product_custom_field_value', $identity, ['value' => $value === null ? null : (string) $value]);
        }
    }

    private function presentation(string $type, int $id, array $record): void
    {
        foreach ($this->records($record['assets'] ?? []) as $asset) {
            $this->keys($asset, ['asset_key', 'role', 'storage_disk', 'file_path', 'mime_type', 'title', 'alt_text', 'metadata', 'display_order', 'is_download', 'is_public']);
            $key = $this->string($asset['asset_key'] ?? null, 191);
            $identity = ['owner_type' => $type, 'owner_id' => $id, 'asset_key' => $key];
            $current = $this->repository->find('catalog_asset', $identity);
            $values = $this->scalars($asset, ['role', 'storage_disk', 'file_path', 'mime_type', 'title', 'alt_text', 'display_order', 'is_download', 'is_public']);
            if ($current === null) {
                foreach (['role', 'file_path', 'mime_type'] as $required) {
                    $this->string($values[$required] ?? null, $required === 'file_path' ? 1024 : 191);
                }
            }
            $disk = $values['storage_disk'] ?? $current['storage_disk'] ?? 'media';
            $path = $values['file_path'] ?? $current['file_path'] ?? '';
            if (!in_array($disk, ['media', 'typst', 'public', 'external'], true)) {
                $this->invalid('Invalid asset storage disk.');
            }
            if ($disk === 'external') {
                if (CatalogV1Service::safeUrl($path) === null || !str_starts_with($path, 'https://')) {
                    $this->invalid('External assets require an HTTPS URL.');
                }
            } elseif (str_starts_with(str_replace('\\', '/', $path), '/') || preg_match('/^[A-Za-z]:|(?:^|[\\\\\/])\.\.(?:[\\\\\/]|$)/', $path) === 1
                || str_contains($path, "\0") || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'phtml', 'phar', 'ini', 'env', 'htaccess'], true)) {
                $this->invalid('Local assets require a safe relative file path.');
            }
            if (array_key_exists('metadata', $asset)) {
                $values['metadata_json'] = $this->json($asset['metadata']);
            }
            $this->repository->upsert('catalog_asset', $identity, $values);
            $this->counts['assets']++;
        }
        foreach ($this->records($record['blocks'] ?? []) as $block) {
            $this->keys($block, ['block_key', 'block_type', 'title', 'anchor_id', 'payload', 'display_order', 'is_navigation', 'is_public']);
            $identity = ['owner_type' => $type, 'owner_id' => $id, 'block_key' => $this->string($block['block_key'] ?? null, 191)];
            $current = $this->repository->find('catalog_content_block', $identity);
            $values = $this->scalars($block, ['block_type', 'title', 'anchor_id', 'display_order', 'is_navigation', 'is_public']);
            if ($current === null) {
                $this->string($values['block_type'] ?? null, 64);
            }
            if (array_key_exists('payload', $block)) {
                $values['payload_json'] = $this->json($this->references($block['payload'], $type, $id));
            }
            $this->repository->upsert('catalog_content_block', $identity, $values);
            $this->counts['blocks']++;
        }
    }

    private function assetId(string $type, int $id, string $key): int
    {
        $asset = $this->repository->find('catalog_asset', ['owner_type' => $type, 'owner_id' => $id, 'asset_key' => $key]);
        if ($asset === null) {
            $this->invalid('Referenced asset key does not exist for this owner.');
        }
        return (int) $asset['id'];
    }

    private function references(mixed $value, string $type, int $id): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (isset($value['asset_key'])) {
            return ['asset_id' => $this->assetId($type, $id, $this->string($value['asset_key'], 191))];
        }
        return array_map(fn (mixed $item): mixed => $this->references($item, $type, $id), $value);
    }

    private function keys(array $record, array $allowed): void
    {
        foreach (array_keys($record) as $key) {
            if (!in_array($key, $allowed, true)) {
                $this->invalid('Unknown manifest property.');
            }
        }
    }

    private function records(mixed $records): array
    {
        if (!is_array($records) || !array_is_list($records)) {
            $this->invalid('Record collections must be arrays.');
        }
        foreach ($records as $record) {
            if (!is_array($record) || array_is_list($record)) {
                $this->invalid('Each record must be an object.');
            }
        }
        return $records;
    }

    private function scalars(array $record, array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $record)) {
                continue;
            }
            $value = $record[$key];
            if (str_starts_with($key, 'is_')) {
                $value = $this->flag($value);
            } elseif (in_array($key, ['display_order', 'sort_order'], true)) {
                if (!is_int($value) || $value < -2147483648 || $value > 2147483647) {
                    $this->invalid('Display order must be an integer.');
                }
            } elseif ($value !== null) {
                if (!is_scalar($value)) {
                    $this->invalid('Record attributes must be scalar values.');
                }
                $value = (string) $value;
                if (!mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                    $this->invalid('Invalid text attribute.');
                }
            }
            $result[$key] = $value;
        }
        return $result;
    }

    private function flag(mixed $value): int
    {
        if (!in_array($value, [true, false, 0, 1], true)) {
            $this->invalid('Visibility flags must be boolean or 0/1.');
        }
        return $value ? 1 : 0;
    }

    private function string(mixed $value, int $maximum): string
    {
        if (!is_string($value) || trim($value) === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum || str_contains($value, "\0")) {
            $this->invalid('Required string is missing or invalid.');
        }
        return $value;
    }

    private function json(mixed $value): string
    {
        if (!is_array($value)) {
            $this->invalid('Structured presentation data must be an object or array.');
        }
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function invalid(string $message): never
    {
        throw new CatalogApiException('IMPORT_VALIDATION_ERROR', $message, 422);
    }
}
