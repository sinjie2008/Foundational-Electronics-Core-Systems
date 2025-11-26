<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Simplified request helper for query/body access.
 */
final class Request
{
    /**
     * Decode JSON body to array.
     *
     * @return array<string, mixed>
     */
    public static function json(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    public static function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Return the HTTP method (defaults to GET when unavailable).
     */
    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    /**
     * Return the current route/path for logging purposes.
     */
    public static function route(): string
    {
        if (!empty($_SERVER['REQUEST_URI'])) {
            return $_SERVER['REQUEST_URI'];
        }
        if (!empty($_SERVER['SCRIPT_NAME'])) {
            return $_SERVER['SCRIPT_NAME'];
        }
        return 'unknown';
    }

    /**
     * Return or generate a correlation ID for the request lifecycle.
     */
    public static function correlationId(): string
    {
        return CorrelationId::fromRequest();
    }
}
