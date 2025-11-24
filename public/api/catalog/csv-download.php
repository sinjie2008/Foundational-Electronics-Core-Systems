<?php
declare(strict_types=1);

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../catalog.php';

$fileId = isset($_GET['id']) ? (string) $_GET['id'] : '';
if ($fileId === '') {
    http_response_code(404);
    echo json_encode(['error' => ['code' => 'CSV_NOT_FOUND', 'message' => 'CSV id is required.']]);
    exit;
}

$app = CatalogApplication::create(true);
$csv = $app->getCsvService();
$responder = new HttpResponder();

try {
    $csv->streamFile($fileId, $responder);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => ['code' => 'CSV_DOWNLOAD_ERROR', 'message' => $e->getMessage()]]);
}
