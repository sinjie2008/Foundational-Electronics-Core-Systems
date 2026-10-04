<?php
declare(strict_types=1);

use CatalogSuite\Repositories\TypstRepository;
use CatalogSuite\Services\CatalogService;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Services\SpecSearchService;
use CatalogSuite\Services\TypstService;
use CatalogSuite\Support\Config;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $f = Fixtures::catalog($db);
    new TypstRepository($db);
    $hidden = Fixtures::insert($db, 'series_custom_field', ['series_id' => $f['seriesA'], 'field_key' => 'runtime-private-probe',
        'label' => 'Runtime private field', 'is_public_portal_hidden' => 1]);
    Fixtures::insert($db, 'product_custom_field_value', ['product_id' => $f['partsA'][0], 'series_custom_field_id' => $hidden, 'value' => 'runtime-private-value']);
    $t->test('legacy specification search cannot match or facet on hidden fields', static function () use ($t, $db, $f): void {
        $service = new SpecSearchService($db);
        $t->same([], $service->getProducts([$f['familyA']], ['runtime-private-probe' => ['runtime-private-value']]));
        $facets = $service->getFacets([$f['familyA']]);
        $t->truth(!in_array('runtime-private-probe', array_column($facets, 'key'), true));
    });
    $t->test('legacy public read and search exclude unpublished entities and their descendants', static function () use ($t, $db, $f): void {
        $db->query("UPDATE category SET is_published = 0 WHERE id = {$f['familyA']}");
        $db->query("UPDATE product SET is_published = 0 WHERE id = {$f['partB']}");
        try {
            $catalog = new CatalogService($db);
            $t->same(null, $catalog->getSeriesDetails($f['seriesA']));
            $t->same([], $catalog->search('Runtime Series A'));
            $t->same([], $catalog->search('Runtime Distinct Part'));
            $spec = new SpecSearchService($db);
            $t->same([], $spec->getProducts([$f['familyA']], []));
            $t->same([], $spec->getProducts([$f['familyB']], []));
            $t->same([], $spec->getFacets([$f['familyA']]));
            $t->truth(!str_contains(json_encode($catalog->getHierarchy(), JSON_THROW_ON_ERROR), 'Runtime Series A'));
            $hierarchy = new HierarchyService($db);
            $t->same([$f['seriesB']], array_column($hierarchy->listHierarchy(true)['seriesOptions'], 'id'));
            $t->truth(in_array($f['seriesA'], array_column($hierarchy->listHierarchy()['seriesOptions'], 'id'), true));
        } finally {
            $db->query("UPDATE category SET is_published = 1 WHERE id = {$f['familyA']}");
            $db->query("UPDATE product SET is_published = 1 WHERE id = {$f['partB']}");
        }
    });
    $t->test('internal document data retains hidden metadata and unpublished series access', static function () use ($t, $db, $f): void {
        $metadata = Fixtures::insert($db, 'series_custom_field', ['series_id' => $f['seriesB'], 'field_key' => 'runtime_internal_copy',
            'label' => 'Internal runtime copy', 'field_scope' => 'series_metadata', 'is_public_portal_hidden' => 1]);
        Fixtures::insert($db, 'series_custom_field_value', ['series_id' => $f['seriesB'], 'series_custom_field_id' => $metadata, 'value' => 'Generic internal value']);
        $db->query("UPDATE category SET is_published = 0 WHERE id = {$f['seriesB']}");
        try {
            $catalog = new CatalogService($db);
            $t->same(null, $catalog->getSeriesDetails($f['seriesB']));
            $t->truth(in_array('runtime_internal_copy', array_column($catalog->getSeriesDetails($f['seriesB'], false)['metadata'], 'key'), true));
            $build = Config::get('app')['storage']['typst_build'];
            mkdir($build, 0700, true);
            $method = new ReflectionMethod(TypstService::class, 'generateDataHeader');
            $header = $method->invoke(new TypstService($db), $f['seriesB'], $build);
            $t->same('Generic internal value', $header['safeData']['metadata']['runtime_internal_copy']);
            $t->same('TEST-DISTINCT-PART', $header['safeData']['products'][0]['sku']);
        } finally {
            $db->query("UPDATE category SET is_published = 1 WHERE id = {$f['seriesB']}");
        }
    });
};
