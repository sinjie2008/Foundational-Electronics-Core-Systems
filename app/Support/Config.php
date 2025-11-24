<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Loads configuration arrays from config/*.php files with simple caching.
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /**
        * Load a config file by base name (e.g., "db" loads config/db.php).
        *
        * @return array<string, mixed>
        */
    public static function get(string $name): array
    {
        if (isset(self::$cache[$name])) {
            return self::$cache[$name];
        }

        $path = dirname(__DIR__, 2) . '/config/' . $name . '.php';
        if (!is_file($path)) {
            throw new \RuntimeException("Config file not found: {$name}");
        }

        /** @var array<string, mixed> $config */
        $config = require $path;
        self::$cache[$name] = $config;

        return $config;
    }
}
