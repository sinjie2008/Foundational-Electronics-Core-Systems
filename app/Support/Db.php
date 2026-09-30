<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use mysqli;
use mysqli_sql_exception;

/**
 * Factory for MySQLi connections with consistent charset and error handling.
 */
final class Db
{
    /**
     * Create a MySQLi connection using db config.
     *
     * @throws mysqli_sql_exception
     */
    public static function connection(): mysqli
    {
        $config = Config::get('db');

        $mysqli = new mysqli(
            (string) $config['host'],
            (string) $config['username'],
            (string) $config['password'],
            (string) $config['database'],
            (int) ($config['port'] ?? 3306),
            $config['unix_socket'] ?? null
        );

        $mysqli->set_charset($config['charset'] ?? 'utf8mb4');

        return $mysqli;
    }
}
