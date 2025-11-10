<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);
require __DIR__ . '/../catalog.php';

$app = CatalogApplication::create(false);
var_export($app->getHierarchyService()->listHierarchy());