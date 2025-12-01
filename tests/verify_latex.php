<?php
require __DIR__ . '/../app/bootstrap.php';

use App\Latex\LatexService;

echo "Starting Verification...\n";

try {
    $service = new LatexService();

    // --- Test Variables ---
    echo "Testing Variables...\n";
    $varKey = 'test_var_' . time();
    $var = $service->saveGlobalVariable($varKey, 'text', 'Initial Value');
    $varId = $var['id'] ?? 0;
    echo "Created Variable ID: $varId\n";

    $vars = $service->listGlobalVariables();
    $found = false;
    foreach ($vars as $v) {
        if ($v['id'] === $varId && $v['key'] === $varKey) {
            $found = true;
            break;
        }
    }
    if (!$found) throw new Exception("Variable not found after creation.");
    echo "Variable found in list.\n";

    $service->saveGlobalVariable($varKey, 'text', 'Updated Value', $varId);
    $vars = $service->listGlobalVariables();
    foreach ($vars as $v) {
        if ($v['id'] === $varId && $v['value'] === 'Updated Value') {
            echo "Variable updated successfully.\n";
            break;
        }
    }

    $service->deleteGlobalVariable($varId);
    $vars = $service->listGlobalVariables();
    foreach ($vars as $v) {
        if ($v['id'] === $varId) throw new Exception("Variable not deleted.");
    }
    echo "Variable deleted successfully.\n";

    // --- Test Templates ---
    echo "\nTesting Templates...\n";
    $tplTitle = 'Test Template ' . time();
    $tpl = $service->createGlobalTemplate($tplTitle, 'Desc', 'Latex Content');
    $tplId = $tpl['id'] ?? 0;
    echo "Created Template ID: $tplId\n";

    $tpl = $service->getGlobalTemplate($tplId);
    if (!$tpl || $tpl['title'] !== $tplTitle) throw new Exception("Template not found or mismatch.");
    echo "Template retrieved successfully.\n";

    $service->updateGlobalTemplate($tplId, $tplTitle . ' Updated', 'Desc', 'New Content');
    $tpl = $service->getGlobalTemplate($tplId);
    if ($tpl['title'] !== $tplTitle . ' Updated') throw new Exception("Template update failed.");
    echo "Template updated successfully.\n";

    $service->deleteGlobalTemplate($tplId);
    $tpl = $service->getGlobalTemplate($tplId);
    if ($tpl) throw new Exception("Template not deleted.");
    echo "Template deleted successfully.\n";

    echo "\nVerification Passed!\n";

} catch (Throwable $e) {
    echo "\nVerification Failed: " . $e->getMessage() . "\n";
    exit(1);
}
