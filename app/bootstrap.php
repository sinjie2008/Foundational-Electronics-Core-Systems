<?php
declare(strict_types=1);

/**
 * Standalone bootstrap. Composer loads module classes without these side effects.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'CatalogSuite\\';
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

require_once __DIR__ . '/compatibility.php';
