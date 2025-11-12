<?php
declare(strict_types=1);

/**
 * Backend service layer smoke tests.
 *
 * Validates node, series field, and product flows using the service-level API
 * functions within catalog.php. Execute with PowerShell:
 *
 *   php .\tests\api_backend_test.php
 */

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../catalog.php';

/**
 * Simple assertion helper.
 */
function catalog_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

/**
 * Recursively locate a node id by name and type within the hierarchy payload.
 *
 * @param array<int, array<string, mixed>> $nodes
 */
function catalog_test_find_node_id(array $nodes, string $targetName, string $targetType): ?int
{
    foreach ($nodes as $node) {
        if (
            isset($node['name'], $node['type'], $node['id'])
            && $node['name'] === $targetName
            && $node['type'] === $targetType
        ) {
            return (int) $node['id'];
        }
        $children = $node['children'] ?? [];
        if (is_array($children)) {
            $candidate = catalog_test_find_node_id($children, $targetName, $targetType);
            if ($candidate !== null) {
                return $candidate;
            }
        }
    }

    return null;
}

/**
 * Locate a series entry inside the public snapshot hierarchy.
 *
 * @param array<int, array<string, mixed>> $nodes
 */
function catalog_test_find_series_snapshot(array $nodes, int $seriesId): ?array
{
    foreach ($nodes as $node) {
        if (($node['type'] ?? null) === 'series' && (int) ($node['id'] ?? 0) === $seriesId) {
            return $node;
        }
        $children = $node['children'] ?? [];
        if (is_array($children)) {
            $candidate = catalog_test_find_series_snapshot($children, $seriesId);
            if ($candidate !== null) {
                return $candidate;
            }
        }
    }

    return null;
}

$application = CatalogApplication::create(false);
$seeder = $application->getSeeder();
$hierarchyService = $application->getHierarchyService();
$seriesFieldService = $application->getSeriesFieldService();
$seriesAttributeService = $application->getSeriesAttributeService();
$productService = $application->getProductService();
$publicCatalogService = $application->getPublicCatalogService();
$truncateService = $application->getTruncateService();
$connection = $application->getConnection();

try {
    $seeder->ensureSchema();
    $seeder->seedInitialData();

    $uniqueSuffix = (string) round(microtime(true));
    $parentCategory = $hierarchyService->saveNode([
        'parentId' => null,
        'name' => 'QA Root ' . $uniqueSuffix,
        'type' => 'category',
        'displayOrder' => 1000,
    ]);
    catalog_test_assert(isset($parentCategory['id']), 'Parent category should have id.');

    $series = $hierarchyService->saveNode([
        'parentId' => $parentCategory['id'],
        'name' => 'QA Series ' . $uniqueSuffix,
        'type' => 'series',
        'displayOrder' => 1,
    ]);
    catalog_test_assert($series['type'] === 'series', 'Series type should be series.');

$field = $seriesFieldService->saveField([
    'seriesId' => $series['id'],
    'fieldKey' => 'qa_field',
    'label' => 'QA Field',
    'fieldType' => 'text',
    'isRequired' => true,
    'sortOrder' => 1,
]);
catalog_test_assert($field['fieldKey'] === 'qa_field', 'Custom field key should match.');

$metadataField = $seriesFieldService->saveField([
    'seriesId' => $series['id'],
    'fieldKey' => 'qa_series_info',
    'label' => 'QA Series Info',
    'fieldType' => 'text',
    'fieldScope' => SERIES_FIELD_SCOPE_SERIES,
    'isRequired' => false,
    'sortOrder' => 1,
]);
catalog_test_assert(
    $metadataField['fieldScope'] === SERIES_FIELD_SCOPE_SERIES,
    'Metadata field should persist with series scope.'
);

$metadataSave = $seriesAttributeService->saveAttributes([
    'seriesId' => $series['id'],
    'values' => [
        'qa_series_info' => 'Series meta value',
    ],
]);
catalog_test_assert(
    $metadataSave['values']['qa_series_info'] === 'Series meta value',
    'Series metadata value should persist.'
);

$product = $productService->saveProduct([
    'seriesId' => $series['id'],
    'sku' => 'QA-SKU-' . $uniqueSuffix,
    'name' => 'QA Product ' . $uniqueSuffix,
    'description' => 'Auto-generated test product',
        'customValues' => [
            'qa_field' => 'value',
        ],
    ]);
    catalog_test_assert($product['sku'] === 'QA-SKU-' . $uniqueSuffix, 'Product SKU should match.');
    catalog_test_assert(
        $product['customValues']['qa_field'] === 'value',
        'Custom field value should persist.'
    );

    $productUpdated = $productService->saveProduct([
        'id' => $product['id'],
        'seriesId' => $series['id'],
        'sku' => 'QA-SKU-' . $uniqueSuffix,
        'name' => 'QA Product Updated ' . $uniqueSuffix,
        'description' => 'Updated description',
        'customValues' => [
            'qa_field' => 'updated',
        ],
    ]);
    catalog_test_assert(
        $productUpdated['name'] === 'QA Product Updated ' . $uniqueSuffix,
        'Product name should update.'
    );
    catalog_test_assert(
        $productUpdated['customValues']['qa_field'] === 'updated',
        'Updated custom value should persist.'
    );

    $snapshot = $publicCatalogService->buildSnapshot();
    catalog_test_assert(isset($snapshot['hierarchy']), 'Public snapshot should include hierarchy.');
    $seriesSnapshot = catalog_test_find_series_snapshot($snapshot['hierarchy'], (int) $series['id']);
    catalog_test_assert($seriesSnapshot !== null, 'Public snapshot should contain the QA series.');
    catalog_test_assert(
        isset($seriesSnapshot['metadata']['values']['qa_series_info'])
        && $seriesSnapshot['metadata']['values']['qa_series_info'] === 'Series meta value',
        'Series metadata should be embedded inside snapshot.'
    );
    catalog_test_assert(
        isset($seriesSnapshot['products'][0]['customValues']['qa_field'])
        && $seriesSnapshot['products'][0]['customValues']['qa_field'] === 'updated',
        'Product custom values should appear in snapshot output.'
    );

    $products = $productService->listProducts($series['id']);
    catalog_test_assert(count($products['products']) === 1, 'Series should list one product.');

    $csvService = $application->getCsvService();
    $exportMeta = $csvService->exportCatalog();
    catalog_test_assert(isset($exportMeta['id']), 'CSV export should return a file id.');

    $history = $csvService->listHistory();
    catalog_test_assert(
        isset($history['files']) && count($history['files']) > 0,
        'CSV history should include at least one entry after export.'
    );

    $importSku = 'IMP-' . $uniqueSuffix;
    $importCsv = "category_path,product_name,acf.length,acf.width\n"
        . "Imported Root > Imported Series,$importSku,10,5\n"
        . "Imported Root > Imported Series,{$importSku}-2,15,7\n";
    $tmpCsvPath = tempnam(sys_get_temp_dir(), 'catalog_csv');
    file_put_contents($tmpCsvPath, $importCsv);

    $importResult = $csvService->importFromPath($tmpCsvPath, 'test_import.csv');
    catalog_test_assert(
        isset($importResult['importedProducts']) && $importResult['importedProducts'] === 2,
        'CSV import should process two products.'
    );

    $skuCheck = $connection->prepare('SELECT COUNT(1) AS total FROM product WHERE sku = ?');
    $skuCheck->bind_param('s', $importSku);
    $skuCheck->execute();
    $skuResult = $skuCheck->get_result()->fetch_assoc();
    $skuCheck->close();
    catalog_test_assert((int) ($skuResult['total'] ?? 0) === 1, 'Imported product must exist.');

    $restoreResult = $csvService->restoreCatalog($exportMeta['id']);
    catalog_test_assert(
        isset($restoreResult['fileId']) && $restoreResult['fileId'] === $exportMeta['id'],
        'CSV restore should reference the original file id.'
    );

    $csvService->deleteFile($importResult['fileId']);
    if (isset($restoreResult['fileId']) && $restoreResult['fileId'] !== $exportMeta['id']) {
        $csvService->deleteFile($restoreResult['fileId']);
    }
    $csvService->deleteFile($exportMeta['id']);
    unlink($tmpCsvPath);

    $hierarchyPayload = $hierarchyService->listHierarchy();
    $hierarchyTree = $hierarchyPayload['hierarchy'] ?? [];

    $seriesIdForCleanup = $series['id'];
    $seriesProducts = $productService->listProducts($seriesIdForCleanup);
    if ($seriesProducts['products'] === []) {
        $seriesIdForCleanup = catalog_test_find_node_id($hierarchyTree, 'QA Series ' . $uniqueSuffix, 'series');
        catalog_test_assert($seriesIdForCleanup !== null, 'QA series should exist for cleanup.');
        $seriesProducts = $productService->listProducts($seriesIdForCleanup);
    }

    $productIdForCleanup = null;
    foreach ($seriesProducts['products'] as $candidateProduct) {
        if ($candidateProduct['sku'] === $product['sku']) {
            $productIdForCleanup = $candidateProduct['id'];
            break;
        }
    }
    catalog_test_assert($productIdForCleanup !== null, 'QA product should exist for cleanup.');
    $productService->deleteProduct($productIdForCleanup);
    $productsAfterDelete = $productService->listProducts($seriesIdForCleanup);
    catalog_test_assert($productsAfterDelete['products'] === [], 'Products should be empty after delete.');

$fields = $seriesFieldService->listFields($seriesIdForCleanup);
$fieldIdForCleanup = null;
foreach ($fields as $candidateField) {
    if ($candidateField['fieldKey'] === $field['fieldKey']) {
        $fieldIdForCleanup = $candidateField['id'];
        break;
    }
}
if ($fieldIdForCleanup !== null) {
    $seriesFieldService->deleteField($fieldIdForCleanup);
}

$metadataFields = $seriesFieldService->listFields($seriesIdForCleanup, SERIES_FIELD_SCOPE_SERIES);
foreach ($metadataFields as $candidateField) {
    if ($candidateField['fieldKey'] === 'qa_series_info') {
        $seriesFieldService->deleteField($candidateField['id']);
        break;
    }
}

    $seriesNodeId = catalog_test_find_node_id($hierarchyTree, 'QA Series ' . $uniqueSuffix, 'series');
    if ($seriesNodeId !== null) {
        $hierarchyService->deleteNode($seriesNodeId);
    }
    $categoryNodeId = catalog_test_find_node_id($hierarchyTree, 'QA Root ' . $uniqueSuffix, 'category');
    if ($categoryNodeId !== null) {
        $hierarchyService->deleteNode($categoryNodeId);
    }

    $truncateResult = $truncateService->truncateCatalog([
        'reason' => 'Automated smoke test cleanup',
        'confirmToken' => CATALOG_TRUNCATE_CONFIRM_TOKEN,
        'correlationId' => 'test-suite-' . $uniqueSuffix,
    ]);
    catalog_test_assert(isset($truncateResult['auditId']), 'Truncate result should include audit id.');
    catalog_test_assert(
        isset($truncateResult['deleted']) && is_array($truncateResult['deleted']),
        'Truncate result should include deleted counts.'
    );
    $postTruncateHierarchy = $hierarchyService->listHierarchy();
    $postHierarchy = $postTruncateHierarchy['hierarchy'] ?? [];
    catalog_test_assert(
        $postHierarchy === [],
        'Hierarchy should remain empty after truncate until the next import.'
    );

    fwrite(STDOUT, "API backend smoke tests passed.\n");
    exit(0);
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        sprintf(
            "API backend test failure: %s\n",
            $exception->getMessage()
        )
    );
    exit(1);
} finally {
    $connection->close();
}
