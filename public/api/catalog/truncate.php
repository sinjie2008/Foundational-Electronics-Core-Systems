<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);
require_once __DIR__ . '/../../../app/bootstrap.php';

(new CatalogSuite\Controllers\CatalogOperationsController())->truncate();