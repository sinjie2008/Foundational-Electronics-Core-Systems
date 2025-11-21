<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

try {
    $query = $_GET['q'] ?? '';
    if (trim($query) === '') {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

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

    $searchTerm = '%' . $mysqli->real_escape_string($query) . '%';
    $matches = [];

    // Search Categories and Series
    $stmt = $mysqli->prepare("SELECT id, parent_id, name, type FROM category WHERE name LIKE ?");
    $stmt->bind_param('s', $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $matches[] = [
            'id' => (int)$row['id'],
            'parent_id' => $row['parent_id'] ? (int)$row['parent_id'] : null,
            'name' => $row['name'],
            'type' => $row['type']
        ];
    }
    $stmt->close();

    // Search Products
    $stmt = $mysqli->prepare("SELECT id, series_id, name FROM product WHERE name LIKE ? OR sku LIKE ?");
    $stmt->bind_param('ss', $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $matches[] = [
            'id' => (int)$row['id'],
            'parent_id' => (int)$row['series_id'], // For products, parent is the series
            'name' => $row['name'],
            'type' => 'product'
        ];
    }
    $stmt->close();

    echo json_encode(['success' => true, 'data' => $matches]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
