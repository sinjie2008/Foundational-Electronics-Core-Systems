<?php
declare(strict_types=1);

// PHP development-server router. Production servers should use equivalent aliases.
require_once dirname(__DIR__) . '/app/bootstrap.php';

$rawPath = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($rawPath === '/api/v1/catalog' || str_starts_with($rawPath, '/api/v1/catalog/')) {
    (new CatalogSuite\Controllers\PublicCatalogV1Controller())->run();
    return true;
}

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$publicRoot = realpath(dirname(__DIR__) . '/public');
$publicFile = realpath(dirname(__DIR__) . '/public' . $path);
if ($publicRoot !== false && $publicFile !== false && is_file($publicFile)
    && str_starts_with($publicFile, $publicRoot . DIRECTORY_SEPARATOR)) {
    return false;
}

foreach ([
    '/storage/latex-pdfs/' => CatalogSuite\Support\Config::get('app')['storage']['latex_pdfs'],
    '/storage/media/' => CatalogSuite\Support\Config::get('app')['storage']['media'],
] as $prefix => $directory) {
    if (str_starts_with($path, $prefix)) {
        (new CatalogSuite\Controllers\StorageController())->download($directory, substr($path, strlen($prefix)));
        return true;
    }
}

return false;
