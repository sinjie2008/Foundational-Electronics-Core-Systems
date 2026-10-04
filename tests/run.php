<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/TestSuite.php';

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) !== 0) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
    return false;
});

$settings = CatalogSuite\Support\Config::get('db');
$server = new mysqli((string) $settings['host'], (string) $settings['username'], (string) $settings['password'], '', (int) $settings['port']);
$server->set_charset('utf8mb4');
$suite = new TestSuite();
$files = array_slice($argv, 1) ?: glob(__DIR__ . '/*_test.php');
foreach ($files as $file) {
    if (!is_file($file)) {
        $file = __DIR__ . '/' . $file;
    }
    $database = 'catalog_v1_test_' . bin2hex(random_bytes(8));
    $server->query("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $server->select_db($database);
    CatalogSuite\Support\Config::set('db', [...$settings, 'database' => $database]);
    $storage = sys_get_temp_dir() . '/' . $database;
    mkdir($storage, 0700, true);
    $appConfig = require dirname(__DIR__) . '/config/app.php';
    foreach (['csv', 'media', 'latex_build', 'latex_pdfs', 'api_latex_build', 'api_latex_pdfs', 'typst_build', 'typst_pdfs', 'typst_assets', 'logs'] as $key) {
        $appConfig['storage'][$key] = $storage . '/' . $key;
    }
    $appConfig['truncate']['audit_log'] = $storage . '/truncate.jsonl';
    $appConfig['logging']['path'] = $storage . '/catalog.log';
    CatalogSuite\Support\Config::set('app', $appConfig);
    try {
        (new CatalogSuite\Support\Seeder($server))->ensureSchema();
        if (class_exists(CatalogSuite\Support\CatalogV1Migration::class) && !getenv('CATALOG_TEST_BASELINE')) {
            (new CatalogSuite\Support\CatalogV1Migration($server))->up();
        }
        (require $file)($suite, $server);
    } catch (Throwable $error) {
        $suite->test(basename($file) . ' setup/execution', static function () use ($error): void { throw $error; });
    } finally {
        $server->query("DROP DATABASE `{$database}`");
        $delete = static function (string $path) use (&$delete): void {
            if (is_dir($path) && !is_link($path)) {
                foreach (new FilesystemIterator($path) as $entry) {
                    $delete($entry->getPathname());
                }
                rmdir($path);
            } elseif (is_file($path) || is_link($path)) {
                unlink($path);
            }
        };
        $delete($storage);
    }
}
echo json_encode(['passed' => $suite->passed, 'failed' => $suite->failed, 'skipped' => $suite->skipped], JSON_UNESCAPED_SLASHES) . "\n";
exit($suite->failed === 0 ? 0 : 1);
