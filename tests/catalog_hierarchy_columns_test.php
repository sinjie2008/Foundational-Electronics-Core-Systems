<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../catalog.php';

use App\Catalog\CatalogService;

/**
 * Simple assertion helper.
 */
function catalog_columns_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

/**
 * Fetch a private static method via reflection.
 */
function catalog_columns_get_private_method(ReflectionClass $class, string $methodName): ReflectionMethod
{
    $method = $class->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}

$catalogRef = new ReflectionClass(CatalogService::class);
$catalogColumnsMethod = catalog_columns_get_private_method($catalogRef, 'getCategorySelectColumns');
$catalogColumnsWithLegacy = $catalogColumnsMethod->invoke(null, true);
$catalogColumnsWithoutLegacy = $catalogColumnsMethod->invoke(null, false);

catalog_columns_assert(
    strpos($catalogColumnsWithLegacy, 'latex_templating_enabled') !== false,
    'CatalogService should include legacy column when enabled.'
);
catalog_columns_assert(
    strpos($catalogColumnsWithoutLegacy, 'latex_templating_enabled') === false,
    'CatalogService should omit legacy column when disabled.'
);

$hierarchyRef = new ReflectionClass('HierarchyService');
$hierarchyColumnsMethod = catalog_columns_get_private_method($hierarchyRef, 'getCategorySelectColumns');
$hierarchyColumnsWithLegacy = $hierarchyColumnsMethod->invoke(null, true);
$hierarchyColumnsWithoutLegacy = $hierarchyColumnsMethod->invoke(null, false);

catalog_columns_assert(
    strpos($hierarchyColumnsWithLegacy, 'latex_templating_enabled') !== false,
    'HierarchyService should include legacy column when enabled.'
);
catalog_columns_assert(
    strpos($hierarchyColumnsWithoutLegacy, 'latex_templating_enabled') === false,
    'HierarchyService should omit legacy column when disabled.'
);

echo "ok\n";
