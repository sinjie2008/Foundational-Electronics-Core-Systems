<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/app/bootstrap.php';

(new CatalogSuite\Controllers\PublicCatalogV1Controller())->run();
