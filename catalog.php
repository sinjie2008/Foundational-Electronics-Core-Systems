<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/compatibility.php';

// Preserve the historical API entrypoint and library inclusion behaviour.
if (!defined('CATALOG_NO_AUTO_BOOTSTRAP')) {
    \CatalogSuite\Controllers\CatalogController::create()->run();
}
