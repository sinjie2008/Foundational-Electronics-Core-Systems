<?php
declare(strict_types=1);

use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Support\CatalogV1Migration;
use CatalogSuite\Support\CatalogFactory;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $hierarchy = new HierarchyService($db);
    $save = static fn (string $name, ?int $parent = null): int => (int) $hierarchy->saveNode(['name' => $name, 'type' => 'category', 'parentId' => $parent])['id'];
    $first = $save('Runtime Duplicate');
    $second = $save('Runtime Duplicate');
    $third = $save('Runtime Duplicate', $first);
    $t->test('migration persists sibling-unique slugs including duplicate root names', static function () use ($t, $db, $first, $second, $third): void {
        $rows = $db->query('SELECT id, slug FROM category ORDER BY id')->fetch_all(MYSQLI_ASSOC);
        $t->same(['runtime-duplicate', 'runtime-duplicate-2', 'runtime-duplicate'], array_column($rows, 'slug'));
    });
    $t->test('renaming display names preserves route identity', static function () use ($t, $hierarchy, $first, $db): void {
        $hierarchy->saveNode(['id' => $first, 'name' => 'Runtime Renamed', 'type' => 'category']);
        $t->same($first, (new CatalogV1Service($db))->resolve('runtime-duplicate')['resource']['id']);
    });
    $t->test('root uniqueness is enforced by the database for direct writes', static function () use ($t, $db): void {
        try {
            Fixtures::insert($db, 'category', ['name' => 'Runtime Direct Duplicate', 'slug' => 'runtime-duplicate', 'type' => 'category']);
        } catch (mysqli_sql_exception $error) {
            $t->same(1062, $error->getCode());
            return;
        }
        throw new RuntimeException('Root slug duplicate should fail');
    });
    $t->test('moving a node into a conflicting slug namespace fails without changing identity', static function () use ($t, $hierarchy, $third, $db): void {
        $t->throws(static fn () => $hierarchy->saveNode(['id' => $third, 'name' => 'Runtime Child', 'type' => 'category', 'parentId' => null]), 409);
        $t->same($third, (new CatalogV1Service($db))->resolve('runtime-duplicate/runtime-duplicate')['resource']['id']);
    });
    $t->test('hierarchy service prevents descendant cycles', static function () use ($t, $hierarchy, $first, $third): void {
        $t->throws(static fn () => $hierarchy->saveNode(['id' => $first, 'name' => 'Runtime Invalid Move', 'type' => 'category', 'parentId' => $third]), 409);
    });
    $t->test('category with children cannot be converted to a series', static function () use ($t, $hierarchy, $first, $second): void {
        $t->throws(static fn () => $hierarchy->saveNode(['id' => $first, 'name' => 'Runtime Invalid Type', 'type' => 'series', 'parentId' => $second]), 409);
    });
    $t->test('recursive routing works through forty category levels', static function () use ($t, $db, $first): void {
        $parent = $first;
        $segments = ['runtime-duplicate'];
        for ($index = 0; $index < 40; $index++) {
            $slug = 'depth-' . $index;
            $parent = Fixtures::insert($db, 'category', ['name' => 'Runtime Depth ' . $index, 'slug' => $slug, 'type' => 'category', 'parent_id' => $parent]);
            $segments[] = $slug;
        }
        $series = Fixtures::insert($db, 'category', ['name' => 'Runtime Deep Series', 'slug' => 'deep-series', 'type' => 'series', 'parent_id' => $parent]);
        $segments[] = 'deep-series';
        $resolved = (new CatalogV1Service($db))->resolve(implode('/', $segments));
        $t->same($series, $resolved['resource']['id']);
        $t->same(43, count($resolved['breadcrumb']));
    });
    $t->test('direct database cycles fail safely without indefinite recursion', static function () use ($t, $db, $first, $third): void {
        $db->query("UPDATE category SET parent_id = {$third} WHERE id = {$first}");
        try {
            $service = new CatalogV1Service($db);
            $t->throws(static fn () => $service->tree(), 503);
            $t->throws(static fn () => $service->tree(), 503);
        } finally {
            $db->query("UPDATE category SET parent_id = NULL WHERE id = {$first}");
        }
    });
    $t->test('Unicode slugs have stable stored identities', static function () use ($t, $save, $db): void {
        $id = $save('测试 分类');
        $t->same($id, (new CatalogV1Service($db))->resolve('测试-分类')['resource']['id']);
    });
    $t->test('long display names never produce a truncated trailing slug separator', static function () use ($t, $save, $db): void {
        $id = $save(str_repeat('x', 149) . ' ' . str_repeat('y', 25));
        $slug = $db->query("SELECT slug FROM category WHERE id = {$id}")->fetch_row()[0];
        $t->same(str_repeat('x', 149), $slug);
        $t->same($id, (new CatalogV1Service($db))->resolve($slug)['resource']['id']);
    });
    $t->test('migration is idempotent and backfills only missing identities', static function () use ($t, $db, $first): void {
        $id = Fixtures::insert($db, 'category', ['name' => 'Runtime Backfill', 'type' => 'category']);
        $migration = new CatalogV1Migration($db);
        $migration->up();
        $migration->up();
        $t->same($id, (new CatalogV1Service($db))->resolve('runtime-backfill')['resource']['id']);
        $t->same($first, (new CatalogV1Service($db))->resolve('runtime-duplicate')['resource']['id']);
    });
    $t->test('only one canonical alias per node is enforced by the database', static function () use ($t, $db, $first): void {
        Fixtures::insert($db, 'catalog_path_alias', ['node_id' => $first, 'path' => 'runtime-alias-one', 'is_canonical' => 1]);
        Fixtures::insert($db, 'catalog_path_alias', ['node_id' => $first, 'path' => 'runtime-alias-two', 'is_canonical' => 0]);
        try {
            Fixtures::insert($db, 'catalog_path_alias', ['node_id' => $first, 'path' => 'runtime-alias-three', 'is_canonical' => 1]);
        } catch (mysqli_sql_exception $error) {
            $t->same(1062, $error->getCode());
            return;
        }
        throw new RuntimeException('Two canonical aliases must fail');
    });
    $t->test('legacy bootstrap remains seed-free by default', static function () use ($t, $db): void {
        $before = (int) $db->query('SELECT COUNT(*) FROM category')->fetch_row()[0];
        CatalogFactory::create(false, $db, false)->bootstrap();
        $t->same($before, (int) $db->query('SELECT COUNT(*) FROM category')->fetch_row()[0]);
        $t->same(0, (int) $db->query('SELECT COUNT(*) FROM seed_migration')->fetch_row()[0]);
    });
    $t->test('rollback preserves legacy hierarchy parts and values and allows reapply', static function () use ($t, $db, $first): void {
        $series = Fixtures::insert($db, 'category', ['name' => 'Runtime Rollback Series', 'slug' => 'rollback-series', 'type' => 'series', 'parent_id' => $first]);
        $part = Fixtures::insert($db, 'product', ['series_id' => $series, 'sku' => 'TEST-ROLLBACK-PART', 'name' => 'Runtime Rollback Part']);
        $field = Fixtures::insert($db, 'series_custom_field', ['series_id' => $series, 'field_key' => 'runtime_rollback_value', 'label' => 'Runtime rollback value']);
        Fixtures::insert($db, 'product_custom_field_value', ['product_id' => $part, 'series_custom_field_id' => $field, 'value' => 'runtime retained value']);
        $migration = new CatalogV1Migration($db);
        $migration->down();
        $migration->down();
        $t->same('runtime retained value', $db->query("SELECT value FROM product_custom_field_value WHERE product_id = {$part}")->fetch_row()[0]);
        $t->same(0, $db->query("SHOW COLUMNS FROM category LIKE 'slug'")->num_rows);
        $migration->up();
        $t->same(1, $db->query("SHOW COLUMNS FROM category LIKE 'slug'")->num_rows);
        $t->same($part, (int) $db->query("SELECT id FROM product WHERE id = {$part}")->fetch_row()[0]);
    });
};
