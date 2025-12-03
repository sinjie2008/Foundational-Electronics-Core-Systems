<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Support\Db;

$db = Db::connection();
$result = $db->query("DESCRIBE typst_templates");

if ($result) {
    echo "Columns in typst_templates:\n";
    while ($row = $result->fetch_assoc()) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
} else {
    echo "Error: " . $db->error . "\n";
}
