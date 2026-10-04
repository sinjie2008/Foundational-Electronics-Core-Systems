<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use CatalogSuite\Support\CatalogV1Migration;
use CatalogSuite\Support\DatabaseFactory;
use CatalogSuite\Support\Seeder;

if (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['--up', '--down'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrate_public_catalog_v1.php --up|--down\n");
    exit(1);
}
try {
    $db = (new DatabaseFactory(dirname(__DIR__) . '/db_config.php'))->createConnection();
    $migration = new CatalogV1Migration($db);
    if ($argv[1] === '--up') {
        (new Seeder($db))->ensureSchema(false);
        $migration->up();
    } else {
        $migration->down();
    }
    echo "Public catalog V1 migration applied. No catalog seed records were created.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "Public catalog migration failed: {$error->getMessage()}\n");
    exit(1);
}
