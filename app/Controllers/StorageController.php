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
        if (str_starts_with($relativePath, '__library__/')
            && realpath($directory) === realpath(\CatalogSuite\Support\Config::get('app')['storage']['media'])) {
            try {
                $file = \CatalogSuite\Support\MediaRuntime::aliasPath(\CatalogSuite\Support\Db::connection(), $relativePath);
                if ($file === null) {
                    Transport::status(404);
                    return;
                }
                Transport::header('Content-Type: ' . (mime_content_type($file) ?: 'application/octet-stream'));
                Transport::header('Content-Length: ' . filesize($file));
                Transport::header('X-Content-Type-Options: nosniff');
                Transport::header("Content-Security-Policy: sandbox; default-src 'none'");
                if (!in_array(mime_content_type($file), ['image/png','image/jpeg','image/gif','image/webp','image/avif','image/bmp','application/pdf','model/gltf-binary'], true)) {
                    Transport::header('Content-Disposition: attachment');
                }
                Transport::file($file);
            } catch (\Throwable) {
                Transport::status(404);
            }
            return;
        }
        $root = realpath($directory);
        $file = realpath($directory . DIRECTORY_SEPARATOR . $relativePath);
        // The historical file API writes to its separately configured PDF root.
        // Resolve only an indexed public output when the legacy root has no file.
        $settings = \CatalogSuite\Support\Config::get('app');
        if ($file === false
            && rtrim(str_replace('\\', '/', $directory), '/') === rtrim(str_replace('\\', '/', $settings['storage']['latex_pdfs']), '/')
            && strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) === 'pdf') {
            try {
                $db = \CatalogSuite\Support\Db::connection();
                if (\CatalogSuite\Support\MediaSchema::isInstalled($db)) {
                    $repository = new \CatalogSuite\Repositories\MediaRepository($db);
                    $asset = $repository->at('api_latex_pdfs', \CatalogSuite\Support\MediaStorageRegistry::cleanRelative($relativePath));
                    if ($asset !== null && $asset['visibility'] === 'public_catalog' && in_array($asset['status'], ['ready','trashed'], true)) {
                        $file = (new \CatalogSuite\Support\MediaStorageRegistry())->absolute('api_latex_pdfs', $relativePath);
                        Transport::header('Content-Type: application/pdf');
                        Transport::header('Content-Length: ' . filesize($file));
                        Transport::header('X-Content-Type-Options: nosniff');
                        Transport::file($file);
                        return;
                    }
                }
            } catch (\Throwable) {
                // A failed fallback must retain the normal not-found response.
            }
        }
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
        Transport::header('X-Content-Type-Options: nosniff');
        Transport::file($file);
    }
}
