<?php
declare(strict_types=1);

use CatalogSuite\Controllers\PublicCatalogV1Controller;
use CatalogSuite\Http\Transport;
use CatalogSuite\Services\CatalogV1Service;
use CatalogSuite\Support\CatalogV1Verification;
use CatalogSuite\Support\Config;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $templatePath = dirname(__DIR__) . '/docs/api-plan/public-product-api-v1-test-variables.json';
    $blankTemplate = json_decode((string) file_get_contents($templatePath), true, 512, JSON_THROW_ON_ERROR);
    $fixture = Fixtures::catalog($db);

    $hiddenField = Fixtures::insert($db, 'series_custom_field', [
        'series_id' => $fixture['seriesA'], 'field_key' => 'runtime_hidden_probe', 'label' => 'Runtime hidden fixture',
        'field_type' => 'text', 'field_scope' => 'product_attribute', 'default_value' => 'runtime-hidden-default',
        'sort_order' => 20, 'is_required' => 0, 'is_public_portal_hidden' => 1, 'unit' => null,
        'is_filterable' => 1, 'is_sortable' => 1, 'is_table_column' => 1, 'is_searchable' => 1,
        'filter_type' => 'select',
    ]);
    Fixtures::insert($db, 'product_custom_field_value', [
        'product_id' => $fixture['partsA'][0], 'series_custom_field_id' => $hiddenField,
        'value' => 'runtime-hidden-value',
    ]);

    $mediaRoot = (string) Config::get('app')['storage']['media'];
    $mediaDirectory = $mediaRoot . '/verification-fixtures';
    if (!is_dir($mediaDirectory) && !mkdir($mediaDirectory, 0700, true) && !is_dir($mediaDirectory)) {
        throw new RuntimeException('Unable to create disposable verification media directory.');
    }
    file_put_contents($mediaDirectory . '/generic-image.jpg', 'runtime image fixture');
    file_put_contents($mediaDirectory . '/generic-document.pdf', "%PDF-1.4\nRuntime document fixture\n");
    $imageId = Fixtures::insert($db, 'catalog_asset', [
        'owner_type' => 'series', 'owner_id' => $fixture['seriesA'], 'asset_key' => 'runtime-image',
        'role' => 'runtime-image', 'storage_disk' => 'media', 'file_path' => 'verification-fixtures/generic-image.jpg',
        'mime_type' => 'image/jpeg', 'title' => 'Runtime image', 'alt_text' => 'Runtime image fixture',
        'display_order' => 1, 'is_download' => 0, 'is_public' => 1,
    ]);
    Fixtures::insert($db, 'catalog_asset', [
        'owner_type' => 'series', 'owner_id' => $fixture['seriesA'], 'asset_key' => 'runtime-document',
        'role' => 'runtime-document', 'storage_disk' => 'media', 'file_path' => 'verification-fixtures/generic-document.pdf',
        'mime_type' => 'application/pdf', 'title' => 'Runtime document', 'alt_text' => 'Runtime document fixture',
        'display_order' => 2, 'is_download' => 1, 'is_public' => 1,
    ]);
    Fixtures::insert($db, 'catalog_asset', [
        'owner_type' => 'category', 'owner_id' => $fixture['familyA'], 'asset_key' => 'runtime-section-image',
        'role' => 'runtime-section-image', 'storage_disk' => 'media', 'file_path' => 'verification-fixtures/generic-image.jpg',
        'mime_type' => 'image/jpeg', 'is_public' => 1,
    ]);
    $db->query("UPDATE category SET description = 'Stored category description', anchor_id = 'fixture-family-anchor' WHERE id = " . $fixture['familyA']);
    $db->query("UPDATE category SET anchor_id = 'fixture-series-anchor' WHERE id = " . $fixture['seriesA']);
    $db->query("UPDATE catalog_collection_item SET asset_id = {$imageId}, title = 'Stored card title', subtitle = 'Stored card subtitle' WHERE node_id = " . $fixture['seriesA']);
    $db->query("UPDATE catalog_content_block SET anchor_id = 'fixture-intro-anchor' WHERE owner_type = 'series' AND owner_id = " . $fixture['seriesA'] . " AND block_key = 'custom-section-one'");
    Fixtures::insert($db, 'catalog_content_block', [
        'owner_type' => 'category', 'owner_id' => $fixture['familyA'], 'block_key' => 'runtime-category-heading',
        'block_type' => 'hero', 'title' => 'Stored category heading', 'anchor_id' => 'fixture-heading-anchor',
        'payload_json' => '{"text":"Stored category hero text"}', 'display_order' => 0, 'is_public' => 1,
    ]);

    // Exercise a range filter through the public HTTP contract using the first generic series.
    $db->query("UPDATE series_custom_field SET filter_type = 'range' WHERE id = " . (int) $fixture['fields']['a_scalar']);

    $makeVariables = static function (bool $includeHiddenField = false) use ($blankTemplate, $fixture): array {
        $variables = $blankTemplate;
        $variables['actual_import_verification'] = array_replace($variables['actual_import_verification'], [
            'general_path' => $fixture['familyPathA'],
            'emc_path' => $fixture['familyPathB'],
            'a4k_series_path' => $fixture['pathA'],
            'a4k_series_slug' => 'series-a',
            'known_part_number' => 'TEST-PART-1',
            'expected_public_field_count' => 2,
            'expected_public_field_keys' => ['a_scalar', 'a_label'],
            'known_filterable_field' => 'a_scalar',
            'known_filterable_value' => ['min' => '9', 'max' => '11'],
            'known_sortable_field' => 'a_scalar',
            'expected_first_sorted_part_number' => 'TEST-PART-3',
            'expected_total_part_count' => 6,
            'expected_content_block_keys' => ['custom-section-one', 'custom-section-two', 'configured-parts'],
            'expected_asset_roles' => ['runtime-image'],
            'expected_download_roles' => ['runtime-document'],
            'known_hidden_public_field_key' => $includeHiddenField ? 'runtime_hidden_probe' : null,
            'expected_table_column_keys' => ['a_scalar', 'a_label'],
            'expected_filtered_part_count' => 1,
            'expected_title' => 'Runtime Series A',
        ]);
        $variables['second_actual_series_no_hard_code_proof'] = array_replace($variables['second_actual_series_no_hard_code_proof'], [
            'series_path' => $fixture['pathB'],
            'series_slug' => 'series-b',
            'known_part_number' => 'TEST-DISTINCT-PART',
            'expected_public_field_keys' => ['b_other', 'b_flag'],
            'expected_content_block_keys' => ['different-section'],
            'expected_asset_roles' => [],
            'expected_public_field_count' => 2,
            'expected_total_part_count' => 1,
            'expected_table_column_keys' => ['b_other', 'b_flag'],
            'expected_download_roles' => [],
        ]);
        $variables['page_composition_verification'] = array_replace($variables['page_composition_verification'], [
            'expected_group_paths' => ['runtime-root'],
            'expected_collection_keys' => ['runtime-collection'],
            'family_tables' => [
                [
                    'category_path' => $fixture['familyPathA'], 'block_key' => 'table-a',
                    'expected_column_keys' => ['label', 'custom_a'],
                    'expected_series_paths' => [$fixture['pathA']],
                ],
                [
                    'category_path' => $fixture['familyPathB'], 'block_key' => 'table-b',
                    'expected_column_keys' => ['custom_b'],
                    'expected_series_paths' => [$fixture['pathB']],
                ],
            ],
        ]);
        return $variables;
    };

    $http = static function (string $url) use ($db): array {
        $parsed = parse_url($url);
        $query = [];
        parse_str((string) ($parsed['query'] ?? ''), $query);
        $path = (string) ($parsed['path'] ?? $url);
        return Transport::capture('', $query, [], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $path . (isset($parsed['query']) ? '?' . $parsed['query'] : ''),
        ], static fn () => (new PublicCatalogV1Controller(new CatalogV1Service($db)))->run());
    };
    $summarize = static function (array $result): array {
        $checks = [];
        foreach ($result['checks'] as $check) {
            $checks[$check['name']] = $check['status'];
        }
        return $checks;
    };

    $t->test('blank official verification template is rejected before any HTTP request', static function () use ($t, $blankTemplate): void {
        $requests = 0;
        $http = static function (string $url) use (&$requests): array {
            $requests++;
            throw new RuntimeException('Unexpected HTTP request: ' . $url);
        };
        try {
            (new CatalogV1Verification($blankTemplate))->verify($http);
        } catch (InvalidArgumentException $error) {
            $t->truth(str_contains($error->getMessage(), 'actual path'));
            $t->same(0, $requests);
            return;
        }
        throw new RuntimeException('Expected the blank actual-data template to be rejected.');
    });

    $t->test('required actual values fail with a clear message before HTTP', static function () use ($t, $makeVariables): void {
        $variables = $makeVariables();
        $variables['actual_import_verification']['known_sortable_field'] = null;
        $requests = 0;
        $http = static function (string $url) use (&$requests): array {
            $requests++;
            throw new RuntimeException('Unexpected HTTP request: ' . $url);
        };
        try {
            (new CatalogV1Verification($variables))->verify($http);
        } catch (InvalidArgumentException $error) {
            $t->truth(str_contains($error->getMessage(), 'sort_field'));
            $t->same(0, $requests);
            return;
        }
        throw new RuntimeException('Expected the missing sort field to be rejected.');
    });

    $t->test('unsafe primary secondary and category paths are rejected before HTTP normalization', static function () use ($t, $makeVariables): void {
        foreach ([['actual_import_verification', 'a4k_series_path'], ['actual_import_verification', 'general_path'],
            ['actual_import_verification', 'emc_path'], ['second_actual_series_no_hard_code_proof', 'series_path']] as [$group, $key]) {
            foreach (['../outside', 'runtime/../../outside', 'runtime/%2e%2e/outside', 'runtime\\..\\outside', '/absolute-path'] as $path) {
                $variables = $makeVariables(true);
                $variables[$group][$key] = $path;
                $requests = 0;
                try {
                    (new CatalogV1Verification($variables))->verify(static function () use (&$requests): array {
                        $requests++;
                        throw new RuntimeException('Unexpected HTTP request.');
                    });
                } catch (InvalidArgumentException) {
                    $t->same(0, $requests);
                    continue;
                }
                throw new RuntimeException('Unsafe actual catalog path passed preflight.');
            }
        }
    });

    $t->test('one checker verifies two unrelated actual schemas and supports range filtering', static function () use (
        $t, $makeVariables, $http
    ): void {
        $result = (new CatalogV1Verification($makeVariables()))->verify($http);
        $t->same(0, $result['failed']);
        $t->same(1, $result['skipped']);
        $t->same(true, $result['actual_data_acceptance']);
        $checks = array_column($result['checks'], 'name');
        $t->truth(in_array('primary dynamic fields and columns', $checks, true));
        $t->truth(in_array('secondary dynamic fields and columns', $checks, true));
        $t->truth(in_array('actual dynamic filtering and facets', $checks, true));
        $t->truth(in_array('actual dynamic sorting', $checks, true));
        $t->same('skipped', $result['checks'][array_key_last($result['checks'])]['status']);
    });

    $t->test('supplied hidden field is guarded and removes the optional skip', static function () use (
        $t, $makeVariables, $http
    ): void {
        $result = (new CatalogV1Verification($makeVariables(true)))->verify($http);
        $t->same(0, $result['failed']);
        $t->same(0, $result['skipped']);
        $t->same(true, $result['actual_data_acceptance']);
        $checks = [];
        foreach ($result['checks'] as $check) {
            $checks[$check['name']] = $check['status'];
        }
        $t->same('passed', $checks['known hidden field is absent and cannot be queried'] ?? null);
    });

    $compositionResponses = [
        ['name' => 'root-details', 'path' => '', 'expected' => [
            'hero' => ['title' => 'Stored root heading', 'payload' => ['text' => 'Stored root description']],
            'groups' => [['path' => 'runtime-root', 'children' => [['path' => 'runtime-root/runtime-group', 'children' => [
                ['path' => $fixture['familyPathB']], ['path' => $fixture['familyPathA'], 'anchor_id' => 'fixture-family-anchor'],
            ]]]]],
        ]],
        ['name' => 'category-details', 'path' => '/categories/' . $fixture['familyPathA'], 'expected' => [
            'resource' => ['title' => 'Runtime Family A', 'description' => 'Stored category description', 'anchor_id' => 'fixture-family-anchor'],
            'hero' => ['title' => 'Stored category heading', 'payload' => ['text' => 'Stored category hero text']],
            'assets' => [['role' => 'runtime-section-image', 'available' => true]],
            'navigation' => [['title' => 'Runtime Series A', 'anchor_id' => 'fixture-series-anchor', 'url' => '/products/' . $fixture['pathA']]],
            'section_navigation' => [['key' => 'runtime-category-heading', 'anchor_id' => 'fixture-heading-anchor'], ['key' => 'table-a']],
            'sections' => [['path' => $fixture['pathA'], 'subtitle' => 'Runtime subtitle A', 'assets' => [
                ['role' => 'runtime-image', 'available' => true], ['role' => 'runtime-document', 'available' => true],
            ]]],
        ]],
        ['name' => 'series-navigation', 'path' => '/series/' . $fixture['pathA'], 'expected' => [
            'navigation' => [['key' => 'custom-section-one', 'anchor_id' => 'fixture-intro-anchor'], ['key' => 'custom-section-two'], ['key' => 'configured-parts']],
        ]],
        ['name' => 'collection-cards', 'path' => '/collections/runtime-collection', 'expected' => [
            'title' => 'Stored collection title', 'config' => ['step' => 2, 'visible_count' => 3],
            'items' => [
                ['resource' => ['path' => $fixture['pathB']], 'title' => 'Runtime Series B', 'image' => null],
                ['resource' => ['path' => $fixture['pathA']], 'title' => 'Stored card title', 'subtitle' => 'Stored card subtitle',
                    'url' => '/products/' . $fixture['pathA'], 'image' => ['role' => 'runtime-image', 'available' => true]],
            ],
        ]],
        ['name' => 'filtered-response', 'path' => '/series/' . $fixture['pathA'] . '/parts',
            'query' => ['filter' => ['a_scalar' => ['min' => '9', 'max' => '11']]], 'expected' => ['pagination' => ['total' => 1]]],
    ];
    $t->test('actual composition assertions cover root category images item links cards and section navigation', static function () use (
        $t, $makeVariables, $http, $compositionResponses
    ): void {
        $variables = $makeVariables(true);
        $variables['page_composition_verification']['responses'] = $compositionResponses;
        $result = (new CatalogV1Verification($variables))->verify($http);
        $t->same(0, $result['failed']);
        $t->same(0, $result['skipped']);
        $t->truth($result['actual_data_acceptance']);
    });
    $t->test('actual composition rejects changed titles thumbnails card order anchors configuration and missing properties', static function () use (
        $t, $makeVariables, $http, $compositionResponses
    ): void {
        $mutations = [
            static function (array &$responses): void { $responses[0]['expected']['hero']['title'] = 'Incorrect generic title'; },
            static function (array &$responses): void { $responses[1]['expected']['resource']['description'] = 'Incorrect description'; },
            static function (array &$responses): void { $responses[1]['expected']['assets'][0]['role'] = 'missing-thumbnail-role'; },
            static function (array &$responses): void { $responses[1]['expected']['navigation'][0]['anchor_id'] = 'missing-anchor'; },
            static function (array &$responses): void { $responses[2]['expected']['navigation'] = array_reverse($responses[2]['expected']['navigation']); },
            static function (array &$responses): void { $responses[3]['expected']['items'] = array_reverse($responses[3]['expected']['items']); },
            static function (array &$responses): void { $responses[3]['expected']['config']['step'] = 100; },
            static function (array &$responses): void { $responses[3]['expected']['items'][1]['image']['available'] = false; },
            static function (array &$responses): void { $responses[1]['expected']['navigation'] = []; },
            static function (array &$responses): void { $responses[0]['expected']['missing_property'] = null; },
        ];
        foreach ($mutations as $mutate) {
            $responses = $compositionResponses;
            $mutate($responses);
            $variables = $makeVariables(true);
            $variables['page_composition_verification']['responses'] = $responses;
            $result = (new CatalogV1Verification($variables))->verify($http);
            $t->truth($result['failed'] > 0 && !$result['actual_data_acceptance']);
        }
    });
    $t->test('malformed actual composition assertions fail before HTTP requests', static function () use (
        $t, $makeVariables
    ): void {
        foreach ([null, ['key' => 'not-a-list'], [['path' => 'not-relative', 'expected' => ['hero' => null]]],
            [['path' => '/tree?query=1', 'expected' => ['hero' => null]]], [['path' => '', 'expected' => []]],
            [['path' => '', 'query' => 'invalid', 'expected' => ['hero' => null]]],
            [['path' => '/../../../outside', 'expected' => ['hero' => null]]],
            [['path' => '/categories/%2e%2e/%2e%2e/outside', 'expected' => ['hero' => null]]],
            [['path' => '/categories/%252e%252e/outside', 'expected' => ['hero' => null]]],
            [['path' => '/categories/runtime\\..\\outside', 'expected' => ['hero' => null]]],
            [['path' => '/categories/runtime%5c..%5coutside', 'expected' => ['hero' => null]]],
            [['path' => '/categories/%2foutside', 'expected' => ['hero' => null]]]] as $responses) {
            $variables = $makeVariables(true);
            $variables['page_composition_verification']['responses'] = $responses;
            $requests = 0;
            try {
                (new CatalogV1Verification($variables))->verify(static function () use (&$requests): array {
                    $requests++;
                    throw new RuntimeException('Unexpected HTTP request.');
                });
            } catch (InvalidArgumentException) {
                $t->same(0, $requests);
                continue;
            }
            throw new RuntimeException('Malformed composition was accepted.');
        }
    });
    $t->test('malformed family table composition and traversal paths fail before HTTP', static function () use ($t, $makeVariables): void {
        $valid = ['category_path' => 'runtime-root/runtime-group/family-a', 'block_key' => 'table-a',
            'expected_column_keys' => ['label'], 'expected_series_paths' => ['runtime-root/runtime-group/family-a/series-a']];
        foreach ([null, ['named-table' => $valid], [[]], [['category_path' => '../outside']],
            [array_replace($valid, ['category_path' => 'runtime/../../outside'])],
            [array_replace($valid, ['category_path' => 'runtime/%2e%2e/outside'])],
            [array_replace($valid, ['block_key' => ''])], [array_replace($valid, ['expected_column_keys' => ['x' => 'label']])],
            [array_replace($valid, ['expected_column_keys' => [1]])],
            [array_replace($valid, ['expected_series_paths' => ['../outside']])],
            [array_replace($valid, ['expected_series_paths' => [false]])]] as $tables) {
            $variables = $makeVariables(true);
            $variables['page_composition_verification']['family_tables'] = $tables;
            $requests = 0;
            try {
                (new CatalogV1Verification($variables))->verify(static function () use (&$requests): array {
                    $requests++;
                    throw new RuntimeException('Unexpected HTTP request.');
                });
            } catch (InvalidArgumentException) {
                $t->same(0, $requests);
                continue;
            }
            throw new RuntimeException('Malformed family composition was accepted.');
        }
    });

    $t->test('verification reports incorrect imported counts schemas order slugs parts filters sorting and assets', static function () use (
        $t, $makeVariables, $http, $summarize
    ): void {
        $cases = [
            ['wrong public field count', 'primary dynamic fields and columns', static function (array $variables): array {
                $variables['actual_import_verification']['expected_public_field_count'] = 3;
                $variables['actual_import_verification']['expected_public_field_keys'] = ['a_scalar', 'a_label', 'phantom_field'];
                return $variables;
            }],
            ['wrong field order', 'primary dynamic fields and columns', static function (array $variables): array {
                $variables['actual_import_verification']['expected_public_field_keys'] = ['a_label', 'a_scalar'];
                return $variables;
            }],
            ['wrong table-column order', 'primary dynamic fields and columns', static function (array $variables): array {
                $variables['actual_import_verification']['expected_table_column_keys'] = ['a_label', 'a_scalar'];
                return $variables;
            }],
            ['wrong content-block order', 'primary series composition and media', static function (array $variables): array {
                $variables['actual_import_verification']['expected_content_block_keys'] = ['configured-parts', 'custom-section-two', 'custom-section-one'];
                return $variables;
            }],
            ['wrong persistent slug', 'primary series composition and media', static function (array $variables): array {
                $variables['actual_import_verification']['a4k_series_slug'] = 'wrong-generic-slug';
                return $variables;
            }],
            ['wrong part count', 'primary server-side part pagination and counts', static function (array $variables): array {
                $variables['actual_import_verification']['expected_total_part_count'] = 7;
                return $variables;
            }],
            ['unknown known part', 'primary known actual part search', static function (array $variables): array {
                $variables['actual_import_verification']['known_part_number'] = 'MISSING-GENERIC-PART';
                return $variables;
            }],
            ['wrong first sorted part', 'actual dynamic sorting', static function (array $variables): array {
                $variables['actual_import_verification']['expected_first_sorted_part_number'] = 'TEST-PART-2';
                return $variables;
            }],
            ['wrong filtered count', 'actual dynamic filtering and facets', static function (array $variables): array {
                $variables['actual_import_verification']['expected_filtered_part_count'] = 2;
                return $variables;
            }],
            ['missing asset role', 'primary series composition and media', static function (array $variables): array {
                $variables['actual_import_verification']['expected_asset_roles'] = ['missing-runtime-role'];
                return $variables;
            }],
            ['missing download role', 'primary series composition and media', static function (array $variables): array {
                $variables['actual_import_verification']['expected_download_roles'] = ['missing-runtime-document'];
                return $variables;
            }],
            ['wrong family table order', 'actual family table ' . 'runtime-root/runtime-group/family-a/table-a', static function (array $variables): array {
                $variables['page_composition_verification']['family_tables'][0]['expected_column_keys'] = ['custom_a', 'label'];
                return $variables;
            }],
        ];
        foreach ($cases as [$label, $expectedCheck, $mutate]) {
            $variables = $mutate($makeVariables(true));
            $result = (new CatalogV1Verification($variables))->verify($http);
            $checks = $summarize($result);
            $t->truth($result['failed'] > 0 && $result['actual_data_acceptance'] === false,
                $label . ' was not reflected in verification failure status.');
            $t->truth(($checks[$expectedCheck] ?? null) === 'failed',
                $label . ' did not fail its expected check (' . $expectedCheck . ').');
        }
    });
};
