<?php
declare(strict_types=1);

namespace CatalogSuite\Http;

use CatalogSuite\Support\Config;

/** Validates client keys against public stored definitions before any query is assembled. */
final class CatalogPartsQuery
{
    public static function pagination(array $query): array
    {
        $config = Config::get('catalog-v1');
        return [
            'page' => self::integer($query['page'] ?? 1, 1, 1000000, 'page'),
            'per_page' => self::integer($query['per_page'] ?? $config['default_per_page'], 1, (int) $config['max_per_page'], 'per_page'),
        ];
    }

    public static function integer(mixed $value, int $minimum, int $maximum, string $key): int
    {
        if ((!is_int($value) && !is_string($value)) || preg_match('/^[0-9]+$/D', (string) $value) !== 1
            || strlen((string) $value) > 10 || (int) $value < $minimum || (int) $value > $maximum) {
            self::invalid($key);
        }
        return (int) $value;
    }

    public static function text(mixed $value, string $key, int $maximum = 256): string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            self::invalid($key);
        }
        return trim($value);
    }

    public static function only(array $query, array $allowed): void
    {
        foreach (array_keys($query) as $key) {
            if (!in_array($key, $allowed, true)) {
                self::invalid('query');
            }
        }
    }

    public static function parse(array $query, array $fields): array
    {
        self::only($query, ['page', 'per_page', 'search', 'sort', 'direction', 'filter']);
        $map = [];
        foreach ($fields as $field) {
            $map[$field['field_key']] = $field;
        }
        $result = self::pagination($query);
        $result['search'] = self::text($query['search'] ?? '', 'search');
        $sort = isset($query['sort']) ? self::text($query['sort'], 'sort', 64) : null;
        if ($sort !== null && (!isset($map[$sort]) || !(bool) $map[$sort]['is_sortable'] || $map[$sort]['field_type'] === 'file')) {
            self::invalid('sort');
        }
        $direction = $query['direction'] ?? 'asc';
        if (!is_string($direction) || !in_array($direction, ['asc', 'desc'], true)) {
            self::invalid('direction');
        }
        $result['sort'] = $sort === null ? null : $map[$sort];
        $result['direction'] = $direction;
        $filters = $query['filter'] ?? [];
        if (!is_array($filters) || count($filters) > 50) {
            self::invalid('filter');
        }
        $result['filters'] = [];
        foreach ($filters as $key => $values) {
            if (!isset($map[$key]) || !(bool) $map[$key]['is_filterable'] || $map[$key]['field_type'] === 'file') {
                self::invalid('filter');
            }
            $field = $map[$key];
            if ($field['filter_type'] === 'range') {
                if ($field['field_type'] !== 'number' || !is_array($values) || array_is_list($values) || $values === []) {
                    self::invalid('filter');
                }
                self::only($values, ['min', 'max']);
                foreach ($values as $value) {
                    if ((!is_string($value) && !is_int($value) && !is_float($value))
                        || preg_match('/^[+-]?(?:[0-9]{1,45}(?:\.[0-9]{0,20})?|\.[0-9]{1,20})$/D', (string) $value) !== 1
                        || strlen((string) $value) > 67) {
                        self::invalid('filter');
                    }
                }
                if (isset($values['min'], $values['max']) && self::compareDecimal((string) $values['min'], (string) $values['max']) > 0) {
                    self::invalid('filter');
                }
            } else {
                $values = is_array($values) ? $values : [$values];
                if (!array_is_list($values) || $values === [] || count($values) > 100) {
                    self::invalid('filter');
                }
                $values = array_map(static function (mixed $value): string {
                    if (is_int($value) || is_float($value)) {
                        $value = (string) $value;
                    }
                    return self::text($value, 'filter', 1024);
                }, $values);
            }
            $result['filters'][] = ['field' => $field, 'values' => $values];
        }
        return $result;
    }

    /** Validate ordering without losing precision through a floating-point conversion. */
    private static function compareDecimal(string $left, string $right): int
    {
        $split = static function (string $value): array {
            $negative = str_starts_with($value, '-');
            [$integer, $fraction] = [...explode('.', ltrim($value, '+-'), 2), ''];
            $integer = ltrim($integer, '0') ?: '0';
            $fraction = rtrim($fraction, '0');
            return [$negative && ($integer !== '0' || $fraction !== ''), $integer, $fraction];
        };
        [$negativeLeft, $integerLeft, $fractionLeft] = $split($left);
        [$negativeRight, $integerRight, $fractionRight] = $split($right);
        if ($negativeLeft !== $negativeRight) {
            return $negativeLeft ? -1 : 1;
        }
        $order = strlen($integerLeft) <=> strlen($integerRight);
        $order = $order ?: (strcmp($integerLeft, $integerRight) <=> 0);
        $length = max(strlen($fractionLeft), strlen($fractionRight));
        $order = $order ?: (strcmp(str_pad($fractionLeft, $length, '0'), str_pad($fractionRight, $length, '0')) <=> 0);
        return $negativeLeft ? -$order : $order;
    }

    private static function invalid(string $key): never
    {
        throw new CatalogApiException('VALIDATION_ERROR', 'Invalid or unavailable query parameter.', 422, ['parameter' => $key]);
    }
}
