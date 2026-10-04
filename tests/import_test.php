<?php
declare(strict_types=1);

use CatalogSuite\Services\CatalogV1ImportService;
use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Support\Config;

return static function (TestSuite $t, mysqli $db): void {
    $importer = new CatalogV1ImportService($db);
    $row = static function (string $table, string $where, array $params = []) use ($db): ?array {
        $stmt = $db->prepare("SELECT * FROM `{$table}` WHERE {$where} LIMIT 1");
        $stmt->execute($params);
        $result = $stmt->get_result()->fetch_assoc() ?: null;
        $stmt->close();
        return $result;
    };
    $count = static function (string $table) use ($db): int {
        return (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetch_row()[0];
    };
    $base = static function (string $tag): array {
        $root = 'runtime-import-' . $tag;
        $branch = $root . '/runtime-branch';
        $series = $branch . '/runtime-family/runtime-series';
        return [
            'version' => 'public-product-api-v1',
            'nodes' => [
                ['path' => $root, 'name' => 'Runtime Import Root', 'type' => 'category', 'is_published' => true],
                ['path' => $branch, 'name' => 'Runtime Import Branch', 'type' => 'category', 'is_published' => true],
                ['path' => $branch . '/runtime-family', 'name' => 'Runtime Import Family', 'type' => 'category', 'is_published' => true],
                ['path' => $series, 'name' => 'Runtime Import Series', 'type' => 'series', 'is_published' => true,
                    'fields' => [['field_key' => 'known_value', 'label' => 'Known Value', 'is_public_portal_hidden' => false]],
                    'parts' => [['sku' => 'RUNTIME-IMPORT-' . strtoupper($tag), 'values' => ['known_value' => 'runtime-value']]],
                ],
            ],
            'aliases' => [],
            'collections' => [],
        ];
    };

    $buildMergeManifest = static function (string $tag, bool $updated, bool $includeRetained): array {
        $root = 'runtime-import-' . $tag;
        $branch = $root . '/runtime-branch';
        $familyA = $branch . '/runtime-family-a';
        $seriesA = $familyA . '/runtime-series-a';
        $familyB = $branch . '/runtime-family-b';
        $seriesB = $familyB . '/runtime-series-b';
        $seriesASku = 'RUNTIME-PART-' . strtoupper($tag);
        $imageKey = 'runtime-series-image';
        $nodes = [
            ['path' => $root, 'name' => 'Runtime Import Root', 'type' => 'category', 'is_published' => true],
            ['path' => $branch, 'name' => 'Runtime Branch', 'type' => 'category', 'is_published' => true],
            ['path' => $familyA, 'name' => 'Runtime Family A', 'type' => 'category', 'is_published' => true],
            ['path' => $familyB, 'name' => 'Runtime Family B', 'type' => 'category', 'is_published' => true],
            [
                'path' => $seriesA,
                'name' => $updated ? 'Runtime Series A Renamed' : 'Runtime Series A',
                'type' => 'series',
                'is_published' => true,
                'fields' => [
                    ['field_key' => 'shared_value', 'label' => $updated ? 'Updated Part Value' : 'Part Value', 'field_type' => 'number',
                        'field_scope' => 'product_attribute', 'is_public_portal_hidden' => false, 'is_filterable' => true, 'is_sortable' => true],
                    ['field_key' => 'shared_value', 'label' => 'Series Value', 'field_type' => 'text',
                        'field_scope' => 'series_metadata', 'is_public_portal_hidden' => false],
                    ['field_key' => 'surface_code', 'label' => 'Surface Code', 'field_type' => 'text',
                        'field_scope' => 'product_attribute', 'is_public_portal_hidden' => false],
                ],
                ...($updated ? [] : ['metadata' => ['shared_value' => 'runtime-series-metadata']]),
                'assets' => [[
                    'asset_key' => $imageKey, 'role' => 'series_image', 'file_path' => 'runtime-only/' . $tag . '/series.webp',
                    'mime_type' => 'image/webp', 'title' => $updated ? 'Updated Series Asset' : 'Series Asset', 'is_public' => true,
                ]],
                'blocks' => [[
                    'block_key' => 'runtime-series-section', 'block_type' => 'image_gallery',
                    'title' => $updated ? 'Updated Series Section' : 'Series Section', 'is_public' => true,
                    'payload' => ['image' => ['asset_key' => $imageKey], 'text' => 'Runtime section copy'],
                ]],
                'parts' => [[
                    'sku' => $seriesASku, 'name' => $updated ? 'Updated Runtime Part' : 'Runtime Part', 'is_published' => true,
                    'values' => ['shared_value' => $updated ? '14.0' : '12.5', 'surface_code' => 'runtime-surface'],
                    'assets' => [[
                        'asset_key' => 'runtime-part-document', 'role' => 'document', 'file_path' => 'runtime-only/' . $tag . '/part.pdf',
                        'mime_type' => 'application/pdf', 'title' => $updated ? 'Updated Part Asset' : 'Part Asset', 'is_download' => true, 'is_public' => true,
                    ]],
                    'blocks' => [[
                        'block_key' => 'runtime-part-section', 'block_type' => 'document',
                        'title' => $updated ? 'Updated Part Section' : 'Part Section', 'is_public' => true,
                        'payload' => ['document' => ['asset_key' => 'runtime-part-document']],
                    ]],
                ]],
            ],
            ['path' => $seriesB, 'name' => 'Runtime Series B', 'type' => 'series', 'is_published' => true,
                ...($updated ? [] : [
                    'fields' => [
                        ['field_key' => 'other_metric', 'label' => 'Other Metric', 'field_type' => 'number', 'is_public_portal_hidden' => false],
                        ['field_key' => 'material_class', 'label' => 'Material Class', 'field_type' => 'text', 'is_public_portal_hidden' => false],
                    ],
                    'parts' => [['sku' => 'RUNTIME-OTHER-' . strtoupper($tag), 'name' => 'Other Runtime Part', 'values' => ['other_metric' => '27', 'material_class' => 'runtime-material']]],
                ]),
            ],
        ];
        $retainedSeries = $root . '/runtime-retained-branch/runtime-retained-series';
        if ($includeRetained) {
            $nodes[] = ['path' => $root . '/runtime-retained-branch', 'name' => 'Runtime Retained Branch', 'type' => 'category', 'is_published' => true];
            $nodes[] = ['path' => $retainedSeries, 'name' => 'Runtime Retained Series', 'type' => 'series', 'is_published' => true,
                'fields' => [
                    ['field_key' => 'retained_metric', 'label' => 'Retained Metadata', 'field_scope' => 'series_metadata', 'is_public_portal_hidden' => false],
                    ['field_key' => 'retained_metric', 'label' => 'Retained Part Metric', 'field_scope' => 'product_attribute', 'is_public_portal_hidden' => false],
                ],
                'metadata' => ['retained_metric' => 'retain-this-metadata'],
                'assets' => [['asset_key' => 'retained-asset', 'role' => 'image', 'file_path' => 'runtime-only/' . $tag . '/retained.webp', 'mime_type' => 'image/webp', 'is_public' => true]],
                'blocks' => [['block_key' => 'retained-block', 'block_type' => 'feature_list', 'title' => 'Retained Block', 'is_public' => true]],
                'parts' => [['sku' => 'RUNTIME-RETAINED-' . strtoupper($tag), 'values' => ['retained_metric' => 'retained-value']]],
            ];
        }
        $manifest = ['version' => 'public-product-api-v1', 'nodes' => $nodes,
            'aliases' => [['path' => $root . '/runtime-shortcut', 'node_path' => $seriesA, 'is_canonical' => true]],
            'collections' => [[
                'collection_key' => 'runtime-collection-' . $tag, 'title' => $updated ? 'Updated Collection' : 'Runtime Collection', 'is_public' => true,
                'items' => [
                    ['item_key' => 'runtime-item-a', 'node_path' => $seriesA, 'asset_key' => $imageKey, 'title' => $updated ? 'Updated Item A' : 'Item A', 'is_public' => true],
                    ...($updated ? [] : [
                        ['item_key' => 'runtime-item-b', 'node_path' => $seriesB, 'title' => 'Item B', 'is_public' => true],
                        ...($includeRetained ? [['item_key' => 'runtime-item-retained', 'node_path' => $retainedSeries, 'title' => 'Retained Item', 'is_public' => true]] : []),
                    ]),
                ],
            ]],
        ];
        return $manifest;
    };

    $t->test('empty manifest is non-seeding and reports zero changes', static function () use ($t, $importer, $count): void {
        $before = [$count('category'), $count('product'), $count('catalog_asset'), $count('catalog_content_block'), $count('catalog_collection')];
        $result = $importer->import(['version' => 'public-product-api-v1']);
        $t->same(['nodes' => 0, 'fields' => 0, 'parts' => 0, 'blocks' => 0, 'assets' => 0, 'aliases' => 0, 'collections' => 0, 'collection_items' => 0], $result['records_processed']);
        $t->same($before, [$count('category'), $count('product'), $count('catalog_asset'), $count('catalog_content_block'), $count('catalog_collection')]);
        $t->same(0, $count('seed_migration'));
    });

    $t->test('dry run validates generic hierarchy and presentation then rolls every row back', static function () use ($t, $importer, $buildMergeManifest, $count): void {
        $manifest = $buildMergeManifest(bin2hex(random_bytes(3)), false, true);
        $result = $importer->import($manifest, true);
        $t->same(true, $result['dry_run']);
        $t->truth($result['records_processed']['nodes'] >= 7 && $result['records_processed']['assets'] >= 3 && $result['records_processed']['blocks'] >= 3);
        foreach (['category', 'product', 'series_custom_field', 'series_custom_field_value', 'product_custom_field_value', 'catalog_asset', 'catalog_content_block', 'catalog_path_alias', 'catalog_collection', 'catalog_collection_item'] as $table) {
            $t->same(0, $count($table));
        }
    });

    $t->test('a failure after valid inserts rolls the whole manifest back', static function () use ($t, $db, $importer, $count): void {
        $tag = bin2hex(random_bytes(3));
        $root = 'runtime-rollback-' . $tag;
        $manifest = ['version' => 'public-product-api-v1', 'nodes' => [
            ['path' => $root, 'name' => 'Runtime Partial Root', 'type' => 'category'],
            ['path' => $root . '/missing-parent/runtime-series', 'name' => 'Runtime Invalid Series', 'type' => 'series'],
        ]];
        $before = [$count('category'), $count('series_custom_field')];
        $t->throws(static fn () => $importer->import($manifest), 422);
        $stmt = $db->prepare('SELECT COUNT(*) FROM category WHERE slug = ?');
        $stmt->execute([$root]);
        $t->same(0, (int) $stmt->get_result()->fetch_row()[0]);
        $stmt->close();
        $t->same($before, [$count('category'), $count('series_custom_field')]);
    });

    $t->test('merge upserts persistent identities, preserves omitted records, and resolves asset references', static function () use ($t, $db, $importer, $buildMergeManifest, $row): void {
        $tag = bin2hex(random_bytes(3));
        $first = $buildMergeManifest($tag, false, true);
        $importer->import($first);
        $root = 'runtime-import-' . $tag;
        $seriesAPath = $root . '/runtime-branch/runtime-family-a/runtime-series-a';
        $seriesBPath = $root . '/runtime-branch/runtime-family-b/runtime-series-b';
        $retainedPath = $root . '/runtime-retained-branch/runtime-retained-series';
        $seriesA = $row('category', 'slug = ?', ['runtime-series-a']);
        $seriesB = $row('category', 'slug = ?', ['runtime-series-b']);
        $seriesRetained = $row('category', 'slug = ?', ['runtime-retained-series']);
        $t->truth($seriesA !== null && $seriesB !== null && $seriesRetained !== null);
        $seriesAId = (int) $seriesA['id'];
        $seriesBId = (int) $seriesB['id'];
        $fieldProduct = $row('series_custom_field', "series_id = {$seriesAId} AND field_key = 'shared_value' AND field_scope = 'product_attribute'");
        $fieldMetadata = $row('series_custom_field', "series_id = {$seriesAId} AND field_key = 'shared_value' AND field_scope = 'series_metadata'");
        $fieldOther = $row('series_custom_field', "series_id = {$seriesBId} AND field_key = 'other_metric'");
        $t->truth($fieldProduct !== null && $fieldMetadata !== null && $fieldOther !== null);
        $t->truth((int) $fieldProduct['id'] !== (int) $fieldMetadata['id']);
        $schemaA = (new CatalogV1Service($db))->fields($seriesAPath);
        $schemaB = (new CatalogV1Service($db))->fields($seriesBPath);
        $t->same(['shared_value', 'surface_code'], array_column($schemaA['columns'], 'field_key'));
        $t->same(['other_metric', 'material_class'], array_column($schemaB['columns'], 'field_key'));
        $t->same('runtime-series-metadata', $row('series_custom_field_value', 'series_id = ? AND series_custom_field_id = ?', [$seriesAId, (int) $fieldMetadata['id']])['value']);

        $sku = 'RUNTIME-PART-' . strtoupper($tag);
        $product = $row('product', 'series_id = ? AND sku = ?', [$seriesAId, $sku]);
        $partAsset = $row('catalog_asset', "owner_type = 'product' AND owner_id = ? AND asset_key = 'runtime-part-document'", [(int) $product['id']]);
        $seriesAsset = $row('catalog_asset', "owner_type = 'series' AND owner_id = ? AND asset_key = 'runtime-series-image'", [$seriesAId]);
        $seriesBlock = $row('catalog_content_block', "owner_type = 'series' AND owner_id = ? AND block_key = 'runtime-series-section'", [$seriesAId]);
        $partBlock = $row('catalog_content_block', "owner_type = 'product' AND owner_id = ? AND block_key = 'runtime-part-section'", [(int) $product['id']]);
        $collectionKey = 'runtime-collection-' . $tag;
        $collection = $row('catalog_collection', 'collection_key = ?', [$collectionKey]);
        $item = $row('catalog_collection_item', "collection_id = ? AND item_key = 'runtime-item-a'", [(int) $collection['id']]);
        $aliasPath = $root . '/runtime-shortcut';
        $alias = $row('catalog_path_alias', 'path = ?', [$aliasPath]);
        $t->truth($partAsset !== null && $seriesAsset !== null && $seriesBlock !== null && $partBlock !== null && $collection !== null && $item !== null && $alias !== null);
        $blockPayload = json_decode((string) $seriesBlock['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $t->same((int) $seriesAsset['id'], $blockPayload['image']['asset_id']);
        $partPayload = json_decode((string) $partBlock['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        $t->same((int) $partAsset['id'], $partPayload['document']['asset_id']);
        $t->same((int) $seriesAsset['id'], (int) $item['asset_id']);

        $stableIds = [
            'series_a' => $seriesAId, 'field_product' => (int) $fieldProduct['id'], 'field_metadata' => (int) $fieldMetadata['id'],
            'product' => (int) $product['id'], 'series_asset' => (int) $seriesAsset['id'], 'part_asset' => (int) $partAsset['id'],
            'series_block' => (int) $seriesBlock['id'], 'part_block' => (int) $partBlock['id'], 'collection' => (int) $collection['id'],
            'collection_item' => (int) $item['id'],
        ];
        $updated = $buildMergeManifest($tag, true, false);
        $importer->import($updated);
        $seriesAAfter = $row('category', 'id = ?', [$seriesAId]);
        $t->same('Runtime Series A Renamed', $seriesAAfter['name']);
        $t->same('runtime-series-a', $seriesAAfter['slug']);
        $resolved = (new CatalogV1Service($db))->resolve($seriesAPath);
        $t->same($seriesAPath, $resolved['resource']['hierarchy_path']);
        $t->same('Runtime Series A Renamed', $resolved['resource']['title']);
        $t->same($aliasPath, $resolved['resource']['path']);
        $t->same($seriesAId, (int) $row('category', 'slug = ?', ['runtime-series-a'])['id']);
        $t->same($seriesBId, (int) $row('category', 'slug = ?', ['runtime-series-b'])['id']);
        $t->same($stableIds['field_product'], (int) $row('series_custom_field', "series_id = ? AND field_key = 'shared_value' AND field_scope = 'product_attribute'", [$seriesAId])['id']);
        $t->same($stableIds['field_metadata'], (int) $row('series_custom_field', "series_id = ? AND field_key = 'shared_value' AND field_scope = 'series_metadata'", [$seriesAId])['id']);
        $t->same('Updated Part Value', $row('series_custom_field', "series_id = ? AND field_key = 'shared_value' AND field_scope = 'product_attribute'", [$seriesAId])['label']);
        $productAfter = $row('product', 'series_id = ? AND sku = ?', [$seriesAId, $sku]);
        $t->same($stableIds['product'], (int) $productAfter['id']);
        $t->same('Updated Runtime Part', $productAfter['name']);
        $t->same('14.0', $row('product_custom_field_value', 'product_id = ? AND series_custom_field_id = ?', [(int) $productAfter['id'], $stableIds['field_product']])['value']);
        $t->same($stableIds['series_asset'], (int) $row('catalog_asset', "owner_type = 'series' AND owner_id = ? AND asset_key = 'runtime-series-image'", [$seriesAId])['id']);
        $t->same($stableIds['part_asset'], (int) $row('catalog_asset', "owner_type = 'product' AND owner_id = ? AND asset_key = 'runtime-part-document'", [(int) $productAfter['id']])['id']);
        $t->same('Updated Series Asset', $row('catalog_asset', "owner_type = 'series' AND owner_id = ? AND asset_key = 'runtime-series-image'", [$seriesAId])['title']);
        $t->same($stableIds['series_block'], (int) $row('catalog_content_block', "owner_type = 'series' AND owner_id = ? AND block_key = 'runtime-series-section'", [$seriesAId])['id']);
        $t->same($stableIds['part_block'], (int) $row('catalog_content_block', "owner_type = 'product' AND owner_id = ? AND block_key = 'runtime-part-section'", [(int) $productAfter['id']])['id']);
        $t->same($stableIds['collection'], (int) $row('catalog_collection', 'collection_key = ?', [$collectionKey])['id']);
        $t->same('Updated Collection', $row('catalog_collection', 'collection_key = ?', [$collectionKey])['title']);
        $itemAfter = $row('catalog_collection_item', "collection_id = ? AND item_key = 'runtime-item-a'", [$stableIds['collection']]);
        $t->same($stableIds['collection_item'], (int) $itemAfter['id']);
        $t->same('Updated Item A', $itemAfter['title']);
        $t->same((int) $row('catalog_asset', "owner_type = 'series' AND owner_id = ? AND asset_key = 'runtime-series-image'", [$seriesAId])['id'], (int) $itemAfter['asset_id']);
        $t->same((int) $alias['node_id'], (int) $row('catalog_path_alias', 'path = ?', [$aliasPath])['node_id']);

        $t->truth($row('category', 'slug = ?', ['runtime-retained-series']) !== null);
        $t->truth($row('series_custom_field', "series_id = ? AND field_key = 'retained_metric'", [(int) $seriesRetained['id']]) !== null);
        $t->truth($row('product', 'series_id = ? AND sku = ?', [(int) $seriesRetained['id'], 'RUNTIME-RETAINED-' . strtoupper($tag)]) !== null);
        $t->truth($row('catalog_asset', "owner_type = 'series' AND owner_id = ? AND asset_key = 'retained-asset'", [(int) $seriesRetained['id']]) !== null);
        $t->truth($row('catalog_content_block', "owner_type = 'series' AND owner_id = ? AND block_key = 'retained-block'", [(int) $seriesRetained['id']]) !== null);
        $t->truth($row('catalog_collection_item', "collection_id = ? AND item_key = 'runtime-item-b'", [$stableIds['collection']]) !== null);
        $t->truth($row('catalog_collection_item', "collection_id = ? AND item_key = 'runtime-item-retained'", [$stableIds['collection']]) !== null);
        $t->same('runtime-series-metadata', $row('series_custom_field_value', 'series_id = ? AND series_custom_field_id = ?', [$seriesAId, $stableIds['field_metadata']])['value']);

        $storage = Config::get('app')['storage']['media'];
        $t->truth(!file_exists($storage . '/runtime-only/' . $tag), 'Importer must not create asset directories or files.');
        $t->truth(!file_exists($storage . '/runtime-only/' . $tag . '/series.webp'));
    });

    $t->test('new records default to unpublished and private until explicitly exposed', static function () use ($t, $db, $importer, $row): void {
        $tag = bin2hex(random_bytes(3));
        $root = 'runtime-draft-' . $tag;
        $seriesPath = $root . '/runtime-draft-series';
        $importer->import(['version' => 'public-product-api-v1', 'nodes' => [
            ['path' => $root, 'name' => 'Runtime Draft Root', 'type' => 'category'],
            ['path' => $seriesPath, 'name' => 'Runtime Draft Series', 'type' => 'series',
                'fields' => [['field_key' => 'draft_value', 'label' => 'Draft Value']],
                'parts' => [['sku' => 'RUNTIME-DRAFT-' . strtoupper($tag)]],
                'assets' => [['asset_key' => 'draft-asset', 'role' => 'image', 'file_path' => 'runtime-only/' . $tag . '/draft.webp', 'mime_type' => 'image/webp']],
                'blocks' => [['block_key' => 'draft-block', 'block_type' => 'feature_list']],
            ],
        ], 'collections' => [['collection_key' => 'runtime-draft-collection-' . $tag, 'items' => [['item_key' => 'draft-item', 'node_path' => $seriesPath]]]]]);
        $series = $row('category', 'slug = ?', ['runtime-draft-series']);
        $rootRow = $row('category', 'slug = ?', ['runtime-draft-' . $tag]);
        $field = $row('series_custom_field', 'series_id = ? AND field_key = ?', [(int) $series['id'], 'draft_value']);
        $product = $row('product', 'series_id = ? AND sku = ?', [(int) $series['id'], 'RUNTIME-DRAFT-' . strtoupper($tag)]);
        $asset = $row('catalog_asset', "owner_type = 'series' AND owner_id = ? AND asset_key = 'draft-asset'", [(int) $series['id']]);
        $block = $row('catalog_content_block', "owner_type = 'series' AND owner_id = ? AND block_key = 'draft-block'", [(int) $series['id']]);
        $collection = $row('catalog_collection', 'collection_key = ?', ['runtime-draft-collection-' . $tag]);
        $item = $row('catalog_collection_item', "collection_id = ? AND item_key = 'draft-item'", [(int) $collection['id']]);
        $t->same(0, (int) $rootRow['is_published']);
        $t->same(0, (int) $series['is_published']);
        $t->same(1, (int) $field['is_public_portal_hidden']);
        $t->same(0, (int) $product['is_published']);
        $t->same(0, (int) $asset['is_public']);
        $t->same(0, (int) $block['is_public']);
        $t->same(0, (int) $collection['is_public']);
        $t->same(0, (int) $item['is_public']);
        $t->throws(static fn () => (new CatalogV1Service($db))->resolve($seriesPath), 404);
    });

    $t->test('an alias that shadows a series subresource rolls the complete import back', static function () use ($t, $importer, $base, $count): void {
        $tag = bin2hex(random_bytes(3));
        $manifest = $base($tag);
        $path = $manifest['nodes'][3]['path'];
        $manifest['aliases'] = [['path' => $path . '/parts', 'node_path' => $path]];
        $before = [$count('category'), $count('product'), $count('catalog_path_alias')];
        $t->throws(static fn () => $importer->import($manifest), 503);
        $t->same($before, [$count('category'), $count('product'), $count('catalog_path_alias')]);
    });

    $t->test('invalid manifest shapes, paths, keys, types, URLs, flags, and references are rejected transactionally', static function () use ($t, $importer, $base, $count): void {
        $cases = [];
        $cases['node collection shape'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'] = ['named-record' => $manifest['nodes'][0]];
            return $manifest;
        };
        $cases['record object shape'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][0] = ['runtime-path-only'];
            return $manifest;
        };
        $cases['field collection shape'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['fields'] = ['named-field' => ['field_key' => 'valid_key', 'label' => 'Valid Label']];
            return $manifest;
        };
        $cases['blank field key'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['fields'][0]['field_key'] = ' ';
            return $manifest;
        };
        $cases['overlong field key'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['fields'][0]['field_key'] = str_repeat('k', 65);
            return $manifest;
        };
        $cases['unsupported field type'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['fields'][0]['field_type'] = 'structured';
            return $manifest;
        };
        $cases['unsupported field scope'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['fields'][0]['field_scope'] = 'unrecognized_scope';
            return $manifest;
        };
        $cases['range filter on a text field'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['fields'][0]['filter_type'] = 'range';
            return $manifest;
        };
        $cases['unknown metadata key'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['metadata'] = ['missing_field' => 'runtime'];
            return $manifest;
        };
        $cases['metadata object shape'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['metadata'] = ['not-an-object'];
            return $manifest;
        };
        $cases['unknown product value key'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['parts'][0]['values'] = ['missing_field' => 'runtime'];
            return $manifest;
        };
        $cases['invalid hierarchy path'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][1]['path'] = 'runtime-import-' . $tag . '//runtime-branch';
            return $manifest;
        };
        $cases['invalid target URL'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][0]['target_url'] = 'javascript:alert(1)';
            return $manifest;
        };
        $cases['invalid visibility flag'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][0]['is_published'] = 'yes';
            return $manifest;
        };
        $cases['invalid asset traversal path'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['assets'] = [['asset_key' => 'unsafe-asset', 'role' => 'image', 'file_path' => '../outside.webp', 'mime_type' => 'image/webp']];
            return $manifest;
        };
        $cases['invalid external asset URL'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['assets'] = [['asset_key' => 'unsafe-external', 'role' => 'image', 'storage_disk' => 'external', 'file_path' => 'http://example.invalid/image.webp', 'mime_type' => 'image/webp']];
            return $manifest;
        };
        $cases['invalid structured metadata shape'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['nodes'][3]['assets'] = [['asset_key' => 'bad-metadata', 'role' => 'image', 'file_path' => 'runtime-image.webp', 'mime_type' => 'image/webp', 'metadata' => 'scalar']];
            return $manifest;
        };
        $cases['unknown alias target'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['aliases'] = [['path' => 'runtime-import-' . $tag . '/runtime-alias', 'node_path' => 'runtime-import-' . $tag . '/missing-node']];
            return $manifest;
        };
        $cases['invalid alias path'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['aliases'] = [['path' => 'runtime-import-' . $tag . '//runtime-alias', 'node_path' => 'runtime-import-' . $tag . '/runtime-branch/runtime-family/runtime-series']];
            return $manifest;
        };
        $cases['overlong alias path'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['aliases'] = [['path' => implode('/', array_fill(0, 4, str_repeat('x', 190))),
                'node_path' => $manifest['nodes'][3]['path']]];
            return $manifest;
        };
        $cases['unknown manifest property'] = static function (string $tag) use ($base): array {
            $manifest = $base($tag);
            $manifest['unrecognized'] = true;
            return $manifest;
        };

        foreach ($cases as $name => $make) {
            $tag = bin2hex(random_bytes(3));
            $before = $count('category');
            $t->throws(static fn () => $importer->import($make($tag)), 422);
            $t->same($before, $count('category'));
        }
    });
};
