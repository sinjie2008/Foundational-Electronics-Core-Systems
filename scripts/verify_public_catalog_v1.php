<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use CatalogSuite\Support\CatalogV1Verification;

$options = getopt('', ['base-url:', 'variables:']);
$base = $options['base-url'] ?? null;
$file = $options['variables'] ?? dirname(__DIR__) . '/docs/api-plan/public-product-api-v1-test-variables.json';
if (PHP_SAPI !== 'cli' || !is_string($base) || !filter_var($base, FILTER_VALIDATE_URL)
    || !in_array(parse_url($base, PHP_URL_SCHEME), ['http', 'https'], true) || !is_string($file) || !is_file($file)) {
    fwrite(STDERR, "Usage: php scripts/verify_public_catalog_v1.php --base-url=http://127.0.0.1:8080 [--variables=/path/to/filled-actual-variables.json]\n");
    exit(2);
}
try {
    $variables = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($variables)) {
        throw new InvalidArgumentException('Verification variables must be a JSON object.');
    }
    $result = (new CatalogV1Verification($variables))->verify(static function (string $path) use ($base): array {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 30, 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Accept: application/json\r\n"], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = file_get_contents(rtrim($base, '/') . $path, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $header, $match)) {
                $status = (int) $match[1];
            }
        }
        if ($body === false) {
            throw new RuntimeException('HTTP request failed. Check the base URL and server.');
        }
        return ['status' => $status, 'body' => $body];
    });
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($result['failed'] === 0 ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "Actual-data verification did not run: {$error->getMessage()}\n");
    exit(2);
}
