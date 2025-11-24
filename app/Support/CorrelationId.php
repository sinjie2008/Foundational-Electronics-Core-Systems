<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Generates correlation IDs for tracing requests.
 */
final class CorrelationId
{
    public static function generate(): string
    {
        return bin2hex(random_bytes(16));
    }
}
