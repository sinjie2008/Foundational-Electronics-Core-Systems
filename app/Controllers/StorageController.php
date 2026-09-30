<?php
declare(strict_types=1);

namespace CatalogSuite\Controllers;

use CatalogSuite\Http\Transport;

/** Serves the existing public media/PDF URLs from legacy storage directories. */
final class StorageController
{
    /** Stream a file inside the selected public storage directory only. */
    public function download(string $directory, string $relativePath): void
    {
        $root = realpath($directory);
        $file = realpath($directory . DIRECTORY_SEPARATOR . $relativePath);
        if ($root === false || $file === false || !is_file($file)
            || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            Transport::status(404);
            return;
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'pdf' => 'application/pdf',
            'glb' => 'model/gltf-binary',
            default => 'application/octet-stream',
        };
        Transport::header('Content-Type: ' . $contentType);
        Transport::header('Content-Length: ' . (string) filesize($file));
        Transport::file($file);
    }
}
