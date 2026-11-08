<?php
declare(strict_types=1);

// Thin front controller delegating to root catalog.php to avoid config duplication.
define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../catalog.php';

try {
    CatalogApplication::create()->run();
} catch (CatalogSuite\Http\CatalogApiException $error) {
    $responder = new CatalogSuite\Http\HttpResponder();
    $responder->setCorrelationId(CatalogSuite\Http\Request::correlationId());
    $responder->sendError($error->getErrorCode(), $error->getMessage(), $error->getStatusCode(), $error->getDetails());
}
