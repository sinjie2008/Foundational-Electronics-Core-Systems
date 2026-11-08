<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Http\CatalogApiException;

/** Physical roots are configuration, never client-provided paths. No directories are created on read. */
final class MediaStorageRegistry
{
    private const UPLOAD_TYPES = [
        'png' => ['image/png'], 'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'gif' => ['image/gif'], 'webp' => ['image/webp'], 'bmp' => ['image/bmp','image/x-ms-bmp'],
        'avif' => ['image/avif'], 'tif' => ['image/tiff'], 'tiff' => ['image/tiff'],
        'pdf' => ['application/pdf'], 'glb' => ['model/gltf-binary','application/octet-stream'],
    ];
    private array $roots;

    public function __construct()
    {
        $app = Config::get('app');
        $storage = $app['storage'];
        $this->roots = [
            'catalog_media' => $storage['media'],
            'typst_assets' => $storage['typst_assets'],
            'typst_pdfs' => $storage['typst_pdfs'],
            'legacy_latex_pdfs' => $storage['latex_pdfs'],
            // A compiler may register its own exact output here without scanning its neighbours.
            'api_latex_pdfs' => $storage['api_latex_pdfs'],
        ];
        foreach ($app['media_library']['roots'] ?? [] as $key => $path) {
            if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', (string) $key) && is_string($path)) {
                $this->roots[$key] = $path;
            }
        }
    }

    public function roots(): array
    {
        return $this->roots;
    }

    public function scanRoots(): array
    {
        $roots = $this->roots;
        if (!(Config::get('app')['media_library']['external_roots_enabled'] ?? false)) {
            unset($roots['api_latex_pdfs']);
        }
        return $roots;
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
        $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public static function cleanRelative(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || strlen($path) > 2048 || str_contains($path, "\0") || str_contains($path, ':')
            || str_starts_with($path, '/') || preg_match('#(^|/)\.\.?(/|$)#', $path)) {
            throw new CatalogApiException('MEDIA_PATH_INVALID', 'Invalid media path.', 400);
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/[\x00-\x1f]/', $part) || preg_match('/[. ]$/', $part)) {
                throw new CatalogApiException('MEDIA_PATH_INVALID', 'Invalid media path.', 400);
            }
        }
        return $path;
    }

    public static function within(string $file, string $root): bool
    {
        $file = str_replace('\\', '/', $file);
        $root = rtrim(str_replace('\\', '/', $root), '/') . '/';
        if (PHP_OS_FAMILY === 'Windows') {
            return str_starts_with(strtolower($file), strtolower($root));
        }
        return str_starts_with($file, $root);
    }

    public function absolute(string $rootKey, string $relative, bool $mustExist = true): string
    {
        $relative = self::cleanRelative($relative);
        $configured = $this->roots[$rootKey] ?? null;
        if ($configured === null) {
            throw new CatalogApiException('MEDIA_ROOT_INVALID', 'This storage location is not authorized.', 422);
        }
        $root = realpath($configured);
        if ($root === false) {
            throw new CatalogApiException('MEDIA_ROOT_MISSING', 'The storage location is unavailable.', 404);
        }
        $candidate = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $real = realpath($candidate);
        if ($real !== false) {
            if (!self::within($real, $root) || is_link($candidate) || ($mustExist && !is_file($real))) {
                throw new CatalogApiException('MEDIA_PATH_INVALID', 'The file is outside its authorized storage location.', 400);
            }
            return $real;
        }
        if ($mustExist) {
            throw new CatalogApiException('MEDIA_NOT_FOUND', 'Media file not found.', 404);
        }
        $parent = dirname($candidate);
        while (!is_dir($parent) && dirname($parent) !== $parent) {
            $parent = dirname($parent);
        }
        $realParent = realpath($parent);
        if ($realParent === false || ($realParent !== $root && !self::within($realParent, $root))) {
            throw new CatalogApiException('MEDIA_PATH_INVALID', 'Invalid media destination.', 400);
        }
        return $candidate;
    }

    public function locate(string $value): ?array
    {
        if ($value === '' || str_contains($value, "\0") || preg_match('#^(https?:|data:|//)#i', $value)) {
            return null;
        }
        $value = str_replace('\\', '/', $value);
        // Absolute legacy PDF paths are accepted only inside a configured managed root.
        if (str_starts_with($value, '/') || preg_match('/^[a-z]:\//i', $value)) {
            foreach ($this->roots as $key => $root) {
                $normalizedRoot = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
                if (self::within($value, $normalizedRoot)) {
                    return ['root' => $key, 'path' => self::cleanRelative(substr($value, strlen($normalizedRoot) + 1))];
                }
            }
            return null;
        }
        $prefixes = [
            'public/storage/typst-assets/' => 'typst_assets', 'storage/typst-assets/' => 'typst_assets',
            'typst-assets/' => 'typst_assets', 'public/storage/typst-pdfs/' => 'typst_pdfs',
            'storage/typst-pdfs/' => 'typst_pdfs', 'storage/latex-pdfs/' => 'legacy_latex_pdfs',
            'storage/media/' => 'catalog_media',
        ];
        foreach ($prefixes as $prefix => $key) {
            if (str_starts_with($value, $prefix)) {
                return ['root' => $key, 'path' => self::cleanRelative(substr($value, strlen($prefix)))];
            }
        }
        return ['root' => 'catalog_media', 'path' => self::cleanRelative($value)];
    }

    public static function filename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'file';
        // Never truncate the validated extension into a different (possibly executable) extension.
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $stem = ltrim(substr(pathinfo($name, PATHINFO_FILENAME), 0, 150), '.');
        $stem = preg_replace('/\.(?=(?:php[0-9]*|phtml|pht|phar|cgi|pl|asp|aspx|jsp|shtml|shtm)(?:\.|$))/i', '_', $stem);
        if ($stem === '' || preg_match('/^(con|prn|aux|nul|com[0-9]|lpt[0-9])$/i', $stem)) {
            $stem = 'file_' . $stem;
        }
        return $stem . ($extension === '' ? '' : '.' . substr($extension, 0, 12));
    }

    public static function extensions(): array
    {
        return array_keys(self::UPLOAD_TYPES);
    }

    public static function maxUploadBytes(): int
    {
        $max = (int) Config::get('app')['media']['max_bytes'];
        foreach (['upload_max_filesize','post_max_size'] as $key) {
            $value = trim((string) ini_get($key));
            $number = (float) $value;
            $factor = match (strtolower(substr($value, -1))) { 'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1 };
            $bytes = (int) ($number * $factor);
            if ($bytes > 0) {
                $max = min($max, $key === 'post_max_size' ? max(0, $bytes - 65536) : $bytes);
            }
        }
        return $max;
    }

    /** Inspect historical bytes too; discovery does not turn corrupt or active content into usable media. */
    public static function describe(string $path): array
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
        $supported = false;
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'glb') {
            $handle = fopen($path, 'rb');
            $header = $handle === false ? '' : fread($handle, 12);
            if (is_resource($handle)) fclose($handle);
            $info = strlen($header) === 12 ? unpack('a4magic/Vversion/Vlength', $header) : [];
            $supported = ($info['magic'] ?? '') === 'glTF' && ($info['version'] ?? 0) === 2 && ($info['length'] ?? 0) === filesize($path);
            if ($supported) $mime = 'model/gltf-binary';
        } elseif ($mime === 'application/pdf') {
            $supported = true;
        } elseif (in_array($mime, array_merge(...array_values(array_diff_key(self::UPLOAD_TYPES, ['pdf' => true,'glb' => true]))), true)) {
            $size = @getimagesize($path);
            $supported = $size !== false && (float) $size[0] * (float) $size[1] <= (int) (Config::get('app')['media_library']['max_image_pixels'] ?? 40000000);
        }
        return ['mime' => $mime,'supported' => $supported];
    }

    public static function inspectUpload(array $file): array
    {
        $original = (string) ($file['name'] ?? '');
        if ($original === '' || !mb_check_encoding($original, 'UTF-8') || mb_strlen($original, 'UTF-8') > 255 || str_contains($original, "\0")) {
            throw new CatalogApiException('MEDIA_FILENAME_INVALID', 'The filename is invalid or too long. Rename this file and retry.', 422);
        }
        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new CatalogApiException('MEDIA_UPLOAD_ERROR', in_array($uploadError, [UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE], true) ? 'This file exceeds the server upload limit.' : 'The upload did not finish. Please retry this file.', in_array($uploadError, [UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw new CatalogApiException('MEDIA_UPLOAD_INVALID', 'Invalid upload payload.', 400);
        }
        $size = filesize($tmp);
        $app = Config::get('app');
        if ($size === false || $size <= 0 || $size > (int) $app['media']['max_bytes']) {
            throw new CatalogApiException('MEDIA_TOO_LARGE', 'The file is empty or exceeds the upload limit.', 413);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: 'application/octet-stream';
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $types = self::UPLOAD_TYPES;
        if (!isset($types[$extension]) || !in_array($mime, $types[$extension], true)) {
            throw new CatalogApiException('MEDIA_TYPE_INVALID', 'The file extension and content must be a supported image, PDF or GLB.', 415);
        }
        if ($extension === 'glb') {
            $handle = fopen($tmp, 'rb');
            $header = $handle === false ? '' : fread($handle, 12);
            if (is_resource($handle)) {
                fclose($handle);
            }
            $info = strlen($header) === 12 ? unpack('a4magic/Vversion/Vlength', $header) : [];
            if (($info['magic'] ?? '') !== 'glTF' || ($info['version'] ?? 0) !== 2 || ($info['length'] ?? 0) !== $size) {
                throw new CatalogApiException('MEDIA_TYPE_INVALID', 'Invalid GLB file.', 415);
            }
            $mime = 'model/gltf-binary';
        } elseif (str_starts_with($mime, 'image/')) {
            $image = @getimagesize($tmp);
            if (!$image || (float) $image[0] * (float) $image[1] > (int) ($app['media_library']['max_image_pixels'] ?? 40000000)) {
                throw new CatalogApiException('MEDIA_IMAGE_INVALID', 'The image is invalid or its dimensions are too large.', 415);
            }
        }
        return ['mime' => $mime, 'size' => $size, 'sha256' => hash_file('sha256', $tmp), 'extension' => $extension];
    }
}
