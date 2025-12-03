<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Typst\TypstService;

$service = new TypstService();

$seriesId = 1; // Assuming series ID 1 exists
$title = "Debug Template " . date('His');
$desc = "Debug Description";
$code = "Hello World";
$pdfPath = "C:/laragon/www/test/public/storage/typst-pdfs/debug.pdf";

echo "Attempting to create series template with PDF path: $pdfPath\n";

try {
    $template = $service->createSeriesTemplate($seriesId, $title, $desc, $code, $pdfPath);
    
    echo "Template created with ID: " . $template['id'] . "\n";
    echo "Saved PDF Path: " . ($template['lastPdfPath'] ?? 'NULL') . "\n";
    
    if ($template['lastPdfPath'] === $pdfPath) {
        echo "SUCCESS: Create - PDF Path saved correctly.\n";
    } else {
        echo "FAILURE: Create - PDF Path NOT saved correctly.\n";
    }

    // Test Update
    $newPdfPath = "C:/laragon/www/test/public/storage/typst-pdfs/debug_updated.pdf";
    echo "Attempting to update series template with PDF path: $newPdfPath\n";
    
    $updatedTemplate = $service->updateSeriesTemplate($template['id'], $seriesId, $title . " Updated", $desc, $code, $newPdfPath);
    
    echo "Updated PDF Path: " . ($updatedTemplate['lastPdfPath'] ?? 'NULL') . "\n";
    
    if ($updatedTemplate['lastPdfPath'] === $newPdfPath) {
        echo "SUCCESS: Update - PDF Path saved correctly.\n";
    } else {
        echo "FAILURE: Update - PDF Path NOT saved correctly.\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
