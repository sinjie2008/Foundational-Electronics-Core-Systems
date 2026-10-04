<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use CatalogSuite\Services\CatalogV1ImportService;
use CatalogSuite\Support\Db;

$options = getopt('', ['file:', 'dry-run']);
if (PHP_SAPI !== 'cli' || !isset($options['file']) || !is_string($options['file']) || !is_file($options['file'])) {
    fwrite(STDERR, "Usage: php scripts/import_public_catalog_v1.php --file=/path/to/actual-catalog.json [--dry-run]\n");
    exit(1);
}
try {
    $data = json_decode((string) file_get_contents($options['file']), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Manifest must be a JSON object.');
    }
    $result = (new CatalogV1ImportService(Db::connection()))->import($data, array_key_exists('dry-run', $options));
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Catalog import failed; transaction rolled back: {$error->getMessage()}\n");
    exit(1);
}
