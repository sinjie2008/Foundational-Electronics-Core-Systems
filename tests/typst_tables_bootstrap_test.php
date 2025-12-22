<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Support\Db;
use App\Typst\TypstService;

/**
 * Simple assertion helper for Typst table bootstrap tests.
 */
function typst_bootstrap_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

/**
 * Check whether a table exists in the current database.
 */
function typst_bootstrap_table_exists(mysqli $connection, string $table): bool
{
    $stmt = $connection->prepare(
        'SELECT COUNT(1) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = (int) ($result->fetch_row()[0] ?? 0);
    $stmt->close();

    return $count > 0;
}

$service = new TypstService();
$service->getSeriesPreference(1);

$connection = Db::connection();
$tables = [
    'typst_templates',
    'typst_variables',
    'typst_series_preferences',
];

foreach ($tables as $table) {
    typst_bootstrap_assert(
        typst_bootstrap_table_exists($connection, $table),
        sprintf('Expected table to exist: %s', $table)
    );
}

echo "ok\n";
