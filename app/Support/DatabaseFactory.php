<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use mysqli;
use RuntimeException;

final class DatabaseFactory
{
    public function __construct(private string $configPath)
    {
        if (!is_file($this->configPath)) {
            throw new RuntimeException('Database configuration file not found: ' . $this->configPath);
        }
    }

    /**
     * Builds a mysqli connection using db_config.php settings.
     */
    public function createConnection(): mysqli
    {
        /** @var array<string, mixed> $config */
        $config = require $this->configPath;

        $connection = new mysqli(
            (string) $config['host'],
            (string) $config['username'],
            (string) $config['password'],
            '',
            (int) $config['port']
        );

        if ($connection->connect_errno !== 0) {
            throw new RuntimeException('Database connection failed: ' . $connection->connect_error);
        }

        $charset = (string) ($config['charset'] ?? 'utf8mb4');
        $connection->set_charset($charset);

        $databaseName = (string) $config['database'];
        $escapedName = $connection->real_escape_string($databaseName);
        $escapedCharset = $connection->real_escape_string($charset);
        $escapedCollation = $connection->real_escape_string('utf8mb4_unicode_ci');

        $connection->query(
            sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
                $escapedName,
                $escapedCharset,
                $escapedCollation
            )
        );
        $connection->select_db($databaseName);

        return $connection;
    }
}
