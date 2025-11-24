<?php
declare(strict_types=1);

// Thin front controller delegating to root catalog.php to avoid config duplication.
define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../catalog.php';

CatalogApplication::create()->run();
