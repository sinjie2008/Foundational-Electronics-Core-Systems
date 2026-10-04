<?php
declare(strict_types=1);

use CatalogSuite\Controllers\PublicCatalogV1Controller;
use CatalogSuite\Http\Transport;
use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Support\CatalogV1Migration;
use CatalogSuite\Support\Config;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $f = Fixtures::catalog($db);
    $api = static fn (): CatalogV1Service => new CatalogV1Service($db);
    $request = static function (string $route, array $query = []) use ($api): array {
        $response = Transport::capture('', $query, [], [], ['REQUEST_URI' => '/api/v1/catalog' . $route, 'REQUEST_METHOD' => 'GET'],
            static fn () => (new PublicCatalogV1Controller($api()))->run());
        return [...$response, 'json' => json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)];
    };
    $t->test('root composition uses stored groups hero and ordered collections', static function () use ($t, $api, $f): void {
        $root = Fixtures::plain($api()->root());
        $t->same('Stored root heading', $root['hero']['title']);
        $t->same($f['root'], $root['groups'][0]['id']);
        $t->same($f['seriesB'], $root['collections'][0]['items'][0]['resource']['id']);
        $t->same(3, $root['collections'][0]['config']['visible_count']);
        $t->same('Runtime subtitle A', $root['collections'][0]['items'][1]['subtitle']);
    });
    $t->test('category sections and horizontal navigation use child ordering and persistent anchors', static function () use ($t, $api, $f): void {
        $category = $api()->category('runtime-root/runtime-group');
        $t->same([$f['familyB'], $f['familyA']], array_column($category['sections'], 'id'));
        $t->same(array_column($category['sections'], 'anchor_id'), array_column($category['navigation'], 'anchor_id'));
        $t->same(3, count($category['breadcrumb']));
    });
    $t->test('different families receive entirely different stored summary schemas', static function () use ($t, $api, $f): void {
        $a = Fixtures::plain($api()->category($f['familyPathA']))['blocks'][0]['payload'];
        $b = Fixtures::plain($api()->category($f['familyPathB']))['blocks'][0]['payload'];
        $t->same(['label', 'custom_a'], array_column($a['columns'], 'key'));
        $t->same(['custom_b'], array_column($b['columns'], 'key'));
        $t->same('Stored label a_summary', $a['columns'][1]['label']);
        $t->same('Runtime series summary', $a['rows'][0]['values']['custom_a']);
        $t->same('42', $b['rows'][0]['values']['custom_b']);
    });
    $t->test('series sections title order presence and references come from public records', static function () use ($t, $api, $f): void {
        $a = Fixtures::plain($api()->series($f['pathA']));
        $b = Fixtures::plain($api()->series($f['pathB']));
        $t->same(['custom-section-one', 'custom-section-two', 'configured-parts'], array_column($a['blocks'], 'key'));
        $t->same(array_column($a['blocks'], 'key'), array_column($a['navigation'], 'key'));
        $t->same('Runtime series summary', $a['blocks'][1]['payload']['entries'][0]['value']);
        $t->same(['different-section'], array_column($b['blocks'], 'key'));
        $t->same($f['familyA'], $a['parent_context']['id']);
    });
    $t->test('one implementation returns two unrelated part schemas and values', static function () use ($t, $api, $f): void {
        $a = Fixtures::plain($api()->parts($f['pathA'], []));
        $b = Fixtures::plain($api()->parts($f['pathB'], []));
        $t->same(['a_scalar', 'a_label'], array_column($a['schema']['columns'], 'field_key'));
        $t->same(['b_other', 'b_flag'], array_column($b['schema']['columns'], 'field_key'));
        $t->same(['b_other' => '25.5', 'b_flag' => 'runtime flag'], $b['rows'][0]['values']);
        $t->same('stored-unit', $b['schema']['fields'][0]['unit']);
    });
    $t->test('column visibility changes without controller changes', static function () use ($t, $api, $f, $db): void {
        $id = $f['fields']['a_label'];
        $db->query("UPDATE series_custom_field SET is_table_column = 0 WHERE id = {$id}");
        $schema = $api()->fields($f['pathA']);
        $t->same(['a_scalar'], array_column($schema['columns'], 'field_key'));
        $t->same(['a_scalar', 'a_label'], array_column($schema['fields'], 'field_key'));
        $db->query("UPDATE series_custom_field SET is_table_column = 1 WHERE id = {$id}");
    });
    $t->test('numeric sorting uses numerical values with deterministic ties and invalid values last', static function () use ($t, $api, $f): void {
        $rows = $api()->parts($f['pathA'], ['sort' => 'a_scalar', 'direction' => 'asc'])['rows'];
        $t->same(['TEST-PART-3', 'TEST-PART-2', 'TEST-PART-5', 'TEST-PART-4', 'TEST-PART-1', 'TEST-PART-6'], array_column($rows, 'sku'));
        $desc = $api()->parts($f['pathA'], ['sort' => 'a_scalar', 'direction' => 'desc'])['rows'];
        $t->same(['TEST-PART-1', 'TEST-PART-4', 'TEST-PART-2', 'TEST-PART-5', 'TEST-PART-3', 'TEST-PART-6'], array_column($desc, 'sku'));
    });
    $t->test('text sorting remains based on its stored type', static function () use ($t, $api, $f): void {
        $rows = $api()->parts($f['pathA'], ['sort' => 'a_label', 'direction' => 'asc'])['rows'];
        $t->same(['TEST-PART-1', 'TEST-PART-3', 'TEST-PART-5'], array_slice(array_column($rows, 'sku'), 0, 3));
    });
    $t->test('select filtering accepts scalar and multi-value OR within each field', static function () use ($t, $api, $f): void {
        $t->same(3, $api()->parts($f['pathA'], ['filter' => ['a_label' => 'test-even']])['pagination']['total']);
        $t->same(6, $api()->parts($f['pathA'], ['filter' => ['a_label' => ['test-even', 'test-odd']]])['pagination']['total']);
    });
    $t->test('filters combine with AND across arbitrary fields', static function () use ($t, $api, $f): void {
        $page = $api()->parts($f['pathA'], ['filter' => ['a_label' => ['test-even'], 'a_scalar' => ['2']]]);
        $t->same(['TEST-PART-5'], array_column($page['rows'], 'sku'));
    });
    $t->test('range filtering uses stored numeric range definitions', static function () use ($t, $api, $f): void {
        $t->same(1, $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '20', 'max' => '30']]])['pagination']['total']);
        $t->same(0, $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '30']]])['pagination']['total']);
    });
    $t->test('range validation compares exact decimal bounds rather than rounded floats', static function () use ($t, $api, $f): void {
        $t->throws(static fn () => $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '1.00000000000000000003', 'max' => '1.00000000000000000002']]]), 422);
        $t->throws(static fn () => $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '-1.00000000000000000002', 'max' => '-1.00000000000000000003']]]), 422);
        $t->same(1, $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '25.49999999999999999999', 'max' => '25.50000000000000000001']]])['pagination']['total']);
        $t->same(1, $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '-0.0', 'max' => '0025.50']]])['pagination']['total']);
    });
    $t->test('numeric ranges reject unsupported precision and never silently round stored values into matches', static function () use ($t, $db, $api, $f): void {
        foreach (['1.000000000000000000004', '-1.000000000000000000006', '.000000000000000000001'] as $value) {
            $t->throws(static fn () => $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => $value]]]), 422);
            $t->throws(static fn () => $api()->parts($f['pathB'], ['filter' => ['b_other' => ['max' => $value]]]), 422);
        }
        $update = $db->prepare('UPDATE product_custom_field_value SET value = ? WHERE product_id = ? AND series_custom_field_id = ?');
        try {
            $update->execute(['1.00000000000000000000', $f['partB'], $f['fields']['b_other']]);
            $t->same(1, $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '1', 'max' => '1']]])['pagination']['total']);
            foreach ([str_repeat('9', 25) . '.' . str_repeat('0', 19) . '1', '-' . str_repeat('9', 45),
                str_repeat('9', 45) . '.' . str_repeat('9', 20)] as $value) {
                $update->execute([$value, $f['partB'], $f['fields']['b_other']]);
                $t->same(1, $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => $value, 'max' => $value]]])['pagination']['total']);
            }
            foreach (['1.000000000000000000004', str_repeat('9', 46)] as $value) {
                $update->execute([$value, $f['partB'], $f['fields']['b_other']]);
                $t->same(0, $api()->parts($f['pathB'], ['filter' => ['b_other' => ['min' => '0']]])['pagination']['total']);
                $rows = Fixtures::plain($api()->parts($f['pathB'], []))['rows'];
                $t->same($value, $rows[0]['values']['b_other']);
            }
        } finally {
            $update->execute(['25.5', $f['partB'], $f['fields']['b_other']]);
            $update->close();
        }
    });
    $t->test('search matches part identity and public arbitrary values', static function () use ($t, $api, $f): void {
        $t->same(['TEST-PART-3'], array_column($api()->parts($f['pathA'], ['search' => 'TEST-PART-3'])['rows'], 'sku'));
        $t->same(3, $api()->parts($f['pathA'], ['search' => 'test-even'])['pagination']['total']);
    });
    $t->test('search treats percent underscore and escape characters literally', static function () use ($t, $api, $f): void {
        $t->same(1, $api()->parts($f['pathA'], ['search' => '_50%'])['pagination']['total']);
        $t->same(0, $api()->parts($f['pathA'], ['search' => '!'])['pagination']['total']);
    });
    $t->test('search filter sort and pagination are combined server-side', static function () use ($t, $api, $f): void {
        $result = $api()->parts($f['pathA'], ['search' => 'test-even', 'filter' => ['a_scalar' => ['2', '10']], 'sort' => 'a_scalar', 'per_page' => '1', 'page' => '2']);
        $t->same(2, $result['pagination']['total']);
        $t->same(['TEST-PART-1'], array_column($result['rows'], 'sku'));
    });
    $t->test('pagination counts all matching records while fetching only the requested page', static function () use ($t, $api, $f): void {
        $page = $api()->parts($f['pathA'], ['page' => '2', 'per_page' => '2']);
        $t->same(2, count($page['rows']));
        $t->same(6, $page['pagination']['total']);
        $t->same(3, $page['pagination']['last_page']);
        $t->same(3, $page['pagination']['from']);
        $t->same(4, $page['pagination']['to']);
        $t->truth(str_contains($page['pagination']['links']['next'], 'page=3'));
    });
    $t->test('out-of-range page and empty searches retain correct counts and empty rows', static function () use ($t, $api, $f): void {
        $page = $api()->parts($f['pathA'], ['page' => '99', 'per_page' => '2']);
        $t->same([], $page['rows']);
        $t->same(6, $page['pagination']['total']);
        $t->same(null, $page['pagination']['from']);
        $t->same(0, $api()->parts($f['pathA'], ['search' => 'no-such-runtime-record'])['pagination']['total']);
    });
    $t->test('missing stored values use the same default in output sorting filtering and facets', static function () use ($t, $api, $f): void {
        $page = Fixtures::plain($api()->parts($f['pathA'], ['filter' => ['a_scalar' => ['7']]]));
        $t->same('TEST-PART-4', $page['rows'][0]['sku']);
        $t->same('7', $page['rows'][0]['values']['a_scalar']);
        $facet = $api()->facets($f['pathA'], [])[0];
        $t->truth(in_array('7', array_column($facet['values'], 'value'), true));
    });
    $t->test('facets use the full filtered search universe and expose stored filter metadata', static function () use ($t, $api, $f): void {
        $facets = $api()->facets($f['pathA'], ['search' => 'test-even']);
        $t->same(['a_scalar', 'a_label'], array_column($facets, 'field_key'));
        $t->same([['value' => 'test-even', 'count' => 3]], $facets[1]['values']);
        $t->same('Stored label a_label', $facets[1]['field']['label']);
    });
    $t->test('global search paginates public hierarchy and product matches', static function () use ($t, $api): void {
        $result = $api()->search(['q' => 'Runtime', 'per_page' => '2']);
        $t->same(2, count($result['rows']));
        $t->truth($result['pagination']['total'] > 2);
        $t->same(0, $api()->search(['q' => ''])['pagination']['total']);
    });
    $t->test('collection detail uses membership and order rather than database recency', static function () use ($t, $api, $f): void {
        $collection = $api()->collections('runtime-collection');
        $t->same([$f['seriesB'], $f['seriesA']], array_column(array_column($collection['items'], 'resource'), 'id'));
    });
    foreach (['', '/tree', '/resolve/runtime-root', '/categories/runtime-root', '/series/' . $f['pathA'], '/series/' . $f['pathA'] . '/fields', '/series/' . $f['pathA'] . '/parts', '/series/' . $f['pathA'] . '/facets', '/search', '/collections/runtime-collection'] as $route) {
        $t->test('HTTP dispatcher supplies ' . ($route ?: 'catalog root'), static function () use ($t, $request, $route): void {
            $response = $request($route);
            $t->same(200, $response['status']);
            $t->same(true, $response['json']['success']);
            $t->truth(isset($response['json']['data'], $response['json']['correlationId']));
            $t->same('application/json; charset=utf-8', $response['headers']['Content-Type']);
        });
    }
    $t->test('stored aliases can flatten a series URL without losing recursive family context', static function () use ($t, $api, $db, $f): void {
        Fixtures::insert($db, 'catalog_path_alias', ['path' => 'runtime-root/flattened-series', 'node_id' => $f['seriesA'], 'is_canonical' => 1]);
        $resolved = $api()->resolve('runtime-root/flattened-series');
        $t->same($f['seriesA'], $resolved['resource']['id']);
        $t->same($f['pathA'], $resolved['resource']['hierarchy_path']);
        $t->same('runtime-root/flattened-series', $api()->resolve($f['pathA'])['resource']['path']);
        $t->same(5, count($resolved['breadcrumb']));
    });
    $t->test('series slugs matching a subresource word remain routable', static function () use ($t, $db, $f, $request): void {
        Fixtures::insert($db, 'category', ['name' => 'Runtime Reserved Word', 'slug' => 'parts', 'type' => 'series', 'parent_id' => $f['familyA']]);
        $t->same(200, $request('/series/' . $f['familyPathA'] . '/parts')['status']);
        $t->same(200, $request('/series/' . $f['familyPathA'] . '/parts/fields')['status']);
    });
    $t->test('aliases cannot shadow natural or canonical series field part and facet URLs', static function () use ($t, $db, $api, $f, $request): void {
        foreach ([$f['pathA'], 'runtime-root/flattened-series'] as $path) {
            foreach (['fields', 'parts', 'facets'] as $subresource) {
                foreach ([$f['seriesA'], $f['seriesB'], $f['familyB']] as $target) {
                    Fixtures::insert($db, 'catalog_path_alias', ['path' => $path . '/' . $subresource, 'node_id' => $target]);
                    try {
                        $t->throws(static fn () => $api()->tree(), 503);
                        $t->same(503, $request('/series/' . $path . '/' . $subresource)['status']);
                    } finally {
                        $delete = $db->prepare('DELETE FROM catalog_path_alias WHERE path = ?');
                        $delete->execute([$path . '/' . $subresource]);
                        $delete->close();
                    }
                }
            }
        }
    });
    $t->test('all reusable technical section semantics and arbitrary names are representable', static function () use ($t, $db, $api, $f): void {
        foreach (['hero', 'feature_list', 'key_value', 'compliance', 'image_gallery', 'chart', 'technical_drawing', 'document', 'cta', 'parts_table'] as $index => $type) {
            Fixtures::insert($db, 'catalog_content_block', ['owner_type' => 'series', 'owner_id' => $f['seriesB'], 'block_key' => 'runtime-capability-' . $index,
                'block_type' => $type, 'title' => 'Stored technical title ' . $index, 'payload_json' => '{"text":"Runtime supplied content","actions":[{"url":"/enquiry","label":"Stored action label"}]}',
                'display_order' => $index + 10, 'is_public' => 1]);
        }
        $series = $api()->series($f['pathB']);
        $t->same(11, count($series['blocks']));
        $t->same(11, count($series['navigation']));
    });
};
