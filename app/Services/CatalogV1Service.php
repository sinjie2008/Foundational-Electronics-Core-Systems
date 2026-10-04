<?php
declare(strict_types=1);

namespace CatalogSuite\Services;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\CatalogPartsQuery;
use CatalogSuite\Repositories\CatalogV1Repository;
use CatalogSuite\Support\Config;
use mysqli;

/** Shared public resources for any catalog branch, field schema, section order or asset role. */
final class CatalogV1Service
{
    private CatalogV1Repository $repository;
    private ?array $nodes = null;
    private array $paths = [];
    private array $children = [];

    public function __construct(mysqli $db)
    {
        $this->repository = new CatalogV1Repository($db);
    }

    public static function normalizePath(string $path): string
    {
        $path = trim($path, '/');
        if ($path === '' || strlen($path) > 4096 || !mb_check_encoding($path, 'UTF-8')) {
            throw new CatalogApiException('INVALID_PATH', 'Invalid catalog path.', 422);
        }
        foreach (explode('/', $path) as $segment) {
            if (mb_strlen($segment, 'UTF-8') > 191 || preg_match('/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/uD', $segment) !== 1) {
                throw new CatalogApiException('INVALID_PATH', 'Invalid catalog path.', 422);
            }
        }
        return mb_strtolower($path, 'UTF-8');
    }

    private function graph(): void
    {
        if ($this->nodes !== null) {
            return;
        }
        $rows = [];
        foreach ($this->repository->publicNodes() as $row) {
            $row['id'] = (int) $row['id'];
            $row['parent_id'] = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            $row['display_order'] = (int) $row['display_order'];
            $rows[$row['id']] = $row;
        }
        $resolved = [];
        $visiting = [];
        $resolve = function (int $id) use (&$resolve, &$resolved, &$visiting, $rows): ?string {
            if (array_key_exists($id, $resolved)) {
                return $resolved[$id];
            }
            if (isset($visiting[$id])) {
                throw new CatalogApiException('INVALID_HIERARCHY', 'Catalog hierarchy contains a cycle.', 503);
            }
            $visiting[$id] = true;
            $node = $rows[$id];
            $parentPath = '';
            if ($node['parent_id'] !== null) {
                if (!isset($rows[$node['parent_id']])) {
                    unset($visiting[$id]);
                    return $resolved[$id] = null;
                }
                if ($rows[$node['parent_id']]['type'] !== 'category') {
                    throw new CatalogApiException('INVALID_HIERARCHY', 'Invalid catalog parent.', 503);
                }
                $parentPath = $resolve($node['parent_id']);
                if ($parentPath === null) {
                    unset($visiting[$id]);
                    return $resolved[$id] = null;
                }
            }
            unset($visiting[$id]);
            return $resolved[$id] = ($parentPath === '' ? '' : $parentPath . '/') . self::normalizePath($node['slug']);
        };
        $nodes = [];
        $paths = [];
        $children = [];
        foreach ($rows as $id => $row) {
            $path = $resolve($id);
            if ($path === null) {
                continue;
            }
            $row['path'] = $path;
            $row['hierarchy_path'] = $path;
            $nodes[$id] = $row;
            $paths[$path] = $id;
            $children[$row['parent_id'] ?? 0][] = $id;
        }
        $canonical = [];
        foreach ($this->repository->aliases() as $alias) {
            $id = (int) $alias['node_id'];
            if (!isset($nodes[$id])) {
                continue;
            }
            $path = self::normalizePath($alias['path']);
            if (isset($paths[$path]) && $paths[$path] !== $id) {
                throw new CatalogApiException('INVALID_ALIAS', 'Catalog path alias conflicts with an existing path.', 503);
            }
            $paths[$path] = $id;
            if ((int) $alias['is_canonical'] === 1) {
                $canonical[$id] = $path;
            }
        }
        $preferred = [];
        $preferredPath = function (int $id) use (&$preferredPath, &$preferred, $canonical, $nodes): string {
            if (isset($preferred[$id])) {
                return $preferred[$id];
            }
            $node = $nodes[$id];
            return $preferred[$id] = $canonical[$id] ?? ($node['parent_id'] === null
                ? $node['slug'] : $preferredPath($node['parent_id']) . '/' . $node['slug']);
        };
        foreach ($nodes as $id => &$node) {
            $path = self::normalizePath($preferredPath($id));
            if (isset($paths[$path]) && $paths[$path] !== $id) {
                throw new CatalogApiException('INVALID_ALIAS', 'Catalog canonical path conflicts with an existing path.', 503);
            }
            $paths[$path] = $id;
            $node['path'] = $path;
        }
        unset($node);
        foreach ($paths as $path => $id) {
            $separator = strrpos($path, '/');
            if ($separator === false || !in_array(substr($path, $separator + 1), ['fields', 'parts', 'facets'], true)) {
                continue;
            }
            $parentId = $paths[substr($path, 0, $separator)] ?? null;
            if ($parentId !== null && $nodes[$parentId]['type'] === 'series') {
                throw new CatalogApiException('INVALID_ALIAS', 'Catalog path alias conflicts with a series API resource.', 503);
            }
        }
        $this->nodes = $nodes;
        $this->paths = $paths;
        $this->children = $children;
    }

    private function node(string $path, ?string $type = null): array
    {
        $path = self::normalizePath($path);
        $this->graph();
        $id = $this->paths[$path] ?? null;
        $node = $id === null ? null : ($this->nodes[$id] ?? null);
        if ($node === null || ($type !== null && $node['type'] !== $type)) {
            throw new CatalogApiException('NOT_FOUND', 'Public catalog resource not found.', 404);
        }
        return $node;
    }

    private function pageUrl(string $path = ''): string
    {
        $base = rtrim((string) Config::get('catalog-v1')['page_base'], '/');
        return $base . ($path === '' ? '' : '/' . self::encodePath($path));
    }

    public static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private function describe(array $node): array
    {
        return [
            'id' => $node['id'], 'parent_id' => $node['parent_id'], 'type' => $node['type'],
            'slug' => $node['slug'], 'path' => $node['path'], 'hierarchy_path' => $node['hierarchy_path'],
            'url' => self::safeUrl($node['target_url']) ?? $this->pageUrl($node['path']),
            'title' => $node['name'], 'subtitle' => $node['subtitle'], 'description' => $node['description'],
            'anchor_id' => $node['anchor_id'] ?: 'node-' . $node['id'], 'display_order' => $node['display_order'],
        ];
    }

    public function resolve(string $path): array
    {
        $node = $this->node($path);
        return ['resource' => $this->describe($node), 'breadcrumb' => $this->breadcrumb($node),
            'api_url' => Config::get('catalog-v1')['api_base'] . '/' . ($node['type'] === 'series' ? 'series' : 'categories') . '/' . self::encodePath($node['path'])];
    }

    private function breadcrumb(?array $node): array
    {
        $rootBlocks = $this->blocks('catalog', 0);
        $links = [];
        foreach ($rootBlocks as $block) {
            $payload = (array) $block['payload'];
            if ($block['type'] === 'navigation' && isset($payload['breadcrumbs']) && is_array($payload['breadcrumbs'])) {
                $links = $payload['breadcrumbs'];
                break;
            }
        }
        $hero = $this->hero($rootBlocks);
        $links[] = ['title' => $hero['title'] ?? null, 'url' => $this->pageUrl(), 'path' => ''];
        $ancestors = [];
        while ($node !== null) {
            array_unshift($ancestors, $this->describe($node));
            $node = $node['parent_id'] === null ? null : ($this->nodes[$node['parent_id']] ?? null);
        }
        return [...$links, ...$ancestors];
    }

    private function descendants(int $id): array
    {
        $result = [];
        $pending = $this->children[$id] ?? [];
        while ($pending !== []) {
            $next = array_shift($pending);
            $result[] = $next;
            array_push($pending, ...($this->children[$next] ?? []));
        }
        return $result;
    }

    private function nodeTree(int $id, bool $withContent): array
    {
        $node = $this->nodes[$id];
        $result = $this->describe($node);
        if ($withContent) {
            $result['blocks'] = $this->blocks($node['type'], $id);
            $result['assets'] = $this->assets($node['type'], $id);
        }
        $result['children'] = array_map(fn (int $child): array => $this->nodeTree($child, $withContent), $this->children[$id] ?? []);
        return $result;
    }

    public function tree(): array
    {
        $this->graph();
        return array_map(fn (int $id): array => $this->nodeTree($id, false), $this->children[0] ?? []);
    }

    public function root(): array
    {
        $this->graph();
        $blocks = $this->blocks('catalog', 0);
        return ['breadcrumb' => $this->breadcrumb(null), 'hero' => $this->hero($blocks), 'blocks' => $blocks,
            'navigation' => $this->blockNavigation($blocks), 'assets' => $this->assets('catalog', 0),
            'groups' => array_map(fn (int $id): array => $this->nodeTree($id, true), $this->children[0] ?? []),
            'collections' => $this->collections()];
    }

    public function category(string $path): array
    {
        $node = $this->node($path, 'category');
        $blocks = $this->blocks('category', $node['id']);
        $sections = array_map(fn (int $id): array => $this->nodeTree($id, true), $this->children[$node['id']] ?? []);
        return ['resource' => $this->describe($node), 'breadcrumb' => $this->breadcrumb($node), 'hero' => $this->hero($blocks),
            'blocks' => $blocks, 'assets' => $this->assets('category', $node['id']), 'sections' => $sections,
            'navigation' => array_map(static fn (array $section): array => array_intersect_key($section, array_flip(['id', 'title', 'anchor_id', 'url', 'display_order'])), $sections),
            'section_navigation' => $this->blockNavigation($blocks)];
    }

    public function series(string $path): array
    {
        $node = $this->node($path, 'series');
        $blocks = $this->blocks('series', $node['id']);
        $assets = $this->assets('series', $node['id']);
        return ['resource' => $this->describe($node), 'breadcrumb' => $this->breadcrumb($node),
            'parent_context' => $node['parent_id'] === null ? null : $this->describe($this->nodes[$node['parent_id']]),
            'hero' => $this->hero($blocks), 'blocks' => $blocks, 'navigation' => $this->blockNavigation($blocks),
            'metadata' => $this->metadata($node['id']), 'assets' => $assets,
            'documents' => array_values(array_filter($assets, static fn (array $asset): bool => $asset['is_download'] && $asset['available'] !== false)),
            'schema' => $this->fields($path),
            'endpoints' => array_combine(['fields', 'parts', 'facets'], array_map(fn (string $endpoint): string => Config::get('catalog-v1')['api_base'] . '/series/' . self::encodePath($node['path']) . '/' . $endpoint, ['fields', 'parts', 'facets']))];
    }

    private function hero(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if ($block['type'] === 'hero') {
                return $block;
            }
        }
        return null;
    }

    private function blockNavigation(array $blocks): array
    {
        return array_values(array_map(static fn (array $block): array => array_intersect_key($block, array_flip(['key', 'title', 'anchor_id', 'display_order'])),
            array_filter($blocks, static fn (array $block): bool => $block['is_navigation'])));
    }

    private function decode(?string $json): array
    {
        $value = $json === null ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }

    private function fieldSchema(array $field): array
    {
        return ['id' => (int) $field['id'], 'field_key' => $field['field_key'], 'label' => $field['label'], 'type' => $field['field_type'],
            'scope' => $field['field_scope'], 'unit' => $field['unit'], 'display_order' => (int) $field['sort_order'],
            'is_required' => (bool) $field['is_required'], 'is_filterable' => (bool) $field['is_filterable'] && $field['field_type'] !== 'file',
            'is_sortable' => (bool) $field['is_sortable'] && $field['field_type'] !== 'file', 'is_table_column' => (bool) $field['is_table_column'],
            'is_searchable' => (bool) $field['is_searchable'] && $field['field_type'] !== 'file', 'filter_type' => $field['filter_type'],
            'group_key' => $field['group_key'], 'group_label' => $field['group_label'],
            'config' => (object) $this->safePayload($this->decode($field['config_json']), 'catalog', 0, false),
            'default_value' => $field['field_type'] === 'file' ? null : $field['default_value']];
    }

    public function fields(string $path): array
    {
        $node = $this->node($path, 'series');
        $fields = array_map(fn (array $field): array => $this->fieldSchema($field), $this->repository->fields($node['id'], 'product_attribute'));
        return ['fields' => $fields, 'columns' => array_values(array_filter($fields, static fn (array $field): bool => $field['is_table_column'])),
            'metadata_fields' => array_map(fn (array $field): array => $this->fieldSchema($field), $this->repository->fields($node['id'], 'series_metadata')),
            'row_identity' => ['key' => 'id', 'part_number_key' => 'sku']];
    }

    private function fileValue(?string $value, string $ownerType, int $ownerId): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach ($this->repository->assets($ownerType, $ownerId) as $asset) {
            if ($asset['file_path'] === $value) {
                return $this->assetResource($asset);
            }
        }
        return null;
    }

    private function metadata(int $seriesId): array
    {
        $values = [];
        foreach ($this->repository->metadata($seriesId) as $row) {
            $values[$row['field_key']] = $row['field_type'] === 'file' ? $this->fileValue($row['value'], 'series', $seriesId) : $row['value'];
        }
        return (array) $values;
    }

    public function parts(string $path, array $query): array
    {
        $node = $this->node($path, 'series');
        $definitions = $this->repository->fields($node['id'], 'product_attribute');
        $criteria = CatalogPartsQuery::parse($query, $definitions);
        $page = $this->repository->parts($node['id'], $criteria);
        $values = [];
        foreach ($this->repository->partValues($node['id'], array_column($page['rows'], 'id')) as $row) {
            $value = $row['value'];
            if ($row['field_type'] === 'file') {
                $value = $this->fileValue($value, 'product', (int) $row['product_id']) ?? $this->fileValue($value, 'series', $node['id']);
            }
            $values[(int) $row['product_id']][$row['field_key']] = $value;
        }
        foreach ($page['rows'] as &$row) {
            $row['id'] = (int) $row['id'];
            $row['values'] = (object) ($values[$row['id']] ?? []);
            $row['assets'] = $this->assets('product', $row['id']);
            $row['blocks'] = $this->blocks('product', $row['id']);
            $row['documents'] = array_values(array_filter($row['assets'], static fn (array $asset): bool => $asset['is_download'] && $asset['available'] !== false));
        }
        unset($row);
        $base = Config::get('catalog-v1')['api_base'] . '/series/' . self::encodePath($node['path']) . '/parts';
        return ['schema' => $this->fields($path), 'rows' => $page['rows'], 'pagination' => $this->pagination($criteria['page'], $criteria['per_page'], $page['total'], $base, $query)];
    }

    private function pagination(int $page, int $perPage, int $total, string $base, array $query): array
    {
        $lastPage = max(1, (int) ceil($total / $perPage));
        $link = static fn (int $target): string => $base . '?' . http_build_query([...$query, 'page' => $target, 'per_page' => $perPage], '', '&', PHP_QUERY_RFC3986);
        return ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage,
            'from' => $total === 0 || $page > $lastPage ? null : ($page - 1) * $perPage + 1,
            'to' => $total === 0 || $page > $lastPage ? null : min($page * $perPage, $total),
            'links' => ['first' => $link(1), 'last' => $link($lastPage), 'previous' => $page > 1 ? $link($page - 1) : null, 'next' => $page < $lastPage ? $link($page + 1) : null]];
    }

    public function facets(string $path, array $query): array
    {
        $node = $this->node($path, 'series');
        CatalogPartsQuery::only($query, ['search', 'filter']);
        $fields = $this->repository->fields($node['id'], 'product_attribute');
        $criteria = CatalogPartsQuery::parse($query, $fields);
        $facets = $this->repository->facets($node['id'], $criteria, $fields);
        $schemas = [];
        foreach ($fields as $field) {
            $schemas[$field['field_key']] = $this->fieldSchema($field);
        }
        foreach ($facets as &$facet) {
            $facet['field'] = $schemas[$facet['field_key']];
        }
        unset($facet);
        return $facets;
    }

    public function search(array $query): array
    {
        CatalogPartsQuery::only($query, ['q', 'page', 'per_page']);
        $term = CatalogPartsQuery::text($query['q'] ?? '', 'q');
        $pagination = CatalogPartsQuery::pagination($query);
        $this->graph();
        $matches = $this->repository->search(array_keys($this->nodes), $term, $pagination['page'], $pagination['per_page']);
        foreach ($matches['rows'] as &$match) {
            $match['id'] = (int) $match['id'];
            $node = $this->nodes[(int) $match['node_id']];
            $match['path'] = $node['path'];
            $match['url'] = $this->describe($node)['url'];
            unset($match['node_id']);
        }
        unset($match);
        return ['rows' => $matches['rows'], 'pagination' => $this->pagination($pagination['page'], $pagination['per_page'], $matches['total'], Config::get('catalog-v1')['api_base'] . '/search', $query)];
    }

    private function ownerPublic(string $type, int $id): bool
    {
        $this->graph();
        if ($type === 'catalog') {
            return $id === 0;
        }
        if ($type === 'product') {
            $product = $this->repository->product($id);
            return $product !== null && isset($this->nodes[(int) $product['series_id']]) && $this->nodes[(int) $product['series_id']]['type'] === 'series';
        }
        return isset($this->nodes[$id]) && $this->nodes[$id]['type'] === $type;
    }

    public static function safeUrl(?string $url): ?string
    {
        if ($url === null || $url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null && parse_url($url, PHP_URL_PASS) === null) {
            return $url;
        }
        return null;
    }

    private function localFile(array $asset): ?string
    {
        $app = Config::get('app');
        $root = match ($asset['storage_disk']) {
            'media' => $app['storage']['media'], 'typst' => $app['storage']['typst_pdfs'], 'public' => $app['public_root'], default => null,
        };
        $relative = str_replace('\\', '/', $asset['file_path']);
        if ($root === null || $relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")
            || preg_match('/^[A-Za-z]:/', $relative) === 1 || in_array('..', explode('/', $relative), true)) {
            return null;
        }
        if (preg_match('/(?:^|\/)[.]/', $relative) === 1 || in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), ['php', 'phtml', 'phar', 'ini', 'env', 'htaccess'], true)) {
            return null;
        }
        $root = realpath($root);
        $file = $root === false ? false : realpath($root . '/' . $relative);
        return $root !== false && $file !== false && is_file($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR) ? $file : null;
    }

    private function assetResource(array $asset): ?array
    {
        if (!$this->ownerPublic($asset['owner_type'], (int) $asset['owner_id'])) {
            return null;
        }
        if ($asset['storage_disk'] === 'external') {
            $url = self::safeUrl($asset['file_path']);
            if ($url === null || !str_starts_with($url, 'https://')) {
                return null;
            }
            $available = null;
        } else {
            $url = Config::get('catalog-v1')['api_base'] . '/assets/' . (int) $asset['id'];
            $available = $this->localFile($asset) !== null;
        }
        return ['id' => (int) $asset['id'], 'key' => $asset['asset_key'], 'role' => $asset['role'], 'title' => $asset['title'],
            'alt_text' => $asset['alt_text'], 'mime_type' => $asset['mime_type'], 'url' => $url,
            'is_download' => (bool) $asset['is_download'], 'available' => $available, 'display_order' => (int) $asset['display_order'],
            'metadata' => (object) $this->safePayload($this->decode($asset['metadata_json']), $asset['owner_type'], (int) $asset['owner_id'], false)];
    }

    public function publicAsset(int $id): ?array
    {
        $asset = $this->repository->asset($id);
        return $asset === null ? null : $this->assetResource($asset);
    }

    public function assetDownload(int $id): array
    {
        $asset = $this->repository->asset($id);
        if ($asset === null || !$this->ownerPublic($asset['owner_type'], (int) $asset['owner_id'])) {
            throw new CatalogApiException('NOT_FOUND', 'Public asset not found.', 404);
        }
        if ($asset['storage_disk'] === 'external') {
            $resource = $this->assetResource($asset);
            if ($resource === null) {
                throw new CatalogApiException('NOT_FOUND', 'Public asset not found.', 404);
            }
            return ['redirect' => $resource['url']];
        }
        $file = $this->localFile($asset);
        if ($file === null) {
            throw new CatalogApiException('NOT_FOUND', 'Public asset file not found.', 404);
        }
        return ['file' => $file, 'filename' => basename($file), 'mime_type' => $asset['mime_type'], 'is_download' => (bool) $asset['is_download']];
    }

    private function assets(string $ownerType, int $ownerId): array
    {
        return array_values(array_filter(array_map(fn (array $asset): ?array => $this->assetResource($asset), $this->repository->assets($ownerType, $ownerId))));
    }

    /** References are resolved rather than reflecting raw private IDs/paths or field configuration. */
    private function safePayload(array $payload, string $ownerType, int $ownerId, bool $references = true): array
    {
        if (array_key_exists('asset_id', $payload)) {
            return $references && is_int($payload['asset_id']) ? ($this->publicAsset($payload['asset_id']) ?? []) : [];
        }
        if (array_key_exists('field_key', $payload)) {
            if (!$references || !is_string($payload['field_key'])) {
                return [];
            }
            $scope = $payload['scope'] ?? 'series_metadata';
            $seriesId = $ownerType === 'series' ? $ownerId : ($ownerType === 'product' ? (int) ($this->repository->product($ownerId)['series_id'] ?? 0) : 0);
            if ($seriesId > 0 && in_array($scope, ['series_metadata', 'product_attribute'], true)) {
                foreach ($this->repository->fields($seriesId, $scope) as $field) {
                    if ($field['field_key'] !== $payload['field_key']) {
                        continue;
                    }
                    $fieldValue = null;
                    if ($scope === 'series_metadata') {
                        $fieldValue = $this->metadata($seriesId)[$field['field_key']] ?? null;
                    } elseif ($ownerType === 'product') {
                        foreach ($this->repository->partValues($seriesId, [$ownerId]) as $row) {
                            if ($row['field_key'] === $field['field_key']) {
                                $fieldValue = $row['field_type'] === 'file'
                                    ? ($this->fileValue($row['value'], 'product', $ownerId) ?? $this->fileValue($row['value'], 'series', $seriesId))
                                    : $row['value'];
                                break;
                            }
                        }
                    }
                    return ['field' => $this->fieldSchema($field), 'value' => $fieldValue];
                }
            }
            return [];
        }
        $result = [];
        foreach ($payload as $key => $value) {
            if (in_array($key, ['file_path', 'storage_disk', 'owner_id', 'owner_type'], true)) {
                continue;
            }
            if (is_array($value)) {
                $isReference = array_key_exists('asset_id', $value) || array_key_exists('field_key', $value);
                $value = $this->safePayload($value, $ownerType, $ownerId, $references);
                if ($isReference && $value === []) {
                    continue;
                }
            } elseif (in_array($key, ['url', 'href', 'target_url'], true)) {
                $value = is_string($value) ? self::safeUrl($value) : null;
                if ($value === null) {
                    continue;
                }
            }
            $result[$key] = $value;
        }
        return array_is_list($payload) ? array_values($result) : $result;
    }

    private function blocks(string $ownerType, int $ownerId): array
    {
        $result = [];
        foreach ($this->repository->blocks($ownerType, $ownerId) as $block) {
            $payload = $this->decode($block['payload_json']);
            if ($block['block_type'] === 'series_table') {
                $payload = $ownerType === 'category' ? $this->summaryTable($ownerId, $payload) : [];
            } else {
                $payload = $this->safePayload($payload, $ownerType, $ownerId);
            }
            $result[] = ['id' => (int) $block['id'], 'key' => $block['block_key'], 'type' => $block['block_type'], 'title' => $block['title'],
                'anchor_id' => $block['anchor_id'] ?: 'block-' . (int) $block['id'], 'display_order' => (int) $block['display_order'],
                'is_navigation' => (bool) $block['is_navigation'], 'payload' => (object) $payload];
        }
        return $result;
    }

    private function summaryTable(int $categoryId, array $config): array
    {
        $ids = ($config['recursive'] ?? true) ? $this->descendants($categoryId) : ($this->children[$categoryId] ?? []);
        $ids = array_values(array_filter($ids, fn (int $id): bool => $this->nodes[$id]['type'] === 'series'));
        $definitions = [];
        $values = [];
        foreach ($ids as $id) {
            foreach ($this->repository->fields($id, 'series_metadata') as $field) {
                $definitions[$id][$field['field_key']] = $field;
            }
            $values[$id] = $this->metadata($id);
        }
        $columns = [];
        foreach (is_array($config['columns'] ?? null) ? $config['columns'] : [] as $column) {
            if (!is_array($column) || !isset($column['key'], $column['source']) || !is_string($column['key'])) {
                continue;
            }
            $source = $column['source'];
            if ($source === 'metadata' && is_string($column['field_key'] ?? null)) {
                $definition = null;
                foreach ($ids as $id) {
                    $definition ??= $definitions[$id][$column['field_key'] ?? ''] ?? null;
                }
                if ($definition === null) {
                    continue;
                }
                $column = ['key' => $column['key'], 'source' => $source, 'field_key' => $definition['field_key'],
                    'label' => $column['label'] ?? $definition['label'], 'type' => $definition['field_type'], 'unit' => $definition['unit']];
            } elseif ($source === 'identity' && in_array($column['property'] ?? null, ['title', 'subtitle', 'description', 'url'], true)) {
                $column = array_intersect_key($column, array_flip(['key', 'source', 'property', 'label']));
            } elseif ($source === 'asset' && isset($column['role']) && is_string($column['role'])) {
                $column = array_intersect_key($column, array_flip(['key', 'source', 'role', 'label']));
            } else {
                continue;
            }
            $columns[] = $column;
        }
        $rows = [];
        foreach ($ids as $id) {
            $resource = $this->describe($this->nodes[$id]);
            $assets = $this->assets('series', $id);
            $cells = [];
            foreach ($columns as $column) {
                $cells[$column['key']] = match ($column['source']) {
                    'metadata' => $values[$id][$column['field_key']] ?? null,
                    'identity' => $resource[$column['property']] ?? null,
                    'asset' => array_values(array_filter($assets, static fn (array $asset): bool => $asset['role'] === $column['role'])),
                };
            }
            $rows[] = ['resource' => $resource, 'values' => (object) $cells, 'assets' => $assets,
                'documents' => array_values(array_filter($assets, static fn (array $asset): bool => $asset['is_download'] && $asset['available'] !== false))];
        }
        return ['columns' => $columns, 'rows' => $rows];
    }

    public function collections(?string $key = null): array
    {
        $this->graph();
        $result = [];
        foreach ($this->repository->collections() as $collection) {
            if ($key !== null && $collection['collection_key'] !== $key) {
                continue;
            }
            $items = [];
            foreach ($this->repository->collectionItems((int) $collection['id']) as $item) {
                $node = $this->nodes[(int) $item['node_id']] ?? null;
                if ($node === null) {
                    continue;
                }
                $items[] = ['id' => (int) $item['id'], 'key' => $item['item_key'], 'resource' => $this->describe($node), 'title' => $item['title'] ?? $node['name'],
                    'subtitle' => $item['subtitle'] ?? $node['subtitle'], 'image' => $item['asset_id'] === null ? null : $this->publicAsset((int) $item['asset_id']),
                    'url' => $this->describe($node)['url'], 'display_order' => (int) $item['display_order']];
            }
            $result[] = ['key' => $collection['collection_key'], 'title' => $collection['title'], 'description' => $collection['description'],
                'target_url' => self::safeUrl($collection['target_url']), 'display_order' => (int) $collection['display_order'],
                'config' => (object) $this->safePayload($this->decode($collection['config_json']), 'catalog', 0), 'items' => $items];
        }
        if ($key !== null && $result === []) {
            throw new CatalogApiException('NOT_FOUND', 'Public collection not found.', 404);
        }
        return $key === null ? $result : $result[0];
    }
}
