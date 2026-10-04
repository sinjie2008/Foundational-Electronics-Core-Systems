<?php
declare(strict_types=1);

use CatalogSuite\Controllers\CatalogReadController;
use CatalogSuite\Controllers\SpecSearchController;
use CatalogSuite\Http\HttpResponder;
use CatalogSuite\Http\Transport;
use CatalogSuite\Repositories\TypstRepository;
use CatalogSuite\Services\CatalogCsvService;
use CatalogSuite\Services\CatalogService;
use CatalogSuite\Services\HierarchyService;
use CatalogSuite\Services\MediaStorageService;
use CatalogSuite\Services\ProductService;
use CatalogSuite\Services\PublicCatalogService;
use CatalogSuite\Services\SeriesAttributeService;
use CatalogSuite\Services\SeriesFieldService;
use CatalogSuite\Services\SpecSearchService;
use CatalogSuite\Services\TypstService;
use CatalogSuite\Support\CatalogFactory;
use CatalogSuite\Support\Config;

return static function (TestSuite $t, mysqli $db): void {
    $hierarchy = new HierarchyService($db);
    $fields = new SeriesFieldService($db);
    $media = new MediaStorageService($db);
    $attributes = new SeriesAttributeService($db, $fields, $media);
    $products = new ProductService($db, $fields, $media);
    $publicCatalog = new PublicCatalogService($hierarchy, $fields, $attributes, $products);
    $csv = new CatalogCsvService($db, $hierarchy, $fields);
    // Existing spec search reads Typst PDF state; its historical schema is created lazily.
    new TypstRepository($db);
    $tag = strtolower(bin2hex(random_bytes(3)));

    $createNode = static function (
        HierarchyService $service,
        string $name,
        string $type,
        ?int $parentId = null,
        int $displayOrder = 0
    ): int {
        $saved = $service->saveNode([
            'name' => $name,
            'type' => $type,
            'parentId' => $parentId,
            'displayOrder' => $displayOrder,
        ]);

        return (int) $saved['id'];
    };

    $fixtureRoot = $createNode($hierarchy, 'Generic Root ' . $tag, 'category', null, 10);
    $fixtureGroup = $createNode($hierarchy, 'Generic Group ' . $tag, 'category', $fixtureRoot, 20);
    $fixtureFamily = $createNode($hierarchy, 'Generic Family ' . $tag, 'category', $fixtureGroup, 30);
    $fixtureSeriesA = $createNode($hierarchy, 'Generic Series A ' . $tag, 'series', $fixtureFamily, 40);
    $fixtureSeriesB = $createNode($hierarchy, 'Generic Series B ' . $tag, 'series', $fixtureFamily, 50);

    $fieldA1 = 'dimension_probe';
    $fieldA2 = 'current_limit_probe';
    $fieldASecret = 'private_probe_' . $tag;
    $fieldB1 = 'material_code_probe';
    $fieldB2 = 'layer_count_probe';
    $fieldBSecret = 'private_probe_b_' . $tag;
    $metadataA = 'intro_probe';
    $metadataASecret = 'private_metadata_probe_' . $tag;
    $metadataB = 'origin_probe';

    foreach ([
        [$fixtureSeriesA, $fieldA1, 'Dimension Probe', 'text', false],
        [$fixtureSeriesA, $fieldA2, 'Current Limit Probe', 'number', false],
        [$fixtureSeriesA, $fieldASecret, 'Private Probe', 'text', true],
        [$fixtureSeriesB, $fieldB1, 'Material Code Probe', 'text', false],
        [$fixtureSeriesB, $fieldB2, 'Layer Count Probe', 'number', false],
        [$fixtureSeriesB, $fieldBSecret, 'Private Probe B', 'text', true],
    ] as [$seriesId, $key, $label, $type, $hidden]) {
        $fields->saveField([
            'seriesId' => $seriesId,
            'fieldKey' => $key,
            'label' => $label,
            'fieldType' => $type,
            'fieldScope' => SeriesFieldService::SCOPE_PRODUCT,
            'sortOrder' => 10,
            'isRequired' => false,
            'publicPortalHidden' => $hidden,
        ]);
    }

    foreach ([
        [$fixtureSeriesA, $metadataA, 'Introduction Probe', false],
        [$fixtureSeriesA, $metadataASecret, 'Private Metadata Probe', true],
        [$fixtureSeriesB, $metadataB, 'Origin Probe', false],
    ] as [$seriesId, $key, $label, $hidden]) {
        $fields->saveField([
            'seriesId' => $seriesId,
            'fieldKey' => $key,
            'label' => $label,
            'fieldType' => 'text',
            'fieldScope' => SeriesFieldService::SCOPE_SERIES,
            'sortOrder' => 10,
            'isRequired' => false,
            'publicPortalHidden' => $hidden,
        ]);
    }

    $privateProductValue = 'private-product-value-' . $tag;
    $privateMetadataValue = 'private-metadata-value-' . $tag;
    $skuA = 'GENERIC-PART-A-' . strtoupper($tag);
    $skuB = 'GENERIC-PART-B-' . strtoupper($tag);

    $productA = $products->saveProduct([
        'seriesId' => $fixtureSeriesA,
        'sku' => $skuA,
        'name' => 'Generic Product A ' . $tag,
        'description' => 'Generic runtime-only test record.',
        'custom_field_values' => [
            $fieldA1 => '12.5 mm',
            $fieldA2 => '5',
            $fieldASecret => $privateProductValue,
        ],
    ]);
    $productB = $products->saveProduct([
        'seriesId' => $fixtureSeriesB,
        'sku' => $skuB,
        'name' => 'Generic Product B ' . $tag,
        'description' => 'Generic runtime-only test record.',
        'custom_field_values' => [
            $fieldB1 => 'MAT-' . strtoupper($tag),
            $fieldB2 => '3',
            $fieldBSecret => 'private-product-b-' . $tag,
        ],
    ]);
    $attributes->saveAttributes([
        'seriesId' => $fixtureSeriesA,
        'values' => [
            $metadataA => 'Generic introduction for runtime verification.',
            $metadataASecret => $privateMetadataValue,
        ],
    ]);
    $attributes->saveAttributes([
        'seriesId' => $fixtureSeriesB,
        'values' => [$metadataB => 'Generic origin value.'],
    ]);

    $findNode = static function (array $nodes, int $id) use (&$findNode): ?array {
        foreach ($nodes as $node) {
            if ((int) ($node['id'] ?? 0) === $id) {
                return $node;
            }
            $found = $findNode($node['children'] ?? [], $id);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    };

    $hasKeyOrValue = static function (mixed $value, string $needle) use (&$hasKeyOrValue): bool {
        if (!is_array($value)) {
            return is_string($value) && $value === $needle;
        }
        if (array_key_exists($needle, $value)) {
            return true;
        }
        foreach ($value as $child) {
            if ($hasKeyOrValue($child, $needle)) {
                return true;
            }
        }

        return false;
    };

    $decode = static function (array $response): array {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected a JSON response body.');
        }

        return $decoded;
    };

    $t->test('hierarchy supports nested category CRUD and existing Typst flag behavior', static function () use (
        $t,
        $hierarchy,
        $createNode,
        $fixtureRoot
    ): void {
        $payload = $hierarchy->listHierarchy();
        $root = null;
        foreach ($payload['hierarchy'] as $node) {
            if ((int) $node['id'] === $fixtureRoot) {
                $root = $node;
                break;
            }
        }
        $t->truth(is_array($root), 'The created root category must be listed.');
        $t->same(1, count($root['children']));
        $group = $root['children'][0];
        $t->same(1, count($group['children']));
        $t->same('category', $group['children'][0]['type']);

        $temporaryCategory = $createNode($hierarchy, 'Generic CRUD Node', 'category', $fixtureRoot, 60);
        $updated = $hierarchy->saveNode([
            'id' => $temporaryCategory,
            'name' => 'Generic CRUD Node Updated',
            'type' => 'category',
            'parentId' => $fixtureRoot,
            'displayOrder' => 70,
        ]);
        $t->same('Generic CRUD Node Updated', $updated['name']);
        $t->same(70, $updated['displayOrder']);

        $temporarySeries = $createNode($hierarchy, 'Generic CRUD Series', 'series', $temporaryCategory);
        $flag = $hierarchy->setTypstTemplatingEnabled($temporarySeries, true);
        $t->same(true, $flag['typstTemplatingEnabled']);
        $t->throws(static fn () => $hierarchy->deleteNode($temporaryCategory), 409);
        $hierarchy->deleteNode($temporarySeries);
        $hierarchy->deleteNode($temporaryCategory);
        $t->throws(static fn () => $hierarchy->saveNode([
            'name' => 'Invalid Generic Child',
            'type' => 'category',
            'parentId' => $temporarySeries,
        ]), 404);
    });

    $t->test('field, product and metadata services preserve generic dynamic schemas', static function () use (
        $t,
        $fields,
        $attributes,
        $products,
        $fixtureSeriesA,
        $fixtureSeriesB,
        $fieldA1,
        $fieldA2,
        $fieldASecret,
        $fieldB1,
        $fieldB2,
        $fieldBSecret,
        $metadataA,
        $metadataASecret,
        $metadataB,
        $privateProductValue,
        $privateMetadataValue,
        $skuA,
        $skuB
    ): void {
        $schemaA = $fields->listFields($fixtureSeriesA, SeriesFieldService::SCOPE_PRODUCT);
        $schemaB = $fields->listFields($fixtureSeriesB, SeriesFieldService::SCOPE_PRODUCT);
        $keysA = array_column($schemaA, 'fieldKey');
        $keysB = array_column($schemaB, 'fieldKey');
        $t->truth(in_array($fieldA1, $keysA, true) && in_array($fieldA2, $keysA, true));
        $t->truth(in_array($fieldB1, $keysB, true) && in_array($fieldB2, $keysB, true));
        $t->truth(!in_array($fieldB1, $keysA, true) && !in_array($fieldA1, $keysB, true));

        $listedA = $products->listProducts($fixtureSeriesA);
        $listedB = $products->listProducts($fixtureSeriesB);
        $rowA = $listedA['products'][0] ?? [];
        $rowB = $listedB['products'][0] ?? [];
        $t->same($skuA, $rowA['sku'] ?? null);
        $t->same('12.5 mm', $rowA['customValues'][$fieldA1] ?? null);
        $t->same($privateProductValue, $rowA['customValues'][$fieldASecret] ?? null);
        $t->same($skuB, $rowB['sku'] ?? null);
        $t->same('MAT-' . strtoupper(substr($skuB, -6)), $rowB['customValues'][$fieldB1] ?? null);

        $metadataPayload = $attributes->getAttributes($fixtureSeriesA);
        $t->same('Generic introduction for runtime verification.', $metadataPayload['values'][$metadataA] ?? null);
        $t->same($privateMetadataValue, $metadataPayload['values'][$metadataASecret] ?? null);
        $metadataKeys = array_column($metadataPayload['definitions'], 'fieldKey');
        $t->truth(in_array($metadataB, array_column(
            $attributes->getAttributes($fixtureSeriesB)['definitions'],
            'fieldKey'
        ), true));
        $t->truth(in_array($metadataASecret, $metadataKeys, true));
    });

    $t->test('public catalog snapshot retains its established envelope and dynamic per-series shape', static function () use (
        $t,
        $db,
        $publicCatalog,
        $decode,
        $findNode,
        $hasKeyOrValue,
        $fixtureSeriesA,
        $fixtureSeriesB,
        $fieldA1,
        $fieldA2,
        $fieldASecret,
        $fieldB1,
        $fieldB2,
        $fieldBSecret,
        $metadataA,
        $metadataASecret,
        $skuA,
        $privateProductValue,
        $privateMetadataValue
    ): void {
        $snapshot = $publicCatalog->buildSnapshot();
        $t->truth(isset($snapshot['generatedAt']) && is_string($snapshot['generatedAt']));
        $t->truth(is_array($snapshot['hierarchy'] ?? null));
        $seriesA = $findNode($snapshot['hierarchy'], $fixtureSeriesA);
        $seriesB = $findNode($snapshot['hierarchy'], $fixtureSeriesB);
        $t->truth(is_array($seriesA) && is_array($seriesB), 'Both generic series should be present.');
        $schemaA = array_column($seriesA['productFields'] ?? [], 'fieldKey');
        $schemaB = array_column($seriesB['productFields'] ?? [], 'fieldKey');
        $t->truth(in_array($fieldA1, $schemaA, true) && in_array($fieldA2, $schemaA, true));
        $t->truth(in_array($fieldB1, $schemaB, true) && in_array($fieldB2, $schemaB, true));
        $t->truth(!in_array($fieldB1, $schemaA, true) && !in_array($fieldA1, $schemaB, true));
        $t->same($metadataA, $seriesA['metadata']['definitions'][0]['fieldKey'] ?? null);
        $t->same($skuA, $seriesA['products'][0]['sku'] ?? null);

        if (!getenv('CATALOG_TEST_BASELINE')) {
            foreach ([$fieldASecret, $fieldBSecret, $metadataASecret, $privateProductValue, $privateMetadataValue] as $privateValue) {
                $t->truth(!$hasKeyOrValue($snapshot, $privateValue), 'A hidden field key/value leaked into the public snapshot.');
            }
        }

        $legacyController = CatalogFactory::create(false, $db, false);
        $response = Transport::capture(
            '',
            ['action' => 'v1.publicCatalogSnapshot'],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/catalog.php?action=v1.publicCatalogSnapshot'],
            static fn () => $legacyController->run()
        );
        $payload = $decode($response);
        $t->same(200, $response['status']);
        $t->same(true, $payload['success'] ?? null);
        $t->truth(isset($payload['data']['generatedAt'], $payload['data']['hierarchy']));
        $t->truth(isset($payload['correlationId']));
        if (!getenv('CATALOG_TEST_BASELINE')) {
            foreach ([$fieldASecret, $fieldBSecret, $metadataASecret, $privateProductValue, $privateMetadataValue] as $privateValue) {
                $t->truth(!$hasKeyOrValue($payload['data'], $privateValue), 'A hidden value leaked from the legacy public snapshot action.');
            }
        }
    });

    $t->test('catalog and specification-search HTTP contracts remain compatible', static function () use (
        $t,
        $db,
        $decode,
        $fixtureRoot,
        $fixtureFamily,
        $fixtureSeriesA,
        $fieldA2,
        $fieldASecret,
        $metadataASecret,
        $privateProductValue,
        $skuA
    ): void {
        $catalogController = new CatalogReadController(new CatalogService($db));
        $hierarchyResponse = Transport::capture(
            '',
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/catalog/hierarchy.php'],
            static fn () => $catalogController->hierarchy()
        );
        $hierarchyPayload = $decode($hierarchyResponse);
        $t->same(200, $hierarchyResponse['status']);
        $t->same(true, $hierarchyPayload['success'] ?? null);
        $t->truth(is_array($hierarchyPayload['data'] ?? null));
        $t->truth(isset($hierarchyPayload['correlationId']));

        $catalogSearchResponse = Transport::capture(
            '',
            ['q' => $skuA],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/catalog/search.php'],
            static fn () => $catalogController->search()
        );
        $catalogSearchPayload = $decode($catalogSearchResponse);
        $t->same(true, $catalogSearchPayload['success'] ?? null);
        $t->truth(count($catalogSearchPayload['data'] ?? []) > 0);

        $searchController = new SpecSearchController(new SpecSearchService($db));
        $rootResponse = Transport::capture(
            '',
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/spec-search/root-categories.php'],
            static fn () => $searchController->rootCategories()
        );
        $rootPayload = $decode($rootResponse);
        $t->same(true, $rootPayload['success'] ?? null);
        $root = null;
        foreach ($rootPayload['data']['categories'] ?? [] as $category) {
            if ((int) ($category['id'] ?? 0) === $fixtureRoot) {
                $root = $category;
                break;
            }
        }
        $t->truth(is_array($root), 'The dynamic spec-search root endpoint should include the imported root.');

        $groupsResponse = Transport::capture(
            '',
            ['root_id' => (string) $fixtureRoot],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/spec-search/product-categories.php'],
            static fn () => $searchController->productCategories()
        );
        $groupsPayload = $decode($groupsResponse);
        $t->same(true, $groupsPayload['success'] ?? null);
        $t->truth(count($groupsPayload['data']['groups'] ?? []) > 0);

        $facetsResponse = Transport::capture(
            json_encode(['category_ids' => [$fixtureFamily]], JSON_THROW_ON_ERROR),
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/spec-search/facets.php'],
            static fn () => $searchController->facets()
        );
        $facetsPayload = $decode($facetsResponse);
        $facetKeys = array_column($facetsPayload['data']['facets'] ?? [], 'key');
        $t->same(true, $facetsPayload['success'] ?? null);
        $t->truth(in_array($fieldA2, $facetKeys, true), 'A public dynamic field should remain a facet.');

        $productsResponse = Transport::capture(
            json_encode([
                'category_ids' => [$fixtureFamily],
                'filters' => [$fieldA2 => ['5']],
            ], JSON_THROW_ON_ERROR),
            [],
            [],
            [],
            ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/api/spec-search/products.php'],
            static fn () => $searchController->products()
        );
        $productsPayload = $decode($productsResponse);
        $t->same(true, $productsPayload['success'] ?? null);
        $matchingProduct = $productsPayload['data']['items'][0] ?? [];
        $t->same($skuA, $matchingProduct['sku'] ?? null);
        if (!getenv('CATALOG_TEST_BASELINE')) {
            $t->truth(!array_key_exists($fieldASecret, $matchingProduct), 'A hidden product field leaked from spec search.');
            $t->truth(!in_array($fieldASecret, $facetKeys, true), 'A hidden field leaked into spec-search facets.');
            $t->truth(!str_contains(json_encode($matchingProduct, JSON_THROW_ON_ERROR), $privateProductValue));
        }

        $legacyController = CatalogFactory::create(false, $db, false);
        $legacyResponse = Transport::capture(
            '',
            ['action' => 'v1.specSearchRootCategories'],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/catalog.php?action=v1.specSearchRootCategories'],
            static fn () => $legacyController->run()
        );
        $legacyPayload = $decode($legacyResponse);
        $t->same(true, $legacyPayload['success'] ?? null);
        $t->same('general', $legacyPayload['data'][0]['id'] ?? null);
        $t->truth(isset($legacyPayload['correlationId']));

        $seriesResponse = Transport::capture(
            '',
            ['id' => (string) $fixtureSeriesA],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/series/details.php'],
            static fn () => $catalogController->seriesDetails()
        );
        $seriesPayload = $decode($seriesResponse);
        $t->same(true, $seriesPayload['success'] ?? null);
        $t->same($fixtureSeriesA, (int) ($seriesPayload['data']['id'] ?? 0));
        if (!getenv('CATALOG_TEST_BASELINE')) {
            $publicMetadataKeys = array_column($seriesPayload['data']['metadata'] ?? [], 'key');
            $publicProductKeys = array_column($seriesPayload['data']['customFields'] ?? [], 'key');
            $t->truth(!in_array($metadataASecret, $publicMetadataKeys, true));
            $t->truth(!in_array($fieldASecret, $publicProductKeys, true));
        }
    });

    $t->test('Typst templates, preferences, variables and series data remain usable without compilation', static function () use (
        $t,
        $db,
        $fixtureSeriesA,
        $productA
    ): void {
        $typst = new TypstService($db);
        $global = $typst->createGlobalTemplate('Generic Global Template', 'Runtime test template.', '#let marker = "generic"');
        $t->truth((int) ($global['id'] ?? 0) > 0);
        $t->same(true, $global['isGlobal'] ?? null);
        $updatedGlobal = $typst->updateGlobalTemplate((int) $global['id'], 'Generic Global Template Updated', 'Updated.', '#let marker = "updated"');
        $t->same('Generic Global Template Updated', $updatedGlobal['title'] ?? null);

        $seriesTemplate = $typst->createSeriesTemplate(
            $fixtureSeriesA,
            'Generic Series Template',
            'Runtime test series template.',
            '#let series_marker = "generic"'
        );
        $t->same($fixtureSeriesA, (int) ($seriesTemplate['seriesId'] ?? 0));
        $available = $typst->listSeriesTemplates($fixtureSeriesA);
        $availableIds = array_column($available, 'id');
        $t->truth(in_array((int) $global['id'], array_map('intval', $availableIds), true));
        $t->truth(in_array((int) $seriesTemplate['id'], array_map('intval', $availableIds), true));

        $preference = $typst->saveSeriesPreference($fixtureSeriesA, (int) $global['id']);
        $t->same((int) $global['id'], (int) ($preference['lastGlobalTemplateId'] ?? 0));
        $t->same((int) $global['id'], (int) ($typst->getSeriesPreference($fixtureSeriesA)['lastGlobalTemplateId'] ?? 0));

        $globalVariable = $typst->saveGlobalVariable('generic_global_probe', 'text', 'generic-value');
        $t->same('generic-value', $globalVariable['value'] ?? null);
        $updatedVariable = $typst->saveGlobalVariable('generic_global_probe', 'text', 'updated-value', (int) $globalVariable['id']);
        $t->same('updated-value', $updatedVariable['value'] ?? null);
        $scopedVariable = $typst->saveScopedVariable($fixtureSeriesA, 'generic_series_probe', 'text', 'series-value');
        $t->same($fixtureSeriesA, (int) ($scopedVariable['seriesId'] ?? 0));
        $t->same('series-value', $typst->getScopedVariable((int) $scopedVariable['id'], $fixtureSeriesA)['value'] ?? null);

        $typstRepository = new TypstRepository($db);
        $seriesProducts = $typstRepository->getSeriesProducts($fixtureSeriesA);
        $t->same(1, count($seriesProducts));
        $t->same((int) $productA['id'], (int) ($seriesProducts[0]['id'] ?? 0));
        $productAttributes = $typstRepository->getProductAttributes((int) $productA['id']);
        $t->truth(count($productAttributes) >= 2);

        $t->same(true, $typst->deleteScopedVariable((int) $scopedVariable['id'], $fixtureSeriesA));
        $t->same(true, $typst->deleteGlobalVariable((int) $globalVariable['id']));
        $t->same(true, $typst->deleteTemplate((int) $seriesTemplate['id']));
        $t->same(true, $typst->deleteTemplate((int) $global['id']));
    });

    $t->test('media values and download stream retain their existing format', static function () use (
        $t,
        $db,
        $media,
        $decode,
        $fixtureSeriesA,
        $productA,
        $tag
    ): void {
        $tmp = tempnam(sys_get_temp_dir(), 'catalog-media-test-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create temporary media input.');
        }
        file_put_contents($tmp, "%PDF-1.4\n% generic runtime media test\n");
        try {
            $stored = $media->saveSeriesFile($fixtureSeriesA, (int) $productA['id'], 'probe_datasheet', [
                'tmp_name' => $tmp,
                'name' => 'generic-runtime-document.pdf',
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
            ]);
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }

        $mediaValue = $media->buildMediaValue($stored['relativePath']);
        $t->same('generic-runtime-document.pdf', $mediaValue['filename'] ?? null);
        $t->truth(str_contains((string) ($mediaValue['url'] ?? ''), 'action=v1.downloadMedia&id='));
        $t->truth((int) ($mediaValue['sizeBytes'] ?? 0) > 0);

        $legacyController = CatalogFactory::create(false, $db, false);
        $streamed = Transport::capture(
            '',
            ['action' => 'v1.downloadMedia', 'id' => $stored['relativePath']],
            [],
            [],
            ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/catalog.php?action=v1.downloadMedia'],
            static fn () => $legacyController->run()
        );
        $t->same(200, $streamed['status']);
        $t->truth(is_string($streamed['file']) && is_file($streamed['file']));
        $t->same("%PDF-1.4\n% generic runtime media test\n", file_get_contents((string) $streamed['file']));
        $t->same('application/pdf', $streamed['headers']['Content-Type'] ?? null);

        if (!getenv('CATALOG_TEST_BASELINE')) {
            $mediaRoot = (string) Config::get('app')['storage']['media'];
            $siblingRoot = $mediaRoot . '-private';
            mkdir($siblingRoot, 0700, true);
            $marker = $siblingRoot . '/guard-marker-' . $tag . '.txt';
            file_put_contents($marker, 'outside-media-root');
            try {
                $t->throws(
                    static fn () => $media->streamMedia('../media-private/' . basename($marker), new HttpResponder()),
                    400
                );
            } finally {
                if (is_file($marker)) {
                    unlink($marker);
                }
                if (is_dir($siblingRoot)) {
                    rmdir($siblingRoot);
                }
            }
        }
    });

    $t->test('CSV export/import preserves generic columns and prunes absent catalog rows', static function () use (
        $t,
        $csv,
        $hierarchy,
        $products,
        $fixtureSeriesA,
        $fixtureFamily,
        $fieldA1,
        $fieldA2,
        $skuA,
        $createNode,
        $db,
        $tag
    ): void {
        $export = $csv->exportCatalog();
        $csvPath = Config::get('app')['storage']['csv'] . '/' . $export['id'];
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not open exported CSV.');
        }
        $header = fgetcsv($handle, null, ',', '"', '\\');
        fclose($handle);
        $t->truth(is_array($header) && in_array($fieldA1, $header, true) && in_array($fieldA2, $header, true));

        $extraRoot = $createNode($hierarchy, 'Generic Prune Root ' . $tag, 'category');
        $extraSeries = $createNode($hierarchy, 'Generic Prune Series ' . $tag, 'series', $extraRoot);
        $extraProduct = $products->saveProduct([
            'seriesId' => $extraSeries,
            'sku' => 'GENERIC-PRUNE-' . strtoupper($tag),
            'name' => 'Generic Prune Product ' . $tag,
        ]);

        $import = $csv->importFromPath($csvPath, 'generic-roundtrip.csv');
        $t->same(2, (int) ($import['importedProducts'] ?? 0));
        $t->same(null, (new CatalogSuite\Repositories\CatalogRepository($db))->findSeries($extraSeries));
        $productCheck = $db->prepare('SELECT COUNT(1) FROM product WHERE id = ?');
        $extraProductId = (int) $extraProduct['id'];
        $productCheck->bind_param('i', $extraProductId);
        $productCheck->execute();
        $t->same(0, (int) ($productCheck->get_result()->fetch_row()[0] ?? -1));
        $productCheck->close();

        $retained = $db->prepare('SELECT COUNT(1) FROM product WHERE series_id = ? AND sku = ?');
        $retained->bind_param('is', $fixtureSeriesA, $skuA);
        $retained->execute();
        $t->same(1, (int) ($retained->get_result()->fetch_row()[0] ?? 0));
        $retained->close();
        $t->truth($fixtureFamily > 0);
    });

    $t->test('Transport isolates sequential bridge requests and restores caller globals', static function () use ($t): void {
        $original = [$_GET, $_POST, $_FILES, $_SERVER];
        try {
            $_GET = ['outer' => 'preserved'];
            $_POST = ['outer_post' => 'preserved'];
            $_FILES = ['outer_file' => 'preserved'];
            $_SERVER = ['REQUEST_METHOD' => 'OUTER', 'REQUEST_URI' => '/outer'];
            $before = [$_GET, $_POST, $_FILES, $_SERVER];

            $first = Transport::capture(
                '{"body_marker":"first"}',
                ['query_marker' => 'first'],
                ['post_marker' => 'first'],
                [],
                ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/first'],
                static function (): void {
                    echo json_encode([
                        'query' => $_GET,
                        'post' => $_POST,
                        'body' => CatalogSuite\Http\Request::json(),
                        'route' => CatalogSuite\Http\Request::route(),
                    ], JSON_THROW_ON_ERROR);
                }
            );
            $second = Transport::capture(
                '{"body_marker":"second"}',
                ['different_query' => 'second'],
                ['different_post' => 'second'],
                [],
                ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/second'],
                static function (): void {
                    echo json_encode([
                        'query' => $_GET,
                        'post' => $_POST,
                        'body' => CatalogSuite\Http\Request::json(),
                        'route' => CatalogSuite\Http\Request::route(),
                    ], JSON_THROW_ON_ERROR);
                }
            );

            $firstPayload = json_decode($first['body'], true);
            $secondPayload = json_decode($second['body'], true);
            $t->same('first', $firstPayload['query']['query_marker'] ?? null);
            $t->same('first', $firstPayload['body']['body_marker'] ?? null);
            $t->same('/first', $firstPayload['route'] ?? null);
            $t->same('second', $secondPayload['query']['different_query'] ?? null);
            $t->same(false, array_key_exists('query_marker', $secondPayload['query'] ?? []));
            $t->same('second', $secondPayload['body']['body_marker'] ?? null);
            $t->same('/second', $secondPayload['route'] ?? null);
            $t->same($before, [$_GET, $_POST, $_FILES, $_SERVER]);
        } finally {
            [$_GET, $_POST, $_FILES, $_SERVER] = $original;
        }
    });
};
