<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Support\Request;
use App\Typst\TypstService;

// Mocking the flow
echo "--- Simulating Compile ---\n";
$service = new TypstService();
$compileResult = $service->compileTypst("Hello World Flow Test", 12);
echo "Compile Result:\n";
print_r($compileResult);

if (empty($compileResult['path'])) {
    die("FAILURE: Compile did not return a path.\n");
}

$pdfPath = $compileResult['path'];
echo "Got Path: $pdfPath\n";

echo "\n--- Simulating Save Template (POST) ---\n";
// We can't easily mock the API request object without setting globals, 
// so let's call the Service method directly as the API would.

$title = "Flow Test " . date('His');
$desc = "Flow Description";
$code = "Hello World Flow Test";
$seriesId = 12;

// Simulate API logic:
// $pdfPath = isset($input['lastPdfPath']) ? (string)$input['lastPdfPath'] : null;

echo "Calling createSeriesTemplate with path: $pdfPath\n";
$template = $service->createSeriesTemplate($seriesId, $title, $desc, $code, $pdfPath);

echo "Template Created:\n";
print_r($template);

if ($template['lastPdfPath'] === $pdfPath) {
    echo "SUCCESS: PDF Path saved correctly in DB.\n";
} else {
    echo "FAILURE: PDF Path NOT saved in DB. Got: " . ($template['lastPdfPath'] ?? 'NULL') . "\n";
}
