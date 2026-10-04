<?php
declare(strict_types=1);

/** Generic runtime records only. The runner owns and drops the entire test database. */
final class Fixtures
{
    public static function insert(mysqli $db, string $table, array $values): int
    {
        $keys = implode(', ', array_map(static fn (string $key): string => '`' . $key . '`', array_keys($values)));
        $stmt = $db->prepare("INSERT INTO `{$table}` ({$keys}) VALUES (" . implode(',', array_fill(0, count($values), '?')) . ')');
        $stmt->execute(array_values($values));
        $id = (int) $stmt->insert_id;
        $stmt->close();
        return $id;
    }

    public static function plain(mixed $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function catalog(mysqli $db): array
    {
        $root = self::insert($db, 'category', ['name' => 'Runtime Root', 'slug' => 'runtime-root', 'type' => 'category']);
        $group = self::insert($db, 'category', ['name' => 'Runtime Group', 'slug' => 'runtime-group', 'type' => 'category', 'parent_id' => $root]);
        $familyA = self::insert($db, 'category', ['name' => 'Runtime Family A', 'slug' => 'family-a', 'type' => 'category', 'parent_id' => $group, 'display_order' => 20]);
        $familyB = self::insert($db, 'category', ['name' => 'Runtime Family B', 'slug' => 'family-b', 'type' => 'category', 'parent_id' => $group, 'display_order' => 10]);
        $seriesA = self::insert($db, 'category', ['name' => 'Runtime Series A', 'slug' => 'series-a', 'type' => 'series', 'parent_id' => $familyA, 'subtitle' => 'Runtime subtitle A']);
        $seriesB = self::insert($db, 'category', ['name' => 'Runtime Series B', 'slug' => 'series-b', 'type' => 'series', 'parent_id' => $familyB]);
        $fields = [];
        foreach ([
            ['a_scalar', $seriesA, 'number', 'product_attribute', 1, 1, 'select', '7'],
            ['a_label', $seriesA, 'text', 'product_attribute', 1, 1, 'select', null],
            ['a_summary', $seriesA, 'text', 'series_metadata', 0, 0, 'select', null],
            ['b_other', $seriesB, 'number', 'product_attribute', 1, 1, 'range', null],
            ['b_flag', $seriesB, 'text', 'product_attribute', 0, 0, 'select', null],
            ['b_summary', $seriesB, 'number', 'series_metadata', 0, 0, 'select', null],
        ] as $order => [$key, $series, $type, $scope, $filter, $sort, $filterType, $default]) {
            $fields[$key] = self::insert($db, 'series_custom_field', ['series_id' => $series, 'field_key' => $key,
                'label' => 'Stored label ' . $key, 'field_type' => $type, 'field_scope' => $scope, 'unit' => 'stored-unit',
                'sort_order' => $order, 'is_filterable' => $filter, 'is_sortable' => $sort, 'filter_type' => $filterType, 'default_value' => $default]);
        }
        self::insert($db, 'series_custom_field_value', ['series_id' => $seriesA, 'series_custom_field_id' => $fields['a_summary'], 'value' => 'Runtime series summary']);
        self::insert($db, 'series_custom_field_value', ['series_id' => $seriesB, 'series_custom_field_id' => $fields['b_summary'], 'value' => '42']);
        $partsA = [];
        foreach (['10', '2', '-1.5', null, '2', 'invalid numeric value'] as $index => $number) {
            $partId = self::insert($db, 'product', ['series_id' => $seriesA, 'sku' => 'TEST-PART-' . ($index + 1),
                'name' => $index === 5 ? 'Literal_50% marker' : 'Runtime Part ' . ($index + 1), 'description' => 'Isolated runtime test']);
            $partsA[] = $partId;
            if ($number !== null) {
                self::insert($db, 'product_custom_field_value', ['product_id' => $partId, 'series_custom_field_id' => $fields['a_scalar'], 'value' => $number]);
            }
            self::insert($db, 'product_custom_field_value', ['product_id' => $partId, 'series_custom_field_id' => $fields['a_label'], 'value' => $index % 2 === 0 ? 'test-even' : 'test-odd']);
        }
        $partB = self::insert($db, 'product', ['series_id' => $seriesB, 'sku' => 'TEST-DISTINCT-PART', 'name' => 'Runtime Distinct Part']);
        self::insert($db, 'product_custom_field_value', ['product_id' => $partB, 'series_custom_field_id' => $fields['b_other'], 'value' => '25.5']);
        self::insert($db, 'product_custom_field_value', ['product_id' => $partB, 'series_custom_field_id' => $fields['b_flag'], 'value' => 'runtime flag']);
        foreach ([
            ['catalog', 0, 'root-heading', 'hero', 'Stored root heading', 1, ['text' => 'Stored root description']],
            ['series', $seriesA, 'custom-section-two', 'key_value', 'Stored second title', 20, ['entries' => [['field_key' => 'a_summary']]]],
            ['series', $seriesA, 'custom-section-one', 'rich_text', 'Stored first title', 10, ['text' => 'Stored introductory text']],
            ['series', $seriesA, 'configured-parts', 'parts_table', 'Stored table title', 30, ['selectable' => true]],
            ['series', $seriesB, 'different-section', 'feature_list', 'Completely different content', 1, ['items' => ['Runtime feature']]],
            ['category', $familyA, 'table-a', 'series_table', 'Stored summary A', 1, ['columns' => [['key' => 'label', 'source' => 'identity', 'property' => 'title', 'label' => 'Stored identity label'], ['key' => 'custom_a', 'source' => 'metadata', 'field_key' => 'a_summary']]]],
            ['category', $familyB, 'table-b', 'series_table', 'Stored summary B', 1, ['columns' => [['key' => 'custom_b', 'source' => 'metadata', 'field_key' => 'b_summary']]]],
        ] as [$ownerType, $ownerId, $key, $type, $title, $order, $payload]) {
            self::insert($db, 'catalog_content_block', ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'block_key' => $key,
                'block_type' => $type, 'title' => $title, 'display_order' => $order, 'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR), 'is_public' => 1]);
        }
        $collection = self::insert($db, 'catalog_collection', ['collection_key' => 'runtime-collection', 'title' => 'Stored collection title', 'is_public' => 1, 'config_json' => '{"visible_count":3,"step":2}']);
        self::insert($db, 'catalog_collection_item', ['collection_id' => $collection, 'node_id' => $seriesA, 'display_order' => 2, 'is_public' => 1]);
        self::insert($db, 'catalog_collection_item', ['collection_id' => $collection, 'node_id' => $seriesB, 'display_order' => 1, 'is_public' => 1]);
        return compact('root', 'group', 'familyA', 'familyB', 'seriesA', 'seriesB', 'fields', 'partsA', 'partB', 'collection') + [
            'pathA' => 'runtime-root/runtime-group/family-a/series-a', 'pathB' => 'runtime-root/runtime-group/family-b/series-b',
            'familyPathA' => 'runtime-root/runtime-group/family-a', 'familyPathB' => 'runtime-root/runtime-group/family-b',
        ];
    }
}
