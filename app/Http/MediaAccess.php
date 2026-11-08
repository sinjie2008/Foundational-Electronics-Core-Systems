<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

use CatalogSuite\Support\Config;

/** Standalone prototype access; Laravel hosts can supply their own authorization policy. */
final class MediaAccess
{
    public static function enabled(): bool
    {
        return (bool) (Config::get('app')['media_library']['enabled'] ?? false);
    }

    public static function requirePermission(string $permission): array
    {
        $settings = Config::get('app')['media_library'] ?? [];
        if (!self::enabled()) {
            throw new CatalogApiException('MEDIA_DISABLED', 'Media Library setup has not been completed yet.', 503);
        }
        \CatalogSuite\Support\MediaRateLimiter::check();
        $policy = $settings['authorize'] ?? null;
        if (is_callable($policy)) {
            $identity = $policy($permission);
            if (is_array($identity) && !empty($identity['actor'])
                && (in_array('*', $identity['permissions'] ?? [], true) || in_array($permission, $identity['permissions'] ?? [], true))) {
                if (!in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','HEAD','OPTIONS'], true)
                    && isset($identity['csrfToken'])
                    && !hash_equals((string) $identity['csrfToken'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
                    throw new CatalogApiException('CSRF_INVALID', 'Refresh your session before saving changes.', 403);
                }
                $_SERVER['CATALOG_MEDIA_ACTOR'] = (string) $identity['actor'];
                return $identity;
            }
            throw new CatalogApiException('FORBIDDEN', 'You do not have permission for this operation.', 403);
        }
        $_SERVER['CATALOG_MEDIA_ACTOR'] = 'standalone-prototype';
        return ['actor' => 'standalone-prototype', 'permissions' => ['*']];
    }

    public static function actor(): string
    {
        return isset($_SERVER['CATALOG_MEDIA_ACTOR']) ? (string) $_SERVER['CATALOG_MEDIA_ACTOR'] : (string) self::requirePermission('view')['actor'];
    }

    public static function isLegacyMutation(): bool
    {
        if (in_array(strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','HEAD','OPTIONS'], true)) {
            return false;
        }
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH);
        return !(basename($path) === 'catalog.php' && in_array((string) ($_GET['action'] ?? ''), ['v1.specSearchFacets','v1.specSearchProducts'], true));
    }

    public static function guardLegacyReadAction(string $action): void
    {
        if (self::enabled() && in_array($action, ['v1.listCsvHistory','v1.downloadCsv','v1.listLatexTemplates','v1.getLatexTemplate'], true)) {
            self::requirePermission('view');
        }
    }

    /** Called by legacy mutation controllers only when the module is enabled. */
    public static function guardLegacyWrite(?\mysqli $db = null): void
    {
        if (self::enabled()) {
            if (self::isLegacyMutation()) {
                self::requirePermission('catalog_write');
            }
            if ($db !== null) {
                \CatalogSuite\Support\MediaSchema::requireInstalled($db);
            }
        }
    }
}
