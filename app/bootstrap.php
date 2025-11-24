<?php
declare(strict_types=1);

/**
 * Minimal bootstrap providing autoloading for the App namespace.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

// Ensure MySQLi throws exceptions for predictable error handling.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
