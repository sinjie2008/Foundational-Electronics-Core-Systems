<?php
declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Structured JSONL logger for API/service boundaries.
 */
final class Logger
{
    /** @var array<string, int> */
    private const LEVELS = [
        'debug' => 10,
        'info' => 20,
        'warn' => 30,
        'error' => 40,
    ];

    /**
     * Log an info message.
     *
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = [], ?string $correlationId = null): void
    {
        self::log('info', $message, $context, $correlationId);
    }

    /**
     * Log a warning message.
     *
     * @param array<string, mixed> $context
     */
    public static function warn(string $message, array $context = [], ?string $correlationId = null): void
    {
        self::log('warn', $message, $context, $correlationId);
    }

    /**
     * Log an error message.
     *
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = [], ?string $correlationId = null): void
    {
        self::log('error', $message, $context, $correlationId);
    }

    /**
     * Core logger with level filtering and optional rotation.
     *
     * @param array<string, mixed> $context
     */
    public static function log(string $level, string $message, array $context = [], ?string $correlationId = null): void
    {
        try {
            $loggingConfig = Config::get('app')['logging'] ?? [];
            $enabled = $loggingConfig['enabled'] ?? true;
            if ($enabled === false) {
                return;
            }

            $levelKey = strtolower($level);
            $thresholdKey = strtolower((string) ($loggingConfig['level'] ?? 'info'));
            $levelValue = self::LEVELS[$levelKey] ?? self::LEVELS['info'];
            $thresholdValue = self::LEVELS[$thresholdKey] ?? self::LEVELS['info'];
            if ($levelValue < $thresholdValue) {
                return;
            }

            $logPath = (string) ($loggingConfig['path'] ?? __DIR__ . '/../../storage/logs/app.log');
            self::ensureLogDirectory($logPath);

            $timezone = (string) ($loggingConfig['timezone'] ?? date_default_timezone_get());
            $timestamp = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format(DATE_ATOM);

            $record = [
                'ts' => $timestamp,
                'level' => $levelKey,
                'corr' => $correlationId,
                'msg' => $message,
                'context' => self::sanitizeContext($context),
            ];

            $line = json_encode($record, JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                return;
            }

            self::rotateIfNeeded($logPath, (int) ($loggingConfig['rotation']['max_bytes'] ?? 0));
            file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable $logError) {
            // Avoid crashing the app due to logging failures.
            error_log('[logger] ' . $logError->getMessage());
        }
    }

    /**
     * Ensure the log directory exists.
     */
    private static function ensureLogDirectory(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    /**
     * Rotate the log file when exceeding the configured size.
     */
    private static function rotateIfNeeded(string $path, int $maxBytes): void
    {
        if ($maxBytes <= 0 || !is_file($path)) {
            return;
        }
        if (filesize($path) < $maxBytes) {
            return;
        }
        $suffix = date('Ymd_His');
        @rename($path, $path . '.' . $suffix);
    }

    /**
     * Sanitize context to JSON-safe scalars/arrays.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private static function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $clean[$key] = self::normalizeValue($value);
        }
        return $clean;
    }

    /**
     * Normalize values for logging (avoid leaking objects or secrets).
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeValue($value)
    {
        if ($value instanceof Throwable) {
            return [
                'type' => get_class($value),
                'message' => $value->getMessage(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
            ];
        }
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (is_array($value)) {
            $trimmed = [];
            foreach ($value as $k => $v) {
                $trimmed[$k] = self::normalizeValue($v);
            }
            return $trimmed;
        }
        if (is_object($value)) {
            return ['type' => get_class($value)];
        }
        return '[unserializable]';
    }
}
