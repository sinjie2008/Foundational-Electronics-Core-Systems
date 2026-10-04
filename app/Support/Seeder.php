<?php
declare(strict_types=1);

namespace CatalogSuite\Support;

use CatalogSuite\Services\SeriesFieldService;

use mysqli;
use mysqli_result;
use Throwable;

final class Seeder
{
    public function __construct(private mysqli $connection)
    {
    }

    /**
     * Ensures all required tables exist.
     */
    public function ensureSchema(bool $includeDemoMetadataDefaults = true): void
    {
        $schemaStatements = [
            <<<SQL
            CREATE TABLE IF NOT EXISTS category (
                id INT AUTO_INCREMENT PRIMARY KEY,
                parent_id INT NULL,
                name VARCHAR(255) NOT NULL,
                type ENUM('category', 'series') NOT NULL DEFAULT 'category',
                typst_templating_enabled TINYINT(1) NOT NULL DEFAULT 0,
                display_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_category_parent FOREIGN KEY (parent_id) REFERENCES category(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS product (
                id INT AUTO_INCREMENT PRIMARY KEY,
                series_id INT NOT NULL,
                sku VARCHAR(128) NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_product_series FOREIGN KEY (series_id) REFERENCES category(id) ON DELETE CASCADE,
                UNIQUE KEY idx_product_series_sku (series_id, sku)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS series_custom_field (
                id INT AUTO_INCREMENT PRIMARY KEY,
                series_id INT NOT NULL,
                field_key VARCHAR(64) NOT NULL,
                label VARCHAR(255) NOT NULL,
                field_type ENUM('text','number','file') NOT NULL DEFAULT 'text',
                field_scope ENUM('series_metadata', 'product_attribute') NOT NULL DEFAULT 'product_attribute',
                default_value TEXT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_required TINYINT(1) NOT NULL DEFAULT 0,
                is_public_portal_hidden TINYINT(1) NOT NULL DEFAULT 0,
                is_backend_portal_hidden TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_series_custom_field_series FOREIGN KEY (series_id) REFERENCES category(id) ON DELETE CASCADE,
                UNIQUE KEY idx_series_field_key (series_id, field_scope, field_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS product_custom_field_value (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                series_custom_field_id INT NOT NULL,
                value TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_product_custom_field_product FOREIGN KEY (product_id) REFERENCES product(id) ON DELETE CASCADE,
                CONSTRAINT fk_product_custom_field_series_field FOREIGN KEY (series_custom_field_id) REFERENCES series_custom_field(id) ON DELETE CASCADE,
                UNIQUE KEY idx_product_field_unique (product_id, series_custom_field_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
            <<<SQL
            CREATE TABLE IF NOT EXISTS series_custom_field_value (
                id INT AUTO_INCREMENT PRIMARY KEY,
                series_id INT NOT NULL,
                series_custom_field_id INT NOT NULL,
                value TEXT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_series_value_series FOREIGN KEY (series_id) REFERENCES category(id) ON DELETE CASCADE,
                CONSTRAINT fk_series_value_field FOREIGN KEY (series_custom_field_id) REFERENCES series_custom_field(id) ON DELETE CASCADE,
                UNIQUE KEY idx_series_value_unique (series_id, series_custom_field_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS latex_template (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                latex_source LONGTEXT NOT NULL,
                pdf_path VARCHAR(512) NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
                        <<<SQL
            CREATE TABLE IF NOT EXISTS seed_migration (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                executed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY idx_seed_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL,
        ];

        foreach ($schemaStatements as $statement) {
            $this->connection->query($statement);
        }

        $this->ensureTypstTemplatingColumn();
        $this->ensureColumnExists(
            'series_custom_field',
            'field_scope',
            "field_scope ENUM('series_metadata', 'product_attribute') NOT NULL DEFAULT 'product_attribute' AFTER field_type"
        );
        $this->ensureColumnExists(
            'series_custom_field',
            'default_value',
            'default_value TEXT NULL AFTER field_scope'
        );
        $this->ensureColumnExists(
            'series_custom_field',
            'is_public_portal_hidden',
            'is_public_portal_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_required'
        );
        $this->ensureColumnExists(
            'series_custom_field',
            'is_backend_portal_hidden',
            'is_backend_portal_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_public_portal_hidden'
        );

        $this->connection->query(
            sprintf(
                "UPDATE series_custom_field SET field_scope = '%s' WHERE field_scope IS NULL OR field_scope = ''",
                SeriesFieldService::SCOPE_PRODUCT
            )
        );

        $this->ensureFieldTypeEnumUpdated();
        if ($includeDemoMetadataDefaults) {
            $this->ensureSeriesMetadataDefaults();
        }
        $this->ensureSeriesFieldScopeIndex();
    }

    /**
     * Applies the initial seed if it has not been executed.
     */
    public function seedInitialData(): void
    {
        if ($this->isSeedApplied(Config::get('app')['seed_name'])) {
            return;
        }

        $this->connection->begin_transaction();

        try {
            $tree = $this->getSeedTree();
            $seriesFieldCache = [];
            foreach ($tree as $index => $node) {
                $this->insertNodeRecursive(null, $node, $index + 1, $seriesFieldCache);
            }

            $stmt = $this->connection->prepare('INSERT INTO seed_migration (name) VALUES (?)');
            $seedName = Config::get('app')['seed_name'];
            $stmt->bind_param('s', $seedName);
            $stmt->execute();
            $stmt->close();

            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollback();
            throw $exception;
        }
    }

    private function isSeedApplied(string $seedName): bool
    {
        $stmt = $this->connection->prepare('SELECT COUNT(1) AS total FROM seed_migration WHERE name = ?');
        $stmt->bind_param('s', $seedName);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_assoc()['total'] ?? 0);
        $stmt->close();

        return $count > 0;
    }

    /**
     * Inserts hierarchy nodes recursively.
     *
     * @param array<string, mixed> $node
     * @param array<int, array<string, array<string, int>>> $seriesFieldCache
     */
    private function insertNodeRecursive(
        ?int $parentId,
        array $node,
        int $displayOrder,
        array &$seriesFieldCache
    ): void {
        $nodeId = $this->insertCategoryNode(
            $parentId,
            (string) $node['name'],
            (string) $node['type'],
            $displayOrder
        );

        if ($node['type'] === 'series') {
            $seriesFieldCache[$nodeId] = [
                SeriesFieldService::SCOPE_PRODUCT => [],
                SeriesFieldService::SCOPE_SERIES => [],
            ];

            $productFieldMap =& $seriesFieldCache[$nodeId][SeriesFieldService::SCOPE_PRODUCT];
            foreach ($node['fields'] ?? [] as $fieldIndex => $fieldDefinition) {
                $fieldId = $this->insertSeriesField(
                    $nodeId,
                    $fieldDefinition,
                    $fieldIndex + 1,
                    SeriesFieldService::SCOPE_PRODUCT
                );
                $productFieldMap[$fieldDefinition['field_key']] = $fieldId;
            }

            $metadataFieldMap =& $seriesFieldCache[$nodeId][SeriesFieldService::SCOPE_SERIES];
            foreach ($node['metadataFields'] ?? [] as $metaIndex => $metadataDefinition) {
                $fieldId = $this->insertSeriesField(
                    $nodeId,
                    $metadataDefinition,
                    $metaIndex + 1,
                    SeriesFieldService::SCOPE_SERIES
                );
                $metadataFieldMap[$metadataDefinition['field_key']] = $fieldId;
            }

            foreach ($node['metadataValues'] ?? [] as $metaKey => $metaValue) {
                $fieldId = $metadataFieldMap[$metaKey] ?? null;
                if ($fieldId !== null) {
                    $this->insertSeriesMetadataValue(
                        $nodeId,
                        $fieldId,
                        $metaValue !== null ? (string) $metaValue : null
                    );
                }
            }

            foreach ($node['products'] ?? [] as $productDefinition) {
                $productId = $this->insertProduct($nodeId, $productDefinition);
                foreach ($productDefinition['custom_values'] ?? [] as $fieldKey => $fieldValue) {
                    $fieldId = $productFieldMap[$fieldKey] ?? null;
                    if ($fieldId !== null) {
                        $this->insertProductCustomValue($productId, $fieldId, $fieldValue);
                    }
                }
            }
        }

        foreach ($node['children'] ?? [] as $childIndex => $childNode) {
            $this->insertNodeRecursive($nodeId, $childNode, $childIndex + 1, $seriesFieldCache);
        }
    }

    private function insertCategoryNode(
        ?int $parentId,
        string $name,
        string $type,
        int $displayOrder
    ): int {
        $stmt = $this->connection->prepare(
            'INSERT INTO category (parent_id, name, type, display_order) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $parentId, $name, $type, $displayOrder);
        $stmt->execute();
        $newId = (int) $stmt->insert_id;
        $stmt->close();

        return $newId;
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     */
    private function insertSeriesField(
        int $seriesId,
        array $fieldDefinition,
        int $sortOrder,
        string $fieldScope = SeriesFieldService::SCOPE_PRODUCT
    ): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field (
                series_id,
                field_key,
                label,
                field_type,
                field_scope,
                default_value,
                sort_order,
                is_required,
                is_public_portal_hidden,
                is_backend_portal_hidden
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $fieldKey = (string) $fieldDefinition['field_key'];
        $label = (string) $fieldDefinition['label'];
        $fieldType = (string) ($fieldDefinition['field_type'] ?? 'text');
        $normalizedScope = $this->normalizeFieldScope($fieldDefinition['field_scope'] ?? $fieldScope);
        $defaultValue = array_key_exists('default_value', $fieldDefinition)
            ? ($fieldDefinition['default_value'] !== null ? (string) $fieldDefinition['default_value'] : null)
            : null;
        $isRequired = (bool) ($fieldDefinition['is_required'] ?? false);
        $requiredValue = $isRequired ? 1 : 0;
        $isPublicPortalHidden = (bool) ($fieldDefinition['is_public_portal_hidden'] ?? false);
        $publicPortalHiddenValue = $isPublicPortalHidden ? 1 : 0;
        $isBackendPortalHidden = (bool) ($fieldDefinition['is_backend_portal_hidden'] ?? false);
        $backendPortalHiddenValue = $isBackendPortalHidden ? 1 : 0;

        $stmt->bind_param(
            'isssssiiii',
            $seriesId,
            $fieldKey,
            $label,
            $fieldType,
            $normalizedScope,
            $defaultValue,
            $sortOrder,
            $requiredValue,
            $publicPortalHiddenValue,
            $backendPortalHiddenValue
        );
        $stmt->execute();
        $insertId = (int) $stmt->insert_id;
        $stmt->close();

        return $insertId;
    }

    /**
     * @param array<string, mixed> $productDefinition
     */
    private function insertProduct(int $seriesId, array $productDefinition): int
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product (series_id, sku, name, description) VALUES (?, ?, ?, ?)'
        );
        $sku = (string) $productDefinition['sku'];
        $name = (string) $productDefinition['name'];
        $description = isset($productDefinition['description'])
            ? (string) $productDefinition['description']
            : null;
        $stmt->bind_param('isss', $seriesId, $sku, $name, $description);
        $stmt->execute();
        $productId = (int) $stmt->insert_id;
        $stmt->close();

        return $productId;
    }

    private function insertProductCustomValue(int $productId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $productId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function insertSeriesMetadataValue(int $seriesId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'INSERT INTO series_custom_field_value (series_id, series_custom_field_id, value)
             VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iis', $seriesId, $fieldId, $value);
        $stmt->execute();
        $stmt->close();
    }

    private function ensureFieldTypeEnumUpdated(): void
    {
        $result = $this->connection->query(
            "SHOW COLUMNS FROM series_custom_field WHERE Field = 'field_type'"
        );
        $type = null;
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $type = $row['Type'] ?? null;
            $result->close();
        }
        if ($type !== null && stripos((string) $type, 'file') !== false && stripos((string) $type, 'number') !== false) {
            return;
        }

        $this->connection->query(
            "ALTER TABLE series_custom_field MODIFY field_type ENUM('text','number','file') NOT NULL DEFAULT 'text'"
        );
    }

    private function ensureSeriesMetadataDefaults(): void
    {
        $seriesResult = $this->connection->query(
            "SELECT id FROM category WHERE type = 'series'"
        );
        if ($seriesResult === false) {
            return;
        }

        while ($seriesRow = $seriesResult->fetch_assoc()) {
            $seriesId = (int) $seriesRow['id'];
            $maxSortOrder = $this->getMaxSortOrderForScope($seriesId, SeriesFieldService::SCOPE_SERIES);
            foreach ($this->getDefaultSeriesMetadataFieldSeeds() as $index => $definition) {
                $fieldKey = (string) $definition['field_key'];
                $fieldId = $this->findFieldIdByKey($seriesId, $fieldKey, SeriesFieldService::SCOPE_SERIES);
                if ($fieldId === null) {
                    $fieldId = $this->insertSeriesField(
                        $seriesId,
                        $definition,
                        $maxSortOrder + $index + 1,
                        SeriesFieldService::SCOPE_SERIES
                    );
                }
                $defaultValue = array_key_exists('default_value', $definition)
                    ? ($definition['default_value'] !== null ? (string) $definition['default_value'] : null)
                    : null;
                $this->ensureMetadataValueExists($seriesId, $fieldId, $defaultValue);
            }
        }
        $seriesResult->close();
    }

    private function ensureSeriesFieldScopeIndex(): void
    {
        $sql = <<<SQL
            SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'series_custom_field'
              AND index_name = 'idx_series_field_key'
        SQL;
        $result = $this->connection->query($sql);
        $columns = null;
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $columns = isset($row['columns']) ? (string) $row['columns'] : null;
            $result->close();
        }
        if ($columns === 'series_id,field_scope,field_key') {
            return;
        }
        $tmpIndex = 'idx_series_field_scope_key_tmp';

        $tmpCheck = $this->connection->query(
            sprintf(
                "SELECT COUNT(1) AS total FROM information_schema.statistics WHERE table_schema = DATABASE()
                 AND table_name = 'series_custom_field' AND index_name = '%s'",
                $this->connection->real_escape_string($tmpIndex)
            )
        );
        if ($tmpCheck instanceof mysqli_result) {
            $count = (int) ($tmpCheck->fetch_assoc()['total'] ?? 0);
            $tmpCheck->close();
            if ($count > 0) {
                $this->connection->query("DROP INDEX {$tmpIndex} ON series_custom_field");
            }
        }

        $this->connection->query(
            "ALTER TABLE series_custom_field ADD UNIQUE KEY {$tmpIndex} (series_id, field_scope, field_key)"
        );
        $this->connection->query('DROP INDEX idx_series_field_key ON series_custom_field');
        $this->connection->query(
            "ALTER TABLE series_custom_field RENAME INDEX {$tmpIndex} TO idx_series_field_key"
        );
    }

    private function getMaxSortOrderForScope(int $seriesId, string $scope): int
    {
        $stmt = $this->connection->prepare(
            'SELECT MAX(sort_order) AS max_sort FROM series_custom_field WHERE series_id = ? AND field_scope = ?'
        );
        $stmt->bind_param('is', $seriesId, $scope);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return (int) ($row['max_sort'] ?? 0);
    }

    private function findFieldIdByKey(int $seriesId, string $fieldKey, string $scope): ?int
    {
        $stmt = $this->connection->prepare(
            'SELECT id FROM series_custom_field WHERE series_id = ? AND field_key = ? AND field_scope = ? LIMIT 1'
        );
        $stmt->bind_param('iss', $seriesId, $fieldKey, $scope);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        return $row !== null ? (int) $row['id'] : null;
    }

    private function ensureMetadataValueExists(int $seriesId, int $fieldId, ?string $value): void
    {
        $stmt = $this->connection->prepare(
            'SELECT id FROM series_custom_field_value WHERE series_id = ? AND series_custom_field_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $seriesId, $fieldId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            $this->insertSeriesMetadataValue($seriesId, $fieldId, $value);
        }
    }

    private function normalizeFieldScope(?string $scope): string
    {
        $normalized = $scope !== null ? (string) $scope : '';
        $allowedScopes = [SeriesFieldService::SCOPE_PRODUCT, SeriesFieldService::SCOPE_SERIES];

        return in_array($normalized, $allowedScopes, true)
            ? $normalized
            : SeriesFieldService::SCOPE_PRODUCT;
    }

    private function ensureColumnExists(string $table, string $column, string $definition): void
    {
        $stmt = $this->connection->prepare(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->bind_param('ss', $table, $column);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($count === 0) {
            $this->connection->query(sprintf('ALTER TABLE `%s` ADD COLUMN %s', $table, $definition));
        }
    }

    /**
     * Ensures typst templating flag column exists and migrates legacy latex flag when present.
     */
    private function ensureTypstTemplatingColumn(): void
    {
        $this->ensureColumnExists(
            'category',
            'typst_templating_enabled',
            "typst_templating_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER type"
        );

        $stmt = $this->connection->prepare(
            'SELECT COUNT(1) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $table = 'category';
        $legacyColumn = 'latex_templating_enabled';
        $stmt->bind_param('ss', $table, $legacyColumn);
        $stmt->execute();
        $result = $stmt->get_result();
        $legacyCount = (int) ($result->fetch_row()[0] ?? 0);
        $stmt->close();

        if ($legacyCount > 0) {
            $this->connection->query(
                'UPDATE category SET typst_templating_enabled = latex_templating_enabled WHERE typst_templating_enabled IS NULL OR typst_templating_enabled = 0'
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getSeedTree(): array
    {
        return [
            [
                'name' => 'General Products',
                'type' => 'category',
                'children' => [
                    $this->buildSeriesSeed(
                        'C0 SERIES',
                        [
                            'metadata_values' => [
                                'series_voltage' => '16V - 25V',
                                'series_notes' => 'General-purpose capacitor line.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'C0-100',
                                    'name' => 'Capacitor 100uF',
                                    'description' => 'Compact capacitor suitable for general electronics.',
                                    'custom_values' => [
                                        'voltage_rating' => '16V',
                                        'tolerance' => '+/-10%',
                                    ],
                                ],
                                [
                                    'sku' => 'C0-200',
                                    'name' => 'Capacitor 220uF',
                                    'description' => 'High capacity for power supplies.',
                                    'custom_values' => [
                                        'voltage_rating' => '25V',
                                        'tolerance' => '+/-20%',
                                    ],
                                ],
                            ],
                        ]
                    ),
                    $this->buildSeriesSeed(
                        'C1 SERIES',
                        [
                            'metadata_values' => [
                                'series_voltage' => '35V',
                                'series_notes' => 'Low ESR line for audio applications.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'C1-300',
                                    'name' => 'Capacitor 330uF',
                                    'description' => 'Low ESR capacitor for audio applications.',
                                    'custom_values' => [
                                        'voltage_rating' => '35V',
                                        'tolerance' => '+/-5%',
                                    ],
                                ],
                            ],
                        ]
                    ),
                ],
            ],
            [
                'name' => 'EMC Components',
                'type' => 'category',
                'children' => [
                    $this->buildSeriesSeed(
                        'EM-Filter',
                        [
                            'metadata_values' => [
                                'series_voltage' => '250VAC',
                                'series_notes' => 'Electromagnetic interference suppression filters.',
                            ],
                            'products' => [
                                [
                                    'sku' => 'EM-F-01',
                                    'name' => 'Power Line Filter',
                                    'description' => 'Suppresses conducted emissions.',
                                    'custom_values' => [
                                        'voltage_rating' => '250VAC',
                                        'tolerance' => 'Standard',
                                    ],
                                ],
                            ],
                        ]
                    ),
                ],
            ],
        ];
    }

    /**
     * Helper for building a series node within seed data.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function buildSeriesSeed(string $seriesName, array $definition): array
    {
        $products = $definition['products'] ?? [];
        $productFields = $definition['product_fields']
            ?? $definition['fields']
            ?? $this->getDefaultProductFieldSeeds();
        $metadataFields = $definition['metadata_fields'] ?? $this->getDefaultSeriesMetadataFieldSeeds();
        $metadataValues = $definition['metadata_values'] ?? [];

        return [
            'name' => $seriesName,
            'type' => 'series',
            'fields' => $productFields,
            'metadataFields' => $metadataFields,
            'metadataValues' => $metadataValues,
            'products' => $products,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultProductFieldSeeds(): array
    {
        return [
            [
                'field_key' => 'voltage_rating',
                'label' => 'Voltage Rating',
                'field_type' => 'text',
                'is_required' => false,
            ],
            [
                'field_key' => 'tolerance',
                'label' => 'Tolerance',
                'field_type' => 'text',
                'is_required' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultSeriesMetadataFieldSeeds(): array
    {
        return [
            [
                'field_key' => 'series_voltage',
                'label' => 'Voltage Range',
                'field_type' => 'text',
                'is_required' => false,
            ],
            [
                'field_key' => 'series_notes',
                'label' => 'Series Notes',
                'field_type' => 'text',
                'is_required' => false,
            ],
        ];
    }
}
