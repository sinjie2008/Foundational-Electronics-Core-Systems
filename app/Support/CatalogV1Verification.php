<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Http\CatalogApiException;
use CatalogSuite\Services\CatalogV1Service;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Actual-data assertions use template bindings, never catalog-specific names or field keys. */
final class CatalogV1Verification
{
    private array $results = [];
    private array $responses = [];

    public function __construct(private array $variables)
    {
    }

    private function value(string $pointer): mixed
    {
        $value = $this->variables;
        foreach (explode('.', $pointer) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                throw new InvalidArgumentException('Missing verification variable: ' . $pointer);
            }
            $value = $value[$key];
        }
        return $value;
    }

    private function bound(array $binding): array
    {
        $values = [];
        foreach ($binding as $key => $pointer) {
            if (!is_string($pointer)) {
                throw new InvalidArgumentException('Verification bindings must be variable pointers.');
            }
            $values[$key] = $this->value($pointer);
        }
        foreach (['path', 'slug', 'part_number'] as $key) {
            if (!is_string($values[$key] ?? null) || trim($values[$key]) === '') {
                throw new InvalidArgumentException('Fill the actual ' . $key . ' variable before verification.');
            }
        }
        self::catalogPath($values['path']);
        foreach (['field_count', 'part_count'] as $key) {
            if (!is_int($values[$key] ?? null) || $values[$key] < 0) {
                throw new InvalidArgumentException('Fill the actual ' . $key . ' count before verification.');
            }
        }
        foreach (['field_keys', 'blocks', 'asset_roles', 'download_roles'] as $key) {
            if (!is_array($values[$key] ?? null) || !array_is_list($values[$key])) {
                throw new InvalidArgumentException('Expected an actual ordered list for ' . $key . '.');
            }
        }
        if (count($values['field_keys']) !== $values['field_count']) {
            throw new InvalidArgumentException('Expected field keys and field count disagree.');
        }
        return $values;
    }

    private function check(string $name, callable $assert): void
    {
        try {
            $assert();
            $this->results[] = ['name' => $name, 'status' => 'passed'];
        } catch (Throwable $error) {
            $this->results[] = ['name' => $name, 'status' => 'failed', 'message' => $error->getMessage()];
        }
    }

    private static function equal(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message);
        }
    }

    /** Objects assert supplied properties; lists assert their complete order and length. */
    private static function matches(mixed $expected, mixed $actual, string $property = 'data'): void
    {
        if (!is_array($expected)) {
            self::equal($expected, $actual, 'Actual composition differs at ' . $property . '.');
            return;
        }
        if (!is_array($actual)) {
            throw new RuntimeException('Missing actual composition object/list at ' . $property . '.');
        }
        if (array_is_list($expected)) {
            if (!array_is_list($actual) || count($expected) !== count($actual)) {
                throw new RuntimeException('Actual composition list length differs at ' . $property . '.');
            }
        }
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                throw new RuntimeException('Missing actual composition property ' . $property . '.' . $key . '.');
            }
            self::matches($value, $actual[$key], $property . '.' . $key);
        }
    }

    private function get(callable $http, string $path, array $query = [], int $status = 200): array
    {
        self::apiPath($path);
        $url = '/api/v1/catalog' . $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $response = $http($url);
        self::equal($status, $response['status'] ?? null, 'Unexpected HTTP status for ' . $url);
        $body = json_decode((string) ($response['body'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
        self::equal($status === 200, $body['success'] ?? null, 'Invalid API envelope for ' . $url);
        $this->responses[] = $body;
        return $status === 200 ? $body['data'] : $body;
    }

    private static function path(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function catalogPath(string $path): void
    {
        if ($path === '' || trim($path, '/') !== $path) {
            throw new InvalidArgumentException('Actual catalog paths must be relative persistent paths.');
        }
        try {
            CatalogV1Service::normalizePath($path);
        } catch (CatalogApiException $error) {
            throw new InvalidArgumentException('Invalid actual catalog path.', 0, $error);
        }
    }

    /** Never let server URL normalization leave the configured catalog API namespace. */
    private static function apiPath(string $path): void
    {
        if ($path === '') {
            return;
        }
        $decoded = rawurldecode($path);
        if (!str_starts_with($path, '/') || preg_match('/%(?![0-9a-f]{2})/i', $path) === 1
            || !mb_check_encoding($decoded, 'UTF-8') || preg_match('/[?#%\x00-\x20\x7f\\\\]/', $decoded) === 1
            || str_contains($decoded, '//') || in_array('.', explode('/', $decoded), true)
            || in_array('..', explode('/', $decoded), true)) {
            throw new InvalidArgumentException('Invalid relative catalog API assertion path.');
        }
    }

    private static function hasToken(mixed $value, string $token): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if ((string) $key === $token || self::hasToken($item, $token)) {
                    return true;
                }
            }
        }
        return is_string($value) && $value === $token;
    }

    /** Missing actual values are rejected before the first HTTP request. */
    public function verify(callable $http): array
    {
        $bindings = $this->variables['verification_bindings'] ?? null;
        if (!is_array($bindings) || !isset($bindings['primary_series'], $bindings['secondary_series'], $bindings['categories'])) {
            throw new InvalidArgumentException('Missing verification bindings.');
        }
        $primary = $this->bound($bindings['primary_series']);
        $secondary = $this->bound($bindings['secondary_series']);
        $categories = array_map(fn (string $pointer): mixed => $this->value($pointer), $bindings['categories']);
        foreach ($categories as $path) {
            if (!is_string($path) || trim($path) === '') {
                throw new InvalidArgumentException('Fill every actual category path before verification.');
            }
            self::catalogPath($path);
        }
        foreach (['filter_field', 'sort_field', 'first_sorted_part_number'] as $key) {
            if (!is_string($primary[$key] ?? null) || trim($primary[$key]) === '') {
                throw new InvalidArgumentException('Fill the actual ' . $key . ' variable before verification.');
            }
        }
        if ((!is_scalar($primary['filter_value'] ?? null) && !is_array($primary['filter_value'] ?? null)) || !is_int($primary['filter_count'] ?? null) || $primary['filter_count'] < 1) {
            throw new InvalidArgumentException('Fill the actual filter value and matching count before verification.');
        }
        if ($primary['path'] === $secondary['path'] || $primary['field_keys'] === $secondary['field_keys']) {
            throw new InvalidArgumentException('Choose another actual series with a different public schema.');
        }
        $this->results = [];
        $this->responses = [];
        $composition = isset($bindings['composition']) ? $this->value($bindings['composition']) : [];
        if (!is_array($composition) || (array_key_exists('responses', $composition)
            && (!is_array($composition['responses']) || !array_is_list($composition['responses'])))) {
            throw new InvalidArgumentException('Composition responses must be an ordered list.');
        }
        foreach ($composition['responses'] ?? [] as $response) {
            if (!is_array($response) || !is_string($response['path'] ?? null)
                || ($response['path'] !== '' && !str_starts_with($response['path'], '/'))
                || preg_match('/[?#\x00-\x20\x7f]/', $response['path']) === 1
                || !is_array($response['query'] ?? [])
                || !is_array($response['expected'] ?? null) || array_is_list($response['expected'])
                || (isset($response['name']) && (!is_string($response['name']) || $response['name'] === ''))) {
                throw new InvalidArgumentException('Each composition response needs a relative API path and a nonempty expected object.');
            }
            self::apiPath($response['path']);
        }
        if (array_key_exists('family_tables', $composition)
            && (!is_array($composition['family_tables']) || !array_is_list($composition['family_tables']))) {
            throw new InvalidArgumentException('Family table assertions must be an ordered list.');
        }
        foreach ($composition['family_tables'] ?? [] as $table) {
            if (!is_array($table) || !is_string($table['category_path'] ?? null)
                || !is_string($table['block_key'] ?? null) || trim($table['block_key']) === ''
                || !is_array($table['expected_column_keys'] ?? null) || !array_is_list($table['expected_column_keys'])
                || !is_array($table['expected_series_paths'] ?? null) || !array_is_list($table['expected_series_paths'])) {
                throw new InvalidArgumentException('Each family table assertion needs an actual category path, block key and ordered column/path lists.');
            }
            self::catalogPath($table['category_path']);
            foreach ($table['expected_column_keys'] as $key) {
                if (!is_string($key) || $key === '') {
                    throw new InvalidArgumentException('Expected family table column keys must be nonempty strings.');
                }
            }
            foreach ($table['expected_series_paths'] as $path) {
                if (!is_string($path)) {
                    throw new InvalidArgumentException('Expected family table series paths must be strings.');
                }
                self::catalogPath($path);
            }
        }
        $this->check('catalog root composition', function () use ($http, $composition): void {
            $root = $this->get($http, '');
            if (!isset($root['breadcrumb'], $root['groups'], $root['collections']) || $root['hero'] === null) {
                throw new RuntimeException('Root hero, breadcrumb, groups or collections are missing.');
            }
            foreach (['expected_group_paths' => ['groups', 'path'], 'expected_collection_keys' => ['collections', 'key']] as $key => [$resource, $column]) {
                if (($composition[$key] ?? []) !== []) {
                    self::equal($composition[$key], array_column($root[$resource], $column), 'Root ordering differs from actual expectations.');
                }
            }
        });
        $this->check('recursive catalog tree', function () use ($http): void {
            $tree = $this->get($http, '/tree');
            if ($tree === []) {
                throw new RuntimeException('The public catalog tree is empty.');
            }
        });
        foreach ($categories as $path) {
            $this->check('category ' . $path, function () use ($http, $path): void {
                $resolved = $this->get($http, '/resolve/' . self::path($path));
                $category = $this->get($http, '/categories/' . self::path($path));
                self::equal('category', $resolved['resource']['type'], 'Expected a category.');
                self::equal($resolved['resource']['id'], $category['resource']['id'], 'Resolved category identities differ.');
                if (!isset($category['breadcrumb'], $category['navigation'], $category['sections'])) {
                    throw new RuntimeException('Category page structure is missing.');
                }
            });
        }
        foreach (['primary' => $primary, 'secondary' => $secondary] as $label => $expected) {
            $base = '/series/' . self::path($expected['path']);
            $this->check($label . ' series composition and media', function () use ($http, $base, $expected): void {
                $series = $this->get($http, $base);
                self::equal($expected['slug'], $series['resource']['slug'], 'Persistent series slug differs.');
                self::equal($expected['blocks'], array_column($series['blocks'], 'key'), 'Content block presence or order differs.');
                foreach (['asset_roles' => 'assets', 'download_roles' => 'documents'] as $key => $resource) {
                    foreach ($expected[$key] as $role) {
                        $matching = array_values(array_filter($series[$resource], static fn (array $asset): bool => $asset['role'] === $role));
                        if ($matching === [] || in_array(false, array_column($matching, 'available'), true)) {
                            throw new RuntimeException('Expected asset or document role is absent or its local file is unavailable.');
                        }
                    }
                }
                if (isset($expected['title'])) {
                    self::equal($expected['title'], $series['resource']['title'], 'Series title differs.');
                }
            });
            $this->check($label . ' dynamic fields and columns', function () use ($http, $base, $expected): void {
                $schema = $this->get($http, $base . '/fields');
                self::equal($expected['field_count'], count($schema['fields']), 'Public field count differs.');
                self::equal($expected['field_keys'], array_column($schema['fields'], 'field_key'), 'Public field keys or order differ.');
                if (isset($expected['table_columns'])) {
                    self::equal($expected['table_columns'], array_column($schema['columns'], 'field_key'), 'Dynamic table columns differ.');
                }
            });
            $this->check($label . ' server-side part pagination and counts', function () use ($http, $base, $expected): void {
                $page = $this->get($http, $base . '/parts', ['per_page' => 2]);
                self::equal($expected['part_count'], $page['pagination']['total'], 'Actual part count differs.');
                self::equal(min(2, $expected['part_count']), count($page['rows']), 'First page row count differs.');
                if ($expected['part_count'] > 2) {
                    $second = $this->get($http, $base . '/parts', ['per_page' => 2, 'page' => 2]);
                    self::equal($expected['part_count'], $second['pagination']['total'], 'Pagination total changes between pages.');
                    self::equal([], array_values(array_intersect(array_column($page['rows'], 'id'), array_column($second['rows'], 'id'))), 'Page rows overlap.');
                }
            });
            $this->check($label . ' known actual part search', function () use ($http, $base, $expected): void {
                $parts = $this->get($http, $base . '/parts', ['search' => $expected['part_number']]);
                if (!in_array($expected['part_number'], array_column($parts['rows'], 'sku'), true)) {
                    throw new RuntimeException('Actual known part was not found in part search.');
                }
            });
        }
        $base = '/series/' . self::path($primary['path']);
        $this->check('actual dynamic filtering and facets', function () use ($http, $base, $primary): void {
            $filter = is_array($primary['filter_value']) ? $primary['filter_value'] : [$primary['filter_value']];
            $parts = $this->get($http, $base . '/parts', ['filter' => [$primary['filter_field'] => $filter]]);
            self::equal($primary['filter_count'], $parts['pagination']['total'], 'Filtered count differs.');
            $facets = $this->get($http, $base . '/facets');
            if (!in_array($primary['filter_field'], array_column($facets, 'field_key'), true)) {
                throw new RuntimeException('Configured filter field is absent from facets.');
            }
        });
        $this->check('actual dynamic sorting', function () use ($http, $base, $primary): void {
            $parts = $this->get($http, $base . '/parts', ['sort' => $primary['sort_field'], 'direction' => 'asc']);
            self::equal($primary['first_sorted_part_number'], $parts['rows'][0]['sku'] ?? null, 'First ascending part differs.');
        });
        $this->check('global actual part search', function () use ($http, $primary): void {
            $matches = $this->get($http, '/search', ['q' => $primary['part_number']]);
            if (!in_array($primary['part_number'], array_column($matches['rows'], 'sku'), true)) {
                throw new RuntimeException('Known part is absent from global search.');
            }
        });
        foreach ($composition['family_tables'] ?? [] as $table) {
            $this->check('actual family table ' . $table['category_path'] . '/' . $table['block_key'], function () use ($http, $table): void {
                $category = $this->get($http, '/categories/' . self::path($table['category_path']));
                $blocks = array_values(array_filter($category['blocks'], static fn (array $block): bool => $block['key'] === $table['block_key'] && $block['type'] === 'series_table'));
                if ($blocks === []) {
                    throw new RuntimeException('Configured family table is missing.');
                }
                self::equal($table['expected_column_keys'], array_column($blocks[0]['payload']['columns'], 'key'), 'Family table columns differ.');
                self::equal($table['expected_series_paths'], array_column(array_column($blocks[0]['payload']['rows'], 'resource'), 'path'), 'Family table rows or order differ.');
            });
        }
        foreach ($composition['responses'] ?? [] as $response) {
            $this->check('actual composition ' . ($response['name'] ?? ($response['path'] ?: 'root')), function () use ($http, $response): void {
                self::matches($response['expected'], $this->get($http, $response['path'], $response['query'] ?? []));
            });
        }
        if (is_string($primary['hidden_field'] ?? null) && $primary['hidden_field'] !== '') {
            $this->check('known hidden field is absent and cannot be queried', function () use ($http, $base, $primary): void {
                if (self::hasToken($this->responses, $primary['hidden_field'])) {
                    throw new RuntimeException('Known hidden field leaked in a public response.');
                }
                $this->get($http, $base . '/parts', ['sort' => $primary['hidden_field']], 422);
                $this->get($http, $base . '/parts', ['filter' => [$primary['hidden_field'] => ['verification-probe']]], 422);
            });
        } else {
            $this->results[] = ['name' => 'known actual hidden field', 'status' => 'skipped', 'message' => 'No actual hidden field was supplied; engineering privacy tests cover this gate.'];
        }
        $counts = array_count_values(array_column($this->results, 'status'));
        return ['passed' => $counts['passed'] ?? 0, 'failed' => $counts['failed'] ?? 0, 'skipped' => $counts['skipped'] ?? 0,
            'actual_data_acceptance' => ($counts['failed'] ?? 0) === 0, 'checks' => $this->results];
    }
}
