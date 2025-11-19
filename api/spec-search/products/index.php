<?php
header('Content-Type: application/json');
require_once '../db_connect.php';

$mysqli = getDbConnection();
$input = json_decode(file_get_contents('php://input'), true);
$categoryIds = isset($input['category_ids']) ? $input['category_ids'] : [];
$filters = isset($input['filters']) ? $input['filters'] : [];

if (empty($categoryIds)) {
    echo json_encode([]);
    exit;
}

// Sanitize IDs
$catIds = array_map('intval', $categoryIds);
$catIdsStr = implode(',', $catIds);

// Base Query: Products in Series that are children of selected Categories
// Structure: Category -> Series -> Product
$sql = "SELECT p.id, p.sku, p.name, s.name as series_name, s.id as series_id 
        FROM product p
        JOIN category s ON p.series_id = s.id
        WHERE s.parent_id IN ($catIdsStr) AND s.type = 'series'";

// Apply Filters
// 1. Series Filter
if (isset($filters['series']) && !empty($filters['series'])) {
    $seriesNames = array_map(function($val) use ($mysqli) {
        return "'" . $mysqli->real_escape_string($val) . "'";
    }, $filters['series']);
    $seriesNamesStr = implode(',', $seriesNames);
    $sql .= " AND s.name IN ($seriesNamesStr)";
}

// 2. Custom Field Filters
// This is the tricky part (EAV filtering).
// For each filtered field, we need to ensure the product has a matching value.
// We can use EXISTS subqueries.

foreach ($filters as $key => $values) {
    if ($key === 'series') continue; // Handled above
    if (empty($values)) continue;

    $valuesEscaped = array_map(function($val) use ($mysqli) {
        return "'" . $mysqli->real_escape_string($val) . "'";
    }, $values);
    $valuesStr = implode(',', $valuesEscaped);
    $fieldKeyEscaped = $mysqli->real_escape_string($key);

    // Subquery to check if product has this field with one of the selected values
    // We need to join series_custom_field to match the field_key
    $sql .= " AND EXISTS (
        SELECT 1 FROM product_custom_field_value pcfv
        JOIN series_custom_field scf ON pcfv.series_custom_field_id = scf.id
        WHERE pcfv.product_id = p.id
        AND scf.field_key = '$fieldKeyEscaped'
        AND pcfv.value IN ($valuesStr)
    )";
}

$sql .= " LIMIT 500"; // Limit results for performance

$result = $mysqli->query($sql);
$products = [];

if ($result) {
    // We need to fetch all custom fields for these products to display in the table
    // It's more efficient to fetch the products first, then fetch their attributes.
    
    $rawProducts = [];
    $productIds = [];
    while ($row = $result->fetch_assoc()) {
        $rawProducts[$row['id']] = [
            'id' => $row['id'],
            'sku' => $row['sku'],
            'series' => $row['series_name']
            // We will add dynamic attributes below
        ];
        $productIds[] = $row['id'];
    }

    if (!empty($productIds)) {
        $productIdsStr = implode(',', $productIds);
        
        // Fetch all attributes for these products
        $sqlAttrs = "SELECT pcfv.product_id, scf.field_key, pcfv.value 
                     FROM product_custom_field_value pcfv
                     JOIN series_custom_field scf ON pcfv.series_custom_field_id = scf.id
                     WHERE pcfv.product_id IN ($productIdsStr)
                     AND scf.field_scope = 'product_attribute'";
        
        $resultAttrs = $mysqli->query($sqlAttrs);
        while ($row = $resultAttrs->fetch_assoc()) {
            if (isset($rawProducts[$row['product_id']])) {
                $rawProducts[$row['product_id']][$row['field_key']] = $row['value'];
            }
        }
    }

    $products = array_values($rawProducts);
}

echo json_encode($products);
?>
