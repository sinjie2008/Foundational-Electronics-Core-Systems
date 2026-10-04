<?php
declare(strict_types=1);

use CatalogSuite\Repositories\CatalogCsvRepository;
use CatalogSuite\Repositories\HierarchyRepository;
use CatalogSuite\Repositories\ProductRepository;
use CatalogSuite\Services\CatalogTruncateService;
use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Support\Config;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $newNode = static function (string $slug, string $name, string $type = 'category', ?int $parent = null, ?int $id = null) use ($db): int {
        $values = ['name' => $name, 'slug' => $slug, 'type' => $type, 'parent_id' => $parent, 'is_published' => 1];
        if ($id !== null) {
            $values = ['id' => $id, ...$values];
        }
        return Fixtures::insert($db, 'category', $values);
    };
    $newProduct = static function (int $seriesId, string $sku, ?int $id = null) use ($db): int {
        $values = ['series_id' => $seriesId, 'sku' => $sku, 'name' => 'Runtime cleanup product', 'is_published' => 1];
        if ($id !== null) {
            $values = ['id' => $id, ...$values];
        }
        return Fixtures::insert($db, 'product', $values);
    };
    $addContent = static function (string $ownerType, int $ownerId, string $key, ?string $filePath = null) use ($db): array {
        $assetId = Fixtures::insert($db, 'catalog_asset', [
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'asset_key' => 'runtime-asset-' . $key,
            'role' => 'runtime_image', 'storage_disk' => 'media', 'file_path' => $filePath ?? ('runtime-cleanup/' . $key . '.bin'),
            'mime_type' => 'application/octet-stream', 'title' => 'Runtime cleanup asset', 'is_public' => 1,
        ]);
        $blockId = Fixtures::insert($db, 'catalog_content_block', [
            'owner_type' => $ownerType, 'owner_id' => $ownerId, 'block_key' => 'runtime-block-' . $key,
            'block_type' => 'feature_list', 'title' => 'Runtime cleanup block', 'payload_json' => '{"items":["runtime"]}', 'is_public' => 1,
        ]);
        return ['asset' => $assetId, 'block' => $blockId];
    };
    $ownerCount = static function (string $table, string $ownerType, int $ownerId) use ($db): int {
        $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE owner_type = ? AND owner_id = ?");
        $stmt->execute([$ownerType, $ownerId]);
        $count = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $count;
    };
    $tableCount = static function (string $table) use ($db): int {
        return (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetch_row()[0];
    };

    $t->test('hierarchy subtree deletion removes polymorphic descendants but keeps unrelated owners and files', static function () use ($t, $db, $newNode, $newProduct, $addContent, $ownerCount): void {
        $tag = bin2hex(random_bytes(3));
        $root = $newNode('runtime-delete-root-' . $tag, 'Runtime Delete Root');
        $child = $newNode('runtime-delete-child-' . $tag, 'Runtime Delete Child', 'category', $root);
        $series = $newNode('runtime-delete-series-' . $tag, 'Runtime Delete Series', 'series', $child);
        $product = $newProduct($series, 'RUNTIME-DELETE-PRODUCT-' . strtoupper($tag));
        $otherRoot = $newNode('runtime-keep-root-' . $tag, 'Runtime Keep Root');
        $otherSeries = $newNode('runtime-keep-series-' . $tag, 'Runtime Keep Series', 'series', $otherRoot);
        $otherProduct = $newProduct($otherSeries, 'RUNTIME-KEEP-PRODUCT-' . strtoupper($tag));
        $media = Config::get('app')['storage']['media'];
        $relativeFile = 'runtime-cleanup/' . $tag . '/existing-image.bin';
        $filePath = $media . '/' . $relativeFile;
        mkdir(dirname($filePath), 0700, true);
        file_put_contents($filePath, 'runtime media must survive row cleanup');

        $deletedOwners = [
            ['category', $root], ['category', $child], ['series', $series], ['product', $product],
        ];
        $assetForSeries = null;
        foreach ($deletedOwners as [$type, $id]) {
            $content = $addContent($type, $id, $tag . '-' . $type . '-' . $id, $type === 'series' ? $relativeFile : null);
            if ($type === 'series') {
                $assetForSeries = $content['asset'];
            }
        }
        foreach ([['category', $otherRoot], ['series', $otherSeries], ['product', $otherProduct]] as [$type, $id]) {
            $addContent($type, $id, $tag . '-unrelated-' . $type . '-' . $id);
        }
        $aliasPath = 'runtime-delete-alias-' . $tag;
        Fixtures::insert($db, 'catalog_path_alias', ['path' => $aliasPath, 'node_id' => $series]);
        $collection = Fixtures::insert($db, 'catalog_collection', ['collection_key' => 'runtime-delete-collection-' . $tag, 'title' => 'Runtime deletion collection', 'is_public' => 1]);
        Fixtures::insert($db, 'catalog_collection_item', ['collection_id' => $collection, 'item_key' => 'runtime-delete-item', 'node_id' => $series,
            'asset_id' => $assetForSeries, 'title' => 'Runtime deletion item', 'is_public' => 1]);

        (new HierarchyRepository($db))->deleteNode($root);
        foreach ($deletedOwners as [$type, $id]) {
            $t->same(0, $ownerCount('catalog_asset', $type, $id));
            $t->same(0, $ownerCount('catalog_content_block', $type, $id));
        }
        foreach ([['category', $otherRoot], ['series', $otherSeries], ['product', $otherProduct]] as [$type, $id]) {
            $t->same(1, $ownerCount('catalog_asset', $type, $id));
            $t->same(1, $ownerCount('catalog_content_block', $type, $id));
        }
        $t->truth(is_file($filePath), 'Deleting catalog rows must not delete the referenced media file.');
        $t->same(0, (int) $db->query("SELECT COUNT(*) FROM catalog_path_alias WHERE path = '{$aliasPath}'")->fetch_row()[0]);
        $t->same(0, (int) $db->query("SELECT COUNT(*) FROM catalog_collection_item WHERE collection_id = {$collection}")->fetch_row()[0]);

        // Reuse the deleted IDs explicitly to verify old polymorphic rows and memberships cannot attach to new owners.
        $replacementRoot = $newNode('runtime-replacement-root-' . $tag, 'Runtime Replacement Root', 'category', null, $root);
        $replacementSeries = $newNode('runtime-replacement-series-' . $tag, 'Runtime Replacement Series', 'series', $replacementRoot, $series);
        $replacementProduct = $newProduct($replacementSeries, 'RUNTIME-REPLACEMENT-' . strtoupper($tag), $product);
        $api = new CatalogV1Service($db);
        $seriesPage = $api->series('runtime-replacement-root-' . $tag . '/runtime-replacement-series-' . $tag);
        $t->same([], $seriesPage['assets']);
        $t->same([], $seriesPage['blocks']);
        $partPage = $api->parts('runtime-replacement-root-' . $tag . '/runtime-replacement-series-' . $tag, []);
        $t->truth(count($partPage['rows']) === 1, 'Expected one replacement part row, got ' . count($partPage['rows']) . ': ' . json_encode($partPage['rows']));
        $t->same($replacementProduct, (int) $partPage['rows'][0]['id']);
        $t->same([], $partPage['rows'][0]['assets']);
        $t->same([], $partPage['rows'][0]['blocks']);
        $collectionRecord = $api->collections('runtime-delete-collection-' . $tag);
        $t->same([], $collectionRecord['items']);
        $t->throws(static fn () => $api->resolve($aliasPath), 404);
        $t->truth(is_file($filePath), 'The media file must remain after ID reuse as well.');
    });

    $t->test('product and CSV product pruning remove only deleted product presentation rows', static function () use ($t, $db, $newNode, $newProduct, $addContent, $ownerCount): void {
        $tag = bin2hex(random_bytes(3));
        $root = $newNode('runtime-product-delete-root-' . $tag, 'Runtime Product Delete Root');
        $series = $newNode('runtime-product-delete-series-' . $tag, 'Runtime Product Delete Series', 'series', $root);
        $product = $newProduct($series, 'RUNTIME-REPOSITORY-DELETE-' . strtoupper($tag));
        $csvProduct = $newProduct($series, 'RUNTIME-CSV-DELETE-' . strtoupper($tag));
        $unrelatedRoot = $newNode('runtime-product-keep-root-' . $tag, 'Runtime Product Keep Root');
        $unrelatedSeries = $newNode('runtime-product-keep-series-' . $tag, 'Runtime Product Keep Series', 'series', $unrelatedRoot);
        $unrelatedProduct = $newProduct($unrelatedSeries, 'RUNTIME-PRODUCT-KEEP-' . strtoupper($tag));
        $seriesContent = $addContent('series', $series, $tag . '-series');
        $productContent = $addContent('product', $product, $tag . '-product');
        $csvProductContent = $addContent('product', $csvProduct, $tag . '-csv-product');
        $unrelatedContent = $addContent('product', $unrelatedProduct, $tag . '-unrelated-product');
        (new ProductRepository($db))->deleteProduct($product);
        $t->same(0, $ownerCount('catalog_asset', 'product', $product));
        $t->same(0, $ownerCount('catalog_content_block', 'product', $product));
        $t->same(1, $ownerCount('catalog_asset', 'series', $series));
        $t->same(1, $ownerCount('catalog_content_block', 'series', $series));
        $t->same(0, (int) $db->query('SELECT COUNT(*) FROM catalog_asset WHERE id = ' . (int) $productContent['asset'])->fetch_row()[0]);

        (new CatalogCsvRepository($db))->deleteProducts([$csvProduct]);
        $t->same(0, $ownerCount('catalog_asset', 'product', $csvProduct));
        $t->same(0, $ownerCount('catalog_content_block', 'product', $csvProduct));
        $t->same(0, (int) $db->query('SELECT COUNT(*) FROM catalog_asset WHERE id = ' . (int) $csvProductContent['asset'])->fetch_row()[0]);
        $t->same(1, $ownerCount('catalog_asset', 'series', $series));
        $t->same(1, $ownerCount('catalog_content_block', 'series', $series));
        $t->truth($seriesContent['asset'] > 0);
        $t->same(1, $ownerCount('catalog_asset', 'product', $unrelatedProduct));
        $t->same(1, $ownerCount('catalog_content_block', 'product', $unrelatedProduct));
        $t->same(1, (int) $db->query('SELECT COUNT(*) FROM catalog_asset WHERE id = ' . (int) $unrelatedContent['asset'])->fetch_row()[0]);
    });

    $t->test('CSV series and category pruning cleans descendant owners and retains unrelated presentation', static function () use ($t, $db, $newNode, $newProduct, $addContent, $ownerCount): void {
        $tag = bin2hex(random_bytes(3));
        $parent = $newNode('runtime-csv-parent-' . $tag, 'Runtime CSV Parent');
        $branch = $newNode('runtime-csv-branch-' . $tag, 'Runtime CSV Branch', 'category', $parent);
        $series = $newNode('runtime-csv-series-' . $tag, 'Runtime CSV Series', 'series', $branch);
        $product = $newProduct($series, 'RUNTIME-CSV-SERIES-PART-' . strtoupper($tag));
        $unrelatedRoot = $newNode('runtime-csv-unrelated-' . $tag, 'Runtime CSV Unrelated');
        $unrelatedSeries = $newNode('runtime-csv-unrelated-series-' . $tag, 'Runtime CSV Unrelated Series', 'series', $unrelatedRoot);
        $unrelatedProduct = $newProduct($unrelatedSeries, 'RUNTIME-CSV-UNRELATED-' . strtoupper($tag));
        foreach ([['category', $parent], ['category', $branch], ['series', $series], ['product', $product]] as [$type, $id]) {
            $addContent($type, $id, $tag . '-series-prune-' . $type . '-' . $id);
        }
        foreach ([['category', $unrelatedRoot], ['series', $unrelatedSeries], ['product', $unrelatedProduct]] as [$type, $id]) {
            $addContent($type, $id, $tag . '-csv-retained-' . $type . '-' . $id);
        }
        $repository = new CatalogCsvRepository($db);
        $repository->deleteSeries([$series]);
        foreach ([['series', $series], ['product', $product]] as [$type, $id]) {
            $t->same(0, $ownerCount('catalog_asset', $type, $id));
            $t->same(0, $ownerCount('catalog_content_block', $type, $id));
        }
        $t->same(1, $ownerCount('catalog_asset', 'category', $parent));
        $t->same(1, $ownerCount('catalog_asset', 'category', $branch));
        $t->same(1, $ownerCount('catalog_content_block', 'category', $parent));

        $series2 = $newNode('runtime-csv-category-series-' . $tag, 'Runtime CSV Category Series', 'series', $branch);
        $product2 = $newProduct($series2, 'RUNTIME-CSV-CATEGORY-PART-' . strtoupper($tag));
        foreach ([['category', $branch], ['series', $series2], ['product', $product2]] as [$type, $id]) {
            $addContent($type, $id, $tag . '-category-prune-' . $type . '-' . $id);
        }
        $repository->deleteCategories([$parent]);
        foreach ([['category', $parent], ['category', $branch], ['series', $series2], ['product', $product2]] as [$type, $id]) {
            $t->same(0, $ownerCount('catalog_asset', $type, $id));
            $t->same(0, $ownerCount('catalog_content_block', $type, $id));
        }
        foreach ([['category', $unrelatedRoot], ['series', $unrelatedSeries], ['product', $unrelatedProduct]] as [$type, $id]) {
            $t->same(1, $ownerCount('catalog_asset', $type, $id));
            $t->same(1, $ownerCount('catalog_content_block', $type, $id));
        }
    });

    $t->test('truncate clears every V1 table while preserving the legacy count response and does not reseed', static function () use ($t, $db, $newNode, $newProduct, $addContent, $tableCount): void {
        $tag = bin2hex(random_bytes(3));
        $root = $newNode('runtime-truncate-root-' . $tag, 'Runtime Truncate Root');
        $series = $newNode('runtime-truncate-series-' . $tag, 'Runtime Truncate Series', 'series', $root);
        $product = $newProduct($series, 'RUNTIME-TRUNCATE-PRODUCT-' . strtoupper($tag));
        $seriesCustomField = Fixtures::insert($db, 'series_custom_field', ['series_id' => $series, 'field_key' => 'runtime_truncate_field', 'label' => 'Runtime truncate field']);
        Fixtures::insert($db, 'series_custom_field_value', ['series_id' => $series, 'series_custom_field_id' => $seriesCustomField, 'value' => 'runtime metadata']);
        Fixtures::insert($db, 'product_custom_field_value', ['product_id' => $product, 'series_custom_field_id' => $seriesCustomField, 'value' => 'runtime product value']);
        $catalogContent = $addContent('catalog', 0, $tag . '-catalog');
        $categoryContent = $addContent('category', $root, $tag . '-category');
        $seriesContent = $addContent('series', $series, $tag . '-series');
        $productContent = $addContent('product', $product, $tag . '-product');
        $alias = 'runtime-truncate-alias-' . $tag;
        Fixtures::insert($db, 'catalog_path_alias', ['path' => $alias, 'node_id' => $series]);
        $collection = Fixtures::insert($db, 'catalog_collection', ['collection_key' => 'runtime-truncate-collection-' . $tag, 'title' => 'Runtime truncate collection', 'is_public' => 1]);
        Fixtures::insert($db, 'catalog_collection_item', ['collection_id' => $collection, 'item_key' => 'runtime-truncate-item', 'node_id' => $series,
            'asset_id' => $seriesContent['asset'], 'is_public' => 1]);

        $expectedLegacyCounts = [
            'categories' => (int) $db->query("SELECT COUNT(*) FROM category WHERE type = 'category'")->fetch_row()[0],
            'series' => (int) $db->query("SELECT COUNT(*) FROM category WHERE type = 'series'")->fetch_row()[0],
            'products' => (int) $db->query('SELECT COUNT(*) FROM product')->fetch_row()[0],
            'fieldDefinitions' => (int) $db->query('SELECT COUNT(*) FROM series_custom_field')->fetch_row()[0],
            'productValues' => (int) $db->query('SELECT COUNT(*) FROM product_custom_field_value')->fetch_row()[0],
            'seriesValues' => (int) $db->query('SELECT COUNT(*) FROM series_custom_field_value')->fetch_row()[0],
        ];
        $auditPath = Config::get('app')['truncate']['audit_log'];
        $result = (new CatalogTruncateService($db, $auditPath))->truncateCatalog([
            'reason' => 'Runtime isolated cleanup regression test', 'confirmToken' => 'TRUNCATE', 'correlationId' => 'runtime-cleanup-' . $tag,
        ]);
        $t->same($expectedLegacyCounts, array_intersect_key($result['deleted'], $expectedLegacyCounts));
        foreach (array_keys($expectedLegacyCounts) as $key) {
            $t->truth(array_key_exists($key, $result['deleted']), 'Legacy truncate response key is missing: ' . $key);
        }
        foreach (['catalog_path_alias', 'catalog_collection_item', 'catalog_collection', 'catalog_content_block', 'catalog_asset'] as $table) {
            $t->same(0, $tableCount($table));
        }
        foreach (['category', 'product', 'series_custom_field', 'series_custom_field_value', 'product_custom_field_value', 'seed_migration'] as $table) {
            $t->same(0, $tableCount($table));
        }
        $t->same(0, (int) $db->query("SELECT COUNT(*) FROM catalog_asset WHERE id IN ({$catalogContent['asset']}, {$categoryContent['asset']}, {$seriesContent['asset']}, {$productContent['asset']})")->fetch_row()[0]);
    });
};
