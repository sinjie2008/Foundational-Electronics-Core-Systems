<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $configPath = __DIR__ . '/../../db_config.php';
    if (!file_exists($configPath)) {
        throw new Exception('Database configuration not found.');
    }
    $config = require $configPath;

    $mysqli = new mysqli(
        $config['host'],
        $config['username'],
        $config['password'],
        $config['database'],
        $config['port']
    );

    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset($config['charset'] ?? 'utf8mb4');

    // Fetch all categories (including series)
    $categories = [];
    $result = $mysqli->query("SELECT id, parent_id, name, type, display_order FROM category ORDER BY display_order ASC, name ASC");
    while ($row = $result->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['parent_id'] = $row['parent_id'] ? (int)$row['parent_id'] : null;
        $row['display_order'] = (int)$row['display_order'];
        $row['children'] = [];
        $row['products'] = [];
        $row['product_count'] = 0;
        $row['category_count'] = 0;
        $categories[$row['id']] = $row;
    }
    $result->close();

    // Fetch all products
    $result = $mysqli->query("SELECT id, series_id, name, sku FROM product ORDER BY name ASC");
    while ($row = $result->fetch_assoc()) {
        $seriesId = (int)$row['series_id'];
        if (isset($categories[$seriesId])) {
            $categories[$seriesId]['products'][] = [
                'id' => (int)$row['id'],
                'name' => $row['name'],
                'sku' => $row['sku'],
                'type' => 'product'
            ];
            $categories[$seriesId]['product_count']++;
        }
    }
    $result->close();

    // Build Tree
    $tree = [];
    foreach ($categories as $id => &$node) {
        if ($node['parent_id'] === null) {
            $tree[] = &$node;
        } else {
            if (isset($categories[$node['parent_id']])) {
                $categories[$node['parent_id']]['children'][] = &$node;
                $categories[$node['parent_id']]['category_count']++;
            }
        }
    }

    // Recursive function to update counts up the tree (optional, but user asked for direct child counts usually)
    // User requirement:
    // Category: show number of child categories.
    // Series: show number of products.
    // The current logic counts direct children.

    echo json_encode(['success' => true, 'data' => $tree]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
