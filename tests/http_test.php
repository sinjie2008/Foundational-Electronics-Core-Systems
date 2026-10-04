<?php
declare(strict_types=1);

use CatalogSuite\Repositories\TypstRepository;
use CatalogSuite\Support\Config;

require_once __DIR__ . '/Fixtures.php';

return static function (TestSuite $t, mysqli $db): void {
    $root = dirname(__DIR__);
    $settings = Config::get('db');
    $storage = dirname(Config::get('app')['storage']['media']);
    $environment = [...getenv(), 'CATALOG_DB_DATABASE' => $settings['database'], 'CATALOG_STORAGE_ROOT' => $storage, 'CATALOG_SEED_DEMO' => 'false'];
    $cli = static function (array $arguments) use ($root, $environment): array {
        $process = proc_open([PHP_BINARY, ...$arguments], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the CLI test process.');
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'out' => $out, 'err' => $err];
    };
    $t->test('migration CLI is repeatable and creates no catalog seed records', static function () use ($t, $cli, $db): void {
        $response = $cli(['scripts/migrate_public_catalog_v1.php', '--up']);
        $t->same(0, $response['exit']);
        $t->same('', $response['err']);
        foreach (['category', 'product', 'series_custom_field', 'seed_migration'] as $table) {
            $t->same(0, (int) $db->query("SELECT COUNT(*) FROM `{$table}`")->fetch_row()[0]);
        }
    });
    $t->test('import CLI validates a dry run and rolls malformed manifests back', static function () use ($t, $cli, $storage, $db): void {
        $file = $storage . '/runtime-import.json';
        file_put_contents($file, '{"version":"public-product-api-v1","nodes":[]}');
        $dry = $cli(['scripts/import_public_catalog_v1.php', '--file=' . $file, '--dry-run']);
        $t->same(0, $dry['exit']);
        $t->same(true, json_decode($dry['out'], true, 512, JSON_THROW_ON_ERROR)['dry_run']);
        file_put_contents($file, '{"version":"public-product-api-v1","nodes":[{"path":"runtime-http-invalid","type":"category"}]}');
        $invalid = $cli(['scripts/import_public_catalog_v1.php', '--file=' . $file]);
        $t->same(1, $invalid['exit']);
        $t->same(0, (int) $db->query('SELECT COUNT(*) FROM category')->fetch_row()[0]);
        unlink($file);
    });
    $t->test('actual-data CLI refuses the unfilled verification template before HTTP access', static function () use ($t, $cli): void {
        $response = $cli(['scripts/verify_public_catalog_v1.php', '--base-url=http://127.0.0.1:1']);
        $t->same(2, $response['exit']);
        $t->truth(str_contains($response['err'], 'Fill the actual path'));
        $t->truth(!str_contains($response['err'], 'HTTP request failed'));
    });
    $f = Fixtures::catalog($db);
    new TypstRepository($db);
    $media = Config::get('app')['storage']['media'];
    mkdir($media, 0700, true);
    file_put_contents($media . '/runtime-http.pdf', "%PDF-1.4\nGeneric native HTTP fixture\n");
    $asset = Fixtures::insert($db, 'catalog_asset', ['owner_type' => 'series', 'owner_id' => $f['seriesA'], 'asset_key' => 'runtime-http-download',
        'role' => 'runtime-http-role', 'file_path' => 'runtime-http.pdf', 'mime_type' => 'application/pdf', 'is_public' => 1, 'is_download' => 1]);
    $inline = Fixtures::insert($db, 'catalog_asset', ['owner_type' => 'series', 'owner_id' => $f['seriesA'], 'asset_key' => 'runtime-http-inline',
        'role' => 'runtime-http-inline-role', 'file_path' => 'runtime-http.pdf', 'mime_type' => 'application/pdf', 'is_public' => 1]);
    $start = static function (array $overrides = []) use ($root, $storage, $environment): array {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $error, $message);
        if ($socket === false) {
            throw new RuntimeException('Unable to allocate a native HTTP test port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $process = proc_open([PHP_BINARY, '-S', $address, '-t', $root . '/public', $root . '/scripts/serve.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $storage . '/http-out.log', 'a'], 2 => ['file', $storage . '/http-error.log', 'a']], $pipes, $root, [...$environment, ...$overrides]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start native PHP HTTP test server.');
        }
        fclose($pipes[0]);
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $probe = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
            if ($probe !== false) {
                fclose($probe);
                return [$process, 'http://' . $address];
            }
            usleep(100000);
        }
        proc_terminate($process);
        proc_close($process);
        throw new RuntimeException('Native HTTP test server did not start.');
    };
    $http = static function (string $base, string $path, string $method = 'GET'): array {
        $body = file_get_contents($base . $path, false, stream_context_create(['http' => [
            'method' => $method, 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "Accept: application/json\r\n",
        ]]));
        $headers = [];
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $header, $match)) {
                $status = (int) $match[1];
            } elseif (str_contains($header, ': ')) {
                [$name, $value] = explode(': ', $header, 2);
                $headers[strtolower($name)] = $value;
            }
        }
        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    };
    [$process, $base] = $start();
    try {
        $t->test('native HTTP dispatcher serves every required V1 JSON route', static function () use ($t, $http, $base, $f): void {
            foreach (['', '/tree', '/resolve/runtime-root', '/categories/runtime-root/runtime-group', '/series/' . $f['pathA'],
                '/series/' . $f['pathA'] . '/fields', '/series/' . $f['pathA'] . '/parts', '/series/' . $f['pathA'] . '/facets', '/search?q=Runtime'] as $path) {
                $response = $http($base, '/api/v1/catalog' . $path);
                $t->same(200, $response['status']);
                $t->same(true, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['success']);
                $t->truth(isset($response['headers']['x-correlation-id']));
                $t->same('nosniff', $response['headers']['x-content-type-options']);
            }
        });
        $t->test('native HTTP query parsing supports encoded filter arrays sort search and paging', static function () use ($t, $http, $base, $f): void {
            $query = http_build_query(['search' => 'test-even', 'filter' => ['a_scalar' => ['2', '10']], 'sort' => 'a_scalar', 'direction' => 'asc', 'per_page' => 1, 'page' => 2], '', '&', PHP_QUERY_RFC3986);
            $response = $http($base, '/api/v1/catalog/series/' . $f['pathA'] . '/parts?' . $query);
            $data = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['data'];
            $t->same(200, $response['status']);
            $t->same(2, $data['pagination']['total']);
            $t->same(['TEST-PART-1'], array_column($data['rows'], 'sku'));
        });
        $t->test('native HTTP emits guarded file bytes and configured media disposition', static function () use ($t, $http, $base, $asset, $inline): void {
            $response = $http($base, '/api/v1/catalog/assets/' . $asset);
            $t->same(200, $response['status']);
            $t->same("%PDF-1.4\nGeneric native HTTP fixture\n", $response['body']);
            $t->truth(str_starts_with($response['headers']['content-disposition'], 'attachment;'));
            $t->truth(str_starts_with($http($base, '/api/v1/catalog/assets/' . $inline)['headers']['content-disposition'], 'inline;'));
        });
        $t->test('native HTTP retains legacy action and file API envelopes', static function () use ($t, $http, $base): void {
            foreach (['/catalog.php?action=v1.listHierarchy', '/api/catalog/hierarchy.php', '/api/spec-search/root-categories.php'] as $path) {
                $response = $http($base, $path);
                $t->same(200, $response['status']);
                $t->same(true, json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR)['success']);
            }
        });
        $t->test('native HTTP malformed queries missing paths and write methods fail safely', static function () use ($t, $http, $base, $f): void {
            $t->same(422, $http($base, '/api/v1/catalog/series/' . $f['pathA'] . '/parts?page[]=1')['status']);
            $t->same(404, $http($base, '/api/v1/catalog/resolve/runtime-missing')['status']);
            $t->same(405, $http($base, '/api/v1/catalog', 'POST')['status']);
        });
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
    $user = 'runtime_read_' . bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(16));
    $db->query("CREATE USER '{$user}'@'127.0.0.1' IDENTIFIED BY '{$password}'");
    $database = $settings['database'];
    $db->query("GRANT SELECT ON `{$database}`.* TO '{$user}'@'127.0.0.1'");
    try {
        [$process, $base] = $start(['CATALOG_DB_USERNAME' => $user, 'CATALOG_DB_PASSWORD' => $password]);
        try {
            $t->test('V1 public GET requests work with only SELECT database privileges', static function () use ($t, $http, $base, $f): void {
                foreach (['', '/tree', '/categories/runtime-root', '/series/' . $f['pathA'], '/series/' . $f['pathA'] . '/parts', '/series/' . $f['pathA'] . '/facets', '/search?q=Runtime'] as $path) {
                    $t->same(200, $http($base, '/api/v1/catalog' . $path)['status']);
                }
            });
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    } finally {
        $db->query("DROP USER '{$user}'@'127.0.0.1'");
    }
};
