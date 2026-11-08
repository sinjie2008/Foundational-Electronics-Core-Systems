<?php
declare(strict_types=1);

// PHP development-server router. Production servers should use equivalent aliases.
require_once dirname(__DIR__) . '/app/bootstrap.php';

$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$publicRoot = realpath(dirname(__DIR__) . '/public');
$publicFile = realpath(dirname(__DIR__) . '/public' . $path);
if ($publicRoot !== false && $publicFile !== false && is_file($publicFile)
    && str_starts_with($publicFile, $publicRoot . DIRECTORY_SEPARATOR)) {
    return false;
}

// Keep native PHP PATH_INFO handling for the existing file endpoints.
if (preg_match('#^(/api/.*?\.php)/#i', $path, $matches) === 1) {
    $scriptFile = realpath(dirname(__DIR__) . '/public' . $matches[1]);
    if ($publicRoot !== false && $scriptFile !== false && is_file($scriptFile)
        && str_starts_with($scriptFile, $publicRoot . DIRECTORY_SEPARATOR)) {
        return false;
    }
}

if ($path === '/api' || str_starts_with($path, '/api/')) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require dirname(__DIR__) . '/public/api/index.php';
    return true;
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
