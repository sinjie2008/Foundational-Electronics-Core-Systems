<?php
// api/spec-search/db_connect.php

function getDbConnection() {
    $configPath = __DIR__ . '/../../db_config.php';
    if (!file_exists($configPath)) {
        die(json_encode(['error' => 'Database configuration not found']));
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
        die(json_encode(['error' => 'Database connection failed: ' . $mysqli->connect_error]));
    }

    $mysqli->set_charset($config['charset'] ?? 'utf8mb4');

    return $mysqli;
}
?>
