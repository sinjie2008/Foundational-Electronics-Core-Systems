<?php
declare(strict_types=1);

use CatalogSuite\Controllers\PublicCatalogV1Controller;
use CatalogSuite\Http\Transport;
use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Support\Config;

return static function (TestSuite $t, mysqli $db): void {
    $tag = bin2hex(random_bytes(4));
    $insert = static function (string $sql, array $parameters = []) use ($db): int {
        $statement = $db->prepare($sql);
        if ($parameters !== []) {
            $statement->execute($parameters);
        } else {
            $statement->execute();
        }
        $id = $statement->insert_id;
        $statement->close();
        return (int) $id;
    };
    $node = static function (
        ?int $parentId,
        string $name,
        string $type = 'category',
        int $published = 1,
        ?string $targetUrl = null
    ) use ($insert, $tag): array {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name)) . '-' . $tag;
        $id = $insert('INSERT INTO category (parent_id, name, type, display_order, slug, is_published, description, subtitle, anchor_id, target_url)
            VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?)', [
            $parentId, $name, $type, $slug, $published, 'Runtime-only generic security fixture.', 'Generic fixture',
            'anchor-' . $slug, $targetUrl,
        ]);
        return ['id' => $id, 'slug' => $slug, 'name' => $name, 'type' => $type];
    };
    $field = static function (
        int $seriesId,
        string $key,
        string $label,
        string $type = 'text',
        string $scope = 'product_attribute',
        int $hidden = 0,
        int $filterable = 0,
        int $sortable = 0,
        int $tableColumn = 1,
        int $searchable = 1,
        string $filterType = 'select',
        ?string $default = null
    ) use ($insert): int {
        return $insert('INSERT INTO series_custom_field
            (series_id, field_key, label, field_type, field_scope, default_value, sort_order, is_required,
             is_public_portal_hidden, unit, is_filterable, is_sortable, is_table_column, is_searchable,
             filter_type, config_json, group_key, group_label)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?, NULL, ?, ?, ?, ?, ?, NULL, NULL, NULL)', [
            $seriesId, $key, $label, $type, $scope, $default, $hidden, $filterable, $sortable,
            $tableColumn, $searchable, $filterType,
        ]);
    };
    $value = static function (int $productId, int $fieldId, string $text) use ($insert): void {
        $insert('INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value) VALUES (?, ?, ?)', [$productId, $fieldId, $text]);
    };
    $metadataValue = static function (int $seriesId, int $fieldId, string $text) use ($insert): void {
        $insert('INSERT INTO series_custom_field_value (series_id, series_custom_field_id, value) VALUES (?, ?, ?)', [$seriesId, $fieldId, $text]);
    };
    $asset = static function (
        string $ownerType,
        int $ownerId,
        string $key,
        string $path,
        int $public = 1,
        string $disk = 'media',
        int $download = 1,
        string $mime = 'application/pdf',
        ?string $metadata = null
    ) use ($insert): int {
        return $insert('INSERT INTO catalog_asset
            (owner_type, owner_id, asset_key, role, storage_disk, file_path, mime_type, title, alt_text,
             metadata_json, display_order, is_download, is_public)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)', [
            $ownerType, $ownerId, $key, 'generic-document', $disk, $path, $mime, 'Generic fixture asset',
            'Generic fixture', $metadata, $download, $public,
        ]);
    };
    $block = static function (
        string $ownerType,
        int $ownerId,
        string $key,
        string $type,
        array $payload,
        int $public = 1,
        ?string $title = null
    ) use ($insert): int {
        return $insert('INSERT INTO catalog_content_block
            (owner_type, owner_id, block_key, block_type, title, anchor_id, payload_json, display_order, is_navigation, is_public)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0, 1, ?)', [
            $ownerType, $ownerId, $key, $type, $title ?? $key, 'block-' . $key,
            json_encode($payload, JSON_THROW_ON_ERROR), $public,
        ]);
    };
    $collection = static function (string $key, int $public = 1) use ($insert): int {
        return $insert('INSERT INTO catalog_collection (collection_key, title, description, target_url, config_json, display_order, is_public)
            VALUES (?, ?, ?, NULL, ?, 0, ?)', [$key, 'Generic collection', 'Runtime fixture', '{}', $public]);
    };
    $collectionItem = static function (int $collectionId, int $nodeId, ?int $assetId, int $public = 1) use ($insert): int {
        return $insert('INSERT INTO catalog_collection_item (collection_id, node_id, asset_id, title, subtitle, display_order, is_public)
            VALUES (?, ?, ?, ?, ?, 0, ?)', [$collectionId, $nodeId, $assetId, 'Generic item', 'Runtime fixture', $public]);
    };

    $decode = static function (array $response): array {
        $value = json_decode((string) ($response['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new RuntimeException('Expected a JSON response object.');
        }
        return $value;
    };
    $dispatch = static function (
        string $path,
        array $query = [],
        string $method = 'GET',
        ?mysqli $connection = null,
        ?CatalogV1Service $injected = null
    ) use ($db): array {
        $controller = new PublicCatalogV1Controller($injected ?? new CatalogV1Service($connection ?? $db));
        return Transport::capture('', $query, [], [], [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $path,
        ], static fn () => $controller->run());
    };
    $success = static function (array $response, TestSuite $t, int $status = 200): array {
        if ($status !== $response['status']) {
            throw new RuntimeException('Expected status ' . $status . '; got ' . $response['status'] . ': ' . ($response['body'] ?? ''));
        }
        $t->same($status, $response['status']);
        $body = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        $t->same(true, $body['success'] ?? null);
        $t->truth(array_key_exists('data', $body), 'Success envelope must contain data.');
        return $body['data'];
    };
    $error = static function (array $response, TestSuite $t, int $status, ?string $code = null): array {
        if ($status !== $response['status']) {
            throw new RuntimeException('Expected status ' . $status . '; got ' . $response['status'] . ': ' . ($response['body'] ?? ''));
        }
        $t->same($status, $response['status']);
        $body = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        $t->same(false, $body['success'] ?? null);
        $t->truth(isset($body['errorCode']), 'Error envelope must contain errorCode.');
        $t->truth(!array_key_exists('data', $body), 'Error envelope must not contain data.');
        if ($code !== null) {
            $t->same($code, $body['errorCode']);
        }
        return $body;
    };
    $contains = static function (mixed $haystack, string $needle) use (&$contains): bool {
        if (is_array($haystack)) {
            foreach ($haystack as $key => $value) {
                if ((string) $key === $needle || $contains($value, $needle)) {
                    return true;
                }
            }
        } elseif (is_string($haystack) && str_contains($haystack, $needle)) {
            return true;
        }
        return false;
    };

    $root = $node(null, 'Generic Root ' . $tag);
    $branch = $node($root['id'], 'Generic Branch ' . $tag);
    $deep = $node($branch['id'], 'Generic Deep Section ' . $tag, 'category', 1, 'javascript:alert(1)');
    $seriesA = $node($deep['id'], 'Generic Series Alpha ' . $tag, 'series');
    $seriesB = $node($deep['id'], 'Generic Series Beta ' . $tag, 'series');
    $hiddenParent = $node(null, 'Generic Unpublished Parent ' . $tag, 'category', 0);
    $hiddenChild = $node($hiddenParent['id'], 'Generic Published Child ' . $tag);
    $hiddenSeries = $node($hiddenChild['id'], 'Generic Hidden Series ' . $tag, 'series');

    $publicMetaKey = 'intro_probe';
    $privateMetaKey = 'private_metadata_probe_' . $tag;
    $publicMeta = $field($seriesA['id'], $publicMetaKey, 'Generic Introduction', 'text', 'series_metadata', 0, 0, 0, 0, 1, 'select', 'Generic default introduction');
    $privateMeta = $field($seriesA['id'], $privateMetaKey, 'Private Metadata Probe', 'text', 'series_metadata', 1, 1, 1, 1, 1, 'select', 'private-metadata-default-' . $tag);
    $metadataValue($seriesA['id'], $publicMeta, 'Generic metadata visible value.');
    $metadataValue($seriesA['id'], $privateMeta, 'private-metadata-value-' . $tag);

    $dimensionKey = 'dimension_probe';
    $rangeKey = 'voltage_probe';
    $plainKey = 'plain_probe';
    $fileKey = 'attachment_probe';
    $privateProductKey = 'private_product_probe_' . $tag;
    $dimensionField = $field($seriesA['id'], $dimensionKey, 'Generic Dimension', 'text', 'product_attribute', 0, 1, 1, 1, 1, 'select', 'Default Dimension');
    $rangeField = $field($seriesA['id'], $rangeKey, 'Generic Voltage', 'number', 'product_attribute', 0, 1, 1, 1, 1, 'range', '0');
    $plainField = $field($seriesA['id'], $plainKey, 'Generic Plain Field', 'text', 'product_attribute', 0, 0, 0, 1, 0, 'select', null);
    $fileField = $field($seriesA['id'], $fileKey, 'Generic Attachment', 'file', 'product_attribute', 0, 1, 1, 1, 1, 'select', 'security/probe.pdf');
    $privateProductField = $field($seriesA['id'], $privateProductKey, 'Private Product Probe', 'text', 'product_attribute', 1, 1, 1, 1, 1, 'select', 'private-default-' . $tag);
    $otherKeyA = 'material_probe';
    $otherKeyB = 'layer_probe';
    $fieldB1 = $field($seriesB['id'], $otherKeyA, 'Generic Material', 'text', 'product_attribute', 0, 1, 0, 1, 1, 'select', null);
    $fieldB2 = $field($seriesB['id'], $otherKeyB, 'Generic Layer Count', 'number', 'product_attribute', 0, 0, 1, 1, 1, 'range', null);
    $hiddenSeriesField = $field($hiddenSeries['id'], 'hidden_series_value_' . $tag, 'Hidden Series Field', 'text');

    $skuA1 = 'GENERIC-PROBE-A-' . strtoupper($tag);
    $skuA2 = 'GENERIC-PROBE-B-' . strtoupper($tag);
    $skuB = 'GENERIC-PROBE-C-' . strtoupper($tag);
    $productA1 = $insert('INSERT INTO product (series_id, sku, name, description, is_published) VALUES (?, ?, ?, ?, 1)', [
        $seriesA['id'], $skuA1, 'Generic Product Alpha ' . $tag, 'Generic searchable product fixture.',
    ]);
    $productA2 = $insert('INSERT INTO product (series_id, sku, name, description, is_published) VALUES (?, ?, ?, ?, 1)', [
        $seriesA['id'], $skuA2, 'Generic Product Beta ' . $tag, 'Second generic product fixture.',
    ]);
    $productB = $insert('INSERT INTO product (series_id, sku, name, description, is_published) VALUES (?, ?, ?, ?, 1)', [
        $seriesB['id'], $skuB, 'Generic Product Gamma ' . $tag, 'Independent generic schema fixture.',
    ]);
    $productUnpublished = $insert('INSERT INTO product (series_id, sku, name, description, is_published) VALUES (?, ?, ?, ?, 0)', [
        $seriesA['id'], 'GENERIC-PRIVATE-' . strtoupper($tag), 'Hidden Product ' . $tag, 'unpublished-product-secret-' . $tag,
    ]);
    $productHiddenAncestor = $insert('INSERT INTO product (series_id, sku, name, description, is_published) VALUES (?, ?, ?, ?, 1)', [
        $hiddenSeries['id'], 'GENERIC-HIDDEN-ANCESTOR-' . strtoupper($tag), 'Hidden Ancestor Product ' . $tag, 'hidden-ancestor-product-' . $tag,
    ]);
    $value($productA1, $dimensionField, 'dimension alpha');
    $value($productA1, $rangeField, '10');
    $value($productA1, $plainField, 'nonfilterable-value-' . $tag);
    $value($productA1, $privateProductField, 'private-product-value-' . $tag);
    $value($productA1, $fieldB1, 'cross-series-private-value-' . $tag);
    $value($productA2, $rangeField, '2');
    $value($productA2, $plainField, 'ordinary-value');
    $value($productB, $fieldB1, 'material beta');
    $value($productB, $fieldB2, '4');
    $value($productUnpublished, $dimensionField, 'unpublished-only-dimension');
    $value($productUnpublished, $privateProductField, 'unpublished-private-value-' . $tag);
    $value($productHiddenAncestor, $hiddenSeriesField, 'hidden-ancestor-field-value-' . $tag);

    $mediaRoot = (string) Config::get('app')['storage']['media'];
    $testDirectory = $mediaRoot . '/security-probe-' . $tag;
    if (!is_dir($testDirectory) && !mkdir($testDirectory, 0700, true) && !is_dir($testDirectory)) {
        throw new RuntimeException('Unable to prepare disposable asset storage.');
    }
    $publicRelative = 'security-probe-' . $tag . '/generic-document.pdf';
    file_put_contents($mediaRoot . '/' . $publicRelative, "%PDF-1.4\nGeneric test fixture\n");
    $publicAsset = $asset('series', $seriesA['id'], 'public-fixture-' . $tag, $publicRelative, 1, 'media', 1, 'application/pdf',
        json_encode(['file_path' => '/private/path', 'storage_disk' => 'media', 'owner_id' => 777, 'owner_type' => 'secret', 'caption' => 'safe caption'], JSON_THROW_ON_ERROR));
    $privateAsset = $asset('series', $seriesA['id'], 'private-fixture-' . $tag, 'private-storage-token-' . $tag . '.pdf', 0);
    $orphanAsset = $asset('category', 2147483000, 'orphan-fixture-' . $tag, 'orphan.pdf');
    $traversalAsset = $asset('series', $seriesA['id'], 'traversal-fixture-' . $tag, '../outside-' . $tag . '.pdf');
    $hiddenFileAsset = $asset('series', $seriesA['id'], 'executable-fixture-' . $tag, 'security-probe-' . $tag . '/unsafe.php', 1, 'media', 1, 'text/plain');
    $outsidePath = dirname($mediaRoot) . '/outside-' . $tag . '.pdf';
    file_put_contents($outsidePath, 'outside media root');
    $symlinkPath = $testDirectory . '/escape-' . $tag . '.pdf';
    if (!symlink($outsidePath, $symlinkPath)) {
        throw new RuntimeException('Unable to create the symlink fixture required by the security test.');
    }
    $symlinkAsset = $asset('series', $seriesA['id'], 'symlink-fixture-' . $tag,
        'security-probe-' . $tag . '/escape-' . $tag . '.pdf');
    $externalAsset = $asset('series', $seriesA['id'], 'external-fixture-' . $tag,
        'https://cdn.example.invalid/generic-document.pdf', 1, 'external');
    $badExternalAsset = $asset('series', $seriesA['id'], 'bad-external-fixture-' . $tag,
        'javascript:alert(1)', 1, 'external');
    $productPrivateAsset = $asset('product', $productA1, 'private-product-asset-' . $tag, 'private-product-file-' . $tag . '.pdf', 0);
    $unpublishedProductAsset = $asset('product', $productUnpublished, 'unpublished-product-asset-' . $tag, 'unpublished-product-file-' . $tag . '.pdf');
    $hiddenAncestorAsset = $asset('series', $hiddenSeries['id'], 'hidden-ancestor-asset-' . $tag, 'hidden-ancestor.pdf');

    $block('catalog', 0, 'public-root-' . $tag, 'hero', ['title' => 'Generic public catalog', 'private' => ['file_path' => '/secret/root']], 1);
    $block('catalog', 0, 'private-root-' . $tag, 'hero', ['secret' => 'private-root-block-' . $tag], 0);
    $block('series', $seriesA['id'], 'public-series-' . $tag, 'rich_text', [
        'body' => 'Generic public content.', 'private_ref' => ['asset_id' => $privateAsset],
        'safe_ref' => ['asset_id' => $publicAsset], 'private_paths' => ['storage_disk' => 'media', 'owner_id' => 888],
    ], 1);
    $block('series', $seriesA['id'], 'private-series-' . $tag, 'rich_text', ['secret' => 'private-series-block-' . $tag], 0);
    $block('category', $deep['id'], 'summary-' . $tag, 'series_table', ['columns' => [
        ['key' => 'public_intro', 'source' => 'metadata', 'field_key' => $publicMetaKey],
        ['key' => 'private_intro', 'source' => 'metadata', 'field_key' => $privateMetaKey],
        ['key' => 'unsafe', 'source' => 'metadata', 'field_key' => 'not-a-real-field'],
    ]], 1, 'Generic series summary');
    $block('category', $deep['id'], 'private-category-' . $tag, 'rich_text', ['secret' => 'private-category-block-' . $tag], 0);

    $collectionId = $collection('generic-release-' . $tag);
    $privateCollectionId = $collection('private-release-' . $tag, 0);
    $collectionItem($collectionId, $seriesA['id'], $publicAsset, 1);
    $collectionItem($collectionId, $seriesA['id'], $privateAsset, 1);
    $collectionItem($collectionId, $hiddenSeries['id'], $publicAsset, 1);
    $collectionItem($collectionId, $seriesA['id'], $publicAsset, 0);
    $collectionItem($privateCollectionId, $seriesA['id'], $publicAsset, 1);

    $pathA = implode('/', [$root['slug'], $branch['slug'], $deep['slug'], $seriesA['slug']]);
    $pathB = implode('/', [$root['slug'], $branch['slug'], $deep['slug'], $seriesB['slug']]);
    $pathHidden = implode('/', [$hiddenParent['slug'], $hiddenChild['slug'], $hiddenSeries['slug']]);
    $categoryPath = implode('/', [$root['slug'], $branch['slug'], $deep['slug']]);
    $base = '/api/v1/catalog';

    $t->test('public V1 routes use the success envelope and expose generic resources', static function () use (
        $t, $dispatch, $success, $root, $seriesA, $seriesB, $pathA, $pathB, $categoryPath, $base,
        $publicAsset, $collectionId, $tag, $dimensionKey, $rangeKey, $otherKeyA, $otherKeyB
    ): void {
        $rootData = $success($dispatch($base), $t);
        $t->truth(isset($rootData['breadcrumb'], $rootData['groups'], $rootData['collections']));
        $tree = $success($dispatch($base . '/tree'), $t);
        $t->truth($tree !== []);
        $resolved = $success($dispatch($base . '/resolve/' . $pathA), $t);
        $t->same('series', $resolved['resource']['type']);
        $t->same($pathA, $resolved['resource']['path']);
        $category = $success($dispatch($base . '/categories/' . $categoryPath), $t);
        $t->same($categoryPath, $category['resource']['path']);
        $t->truth(isset($category['sections'], $category['navigation'], $category['breadcrumb']));
        $series = $success($dispatch($base . '/series/' . $pathA), $t);
        $t->same($seriesA['id'], $series['resource']['id']);
        $fieldsA = $success($dispatch($base . '/series/' . $pathA . '/fields'), $t);
        $fieldsB = $success($dispatch($base . '/series/' . $pathB . '/fields'), $t);
        $keysA = array_column($fieldsA['fields'], 'field_key');
        $keysB = array_column($fieldsB['fields'], 'field_key');
        $t->truth(in_array($dimensionKey, $keysA, true) && in_array($rangeKey, $keysA, true));
        $t->truth(in_array($otherKeyA, $keysB, true) && in_array($otherKeyB, $keysB, true));
        $t->truth(!in_array($dimensionKey, $keysB, true) && !in_array($otherKeyA, $keysA, true), 'Series must expose independent dynamic schemas.');
        $parts = $success($dispatch($base . '/series/' . $pathA . '/parts'), $t);
        $t->truth(isset($parts['schema'], $parts['rows'], $parts['pagination']));
        $facets = $success($dispatch($base . '/series/' . $pathA . '/facets'), $t);
        $t->truth(is_array($facets));
        $search = $success($dispatch($base . '/search', ['q' => 'Generic']), $t);
        $t->truth(isset($search['rows'], $search['pagination']));
        $collection = $success($dispatch($base . '/collections/generic-release-' . $tag), $t);
        $t->same('generic-release-' . $tag, $collection['key']);
        $assetDownload = $dispatch($base . '/assets/' . $publicAsset);
        $t->same(200, $assetDownload['status']);
        $t->truth(is_string($assetDownload['file']));
        $t->same($collectionId > 0, true);
    });

    $t->test('private fields and values never appear in schema, defaults, rows, facets, search or summary tables', static function () use (
        $t, $dispatch, $success, $base, $pathA, $categoryPath, $privateProductKey, $privateMetaKey, $tag, $contains
    ): void {
        $fields = $success($dispatch($base . '/series/' . $pathA . '/fields'), $t);
        $parts = $success($dispatch($base . '/series/' . $pathA . '/parts'), $t);
        $series = $success($dispatch($base . '/series/' . $pathA), $t);
        $facets = $success($dispatch($base . '/series/' . $pathA . '/facets'), $t);
        $summary = $success($dispatch($base . '/categories/' . $categoryPath), $t);
        $t->truth(!$contains($fields, $privateProductKey), 'Private field metadata leaked from schema.');
        $t->truth(!$contains($parts, $privateProductKey) && !$contains($parts, 'private-product-value-' . $tag));
        $t->truth(!$contains($parts, 'private-default-' . $tag), 'Private default leaked in part values.');
        $t->truth(!$contains($series, $privateMetaKey) && !$contains($series, 'private-metadata-value-' . $tag));
        $t->truth(!$contains($series, 'private-metadata-default-' . $tag));
        $t->truth(!$contains($facets, $privateProductKey) && !$contains($facets, 'private-product-value-' . $tag));
        $t->truth(!$contains($summary, $privateMetaKey) && !$contains($summary, 'private-metadata-value-' . $tag));
        $t->truth(!$contains($summary, 'private-category-block-' . $tag));
    });

    $t->test('hidden fields are excluded from search and cross-series values are not joined', static function () use (
        $t, $dispatch, $success, $base, $pathA, $pathB, $tag, $otherKeyA
    ): void {
        $hiddenValue = $success($dispatch($base . '/series/' . $pathA . '/parts', ['search' => 'private-product-value-' . $tag]), $t);
        $t->same(0, $hiddenValue['pagination']['total']);
        $hiddenDefault = $success($dispatch($base . '/series/' . $pathA . '/parts', ['search' => 'private-default-' . $tag]), $t);
        $t->same(0, $hiddenDefault['pagination']['total']);
        $privateMetadataSearch = $success($dispatch($base . '/search', ['q' => 'private-metadata-value-' . $tag]), $t);
        $t->same(0, $privateMetadataSearch['pagination']['total']);
        $seriesAParts = $success($dispatch($base . '/series/' . $pathA . '/parts'), $t);
        $firstRowValues = $seriesAParts['rows'][0]['values'] ?? [];
        $t->truth(!array_key_exists($otherKeyA, $firstRowValues), 'A field belonging to another series was returned for this product.');
        $seriesBParts = $success($dispatch($base . '/series/' . $pathB . '/parts'), $t);
        $t->truth(array_key_exists($otherKeyA, $seriesBParts['rows'][0]['values'] ?? []));
    });

    $t->test('published hierarchy and products gate search, assets and collections', static function () use (
        $t, $dispatch, $success, $error, $base, $pathHidden, $pathA, $tag, $hiddenAncestorAsset,
        $productUnpublished, $unpublishedProductAsset, $hiddenParent, $privateCollectionId, $contains
    ): void {
        $tree = $success($dispatch($base . '/tree'), $t);
        $t->truth(!$contains($tree, 'Generic Unpublished Parent ' . $tag));
        $hiddenPathResponse = $dispatch($base . '/resolve/' . $pathHidden);
        $error($hiddenPathResponse, $t, 404, 'NOT_FOUND');
        $hiddenAssetResponse = $dispatch($base . '/assets/' . $hiddenAncestorAsset);
        $error($hiddenAssetResponse, $t, 404, 'NOT_FOUND');
        $unpublishedAssetResponse = $dispatch($base . '/assets/' . $unpublishedProductAsset);
        $error($unpublishedAssetResponse, $t, 404, 'NOT_FOUND');
        $partRows = $success($dispatch($base . '/series/' . $pathA . '/parts'), $t);
        $t->truth(!$contains($partRows, 'GENERIC-PRIVATE-' . strtoupper($tag)));
        $search = $success($dispatch($base . '/search', ['q' => 'unpublished-product-secret-' . $tag]), $t);
        $t->same(0, $search['pagination']['total']);
        $hiddenSeriesSearch = $success($dispatch($base . '/search', ['q' => 'hidden-ancestor-product-' . $tag]), $t);
        $t->same(0, $hiddenSeriesSearch['pagination']['total']);
        $publicCollection = $success($dispatch($base . '/collections/generic-release-' . $tag), $t);
        $t->same(2, count($publicCollection['items']));
        $t->same(null, $publicCollection['items'][1]['image']);
        $privateCollectionResponse = $dispatch($base . '/collections/private-release-' . $tag);
        $error($privateCollectionResponse, $t, 404, 'NOT_FOUND');
        $t->truth($hiddenParent['id'] > 0 && $productUnpublished > 0 && $privateCollectionId > 0);
    });

    $t->test('private blocks, assets, orphan references and unsafe payload metadata are filtered', static function () use (
        $t, $dispatch, $success, $base, $pathA, $publicAsset, $privateAsset, $productPrivateAsset,
        $orphanAsset, $traversalAsset, $hiddenFileAsset, $symlinkAsset, $externalAsset, $badExternalAsset, $tag, $contains
    ): void {
        $series = $success($dispatch($base . '/series/' . $pathA), $t);
        $t->truth(!$contains($series, 'private-series-block-' . $tag));
        $t->truth(!$contains($series, 'private-storage-token-' . $tag . '.pdf'));
        $t->truth(!$contains($series, 'private-product-file-' . $tag . '.pdf'));
        $t->truth(!$contains($series, 'private_ref') || !$contains($series, (string) $privateAsset));
        $t->truth(!$contains($series, 'private/path') && !$contains($series, 'owner_id') && !$contains($series, 'storage_disk'));
        $t->truth($contains($series, 'safe caption'));
        $assetResources = $series['assets'];
        $availableById = [];
        foreach ($assetResources as $resource) {
            $availableById[(int) $resource['id']] = $resource['available'];
        }
        $t->same(true, $availableById[$publicAsset] ?? null);
        foreach ([$traversalAsset, $hiddenFileAsset, $symlinkAsset] as $unsafeId) {
            $t->same(false, $availableById[$unsafeId] ?? null);
        }
        $t->truth(!array_key_exists($privateAsset, $availableById));
        $t->truth(!array_key_exists($orphanAsset, $availableById));
        $t->truth(!array_key_exists($badExternalAsset, $availableById));
        $t->truth(array_key_exists($externalAsset, $availableById));
        $t->same(null, $availableById[$externalAsset]);
        foreach ([$privateAsset, $productPrivateAsset, $orphanAsset, $traversalAsset, $hiddenFileAsset, $symlinkAsset, $badExternalAsset] as $id) {
            $response = $dispatch($base . '/assets/' . $id);
            $t->same(404, $response['status']);
        }
        $publicDownload = $dispatch($base . '/assets/' . $publicAsset);
        $t->same(200, $publicDownload['status']);
        $t->same('application/pdf', $publicDownload['headers']['Content-Type'] ?? null);
        $t->same('sandbox; default-src \'none\'', $publicDownload['headers']['Content-Security-Policy'] ?? null);
        $t->truth(is_string($publicDownload['file']) && str_ends_with($publicDownload['file'], 'generic-document.pdf'));
        $redirect = $dispatch($base . '/assets/' . $externalAsset);
        $t->same(302, $redirect['status']);
        $t->same('https://cdn.example.invalid/generic-document.pdf', $redirect['headers']['Location'] ?? null);
    });

    $t->test('summary tables expose configured public metadata columns only', static function () use (
        $t, $dispatch, $success, $base, $categoryPath, $privateMetaKey, $tag, $contains
    ): void {
        $category = $success($dispatch($base . '/categories/' . $categoryPath), $t);
        $block = null;
        foreach ($category['blocks'] as $candidate) {
            if (($candidate['type'] ?? null) === 'series_table') {
                $block = $candidate;
                break;
            }
        }
        $t->truth(is_array($block), 'Expected generic series summary block.');
        $payload = $block['payload'];
        $table = $payload['table'] ?? $payload;
        $t->truth(!in_array($privateMetaKey, array_column($table['columns'] ?? [], 'field_key'), true));
        $t->truth(!$contains($table, 'private-metadata-value-' . $tag));
        $t->truth(!$contains($table, 'not-a-real-field'));
    });

    $t->test('parts paging, searching, sorting, filtering and facets respect public field metadata', static function () use (
        $t, $dispatch, $success, $base, $pathA, $dimensionKey, $rangeKey, $plainKey, $fileKey
    ): void {
        $page = $success($dispatch($base . '/series/' . $pathA . '/parts', ['page' => '1', 'per_page' => '1']), $t);
        $t->same(1, $page['pagination']['per_page']);
        $t->same(2, $page['pagination']['total']);
        $t->truth(($page['pagination']['next'] ?? $page['pagination']['links']['next'] ?? null) !== null);
        $sorted = $success($dispatch($base . '/series/' . $pathA . '/parts', ['sort' => $rangeKey, 'direction' => 'desc']), $t);
        $t->same('10', (string) ($sorted['rows'][0]['values'][$rangeKey] ?? ''));
        $filtered = $success($dispatch($base . '/series/' . $pathA . '/parts', ['filter' => [$dimensionKey => ['dimension alpha']]]), $t);
        $t->same(1, $filtered['pagination']['total']);
        $ranged = $success($dispatch($base . '/series/' . $pathA . '/parts', ['filter' => [$rangeKey => ['min' => '3', 'max' => '12']]]), $t);
        $t->same(1, $ranged['pagination']['total']);
        $searched = $success($dispatch($base . '/series/' . $pathA . '/parts', ['search' => 'dimension alpha']), $t);
        $t->same(1, $searched['pagination']['total']);
        $nonSearchable = $success($dispatch($base . '/series/' . $pathA . '/parts', ['search' => 'nonfilterable-value']), $t);
        $t->same(0, $nonSearchable['pagination']['total']);
        $facets = $success($dispatch($base . '/series/' . $pathA . '/facets'), $t);
        $facetKeys = array_column($facets, 'field_key');
        $t->truth(in_array($dimensionKey, $facetKeys, true) && in_array($rangeKey, $facetKeys, true));
        $t->truth(!in_array($plainKey, $facetKeys, true) && !in_array($fileKey, $facetKeys, true));
    });

    $t->test('unknown, private and non-filterable query keys fail safely', static function () use (
        $t, $dispatch, $error, $base, $pathA, $privateProductKey, $plainKey, $fileKey, $tag
    ): void {
        $parts = $base . '/series/' . $pathA . '/parts';
        $facets = $base . '/series/' . $pathA . '/facets';
        foreach ([
            [$parts, ['unexpected' => 'value']],
            [$parts, ['sort' => 'unknown_probe']],
            [$parts, ['sort' => $plainKey]],
            [$parts, ['sort' => $privateProductKey]],
            [$parts, ['sort' => $fileKey]],
            [$parts, ['filter' => ['unknown_probe' => ['x']]]],
            [$parts, ['filter' => [$plainKey => ['x']]]],
            [$parts, ['filter' => [$privateProductKey => ['private-product-value-' . $tag]]]],
            [$parts, ['filter' => [$fileKey => ['security/probe.pdf']]]],
            [$facets, ['page' => '1']],
            [$facets, ['filter' => [$privateProductKey => ['private-product-value-' . $tag]]]],
            ['/api/v1/catalog', ['unknown' => 'query']],
            ['/api/v1/catalog/series/' . $pathA . '/fields', ['search' => 'x']],
        ] as [$path, $query]) {
            $response = $dispatch($path, $query);
            $body = $error($response, $t, 422, 'VALIDATION_ERROR');
            $t->truth(!isset($body['details']['sql']) && !isset($body['details']['exception']));
        }
    });

    $t->test('malformed query arrays, numbers, ranges and text are rejected', static function () use (
        $t, $dispatch, $error, $base, $pathA, $rangeKey, $dimensionKey
    ): void {
        $parts = $base . '/series/' . $pathA . '/parts';
        $cases = [
            ['page' => []], ['page' => '0'], ['page' => '-1'], ['page' => '1.2'], ['page' => '1000001'],
            ['per_page' => '0'], ['per_page' => '101'], ['per_page' => ['2']],
            ['search' => ['probe']], ['search' => "bad\ntext"], ['direction' => 'DESC'], ['direction' => ['asc']],
            ['filter' => 'not-an-array'], ['filter' => [$dimensionKey => []]],
            ['filter' => [$dimensionKey => ['nested' => 'value']]],
            ['filter' => [$rangeKey => []]], ['filter' => [$rangeKey => ['min' => '10', 'max' => '2']]],
            ['filter' => [$rangeKey => ['min' => 'NaN']]], ['filter' => [$rangeKey => ['min' => '1', 'unexpected' => '2']]],
            ['sort' => ['field']],
        ];
        foreach ($cases as $query) {
            $error($dispatch($parts, $query), $t, 422, 'VALIDATION_ERROR');
        }
        $tooManyFilters = [];
        for ($index = 0; $index < 51; $index++) {
            $tooManyFilters['generic_' . $index] = ['x'];
        }
        $error($dispatch($parts, ['filter' => $tooManyFilters]), $t, 422, 'VALIDATION_ERROR');
        $error($dispatch($base . '/search', ['q' => str_repeat('x', 257)]), $t, 422, 'VALIDATION_ERROR');
        $error($dispatch($base . '/search', ['per_page' => '101']), $t, 422, 'VALIDATION_ERROR');
    });

    $t->test('invalid paths, missing resources, wrong resource types and methods have stable errors', static function () use (
        $t, $dispatch, $error, $base, $pathA, $categoryPath
    ): void {
        foreach ([
            $base . '/resolve/%',
            $base . '/resolve/../outside',
            $base . '/resolve/%2e%2e/outside',
            $base . '/resolve/a%ZZ',
            $base . '/resolve/a\\b',
        ] as $path) {
            $error($dispatch($path), $t, 422, 'INVALID_PATH');
        }
        $error($dispatch($base . '/resolve/a%2Fb'), $t, 404, 'NOT_FOUND');
        $error($dispatch($base . '/route-that-does-not-exist'), $t, 404, 'NOT_FOUND');
        $error($dispatch($base . '/resolve/missing-generic-node'), $t, 404, 'NOT_FOUND');
        $error($dispatch($base . '/categories/' . $pathA), $t, 404, 'NOT_FOUND');
        $error($dispatch($base . '/series/' . $categoryPath), $t, 404, 'NOT_FOUND');
        $error($dispatch($base . '/collections/no-such-generic-collection'), $t, 404, 'NOT_FOUND');
        $error($dispatch($base . '/assets/2147483648'), $t, 422, 'VALIDATION_ERROR');
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'] as $method) {
            $response = $dispatch($base, [], $method);
            $error($response, $t, 405, 'METHOD_NOT_ALLOWED');
            $t->same('GET', $response['headers']['Allow'] ?? null);
            $t->truth(isset($response['headers']['Cache-Control'], $response['headers']['X-Content-Type-Options']));
        }
    });

    $t->test('database exceptions are redacted from the public error response', static function () use ($t, $dispatch, $base): void {
        $settings = Config::get('db');
        $closed = new mysqli((string) $settings['host'], (string) $settings['username'], (string) $settings['password'],
            (string) $settings['database'], (int) $settings['port']);
        $closed->set_charset('utf8mb4');
        $service = new CatalogV1Service($closed);
        $closed->close();
        $response = $dispatch($base . '/tree', [], 'GET', null, $service);
        $body = json_decode((string) $response['body'], true, 512, JSON_THROW_ON_ERROR);
        $t->same(500, $response['status']);
        $t->same(false, $body['success'] ?? null);
        $t->same('INTERNAL_ERROR', $body['errorCode'] ?? null);
        $t->same('The public catalog is unavailable. Check database provisioning.', $body['message'] ?? null);
        $t->truth(!str_contains((string) $response['body'], 'mysqli'));
        $t->truth(!str_contains((string) $response['body'], (string) $settings['database']));
        $t->truth(!str_contains((string) $response['body'], (string) Config::get('app')['project_root']));
    });
};
