<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

/** Validation for the additive media API; legacy request parsing remains unchanged. */
final class MediaInput
{
    public static function json(): array
    {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4194304) {
            throw new CatalogApiException('REQUEST_TOO_LARGE', 'The mapping request is too large.', 413);
        }
        $body = Transport::body();
        if (strlen($body) > 4194304) {
            throw new CatalogApiException('REQUEST_TOO_LARGE', 'The mapping request is too large.', 413);
        }
        try {
            $value = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new CatalogApiException('JSON_INVALID', 'Send a valid JSON object.', 400);
        }
        if (!is_array($value) || !str_starts_with(ltrim($body), '{')) {
            throw new CatalogApiException('JSON_INVALID', 'Send a JSON object with named fields.', 400);
        }
        return $value;
    }

    public static function scalars(array $values, array $keys): void
    {
        foreach ($keys as $key) {
            if (isset($values[$key]) && !is_scalar($values[$key])) {
                throw new CatalogApiException('INPUT_INVALID', 'The field ' . $key . ' must contain one value.', 422);
            }
        }
    }
}
