<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Lightweight logger for service-boundary errors.
 */
final class Logger
{
    public static function error(string $message, ?string $correlationId = null): void
    {
        $line = sprintf(
            '[%s] correlation_id=%s %s',
            date('c'),
            $correlationId ?? 'n/a',
            $message
        );
        error_log($line);
    }
}
