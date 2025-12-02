<?php
require_once __DIR__ . '/app/bootstrap.php';

use App\Support\Db;

$db = Db::connection();
$result = $db->query("SELECT id, title, typst_content FROM typst_templates WHERE typst_content LIKE '%#date()%'");

echo "Found " . $result->num_rows . " templates with #date():\n";

while ($row = $result->fetch_assoc()) {
    echo "ID: " . $row['id'] . "\n";
    echo "Title: " . $row['title'] . "\n";
    echo "Content Snippet: " . substr($row['typst_content'], 0, 100) . "...\n";
    echo "---------------------------------------------------\n";
}
