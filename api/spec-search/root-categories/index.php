<?php
header('Content-Type: application/json');
require_once '../db_connect.php';

$mysqli = getDbConnection();

// Fetch root categories (parent_id IS NULL and type = 'category')
// Adjust the query if your root categories are defined differently (e.g. specific IDs)
$sql = "SELECT id, name FROM category WHERE parent_id IS NULL AND type = 'category' ORDER BY display_order ASC, name ASC";
$result = $mysqli->query($sql);

$data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $data[] = [
            'id' => $row['id'],
            'name' => $row['name']
        ];
    }
}

echo json_encode($data);
?>
