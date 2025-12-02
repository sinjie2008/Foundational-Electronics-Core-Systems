<?php
require_once __DIR__ . '/../app/Support/Db.php';
use App\Support\Db;

// Assuming Db class handles connection. 
// If not, I'll fallback to raw PDO using config.
// Let's check App\Support\Db first. 
// Actually, to be safe and quick, I'll copy the logic from run_sql.php but point to the new file.
// But run_sql.php used config/db.php which might be different from App\Support\Db.
// Let's stick to the pattern in run_sql.php but fix the path if needed.

$configPath = __DIR__ . '/../config/db.php';
if (!file_exists($configPath)) {
    // Fallback to root db_config.php if config/db.php doesn't exist
    $configPath = __DIR__ . '/../db_config.php';
}

if (file_exists($configPath)) {
    $config = require $configPath;
} else {
    die("Database configuration not found.");
}

// Adjust config keys if they differ (e.g. if db_config.php returns different structure)
// But assuming it's standard.

try {
    $host = $config['host'] ?? 'localhost';
    $port = $config['port'] ?? 3306;
    $db   = $config['database'] ?? 'test';
    $user = $config['username'] ?? 'root';
    $pass = $config['password'] ?? '';
    $charset = $config['charset'] ?? 'utf8mb4';

    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$db;charset=$charset",
        $user,
        $pass
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sqlFile = __DIR__ . '/create_typst_tables.sql';
    if (!file_exists($sqlFile)) {
        die("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split by semicolon to run multiple queries if needed, or just exec if PDO supports multiple
    // PDO::exec supports multiple queries in one string for MySQL usually.
    $pdo->exec($sql);
    
    echo "Typst tables created successfully.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
