<?php
declare(strict_types=1);

use CatalogSuite\Controllers\PublicCatalogV1Controller;
use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Http\Transport;
use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Support\Config;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $f = Fixtures::catalog($db);
    $api = static fn (): CatalogV1Service => new CatalogV1Service($db);
    $asset = static function (string $type, int $owner, string $key, string $role, string $mime = 'image/png') use ($db): int {
        return Fixtures::insert($db, 'catalog_asset', ['owner_type' => $type, 'owner_id' => $owner, 'asset_key' => $key, 'role' => $role,
            'storage_disk' => 'external', 'file_path' => 'https://files.example.invalid/' . $key, 'mime_type' => $mime, 'is_public' => 1]);
    };
    $t->test('each public field exposes its own sanitized stored presentation configuration', static function () use ($t, $db, $api, $f): void {
        $stmt = $db->prepare('UPDATE series_custom_field SET config_json = ? WHERE id = ?');
        $stmt->execute(['{"format":"decimal","precision":3,"file_path":"private","reference":{"field_key":"secret"}}', $f['fields']['a_scalar']]);
        $stmt->execute(['{"format":"badge","align":"center"}', $f['fields']['b_other']]);
        $a = Fixtures::plain($api()->fields($f['pathA']))['fields'][0]['config'];
        $b = Fixtures::plain($api()->fields($f['pathB']))['fields'][0]['config'];
        $t->same(['format' => 'decimal', 'precision' => 3], $a);
        $t->equivalent(['format' => 'badge', 'align' => 'center'], $b);
    });
    $t->test('category section images and item thumbnails use arbitrary stored asset roles', static function () use ($t, $asset, $api, $f): void {
        $section = $asset('category', $f['familyA'], 'runtime-section-visual', 'runtime-section-role');
        $thumbnail = $asset('series', $f['seriesA'], 'runtime-item-visual', 'runtime-thumbnail-role');
        $category = $api()->category('runtime-root/runtime-group');
        $a = $category['sections'][1];
        $t->same($section, $a['assets'][0]['id']);
        $t->same('runtime-section-role', $a['assets'][0]['role']);
        $t->same($thumbnail, $a['children'][0]['assets'][0]['id']);
        $t->same('/products/' . $f['pathA'], $a['children'][0]['url']);
    });
    $t->test('series gallery model drawing curve and document references retain configured ordering', static function () use ($t, $db, $api, $f, $asset): void {
        $image = $asset('series', $f['seriesA'], 'runtime-gallery-visual', 'runtime-gallery-role');
        $model = $asset('series', $f['seriesA'], 'runtime-model', 'runtime-model-role', 'model/gltf-binary');
        $drawing = $asset('series', $f['seriesA'], 'runtime-drawing', 'runtime-drawing-role', 'image/svg+xml');
        $curve = $asset('series', $f['seriesA'], 'runtime-curve', 'runtime-curve-role');
        $document = $asset('series', $f['seriesA'], 'runtime-download', 'runtime-document-role', 'application/pdf');
        $db->query("UPDATE catalog_asset SET is_download = 1 WHERE id = {$document}");
        Fixtures::insert($db, 'catalog_content_block', ['owner_type' => 'series', 'owner_id' => $f['seriesA'], 'block_key' => 'runtime-media-section',
            'block_type' => 'image_gallery', 'title' => 'Stored media section title', 'display_order' => 40, 'is_public' => 1,
            'payload_json' => json_encode(['items' => [['asset_id' => $model], ['asset_id' => $image], ['asset_id' => $curve], ['asset_id' => $drawing]]], JSON_THROW_ON_ERROR)]);
        $series = Fixtures::plain($api()->series($f['pathA']));
        $block = $series['blocks'][3];
        $t->same([$model, $image, $curve, $drawing], array_column($block['payload']['items'], 'id'));
        $t->same([$document], array_column($series['documents'], 'id'));
        $t->same('runtime-media-section', $series['navigation'][3]['key']);
    });
    $t->test('family summary schemas can select arbitrary public asset roles', static function () use ($t, $db, $api, $f): void {
        $db->query("UPDATE catalog_content_block SET payload_json = '{\"columns\":[{\"key\":\"configured-media\",\"source\":\"asset\",\"role\":\"runtime-gallery-role\",\"label\":\"Stored media label\"}]}' WHERE owner_type = 'category' AND owner_id = {$f['familyA']}");
        $table = Fixtures::plain($api()->category($f['familyPathA']))['blocks'][0]['payload'];
        $t->same('configured-media', $table['columns'][0]['key']);
        $t->same('runtime-gallery-role', $table['rows'][0]['values']['configured-media'][0]['role']);
        $t->same('Stored media label', $table['columns'][0]['label']);
    });
    $t->test('collection cards supply public image title subtitle URL and configured browsing settings', static function () use ($t, $db, $api, $f): void {
        $image = $db->query("SELECT id FROM catalog_asset WHERE owner_id = {$f['seriesA']} AND asset_key = 'runtime-gallery-visual'")->fetch_row()[0];
        $db->query("UPDATE catalog_collection_item SET asset_id = {$image}, title = 'Stored card title', subtitle = 'Stored card subtitle' WHERE node_id = {$f['seriesA']}");
        $collection = $api()->collections('runtime-collection');
        $t->same((int) $image, $collection['items'][1]['image']['id']);
        $t->same('Stored card title', $collection['items'][1]['title']);
        $t->same('Stored card subtitle', $collection['items'][1]['subtitle']);
        $t->same('/products/' . $f['pathA'], $collection['items'][1]['url']);
        $t->equivalent(['visible_count' => 3, 'step' => 2], (array) $collection['config']);
    });
    $t->test('enquiry and download actions carry stored selection metadata beside part row identity', static function () use ($t, $db, $api, $f): void {
        $payload = ['actions' => [['key' => 'runtime-enquiry', 'label' => 'Stored enquiry label', 'url' => '/runtime-enquiry',
            'method' => 'GET', 'selection' => ['source' => 'parts', 'value_key' => 'sku', 'query_key' => 'selected_parts', 'multiple' => true]]]];
        Fixtures::insert($db, 'catalog_content_block', ['owner_type' => 'series', 'owner_id' => $f['seriesB'], 'block_key' => 'runtime-action-section',
            'block_type' => 'cta', 'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'is_public' => 1, 'display_order' => 100]);
        $series = Fixtures::plain($api()->series($f['pathB']));
        $t->equivalent($payload, $series['blocks'][1]['payload']);
        $parts = $api()->parts($f['pathB'], []);
        $t->same(['key' => 'id', 'part_number_key' => 'sku'], $parts['schema']['row_identity']);
        $t->same('TEST-DISTINCT-PART', $parts['rows'][0]['sku']);
    });
    $t->test('product content blocks resolve public part attributes without leaking private references', static function () use ($t, $db, $api, $f): void {
        Fixtures::insert($db, 'catalog_content_block', ['owner_type' => 'product', 'owner_id' => $f['partB'], 'block_key' => 'runtime-part-detail',
            'block_type' => 'key_value', 'is_public' => 1, 'payload_json' => '{"entries":[{"field_key":"b_other","scope":"product_attribute"},{"field_key":"nonexistent","scope":"product_attribute"}]}']);
        $parts = Fixtures::plain($api()->parts($f['pathB'], []));
        $t->same(1, count($parts['rows'][0]['blocks'][0]['payload']['entries']));
        $t->same('25.5', $parts['rows'][0]['blocks'][0]['payload']['entries'][0]['value']);
    });
    $t->test('top-level references use the same visibility rules as nested references', static function () use ($t, $db, $api, $f): void {
        Fixtures::insert($db, 'catalog_content_block', ['owner_type' => 'series', 'owner_id' => $f['seriesB'], 'block_key' => 'runtime-top-level-reference',
            'block_type' => 'key_value', 'is_public' => 1, 'display_order' => 200, 'payload_json' => '{"field_key":"b_summary"}']);
        Fixtures::insert($db, 'catalog_content_block', ['owner_type' => 'series', 'owner_id' => $f['seriesB'], 'block_key' => 'runtime-private-reference',
            'block_type' => 'document', 'is_public' => 1, 'display_order' => 210, 'payload_json' => '{"asset_id":2147483000}']);
        $blocks = Fixtures::plain($api()->series($f['pathB']))['blocks'];
        $t->same('42', $blocks[2]['payload']['value']);
        $t->same([], $blocks[3]['payload']);
    });
    $t->test('canonical category aliases rebase descendant routes while preserving natural identities', static function () use ($t, $db, $api, $f): void {
        Fixtures::insert($db, 'catalog_path_alias', ['path' => 'runtime-alternative-group', 'node_id' => $f['group'], 'is_canonical' => 1]);
        $resource = $api()->resolve('runtime-alternative-group/family-b/series-b')['resource'];
        $t->same($f['seriesB'], $resource['id']);
        $t->same($f['pathB'], $resource['hierarchy_path']);
        $t->same('runtime-alternative-group/family-b/series-b', $resource['path']);
        $t->same($resource, $api()->resolve($f['pathB'])['resource']);
        Fixtures::insert($db, 'catalog_path_alias', ['path' => 'runtime-independent-series', 'node_id' => $f['seriesB'], 'is_canonical' => 1]);
        $t->same('runtime-independent-series', $api()->resolve($f['pathB'])['resource']['path']);
    });
    $t->test('download disposition escapes unsafe filename parameters and supports inline media', static function () use ($t): void {
        $path = tempnam(sys_get_temp_dir(), 'catalog-download-test-');
        try {
            file_put_contents($path, 'generic bytes');
            $response = Transport::capture('', [], [], [], [], static fn () => (new HttpResponder())->sendFile($path, "generic\"; injected=yes\r\nname.pdf", 'application/pdf'));
            $header = $response['headers']['Content-Disposition'];
            $t->truth(!str_contains($header, "\r") && !str_contains($header, "\n"));
            $t->truth(str_contains($header, 'filename="generic\\"; injected=yesname.pdf"'));
            $t->truth(str_contains($header, 'filename*=UTF-8\'\'generic%22%3B%20injected%3Dyesname.pdf'));
            $inline = Transport::capture('', [], [], [], [], static fn () => (new HttpResponder())->sendFile($path, 'generic.png', 'image/png', false));
            $t->truth(str_starts_with($inline['headers']['Content-Disposition'], 'inline;'));
        } finally {
            unlink($path);
        }
    });
};
