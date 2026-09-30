<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

/**
 * Loads configuration arrays from config/*.php files with simple caching.
 */
final class Config
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    /** Override module settings before constructing its services. */
    public static function set(string $name, array $settings): void
    {
        self::$cache[$name] = $settings;
    }

    /** Merge host settings with standalone defaults without changing config files. */
    public static function merge(string $name, array $settings): void
    {
        self::$cache[$name] = self::combine(self::get($name), $settings);
    }

    /** Merge named settings recursively; explicitly supplied lists replace defaults. */
    public static function combine(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])
                && !array_is_list($value) && !array_is_list($defaults[$key])) {
                $defaults[$key] = self::combine($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }
        return $defaults;
    }

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
