<?php
declare(strict_types=1);

/**
 * Seed verification script.
 *
 * Executes schema creation and seed routines, then validates that critical
 * records exist. Intended to be run via PowerShell: php tests/seed_verification.php
 */

define('CATALOG_NO_AUTO_BOOTSTRAP', true);

require __DIR__ . '/../catalog.php';

/**
 * Writes a status message and exits with given code.
 */
function seed_test_finish(string $message, int $exitCode = 0): void
{
    if ($exitCode === 0) {
        fwrite(STDOUT, $message . PHP_EOL);
    } else {
        fwrite(STDERR, $message . PHP_EOL);
    }
    exit($exitCode);
}

$application = CatalogApplication::create(false);
$seeder = $application->getSeeder();
$connection = $application->getConnection();

try {
    $seeder->ensureSchema();
    $seeder->seedInitialData();

    $checks = [
        'General Products' => 'category',
        'EMC Components' => 'category',
        'C0 SERIES' => 'series',
        'C1 SERIES' => 'series',
    ];

    foreach ($checks as $name => $type) {
        $stmt = $connection->prepare('SELECT id FROM category WHERE name = ? AND type = ? LIMIT 1');
        $stmt->bind_param('ss', $name, $type);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows === 0) {
            seed_test_finish("Missing expected $type node: $name", 1);
        }
        $stmt->close();
    }

    $fieldStmt = $connection->prepare(
        "SELECT COUNT(1) FROM series_custom_field WHERE series_id = (
            SELECT id FROM category WHERE name = 'C0 SERIES' AND type = 'series' LIMIT 1
        )"
    );
    $fieldStmt->execute();
    $fieldResult = $fieldStmt->get_result()->fetch_row();
    $fieldStmt->close();

    if (((int) ($fieldResult[0] ?? 0)) < 3) {
        seed_test_finish('Expected at least 3 custom fields for C0 SERIES.', 1);
    }

    $metadataScope = SERIES_FIELD_SCOPE_SERIES;
    $metaFieldStmt = $connection->prepare(
        "SELECT COUNT(1) FROM series_custom_field WHERE series_id = (
            SELECT id FROM category WHERE name = 'C0 SERIES' AND type = 'series' LIMIT 1
        ) AND field_scope = ?"
    );
    $metaFieldStmt->bind_param('s', $metadataScope);
    $metaFieldStmt->execute();
    $metaFieldResult = $metaFieldStmt->get_result()->fetch_row();
    $metaFieldStmt->close();

    if (((int) ($metaFieldResult[0] ?? 0)) === 0) {
        seed_test_finish('Expected at least 1 series metadata field for C0 SERIES.', 1);
    }

    $metaValueStmt = $connection->prepare(
        "SELECT COUNT(1) FROM series_custom_field_value WHERE series_id = (
            SELECT id FROM category WHERE name = 'C0 SERIES' AND type = 'series' LIMIT 1
        )"
    );
    $metaValueStmt->execute();
    $metaValueResult = $metaValueStmt->get_result()->fetch_row();
    $metaValueStmt->close();

    if (((int) ($metaValueResult[0] ?? 0)) === 0) {
        seed_test_finish('Expected metadata values for C0 SERIES.', 1);
    }

    $productStmt = $connection->prepare(
        "SELECT COUNT(1) FROM product WHERE series_id = (
            SELECT id FROM category WHERE name = 'C0 SERIES' AND type = 'series' LIMIT 1
        )"
    );
    $productStmt->execute();
    $productResult = $productStmt->get_result()->fetch_row();
    $productStmt->close();

    if (((int) ($productResult[0] ?? 0)) < 2) {
        seed_test_finish('Expected at least 2 products for C0 SERIES.', 1);
    }

    $seedStmt = $connection->prepare('SELECT COUNT(1) FROM seed_migration WHERE name = ?');
    $seedName = CATALOG_SEED_NAME;
    $seedStmt->bind_param('s', $seedName);
    $seedStmt->execute();
    $seedResult = $seedStmt->get_result()->fetch_row();
    $seedStmt->close();

    if (((int) ($seedResult[0] ?? 0)) === 0) {
        seed_test_finish('Seed migration record missing.', 1);
    }

    seed_test_finish('Seed verification completed successfully.');
} finally {
    $connection->close();
}
