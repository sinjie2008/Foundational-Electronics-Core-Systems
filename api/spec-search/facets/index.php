<?php
header('Content-Type: application/json');
require_once '../db_connect.php';

$mysqli = getDbConnection();
$input = json_decode(file_get_contents('php://input'), true);
$categoryIds = isset($input['category_ids']) ? $input['category_ids'] : [];

if (empty($categoryIds)) {
    echo json_encode([]);
    exit;
}

// Sanitize IDs
$ids = array_map('intval', $categoryIds);
$idsStr = implode(',', $ids);

// 1. Facet: Series
// Find all series (category type='series') that are children of the selected categories.
// Assuming structure: Category -> Series
$seriesFacet = [
    'key' => 'series',
    'label' => 'Series',
    'values' => []
];

$sqlSeries = "SELECT name FROM category WHERE parent_id IN ($idsStr) AND type = 'series' ORDER BY name ASC";
$resultSeries = $mysqli->query($sqlSeries);
if ($resultSeries) {
    while ($row = $resultSeries->fetch_assoc()) {
        $seriesFacet['values'][] = $row['name'];
    }
}

// 2. Facet: Custom Fields (ACF)
// Find all custom fields associated with these series.
// We need to find all series IDs first.
$sqlSeriesIds = "SELECT id FROM category WHERE parent_id IN ($idsStr) AND type = 'series'";
$resultSeriesIds = $mysqli->query($sqlSeriesIds);
$seriesIds = [];
while ($row = $resultSeriesIds->fetch_assoc()) {
    $seriesIds[] = $row['id'];
}

$facets = [];
if (!empty($seriesFacet['values'])) {
    $facets[] = $seriesFacet;
}

if (!empty($seriesIds)) {
    $seriesIdsStr = implode(',', $seriesIds);

    // Get all field definitions for these series
    // We want distinct fields (by field_key) that are used in these series.
    // Also we need to merge them if multiple series share the same field key.
    $sqlFields = "SELECT field_key, label, id, sort_order FROM series_custom_field 
                  WHERE series_id IN ($seriesIdsStr) AND field_scope = 'product_attribute' 
                  ORDER BY sort_order ASC";
    
    $resultFields = $mysqli->query($sqlFields);
    
    $fields = [];
    while ($row = $resultFields->fetch_assoc()) {
        // Use field_key as unique identifier
        if (!isset($fields[$row['field_key']])) {
            $fields[$row['field_key']] = [
                'key' => $row['field_key'],
                'label' => $row['label'],
                'ids' => [] // Store IDs to fetch values later
            ];
        }
        $fields[$row['field_key']]['ids'][] = $row['id'];
    }

    // For each field, fetch distinct values from product_custom_field_value
    foreach ($fields as $key => $field) {
        $fieldIdsStr = implode(',', $field['ids']);
        
        // Fetch distinct values for this field across all relevant products
        // We need to join product table to ensure we only get values for products in the selected series?
        // Actually, series_custom_field_id is unique to a series, so just querying by that ID is enough.
        
        $sqlValues = "SELECT DISTINCT value FROM product_custom_field_value 
                      WHERE series_custom_field_id IN ($fieldIdsStr) 
                      AND value IS NOT NULL AND value != '' 
                      ORDER BY value ASC"; // Note: Natural sort might be better but SQL is text sort
        
        $resultValues = $mysqli->query($sqlValues);
        $values = [];
        while ($row = $resultValues->fetch_assoc()) {
            $values[] = $row['value'];
        }

        if (!empty($values)) {
            $facets[] = [
                'key' => $key,
                'label' => $field['label'],
                'values' => $values
            ];
        }
    }
}

echo json_encode($facets);
?>
