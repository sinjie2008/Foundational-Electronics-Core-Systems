<?php
declare(strict_types=1);

/**
 * Database connection configuration.
 *
 * Important: Update credentials before running the application.
 */
return [
    'host' => getenv('CATALOG_DB_HOST') !== false ? getenv('CATALOG_DB_HOST') : '127.0.0.1',
    'port' => getenv('CATALOG_DB_PORT') !== false ? (int) getenv('CATALOG_DB_PORT') : 3306,
    'username' => getenv('CATALOG_DB_USERNAME') !== false ? getenv('CATALOG_DB_USERNAME') : 'root',
    'password' => getenv('CATALOG_DB_PASSWORD') !== false ? getenv('CATALOG_DB_PASSWORD') : '',
    'database' => getenv('CATALOG_DB_DATABASE') !== false ? getenv('CATALOG_DB_DATABASE') : 'product_catalog',
    'charset' => 'utf8mb4',
];
