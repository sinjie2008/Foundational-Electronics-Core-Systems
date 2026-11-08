<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Http\Transport;

/** Small standalone limiter, shared across PHP workers. Hosts may use stricter middleware too. */
final class MediaRateLimiter
{
    public static function check(): void
    {
        if (PHP_SAPI === 'cli' || !empty($_SERVER['CATALOG_MEDIA_RATE_CHECKED'])) {
            return;
        }
        $_SERVER['CATALOG_MEDIA_RATE_CHECKED'] = true;
        $app = Config::get('app');
        $kind = in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','HEAD'], true) ? 'read' : 'write';
        $limit = (int) ($app['media_library']['rate_limits'][$kind] ?? 0);
        if ($limit <= 0) {
            return;
        }
        $directory = rtrim($app['storage']['logs'], '/\\');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new CatalogApiException('MEDIA_ACCESS_UNAVAILABLE', 'Management access is temporarily unavailable.', 503);
        }
        $handle = @fopen($directory . '/media-rate-limits.json', 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new CatalogApiException('MEDIA_ACCESS_UNAVAILABLE', 'Management access is temporarily unavailable.', 503);
        }
        try {
            $now = time();
            $buckets = json_decode(stream_get_contents($handle) ?: '{}', true) ?: [];
            $buckets = array_filter($buckets, static fn (array $entry): bool => (int) $entry['start'] > $now - 60);
            // Only the server connection address is trusted, not forwarded client headers.
            $key = hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $kind);
            if (!isset($buckets[$key]) && count($buckets) >= 5000) {
                throw new CatalogApiException('RATE_LIMITED', 'Management traffic is busy. Please retry shortly.', 429);
            }
            $entry = $buckets[$key] ?? ['start' => $now,'count' => 0];
            if ($entry['count'] >= $limit) {
                Transport::header('Retry-After: ' . max(1, 60 - ($now - $entry['start'])));
                throw new CatalogApiException('RATE_LIMITED', 'Too many media requests. Please retry shortly.', 429);
            }
            $entry['count']++;
            $buckets[$key] = $entry;
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($buckets, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
